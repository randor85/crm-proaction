<?php
declare(strict_types=1);

function ip_cliente(): string
{
    // Se usa REMOTE_ADDR a propósito: X-Forwarded-For puede ser falsificado.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ip_en_rango(string $ip, string $rango): bool
{
    if (strpos($rango, '/') === false) {
        return $ip === $rango;
    }
    [$red, $bits] = explode('/', $rango, 2);
    $ipBin = @inet_pton($ip);
    $redBin = @inet_pton($red);
    if ($ipBin === false || $redBin === false || strlen($ipBin) !== strlen($redBin)) {
        return false;
    }
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8);
    $resto = $bits % 8;
    if (strncmp($ipBin, $redBin, $bytes) !== 0) {
        return false;
    }
    if ($resto === 0) {
        return true;
    }
    $mascara = (0xFF << (8 - $resto)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mascara) === (ord($redBin[$bytes]) & $mascara);
}

/** Bloquea el acceso si la IP no está en la lista de la intranet. */
function verificar_ip(): void
{
    $permitidas = config('ips_permitidas', []);
    if (!$permitidas) {
        return;
    }
    $ip = ip_cliente();
    foreach ($permitidas as $rango) {
        if (ip_en_rango($ip, $rango)) {
            return;
        }
    }
    http_response_code(403);
    exit('Acceso restringido a la red interna de la empresa.');
}

function iniciar_sesion(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('crm_sesion');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    $limite = (int)config('sesion_minutos', 120) * 60;
    if (isset($_SESSION['ultimo_acceso']) && time() - $_SESSION['ultimo_acceso'] > $limite) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('aviso', 'Su sesión expiró por inactividad.');
    }
    $_SESSION['ultimo_acceso'] = time();
}

function usuario_actual(): ?array
{
    static $usuario = false;
    if ($usuario !== false) {
        return $usuario;
    }
    $id = $_SESSION['usuario_id'] ?? null;
    $usuario = $id ? q_uno(
        'SELECT id, nombre, email, rol, ver_credenciales, ical_token,
            CASE WHEN totp_secreto IS NULL THEN 0 ELSE 1 END AS totp_activo
         FROM usuarios WHERE id = ? AND activo = 1', [$id]) : null;
    return $usuario;
}

function es_admin(): bool
{
    return (usuario_actual()['rol'] ?? '') === 'admin';
}

function requerir_login(): void
{
    if (!usuario_actual()) {
        redirigir('login.php');
    }
}

function requerir_admin(): void
{
    if (!es_admin()) {
        http_response_code(403);
        exit('No tiene permisos para esta sección.');
    }
}

/** Solo el administrador o el responsable del registro pueden eliminarlo. */
function puede_eliminar(?array $registro, string $campo = 'responsable_id'): bool
{
    return es_admin() || ($registro && (int)($registro[$campo] ?? 0) === (int)usuario_actual()['id']);
}

const MAX_INTENTOS = 5;
const MINUTOS_BLOQUEO = 15;

function login_bloqueado(): bool
{
    $desde = date('Y-m-d H:i:s', time() - MINUTOS_BLOQUEO * 60);
    $n = (int)q_valor('SELECT COUNT(*) FROM login_intentos WHERE ip = ? AND creado_en > ?', [ip_cliente(), $desde]);
    return $n >= MAX_INTENTOS;
}

/**
 * Primer paso del inicio de sesión (correo y contraseña).
 * @return string 'ok' | '2fa' (falta el código de verificación) | 'error'
 */
function intentar_login(string $email, string $clave): string
{
    $u = q_uno('SELECT id, password_hash, totp_secreto FROM usuarios WHERE email = ? AND activo = 1', [mb_strtolower($email)]);
    if (!$u || !password_verify($clave, $u['password_hash'])) {
        registrar_intento_fallido();
        return 'error';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['clave_debil'] = clave_debil($clave, $email) !== null;
    if ($u['totp_secreto']) {
        $_SESSION['2fa_usuario'] = (int)$u['id'];
        $_SESSION['2fa_desde'] = time();
        return '2fa';
    }
    completar_login((int)$u['id']);
    return 'ok';
}

/** Segundo paso: código de la app autenticadora. */
function verificar_login_2fa(string $codigo): bool
{
    $id = $_SESSION['2fa_usuario'] ?? null;
    if (!$id || time() - ($_SESSION['2fa_desde'] ?? 0) > 300) {
        unset($_SESSION['2fa_usuario'], $_SESSION['2fa_desde']);
        return false;
    }
    $secreto = q_valor('SELECT totp_secreto FROM usuarios WHERE id = ? AND activo = 1', [$id]);
    if (!$secreto || !totp_verificar((string)$secreto, $codigo)) {
        registrar_intento_fallido();
        return false;
    }
    unset($_SESSION['2fa_usuario'], $_SESSION['2fa_desde']);
    completar_login((int)$id);
    return true;
}

function login_2fa_pendiente(): bool
{
    return !empty($_SESSION['2fa_usuario']) && time() - ($_SESSION['2fa_desde'] ?? 0) <= 300;
}

function registrar_intento_fallido(): void
{
    q('INSERT INTO login_intentos (ip, creado_en) VALUES (?, ?)', [ip_cliente(), ahora()]);
}

function completar_login(int $id): void
{
    q('DELETE FROM login_intentos WHERE ip = ?', [ip_cliente()]);
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $id;
    q('UPDATE usuarios SET ultimo_login = ? WHERE id = ?', [ahora(), $id]);
}
