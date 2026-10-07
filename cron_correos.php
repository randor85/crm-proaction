<?php
declare(strict_types=1);

/*
 * Lee por IMAP la bandeja de entrada de los buzones configurados (Sistema → Correos) y guarda los
 * correos nuevos para que la rutina de Claude los clasifique. Solo se ejecuta desde la línea de comandos
 * (cron del cPanel), p. ej. cada hora:
 *     /usr/local/bin/php /home/proactio/public_html/intranet.proactionconsultores.cl/crm/cron_correos.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SIN_SESION', true);
require __DIR__ . '/app/bootstrap.php';

foreach (correos_leer_buzones() as $linea) {
    echo date('Y-m-d H:i') . ' ' . $linea . PHP_EOL;
}
