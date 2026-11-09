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

    // spec tenant/031. El token de cada número se guarda cifrado en `whatsapp_numeros`.
    'whatsapp' => [
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
    ],

    // spec tenant/031, D5 y RN-33: la IA que lee los pedidos de WhatsApp. El modelo empieza en Haiku
    // y solo sube si no alcanza la precisión acordada.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'modelo_whatsapp' => env('WHATSAPP_IA_MODELO', 'claude-haiku-5-5'),
    ],

    'fcm' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
    ],

];
