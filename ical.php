<?php
declare(strict_types=1);

/*
 * Calendario de un usuario en formato iCalendar (RFC 5545).
 * Google Calendar y Outlook se suscriben a esta URL (ver Mi perfil) y la
 * vuelven a consultar periódicamente (Google: cada varias horas; Outlook: ~3 h).
 * Acceso por token secreto; no usa sesión ni la restricción por IP.
 */

define('SIN_SESION', true);
require __DIR__ . '/app/bootstrap.php';

$token = (string)($_GET['t'] ?? '');
$usuario = preg_match('/^[a-f0-9]{48}$/', $token)
    ? q_uno('SELECT id, nombre FROM usuarios WHERE ical_token = ? AND activo = 1', [$token])
    : null;
if (!$usuario) {
    http_response_code(404);
    exit('Calendario no encontrado.');
}

function ical_texto(?string $v): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', ''], (string)$v);
}

/** Corta las líneas a 75 bytes como exige el estándar. */
function ical_linea(string $linea): string
{
    $salida = '';
    while (strlen($linea) > 75) {
        $corte = 75;
        // No cortar en medio de un carácter UTF-8
        while ($corte > 0 && (ord($linea[$corte]) & 0xC0) === 0x80) {
            $corte--;
        }
        $salida .= substr($linea, 0, $corte) . "\r\n ";
        $linea = substr($linea, $corte);
    }
    return $salida . $linea . "\r\n";
}

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/';
$dominio = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'crm');
$marca = gmdate('Ymd\THis\Z');
$desde = date('Y-m-d', strtotime('-60 days'));

$tareas = q_todos(
    "SELECT t.*, c.nombre AS cliente, e.nombre AS empresa, e.identificacion AS rut
     FROM tareas t LEFT JOIN clientes c ON c.id = t.cliente_id LEFT JOIN empresas e ON e.id = t.empresa_id
     WHERE t.responsable_id = ? AND t.vencimiento IS NOT NULL
       AND (t.estado <> 'completada' OR t.vencimiento >= ?)
     ORDER BY t.vencimiento", [$usuario['id'], $desde]);

$gestiones = q_todos(
    "SELECT a.*, c.nombre AS cliente, e.nombre AS empresa
     FROM actividades a LEFT JOIN clientes c ON c.id = a.cliente_id LEFT JOIN empresas e ON e.id = a.empresa_id
     WHERE a.usuario_id = ? AND a.fecha >= ?
     ORDER BY a.fecha", [$usuario['id'], $desde . ' 00:00:00']);

$l = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//ProAction//CRM//ES',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:' . ical_texto(config('app_nombre', 'CRM') . ' · ' . $usuario['nombre']),
    'X-WR-TIMEZONE:' . config('zona_horaria', 'America/Santiago'),
    'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
    'X-PUBLISHED-TTL:PT1H',
];

// Tareas: evento de día completo en la fecha de vencimiento.
foreach ($tareas as $t) {
    $hecha = $t['estado'] === 'completada';
    $titulo = ($hecha ? '✓ ' : '') . $t['titulo'] . ($t['cliente'] ? ' · ' . $t['cliente'] : '');
    $detalle = trim(implode("\n", array_filter([
        'Estado: ' . (ESTADOS_TAREA[$t['estado']] ?? $t['estado']),
        $t['empresa'] ? 'RUT: ' . $t['empresa'] . ' (' . $t['rut'] . ')' : null,
        $t['descripcion'],
        $base . 'index.php?r=tareas&a=ver&id=' . $t['id'],
    ])));
    array_push($l,
        'BEGIN:VEVENT',
        'UID:tarea-' . $t['id'] . '@' . $dominio,
        'DTSTAMP:' . $marca,
        'LAST-MODIFIED:' . gmdate('Ymd\THis\Z', strtotime($t['actualizado_en'])),
        'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($t['vencimiento'])),
        'DTEND;VALUE=DATE:' . date('Ymd', strtotime($t['vencimiento'] . ' +1 day')),
        'SUMMARY:' . ical_texto($titulo),
        'DESCRIPTION:' . ical_texto($detalle),
        'URL:' . $base . 'index.php?r=tareas&a=ver&id=' . $t['id'],
        'TRANSP:TRANSPARENT',
        'STATUS:' . ($hecha ? 'CANCELLED' : 'CONFIRMED'),
        'CATEGORIES:Tarea'
    );
    if (!$hecha) {
        array_push($l, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ical_texto($t['titulo']), 'TRIGGER:-PT15H', 'END:VALARM');
    }
    $l[] = 'END:VEVENT';
}

// Gestiones (reuniones, llamadas agendadas…): evento de 1 hora a la hora indicada.
foreach ($gestiones as $a) {
    $inicio = strtotime($a['fecha']);
    array_push($l,
        'BEGIN:VEVENT',
        'UID:gestion-' . $a['id'] . '@' . $dominio,
        'DTSTAMP:' . $marca,
        'DTSTART:' . gmdate('Ymd\THis\Z', $inicio),
        'DTEND:' . gmdate('Ymd\THis\Z', $inicio + 3600),
        'SUMMARY:' . ical_texto((TIPOS_ACTIVIDAD[$a['tipo']] ?? '') . ': ' . $a['asunto'] . ($a['cliente'] ? ' · ' . $a['cliente'] : '')),
        'DESCRIPTION:' . ical_texto(trim(($a['descripcion'] ?? '') . "\n" . $base . 'index.php?r=actividades&a=form&id=' . $a['id'])),
        'STATUS:CONFIRMED',
        'CATEGORIES:Gestión',
        'END:VEVENT'
    );
}
$l[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="crm.ics"');
header('Cache-Control: no-cache, must-revalidate');
foreach ($l as $linea) {
    echo ical_linea($linea);
}
