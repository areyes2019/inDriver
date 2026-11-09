<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * Las plantillas de utilidad de los avisos de pedidos del Panel (spec tenant/031, RN-49 y D20).
 *
 * El texto vive solo aquí: `whatsapp:crear-plantillas` lo da de alta en Meta y los avisos lo usan,
 * así que no puede haber diferencias entre lo aprobado y lo que se manda. Cambiar un texto obliga a
 * cambiar también el nombre (por ejemplo `_v2`): Meta vuelve a revisar una plantilla editada y,
 * mientras tanto, la vieja sigue siendo la aprobada.
 */
final class PlantillasWhatsapp
{
    public const IDIOMA = 'es_MX';

    public const ASIGNADO = 'pedido_asignado_v1';

    public const ENTREGADO = 'pedido_entregado_v1';

    /**
     * nombre => [texto con {{n}}, ejemplo de cada variable]. Meta exige un ejemplo por variable.
     *
     * @var array<string, array{texto: string, ejemplo: list<string>}>
     */
    public const PLANTILLAS = [
        self::ASIGNADO => [
            'texto' => 'Hola {{1}}, tu envío {{2}} ya tiene repartidor: {{3}} va por el paquete.',
            'ejemplo' => ['Abdias', 'PED-000012', 'Juan'],
        ],
        self::ENTREGADO => [
            'texto' => 'Hola {{1}}, tu envío {{2}} ya fue entregado.',
            'ejemplo' => ['Abdias', 'PED-000012'],
        ],
    ];
}
