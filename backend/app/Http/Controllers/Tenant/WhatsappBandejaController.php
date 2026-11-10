<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\WhatsappConversacionResource;
use App\Http\Resources\Tenant\WhatsappMensajeResource;
use App\Models\Tenant\WhatsappAviso;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\Tenant\WhatsappSolicitud;
use App\Models\WhatsappNumero;
use App\Services\Whatsapp\WhatsappClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

/**
 * La bandeja de conversaciones de WhatsApp del Panel (spec tenant/031, etapa 7b, RN-54 a RN-60).
 */
class WhatsappBandejaController extends Controller
{
    /** Lo que cabe en la lista; las conversaciones más viejas no se atienden desde aquí. */
    private const LIMITE_LISTA = 50;

    /** El historial que se pinta al abrir una conversación. */
    private const LIMITE_MENSAJES = 200;

    /** RN-55: primero la que tuvo movimiento más reciente, de quien sea. */
    public function index(): AnonymousResourceCollection
    {
        $conversaciones = WhatsappConversacion::query()
            ->with(['cliente', 'ultimoMensaje'])
            ->whereHas('mensajes')
            ->orderByDesc(WhatsappMensaje::select('id_mensaje')
                ->whereColumn('whatsapp_mensajes.id_conversacion', 'whatsapp_conversaciones.id_conversacion')
                ->orderByDesc('id_mensaje')
                ->limit(1))
            ->limit(self::LIMITE_LISTA)
            ->get();

        return WhatsappConversacionResource::collection($conversaciones);
    }

    /** RN-55: el contador del menú. */
    public function esperando(): JsonResponse
    {
        return response()->json(['esperando' => WhatsappConversacion::whereNotNull('pide_persona_en')->count()]);
    }

    /** RN-56 y RN-57: el historial, los folios y si todavía se le puede escribir. */
    public function show(WhatsappConversacion $conversacion): JsonResponse
    {
        $mensajes = $conversacion->mensajes()
            ->with('usuario')
            ->orderByDesc('id_mensaje')
            ->limit(self::LIMITE_MENSAJES)
            ->get()
            ->reverse()
            ->values();

        $folios = WhatsappAviso::query()
            ->join('pedidos', 'pedidos.id_pedido', '=', 'whatsapp_avisos.id_pedido')
            ->whereIn('whatsapp_avisos.wamid', $mensajes->where('tipo', WhatsappMensaje::TIPO_AVISO)->pluck('wamid'))
            ->pluck('pedidos.numero_pedido', 'whatsapp_avisos.wamid')
            ->all();

        $pedidos = WhatsappSolicitud::query()
            ->with('pedido')
            ->where('id_conversacion', $conversacion->id_conversacion)
            ->whereNotNull('id_pedido')
            ->orderByDesc('id_solicitud')
            ->get()
            ->map(fn (WhatsappSolicitud $s) => $s->pedido)
            ->filter()
            ->map(fn ($pedido) => [
                'id_pedido' => $pedido->id_pedido,
                'numero_pedido' => $pedido->numero_pedido,
                'estado' => $pedido->estado,
            ])
            ->values();

        $cierra = $conversacion->ventanaCierraEn();

        return response()->json([
            ...(new WhatsappConversacionResource($conversacion->load('cliente')))->resolve(),
            'ventana_abierta' => $cierra !== null && $cierra->isFuture(),
            'ventana_cierra_en' => $cierra,
            'pedidos' => $pedidos,
            'mensajes' => $mensajes->map(fn (WhatsappMensaje $m) => (new WhatsappMensajeResource($m, $folios))->resolve()),
        ]);
    }

    /**
     * RN-57 y RN-58: una persona contesta con texto dentro de las 24 horas, y con eso el bot se
     * calla dos horas y la conversación deja de esperar.
     */
    public function responder(Request $request, WhatsappConversacion $conversacion, WhatsappClient $cliente): JsonResponse
    {
        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:4096'],
        ]);

        $cierra = $conversacion->ventanaCierraEn();

        if ($cierra === null || $cierra->isPast()) {
            return response()->json([
                'message' => 'Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp no deja escribirle hasta que vuelva a escribir.',
            ], 422);
        }

        $numero = WhatsappNumero::where('id_tenant', tenant()->getTenantKey())->where('estado', 'Activo')->oldest('id_numero')->first();

        if ($numero === null) {
            return response()->json(['message' => 'La flotilla no tiene un número de WhatsApp conectado.'], 422);
        }

        $texto = trim($datos['texto']);

        try {
            $wamid = $cliente->enviarTexto($numero, $conversacion, $texto);
        } catch (RequestException|ConnectionException $e) {
            Log::warning('WhatsApp: no se pudo enviar la respuesta del Panel.', [
                'id_conversacion' => $conversacion->id_conversacion,
                'error' => $e instanceof RequestException ? ($e->response->json('error.message') ?? $e->getMessage()) : $e->getMessage(),
            ]);

            return response()->json(['message' => 'WhatsApp no aceptó el mensaje. Intenta de nuevo en un momento.'], 502);
        }

        $mensaje = WhatsappMensaje::create([
            'id_conversacion' => $conversacion->id_conversacion,
            'wamid' => $wamid,
            'direccion' => WhatsappMensaje::SALIENTE,
            'tipo' => 'text',
            'texto' => $texto,
            'id_usuario' => $request->user('usuario')->id_usuario,
            'enviado_en' => now(),
        ]);

        $conversacion->pide_persona_en = null;
        $conversacion->pausarBot();
        $conversacion->save();

        return response()->json((new WhatsappMensajeResource($mensaje->load('usuario')))->resolve(), 201);
    }

    /** RN-58: el bot vuelve a contestar sin esperar a que pasen las dos horas. */
    public function devolverAlBot(WhatsappConversacion $conversacion): JsonResponse
    {
        $conversacion->update(['bot_pausado_hasta' => null, 'pide_persona_en' => null]);

        return response()->json((new WhatsappConversacionResource($conversacion->load('cliente')))->resolve());
    }
}
