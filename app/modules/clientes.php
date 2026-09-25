<?php
declare(strict_types=1);

$id = entrada_int('id');

/** Valores ya usados en una columna de clientes (para sugerencias y filtros). */
function valores_cliente(string $columna): array
{
    static $cache = [];
    if (!in_array($columna, ['categoria', 'situacion'], true)) {
        return [];
    }
    return $cache[$columna] ??= array_column(
        q_todos("SELECT DISTINCT $columna AS v FROM clientes WHERE $columna IS NOT NULL ORDER BY $columna"), 'v');
}

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    [$rut, $errorRut] = rut_entrada('rut');
    $tipo = entrada('tipo');
    $datos = [
        'nombre'           => entrada('nombre'),
        'tipo'             => isset(TIPOS_CLIENTE[$tipo]) ? $tipo : 'empresa',
        'rut'              => $rut,
        'email'            => nulo_si_vacio(entrada('email')),
        'telefono'         => nulo_si_vacio(entrada('telefono')),
        'direccion'        => nulo_si_vacio(entrada('direccion')),
        'ejecutivo_id'     => entrada_int('ejecutivo_id'),
        'categoria'        => entrada_etiqueta('categoria'),
        'situacion'        => entrada_etiqueta('situacion'),
        'activo'           => entrada('activo') === '1' ? 1 : 0,
        'notas'            => nulo_si_vacio(entrada('notas')),
        'actualizado_en'   => ahora(),
    ];
    if ($datos['nombre'] === '' || $errorRut) {
        flash('error', $errorRut ?: 'El nombre del cliente es obligatorio.');
        redirigir(url('clientes', ['a' => 'form', 'id' => $id]));
    }
    if ($id) {
        actualizar('clientes', $id, $datos);
        flash('ok', 'Cliente actualizado.');
    } else {
        $datos['creado_en'] = ahora();
        $id = insertar('clientes', $datos);
        // Si es una empresa o persona con RUT, crear también su ficha de RUT.
        if ($rut && entrada('crear_empresa') === '1') {
            insertar('empresas', [
                'nombre' => $datos['nombre'], 'identificacion' => $rut, 'cliente_id' => $id,
                'email' => $datos['email'], 'telefono' => $datos['telefono'], 'direccion' => $datos['direccion'],
                'responsable_id' => $datos['ejecutivo_id'], 'creado_en' => ahora(), 'actualizado_en' => ahora(),
            ]);
        }
        // Honorario inicial → primer plan de cobro
        $monto = entrada_decimal('honorario_monto');
        if ($monto > 0) {
            $periodicidad = entrada('periodicidad');
            $doc = entrada('documento_tipo');
            insertar('cobros', [
                'cliente_id' => $id, 'concepto' => 'Honorarios asesoría tributaria', 'monto' => $monto,
                'moneda' => entrada('honorario_moneda') === 'CLP' ? 'CLP' : 'UF',
                'documento_tipo' => isset(TIPOS_DOCUMENTO_VENTA[$doc]) ? $doc : 'factura_afecta',
                'periodicidad' => isset(PERIODICIDADES[$periodicidad]) ? $periodicidad : 'mensual',
                'mes_inicio' => max(1, min(12, entrada_int('mes_inicio') ?? 1)), 'activo' => 1,
                'creado_en' => ahora(), 'actualizado_en' => ahora(),
            ]);
        }
        flash('ok', 'Cliente creado.');
    }
    // Equipo: otros ejecutivos marcados, con su área (el principal puede tener área también)
    q('DELETE FROM cliente_ejecutivos WHERE cliente_id = ?', [$id]);
    $areas = (array)($_POST['area'] ?? []);
    foreach (array_keys(opciones_usuarios()) as $uid) {
        $marcado = in_array((string)$uid, (array)($_POST['equipo'] ?? []), true);
        $area = mb_substr(trim((string)($areas[$uid] ?? '')), 0, 60);
        if ($marcado || ($uid === $datos['ejecutivo_id'] && $area !== '')) {
            insertar('cliente_ejecutivos', ['cliente_id' => $id, 'usuario_id' => $uid, 'area' => nulo_si_vacio($area)]);
        }
    }
    redirigir(url('clientes', ['a' => 'ver', 'id' => $id]));
}

/* ---------- Cambio masivo: qué cambiar y a quiénes (se aplica tras calcular los filtros) ---------- */
if ($accion === 'masivo' && es_post()) {
    $cambios = [];
    foreach (['categoria', 'situacion'] as $campo) {
        if (entrada("m_$campo") !== '__igual__') {
            $cambios[$campo] = entrada_etiqueta("m_$campo");
        }
    }
    if (ctype_digit(entrada('m_ejecutivo_id'))) {
        $cambios['ejecutivo_id'] = (int)entrada('m_ejecutivo_id');
    }
    if (in_array(entrada('m_activo'), ['0', '1'], true)) {
        $cambios['activo'] = (int)entrada('m_activo');
    }
    $volver = (string)($_POST['volver'] ?? '');
    $volver = strpos($volver, 'index.php?r=clientes') === 0 ? $volver : url('clientes');
    $ids = [];
    if (entrada('todos_filtro') === '1') {
        // Reaplicar los mismos filtros de la lista (vienen en la URL de retorno)
        parse_str((string)parse_url($volver, PHP_URL_QUERY), $filtroLista);
        $_GET = $filtroLista;
        $_POST = array_intersect_key($_POST, ['csrf' => 1]);
        $ids = null;
    } else {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    }
    if (!$cambios || $ids === []) {
        flash('error', !$cambios ? 'No eligió ningún cambio.' : 'No marcó ningún cliente.');
        redirigir($volver);
    }
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $c = q_uno('SELECT * FROM clientes WHERE id = ?', [$id]);
    if (!es_admin()) {
        flash('error', 'Solo un administrador puede eliminar clientes. Puede marcarlo como inactivo.');
        redirigir(url('clientes', ['a' => 'ver', 'id' => $id]));
    }
    // Los archivos de documentos se borran del disco antes de eliminar los registros.
    foreach (q_todos('SELECT archivo FROM documentos WHERE cliente_id = ?', [$id]) as $d) {
        @unlink(documentos_ruta() . '/' . $d['archivo']);
    }
    q('UPDATE empresas SET cliente_id = NULL WHERE cliente_id = ?', [$id]);
    q('DELETE FROM clientes WHERE id = ?', [$id]);
    flash('ok', 'Cliente "' . ($c['nombre'] ?? '') . '" eliminado. Sus empresas se conservaron sin cliente asignado.');
    redirigir(url('clientes'));
}

/* ---------- Filtros comunes ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['c.nombre', 'c.rut', 'c.email', 'c.categoria', 'c.notas',
    '(SELECT GROUP_CONCAT(e2.identificacion) FROM empresas e2 WHERE e2.cliente_id = c.id)'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
$estado = entrada('estado', 'activos');
if ($estado !== 'todos') {
    $condiciones[] = 'c.activo = ' . ($estado === 'inactivos' ? 0 : 1);
}
$categoria = entrada('categoria');
if ($categoria !== '') {
    $condiciones[] = 'c.categoria = :categoria';
    $params['categoria'] = $categoria;
}
$situacion = entrada('situacion');
if ($situacion !== '') {
    $condiciones[] = 'c.situacion = :situacion';
    $params['situacion'] = $situacion;
}
$ejecutivo = entrada_int('ejecutivo_id');
if ($ejecutivo) {
    $condiciones[] = '(c.ejecutivo_id = :ejecutivo OR EXISTS (SELECT 1 FROM cliente_ejecutivos ce WHERE ce.cliente_id = c.id AND ce.usuario_id = :ejecutivo2))';
    $params['ejecutivo'] = $ejecutivo;
    $params['ejecutivo2'] = $ejecutivo;
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

if ($accion === 'masivo' && es_post()) {
    $destino = $ids === null ? array_column(q_todos("SELECT c.id FROM clientes c $where", $params), 'id') : $ids;
    foreach ($destino as $cid) {
        actualizar('clientes', (int)$cid, $cambios + ['actualizado_en' => ahora()]);
    }
    $nombres = ['categoria' => 'categoría', 'situacion' => 'situación', 'ejecutivo_id' => 'ejecutivo', 'activo' => 'estado'];
    $textos = [];
    foreach ($cambios as $campo => $valor) {
        $textos[] = $nombres[$campo] . ' → ' . ($campo === 'activo' ? ($valor ? 'activo' : 'inactivo')
            : ($campo === 'ejecutivo_id' ? (opciones_usuarios()[$valor] ?? $valor) : ($valor ?? 'sin asignar')));
    }
    flash('ok', count($destino) . ' cliente(s) actualizado(s): ' . implode(', ', $textos) . '.');
    redirigir($volver);
}

if ($accion === 'csv') {
    $filas = q_todos(
        "SELECT c.nombre, c.rut, c.tipo, c.categoria, c.situacion, c.email, c.telefono, u.nombre AS ejecutivo, c.id,
            (SELECT COUNT(*) FROM empresas e WHERE e.cliente_id = c.id) AS ruts
         FROM clientes c LEFT JOIN usuarios u ON u.id = c.ejecutivo_id $where ORDER BY c.nombre", $params);
    $filas = array_map(static fn($f) => [$f['nombre'], $f['rut'], TIPOS_CLIENTE[$f['tipo']] ?? $f['tipo'], $f['categoria'], $f['situacion'], $f['email'], $f['telefono'],
        implode(', ', array_map(static fn($m) => $m['nombre'] . ($m['area'] ? ' (' . $m['area'] . ')' : ''), equipo_cliente((int)$f['id']))), implode(' + ', array_map(static fn($k) => $k['concepto'] . ': ' . cobro_resumen($k), q_todos('SELECT * FROM cobros WHERE cliente_id = ? AND activo = 1', [$f['id']]))), $f['ruts']], $filas);
    exportar_csv('clientes_' . date('Ymd') . '.csv',
        ['Cliente', 'RUT', 'Tipo', 'Categoría', 'Situación', 'Correo', 'Teléfono', 'Equipo a cargo', 'Planes de cobro', 'N° de RUT'], $filas);
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $c = $id ? q_uno('SELECT * FROM clientes WHERE id = ?', [$id])
        : ['tipo' => 'empresa', 'ejecutivo_id' => usuario_actual()['id'], 'activo' => 1];
    if ($id && !$c) {
        redirigir(url('clientes'));
    }
    layout_inicio($id ? 'Editar cliente' : 'Nuevo cliente', 'clientes');
    ?>
    <h1><?= $id ? 'Editar cliente' : 'Nuevo cliente' ?></h1>
    <form method="post" action="<?= e(url('clientes', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div><?= campo('nombre', 'Nombre del cliente *', $c['nombre'] ?? '', 'text', 'required maxlength="150" placeholder="Ej.: Grupo Fuentes o Juan Pérez"') ?></div>
        <div><?= selector('tipo', 'Tipo', TIPOS_CLIENTE, $c['tipo'], false) ?></div>
        <div><?= campo('rut', 'RUT principal', $c['rut'] ?? '', 'text', 'placeholder="12.345.678-9"') ?></div>
        <div><?= selector('ejecutivo_id', 'Ejecutivo principal', opciones_usuarios(), $c['ejecutivo_id'] ?? '') ?></div>
        <div><?= selector_etiqueta('categoria', 'Categoría', valores_cliente('categoria'), $c['categoria'] ?? '') ?></div>
        <div><?= selector_etiqueta('situacion', 'Situación', valores_cliente('situacion'), $c['situacion'] ?? '') ?></div>
        <div><?= campo('email', 'Correo', $c['email'] ?? '', 'email') ?></div>
        <div><?= campo('telefono', 'Teléfono', $c['telefono'] ?? '', 'tel') ?></div>
        <div class="completo"><?= campo('direccion', 'Dirección', $c['direccion'] ?? '') ?></div>
        <div class="completo">
            <h3 class="separado">Equipo a cargo</h3>
            <p class="tenue">Marque a quienes también atienden a este cliente y, si quiere, en qué área (p. ej. Remuneraciones, Contabilidad, Trámites SII).</p>
            <?php $equipoActual = $id ? array_column(q_todos('SELECT usuario_id, area FROM cliente_ejecutivos WHERE cliente_id = ?', [$id]), 'area', 'usuario_id') : []; ?>
            <table class="tabla-equipo"><tbody>
            <?php foreach (opciones_usuarios() as $uid => $nombreU): ?>
                <tr>
                    <td><label class="check"><input type="checkbox" name="equipo[]" value="<?= (int)$uid ?>" <?= array_key_exists($uid, $equipoActual) ? 'checked' : '' ?>> <?= e($nombreU) ?></label></td>
                    <td><input type="text" name="area[<?= (int)$uid ?>]" value="<?= e($equipoActual[$uid] ?? '') ?>" maxlength="60" placeholder="Área (opcional)" list="areas-equipo" aria-label="Área de <?= e($nombreU) ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <datalist id="areas-equipo"><?php foreach (['Tributario', 'Contabilidad', 'Remuneraciones', 'Trámites SII', 'Facturación', 'Legal'] as $ar): ?><option value="<?= e($ar) ?>"><?php endforeach; ?></datalist>
        </div>
        <?php if (!$id): ?>
        <div class="completo"><h3 class="separado">Honorario (opcional)</h3>
            <p class="tenue">Crea el primer plan de cobro. Después puede agregar otros (p. ej. la renta anual) desde la ficha del cliente.</p></div>
        <div><?= campo('honorario_monto', 'Monto neto por cobro', '', 'text', 'inputmode="decimal" placeholder="Ej.: 3,5"') ?></div>
        <div><?= selector('honorario_moneda', 'Moneda', ['UF' => 'UF', 'CLP' => 'Pesos'], 'UF', false) ?></div>
        <div><?= selector('periodicidad', 'Se cobra', PERIODICIDADES, 'mensual', false) ?></div>
        <div><?= selector('mes_inicio', 'Mes (anual) o primer mes del ciclo', array_map('ucfirst', MESES), 1, false) ?></div>
        <div><?= selector('documento_tipo', 'Documento que se emite', TIPOS_DOCUMENTO_VENTA, 'factura_afecta', false) ?></div>
        <?php endif; ?>
        <div class="completo"><?= area('notas', 'Notas', $c['notas'] ?? '') ?></div>
        <div class="completo">
            <label class="check"><input type="checkbox" name="activo" value="1" <?= $c['activo'] ? 'checked' : '' ?>> Cliente activo</label>
            <?php if (!$id): ?>
            <label class="check"><input type="checkbox" name="crear_empresa" value="1" checked> Crear también la ficha de este RUT en Empresas</label>
            <?php endif; ?>
        </div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e($id ? url('clientes', ['a' => 'ver', 'id' => $id]) : url('clientes')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Ficha ---------- */
if ($accion === 'ver' && $id) {
    $c = q_uno('SELECT c.*, u.nombre AS ejecutivo FROM clientes c LEFT JOIN usuarios u ON u.id = c.ejecutivo_id WHERE c.id = ?', [$id]);
    if (!$c) {
        redirigir(url('clientes'));
    }
    $empresas = q_todos(
        'SELECT e.*, (SELECT COUNT(*) FROM socios s WHERE s.empresa_id = e.id) AS n_socios
         FROM empresas e WHERE e.cliente_id = ? ORDER BY e.nombre', [$id]);
    $tareas = q_todos(
        "SELECT t.*, e.nombre AS empresa, u.nombre AS responsable FROM tareas t
         LEFT JOIN empresas e ON e.id = t.empresa_id LEFT JOIN usuarios u ON u.id = t.responsable_id
         WHERE t.cliente_id = ? AND t.estado <> 'completada' ORDER BY t.vencimiento IS NULL, t.vencimiento", [$id]);
    $gestiones = q_todos(
        'SELECT a.*, u.nombre AS usuario FROM actividades a LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.cliente_id = ? OR a.empresa_id IN (SELECT id FROM empresas WHERE cliente_id = ?)
         ORDER BY a.fecha DESC LIMIT 15', [$id, $id]);
    $facturas = q_todos('SELECT * FROM facturas WHERE cliente_id = ? ORDER BY fecha_emision DESC, id DESC LIMIT 8', [$id]);
    $porCobrar = (float)q_valor("SELECT COALESCE(SUM(total), 0) FROM facturas WHERE cliente_id = ? AND estado = 'emitida'", [$id]);
    $cobros = q_todos('SELECT * FROM cobros WHERE cliente_id = ? ORDER BY activo DESC, periodicidad, concepto', [$id]);
    $documentos = q_todos(
        'SELECT d.*, e.nombre AS empresa FROM documentos d LEFT JOIN empresas e ON e.id = d.empresa_id
         WHERE d.cliente_id = ? ORDER BY d.creado_en DESC LIMIT 8', [$id]);
    $credenciales = puede_ver_credenciales() ? q_todos(
        'SELECT k.*, e.nombre AS empresa FROM credenciales k LEFT JOIN empresas e ON e.id = k.empresa_id
         WHERE k.cliente_id = ? ORDER BY k.institucion', [$id]) : [];
    $contactos = q_todos(
        'SELECT ct.*, e.nombre AS empresa FROM contactos ct JOIN empresas e ON e.id = ct.empresa_id
         WHERE e.cliente_id = ? ORDER BY ct.nombre', [$id]);

    layout_inicio($c['nombre'], 'clientes');
    ?>
    <div class="encabezado">
        <h1><?= e($c['nombre']) ?> <?php if (!$c['activo']): ?><span class="badge">Inactivo</span><?php endif; ?></h1>
        <div>
            <a class="boton" href="<?= e(url('clientes', ['a' => 'form', 'id' => $id])) ?>">Editar</a>
            <?php if (es_admin()) echo boton_post(url('clientes', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro',
                '¿Eliminar el cliente? Se borrarán sus tareas, facturas, credenciales y documentos. Sus empresas se conservan.'); ?>
        </div>
    </div>

    <div class="columnas">
        <section class="panel">
            <h2>Datos</h2>
            <dl class="ficha">
                <dt>Tipo</dt><dd><?= e(TIPOS_CLIENTE[$c['tipo']] ?? $c['tipo']) ?></dd>
                <dt>Categoría</dt><dd><?php if ($c['categoria']): ?><a href="<?= e(url('clientes', ['categoria' => $c['categoria']])) ?>"><?= e($c['categoria']) ?></a><?php endif; ?></dd>
                <dt>Situación</dt><dd><?php if ($c['situacion']): ?><a href="<?= e(url('clientes', ['situacion' => $c['situacion']])) ?>"><?= e($c['situacion']) ?></a><?php endif; ?></dd>
                <dt>RUT</dt><dd><?= e($c['rut']) ?></dd>
                <dt>Equipo a cargo</dt><dd><?php foreach (equipo_cliente($id) as $m): ?>
                    <div><?= $m['principal'] ? '<strong>' . e($m['nombre']) . '</strong>' : e($m['nombre']) ?>
                        <small class="tenue"><?= e(implode(' · ', array_filter([$m['area'], $m['principal'] ? 'principal' : null]))) ?></small></div>
                    <?php endforeach; ?></dd>
                <dt>Correo</dt><dd><?php if ($c['email']): ?><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a><?php endif; ?></dd>
                <dt>Teléfono</dt><dd><?= e($c['telefono']) ?></dd>
                <dt>Dirección</dt><dd><?= e($c['direccion']) ?></dd>

                <dt>Por cobrar</dt><dd><?= $porCobrar > 0 ? '<strong>' . e(dinero($porCobrar)) . '</strong>' : '—' ?></dd>
            </dl>
            <?php if ($c['notas']): ?><p class="notas"><?= nl2br(e($c['notas'])) ?></p><?php endif; ?>
        </section>
        <section class="panel">
            <div class="encabezado"><h2>RUT / Empresas (<?= count($empresas) ?>)</h2>
                <a href="<?= e(url('empresas', ['a' => 'form', 'cliente_id' => $id])) ?>">+ Agregar RUT</a></div>
            <?php if (!$empresas): ?><p class="vacio">Este cliente aún no tiene RUT asociados.</p><?php else: ?>
            <table><tbody>
            <?php foreach ($empresas as $em): ?>
                <tr>
                    <td><a href="<?= e(url('empresas', ['a' => 'ver', 'id' => $em['id']])) ?>"><?= e($em['nombre']) ?></a>
                        <br><small class="tenue"><?= e($em['regimen'] ?? '') ?></small></td>
                    <td class="nowrap"><?= e($em['identificacion']) ?></td>
                    <td class="tenue nowrap"><?= (int)$em['n_socios'] ?> socio(s)</td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
            <?php if ($contactos): ?>
                <h3 class="separado">Contactos</h3>
                <ul class="lista-simple">
                <?php foreach ($contactos as $ct): ?>
                    <li><a href="<?= e(url('contactos', ['a' => 'ver', 'id' => $ct['id']])) ?>"><?= e(trim($ct['nombre'] . ' ' . $ct['apellido'])) ?></a>
                        <small class="tenue"><?= e($ct['cargo']) ?> · <?= e($ct['empresa']) ?></small></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php panel_tareas($tareas, ['cliente_id' => $id]); ?>
    <section class="panel" id="cobros">
        <div class="encabezado">
            <h2>Planes de cobro</h2>
            <a href="<?= e(url('facturas', ['a' => 'cobro_form', 'cliente_id' => $id])) ?>">+ Agregar cobro</a>
        </div>
        <?php if (!$cobros): ?>
            <p class="vacio">Sin planes de cobro. Agregue el honorario (mensual, trimestral…) y los cobros anuales como la renta.</p>
        <?php else: ?>
        <table>
            <thead><tr><th>Concepto</th><th class="derecha">Monto</th><th>Se cobra</th><th>Documento</th><th></th></tr></thead>
            <tbody>
            <?php $anual = ['UF' => 0.0, 'CLP' => 0.0];
            foreach ($cobros as $k):
                if ($k['activo']) { $anual[$k['moneda']] += $k['monto'] * cobro_veces_anio($k); } ?>
                <tr class="<?= $k['activo'] ? '' : 'hecha' ?>">
                    <td><?= e($k['concepto']) ?><?= $k['activo'] ? '' : ' <span class="badge">Inactivo</span>' ?></td>
                    <td class="derecha nowrap"><?= e(cobro_monto_texto($k)) ?></td>
                    <td><?= e(PERIODICIDADES[$k['periodicidad']] ?? '') ?> <small class="tenue">(<?= e(cobro_calendario($k)) ?>)</small></td>
                    <td><?= e(TIPOS_DOCUMENTO_VENTA[$k['documento_tipo']] ?? '') ?></td>
                    <td class="derecha nowrap">
                        <a class="boton chico secundario" href="<?= e(url('facturas', ['a' => 'form', 'cobro_id' => $k['id']])) ?>">Facturar ahora</a>
                        <a class="boton chico secundario" href="<?= e(url('facturas', ['a' => 'cobro_form', 'cobro_id' => $k['id']])) ?>">Editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="tenue">Ingreso anual estimado: <strong>UF <?= e(numero_corto($anual['UF'], 2)) ?></strong><?= $anual['CLP'] > 0 ? ' + <strong>' . e(dinero($anual['CLP'])) . '</strong>' : '' ?> (neto).</p>
        <?php endif; ?>
    </section>
    <div class="columnas">
        <?php panel_facturas($facturas, ['cliente_id' => $id]); ?>
        <?php panel_documentos($documentos, ['cliente_id' => $id]); ?>
    </div>
    <?php panel_credenciales($credenciales, ['cliente_id' => $id]); ?>
    <?php historial_actividades($gestiones, ['cliente_id' => $id]); ?>
    <?php
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
[$limite, $desde] = paginacion_limites();
$total = (int)q_valor("SELECT COUNT(*) FROM clientes c $where", $params);
$hoy = date('Y-m-d');
$clientes = q_todos(
    "SELECT c.*, u.nombre AS ejecutivo,
        (SELECT COUNT(*) FROM empresas e WHERE e.cliente_id = c.id) AS n_ruts,
        (SELECT COUNT(*) FROM tareas t WHERE t.cliente_id = c.id AND t.estado <> 'completada') AS n_tareas,
        (SELECT COUNT(*) FROM tareas t WHERE t.cliente_id = c.id AND t.estado <> 'completada' AND t.vencimiento < :hoy) AS n_vencidas
     FROM clientes c LEFT JOIN usuarios u ON u.id = c.ejecutivo_id
     $where ORDER BY c.nombre LIMIT $limite OFFSET $desde",
    $params + ['hoy' => $hoy]
);
$cobrosLista = [];
$equipoLista = [];
if ($clientes) {
    foreach (q_todos('SELECT ce.cliente_id, u.nombre FROM cliente_ejecutivos ce JOIN usuarios u ON u.id = ce.usuario_id
        WHERE ce.cliente_id IN (' . implode(',', array_map('intval', array_column($clientes, 'id'))) . ') ORDER BY u.nombre') as $m) {
        $equipoLista[$m['cliente_id']][] = $m['nombre'];
    }
    foreach (q_todos('SELECT * FROM cobros WHERE activo = 1 AND cliente_id IN (' . implode(',', array_map('intval', array_column($clientes, 'id'))) . ') ORDER BY periodicidad, concepto') as $k) {
        $cobrosLista[$k['cliente_id']][] = $k;
    }
}

layout_inicio('Clientes', 'clientes');
?>
<h1>Clientes</h1>
<?php barra_lista('clientes', '+ Nuevo cliente', true, [
    selector('estado', '', ['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'], $estado, false, 'aria-label="Estado"'),
    selector('categoria', '', array_combine(valores_cliente('categoria'), valores_cliente('categoria')), $categoria, 'Todas las categorías', 'aria-label="Categoría"'),
    selector('situacion', '', array_combine(valores_cliente('situacion'), valores_cliente('situacion')), $situacion, 'Todas las situaciones', 'aria-label="Situación"'),
    selector('ejecutivo_id', '', opciones_usuarios(), $ejecutivo ?? '', 'Todos los ejecutivos', 'aria-label="Ejecutivo"'),
]); ?>
<form method="post" action="<?= e(url('clientes', ['a' => 'masivo'])) ?>" id="form-masivo">
<?= csrf_campo() ?>
<input type="hidden" name="volver" value="<?= e('index.php?' . http_build_query($_GET)) ?>">
<table>
    <thead><tr><th class="casilla"><input type="checkbox" data-marcar-todos aria-label="Marcar todos"></th><th>Cliente</th><th>RUT</th><th>Tipo</th><th>Categoría</th><th>Tareas abiertas</th><th>Cobros</th><th>Equipo</th></tr></thead>
    <tbody>
    <?php foreach ($clientes as $c): ?>
        <tr class="<?= $c['activo'] ? '' : 'hecha' ?>">
            <td class="casilla"><input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" aria-label="Marcar <?= e($c['nombre']) ?>"></td>
            <td><a href="<?= e(url('clientes', ['a' => 'ver', 'id' => $c['id']])) ?>"><?= e($c['nombre']) ?></a></td>
            <td class="nowrap"><?= e($c['rut']) ?></td>
            <td><?= e(TIPOS_CLIENTE[$c['tipo']] ?? $c['tipo']) ?></td>
            <td><?= e($c['categoria']) ?><?php if ($c['situacion']): ?><br><small class="tenue"><?= e($c['situacion']) ?></small><?php endif; ?></td>
            <td><?= (int)$c['n_tareas'] ?><?php if ($c['n_vencidas'] > 0): ?> <span class="badge tarea-vencida"><?= (int)$c['n_vencidas'] ?> vencida(s)</span><?php endif; ?></td>
            <td><?php foreach ($cobrosLista[$c['id']] ?? [] as $k): ?><div class="nowrap"><?= e(cobro_monto_texto($k)) ?> <small class="tenue"><?= e(mb_strtolower(PERIODICIDADES[$k['periodicidad']] ?? '')) ?><?= $k['periodicidad'] === 'anual' ? ' · ' . e(MESES[(int)$k['mes_inicio']]) : '' ?></small></div><?php endforeach; ?></td>
            <td><?= e($c['ejecutivo']) ?><?php $otros = array_diff($equipoLista[$c['id']] ?? [], [$c['ejecutivo']]);
                if ($otros): ?><br><small class="tenue">+ <?= e(implode(', ', $otros)) ?></small><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$clientes): ?><tr><td colspan="8" class="vacio">No se encontraron clientes.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php if ($clientes): ?>
<section class="panel acciones-masivas">
    <h2>Cambiar varios a la vez</h2>
    <div class="fila-formulario formulario">
        <div><?= selector_etiqueta('m_categoria', 'Categoría', valores_cliente('categoria'), '__igual__', ['__igual__' => '— No cambiar —', '' => '— Quitar categoría —']) ?></div>
        <div><?= selector_etiqueta('m_situacion', 'Situación', valores_cliente('situacion'), '__igual__', ['__igual__' => '— No cambiar —', '' => '— Quitar situación —']) ?></div>
        <div><?= selector('m_ejecutivo_id', 'Ejecutivo', opciones_usuarios(), '', '— No cambiar —') ?></div>
        <div><?= selector('m_activo', 'Estado', ['1' => 'Activo', '0' => 'Inactivo'], '', '— No cambiar —') ?></div>
    </div>
    <div class="formulario">
        <label class="check"><input type="radio" name="todos_filtro" value="0" checked> Solo los clientes marcados en esta página</label>
        <label class="check"><input type="radio" name="todos_filtro" value="1"> Todos los <?= $total ?> clientes del filtro actual</label>
    </div>
    <div class="acciones"><button type="submit" onclick="return confirm('¿Aplicar los cambios a los clientes elegidos?')">Aplicar</button></div>
</section>
<?php endif; ?>
</form>
<?php
layout_fin();
