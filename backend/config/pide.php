<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| App de clientes (spec tenant/035)
|--------------------------------------------------------------------------
*/

return [

    'codigo' => [
        // RN-13: `correo` en todos lados; `log` solo existe en desarrollo local y en pruebas. El
        // arranque se niega a usar `log` en cualquier otro ambiente (AppServiceProvider).
        'canal' => env('OTP_CANAL', 'correo'),

        // Remitente de los códigos. El nombre visible es el de la flotilla.
        'remitente' => env('OTP_MAIL_FROM', env('MAIL_FROM_ADDRESS')),

        'digitos' => 6,
        'vigencia_minutos' => 10,
        'intentos_por_codigo' => 5,

        // RN-12.
        'limites' => [
            'por_minuto_correo' => 1,
            'por_hora_correo' => 5,
            'por_dia_correo' => 10,
            'por_hora_ip' => 20,
            'por_hora_flotilla' => 300,
            // Por debajo del plan de Resend (Free: 100 al día) para no agotar el correo de todo el
            // sistema, que también manda credenciales y recuperación de contraseñas.
            'por_dia_global' => (int) env('OTP_TOPE_DIARIO', 80),
            'fallos_por_hora' => 10,
        ],

        // RN-10: revisar que el dominio del correo reciba correo (registro MX). Se apaga en pruebas
        // para no depender de la red.
        'verificar_dominio' => (bool) env('OTP_VERIFICAR_DOMINIO', true),
    ],

];
