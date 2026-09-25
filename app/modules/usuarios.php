<?php
declare(strict_types=1);

requerir_admin();

$id = entrada_int('id');

/** Guarda el enlace recién generado para mostrarlo una sola vez en la ficha del usuario. */
function recordar_enlace(int $usuarioId, string $url, bool $correo, string $tipo): void
{
    $_SESSION['enlace_generado'] = ['usuario_id' => $usuarioId, 'url' => $url, 'correo' => $correo, 'tipo' => $tipo];
}

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
    // Contraseña manual solo si se eligió explícitamente; lo normal es la invitación por enlace.
    $manual = entrada('modo_clave') === 'manual';
    $clave = $manual ? (string)($_POST['clave'] ?? '') : '';
    $volver = url('usuarios', ['a' => 'form', 'id' => $id]);

    if ($datos['nombre'] === '' || !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Nombre y un correo válido son obligatorios.');
        redirigir($volver);
    }
    if (q_valor('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$datos['email'], $id ?? 0])) {
        flash('error', 'Ya existe un usuario con ese correo.');
        redirigir($volver);
    }
    if ($manual && ($motivo = clave_debil($clave, $datos['email'], $datos['nombre']))) {
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
        flash('ok', 'Usuario actualizado.' . ($clave !== '' ? ' Se cambió su contraseña.' : ''));
        redirigir(url('usuarios'));
    }
    $datos['creado_en'] = ahora();
    if (!$manual) {
        // Contraseña imposible de adivinar hasta que la persona cree la suya con la invitación
        $datos['password_hash'] = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }
    $nuevoId = insertar('usuarios', $datos);
    if ($manual) {
        flash('ok', 'Usuario creado con la contraseña indicada.');
        redirigir(url('usuarios'));
    }
    $enlace = crear_enlace_clave($nuevoId, 'invitacion', (int)usuario_actual()['id']);
    $correo = correo_enlace_clave($datos, $enlace, 'invitacion');
    recordar_enlace($nuevoId, $enlace, $correo, 'invitacion');
    flash('ok', 'Usuario creado. Comparta la invitación para que cree su contraseña.');
    redirigir(url('usuarios', ['a' => 'form', 'id' => $nuevoId]));
}

/* ---------- Enlace para crear o restablecer la contraseña ---------- */
if ($accion === 'enlace' && es_post() && $id) {
    $u = q_uno('SELECT id, nombre, email, ultimo_login, activo FROM usuarios WHERE id = ?', [$id]);
    if (!$u || !$u['activo']) {
        flash('error', 'El usuario no existe o está inactivo; actívelo primero.');
        redirigir(url('usuarios', ['a' => 'form', 'id' => $id]));
    }
    $tipo = $u['ultimo_login'] ? 'recuperacion' : 'invitacion';
    $url = crear_enlace_clave((int)$u['id'], $tipo, (int)usuario_actual()['id']);
    recordar_enlace((int)$u['id'], $url, correo_enlace_clave($u, $url, $tipo), $tipo);
    redirigir(url('usuarios', ['a' => 'form', 'id' => $id]));
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
    // El enlace recién generado se muestra una sola vez
    $generado = null;
    if ($id && ($_SESSION['enlace_generado']['usuario_id'] ?? null) === $id) {
        $generado = $_SESSION['enlace_generado'];
        unset($_SESSION['enlace_generado']);
    }
    layout_inicio($id ? 'Editar usuario' : 'Nuevo usuario', 'usuarios');
    ?>
    <div class="encabezado">
        <h1><?= $id ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
        <?php if ($id && $u['totp_secreto']) echo boton_post(url('usuarios', ['a' => 'reiniciar_2fa', 'id' => $id]), 'Reiniciar verificación en dos pasos', 'secundario', '¿Reiniciar la verificación en dos pasos de este usuario? Úselo si perdió su teléfono.'); ?>
    </div>

    <?php if ($generado):
        $mensaje = ($generado['tipo'] === 'invitacion'
            ? "Hola {$u['nombre']}, te creé una cuenta en el CRM. Crea tu contraseña aquí (vale 7 días): "
            : "Hola {$u['nombre']}, aquí puedes crear una nueva contraseña del CRM (vale 1 hora): ") . $generado['url']; ?>
    <section class="panel enlace-generado">
        <h2><?= $generado['tipo'] === 'invitacion' ? 'Invitación para crear la contraseña' : 'Enlace para restablecer la contraseña' ?></h2>
        <p><?= $generado['correo']
            ? '✅ Se envió por correo a <strong>' . e($u['email']) . '</strong>. Si no llega (revise spam), compártalo por otro medio:'
            : '⚠️ No se pudo enviar el correo. Compártalo usted:' ?></p>
        <div class="grupo-input">
            <input type="text" id="enlace-clave" value="<?= e($generado['url']) ?>" readonly>
            <button type="button" class="secundario" data-copiar="enlace-clave">Copiar enlace</button>
            <a class="boton secundario" href="https://wa.me/?text=<?= e(rawurlencode($mensaje)) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
        </div>
        <p class="tenue">Este enlace se muestra solo ahora, sirve una sola vez y vence en <?= $generado['tipo'] === 'invitacion' ? '7 días' : '1 hora' ?>.
            Generar otro invalida el anterior. Nadie más que la persona conocerá su contraseña.</p>
    </section>
    <?php endif; ?>

    <form method="post" action="<?= e(url('usuarios', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div><?= campo('nombre', 'Nombre *', $u['nombre'] ?? '', 'text', 'required autocomplete="off"') ?></div>
        <div><?= campo('email', 'Correo (usuario de acceso) *', $u['email'] ?? '', 'email', 'required autocomplete="off"') ?></div>
        <div><?= selector('rol', 'Rol', ROLES, $u['rol'], false) ?></div>
        <div class="completo">
            <label class="check"><input type="checkbox" name="activo" value="1" <?= $u['activo'] ? 'checked' : '' ?>> Activo (puede ingresar)</label>
            <label class="check"><input type="checkbox" name="ver_credenciales" value="1" <?= $u['ver_credenciales'] ? 'checked' : '' ?>> Puede ver y administrar credenciales de clientes<?= credenciales_requieren_2fa() ? ' (requiere que active la verificación en dos pasos)' : '' ?></label>
        </div>
        <div class="completo">
            <?php if (!$id): ?>
                <p><strong>Contraseña:</strong> al guardar se genera una <strong>invitación</strong> (enlace por correo y para copiar)
                    con la que la persona crea su propia contraseña.</p>
            <?php endif; ?>
            <details class="subform">
                <summary><?= $id ? 'Cambiar la contraseña manualmente' : 'Prefiero definir yo la contraseña' ?></summary>
                <label class="check"><input type="checkbox" name="modo_clave" value="manual" data-activa="f_clave"> Usar la contraseña que escribo aquí</label>
                <?= campo('clave', 'Contraseña', '', 'password', 'minlength="' . CLAVE_MIN . '" autocomplete="new-password" disabled') ?>
                <small class="tenue"><?= e(texto_politica_clave()) ?> Recomendado: usar el enlace, para que solo la persona conozca su contraseña.</small>
            </details>
        </div>
        <div class="completo acciones">
            <button type="submit"><?= $id ? 'Guardar' : 'Crear usuario e invitar' ?></button>
            <a class="boton secundario" href="<?= e(url('usuarios')) ?>">Cancelar</a>
        </div>
    </form>

    <?php if ($id && $u['activo']): ?>
    <section class="panel">
        <h2>Acceso</h2>
        <p>Estado: <strong><?= e(estado_acceso($u)) ?></strong></p>
        <?= boton_post(url('usuarios', ['a' => 'enlace', 'id' => $id]),
            $u['ultimo_login'] ? 'Generar enlace para restablecer la contraseña' : 'Reenviar invitación', 'secundario') ?>
        <p class="tenue">Se envía a <?= e($u['email']) ?> y también se muestra aquí para copiarlo. Úselo si la persona olvidó su contraseña o no puede ingresar.</p>
    </section>
    <?php endif; ?>
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
            <td><?= e(estado_acceso($u)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
layout_fin();
