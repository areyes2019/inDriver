<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\Tenant\ZonaServicio;
use App\Services\GeocodificadorService;
use App\Services\PedidoEstadoService;
use App\Services\RutaService;
use App\Services\TarifaService;
use Illuminate\Support\Facades\Log;

/**
 * Etapa 5 de la spec tenant/031: lee la conversación, valida lo que entendió la IA y contesta con
 * lo que entendió y el precio. No crea pedidos ni guarda nada: eso llega en la etapa 6.
 *
 * La IA solo extrae texto. Coordenadas, cobertura, teléfono y tarifa los decide Laravel (RN-23), y
 * todo lo que falta se pregunta en un solo mensaje (RN-34).
 */
class AsistentePedidoWhatsapp
{
    /** Lo que el cliente dijo hace más de esto ya no cuenta para el pedido que está armando. */
    private const HORAS_DE_CONTEXTO = 2;

    private const MENSAJES_DE_CONTEXTO = 12;

    public function __construct(
        private readonly ExtractorPedidoIA $extractor,
        private readonly GeocodificadorService $geocodificador,
        private readonly RutaService $rutas,
        private readonly TarifaService $tarifas,
    ) {}

    public function responder(WhatsappConversacion $conversacion, ?string $telefonoFlotilla): string
    {
        try {
            $pedido = $this->extractor->extraer($this->contexto($conversacion));
        } catch (ExtraccionFallida) {
            // RN-24: nunca silencio, ni siquiera si la IA falla.
            return 'Perdón, no pude leer bien tu mensaje. ¿Me dices dónde recogemos el paquete y a dónde lo llevamos?';
        }

        return match ($pedido->intencion) {
            ExtraccionPedido::INTENCION_PERSONA => $this->conPersona($telefonoFlotilla),
            ExtraccionPedido::INTENCION_PEDIDO => $this->cotizar($pedido, $telefonoFlotilla),
            default => "¡Hola! Te ayudo a cotizar un envío. Dime:\n"
                ."• Dónde recogemos (calle, número y colonia)\n"
                ."• Dónde entregamos\n"
                ."• Nombre y teléfono de quien recibe\n"
                .'• Quién paga el envío, y si hay que cobrarle algo a quien recibe',
        };
    }

    /**
     * La conversación reciente, de la más vieja a la más nueva. Incluye lo que contestó el bot, para
     * que un "a Juan" se entienda como respuesta a "¿quién recibe?".
     *
     * @return list<array{rol: 'cliente'|'negocio', texto: string}>
     */
    private function contexto(WhatsappConversacion $conversacion): array
    {
        return $conversacion->mensajes()
            ->whereNotNull('texto')
            ->where('enviado_en', '>=', now()->subHours(self::HORAS_DE_CONTEXTO))
            ->latest('enviado_en')
            ->latest('id_mensaje')
            ->limit(self::MENSAJES_DE_CONTEXTO)
            ->get(['direccion', 'texto'])
            ->reverse()
            ->map(fn (WhatsappMensaje $mensaje) => [
                'rol' => $mensaje->direccion === WhatsappMensaje::ENTRANTE ? 'cliente' : 'negocio',
                'texto' => (string) $mensaje->texto,
            ])
            ->values()
            ->all();
    }

    private function cotizar(ExtraccionPedido $pedido, ?string $telefonoFlotilla): string
    {
        // RN-06: cobrar un producto cuando el envío ya lo pagó quien envía no se admite en esta versión.
        if ($pedido->quienPagaEnvio === ExtraccionPedido::PAGA_REMITENTE && $pedido->hayCobroProducto === ExtraccionPedido::COBRO_SI) {
            return 'Ese tipo de envío (tú pagas el envío y se le cobra un producto a quien recibe) lo atiende la flotilla directamente. '
                .$this->comoContactar($telefonoFlotilla);
        }

        $faltantes = [];
        $avisos = [];

        $recogida = $this->ubicar($pedido->recogidaDireccion, 'recogemos', $faltantes, $avisos);
        $entrega = $this->ubicar($pedido->entregaDireccion, 'entregamos', $faltantes, $avisos);

        if ($pedido->destinatarioNombre === '') {
            $faltantes[] = 'El nombre de quien recibe';
        }

        $telefono = $this->telefonoMexicano($pedido->destinatarioTelefono);

        if ($telefono === null) {
            $faltantes[] = $pedido->destinatarioTelefono === ''
                ? 'El teléfono de quien recibe'
                : 'El teléfono de quien recibe, a 10 dígitos';
        }

        // RN-04: si no queda claro quién paga o cuánto, se pregunta.
        if ($pedido->quienPagaEnvio === ExtraccionPedido::NO_DICHO) {
            $faltantes[] = '¿Quién paga el envío: tú o quien recibe?';
        }

        if ($pedido->hayCobroProducto === ExtraccionPedido::NO_DICHO) {
            $faltantes[] = '¿Hay que cobrarle algo a quien recibe además del envío? (por ejemplo, el precio de un producto)';
        } elseif ($pedido->hayCobroProducto === ExtraccionPedido::COBRO_SI && $pedido->importeProducto <= 0) {
            $faltantes[] = '¿Cuánto hay que cobrarle a quien recibe por el producto?';
        }

        $envio = null;

        if ($recogida !== null && $entrega !== null) {
            $envio = $this->precio($recogida, $entrega);

            if ($envio === null) {
                return 'Ahorita no puedo calcular el precio de ese envío. '.$this->comoContactar($telefonoFlotilla);
            }
        }

        return $this->armarRespuesta($pedido, $recogida, $entrega, $telefono, $envio, $faltantes, $avisos);
    }

    /**
     * @param  list<string>  $faltantes
     * @param  list<string>  $avisos
     * @return array{lat: float, lng: float, direccion: string}|null
     */
    private function ubicar(string $direccion, string $verbo, array &$faltantes, array &$avisos): ?array
    {
        if ($direccion === '') {
            $faltantes[] = "Dónde {$verbo} (calle, número y colonia)";

            return null;
        }

        $punto = $this->geocodificador->geocodificar($direccion);

        // RN-16: nunca se adivina.
        if ($punto === null) {
            $faltantes[] = "No encontré con precisión «{$direccion}». ¿Me das calle, número, colonia y ciudad?";

            return null;
        }

        // RN-17.
        if (! ZonaServicio::cubrePunto($punto['lat'], $punto['lng'])) {
            $avisos[] = "«{$punto['direccion']}» está fuera de la zona donde damos servicio.";

            return null;
        }

        return $punto;
    }

    /**
     * RN-01 y RN-02: la tarifa la calcula el servidor con la distancia por calle, o no se cotiza.
     *
     * @param  array{lat: float, lng: float}  $recogida
     * @param  array{lat: float, lng: float}  $entrega
     * @return array{importe: float, km: float}|null
     */
    private function precio(array $recogida, array $entrega): ?array
    {
        $tarifas = $this->tarifas->vigentes();

        if ($tarifas === null) {
            Log::warning('WhatsApp: la flotilla no tiene tarifas configuradas.');

            return null;
        }

        $km = $this->rutas->distanciaPorCalleKm(
            ['lat' => $recogida['lat'], 'lng' => $recogida['lng']],
            ['lat' => $entrega['lat'], 'lng' => $entrega['lng']],
        );

        if ($km === null) {
            return null;
        }

        return ['importe' => $this->tarifas->importe($km, $tarifas), 'km' => round($km, 1)];
    }

    /**
     * Acepta 10 dígitos, o 12 y 13 con lada de país (`52` y el `521` de los móviles viejos).
     */
    private function telefonoMexicano(string $digitos): ?string
    {
        if (preg_match('/^(?:521|52)?(\d{10})$/', $digitos, $partes) !== 1) {
            return null;
        }

        return $partes[1];
    }

    /**
     * @param  array{lat: float, lng: float, direccion: string}|null  $recogida
     * @param  array{lat: float, lng: float, direccion: string}|null  $entrega
     * @param  array{importe: float, km: float}|null  $envio
     * @param  list<string>  $faltantes
     * @param  list<string>  $avisos
     */
    private function armarRespuesta(
        ExtraccionPedido $pedido,
        ?array $recogida,
        ?array $entrega,
        ?string $telefono,
        ?array $envio,
        array $faltantes,
        array $avisos,
    ): string {
        $lineas = ['Esto es lo que entendí:'];

        if ($recogida !== null) {
            $lineas[] = "📦 Recoger en: {$recogida['direccion']}";
        }

        if ($entrega !== null) {
            $lineas[] = "📍 Entregar en: {$entrega['direccion']}";
        }

        if ($pedido->destinatarioNombre !== '' || $telefono !== null) {
            $lineas[] = '👤 Recibe: '.trim($pedido->destinatarioNombre.($telefono !== null ? ", {$telefono}" : ''), ', ');
        }

        if ($pedido->quienPagaEnvio !== ExtraccionPedido::NO_DICHO) {
            $lineas[] = '💳 Paga el envío: '.($pedido->quienPagaEnvio === ExtraccionPedido::PAGA_REMITENTE ? 'tú' : 'quien recibe');
        }

        $cobroProducto = $pedido->hayCobroProducto === ExtraccionPedido::COBRO_SI && $pedido->importeProducto > 0
            ? $pedido->importeProducto
            : null;

        if ($envio !== null) {
            $lineas[] = "🛵 Envío: {$this->pesos($envio['importe'])} ({$envio['km']} km)";
        }

        if ($cobroProducto !== null) {
            $lineas[] = "💵 Producto a cobrar: {$this->pesos($cobroProducto)}";

            if ($envio !== null && $pedido->quienPagaEnvio === ExtraccionPedido::PAGA_DESTINATARIO) {
                $lineas[] = "💰 Quien recibe paga en total: {$this->pesos($cobroProducto + $envio['importe'])}";
            }
        }

        if (count($lineas) === 1) {
            $lineas = [];
        }

        foreach ($avisos as $aviso) {
            $lineas[] = "⚠️ {$aviso}";
        }

        if ($faltantes !== []) {
            $lineas[] = '';
            $lineas[] = $envio === null ? 'Para cotizar me falta:' : 'Para completar el pedido me falta:';

            foreach ($faltantes as $faltante) {
                $lineas[] = "• {$faltante}";
            }
        }

        if ($envio !== null && $faltantes === [] && $avisos === [] && $cobroProducto !== null) {
            // D9, D10 y RN-09: el repartidor adelanta el producto, y el contra entrega avisa del
            // regreso antes de confirmar.
            $regreso = round($envio['importe'] * PedidoEstadoService::PORCENTAJE_TARIFA_REGRESO / 100, 2);
            $lineas[] = '';
            $lineas[] = "Al recoger, el repartidor te paga {$this->pesos($cobroProducto)} por el producto.";
            $lineas[] = "Si quien recibe no paga, el repartidor te regresa el paquete y tú le pagas {$this->pesos($cobroProducto + $envio['importe'] + $regreso)}: el adelanto, el envío y {$this->pesos($regreso)} por el regreso.";
        }

        $lineas[] = '';
        $lineas[] = '_Esto es una prueba: todavía no se crea el pedido._';

        return trim(implode("\n", $lineas));
    }

    private function conPersona(?string $telefonoFlotilla): string
    {
        return 'Claro. '.$this->comoContactar($telefonoFlotilla);
    }

    /**
     * RN-26: siempre hay una vía para hablar con una persona.
     */
    private function comoContactar(?string $telefonoFlotilla): string
    {
        return $telefonoFlotilla !== null && $telefonoFlotilla !== ''
            ? "Puedes hablar con una persona de la flotilla al {$telefonoFlotilla}."
            : 'Por ahora no hay una persona disponible por este medio; comunícate directamente con la flotilla.';
    }

    private function pesos(float $importe): string
    {
        return '$'.number_format($importe, 2);
    }
}
