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
    } elseif ($motivo = clave_debil($nueva, usuario_actual()['email'], usuario_actual()['nombre'])) {
        flash('error', $motivo);
    } elseif ($nueva !== $repetir) {
        flash('error', 'Las contraseñas nuevas no coinciden.');
    } else {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), $uid]);
        session_regenerate_id(true);
        unset($_SESSION['clave_debil']);
        flash('ok', 'Contraseña actualizada.');
    }
    redirigir(url('perfil'));
}

/* ---------- Calendario ---------- */
if ($accion === 'ical' && es_post()) {
    q('UPDATE usuarios SET ical_token = ? WHERE id = ?', [bin2hex(random_bytes(24)), $uid]);
    flash('ok', usuario_actual()['ical_token']
        ? 'Se generó un enlace nuevo. El anterior dejó de funcionar: actualice la suscripción en su calendario.'
        : 'Enlace de calendario creado.');
    redirigir(url('perfil') . '#calendario');
}

/* ---------- Verificación en dos pasos ---------- */
if ($accion === '2fa_iniciar' && es_post()) {
    $_SESSION['totp_nuevo'] = totp_nuevo_secreto();
    redirigir(url('perfil', ['a' => '2fa']) . '#dos-pasos');
}

if ($accion === '2fa_confirmar' && es_post()) {
    $secreto = $_SESSION['totp_nuevo'] ?? '';
    if ($secreto && totp_verificar($secreto, entrada('codigo'))) {
        q('UPDATE usuarios SET totp_secreto = ? WHERE id = ?', [$secreto, $uid]);
        unset($_SESSION['totp_nuevo']);
        flash('ok', 'Verificación en dos pasos activada. Desde ahora se le pedirá el código al iniciar sesión.');
        redirigir(url('perfil') . '#dos-pasos');
    }
    flash('error', 'El código no coincide. Revise que la hora de su teléfono sea automática e inténtelo de nuevo.');
    redirigir(url('perfil', ['a' => '2fa']) . '#dos-pasos');
}

if ($accion === '2fa_desactivar' && es_post()) {
    $secreto = (string)q_valor('SELECT totp_secreto FROM usuarios WHERE id = ?', [$uid]);
    if ($secreto && totp_verificar($secreto, entrada('codigo'))) {
        q('UPDATE usuarios SET totp_secreto = NULL WHERE id = ?', [$uid]);
        flash('ok', 'Verificación en dos pasos desactivada.' . (credenciales_requieren_2fa() ? ' Ya no podrá ver credenciales hasta volver a activarla.' : ''));
    } else {
        flash('error', 'Código incorrecto; la verificación en dos pasos sigue activa.');
    }
    redirigir(url('perfil') . '#dos-pasos');
}

$u = usuario_actual();
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/';
$icalUrl = $u['ical_token'] ? $base . 'ical.php?t=' . $u['ical_token'] : null;
$secretoNuevo = $accion === '2fa' ? ($_SESSION['totp_nuevo'] ?? null) : null;

layout_inicio('Mi perfil');
?>
<h1>Mi perfil</h1>
<section class="panel">
    <dl class="ficha">
        <dt>Nombre</dt><dd><?= e($u['nombre']) ?></dd>
        <dt>Correo</dt><dd><?= e($u['email']) ?></dd>
        <dt>Rol</dt><dd><?= e(ROLES[$u['rol']] ?? '') ?></dd>
        <dt>Credenciales</dt><dd><?= $u['ver_credenciales'] ? 'Con permiso' : 'Sin permiso' ?></dd>
    </dl>
</section>

<section class="panel" id="calendario">
    <h2>Calendario (Google / Outlook)</h2>
    <p>Sus tareas (por fecha de vencimiento) y gestiones agendadas aparecen en su calendario mediante un enlace privado.
        Es de solo lectura: los cambios se hacen en el CRM y el calendario se actualiza solo.</p>
    <?php if ($icalUrl): ?>
        <div class="grupo-input">
            <input type="text" id="ical-url" value="<?= e($icalUrl) ?>" readonly>
            <button type="button" class="secundario" data-copiar="ical-url">Copiar enlace</button>
        </div>
        <div class="columnas instrucciones">
            <div>
                <h3>Google Calendar</h3>
                <ol>
                    <li>Abra <a href="https://calendar.google.com/calendar/r/settings/addbyurl" target="_blank" rel="noopener">Google Calendar → Agregar calendario → Desde URL</a>.</li>
                    <li>Pegue el enlace y pulse <em>Agregar calendario</em>.</li>
                </ol>
                <p class="tenue">Google actualiza los calendarios suscritos cada algunas horas.</p>
            </div>
            <div>
                <h3>Outlook</h3>
                <ol>
                    <li>En <a href="https://outlook.office.com/calendar/addcalendar" target="_blank" rel="noopener">Outlook → Agregar calendario → Suscribirse desde la web</a>.</li>
                    <li>Pegue el enlace, póngale un nombre y pulse <em>Importar</em>.</li>
                </ol>
                <p class="tenue">En Outlook de escritorio: <em>Agregar calendario → Desde Internet</em>.</p>
            </div>
        </div>
        <p class="tenue">⚠️ Quien tenga este enlace puede ver sus tareas. No lo comparta; si se filtra, genere uno nuevo.</p>
        <?= boton_post(url('perfil', ['a' => 'ical']), 'Generar enlace nuevo', 'secundario', 'El enlace actual dejará de funcionar. ¿Continuar?') ?>
    <?php else: ?>
        <?= boton_post(url('perfil', ['a' => 'ical']), 'Crear enlace de calendario') ?>
    <?php endif; ?>
</section>

<section class="panel" id="dos-pasos">
    <h2>Verificación en dos pasos</h2>
    <?php if ($u['totp_activo']): ?>
        <p><span class="badge factura-pagada">Activa</span> Al iniciar sesión se le pide el código de su app autenticadora.</p>
        <details class="subform">
            <summary>Desactivar</summary>
            <form method="post" action="<?= e(url('perfil', ['a' => '2fa_desactivar'])) ?>" class="formulario angosto">
                <?= csrf_campo() ?>
                <?= campo('codigo', 'Código actual de la app', '', 'text', 'required inputmode="numeric" maxlength="7" autocomplete="one-time-code"') ?>
                <button type="submit" class="peligro">Desactivar verificación en dos pasos</button>
            </form>
        </details>
    <?php elseif ($secretoNuevo): ?>
        <ol>
            <li>Instale en su teléfono <strong>Google Authenticator</strong> o <strong>Microsoft Authenticator</strong>.</li>
            <li>Escanee este código QR (o ingrese la clave manualmente):</li>
        </ol>
        <div class="qr-2fa">
            <div id="qr" data-uri="<?= e(totp_uri($secretoNuevo, $u['email'])) ?>"></div>
            <div>
                <p class="tenue">Clave para ingreso manual:</p>
                <code class="clave-2fa"><?= e(trim(chunk_split($secretoNuevo, 4, ' '))) ?></code>
            </div>
        </div>
        <form method="post" action="<?= e(url('perfil', ['a' => '2fa_confirmar'])) ?>" class="formulario angosto">
            <?= csrf_campo() ?>
            <?= campo('codigo', '3. Ingrese el código de 6 dígitos que muestra la app', '', 'text', 'required autofocus inputmode="numeric" maxlength="7" autocomplete="one-time-code"') ?>
            <button type="submit">Activar</button>
        </form>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            (function () {
                var el = document.getElementById('qr');
                if (window.QRCode) { new QRCode(el, { text: el.getAttribute('data-uri'), width: 180, height: 180 }); }
            })();
        </script>
    <?php else: ?>
        <p>Agrega un código desde su teléfono al iniciar sesión. <?= credenciales_requieren_2fa() ? '<strong>Es obligatoria para usar el gestor de credenciales.</strong>' : 'Es opcional, pero protege su cuenta aunque alguien descubra su contraseña.' ?></p>
        <?= boton_post(url('perfil', ['a' => '2fa_iniciar']), 'Activar verificación en dos pasos') ?>
    <?php endif; ?>
</section>

<section class="panel" id="contrasena">
    <h2>Cambiar contraseña</h2>
    <form method="post" action="<?= e(url('perfil', ['a' => 'guardar'])) ?>" class="formulario angosto">
        <?= csrf_campo() ?>
        <?= campo('clave_actual', 'Contraseña actual', '', 'password', 'required autocomplete="current-password"') ?>
        <?= campo('clave_nueva', 'Nueva contraseña', '', 'password', 'required minlength="' . CLAVE_MIN . '" autocomplete="new-password"') ?>
        <small class="tenue"><?= e(texto_politica_clave()) ?></small>
        <?= campo('clave_repetir', 'Repita la nueva contraseña', '', 'password', 'required minlength="' . CLAVE_MIN . '" autocomplete="new-password"') ?>
        <button type="submit">Actualizar contraseña</button>
    </form>
</section>
<?php
layout_fin();
