<?php
declare(strict_types=1);

/* ================================================================
 * Cifrado de credenciales (AES-256-GCM)
 * La llave vive en un archivo FUERA de public_html (config 'llave_archivo').
 * Si se pierde la llave, las claves guardadas no se pueden recuperar:
 * respalde ese archivo por separado de la base de datos.
 * ================================================================ */

function llave_ruta(): string
{
    return (string)config('llave_archivo', BASE_DIR . '/data/llave_credenciales.key');
}

function llave_existe(): bool
{
    return is_file(llave_ruta()) && is_readable(llave_ruta());
}

function llave(): string
{
    static $llave = null;
    if ($llave !== null) {
        return $llave;
    }
    if (!llave_existe()) {
        throw new RuntimeException('No existe el archivo de llave de credenciales.');
    }
    $raw = base64_decode(trim((string)file_get_contents(llave_ruta())), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('El archivo de llave de credenciales no es válido.');
    }
    return $llave = $raw;
}

/** Crea el archivo de llave (solo si no existe). */
function llave_crear(): void
{
    $ruta = llave_ruta();
    if (is_file($ruta)) {
        throw new RuntimeException('La llave ya existe; no se sobrescribe.');
    }
    $dir = dirname($ruta);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new RuntimeException('No se pudo crear la carpeta ' . $dir);
    }
    if (file_put_contents($ruta, base64_encode(random_bytes(32)) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir ' . $ruta);
    }
    @chmod($ruta, 0600);
}

function cifrar(string $texto): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cifrado = openssl_encrypt($texto, 'aes-256-gcm', llave(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cifrado === false) {
        throw new RuntimeException('No se pudo cifrar.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cifrado);
}

function descifrar(?string $valor): string
{
    if ($valor === null || $valor === '') {
        return '';
    }
    if (strncmp($valor, 'v1:', 3) !== 0) {
        throw new RuntimeException('Formato de clave cifrada desconocido.');
    }
    $raw = base64_decode(substr($valor, 3), true);
    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Clave cifrada dañada.');
    }
    $texto = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', llave(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($texto === false) {
        throw new RuntimeException('No se pudo descifrar (¿llave distinta?).');
    }
    return $texto;
}

/* ================================================================
 * Verificación en dos pasos (TOTP, RFC 6238)
 * Compatible con Google Authenticator, Microsoft Authenticator, etc.
 * ================================================================ */

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_nuevo_secreto(): string
{
    $bytes = random_bytes(20);
    $bits = '';
    foreach (str_split($bytes) as $b) {
        $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
    }
    $secreto = '';
    foreach (str_split($bits, 5) as $grupo) {
        $secreto .= BASE32[bindec(str_pad($grupo, 5, '0'))];
    }
    return $secreto;
}

function base32_decodificar(string $texto): string
{
    $texto = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $texto));
    $bits = '';
    foreach (str_split($texto) as $c) {
        $bits .= str_pad(decbin(strpos(BASE32, $c)), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $grupo) {
        if (strlen($grupo) === 8) {
            $bytes .= chr(bindec($grupo));
        }
    }
    return $bytes;
}

function totp_codigo(string $secreto, int $paso): string
{
    $hash = hash_hmac('sha1', pack('J', $paso), base32_decodificar($secreto), true);
    $offset = ord($hash[19]) & 0x0F;
    $num = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Acepta el código actual y el de ±30 segundos (desfase de reloj). */
function totp_verificar(string $secreto, string $codigo): bool
{
    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6) {
        return false;
    }
    $paso = intdiv(time(), 30);
    foreach ([-1, 0, 1] as $d) {
        if (hash_equals(totp_codigo($secreto, $paso + $d), $codigo)) {
            return true;
        }
    }
    return false;
}

function totp_uri(string $secreto, string $email): string
{
    $emisor = config('app_nombre', 'CRM');
    return 'otpauth://totp/' . rawurlencode($emisor . ':' . $email)
        . '?secret=' . $secreto . '&issuer=' . rawurlencode($emisor);
}

/* ================================================================
 * Permisos del gestor de credenciales
 * ================================================================ */

/** ¿Se exige verificación en dos pasos para usar el gestor de credenciales? (config) */
function credenciales_requieren_2fa(): bool
{
    return (bool)config('credenciales_requiere_2fa', false);
}

/** Puede ver y administrar credenciales: permiso otorgado (+ 2FA activa si la configuración la exige). */
function puede_ver_credenciales(): bool
{
    $u = usuario_actual();
    return $u && (int)($u['ver_credenciales'] ?? 0) === 1
        && (!credenciales_requieren_2fa() || !empty($u['totp_activo']));
}

/* ================================================================
 * Confirmación de contraseña para mostrar claves
 * Tras confirmar, no se vuelve a pedir durante unos minutos.
 * ================================================================ */

const MINUTOS_CONFIRMACION = 5;

function clave_confirmada(): bool
{
    return time() - (int)($_SESSION['clave_confirmada_en'] ?? 0) < MINUTOS_CONFIRMACION * 60;
}

/** Verifica la contraseña del usuario actual; los fallos cuentan para el bloqueo por intentos. */
function confirmar_clave(string $clave): bool
{
    $hash = (string)q_valor('SELECT password_hash FROM usuarios WHERE id = ?', [(int)usuario_actual()['id']]);
    if ($clave === '' || !password_verify($clave, $hash)) {
        registrar_intento_fallido();
        return false;
    }
    $_SESSION['clave_confirmada_en'] = time();
    return true;
}

/* ================================================================
 * Política de contraseñas fuertes
 * ================================================================ */

const CLAVE_MIN = 12;

/** Devuelve null si la contraseña es aceptable, o el motivo por el que no lo es. */
function clave_debil(string $clave, string $email = '', string $nombre = ''): ?string
{
    if (mb_strlen($clave) < CLAVE_MIN) {
        return 'La contraseña debe tener al menos ' . CLAVE_MIN . ' caracteres.';
    }
    $tipos = (int)preg_match('/[a-z]/', $clave) + (int)preg_match('/[A-Z]/', $clave)
        + (int)preg_match('/\d/', $clave) + (int)preg_match('/[^A-Za-z0-9]/', $clave);
    if ($tipos < 3) {
        return 'La contraseña debe combinar al menos 3 de estos tipos: minúsculas, mayúsculas, números y símbolos.';
    }
    if (preg_match('/(.)\1{3,}/', $clave)) {
        return 'La contraseña no puede repetir el mismo carácter 4 veces seguidas.';
    }
    $minus = mb_strtolower($clave);
    $prohibidas = array_filter(array_merge(
        ['password', 'contraseña', 'contrasena', 'qwerty', '123456', 'abc123', 'proaction', 'admin'],
        [mb_strtolower(strtok($email, '@') ?: '')],
        array_map('mb_strtolower', preg_split('/\s+/', $nombre) ?: [])
    ), static fn($p) => mb_strlen($p) >= 4);
    foreach ($prohibidas as $p) {
        if (mb_strpos($minus, $p) !== false) {
            return 'La contraseña no puede contener palabras obvias, su nombre ni su correo.';
        }
    }
    return null;
}

function texto_politica_clave(): string
{
    return 'Mínimo ' . CLAVE_MIN . ' caracteres, combinando al menos 3 de: minúsculas, mayúsculas, números y símbolos. '
        . 'Sugerencia: una frase de 3 o 4 palabras con un número, p. ej. "Cafe-Lunes-Rancagua-27".';
}

function registrar_credencial(?int $credencialId, string $accion, string $detalle = ''): void
{
    insertar('credenciales_log', [
        'credencial_id' => $credencialId,
        'usuario_id'    => (int)usuario_actual()['id'],
        'accion'        => $accion,
        'detalle'       => nulo_si_vacio(mb_substr($detalle, 0, 250)),
        'ip'            => ip_cliente(),
        'creado_en'     => ahora(),
    ]);
}
