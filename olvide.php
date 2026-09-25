<?php
declare(strict_types=1);

/* "¿Olvidó su contraseña?": envía un enlace de recuperación al correo del usuario. */

require __DIR__ . '/app/bootstrap.php';

if (usuario_actual()) {
    redirigir(url('perfil'));
}

$enviado = false;
$error = '';
if (es_post()) {
    csrf_verificar();
    if (login_bloqueado()) {
        $error = 'Demasiados intentos desde este equipo. Espere ' . MINUTOS_BLOQUEO . ' minutos e inténtelo de nuevo.';
    } else {
        // Cada solicitud cuenta como intento: evita que se use para bombardear correos o adivinar cuentas.
        registrar_intento_fallido();
        $u = q_uno('SELECT id, nombre, email FROM usuarios WHERE email = ? AND activo = 1', [mb_strtolower(entrada('email'))]);
        if ($u) {
            correo_enlace_clave($u, crear_enlace_clave((int)$u['id'], 'recuperacion'), 'recuperacion');
        }
        // Mismo mensaje exista o no la cuenta, para no revelar qué correos están registrados.
        $enviado = true;
    }
}

layout_inicio('Recuperar contraseña');
?>
<div class="login">
    <h1>Recuperar contraseña</h1>
    <?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($enviado): ?>
        <div class="alerta alerta-ok">Si el correo corresponde a una cuenta activa, le enviamos un enlace para crear una nueva contraseña.
            Vale 1 hora. Revise también la carpeta de spam.</div>
        <p class="tenue">¿No llegó? Pida a un administrador que le genere el enlace desde <em>Usuarios</em>.</p>
    <?php else: ?>
        <p class="tenue">Ingrese el correo con que entra al CRM y le enviaremos un enlace para crear una nueva contraseña.</p>
        <form method="post" class="formulario">
            <?= csrf_campo() ?>
            <?= campo('email', 'Correo electrónico', entrada('email'), 'email', 'required autofocus autocomplete="username"') ?>
            <button type="submit">Enviar enlace</button>
        </form>
    <?php endif; ?>
    <p class="centrado"><a href="login.php">Volver a ingresar</a></p>
</div>
<?php
layout_fin();
