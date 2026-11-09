<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * Lo que aporta o cambia el mensaje nuevo del cliente, según la IA (spec tenant/031, RN-22 y D18).
 *
 * Es dato no confiable: quien lo use lo vuelve a validar (RN-23). Un campo vacío o `no_dicho`
 * significa "el mensaje no lo menciona", no "bórralo".
 */
final readonly class ExtraccionPedido
{
    public const INTENCION_PEDIDO = 'pedido_envio';

    public const INTENCION_CONFIRMAR = 'confirmar';

    public const INTENCION_CANCELAR = 'cancelar';

    public const INTENCION_PERSONA = 'hablar_con_persona';

    public const INTENCION_OTRA = 'otra';

    public const PAGA_REMITENTE = 'remitente';

    public const PAGA_DESTINATARIO = 'destinatario';

    public const NO_DICHO = 'no_dicho';

    public const SI = 'si';

    public const NO = 'no';

    private const INTENCIONES = [
        self::INTENCION_PEDIDO, self::INTENCION_CONFIRMAR, self::INTENCION_CANCELAR, self::INTENCION_PERSONA, self::INTENCION_OTRA,
    ];

    public function __construct(
        public string $intencion,
        public string $recogidaDireccion,
        public string $entregaDireccion,
        public string $destinatarioNombre,
        public string $destinatarioTelefono,
        public string $quienPagaEnvio,
        public string $hayCobroProducto,
        public float $importeProducto,
        public string $nombreCliente = '',
        public string $aceptaDireccionOfrecida = self::NO_DICHO,
    ) {}

    /**
     * Esquema de la salida estructurada. Sin campos nulos: "no lo menciona" es cadena vacía o
     * `no_dicho`, para que el modelo nunca tenga que inventar un valor.
     *
     * @return array<string, mixed>
     */
    public static function esquema(): array
    {
        $texto = fn (string $descripcion) => ['type' => 'string', 'description' => $descripcion];
        $siNo = fn (string $descripcion) => ['type' => 'string', 'enum' => [self::SI, self::NO, self::NO_DICHO], 'description' => $descripcion];

        return [
            'type' => 'object',
            'properties' => [
                'intencion' => [
                    'type' => 'string',
                    'enum' => self::INTENCIONES,
                    'description' => 'pedido_envio si da o corrige datos de un envío, o pide uno; confirmar si acepta el resumen ("sí", "va", "confírmalo"); cancelar si ya no quiere el envío; hablar_con_persona si pide hablar con alguien; otra en cualquier otro caso, incluido un saludo sin datos.',
                ],
                'recogida_direccion' => $texto('Dirección donde se recoge, como la escribió el cliente. Vacío si el mensaje no la menciona.'),
                'entrega_direccion' => $texto('Dirección donde se entrega, como la escribió el cliente. Vacío si el mensaje no la menciona.'),
                'destinatario_nombre' => $texto('Nombre de quien recibe. Vacío si el mensaje no lo menciona.'),
                'destinatario_telefono' => $texto('Teléfono de quien recibe, solo dígitos. Vacío si el mensaje no lo menciona.'),
                'quien_paga_envio' => [
                    'type' => 'string',
                    'enum' => [self::PAGA_REMITENTE, self::PAGA_DESTINATARIO, self::NO_DICHO],
                    'description' => 'remitente si paga el cliente que escribe ("yo", "yo pago"); destinatario si paga quien recibe; no_dicho si el mensaje no lo dice.',
                ],
                'hay_cobro_producto' => $siNo('si cuando hay que cobrarle a quien recibe el precio de un producto; no si dice que no hay nada que cobrar; no_dicho si el mensaje no lo dice.'),
                'importe_producto' => [
                    'type' => 'number',
                    'description' => 'Precio del producto a cobrar a quien recibe, en pesos. 0 si el mensaje no lo dice.',
                ],
                'nombre_cliente' => $texto('El nombre del propio cliente, solo si el negocio se lo preguntó y lo contesta. Vacío en otro caso.'),
                'acepta_direccion_ofrecida' => $siNo('Si el negocio ofreció una dirección guardada para recoger: si si la acepta, no si la rechaza. no_dicho en otro caso.'),
            ],
            'required' => [
                'intencion', 'recogida_direccion', 'entrega_direccion', 'destinatario_nombre', 'destinatario_telefono',
                'quien_paga_envio', 'hay_cobro_producto', 'importe_producto', 'nombre_cliente', 'acepta_direccion_ofrecida',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Valores fuera de la lista se tratan como "no lo menciona": el esquema los impide, pero el
     * resultado sigue siendo dato no confiable (RN-23).
     *
     * @param  array<string, mixed>  $datos
     */
    public static function desdeArreglo(array $datos): self
    {
        $texto = fn (string $clave): string => is_string($datos[$clave] ?? null) ? mb_substr(trim($datos[$clave]), 0, 200) : '';
        $opcion = fn (string $clave, array $validas): string => in_array($datos[$clave] ?? null, $validas, true)
            ? $datos[$clave]
            : self::NO_DICHO;

        $intencion = $datos['intencion'] ?? null;
        $importe = $datos['importe_producto'] ?? 0;

        return new self(
            intencion: in_array($intencion, self::INTENCIONES, true) ? $intencion : self::INTENCION_OTRA,
            recogidaDireccion: $texto('recogida_direccion'),
            entregaDireccion: $texto('entrega_direccion'),
            destinatarioNombre: $texto('destinatario_nombre'),
            destinatarioTelefono: preg_replace('/\D+/', '', $texto('destinatario_telefono')) ?? '',
            quienPagaEnvio: $opcion('quien_paga_envio', [self::PAGA_REMITENTE, self::PAGA_DESTINATARIO]),
            hayCobroProducto: $opcion('hay_cobro_producto', [self::SI, self::NO]),
            importeProducto: is_numeric($importe) && $importe > 0 && $importe < 1_000_000 ? round((float) $importe, 2) : 0.0,
            nombreCliente: $texto('nombre_cliente'),
            aceptaDireccionOfrecida: $opcion('acepta_direccion_ofrecida', [self::SI, self::NO]),
        );
    }

    /** ¿El mensaje trae algún dato del envío? */
    public function traeDatos(): bool
    {
        return $this->recogidaDireccion !== '' || $this->entregaDireccion !== '' || $this->destinatarioNombre !== ''
            || $this->destinatarioTelefono !== '' || $this->quienPagaEnvio !== self::NO_DICHO
            || $this->hayCobroProducto !== self::NO_DICHO || $this->importeProducto > 0 || $this->nombreCliente !== ''
            || $this->aceptaDireccionOfrecida !== self::NO_DICHO;
    }
}
