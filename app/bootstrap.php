<?php
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('BASE_DIR', dirname(__DIR__));

if (!is_file(BASE_DIR . '/config.php')) {
    http_response_code(500);
    exit('Falta config.php. Copie config.sample.php como config.php y configure la base de datos.');
}

$GLOBALS['config'] = require BASE_DIR . '/config.php';

date_default_timezone_set(config('zona_horaria', 'UTC'));
mb_internal_encoding('UTF-8');

require APP_DIR . '/helpers.php';
require APP_DIR . '/db.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';

verificar_ip();
iniciar_sesion();

function config(string $clave, $defecto = null)
{
    return $GLOBALS['config'][$clave] ?? $defecto;
}
