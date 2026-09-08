<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Simulación de envíos TEST (spec tenant/025)
    |--------------------------------------------------------------------------
    |
    | RN-09 fija 60 km/h como la velocidad "realista" del modo TEST, y ese sigue siendo el valor
    | por omisión. Se hace configurable solo para desarrollo: esperar en tiempo real a que un móvil
    | recorra 8 km vuelve impracticable probar el flujo completo varias veces seguidas.
    |
    | `paso_segundos` es cada cuántos segundos simulados se escribe un punto. Subir la velocidad
    | separa más los puntos (a 240 km/h y paso 2s son ~133 m); bajar el paso a 1s los vuelve a
    | juntar, a costa del doble de escrituras y de eventos Reverb.
    |
    */

    'velocidad_kmh' => env('SIMULACION_VELOCIDAD_KMH', 60),

    'paso_segundos' => env('SIMULACION_PASO_SEGUNDOS', 2),

];
