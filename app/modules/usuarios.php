<?php
declare(strict_types=1);

requerir_admin();

$id = entrada_int('id');

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $rol = entrada('rol');
    $datos = [
        'nombre' => entrada('nombre'),
        'email'  => mb_strtolower(entrada('email')),
        'rol'    => isset(ROLES[$rol]) ? $rol : 'usuario',
        'activo' => entrada('activo') === '1' ? 1 : 0,
        'ver_credenciales' => entrada('ver_credenciales') === '1' ? 1 : 0,
    ];
    $clave = (string)($_POST['clave'] ?? '');
    $volver = url('usuarios', ['a' => 'form', 'id' => $id]);

    if ($datos['nombre'] === '' || !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Nombre y un correo válido son obligatorios.');
        redirigir($volver);
    }
    if (q_valor('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$datos['email'], $id ?? 0])) {
        flash('error', 'Ya existe un usuario con ese correo.');
        redirigir($volver);
    }
    if ((!$id || $clave !== '') && ($motivo = clave_debil($clave, $datos['email'], $datos['nombre']))) {
        flash('error', $motivo);
        redirigir($volver);
    }
    if ($id === (int)usuario_actual()['id'] && ($datos['rol'] !== 'admin' || !$datos['activo'])) {
        flash('error', 'No puede quitarse el rol de administrador ni desactivarse a sí mismo.');
        redirigir($volver);
    }
    if ($clave !== '') {
        $datos['password_hash'] = password_hash($clave, PASSWORD_DEFAULT);
    }

    if ($id) {
        actualizar('usuarios', $id, $datos);
        flash('ok', 'Usuario actualizado.');
    } else {
        $datos['creado_en'] = ahora();
        insertar('usuarios', $datos);
        flash('ok', 'Usuario creado.');
    }
    redirigir(url('usuarios'));
}

/* ---------- Reiniciar verificación en dos pasos (teléfono perdido) ---------- */
if ($accion === 'reiniciar_2fa' && es_post() && $id) {
    q('UPDATE usuarios SET totp_secreto = NULL WHERE id = ?', [$id]);
    flash('ok', 'Verificación en dos pasos reiniciada. El usuario debe volver a activarla en Mi perfil.');
    redirigir(url('usuarios', ['a' => 'form', 'id' => $id]));
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $u = $id ? q_uno('SELECT * FROM usuarios WHERE id = ?', [$id]) : ['rol' => 'usuario', 'activo' => 1, 'ver_credenciales' => 0];
    if ($id && !$u) {
        redirigir(url('usuarios'));
    }
    layout_inicio($id ? 'Editar usuario' : 'Nuevo usuario', 'usuarios');
    ?>
    <div class="encabezado">
        <h1><?= $id ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
        <?php if ($id && $u['totp_secreto']) echo boton_post(url('usuarios', ['a' => 'reiniciar_2fa', 'id' => $id]), 'Reiniciar verificación en dos pasos', 'secundario', '¿Reiniciar la verificación en dos pasos de este usuario? Úselo si perdió su teléfono.'); ?>
    </div>
    <form method="post" action="<?= e(url('usuarios', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div><?= campo('nombre', 'Nombre *', $u['nombre'] ?? '', 'text', 'required') ?></div>
        <div><?= campo('email', 'Correo (usuario de acceso) *', $u['email'] ?? '', 'email', 'required') ?></div>
        <div><?= selector('rol', 'Rol', ROLES, $u['rol'], false) ?></div>
        <div><?= campo('clave', $id ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña *', '', 'password', ($id ? '' : 'required ') . 'minlength="' . CLAVE_MIN . '" autocomplete="new-password"') ?>
            <small class="tenue"><?= e(texto_politica_clave()) ?></small></div>
        <div class="completo">
            <label class="check"><input type="checkbox" name="activo" value="1" <?= $u['activo'] ? 'checked' : '' ?>> Activo (puede ingresar)</label>
            <label class="check"><input type="checkbox" name="ver_credenciales" value="1" <?= $u['ver_credenciales'] ? 'checked' : '' ?>> Puede ver y administrar credenciales de clientes<?= credenciales_requieren_2fa() ? ' (requiere que active la verificación en dos pasos)' : '' ?></label>
        </div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e(url('usuarios')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
$usuarios = q_todos('SELECT * FROM usuarios ORDER BY activo DESC, nombre');
layout_inicio('Usuarios', 'usuarios');
?>
<div class="encabezado">
    <h1>Usuarios</h1>
    <a class="boton" href="<?= e(url('usuarios', ['a' => 'form'])) ?>">+ Nuevo usuario</a>
</div>
<p class="tenue">Los usuarios no se eliminan para conservar el historial; desactívelos para quitarles el acceso.</p>
<table>
    <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Dos pasos</th><th>Credenciales</th><th>Último acceso</th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $u): ?>
        <tr class="<?= $u['activo'] ? '' : 'hecha' ?>">
            <td><a href="<?= e(url('usuarios', ['a' => 'form', 'id' => $u['id']])) ?>"><?= e($u['nombre']) ?></a></td>
            <td><?= e($u['email']) ?></td>
            <td><?= e(ROLES[$u['rol']] ?? $u['rol']) ?></td>
            <td><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></td>
            <td><?= $u['totp_secreto'] ? '✓' : '—' ?></td>
            <td><?= $u['ver_credenciales'] ? '✓' : '—' ?></td>
            <td><?= e(fecha($u['ultimo_login'], true)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
layout_fin();
