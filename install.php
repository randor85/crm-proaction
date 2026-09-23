<?php
declare(strict_types=1);

/*
 * Instalador: crea las tablas y el primer usuario administrador.
 * Se desactiva solo una vez que existe al menos un usuario.
 * Por seguridad, elimine este archivo del servidor después de instalar.
 */

define('INSTALANDO', true);
require __DIR__ . '/app/bootstrap.php';

function ya_instalado(): bool
{
    try {
        return (int)q_valor('SELECT COUNT(*) FROM usuarios') > 0;
    } catch (PDOException $ex) {
        return false; // la tabla aún no existe
    }
}

if (ya_instalado()) {
    layout_inicio('Instalación');
    echo '<div class="login"><h1>Ya instalado</h1><p>El CRM ya está instalado. Elimine <code>install.php</code> del servidor.</p>'
        . '<p><a class="boton" href="login.php">Ir al inicio de sesión</a></p></div>';
    layout_fin();
    exit;
}

$errores = [];
$nombre = entrada('nombre');
$email = mb_strtolower(entrada('email'));

if (es_post()) {
    csrf_verificar();
    $clave = (string)($_POST['clave'] ?? '');
    if ($nombre === '') {
        $errores[] = 'Ingrese su nombre.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Ingrese un correo válido.';
    }
    if ($motivo = clave_debil($clave, $email, $nombre)) {
        $errores[] = $motivo;
    }

    if (!$errores) {
        $archivo = __DIR__ . '/sql/' . (db_driver() === 'sqlite' ? 'sqlite.sql' : 'mysql.sql');
        $sql = preg_replace('/^--.*$/m', '', (string)file_get_contents($archivo));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) {
            db()->exec($sentencia);
        }
        migraciones_aplicar();
        insertar('usuarios', [
            'nombre'        => $nombre,
            'email'         => $email,
            'password_hash' => password_hash($clave, PASSWORD_DEFAULT),
            'rol'           => 'admin',
            'activo'        => 1,
            'creado_en'     => ahora(),
        ]);
        flash('ok', 'Instalación completada. Ingrese con su correo y contraseña, y elimine install.php del servidor.');
        redirigir('login.php');
    }
}

layout_inicio('Instalación');
?>
<div class="login">
    <h1>Instalar <?= e(config('app_nombre', 'CRM')) ?></h1>
    <p class="tenue">Se crearán las tablas en la base de datos configurada (<?= e(db_driver()) ?>) y el usuario administrador.</p>
    <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" class="formulario">
        <?= csrf_campo() ?>
        <?= campo('nombre', 'Su nombre', $nombre, 'text', 'required') ?>
        <?= campo('email', 'Correo (será su usuario)', $email, 'email', 'required') ?>
        <?= campo('clave', 'Contraseña', '', 'password', 'required minlength="' . CLAVE_MIN . '" autocomplete="new-password"') ?>
        <small class="tenue"><?= e(texto_politica_clave()) ?></small>
        <button type="submit">Instalar</button>
    </form>
</div>
<?php
layout_fin();
