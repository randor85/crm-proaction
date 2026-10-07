<?php
declare(strict_types=1);

requerir_admin();

if ($accion === 'crear_llave' && es_post()) {
    try {
        llave_crear();
        flash('ok', 'Llave de cifrado creada en ' . llave_ruta() . '. Descargue una copia de respaldo ahora y guárdela fuera del servidor.');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirigir(url('sistema'));
}

if ($accion === 'indicador' && es_post()) {
    $codigo = entrada('codigo');
    $fechaI = entrada_fecha('fecha');
    $valor = entrada_decimal('valor');
    if (isset(INDICADORES[$codigo]) && $fechaI && $valor > 0) {
        indicador_guardar($codigo, $codigo === 'utm' ? substr($fechaI, 0, 7) . '-01' : $fechaI, $valor);
        flash('ok', INDICADORES[$codigo] . ' del ' . fecha($fechaI) . ' guardada: ' . formato_uf($valor) . '.');
    } else {
        flash('error', 'Indique indicador, fecha y un valor válido.');
    }
    redirigir(url('sistema'));
}

/* ---------- Reorganizaciones únicas de la planilla histórica ---------- */
$reorganizaciones = [
    'reorganizar' => ['aplicada' => 'reorganizacion_aplicada', 'correr' => 'reorganizar_datos', 'ancla' => 'reorganizacion'],
    'etapa2'      => ['aplicada' => 'reorganizacion2_aplicada', 'correr' => 'reorganizar_etapa2', 'ancla' => 'etapa2'],
];
[$tipoReorg] = explode('_', $accion) + [''];
if (isset($reorganizaciones[$tipoReorg]) && in_array($accion, [$tipoReorg . '_vista', $tipoReorg . '_aplicar'], true) && es_post()) {
    $reorg = $reorganizaciones[$tipoReorg];
    $volverReorg = url('sistema') . '#' . $reorg['ancla'];
    $aplicar = $accion === $tipoReorg . '_aplicar';
    if ($fechaYa = $reorg['aplicada']()) {
        flash('error', 'La reorganización ya se aplicó el ' . fecha($fechaYa, true) . '.');
        redirigir($volverReorg);
    }
    if ($tipoReorg === 'etapa2' && !reorganizacion_aplicada()) {
        flash('error', 'Aplique primero la reorganización inicial.');
        redirigir($volverReorg);
    }
    if ($aplicar && entrada('respaldo') !== '1') {
        flash('error', 'Confirme que respaldó la base de datos antes de aplicar.');
        redirigir($volverReorg);
    }
    try {
        $informe = $reorg['correr']($aplicar);
    } catch (Throwable $ex) {
        error_log('CRM - error en la reorganización: ' . $ex->getMessage());
        flash('error', 'No se cambió nada: la reorganización falló (' . $ex->getMessage() . ').');
        redirigir($volverReorg);
    }
    layout_inicio($aplicar ? 'Reorganización aplicada' : 'Vista previa de la reorganización', 'sistema');
    ?>
    <h1><?= $aplicar ? 'Reorganización aplicada' : 'Vista previa de la reorganización' ?></h1>
    <div class="alerta alerta-<?= $aplicar ? 'ok' : 'aviso' ?>"><?= $aplicar
        ? 'Los cambios quedaron guardados.'
        : 'Esto es solo una simulación: se calculó todo y se deshizo. La base no cambió.' ?></div>
    <?php foreach ($informe as $paso => $lineas): ?>
        <section class="panel">
            <h2><?= e($paso) ?></h2>
            <ul><?php foreach ($lineas as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
        </section>
    <?php endforeach; ?>
    <?php if (!$aplicar): ?>
    <section class="panel">
        <h2>¿Aplicar?</h2>
        <form method="post" action="<?= e(url('sistema', ['a' => $tipoReorg . '_aplicar'])) ?>" class="formulario">
            <?= csrf_campo() ?>
            <label class="check"><input type="checkbox" name="respaldo" value="1" required> Respaldé la base de datos (cPanel → Copias de seguridad → base proactio_crm)</label>
            <div class="acciones">
                <button type="submit" onclick="return confirm('¿Aplicar la reorganización? No tiene vuelta atrás salvo restaurando el respaldo.')">Aplicar la reorganización</button>
                <a class="boton secundario" href="<?= e(url('sistema')) ?>">Volver</a>
            </div>
        </form>
    </section>
    <?php endif; ?>
    <?php
    layout_fin();
    return;
}

$dirDocs = documentos_ruta();
$docsOk = is_dir($dirDocs) ? is_writable($dirDocs) : is_writable(dirname($dirDocs));
$dentroWeb = static fn(string $ruta): bool => strpos(str_replace('\\', '/', realpath(dirname($ruta)) ?: $ruta),
    str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '#')) === 0;

$chequeos = [
    ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>='), 'Se requiere PHP 8.0 o superior.'],
    ['Extensión OpenSSL (cifrado)', extension_loaded('openssl'), 'Necesaria para el gestor de credenciales.'],
    ['Extensión zip (importar planillas .xlsx)', class_exists('ZipArchive'), 'Sin ella solo se pueden importar planillas .csv.'],
    ['Conexión a internet (cURL)', function_exists('curl_init') || ini_get('allow_url_fopen'), 'Necesaria para obtener la UF automáticamente.'],
    ['Llave de cifrado', llave_existe(), llave_existe() ? llave_ruta() : 'No existe todavía: créela abajo.'],
    ['Llave fuera de la carpeta pública', $llaveFuera = !llave_existe() || !$dentroWeb(llave_ruta()),
        $llaveFuera ? 'Correcto.' : 'Mueva la llave fuera de public_html (config "llave_archivo").'],
    ['Carpeta de documentos escribible', $docsOk, $dirDocs],
    ['Documentos fuera de la carpeta pública', $docsFuera = !$dentroWeb($dirDocs . '/x'),
        $docsFuera ? 'Correcto.' : 'Recomendado: config "documentos_ruta" fuera de public_html.'],
];
$migraciones = q_todos('SELECT * FROM migraciones ORDER BY aplicada_en');
$indicadoresRecientes = q_todos('SELECT * FROM indicadores ORDER BY fecha DESC, codigo LIMIT 12');

layout_inicio('Sistema', 'sistema');
?>
<h1>Sistema</h1>
<section class="panel">
    <h2>Estado</h2>
    <table><tbody>
    <?php foreach ($chequeos as [$texto, $ok, $detalle]): ?>
        <tr><td><?= $ok ? '✅' : '⚠️' ?></td><td><?= e($texto) ?></td><td class="tenue"><?= e($detalle) ?></td></tr>
    <?php endforeach; ?>
        <tr><td>ℹ️</td><td>Verificación en dos pasos para credenciales</td><td class="tenue"><?= credenciales_requieren_2fa() ? 'Exigida' : 'Opcional (se pide la contraseña para mostrar cada clave). Se cambia con "credenciales_requiere_2fa" en config.local.php.' ?></td></tr>
        <tr><td>ℹ️</td><td>Tamaño máximo de subida</td><td class="tenue"><?= e(ini_get('upload_max_filesize')) ?> por archivo · <?= e(ini_get('post_max_size')) ?> por envío</td></tr>
    </tbody></table>
</section>

<section class="panel" id="reorganizacion">
    <h2>Reorganizar datos de la planilla histórica</h2>
    <?php if ($fechaReorg = reorganizacion_aplicada()): ?>
        <p>✅ Aplicada el <?= e(fecha($fechaReorg, true)) ?>.</p>
    <?php else: ?>
        <p>Ordena lo importado de la planilla según lo acordado: grupos Lefimil y Angel Villar como un cliente cada uno,
            «Del pasado» como ex-clientes, listas del SII y «A futuro/Para probar» como prospectos, responsables de tareas y Pedro Alvares
            como contactos, socios persona con su RUT dentro del cliente, y correcciones de socios, credenciales y notas.</p>
        <p>Primero vea la <strong>vista previa</strong>: calcula todo y lo deshace, sin cambiar nada.</p>
        <?= boton_post(url('sistema', ['a' => 'reorganizar_vista']), 'Ver vista previa', 'secundario') ?>
    <?php endif; ?>
</section>

<?php if (reorganizacion_aplicada()): ?>
<section class="panel" id="etapa2">
    <h2>Reorganización, segunda etapa</h2>
    <?php if ($fechaEtapa2 = reorganizacion2_aplicada()): ?>
        <p>✅ Aplicada el <?= e(fecha($fechaEtapa2, true)) ?>.</p>
    <?php else: ?>
        <p>Convierte las categorías «Empresas para declaración» e «Impuestos por revisar» en tareas sin plazo,
            quita las categorías Otras empresas, ALE, Personales y Préstamos solidarios (queda anotada en la nota del cliente)
            y une los contactos repetidos en una sola ficha por persona, vinculada a todos sus RUT.</p>
        <?= boton_post(url('sistema', ['a' => 'etapa2_vista']), 'Ver vista previa', 'secundario') ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
    <div class="encabezado"><h2>Correos</h2><a class="boton secundario" href="<?= e(url('correos')) ?>">Configurar</a></div>
    <p>Buzones que el servidor lee por IMAP, cola de correos por clasificar y token de la rutina de Claude que deja propuestas en la Bandeja de correos.</p>
</section>

<section class="panel">
    <h2>Llave de cifrado de credenciales</h2>
    <?php if (llave_existe()): ?>
        <p>La llave existe en <code><?= e(llave_ruta()) ?></code>.</p>
        <p class="alerta alerta-aviso"><strong>Respaldo:</strong> si este archivo se pierde, las claves guardadas no se pueden recuperar.
            Descárguelo por el Administrador de archivos de cPanel y guárdelo en un lugar seguro, <em>separado</em> de los respaldos de la base de datos.</p>
    <?php else: ?>
        <p>Se creará un archivo con una llave aleatoria de 256 bits en <code><?= e(llave_ruta()) ?></code>.</p>
        <?= boton_post(url('sistema', ['a' => 'crear_llave']), 'Crear llave de cifrado') ?>
    <?php endif; ?>
</section>

<div class="columnas">
    <section class="panel">
        <h2>Indicadores guardados</h2>
        <p class="tenue">Se obtienen de mindicador.cl la primera vez que se necesitan. Si la fuente no responde, puede ingresarlos a mano.</p>
        <table><tbody>
        <?php foreach ($indicadoresRecientes as $i): ?>
            <tr><td><?= e(INDICADORES[$i['codigo']] ?? $i['codigo']) ?></td><td><?= e(fecha($i['fecha'])) ?></td><td class="derecha">$ <?= e(formato_uf($i['valor'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$indicadoresRecientes): ?><tr><td class="vacio">Sin valores guardados.</td></tr><?php endif; ?>
        </tbody></table>
        <form method="post" action="<?= e(url('sistema', ['a' => 'indicador'])) ?>" class="formulario fila-formulario">
            <?= csrf_campo() ?>
            <div><?= selector('codigo', 'Indicador', INDICADORES, 'uf', false) ?></div>
            <div><?= campo('fecha', 'Fecha', date('Y-m-d'), 'date', 'required') ?></div>
            <div><?= campo('valor', 'Valor', '', 'text', 'required inputmode="decimal" placeholder="39.485,65"') ?></div>
            <div><button type="submit">Guardar</button></div>
        </form>
    </section>
    <section class="panel">
        <h2>Base de datos</h2>
        <p>Motor: <strong><?= e(db_driver()) ?></strong></p>
        <table><tbody>
        <?php foreach ($migraciones as $m): ?>
            <tr><td><?= e($m['id']) ?></td><td class="tenue"><?= e(fecha($m['aplicada_en'], true)) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p><a href="<?= e(url('credenciales', ['a' => 'registro'])) ?>">Registro de accesos a credenciales →</a></p>
    </section>
</div>
<?php
layout_fin();
