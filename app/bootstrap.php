<?php
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('BASE_DIR', dirname(__DIR__));

if (!is_file(BASE_DIR . '/config.php')) {
    http_response_code(500);
    exit('Falta config.php. Copie config.sample.php como config.php y configure la base de datos.');
}

$GLOBALS['config'] = require BASE_DIR . '/config.php';
// Ajustes propios del servidor (rutas privadas, etc.) sin tocar config.php, que guarda la clave de la base.
if (is_file(BASE_DIR . '/config.local.php')) {
    $GLOBALS['config'] = array_replace($GLOBALS['config'], (array)require BASE_DIR . '/config.local.php');
}

date_default_timezone_set(config('zona_horaria', 'UTC'));
mb_internal_encoding('UTF-8');

require APP_DIR . '/helpers.php';
require APP_DIR . '/db.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/migraciones.php';
require APP_DIR . '/seguridad.php';
require APP_DIR . '/indicadores.php';
require APP_DIR . '/cobros.php';
require APP_DIR . '/cuentas.php';

// El feed de calendario (ical.php) lo consultan los servidores de Google/Microsoft:
// no usa sesión ni restricción por IP; se protege con un token secreto por usuario.
if (!defined('SIN_SESION')) {
    verificar_ip();
    iniciar_sesion();
}
if (!defined('INSTALANDO')) {
    migraciones_automaticas();
}

function config(string $clave, $defecto = null)
{
    return $GLOBALS['config'][$clave] ?? $defecto;
}
