<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\WhatsappAviso;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\Tenant\WhatsappSolicitud;
use App\Models\WhatsappNumero;
use App\Services\Whatsapp\FrasesBot;
use App\Services\Whatsapp\PlantillasWhatsapp;
use App\Services\Whatsapp\WhatsappClient;
use App\Support\TelefonoMexicano;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Avisa por WhatsApp a quien pidió un envío que su pedido cambió de estado (spec tenant/031, 7a).
 *
 * Corre en la cola `whatsapp`, fuera del cambio de estado: si Meta falla, el despacho no se entera
 * (RN-52). Cada momento se avisa una sola vez por pedido, aunque el job se reintente.
 */
class AvisarEstadoPedido implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** RN-47: estado del pedido => momento que se avisa. Los demás estados no avisan. */
    public const MOMENTOS = [
        'TOMADO' => 'asignado',
        'EN_CAMINO' => 'en_camino',
        'ENTREGADO' => 'entregado',
        'CANCELADO' => 'cancelado',
        'INCIDENCIA' => 'incidencia',
        'EN_REGRESO' => 'en_regreso',
        'DEVUELTO' => 'devuelto',
    ];

    /** RN-49: lo único que avisa un pedido del Panel, con su plantilla. */
    private const PLANTILLAS_DEL_PANEL = [
        'asignado' => PlantillasWhatsapp::ASIGNADO,
        'entregado' => PlantillasWhatsapp::ENTREGADO,
    ];

    private const VENTANA_HORAS = 24;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(
        private readonly TenantModel $tenant,
        private readonly int $idPedido,
        private readonly string $estado,
    ) {
        $this->onQueue(ProcesarMensajeWhatsapp::COLA);
    }

    public function handle(WhatsappClient $cliente, FrasesBot $frases): void
    {
        $yaInicializado = tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $this->tenant->getTenantKey();

        if (! $yaInicializado) {
            tenancy()->initialize($this->tenant);
        }

        try {
            $this->avisar($cliente, $frases);
        } finally {
            if (! $yaInicializado) {
                tenancy()->end();
            }
        }
    }

    private function avisar(WhatsappClient $cliente, FrasesBot $frases): void
    {
        $momento = self::MOMENTOS[$this->estado] ?? null;
        $pedido = Pedido::withoutGlobalScopes()->with(['cliente', 'conductor.usuario'])->find($this->idPedido);

        if ($momento === null || $pedido === null || WhatsappAviso::where('id_pedido', $pedido->id_pedido)->where('momento', $momento)->exists()) {
            return;
        }

        // Un envío de prueba no le escribe a nadie.
        if ($pedido->esTest()) {
            return;
        }

        $numero = WhatsappNumero::where('id_tenant', $this->tenant->getTenantKey())->where('estado', 'Activo')->oldest('id_numero')->first();

        if ($numero === null) {
            return;
        }

        $solicitud = WhatsappSolicitud::with('conversacion.cliente')->where('id_pedido', $pedido->id_pedido)->first();

        $solicitud !== null
            ? $this->avisarPedidoDeWhatsapp($cliente, $frases, $numero, $pedido, $solicitud->conversacion, $momento)
            : $this->avisarPedidoDelPanel($cliente, $numero, $pedido, $momento);
    }

    /** RN-47 y RN-48: texto normal, solo con la ventana de 24 horas abierta. */
    private function avisarPedidoDeWhatsapp(WhatsappClient $cliente, FrasesBot $frases, WhatsappNumero $numero, Pedido $pedido, WhatsappConversacion $conversacion, string $momento): void
    {
        if ($conversacion->cliente?->avisos_whatsapp === false) {
            $this->registrar($pedido, $momento, WhatsappAviso::OMITIDO, 'El cliente pidió BAJA');

            return;
        }

        $ultimoDelCliente = $conversacion->mensajes()->where('direccion', WhatsappMensaje::ENTRANTE)->max('enviado_en');

        if ($ultimoDelCliente === null || now()->subHours(self::VENTANA_HORAS)->gt($ultimoDelCliente)) {
            $this->registrar($pedido, $momento, WhatsappAviso::OMITIDO, 'Ventana de 24 horas cerrada');

            return;
        }

        $texto = $frases->decir("aviso_{$momento}", [
            'folio' => $pedido->numero_pedido,
            'repartidor' => $this->nombreRepartidor($pedido),
            'destinatario' => (string) $pedido->nombre_solicitante,
            'total' => '$'.number_format((float) $pedido->importe_cobro + (float) $pedido->importe_envio + (float) $pedido->tarifa_regreso, 2),
            'contacto' => $frases->contacto($this->tenant->telefono),
        ]);

        $wamid = $this->enviar($pedido, $momento, fn () => $cliente->enviarTexto($numero, $conversacion, $texto));

        if ($wamid !== null) {
            $this->guardarEnConversacion($conversacion, $wamid, $texto);
        }
    }

    /** RN-49: plantilla, solo asignado y entregado, solo con permiso del cliente. */
    private function avisarPedidoDelPanel(WhatsappClient $cliente, WhatsappNumero $numero, Pedido $pedido, string $momento): void
    {
        $plantilla = self::PLANTILLAS_DEL_PANEL[$momento] ?? null;
        $destinatario = $pedido->cliente;
        $diez = $destinatario?->telefono !== null ? TelefonoMexicano::diezDigitos($destinatario->telefono) : null;

        if ($plantilla === null || $destinatario === null || $diez === null || $destinatario->avisos_whatsapp !== true) {
            return;
        }

        $variables = $momento === 'asignado'
            ? [$destinatario->nombre, $pedido->numero_pedido, $this->nombreRepartidor($pedido)]
            : [$destinatario->nombre, $pedido->numero_pedido];

        $wamid = $this->enviar($pedido, $momento, fn () => $cliente->enviarPlantilla($numero, TelefonoMexicano::paraWhatsapp($diez), $plantilla, $variables));

        // Si el cliente ya escribió alguna vez, el aviso queda en su conversación para la bandeja.
        $conversacion = WhatsappConversacion::where('telefono', 'like', "%{$diez}")->first();

        if ($wamid !== null && $conversacion !== null) {
            $texto = str_replace(['{{1}}', '{{2}}', '{{3}}'], $variables + [2 => ''], PlantillasWhatsapp::PLANTILLAS[$plantilla]['texto']);
            $this->guardarEnConversacion($conversacion, $wamid, $texto);
        }
    }

    /**
     * Un rechazo de Meta (plantilla sin aprobar, número inválido) se anota y no se reintenta; una
     * caída sí, porque el job vuelve a intentarlo.
     *
     * @param  callable(): string  $envio
     */
    private function enviar(Pedido $pedido, string $momento, callable $envio): ?string
    {
        try {
            $wamid = $envio();
        } catch (RequestException $e) {
            if ($e->response->serverError()) {
                throw $e;
            }

            Log::warning('WhatsApp: Meta rechazó un aviso de pedido.', [
                'id_pedido' => $pedido->id_pedido,
                'momento' => $momento,
                'error' => $e->response->json('error.message'),
            ]);
            $this->registrar($pedido, $momento, WhatsappAviso::FALLIDO, mb_substr((string) $e->response->json('error.message'), 0, 255));

            return null;
        }

        $this->registrar($pedido, $momento, WhatsappAviso::ENVIADO, null, $wamid);

        return $wamid;
    }

    private function registrar(Pedido $pedido, string $momento, string $estado, ?string $motivo, ?string $wamid = null): void
    {
        WhatsappAviso::firstOrCreate(
            ['id_pedido' => $pedido->id_pedido, 'momento' => $momento],
            ['estado' => $estado, 'motivo' => $motivo, 'wamid' => $wamid],
        );
    }

    private function guardarEnConversacion(WhatsappConversacion $conversacion, string $wamid, string $texto): void
    {
        WhatsappMensaje::create([
            'id_conversacion' => $conversacion->id_conversacion,
            'wamid' => $wamid,
            'direccion' => WhatsappMensaje::SALIENTE,
            'tipo' => WhatsappMensaje::TIPO_AVISO,
            'texto' => $texto,
            'enviado_en' => now(),
        ]);
    }

    private function nombreRepartidor(Pedido $pedido): string
    {
        return (string) ($pedido->conductor?->usuario?->nombre ?? 'Tu repartidor');
    }
}
