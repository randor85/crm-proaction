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
    if ((!$id || $clave !== '') && strlen($clave) < 8) {
        flash('error', 'La contraseña debe tener al menos 8 caracteres.');
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

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $u = $id ? q_uno('SELECT * FROM usuarios WHERE id = ?', [$id]) : ['rol' => 'usuario', 'activo' => 1];
    if ($id && !$u) {
        redirigir(url('usuarios'));
    }
    layout_inicio($id ? 'Editar usuario' : 'Nuevo usuario', 'usuarios');
    ?>
    <h1><?= $id ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
    <form method="post" action="<?= e(url('usuarios', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div><?= campo('nombre', 'Nombre *', $u['nombre'] ?? '', 'text', 'required') ?></div>
        <div><?= campo('email', 'Correo (usuario de acceso) *', $u['email'] ?? '', 'email', 'required') ?></div>
        <div><?= selector('rol', 'Rol', ROLES, $u['rol'], false) ?></div>
        <div><?= campo('clave', $id ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña *', '', 'password', ($id ? '' : 'required ') . 'minlength="8" autocomplete="new-password"') ?></div>
        <div class="completo"><label class="check"><input type="checkbox" name="activo" value="1" <?= $u['activo'] ? 'checked' : '' ?>> Activo (puede ingresar)</label></div>
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
    <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Último acceso</th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $u): ?>
        <tr class="<?= $u['activo'] ? '' : 'hecha' ?>">
            <td><a href="<?= e(url('usuarios', ['a' => 'form', 'id' => $u['id']])) ?>"><?= e($u['nombre']) ?></a></td>
            <td><?= e($u['email']) ?></td>
            <td><?= e(ROLES[$u['rol']] ?? $u['rol']) ?></td>
            <td><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></td>
            <td><?= e(fecha($u['ultimo_login'], true)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
layout_fin();
