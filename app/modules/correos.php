<?php
declare(strict_types=1);

/*
 * Sistema → Correos: buzones que lee el servidor por IMAP, cola de correos por clasificar
 * y token de la rutina de Claude (ver app/correos.php y api_correos.php).
 */

requerir_admin();
$id = entrada_int('id');
$volver = url('correos');

/* ---------- Buzones ---------- */
if ($accion === 'guardar_buzon' && es_post()) {
    $usuario = mb_strtolower(trim(entrada('usuario')));
    $clave = (string)($_POST['clave'] ?? ''); // sin trim: una clave puede empezar o terminar en espacio
    $actual = $id ? q_uno('SELECT * FROM correo_buzones WHERE id = ?', [$id]) : null;
    if (!filter_var($usuario, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Indique el correo completo del buzón (usuario IMAP).');
        redirigir($volver);
    }
    if (!llave_existe()) {
        flash('error', 'Primero cree la llave de cifrado en Sistema: con ella se guardan las claves de los buzones.');
        redirigir($volver);
    }
    if (!$actual && $clave === '') {
        flash('error', 'Escriba la clave del buzón.');
        redirigir($volver);
    }
    if (q_valor('SELECT id FROM correo_buzones WHERE usuario = ? AND id <> ?', [$usuario, $id ?? 0])) {
        flash('error', 'Ese buzón ya está registrado.');
        redirigir($volver);
    }
    $datos = ['usuario' => $usuario, 'activo' => entrada('activo') === '1' ? 1 : 0];
    if ($clave !== '') {
        $datos['clave_cifrada'] = cifrar($clave);
        $datos['error'] = null;
    }
    if ($actual && $actual['usuario'] !== $usuario) {
        $datos += ['ultimo_uid' => 0, 'uidvalidity' => 0]; // otro buzón: se empieza de nuevo
    }
    if ($actual) {
        actualizar('correo_buzones', $id, $datos);
    } else {
        insertar('correo_buzones', $datos + ['creado_en' => ahora()]);
    }
    flash('ok', 'Buzón guardado' . ($clave !== '' ? ' (la clave quedó cifrada)' : '') . '. Use «Probar» para verificar la conexión.');
    redirigir($volver);
}

if ($accion === 'eliminar_buzon' && es_post() && $id) {
    q('DELETE FROM correo_buzones WHERE id = ?', [$id]);
    flash('ok', 'Buzón eliminado. Los correos ya leídos se conservan hasta que venzan.');
    redirigir($volver);
}

if ($accion === 'leer' && es_post()) {
    $resumen = correos_leer_buzones($id ?: null);
    $conError = array_filter($resumen, static fn($l) => strpos($l, 'error') !== false);
    flash($conError ? 'error' : 'ok', $resumen ? implode(' ', $resumen) : 'No hay buzones activos.');
    redirigir($volver);
}

if ($accion === 'servidor' && es_post()) {
    $host = trim(entrada('imap_servidor'));
    $puerto = entrada_int('imap_puerto') ?? 993;
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
        flash('error', 'Servidor IMAP no válido.');
        redirigir($volver);
    }
    ajuste_guardar('imap_servidor', $host);
    ajuste_guardar('imap_puerto', (string)$puerto);
    flash('ok', "Servidor IMAP: $host:$puerto (TLS).");
    redirigir($volver);
}

/* ---------- Token de la rutina: se muestra una sola vez ---------- */
if ($accion === 'token' && es_post()) {
    $token = bin2hex(random_bytes(32));
    ajuste_guardar('correos_token_sha256', hash('sha256', $token));
    ajuste_guardar('correos_token_creado', ahora());
    layout_inicio('Token de la rutina', 'sistema');
    ?>
    <h1>Token de la rutina de correos</h1>
    <div class="alerta alerta-aviso">Cópielo ahora: no se vuelve a mostrar. El token anterior, si había, dejó de funcionar.</div>
    <section class="panel">
        <p>Péguelo como variable de entorno <code>CRM_CORREOS_TOKEN</code> en el entorno de la rutina (claude.ai → Code → Entornos).
            No lo envíe por correo ni lo pegue en un chat.</p>
        <div class="grupo-input">
            <input type="text" id="token-rutina" readonly value="<?= e($token) ?>" class="clave-visible" style="width:100%;font-family:monospace">
        </div>
        <div class="acciones">
            <button type="button" data-copiar="token-rutina">Copiar</button>
            <a class="boton secundario" href="<?= e($volver) ?>">Listo</a>
        </div>
    </section>
    <?php
    layout_fin();
    return;
}

if ($accion === 'revocar' && es_post()) {
    ajuste_guardar('correos_token_sha256', null);
    flash('ok', 'Token revocado: la rutina ya no puede leer correos ni dejar propuestas.');
    redirigir($volver);
}

/* ---------- Vista ---------- */
$buzones = q_todos('SELECT * FROM correo_buzones ORDER BY usuario');
$editar = $id ? q_uno('SELECT * FROM correo_buzones WHERE id = ?', [$id]) : null;
$srv = correos_servidor();
$conteo = array_column(q_todos('SELECT estado, COUNT(*) n FROM correos_entrantes GROUP BY estado'), 'n', 'estado');
$recientes = q_todos('SELECT remitente, asunto, recibido_en, estado, propuestas, buzones FROM correos_entrantes ORDER BY recibido_en DESC LIMIT 15');
$tokenCreado = ajuste('correos_token_sha256') ? ajuste('correos_token_creado') : null;
$base = url_absoluta('');

layout_inicio('Correos', 'sistema');
?>
<div class="encabezado">
    <h1>Correos</h1>
    <div><a class="boton secundario" href="<?= e(url('bandeja')) ?>">Ir a la Bandeja de correos</a></div>
</div>
<p class="tenue">Cada hora el servidor lee la bandeja de entrada de estos buzones (sin marcar nada como leído) y guarda un extracto de los correos nuevos.
    La rutina de Claude los clasifica y deja propuestas en la Bandeja; nada se crea sin aprobación. Los extractos se borran a los <?= CORREOS_DIAS_RETENCION ?> días.</p>

<section class="panel">
    <div class="encabezado"><h2>Buzones</h2>
        <?php if ($buzones): ?><?= boton_post(url('correos', ['a' => 'leer']), 'Leer todos ahora', 'secundario') ?><?php endif; ?></div>
    <?php if (!$buzones): ?><p class="vacio">Aún no hay buzones. Agregue el de Ignacio y el de Arturo abajo.</p><?php else: ?>
    <table>
        <thead><tr><th>Buzón</th><th>Estado</th><th>Última lectura</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($buzones as $b): ?>
            <tr>
                <td><?= e($b['usuario']) ?></td>
                <td><?php if (!$b['activo']): ?><span class="badge">Pausado</span>
                    <?php elseif ($b['error']): ?><span class="badge mandato-vencido">Error</span> <small class="texto-peligro"><?= e($b['error']) ?></small>
                    <?php elseif ($b['leido_en']): ?><span class="badge mandato-vigente">OK</span>
                    <?php else: ?><span class="badge">Sin probar</span><?php endif; ?></td>
                <td class="nowrap"><?= e(fecha($b['leido_en'], true)) ?: '—' ?></td>
                <td class="derecha nowrap">
                    <?= boton_post(url('correos', ['a' => 'leer', 'id' => $b['id']]), 'Probar', 'chico secundario') ?>
                    <a class="boton chico secundario" href="<?= e(url('correos', ['id' => $b['id']])) ?>#form-buzon">Editar</a>
                    <?= boton_post(url('correos', ['a' => 'eliminar_buzon', 'id' => $b['id']]), 'Quitar', 'chico peligro', '¿Quitar este buzón? Se deja de leer.') ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3 class="separado" id="form-buzon"><?= $editar ? 'Editar ' . e($editar['usuario']) : 'Agregar buzón' ?></h3>
    <form method="post" action="<?= e(url('correos', ['a' => 'guardar_buzon', 'id' => $editar['id'] ?? null])) ?>" class="formulario fila-formulario" autocomplete="off">
        <?= csrf_campo() ?>
        <div><?= campo('usuario', 'Correo del buzón', $editar['usuario'] ?? '', 'email', 'required placeholder="nombre@proactionconsultores.cl"') ?></div>
        <div><label for="f_clave">Clave del buzón<?= $editar ? ' (vacío = no cambiar)' : '' ?></label>
            <input type="password" id="f_clave" name="clave" autocomplete="new-password" <?= $editar ? '' : 'required' ?>></div>
        <div><label class="check"><input type="checkbox" name="activo" value="1" <?= !$editar || $editar['activo'] ? 'checked' : '' ?>> Leer este buzón</label></div>
        <div><button type="submit">Guardar</button><?php if ($editar): ?> <a class="boton secundario" href="<?= e($volver) ?>">Cancelar</a><?php endif; ?></div>
    </form>
    <p class="tenue chico-texto">La clave se guarda cifrada con la llave del servidor (la misma de las credenciales) y nunca se vuelve a mostrar.</p>
</section>

<div class="columnas">
    <section class="panel">
        <h2>Rutina de Claude</h2>
        <?php if ($tokenCreado): ?>
            <p>✅ Token activo desde el <?= e(fecha($tokenCreado, true)) ?>.</p>
        <?php else: ?>
            <p>⚠️ Sin token: la rutina no puede leer correos todavía.</p>
        <?php endif; ?>
        <dl class="ficha">
            <dt>Dirección</dt><dd><code><?= e($base) ?>api_correos.php</code></dd>
            <dt>Cabecera</dt><dd><code>X-Correos-Token</code></dd>
            <dt>Por clasificar</dt><dd><?= (int)($conteo['nuevo'] ?? 0) ?> correos</dd>
        </dl>
        <div class="acciones">
            <?= boton_post(url('correos', ['a' => 'token']), $tokenCreado ? 'Generar token nuevo' : 'Generar token', $tokenCreado ? 'secundario' : '',
                $tokenCreado ? '¿Generar otro token? El actual deja de funcionar y habrá que actualizar la rutina.' : '') ?>
            <?php if ($tokenCreado): ?><?= boton_post(url('correos', ['a' => 'revocar']), 'Revocar', 'peligro', '¿Revocar el token? La rutina dejará de funcionar.') ?><?php endif; ?>
        </div>
    </section>
    <section class="panel">
        <h2>Servidor IMAP</h2>
        <form method="post" action="<?= e(url('correos', ['a' => 'servidor'])) ?>" class="formulario fila-formulario">
            <?= csrf_campo() ?>
            <div><?= campo('imap_servidor', 'Servidor', $srv['host']) ?></div>
            <div><?= campo('imap_puerto', 'Puerto (TLS)', (string)$srv['puerto'], 'number') ?></div>
            <div><button type="submit" class="secundario">Guardar</button></div>
        </form>
        <p class="tenue chico-texto">«localhost» sirve cuando el correo está en el mismo hosting que el CRM. Lectura programada (cPanel → Trabajos de cron, cada hora):<br>
            <code>php <?= e(dirname(__DIR__, 2)) ?>/cron_correos.php</code></p>
    </section>
</div>

<section class="panel">
    <h2>Últimos correos recibidos</h2>
    <p class="tenue">Por clasificar: <?= (int)($conteo['nuevo'] ?? 0) ?> · clasificados: <?= (int)($conteo['clasificado'] ?? 0) ?> · automáticos ignorados: <?= (int)($conteo['ignorado'] ?? 0) ?></p>
    <?php if (!$recientes): ?><p class="vacio">Todavía no se ha leído ningún correo.</p><?php else: ?>
    <table>
        <thead><tr><th>Recibido</th><th>De</th><th>Asunto</th><th>Buzón</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($recientes as $c): ?>
            <tr class="<?= $c['estado'] === 'ignorado' ? 'hecha' : '' ?>">
                <td class="nowrap"><?= e(fecha($c['recibido_en'], true)) ?></td>
                <td><?= e($c['remitente']) ?></td>
                <td><?= e($c['asunto']) ?></td>
                <td class="tenue"><?= e($c['buzones']) ?></td>
                <td><?= e(['nuevo' => 'Por clasificar', 'clasificado' => 'Clasificado', 'ignorado' => 'Automático'][$c['estado']] ?? $c['estado']) ?>
                    <?= $c['estado'] === 'clasificado' ? '<small class="tenue">· ' . (int)$c['propuestas'] . ' propuesta(s)</small>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php
layout_fin();
