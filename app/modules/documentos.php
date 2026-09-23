<?php
declare(strict_types=1);

$id = entrada_int('id');

const EXTENSIONES_PERMITIDAS = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'csv', 'txt', 'xml', 'ppt', 'pptx', 'odt', 'ods',
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'rar', '7z', 'msg', 'eml',
];

function documentos_max_bytes(): int
{
    $aBytes = static function (string $v): int {
        $n = (int)$v;
        $u = strtolower(substr(trim($v), -1));
        return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
    };
    $limites = array_filter([
        (int)config('documentos_max_mb', 20) * 1048576,
        $aBytes((string)ini_get('upload_max_filesize')),
        $aBytes((string)ini_get('post_max_size')),
    ]);
    return $limites ? min($limites) : 2097152;
}

/** Asegura la carpeta de documentos, protegida por si quedara dentro de public_html. */
function documentos_preparar_carpeta(): string
{
    $dir = documentos_ruta();
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException('No se pudo crear la carpeta de documentos: ' . $dir);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

/* ---------- Descargar ---------- */
if ($accion === 'descargar' && $id) {
    $d = q_uno('SELECT * FROM documentos WHERE id = ?', [$id]);
    $ruta = $d ? documentos_ruta() . '/' . basename($d['archivo']) : '';
    if (!$d || !is_file($ruta)) {
        http_response_code(404);
        flash('error', 'El archivo no existe en el servidor.');
        redirigir(url('documentos'));
    }
    $ext = strtolower(pathinfo($d['nombre'], PATHINFO_EXTENSION));
    $enLinea = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true) && entrada('descargar') !== '1';
    $nombreAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $d['nombre']) ?: 'documento');
    header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($ruta));
    header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombreAscii . '"; filename*=UTF-8\'\'' . rawurlencode($d['nombre']));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, no-store');
    readfile($ruta);
    exit;
}

/* ---------- Subir ---------- */
if ($accion === 'subir' && es_post()) {
    $clienteId = entrada_int('cliente_id');
    $empresaId = entrada_int('empresa_id');
    if (!$clienteId && $empresaId) {
        $clienteId = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$empresaId]);
    }
    $categoria = entrada('categoria');
    $periodo = preg_match('/^\d{4}(-\d{2})?$/', entrada('periodo')) ? entrada('periodo') : null;
    $volver = url('documentos', ['a' => 'form', 'cliente_id' => $clienteId, 'empresa_id' => $empresaId]);

    if (!$clienteId && !$empresaId) {
        flash('error', 'Indique el cliente o RUT al que pertenecen los documentos.');
        redirigir($volver);
    }
    $archivos = $_FILES['archivos'] ?? null;
    if (!$archivos || !is_array($archivos['name'])) {
        flash('error', 'No se recibió ningún archivo. Puede que supere el tamaño máximo permitido.');
        redirigir($volver);
    }

    $dir = documentos_preparar_carpeta();
    $max = documentos_max_bytes();
    $ok = 0;
    foreach ($archivos['name'] as $i => $nombre) {
        $nombre = trim(str_replace(["\0", '/', '\\'], '', (string)$nombre));
        $error = (int)$archivos['error'][$i];
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if ($error !== UPLOAD_ERR_OK || $archivos['size'][$i] > $max) {
            flash('error', "$nombre: supera el tamaño máximo (" . tamano_legible($max) . ') o no se pudo subir.');
            continue;
        }
        if (!in_array($ext, EXTENSIONES_PERMITIDAS, true)) {
            flash('error', "$nombre: tipo de archivo no permitido (.$ext).");
            continue;
        }
        $guardado = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($archivos['tmp_name'][$i], $dir . '/' . $guardado)) {
            flash('error', "$nombre: no se pudo guardar en el servidor.");
            continue;
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($dir . '/' . $guardado) ?: null) : null;
        insertar('documentos', [
            'cliente_id'  => $clienteId,
            'empresa_id'  => $empresaId,
            'categoria'   => isset(CATEGORIAS_DOCUMENTO[$categoria]) ? $categoria : 'otro',
            'nombre'      => mb_substr($nombre, 0, 200),
            'archivo'     => $guardado,
            'mime'        => $mime,
            'tamano'      => (int)$archivos['size'][$i],
            'periodo'     => $periodo,
            'descripcion' => nulo_si_vacio(entrada('descripcion')),
            'subido_por'  => (int)usuario_actual()['id'],
            'creado_en'   => ahora(),
        ]);
        $ok++;
    }
    if ($ok) {
        flash('ok', "$ok documento(s) subido(s).");
    }
    redirigir(url('documentos', array_filter(['cliente_id' => $clienteId, 'empresa_id' => $empresaId])));
}

/* ---------- Editar datos / eliminar ---------- */
if ($accion === 'guardar' && es_post() && $id) {
    $categoria = entrada('categoria');
    actualizar('documentos', $id, [
        'nombre'      => entrada('nombre') ?: 'documento',
        'categoria'   => isset(CATEGORIAS_DOCUMENTO[$categoria]) ? $categoria : 'otro',
        'periodo'     => preg_match('/^\d{4}(-\d{2})?$/', entrada('periodo')) ? entrada('periodo') : null,
        'descripcion' => nulo_si_vacio(entrada('descripcion')),
        'empresa_id'  => entrada_int('empresa_id'),
    ]);
    flash('ok', 'Documento actualizado.');
    redirigir(url('documentos', ['cliente_id' => q_valor('SELECT cliente_id FROM documentos WHERE id = ?', [$id])]));
}

if ($accion === 'eliminar' && es_post() && $id) {
    $d = q_uno('SELECT * FROM documentos WHERE id = ?', [$id]);
    if (!puede_eliminar($d, 'subido_por')) {
        flash('error', 'Solo quien subió el documento o un administrador puede eliminarlo.');
        redirigir(url('documentos', ['a' => 'form', 'id' => $id]));
    }
    @unlink(documentos_ruta() . '/' . basename($d['archivo']));
    q('DELETE FROM documentos WHERE id = ?', [$id]);
    flash('ok', 'Documento eliminado.');
    redirigir(url('documentos', array_filter(['cliente_id' => $d['cliente_id']])));
}

/* ---------- Formulario (subir o editar) ---------- */
if ($accion === 'form') {
    $d = $id ? q_uno('SELECT * FROM documentos WHERE id = ?', [$id]) : null;
    $clienteId = $d['cliente_id'] ?? entrada_int('cliente_id');
    $empresaId = $d['empresa_id'] ?? entrada_int('empresa_id');
    if (!$clienteId && $empresaId) {
        $clienteId = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$empresaId]);
    }
    layout_inicio($id ? 'Editar documento' : 'Subir documentos', 'documentos');
    ?>
    <div class="encabezado">
        <h1><?= $id ? 'Editar documento' : 'Subir documentos' ?></h1>
        <?php if ($d && puede_eliminar($d, 'subido_por')) echo boton_post(url('documentos', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar este documento del servidor?'); ?>
    </div>
    <form method="post" enctype="multipart/form-data" action="<?= e(url('documentos', ['a' => $id ? 'guardar' : 'subir', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <?php if ($id): ?>
            <div class="completo"><?= campo('nombre', 'Nombre', $d['nombre']) ?></div>
        <?php else: ?>
            <div class="completo">
                <label for="f_archivos">Archivos (puede elegir varios)</label>
                <input type="file" id="f_archivos" name="archivos[]" multiple required accept=".<?= e(implode(',.', EXTENSIONES_PERMITIDAS)) ?>">
                <small class="tenue">Máximo <?= e(tamano_legible(documentos_max_bytes())) ?> por archivo. PDF, Office, imágenes, XML, ZIP, correos.</small>
            </div>
            <div><?= selector('cliente_id', 'Cliente *', opciones_clientes(false), $clienteId ?? '', true, 'required') ?></div>
        <?php endif; ?>
        <div><?= selector_empresa('empresa_id', 'RUT / Empresa', $empresaId ?? '', 'Todas / del cliente') ?></div>
        <div><?= selector('categoria', 'Categoría', CATEGORIAS_DOCUMENTO, $d['categoria'] ?? 'tributario', false) ?></div>
        <div><?= campo('periodo', 'Período (año o mes)', $d['periodo'] ?? '', 'text', 'placeholder="2026 o 2026-08" pattern="\d{4}(-\d{2})?"') ?></div>
        <div class="completo"><?= area('descripcion', 'Descripción', $d['descripcion'] ?? '') ?></div>
        <div class="completo acciones">
            <button type="submit"><?= $id ? 'Guardar' : 'Subir' ?></button>
            <a class="boton secundario" href="<?= e(url('documentos', array_filter(['cliente_id' => $clienteId]))) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['d.nombre', 'd.descripcion', 'c.nombre', 'e.nombre', 'e.identificacion'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
$filtroCliente = entrada_int('cliente_id');
if ($filtroCliente) {
    $condiciones[] = 'd.cliente_id = :cliente';
    $params['cliente'] = $filtroCliente;
}
$filtroEmpresa = entrada_int('empresa_id');
if ($filtroEmpresa) {
    $condiciones[] = 'd.empresa_id = :empresa';
    $params['empresa'] = $filtroEmpresa;
}
$categoria = entrada('categoria');
if (isset(CATEGORIAS_DOCUMENTO[$categoria])) {
    $condiciones[] = 'd.categoria = :categoria';
    $params['categoria'] = $categoria;
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$desdeTabla = 'FROM documentos d LEFT JOIN clientes c ON c.id = d.cliente_id LEFT JOIN empresas e ON e.id = d.empresa_id
    LEFT JOIN usuarios u ON u.id = d.subido_por';
[$limite, $desde] = paginacion_limites();
$total = (int)q_valor("SELECT COUNT(*) $desdeTabla $where", $params);
$documentos = q_todos("SELECT d.*, c.nombre AS cliente, e.nombre AS empresa, u.nombre AS usuario
    $desdeTabla $where ORDER BY d.creado_en DESC LIMIT $limite OFFSET $desde", $params);

layout_inicio('Documentos', 'documentos');
?>
<h1>Documentos</h1>
<form class="barra-lista" method="get">
    <input type="hidden" name="r" value="documentos">
    <input type="search" name="q" value="<?= e(entrada('q')) ?>" placeholder="Buscar…">
    <?= selector('cliente_id', '', opciones_clientes(false), $filtroCliente ?? '', 'Todos los clientes', 'aria-label="Cliente"') ?>
    <?= selector('categoria', '', CATEGORIAS_DOCUMENTO, $categoria, 'Todas las categorías', 'aria-label="Categoría"') ?>
    <?php if ($filtroEmpresa): ?><input type="hidden" name="empresa_id" value="<?= (int)$filtroEmpresa ?>"><?php endif; ?>
    <button type="submit" class="secundario">Filtrar</button>
    <span class="espaciador"></span>
    <a class="boton" href="<?= e(url('documentos', ['a' => 'form'] + array_filter(['cliente_id' => $filtroCliente, 'empresa_id' => $filtroEmpresa]))) ?>">+ Subir documentos</a>
</form>
<table>
    <thead><tr><th>Documento</th><th>Cliente / RUT</th><th>Categoría</th><th>Período</th><th>Subido</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($documentos as $d): ?>
        <tr>
            <td><a href="<?= e(url('documentos', ['a' => 'descargar', 'id' => $d['id']])) ?>" target="_blank"><?= e($d['nombre']) ?></a>
                <small class="tenue"> · <?= e(tamano_legible((int)$d['tamano'])) ?></small>
                <?php if ($d['descripcion']): ?><br><small class="tenue"><?= e($d['descripcion']) ?></small><?php endif; ?></td>
            <td><?= enlace('clientes', $d['cliente_id'], $d['cliente']) ?><?php if ($d['empresa']): ?><br><small class="tenue"><?= e($d['empresa']) ?></small><?php endif; ?></td>
            <td><span class="badge"><?= e(CATEGORIAS_DOCUMENTO[$d['categoria']] ?? $d['categoria']) ?></span></td>
            <td class="nowrap"><?= e($d['periodo']) ?></td>
            <td class="nowrap tenue"><?= e(fecha($d['creado_en'])) ?><br><small><?= e($d['usuario']) ?></small></td>
            <td class="derecha nowrap">
                <a class="boton chico secundario" href="<?= e(url('documentos', ['a' => 'descargar', 'id' => $d['id'], 'descargar' => 1])) ?>">Descargar</a>
                <a class="boton chico secundario" href="<?= e(url('documentos', ['a' => 'form', 'id' => $d['id']])) ?>">Editar</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$documentos): ?><tr><td colspan="6" class="vacio">No hay documentos con estos filtros.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
