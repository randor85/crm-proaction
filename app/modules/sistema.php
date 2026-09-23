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
