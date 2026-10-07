<?php
declare(strict_types=1);

/*
 * API para la rutina de Claude que clasifica los correos.
 *
 *   GET  api_correos.php            → correos por clasificar (extractos) + contexto mínimo del CRM
 *   POST api_correos.php  (JSON)    → { "propuestas": [...], "procesados": [ids] }
 *        guarda las propuestas en la Bandeja de correos (cola de revisión) y marca esos correos
 *        como clasificados. No toca clientes, tareas, gestiones ni oportunidades.
 *
 * Acceso: cabecera X-Correos-Token con el token de la rutina. En la base solo se guarda su hash
 * (Sistema → Correos → Generar token). No usa sesión ni la restricción por IP, igual que ical.php.
 */

define('SIN_SESION', true);
require __DIR__ . '/app/bootstrap.php';

const API_CORREOS_MAX_LOTE = 100;
const API_CORREOS_MAX_BYTES = 2000000;

function api_responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$hashConfigurado = strtolower(trim((string)ajuste('correos_token_sha256', '')));
if (!preg_match('/^[a-f0-9]{64}$/', $hashConfigurado)) {
    api_responder(503, ['ok' => false, 'error' => 'La API de correos no está configurada (falta generar el token en Sistema → Correos).']);
}
$token = (string)($_SERVER['HTTP_X_CORREOS_TOKEN'] ?? '');
if ($token === '' || !hash_equals($hashConfigurado, hash('sha256', $token))) {
    usleep(700000); // frena los intentos a ciegas
    error_log('CRM - api_correos: token inválido desde ' . ip_cliente());
    api_responder(403, ['ok' => false, 'error' => 'Token inválido.']);
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- Correos por clasificar ---------- */
if ($metodo === 'GET') {
    $limite = max(1, min(API_CORREOS_MAX_LOTE, (int)($_GET['limite'] ?? 60)));
    $correos = q_todos(
        "SELECT id, message_id, buzones, remitente, para, asunto, recibido_en, cuerpo
         FROM correos_entrantes WHERE estado = 'nuevo' ORDER BY recibido_en, id LIMIT $limite");
    api_responder(200, [
        'ok'        => true,
        'ahora'     => date('Y-m-d H:i'),
        'zona'      => date_default_timezone_get(),
        'pendientes_total' => correos_por_clasificar(),
        'correos'   => array_map(static fn($c) => [
            'id' => (int)$c['id'], 'correo_id' => $c['message_id'], 'buzones' => $c['buzones'],
            'remitente' => $c['remitente'], 'para' => $c['para'], 'asunto' => $c['asunto'],
            'recibido_en' => substr((string)$c['recibido_en'], 0, 16), 'cuerpo' => $c['cuerpo'],
        ], $correos),
        // Contexto para reconocer clientes, prospectos y al equipo (solo nombres y RUT)
        'contexto'  => [
            'equipo'     => q_todos('SELECT nombre, email FROM usuarios WHERE activo = 1 ORDER BY nombre'),
            'clientes'   => q_todos('SELECT nombre, rut FROM clientes WHERE activo = 1 ORDER BY nombre'),
            'prospectos' => array_column(q_todos("SELECT titulo FROM oportunidades WHERE etapa NOT IN ('ganada', 'perdida') ORDER BY titulo"), 'titulo'),
        ],
    ]);
}

if ($metodo !== 'POST') {
    api_responder(405, ['ok' => false, 'error' => 'Use GET o POST.']);
}

/* ---------- Propuestas de la rutina ---------- */
$crudo = file_get_contents('php://input', false, null, 0, API_CORREOS_MAX_BYTES + 1);
if ($crudo === false || $crudo === '' || strlen($crudo) > API_CORREOS_MAX_BYTES) {
    api_responder(413, ['ok' => false, 'error' => 'Cuerpo vacío o demasiado grande.']);
}
$cuerpo = json_decode($crudo, true);
if (!is_array($cuerpo)) {
    api_responder(400, ['ok' => false, 'error' => 'El cuerpo no es JSON válido.']);
}
$propuestas = $cuerpo['propuestas'] ?? [];
$procesados = $cuerpo['procesados'] ?? [];
if (!is_array($propuestas) || !array_is_list($propuestas) || !is_array($procesados) || !array_is_list($procesados)) {
    api_responder(400, ['ok' => false, 'error' => '"propuestas" y "procesados" deben ser listas.']);
}
if (count($propuestas) > 300 || count($procesados) > API_CORREOS_MAX_LOTE) {
    api_responder(413, ['ok' => false, 'error' => 'Demasiados elementos en un envío.']);
}

// Los datos del correo los pone el servidor (no se confía en lo que copie la rutina)
$desconocidas = [];
foreach ($propuestas as $i => $p) {
    $c = is_array($p) ? q_uno('SELECT * FROM correos_entrantes WHERE correo_hash = ?', [bandeja_hash_correo((string)($p['correo_id'] ?? ''))]) : null;
    if (!$c) {
        $desconocidas[] = ['posicion' => $i, 'motivo' => 'correo_id no corresponde a un correo recibido'];
        unset($propuestas[$i]);
        continue;
    }
    $buzones = array_filter(array_map('trim', explode(',', (string)$c['buzones'])));
    $propuestas[$i] = array_merge($p, [
        'correo_id'   => $c['message_id'],
        'remitente'   => $c['remitente'],
        'asunto'      => $c['asunto'],
        'recibido_en' => $c['recibido_en'],
        'buzones'     => $c['buzones'],
        'responsable_email' => ($p['responsable_email'] ?? '') ?: (count($buzones) === 1 ? reset($buzones) : ''),
    ]);
}

db()->beginTransaction();
try {
    $r = bandeja_guardar_propuestas(array_values($propuestas));
    $marcados = 0;
    foreach (array_unique(array_map('intval', $procesados)) as $id) {
        $mid = q_valor("SELECT message_id FROM correos_entrantes WHERE id = ? AND estado = 'nuevo'", [$id]);
        if ($mid === false || $mid === null) {
            continue;
        }
        $n = count(array_filter($propuestas, static fn($p) => $p['correo_id'] === $mid));
        q("UPDATE correos_entrantes SET estado = 'clasificado', propuestas = ?, clasificado_en = ? WHERE id = ?", [$n, ahora(), $id]);
        $marcados++;
    }
    db()->commit();
} catch (Throwable $ex) {
    db()->rollBack();
    error_log('CRM - api_correos: ' . $ex->getMessage());
    api_responder(500, ['ok' => false, 'error' => 'No se pudieron guardar las propuestas.']);
}

api_responder(200, [
    'ok'          => true,
    'nuevas'      => $r['nuevas'],
    'duplicadas'  => $r['duplicadas'],
    'invalidas'   => array_merge($desconocidas, $r['invalidas']),
    'procesados'  => $marcados,
    'quedan'      => correos_por_clasificar(),
]);
