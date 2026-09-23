<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (usuario_actual()) {
    redirigir(url());
}

$error = '';
$email = '';

if (es_post()) {
    csrf_verificar();
    $email = entrada('email');
    if (login_bloqueado()) {
        $error = 'Demasiados intentos fallidos. Espere ' . MINUTOS_BLOQUEO . ' minutos e inténtelo de nuevo.';
    } elseif (intentar_login($email, (string)($_POST['clave'] ?? ''))) {
        redirigir(url());
    } else {
        $error = 'Correo o contraseña incorrectos.';
    }
}

layout_inicio('Ingresar');
?>
<div class="login">
    <h1><?= e(config('app_nombre', 'CRM')) ?></h1>
    <?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="formulario">
        <?= csrf_campo() ?>
        <?= campo('email', 'Correo electrónico', $email, 'email', 'required autofocus autocomplete="username"') ?>
        <?= campo('clave', 'Contraseña', '', 'password', 'required autocomplete="current-password"') ?>
        <button type="submit">Ingresar</button>
    </form>
</div>
<?php
layout_fin();
