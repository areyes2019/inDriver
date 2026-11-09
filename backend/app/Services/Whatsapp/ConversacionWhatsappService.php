<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Models\Tenant\Cliente;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\DireccionCliente;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\Tenant\WhatsappSolicitud;
use App\Models\Tenant\ZonaServicio;
use App\Services\CreacionPedidoService;
use App\Services\GeocodificadorService;
use App\Services\PedidoEstadoService;
use App\Services\RutaService;
use App\Services\TarifaService;
use App\Support\HoraOperacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * La conversación con la que un cliente pide un envío por WhatsApp (spec tenant/031, etapa 6).
 *
 * Lleva la hoja del pedido (D18), pregunta lo que falta de uno en uno (D16, RN-35), arma el resumen
 * con botones (RN-42) y crea el pedido al confirmar (RN-44). La IA solo dice qué trae cada mensaje;
 * coordenadas, cobertura, teléfonos, tarifa y textos los decide Laravel (RN-23, D17).
 */
class ConversacionWhatsappService
{
    public const BOTON_CONFIRMAR = 'confirmar';

    public const BOTON_CORREGIR = 'corregir';

    public const BOTON_CANCELAR = 'cancelar';

    /** Sin mensajes del bot en este tiempo, la conversación empieza con saludo (RN-36). */
    private const HORAS_PARA_SALUDAR = 12;

    /** Pasos en los que una ubicación compartida responde la pregunta (RN-41). */
    private const PASOS_DE_DIRECCION = ['entrega', 'recogida', 'recogida_ofrecida'];

    private ?string $telefonoFlotilla = null;

    public function __construct(
        private readonly ExtractorPedidoIA $extractor,
        private readonly GeocodificadorService $geocodificador,
        private readonly RutaService $rutas,
        private readonly TarifaService $tarifas,
        private readonly CreacionPedidoService $creacion,
        private readonly FrasesBot $frases,
    ) {}

    /**
     * @param  Collection<int, WhatsappMensaje>  $pendientes  Lo que el cliente mandó desde la última respuesta, del más viejo al más nuevo.
     */
    public function atender(WhatsappConversacion $conversacion, Collection $pendientes, ?string $telefonoFlotilla): RespuestaBot
    {
        $this->telefonoFlotilla = $telefonoFlotilla;
        $cliente = $this->clienteDe($conversacion);

        // RN-15.
        if ($cliente?->estado === 'Inactivo') {
            return $this->texto('cliente_inactivo', ['contacto' => $this->frases->contacto($telefonoFlotilla)]);
        }

        $solicitud = $this->solicitudVigente($conversacion);
        $boton = $pendientes->last(fn (WhatsappMensaje $m) => $m->boton !== null);

        // RN-32: un botón no pasa por la IA.
        if ($boton !== null) {
            return $this->atenderBoton($boton->boton, $solicitud, $conversacion, $cliente);
        }

        $ubicacion = $pendientes->last(fn (WhatsappMensaje $m) => $m->latitud !== null && $m->longitud !== null);
        $texto = $pendientes
            ->filter(fn (WhatsappMensaje $m) => $m->tipo === 'text' && $m->texto !== null && trim($m->texto) !== '')
            ->pluck('texto')
            ->implode("\n");

        if ($ubicacion === null && $texto === '') {
            return $this->texto('solo_texto');
        }

        $saludo = $this->tocaSaludar($conversacion) ? $this->frases->saludo().' ' : '';
        $esNueva = $solicitud === null;
        $solicitud ??= new WhatsappSolicitud(['id_conversacion' => $conversacion->id_conversacion, 'estado' => WhatsappSolicitud::ABIERTA]);
        $avisos = [];
        $cambio = false;

        if ($ubicacion !== null) {
            $cambio = $this->aplicarUbicacion($solicitud, $ubicacion->latitud, $ubicacion->longitud, $avisos);
        }

        if ($texto !== '') {
            try {
                $extraccion = $this->extractor->extraer($this->hoja($solicitud), $this->ultimaPregunta($conversacion), $texto);
            } catch (ExtraccionFallida) {
                // RN-24: nunca silencio.
                return new RespuestaBot($saludo.$this->frases->decir('ia_fallo'));
            }

            switch ($extraccion->intencion) {
                case ExtraccionPedido::INTENCION_PERSONA:
                    return $this->texto('persona', ['contacto' => $this->frases->contacto($telefonoFlotilla)]);
                case ExtraccionPedido::INTENCION_CANCELAR:
                    return $this->cancelar($esNueva ? null : $solicitud);
                case ExtraccionPedido::INTENCION_CONFIRMAR:
                    if ($solicitud->estado === WhatsappSolicitud::CONFIRMANDO) {
                        return $this->confirmar($solicitud, $conversacion, $cliente);
                    }
                    break;
            }

            $cambio = $this->aplicar($solicitud, $extraccion, $texto, $conversacion, $cliente, $avisos) || $cambio;
            $cliente = $this->clienteDe($conversacion);
        }

        // RN-35: un primer mensaje sin datos recibe la lista corta de lo que hace falta.
        if ($esNueva && ! $cambio && $avisos === []) {
            $solicitud->paso = 'bienvenida';
            $solicitud->save();

            return new RespuestaBot($saludo.$this->frases->decir('bienvenida'));
        }

        // Un "gracias" frente al resumen no cambia nada: se recuerdan los botones.
        if ($solicitud->estado === WhatsappSolicitud::CONFIRMANDO && ! $cambio && $avisos === []) {
            return $this->texto('pulsa_boton');
        }

        return $this->avanzar($solicitud, $conversacion, $cliente, $saludo, $cambio, $avisos);
    }

    /**
     * Pregunta lo siguiente que falta o, si ya está todo, manda el resumen.
     *
     * @param  array<string, string>  $avisos  dato => aviso
     */
    private function avanzar(WhatsappSolicitud $solicitud, WhatsappConversacion $conversacion, ?Cliente $cliente, string $saludo, bool $cambio, array $avisos): RespuestaBot
    {
        $siguiente = $this->siguientePaso($solicitud, $conversacion, $cliente);

        if ($siguiente !== null) {
            [$paso, $clave, $variables] = $siguiente;
            $solicitud->paso = $paso;
            $solicitud->estado = WhatsappSolicitud::ABIERTA;
            $solicitud->save();

            $partes = array_filter([
                trim($saludo),
                $avisos === [] && $cambio ? $this->frases->decir('anotado') : null,
                ...array_values($avisos),
            ]);

            // Un aviso ya pregunta por su dato; la pregunta solo se agrega si es de otro.
            if (! array_key_exists($this->datoDelPaso($paso), $avisos)) {
                $partes[] = $this->frases->decir($clave, $variables);
            }

            return new RespuestaBot(implode(' ', $partes));
        }

        if ($solicitud->importe_envio === null && ! $this->cotizar($solicitud)) {
            $solicitud->save();

            return $this->texto('sin_precio', ['contacto' => $this->frases->contacto($this->telefonoFlotilla)]);
        }

        $solicitud->paso = 'confirmacion';
        $solicitud->estado = WhatsappSolicitud::CONFIRMANDO;
        $solicitud->save();

        return new RespuestaBot(
            mb_substr($this->resumen($solicitud), 0, 1024),
            [self::BOTON_CONFIRMAR => 'Confirmar', self::BOTON_CORREGIR => 'Corregir', self::BOTON_CANCELAR => 'Cancelar'],
        );
    }

    /** El dato que pregunta cada paso, para saber si un aviso ya lo cubre. */
    private function datoDelPaso(string $paso): string
    {
        return $paso === 'recogida_ofrecida' ? 'recogida' : $paso;
    }

    /**
     * RN-35. Devuelve [paso, frase, variables], o `null` si la hoja está completa.
     *
     * @return array{0: string, 1: string, 2: array<string, string>}|null
     */
    private function siguientePaso(WhatsappSolicitud $solicitud, WhatsappConversacion $conversacion, ?Cliente $cliente): ?array
    {
        $nombre = ['nombre' => $solicitud->destinatario_nombre ?? 'quien recibe'];

        if ($solicitud->destinatario_nombre === null) {
            return ['destinatario_nombre', 'destinatario_nombre', []];
        }

        if (! $solicitud->tieneEntrega()) {
            return ['entrega', 'entrega', $nombre];
        }

        if ($solicitud->destinatario_telefono === null) {
            return ['destinatario_telefono', 'destinatario_telefono', $nombre];
        }

        if (! $solicitud->tieneRecogida()) {
            $guardada = $solicitud->id_direccion_ofrecida === null ? $this->direccionGuardada($cliente) : null;

            if ($guardada !== null) {
                $solicitud->id_direccion_ofrecida = $guardada->id_direccion;

                return ['recogida_ofrecida', 'recogida_ofrecida', ['direccion' => $guardada->calle]];
            }

            return ['recogida', 'recogida', []];
        }

        // RN-06: quien envía paga y se cobra un producto no se admite en esta versión.
        if ($solicitud->quien_paga === ExtraccionPedido::PAGA_REMITENTE && $solicitud->cobro_producto === ExtraccionPedido::SI) {
            $solicitud->quien_paga = null;

            return ['quien_paga', 'remitente_con_cobro', $nombre];
        }

        if ($solicitud->quien_paga === null) {
            return ['quien_paga', 'quien_paga', $nombre];
        }

        // RN-37: siempre se pregunta.
        if ($solicitud->cobro_producto === null) {
            return ['cobro_producto', 'cobro_producto', $nombre];
        }

        if ($solicitud->cobro_producto === ExtraccionPedido::SI && ($solicitud->importe_producto ?? 0) <= 0) {
            return ['importe_producto', 'importe_producto', $nombre];
        }

        // RN-13 y RN-39: el cliente nuevo se registra antes del resumen.
        if ($cliente === null && $conversacion->telefono === null && $solicitud->cliente_telefono === null) {
            return ['cliente_telefono', 'cliente_telefono', []];
        }

        if ($cliente === null) {
            return ['cliente_nombre', 'cliente_nombre', []];
        }

        return null;
    }

    /**
     * Anota en la hoja lo que trae el mensaje. Devuelve si cambió algo.
     *
     * @param  array<string, string>  $avisos  dato => aviso
     */
    private function aplicar(WhatsappSolicitud $solicitud, ExtraccionPedido $e, string $textoOriginal, WhatsappConversacion $conversacion, ?Cliente $cliente, array &$avisos): bool
    {
        $cambio = false;

        if ($e->destinatarioNombre !== '') {
            $solicitud->destinatario_nombre = $e->destinatarioNombre;
            $cambio = true;
        }

        if ($e->entregaDireccion !== '') {
            $cambio = $this->ubicar($solicitud, 'entrega', $e->entregaDireccion, $avisos) || $cambio;
        }

        if ($e->recogidaDireccion !== '') {
            $cambio = $this->ubicar($solicitud, 'recogida', $e->recogidaDireccion, $avisos) || $cambio;
        } elseif ($e->aceptaDireccionOfrecida === ExtraccionPedido::SI && $solicitud->paso === 'recogida_ofrecida') {
            $cambio = $this->usarDireccionOfrecida($solicitud, $cliente, $avisos) || $cambio;
        }

        // Al preguntar el teléfono del propio cliente, los dígitos del mensaje son suyos y no de
        // quien recibe. Se leen del texto: no hace falta la IA para encontrar 10 dígitos.
        if ($solicitud->paso === 'cliente_telefono' && preg_match('/\d/', $textoOriginal) === 1) {
            $telefono = $this->telefonoMexicano($textoOriginal);

            if ($telefono === null) {
                $avisos['cliente_telefono'] = $this->frases->decir('telefono_invalido');
            } else {
                $solicitud->cliente_telefono = $telefono;
                $cambio = true;
            }
        } elseif ($e->destinatarioTelefono !== '') {
            $telefono = $this->telefonoMexicano($e->destinatarioTelefono);

            if ($telefono === null) {
                $avisos['destinatario_telefono'] = $this->frases->decir('telefono_invalido');
            } else {
                $solicitud->destinatario_telefono = $telefono;
                $cambio = true;
            }
        }

        if ($e->quienPagaEnvio !== ExtraccionPedido::NO_DICHO) {
            $solicitud->quien_paga = $e->quienPagaEnvio;
            $cambio = true;
        }

        if ($e->importeProducto > 0) {
            $solicitud->cobro_producto = ExtraccionPedido::SI;
            $solicitud->importe_producto = $e->importeProducto;
            $cambio = true;
        } elseif ($e->hayCobroProducto !== ExtraccionPedido::NO_DICHO) {
            $solicitud->cobro_producto = $e->hayCobroProducto;
            $solicitud->importe_producto = $e->hayCobroProducto === ExtraccionPedido::NO ? null : $solicitud->importe_producto;
            $cambio = true;
        }

        if ($e->nombreCliente !== '' && $cliente === null && $solicitud->paso === 'cliente_nombre') {
            $this->registrarCliente($conversacion, $e->nombreCliente, $solicitud->cliente_telefono);
            $cambio = true;
        }

        return $cambio;
    }

    /**
     * RN-16 y RN-17: la dirección se anota solo si Google la ubica con precisión y cae en la cobertura.
     *
     * @param  'entrega'|'recogida'  $punto
     * @param  array<string, string>  $avisos  dato => aviso
     */
    private function ubicar(WhatsappSolicitud $solicitud, string $punto, string $texto, array &$avisos): bool
    {
        // RN-38: una dirección que no cambió no se vuelve a buscar.
        if ($solicitud->{"{$punto}_texto"} === $texto && $solicitud->{"{$punto}_lat"} !== null) {
            return false;
        }

        $solicitud->{"{$punto}_texto"} = $texto;
        $this->limpiarPunto($solicitud, $punto);
        $lugar = $this->geocodificador->geocodificar($texto);

        if ($lugar === null) {
            $avisos[$punto] = $this->frases->decir('direccion_imprecisa', ['texto' => $texto]);

            return false;
        }

        return $this->anotarPunto($solicitud, $punto, $lugar['lat'], $lugar['lng'], $lugar['direccion'], $avisos);
    }

    /**
     * RN-41: la ubicación compartida responde la dirección que se estaba preguntando.
     *
     * @param  array<string, string>  $avisos  dato => aviso
     */
    private function aplicarUbicacion(WhatsappSolicitud $solicitud, float $lat, float $lng, array &$avisos): bool
    {
        $punto = match (true) {
            in_array($solicitud->paso, self::PASOS_DE_DIRECCION, true) => $solicitud->paso === 'entrega' ? 'entrega' : 'recogida',
            ! $solicitud->tieneEntrega() => 'entrega',
            default => 'recogida',
        };

        $direccion = $this->geocodificador->direccionDe($lat, $lng);
        $solicitud->{"{$punto}_texto"} = $direccion;
        $this->limpiarPunto($solicitud, $punto);

        return $this->anotarPunto($solicitud, $punto, $lat, $lng, $direccion, $avisos);
    }

    /**
     * @param  array<string, string>  $avisos  dato => aviso
     */
    private function usarDireccionOfrecida(WhatsappSolicitud $solicitud, ?Cliente $cliente, array &$avisos): bool
    {
        $guardada = $cliente?->direcciones()->whereKey($solicitud->id_direccion_ofrecida)->first();

        if ($guardada === null || $guardada->latitud === null || $guardada->longitud === null) {
            return false;
        }

        $solicitud->recogida_texto = $guardada->calle;

        return $this->anotarPunto($solicitud, 'recogida', (float) $guardada->latitud, (float) $guardada->longitud, $guardada->calle, $avisos);
    }

    /**
     * @param  'entrega'|'recogida'  $punto
     * @param  array<string, string>  $avisos  dato => aviso
     */
    private function anotarPunto(WhatsappSolicitud $solicitud, string $punto, float $lat, float $lng, string $direccion, array &$avisos): bool
    {
        if (! ZonaServicio::cubrePunto($lat, $lng)) {
            $avisos[$punto] = $this->frases->decir('fuera_de_cobertura', ['direccion' => $direccion]);

            return false;
        }

        $solicitud->{"{$punto}_direccion"} = $direccion;
        $solicitud->{"{$punto}_lat"} = $lat;
        $solicitud->{"{$punto}_lng"} = $lng;

        return true;
    }

    /**
     * @param  'entrega'|'recogida'  $punto
     */
    private function limpiarPunto(WhatsappSolicitud $solicitud, string $punto): void
    {
        $solicitud->{"{$punto}_direccion"} = null;
        $solicitud->{"{$punto}_lat"} = null;
        $solicitud->{"{$punto}_lng"} = null;
        // Otra dirección es otra distancia: el precio se vuelve a calcular.
        $solicitud->importe_envio = null;
        $solicitud->km = null;
    }

    /**
     * RN-01 y RN-02: la tarifa la calcula el servidor con la distancia por calle, o no se cotiza.
     */
    private function cotizar(WhatsappSolicitud $solicitud): bool
    {
        $tarifas = $this->tarifas->vigentes();

        if ($tarifas === null) {
            Log::warning('WhatsApp: la flotilla no tiene tarifas configuradas.');

            return false;
        }

        $km = $this->rutas->distanciaPorCalleKm(
            ['lat' => (float) $solicitud->recogida_lat, 'lng' => (float) $solicitud->recogida_lng],
            ['lat' => (float) $solicitud->entrega_lat, 'lng' => (float) $solicitud->entrega_lng],
        );

        if ($km === null) {
            return false;
        }

        $solicitud->km = round($km, 1);
        $solicitud->importe_envio = $this->tarifas->importe($km, $tarifas);

        return true;
    }

    private function resumen(WhatsappSolicitud $solicitud): string
    {
        $paga = $solicitud->quien_paga === ExtraccionPedido::PAGA_REMITENTE ? 'tú' : $solicitud->destinatario_nombre;
        $lineas = [
            $this->frases->decir('resumen'),
            '',
            "📦 Recoger en: {$solicitud->recogida_direccion}",
            "📍 Entregar en: {$solicitud->entrega_direccion}",
            "👤 Recibe: {$solicitud->destinatario_nombre}, {$solicitud->destinatario_telefono}",
            "🛵 Envío: {$this->pesos($solicitud->importe_envio)} ({$solicitud->km} km), lo paga {$paga}",
        ];

        if ($solicitud->cobro_producto === ExtraccionPedido::SI) {
            $producto = (float) $solicitud->importe_producto;
            $total = $producto + (float) $solicitud->importe_envio;
            $regreso = round((float) $solicitud->importe_envio * PedidoEstadoService::PORCENTAJE_TARIFA_REGRESO / 100, 2);

            $lineas[] = "💵 Producto: {$this->pesos($producto)}";
            $lineas[] = "💰 {$solicitud->destinatario_nombre} paga en total: {$this->pesos($total)}";
            $lineas[] = '';
            // D9, D10 y RN-09.
            $lineas[] = "Al recoger, el repartidor te adelanta {$this->pesos($producto)} por el producto. Si {$solicitud->destinatario_nombre} no paga, te regresa el paquete y tú le devuelves {$this->pesos($producto + (float) $solicitud->importe_envio + $regreso)}: el adelanto, el envío y {$this->pesos($regreso)} por el regreso.";
        }

        $lineas[] = '';
        $lineas[] = $this->frases->decir('resumen_pregunta');

        return implode("\n", $lineas);
    }

    private function atenderBoton(string $boton, ?WhatsappSolicitud $solicitud, WhatsappConversacion $conversacion, ?Cliente $cliente): RespuestaBot
    {
        if ($boton === self::BOTON_CANCELAR) {
            return $this->cancelar($solicitud);
        }

        if ($solicitud === null) {
            $confirmada = $this->ultimaConfirmada($conversacion);

            return $boton === self::BOTON_CONFIRMAR && $confirmada !== null
                ? $this->texto('ya_confirmado', ['folio' => $confirmada])
                : $this->texto('sin_pendiente');
        }

        if ($solicitud->estado !== WhatsappSolicitud::CONFIRMANDO) {
            // Un botón de un resumen viejo: se retoma la pregunta en curso.
            return $this->avanzar($solicitud, $conversacion, $cliente, '', false, []);
        }

        if ($boton === self::BOTON_CORREGIR) {
            $solicitud->update(['estado' => WhatsappSolicitud::ABIERTA, 'paso' => 'correccion']);

            return $this->texto('que_cambiamos');
        }

        return $this->confirmar($solicitud, $conversacion, $cliente);
    }

    /**
     * RN-20 y RN-44. La solicitud se toma con un UPDATE condicionado antes de crear el pedido: si
     * llegan dos confirmaciones, solo una lo crea.
     */
    private function confirmar(WhatsappSolicitud $solicitud, WhatsappConversacion $conversacion, ?Cliente $cliente): RespuestaBot
    {
        // D3: en TEST no se crean pedidos por WhatsApp.
        if (ConfiguracionTenant::obtener(ConfiguracionTenant::AMBIENTE, Pedido::AMBIENTE_LIVE) === Pedido::AMBIENTE_TEST) {
            $solicitud->update(['estado' => WhatsappSolicitud::DESCARTADA]);

            return $this->texto('servicio_no_disponible', ['contacto' => $this->frases->contacto($this->telefonoFlotilla)]);
        }

        $tomada = WhatsappSolicitud::whereKey($solicitud->id_solicitud)
            ->where('estado', WhatsappSolicitud::CONFIRMANDO)
            ->whereNull('id_pedido')
            ->update(['estado' => WhatsappSolicitud::CONFIRMADA]);

        if ($tomada === 0) {
            $folio = $solicitud->fresh()?->pedido?->numero_pedido;

            return $this->texto('ya_confirmado', ['folio' => $folio ?? 'tu pedido']);
        }

        try {
            $pedido = $this->creacion->crear($this->datosDelPedido($solicitud, $cliente), null);
        } catch (\Throwable $e) {
            // Se devuelve a CONFIRMANDO para que el reintento del job lo intente otra vez.
            WhatsappSolicitud::whereKey($solicitud->id_solicitud)->update(['estado' => WhatsappSolicitud::CONFIRMANDO]);

            throw $e;
        }

        WhatsappSolicitud::whereKey($solicitud->id_solicitud)->update(['id_pedido' => $pedido->id_pedido]);
        $this->guardarDireccion($cliente, $solicitud);

        return $this->texto('confirmado', ['folio' => $pedido->numero_pedido]);
    }

    /**
     * Los mismos campos que entrega el Panel a `CreacionPedidoService`. Quien escribe es el cliente
     * y quien recibe va en `*_solicitante`, como desde la SPEC-006 (RN-11).
     *
     * @return array<string, mixed>
     */
    private function datosDelPedido(WhatsappSolicitud $solicitud, ?Cliente $cliente): array
    {
        $cobra = $solicitud->cobro_producto === ExtraccionPedido::SI;

        return [
            'id_cliente' => $cliente?->id_cliente,
            'nombre_solicitante' => $solicitud->destinatario_nombre,
            'telefono_solicitante' => $solicitud->destinatario_telefono,
            'direccion_recogida' => mb_substr((string) $solicitud->recogida_direccion, 0, 255),
            'latitud_recogida' => $solicitud->recogida_lat,
            'longitud_recogida' => $solicitud->recogida_lng,
            'direccion_entrega' => mb_substr((string) $solicitud->entrega_direccion, 0, 255),
            'latitud_entrega' => $solicitud->entrega_lat,
            'longitud_entrega' => $solicitud->entrega_lng,
            'fecha_servicio' => HoraOperacion::hoy(),
            'lo_antes_posible' => true,
            'modalidad_pago' => match (true) {
                $cobra => 'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
                $solicitud->quien_paga === ExtraccionPedido::PAGA_REMITENTE => 'REMITENTE_PAGA_ENVIO',
                default => 'RECEPTOR_PAGA_ENVIO',
            },
            'importe_envio' => $solicitud->importe_envio,
            'importe_cobro' => $cobra ? $solicitud->importe_producto : 0,
        ];
    }

    private function cancelar(?WhatsappSolicitud $solicitud): RespuestaBot
    {
        if ($solicitud === null || ! $solicitud->exists) {
            return $this->texto('sin_pendiente');
        }

        $solicitud->update(['estado' => WhatsappSolicitud::CANCELADA]);

        return $this->texto('cancelado');
    }

    /**
     * La hoja abierta de la conversación. Una sin actividad en 2 horas se descarta (RN-45).
     */
    private function solicitudVigente(WhatsappConversacion $conversacion): ?WhatsappSolicitud
    {
        $solicitud = WhatsappSolicitud::where('id_conversacion', $conversacion->id_conversacion)
            ->whereIn('estado', [WhatsappSolicitud::ABIERTA, WhatsappSolicitud::CONFIRMANDO])
            ->latest('id_solicitud')
            ->first();

        if ($solicitud !== null && $solicitud->updated_at->lt(now()->subHours(WhatsappSolicitud::HORAS_DE_VIDA))) {
            $solicitud->update(['estado' => WhatsappSolicitud::DESCARTADA]);

            return null;
        }

        return $solicitud;
    }

    private function ultimaConfirmada(WhatsappConversacion $conversacion): ?string
    {
        return WhatsappSolicitud::where('id_conversacion', $conversacion->id_conversacion)
            ->where('estado', WhatsappSolicitud::CONFIRMADA)
            ->where('updated_at', '>=', now()->subHours(WhatsappSolicitud::HORAS_DE_VIDA))
            ->latest('id_solicitud')
            ->first()?->pedido?->numero_pedido;
    }

    /**
     * RN-13: el cliente se busca por los últimos 10 dígitos de su teléfono de WhatsApp, porque en el
     * Panel se capturan con espacios, guiones o sin lada.
     */
    private function clienteDe(WhatsappConversacion $conversacion): ?Cliente
    {
        if ($conversacion->id_cliente !== null) {
            return $conversacion->cliente;
        }

        $diez = $conversacion->telefono !== null ? $this->telefonoMexicano($conversacion->telefono) : null;

        if ($diez === null) {
            return null;
        }

        $cliente = Cliente::whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefono, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE ?",
            ['%'.$diez],
        )->oldest('id_cliente')->first();

        if ($cliente !== null) {
            $conversacion->update(['id_cliente' => $cliente->id_cliente]);
            $conversacion->setRelation('cliente', $cliente);
        }

        return $cliente;
    }

    /** RN-39 y RN-14: el nombre se le pregunta; no se copia del perfil. */
    private function registrarCliente(WhatsappConversacion $conversacion, string $nombre, ?string $telefono): void
    {
        $telefono ??= $conversacion->telefono !== null ? $this->telefonoMexicano($conversacion->telefono) : null;

        $cliente = Cliente::create([
            'nombre' => mb_substr($nombre, 0, 255),
            'telefono' => $telefono,
            'estado' => 'Activo',
            'referencia' => 'Registrado por WhatsApp',
        ]);

        $conversacion->update(['id_cliente' => $cliente->id_cliente]);
        $conversacion->setRelation('cliente', $cliente);
    }

    /** RN-40: la dirección de recogida más reciente del cliente, si está ubicada. */
    private function direccionGuardada(?Cliente $cliente): ?DireccionCliente
    {
        return $cliente?->direcciones()
            ->whereNotNull('latitud')
            ->whereNotNull('longitud')
            ->latest('updated_at')
            ->latest('id_direccion')
            ->first();
    }

    /**
     * La recogida queda guardada para ofrecerla la próxima vez (RN-40). Si ya existe, solo se
     * marca como la más reciente.
     */
    private function guardarDireccion(?Cliente $cliente, WhatsappSolicitud $solicitud): void
    {
        if ($cliente === null) {
            return;
        }

        $existente = $cliente->direcciones()
            ->whereBetween('latitud', [$solicitud->recogida_lat - 0.0002, $solicitud->recogida_lat + 0.0002])
            ->whereBetween('longitud', [$solicitud->recogida_lng - 0.0002, $solicitud->recogida_lng + 0.0002])
            ->first();

        if ($existente !== null) {
            $existente->touch();

            return;
        }

        $cliente->direcciones()->create([
            'alias' => 'WhatsApp',
            'calle' => mb_substr((string) $solicitud->recogida_direccion, 0, 255),
            'latitud' => $solicitud->recogida_lat,
            'longitud' => $solicitud->recogida_lng,
        ]);
    }

    /**
     * Lo anotado, en palabras, para que la IA entienda una respuesta corta.
     *
     * @return array<string, string>
     */
    private function hoja(WhatsappSolicitud $solicitud): array
    {
        return array_filter([
            'Quien recibe' => $solicitud->destinatario_nombre,
            'Teléfono de quien recibe' => $solicitud->destinatario_telefono,
            'Entrega' => $solicitud->entrega_direccion ?? $solicitud->entrega_texto,
            'Recogida' => $solicitud->recogida_direccion ?? $solicitud->recogida_texto,
            'Paga el envío' => $solicitud->quien_paga,
            'Cobrar producto' => $solicitud->cobro_producto,
            'Importe del producto' => $solicitud->importe_producto !== null ? (string) $solicitud->importe_producto : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    private function ultimaPregunta(WhatsappConversacion $conversacion): ?string
    {
        return $conversacion->mensajes()
            ->where('direccion', WhatsappMensaje::SALIENTE)
            ->latest('id_mensaje')
            ->value('texto');
    }

    private function tocaSaludar(WhatsappConversacion $conversacion): bool
    {
        return ! $conversacion->mensajes()
            ->where('direccion', WhatsappMensaje::SALIENTE)
            ->where('enviado_en', '>=', now()->subHours(self::HORAS_PARA_SALUDAR))
            ->exists();
    }

    /**
     * Acepta 10 dígitos, o 12 y 13 con lada de país (`52` y el `521` de los móviles viejos).
     */
    private function telefonoMexicano(string $telefono): ?string
    {
        $digitos = preg_replace('/\D+/', '', $telefono) ?? '';

        return preg_match('/^(?:521|52)?(\d{10})$/', $digitos, $partes) === 1 ? $partes[1] : null;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function texto(string $clave, array $variables = []): RespuestaBot
    {
        return new RespuestaBot($this->frases->decir($clave, $variables));
    }

    private function pesos(?float $importe): string
    {
        return '$'.number_format((float) $importe, 2);
    }
}
