<?php

declare(strict_types=1);

/**
 * Microservicio GPS (spec tenant/028).
 *
 * Los dos secretos no tienen valor por defecto a propósito: sin ellos el servicio y Laravel no se
 * reconocen, y un valor de relleno silenciaría el problema hasta producción.
 */
return [
    /** Secreto con el que se firma el permiso GPS. Distinto de APP_KEY (SPEC-028, §5.3). */
    'secreto' => env('GPS_TOKEN_SECRET'),

    /** Token fijo con el que Laravel llama a /gps/v1/nearby. */
    'token_servicio' => env('GPS_SERVICE_TOKEN'),

    /** Base del servicio, sin barra final. Vacío = integración apagada (RN-18, RN-19). */
    'url' => rtrim((string) env('GPS_SERVICE_URL', ''), '/'),

    /** RN-07: el permiso dura 30 minutos y la App lo renueva a los 20. */
    'vida_permiso' => (int) env('GPS_VIDA_PERMISO', 1800),
    'renovar_en' => (int) env('GPS_RENOVAR_EN', 1200),

    /** Segundos de espera al consultar cercanía. Corto: si no contesta, se cae al respaldo. */
    'timeout' => (float) env('GPS_TIMEOUT', 1.0),

    /** Conexión de Redis sin prefijo, compartida con el servicio (ver config/database.php). */
    'conexion' => env('GPS_REDIS_CONNECTION', 'gps'),

    'streams' => [
        'hacia_servicio' => env('GPS_STREAM_HACIA_SERVICIO', 'gps:hacia-servicio'),
        'hacia_laravel' => env('GPS_STREAM_HACIA_LARAVEL', 'gps:hacia-laravel'),
    ],

    /** Grupo de consumidores de `gps:consumir-buzon`. */
    'grupo' => env('GPS_GRUPO_LARAVEL', 'laravel'),
];
