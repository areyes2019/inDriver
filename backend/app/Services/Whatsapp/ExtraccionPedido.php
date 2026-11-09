<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * Lo que la IA entendió de la conversación (spec tenant/031, RN-22). Es dato no confiable: quien lo
 * use lo vuelve a validar (RN-23). Un campo vacío significa "el cliente no lo dijo".
 */
final readonly class ExtraccionPedido
{
    public const INTENCION_PEDIDO = 'pedido_envio';

    public const INTENCION_PERSONA = 'hablar_con_persona';

    public const INTENCION_OTRA = 'otra';

    public const PAGA_REMITENTE = 'remitente';

    public const PAGA_DESTINATARIO = 'destinatario';

    public const NO_DICHO = 'no_dicho';

    public const COBRO_SI = 'si';

    public const COBRO_NO = 'no';

    public function __construct(
        public string $intencion,
        public string $recogidaDireccion,
        public string $entregaDireccion,
        public string $destinatarioNombre,
        public string $destinatarioTelefono,
        public string $quienPagaEnvio,
        public string $hayCobroProducto,
        public float $importeProducto,
    ) {}

    /**
     * Esquema de la salida estructurada. Sin campos nulos: "no lo dijo" es cadena vacía o
     * `no_dicho`, para que el modelo nunca tenga que inventar un valor.
     *
     * @return array<string, mixed>
     */
    public static function esquema(): array
    {
        $texto = fn (string $descripcion) => ['type' => 'string', 'description' => $descripcion];

        return [
            'type' => 'object',
            'properties' => [
                'intencion' => [
                    'type' => 'string',
                    'enum' => [self::INTENCION_PEDIDO, self::INTENCION_PERSONA, self::INTENCION_OTRA],
                    'description' => 'pedido_envio si el cliente quiere mandar algo o pide precio; hablar_con_persona si pide hablar con alguien; otra en cualquier otro caso, incluido un saludo sin pedido.',
                ],
                'recogida_direccion' => $texto('Dirección donde se recoge el paquete, tal como la escribió el cliente. Vacío si no la dio.'),
                'entrega_direccion' => $texto('Dirección donde se entrega, tal como la escribió el cliente. Vacío si no la dio.'),
                'destinatario_nombre' => $texto('Nombre de quien recibe. Vacío si no lo dio.'),
                'destinatario_telefono' => $texto('Teléfono de quien recibe, solo dígitos. Vacío si no lo dio.'),
                'quien_paga_envio' => [
                    'type' => 'string',
                    'enum' => [self::PAGA_REMITENTE, self::PAGA_DESTINATARIO, self::NO_DICHO],
                    'description' => 'remitente si paga quien envía (el cliente); destinatario si paga quien recibe; no_dicho si no quedó claro.',
                ],
                'hay_cobro_producto' => [
                    'type' => 'string',
                    'enum' => [self::COBRO_SI, self::COBRO_NO, self::NO_DICHO],
                    'description' => 'si cuando hay que cobrarle a quien recibe el precio de un producto (contra entrega); no si el cliente dijo que no; no_dicho si no lo mencionó.',
                ],
                'importe_producto' => [
                    'type' => 'number',
                    'description' => 'Precio del producto a cobrar a quien recibe, en pesos. 0 si no aplica o no lo dijo.',
                ],
            ],
            'required' => [
                'intencion', 'recogida_direccion', 'entrega_direccion', 'destinatario_nombre',
                'destinatario_telefono', 'quien_paga_envio', 'hay_cobro_producto', 'importe_producto',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Valores fuera de la lista se tratan como "no lo dijo": el esquema los impide, pero el
     * resultado sigue siendo dato no confiable (RN-23).
     *
     * @param  array<string, mixed>  $datos
     */
    public static function desdeArreglo(array $datos): self
    {
        $texto = fn (string $clave): string => is_string($datos[$clave] ?? null) ? trim($datos[$clave]) : '';
        $opcion = fn (string $clave, array $validas): string => in_array($datos[$clave] ?? null, $validas, true)
            ? $datos[$clave]
            : self::NO_DICHO;

        $intencion = $datos['intencion'] ?? null;
        $importe = $datos['importe_producto'] ?? 0;

        return new self(
            intencion: in_array($intencion, [self::INTENCION_PEDIDO, self::INTENCION_PERSONA], true) ? $intencion : self::INTENCION_OTRA,
            recogidaDireccion: $texto('recogida_direccion'),
            entregaDireccion: $texto('entrega_direccion'),
            destinatarioNombre: $texto('destinatario_nombre'),
            destinatarioTelefono: preg_replace('/\D+/', '', $texto('destinatario_telefono')) ?? '',
            quienPagaEnvio: $opcion('quien_paga_envio', [self::PAGA_REMITENTE, self::PAGA_DESTINATARIO]),
            hayCobroProducto: $opcion('hay_cobro_producto', [self::COBRO_SI, self::COBRO_NO]),
            importeProducto: is_numeric($importe) && $importe > 0 ? round((float) $importe, 2) : 0.0,
        );
    }
}
