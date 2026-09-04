<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // 'https://localhost' es el origen fijo que usa el WebView de Capacitor en Android por
    // defecto (hostname "localhost", esquema "https" — ver CapConfig.java del SDK de Capacitor);
    // es el mismo para cualquier build de panda_express, no depende del entorno, así que se deja
    // fijo en vez de por variable de entorno.
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173'), 'https://localhost'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
