<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Respaldo de push nativo cuando el socket de Reverb está caído (spec tenant/018). Solo se usa
    // como servicio de envío de mensajes — no implica ninguna base de datos de Firebase, MySQL
    // sigue siendo la única fuente de datos del sistema.
    // Rutas por calles para los envíos TEST (spec tenant/025). Solo la usa `RutaService`, del lado
    // del servidor: el Panel tiene su propia llave de navegador. Sin ella, la simulación cae a
    // línea recta en vez de romperse.
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'fcm' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
    ],

];
