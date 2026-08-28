<?php

/*
 * Front controller de PRODUCCIÓN. Se copia a public_html/index.php y es el único
 * archivo de Laravel que queda dentro del docroot.
 *
 * Es una copia de backend/public/index.php con una sola diferencia: las rutas
 * apuntan a '../backend' en vez de '../', porque en el servidor el proyecto no
 * está encima del docroot sino a su lado:
 *
 *     domains/delivery.prosello.com.mx/
 *     ├── backend/         <- app/ bootstrap/ config/ storage/ vendor/ .env
 *     └── public_html/     <- este archivo + el SPA compilado
 *
 * La ruta es relativa a propósito: funciona igual en cualquier dominio de la
 * cuenta, sin ninguna ruta absoluta que mantener.
 *
 * Se versiona aparte en vez de editar backend/public/index.php porque ese otro
 * tiene que seguir funcionando en Laragon, donde el proyecto sí está en '../'.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$base = __DIR__.'/../backend';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $base.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $base.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
