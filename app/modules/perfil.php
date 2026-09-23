<?php
declare(strict_types=1);

$uid = (int)usuario_actual()['id'];

if ($accion === 'guardar' && es_post()) {
    $actual = (string)($_POST['clave_actual'] ?? '');
    $nueva = (string)($_POST['clave_nueva'] ?? '');
    $repetir = (string)($_POST['clave_repetir'] ?? '');
    $hash = (string)q_valor('SELECT password_hash FROM usuarios WHERE id = ?', [$uid]);

    if (!password_verify($actual, $hash)) {
        flash('error', 'La contraseña actual no es correcta.');
    } elseif (strlen($nueva) < 8) {
        flash('error', 'La nueva contraseña debe tener al menos 8 caracteres.');
    } elseif ($nueva !== $repetir) {
        flash('error', 'Las contraseñas nuevas no coinciden.');
    } else {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), $uid]);
        session_regenerate_id(true);
        flash('ok', 'Contraseña actualizada.');
    }
    redirigir(url('perfil'));
}

layout_inicio('Mi perfil');
?>
<h1>Mi perfil</h1>
<section class="panel">
    <dl class="ficha">
        <dt>Nombre</dt><dd><?= e(usuario_actual()['nombre']) ?></dd>
        <dt>Correo</dt><dd><?= e(usuario_actual()['email']) ?></dd>
        <dt>Rol</dt><dd><?= e(ROLES[usuario_actual()['rol']] ?? '') ?></dd>
    </dl>
</section>
<section class="panel">
    <h2>Cambiar contraseña</h2>
    <form method="post" action="<?= e(url('perfil', ['a' => 'guardar'])) ?>" class="formulario angosto">
        <?= csrf_campo() ?>
        <?= campo('clave_actual', 'Contraseña actual', '', 'password', 'required autocomplete="current-password"') ?>
        <?= campo('clave_nueva', 'Nueva contraseña (mín. 8 caracteres)', '', 'password', 'required minlength="8" autocomplete="new-password"') ?>
        <?= campo('clave_repetir', 'Repita la nueva contraseña', '', 'password', 'required minlength="8" autocomplete="new-password"') ?>
        <button type="submit">Actualizar contraseña</button>
    </form>
</section>
<?php
layout_fin();
