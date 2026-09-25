<?php
declare(strict_types=1);

/* Crear la contraseña (invitación) o restablecerla, desde un enlace de un solo uso. */

require __DIR__ . '/app/bootstrap.php';

header('Referrer-Policy: no-referrer');
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$enlace = enlace_vigente($token);
$error = '';

if ($enlace && es_post()) {
    csrf_verificar();
    $nueva = (string)($_POST['clave_nueva'] ?? '');
    $repetir = (string)($_POST['clave_repetir'] ?? '');
    if ($motivo = clave_debil($nueva, $enlace['email'], $enlace['nombre'])) {
        $error = $motivo;
    } elseif ($nueva !== $repetir) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), $enlace['usuario_id']]);
        q('UPDATE enlaces_clave SET usado_en = ? WHERE usuario_id = ? AND usado_en IS NULL', [ahora(), $enlace['usuario_id']]);
        q('DELETE FROM login_intentos WHERE ip = ?', [ip_cliente()]);
        // Cerrar cualquier sesión que hubiera en este navegador
        $_SESSION = [];
        session_regenerate_id(true);
        flash('ok', $enlace['tipo'] === 'invitacion'
            ? 'Su cuenta quedó activa. Ingrese con su correo y la contraseña que acaba de crear.'
            : 'Contraseña actualizada. Ingrese con la nueva contraseña.');
        redirigir('login.php');
    }
}

$invitacion = ($enlace['tipo'] ?? '') === 'invitacion';
layout_inicio($invitacion ? 'Crear contraseña' : 'Restablecer contraseña');
?>
<div class="login">
    <?php if (!$enlace): ?>
        <h1>Enlace no válido</h1>
        <div class="alerta alerta-error">El enlace venció o ya se usó.</div>
        <p>Pida uno nuevo en <a href="olvide.php">¿Olvidó su contraseña?</a> o a un administrador del CRM.</p>
    <?php else: ?>
        <h1><?= $invitacion ? 'Bienvenido/a, ' . e(strtok($enlace['nombre'], ' ')) : 'Nueva contraseña' ?></h1>
        <p class="tenue"><?= $invitacion ? 'Cree la contraseña de su cuenta' : 'Cree una nueva contraseña para' ?> <strong><?= e($enlace['email']) ?></strong>.</p>
        <?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="formulario">
            <?= csrf_campo() ?>
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <input type="email" name="usuario" value="<?= e($enlace['email']) ?>" autocomplete="username" hidden>
            <?= campo('clave_nueva', 'Contraseña', '', 'password', 'required autofocus minlength="' . CLAVE_MIN . '" autocomplete="new-password"') ?>
            <small class="tenue"><?= e(texto_politica_clave()) ?></small>
            <?= campo('clave_repetir', 'Repita la contraseña', '', 'password', 'required minlength="' . CLAVE_MIN . '" autocomplete="new-password"') ?>
            <button type="submit"><?= $invitacion ? 'Activar mi cuenta' : 'Guardar contraseña' ?></button>
        </form>
    <?php endif; ?>
</div>
<?php
layout_fin();
