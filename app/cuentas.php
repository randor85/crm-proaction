<?php
declare(strict_types=1);

/*
 * Creación y recuperación de contraseñas mediante enlaces de un solo uso.
 * - Invitación: al crear un usuario, para que defina su propia contraseña (vale 7 días).
 * - Recuperación: "¿Olvidó su contraseña?" o generado por un administrador (vale 1 hora).
 * En la base solo se guarda el hash del token; el enlace se muestra o envía una sola vez.
 */

const ENLACE_HORAS = ['invitacion' => 24 * 7, 'recuperacion' => 1];

/** URL absoluta dentro del CRM (para enlaces en correos y calendarios). */
function url_absoluta(string $ruta = ''): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/' . ltrim($ruta, '/');
}

/**
 * Crea un enlace nuevo e invalida los anteriores del mismo usuario.
 * @return string URL completa con el token (solo existe en este momento)
 */
function crear_enlace_clave(int $usuarioId, string $tipo, ?int $creadoPor = null): string
{
    $tipo = isset(ENLACE_HORAS[$tipo]) ? $tipo : 'recuperacion';
    $token = bin2hex(random_bytes(32));
    q('UPDATE enlaces_clave SET usado_en = ? WHERE usuario_id = ? AND usado_en IS NULL', [ahora(), $usuarioId]);
    insertar('enlaces_clave', [
        'usuario_id' => $usuarioId,
        'tipo'       => $tipo,
        'token_hash' => hash('sha256', $token),
        'expira_en'  => date('Y-m-d H:i:s', time() + ENLACE_HORAS[$tipo] * 3600),
        'creado_por' => $creadoPor,
        'ip'         => ip_cliente(),
        'creado_en'  => ahora(),
    ]);
    return url_absoluta('restablecer.php?t=' . $token);
}

/** Enlace vigente (no usado ni vencido) con los datos del usuario, o null. */
function enlace_vigente(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return q_uno(
        'SELECT k.*, u.nombre, u.email FROM enlaces_clave k JOIN usuarios u ON u.id = k.usuario_id
         WHERE k.token_hash = ? AND k.usado_en IS NULL AND k.expira_en > ? AND u.activo = 1',
        [hash('sha256', $token), ahora()]);
}

/** Estado de acceso de un usuario para la lista de Usuarios. */
function estado_acceso(array $u): string
{
    if (!$u['ultimo_login']) {
        $pendiente = q_valor("SELECT expira_en FROM enlaces_clave WHERE usuario_id = ? AND tipo = 'invitacion' AND usado_en IS NULL AND expira_en > ?",
            [$u['id'], ahora()]);
        return $pendiente ? 'Invitación pendiente (vence ' . fecha($pendiente) . ')' : 'Nunca ha ingresado';
    }
    return fecha($u['ultimo_login'], true);
}

/* ---------------- Correo ---------------- */

/**
 * Envía un correo de texto. Con config 'correo_modo' => 'archivo' (mockup local) lo guarda en
 * data/correos.log en vez de enviarlo. Devuelve true si se entregó al servidor de correo.
 */
function enviar_correo(string $para, string $asunto, string $cuerpo): bool
{
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $remitente = (string)config('correo_remitente', 'no-reply@' . preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $nombre = (string)config('app_nombre', 'CRM');
    if (config('correo_modo', 'mail') === 'archivo') {
        $linea = "==== " . ahora() . "\nPara: $para\nAsunto: $asunto\n\n$cuerpo\n\n";
        return file_put_contents(BASE_DIR . '/data/correos.log', $linea, FILE_APPEND | LOCK_EX) !== false;
    }
    $cabeceras = implode("\r\n", [
        'From: ' . mb_encode_mimeheader($nombre, 'UTF-8') . " <$remitente>",
        "Reply-To: $remitente",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    return @mail($para, mb_encode_mimeheader($asunto, 'UTF-8'), $cuerpo, $cabeceras, '-f' . $remitente);
}

function correo_enlace_clave(array $usuario, string $url, string $tipo): bool
{
    $app = (string)config('app_nombre', 'CRM');
    if ($tipo === 'invitacion') {
        $asunto = "Su cuenta en $app";
        $cuerpo = "Hola {$usuario['nombre']}:\n\n"
            . "Se creó su cuenta en $app con el usuario {$usuario['email']}.\n"
            . "Para activarla, cree su contraseña en este enlace (vale 7 días y se usa una sola vez):\n\n$url\n\n"
            . "Si no esperaba este correo, puede ignorarlo.\n";
    } else {
        $asunto = "Restablecer su contraseña de $app";
        $cuerpo = "Hola {$usuario['nombre']}:\n\n"
            . "Recibimos una solicitud para restablecer la contraseña de {$usuario['email']}.\n"
            . "Cree una nueva en este enlace (vale 1 hora y se usa una sola vez):\n\n$url\n\n"
            . "Si usted no lo pidió, ignore este correo: su contraseña actual sigue funcionando.\n";
    }
    return enviar_correo($usuario['email'], $asunto, $cuerpo);
}
