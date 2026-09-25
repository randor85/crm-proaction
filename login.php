<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (usuario_actual()) {
    redirigir(url());
}

$error = '';
$email = '';
$paso2 = login_2fa_pendiente();

if (entrada('cancelar') === '1') {
    unset($_SESSION['2fa_usuario'], $_SESSION['2fa_desde']);
    redirigir('login.php');
}

if (es_post()) {
    csrf_verificar();
    if (login_bloqueado()) {
        $error = 'Demasiados intentos fallidos. Espere ' . MINUTOS_BLOQUEO . ' minutos e inténtelo de nuevo.';
    } elseif ($paso2) {
        if (verificar_login_2fa(entrada('codigo'))) {
            redirigir(url());
        }
        $paso2 = login_2fa_pendiente();
        $error = $paso2 ? 'Código incorrecto. Revise la hora de su teléfono e inténtelo de nuevo.' : 'El tiempo para ingresar el código expiró. Vuelva a iniciar sesión.';
    } else {
        $email = entrada('email');
        $resultado = intentar_login($email, (string)($_POST['clave'] ?? ''));
        if ($resultado === 'ok') {
            redirigir(url());
        }
        if ($resultado === '2fa') {
            redirigir('login.php');
        }
        $error = 'Correo o contraseña incorrectos.';
    }
}

layout_inicio('Ingresar');
?>
<div class="login">
    <h1><?= e(config('app_nombre', 'CRM')) ?></h1>
    <?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($paso2): ?>
        <p>Ingrese el código de 6 dígitos que muestra su app autenticadora.</p>
        <form method="post" class="formulario">
            <?= csrf_campo() ?>
            <?= campo('codigo', 'Código de verificación', '', 'text', 'required autofocus inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"') ?>
            <button type="submit">Verificar</button>
        </form>
        <p class="centrado"><a href="login.php?cancelar=1">Volver</a></p>
    <?php else: ?>
        <form method="post" class="formulario">
            <?= csrf_campo() ?>
            <?= campo('email', 'Correo electrónico', $email, 'email', 'required autofocus autocomplete="username"') ?>
            <?= campo('clave', 'Contraseña', '', 'password', 'required autocomplete="current-password"') ?>
            <button type="submit">Ingresar</button>
        </form>
        <p class="centrado"><a href="olvide.php">¿Olvidó su contraseña?</a></p>
    <?php endif; ?>
</div>
<?php
layout_fin();
