<?php
declare(strict_types=1);

$id = entrada_int('id');

const REGIMENES = [
    'Pro Pyme General (14 D N°3)',
    'Pro Pyme Transparente (14 D N°8)',
    'Régimen General (14 A)',
    'Renta presunta',
    'Primera categoría sin fines de lucro',
    'Segunda categoría',
    'Exento',
];

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    [$rut, $errorRut] = rut_entrada('identificacion');
    $datos = [
        'nombre'             => entrada('nombre'),
        'identificacion'     => $rut,
        'cliente_id'         => entrada_int('cliente_id'),
        'regimen'            => nulo_si_vacio(entrada('regimen')),
        'inicio_actividades' => entrada_fecha('inicio_actividades'),
        'sector'             => nulo_si_vacio(entrada('sector')),
        'telefono'           => nulo_si_vacio(entrada('telefono')),
        'email'              => nulo_si_vacio(entrada('email')),
        'sitio_web'          => nulo_si_vacio(entrada('sitio_web')),
        'direccion'          => nulo_si_vacio(entrada('direccion')),
        'ciudad'             => nulo_si_vacio(entrada('ciudad')),
        'notas'              => nulo_si_vacio(entrada('notas')),
        'responsable_id'     => entrada_int('responsable_id'),
        'actualizado_en'     => ahora(),
    ];
    if ($datos['nombre'] === '' || $errorRut) {
        flash('error', $errorRut ?: 'La razón social es obligatoria.');
        redirigir(url('empresas', ['a' => 'form', 'id' => $id, 'cliente_id' => $datos['cliente_id']]));
    }
    if ($rut && q_valor('SELECT id FROM empresas WHERE identificacion = ? AND id <> ?', [$rut, $id ?? 0])) {
        flash('error', "Ya existe una empresa con el RUT $rut.");
        redirigir(url('empresas', ['a' => 'form', 'id' => $id, 'cliente_id' => $datos['cliente_id']]));
    }
    if ($id) {
        actualizar('empresas', $id, $datos);
        flash('ok', 'Empresa actualizada.');
    } else {
        $datos['creado_en'] = ahora();
        $id = insertar('empresas', $datos);
        flash('ok', 'Empresa creada.');
    }
    redirigir(url('empresas', ['a' => 'ver', 'id' => $id]));
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $empresa = q_uno('SELECT * FROM empresas WHERE id = ?', [$id]);
    if (!puede_eliminar($empresa)) {
        flash('error', 'Solo el responsable o un administrador puede eliminar esta empresa.');
        redirigir(url('empresas', ['a' => 'ver', 'id' => $id]));
    }
    q('DELETE FROM empresas WHERE id = ?', [$id]);
    flash('ok', 'Empresa eliminada.');
    redirigir($empresa && $empresa['cliente_id'] ? url('clientes', ['a' => 'ver', 'id' => $empresa['cliente_id']]) : url('empresas'));
}

/* ---------- Socios ---------- */
if ($accion === 'socio_guardar' && es_post() && $id) {
    $socioId = entrada_int('socio_id');
    [$rutSocio, $errorRut] = rut_entrada('rut');
    $socioEmpresa = entrada_int('socio_empresa_id');
    $nombre = entrada('nombre');
    // Si el socio es una empresa registrada en el CRM, se toman su nombre y RUT.
    if ($socioEmpresa) {
        $se = q_uno('SELECT nombre, identificacion FROM empresas WHERE id = ?', [$socioEmpresa]);
        $nombre = $nombre !== '' ? $nombre : (string)($se['nombre'] ?? '');
        $rutSocio = $rutSocio ?? ($se['identificacion'] ?? null);
    }
    $porcentaje = entrada_decimal('porcentaje');
    $datos = [
        'empresa_id'       => $id,
        'nombre'           => $nombre,
        'rut'              => $rutSocio,
        'tipo'             => ($socioEmpresa || entrada('tipo') === 'empresa') ? 'empresa' : 'persona',
        'socio_empresa_id' => $socioEmpresa,
        'porcentaje'       => $porcentaje,
        'representante'    => entrada('representante') === '1' ? 1 : 0,
        'email'            => nulo_si_vacio(entrada('email')),
        'telefono'         => nulo_si_vacio(entrada('telefono')),
        'notas'            => nulo_si_vacio(entrada('notas')),
    ];
    if ($nombre === '' || $errorRut || ($porcentaje !== null && ($porcentaje < 0 || $porcentaje > 100))) {
        flash('error', $errorRut ?: ($nombre === '' ? 'Indique el nombre del socio o elija una empresa.' : 'El porcentaje debe estar entre 0 y 100.'));
    } elseif ($socioEmpresa === $id) {
        flash('error', 'Una empresa no puede ser socia de sí misma.');
    } elseif ($socioId) {
        unset($datos['empresa_id']);
        q('UPDATE socios SET ' . implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($datos))) . ' WHERE id = :id AND empresa_id = :emp',
            $datos + ['id' => $socioId, 'emp' => $id]);
        flash('ok', 'Socio actualizado.');
    } else {
        $datos['creado_en'] = ahora();
        insertar('socios', $datos);
        flash('ok', 'Socio agregado.');
    }
    redirigir(url('empresas', ['a' => 'ver', 'id' => $id]) . '#socios');
}

if ($accion === 'socio_eliminar' && es_post() && $id) {
    q('DELETE FROM socios WHERE id = ? AND empresa_id = ?', [entrada_int('socio_id'), $id]);
    flash('ok', 'Socio eliminado.');
    redirigir(url('empresas', ['a' => 'ver', 'id' => $id]) . '#socios');
}

/* ---------- Participaciones de un RUT (persona o empresa) en todas las empresas ---------- */
if ($accion === 'participaciones') {
    $rut = rut_valido(entrada('rut')) ? rut_formatear(entrada('rut')) : '';
    $filas = $rut ? q_todos(
        'SELECT s.*, e.nombre AS empresa, e.identificacion AS empresa_rut, e.id AS eid, c.nombre AS cliente, c.id AS cid
         FROM socios s JOIN empresas e ON e.id = s.empresa_id LEFT JOIN clientes c ON c.id = e.cliente_id
         WHERE s.rut = ? ORDER BY e.nombre', [$rut]) : [];
    $comoEmpresa = $rut ? q_uno('SELECT id, nombre FROM empresas WHERE identificacion = ?', [$rut]) : null;
    layout_inicio('Participaciones', 'empresas');
    ?>
    <h1>Participaciones de <?= e($filas[0]['nombre'] ?? ($comoEmpresa['nombre'] ?? $rut)) ?> <small class="tenue"><?= e($rut) ?></small></h1>
    <?php if ($comoEmpresa): ?><p>Este RUT también está registrado como empresa: <?= enlace('empresas', $comoEmpresa['id'], $comoEmpresa['nombre']) ?>.</p><?php endif; ?>
    <table>
        <thead><tr><th>Empresa</th><th>RUT empresa</th><th>Cliente</th><th class="derecha">Participación</th><th>Representante</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
            <tr>
                <td><?= enlace('empresas', $f['eid'], $f['empresa']) ?></td>
                <td class="nowrap"><?= e($f['empresa_rut']) ?></td>
                <td><?= enlace('clientes', $f['cid'], $f['cliente']) ?></td>
                <td class="derecha"><?= $f['porcentaje'] !== null ? e(numero_corto($f['porcentaje'])) . ' %' : '' ?></td>
                <td><?= $f['representante'] ? 'Sí' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?><tr><td colspan="5" class="vacio">No se encontraron participaciones para ese RUT.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php
    layout_fin();
    return;
}

/* ---------- Filtro común para lista y CSV ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['e.nombre', 'e.identificacion', 'e.ciudad', 'e.sector', 'e.email', 'c.nombre'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
$filtroCliente = entrada_int('cliente_id');
if ($filtroCliente) {
    $condiciones[] = 'e.cliente_id = :cliente';
    $params['cliente'] = $filtroCliente;
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

if ($accion === 'csv') {
    $filas = q_todos(
        "SELECT e.nombre, e.identificacion, c.nombre AS cliente, e.regimen, e.inicio_actividades, e.sector, e.telefono, e.email,
            e.direccion, e.ciudad, u.nombre AS responsable
         FROM empresas e LEFT JOIN clientes c ON c.id = e.cliente_id LEFT JOIN usuarios u ON u.id = e.responsable_id
         $where ORDER BY e.nombre",
        $params
    );
    exportar_csv('empresas_' . date('Ymd') . '.csv',
        ['Razón social', 'RUT', 'Cliente', 'Régimen', 'Inicio de actividades', 'Giro', 'Teléfono', 'Correo', 'Dirección', 'Comuna', 'Responsable'],
        array_map('array_values', $filas));
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $e = $id ? q_uno('SELECT * FROM empresas WHERE id = ?', [$id])
        : ['responsable_id' => usuario_actual()['id'], 'cliente_id' => entrada_int('cliente_id')];
    if ($id && !$e) {
        redirigir(url('empresas'));
    }
    layout_inicio($id ? 'Editar empresa' : 'Nueva empresa', 'empresas');
    ?>
    <h1><?= $id ? 'Editar empresa' : 'Nueva empresa / RUT' ?></h1>
    <form method="post" action="<?= e(url('empresas', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div><?= campo('nombre', 'Razón social / Nombre *', $e['nombre'] ?? '', 'text', 'required maxlength="150"') ?></div>
        <div><?= campo('identificacion', 'RUT', $e['identificacion'] ?? '', 'text', 'placeholder="76.123.456-7"') ?></div>
        <div><?= selector('cliente_id', 'Cliente al que pertenece', opciones_clientes(false), $e['cliente_id'] ?? '', 'Sin cliente') ?></div>
        <div><?= selector('responsable_id', 'Responsable', opciones_usuarios(), $e['responsable_id'] ?? '') ?></div>
        <div>
            <?= campo('regimen', 'Régimen tributario', $e['regimen'] ?? '', 'text', 'list="regimenes"') ?>
            <datalist id="regimenes"><?php foreach (REGIMENES as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?></datalist>
        </div>
        <div><?= campo('inicio_actividades', 'Inicio de actividades', $e['inicio_actividades'] ?? '', 'date') ?></div>
        <div><?= campo('sector', 'Giro', $e['sector'] ?? '') ?></div>
        <div><?= campo('telefono', 'Teléfono', $e['telefono'] ?? '', 'tel') ?></div>
        <div><?= campo('email', 'Correo', $e['email'] ?? '', 'email') ?></div>
        <div><?= campo('sitio_web', 'Sitio web', $e['sitio_web'] ?? '', 'url', 'placeholder="https://"') ?></div>
        <div><?= campo('direccion', 'Dirección', $e['direccion'] ?? '') ?></div>
        <div><?= campo('ciudad', 'Comuna', $e['ciudad'] ?? '') ?></div>
        <div class="completo"><?= area('notas', 'Notas', $e['notas'] ?? '') ?></div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e($id ? url('empresas', ['a' => 'ver', 'id' => $id]) : url('empresas')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Ficha ---------- */
if ($accion === 'ver' && $id) {
    $e = q_uno(
        'SELECT e.*, u.nombre AS responsable, c.nombre AS cliente FROM empresas e
         LEFT JOIN usuarios u ON u.id = e.responsable_id LEFT JOIN clientes c ON c.id = e.cliente_id
         WHERE e.id = ?', [$id]);
    if (!$e) {
        redirigir(url('empresas'));
    }
    $socios = q_todos('SELECT * FROM socios WHERE empresa_id = ? ORDER BY porcentaje DESC, nombre', [$id]);
    $sumaPct = array_sum(array_map(static fn($s) => (float)$s['porcentaje'], $socios));
    // Otras participaciones de cada socio (por RUT)
    $otras = [];
    foreach ($socios as $s) {
        if ($s['rut']) {
            $otras[$s['id']] = (int)q_valor('SELECT COUNT(*) FROM socios WHERE rut = ? AND empresa_id <> ?', [$s['rut'], $id]);
        }
    }
    $participa = $e['identificacion'] ? q_todos(
        'SELECT s.porcentaje, e2.id, e2.nombre, e2.identificacion FROM socios s JOIN empresas e2 ON e2.id = s.empresa_id
         WHERE s.socio_empresa_id = ? OR s.rut = ? ORDER BY e2.nombre', [$id, $e['identificacion']]) : [];
    $contactos = q_todos('SELECT * FROM contactos WHERE empresa_id = ? ORDER BY nombre', [$id]);
    $oportunidades = q_todos('SELECT * FROM oportunidades WHERE empresa_id = ? ORDER BY actualizado_en DESC', [$id]);
    $tareas = q_todos(
        "SELECT t.*, u.nombre AS responsable FROM tareas t LEFT JOIN usuarios u ON u.id = t.responsable_id
         WHERE t.empresa_id = ? AND t.estado <> 'completada' ORDER BY t.vencimiento IS NULL, t.vencimiento", [$id]);
    $facturas = q_todos('SELECT * FROM facturas WHERE empresa_id = ? ORDER BY fecha_emision DESC LIMIT 8', [$id]);
    $documentos = q_todos('SELECT * FROM documentos WHERE empresa_id = ? ORDER BY creado_en DESC LIMIT 8', [$id]);
    $credenciales = puede_ver_credenciales() ? q_todos('SELECT * FROM credenciales WHERE empresa_id = ? ORDER BY institucion', [$id]) : [];
    $actividades = q_todos(
        'SELECT a.*, u.nombre AS usuario FROM actividades a LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.empresa_id = ? ORDER BY a.fecha DESC LIMIT 30', [$id]);
    $vinculo = ['empresa_id' => $id] + ($e['cliente_id'] ? ['cliente_id' => $e['cliente_id']] : []);
    $editarSocio = entrada_int('socio_id') ? q_uno('SELECT * FROM socios WHERE id = ? AND empresa_id = ?', [entrada_int('socio_id'), $id]) : null;

    layout_inicio($e['nombre'], 'empresas');
    ?>
    <div class="encabezado">
        <div class="titulo">
            <h1><?= e($e['nombre']) ?></h1>
            <?php if ($e['cliente']): ?><p class="tenue">Cliente: <?= enlace('clientes', $e['cliente_id'], $e['cliente']) ?></p><?php endif; ?>
        </div>
        <div>
            <a class="boton" href="<?= e(url('empresas', ['a' => 'form', 'id' => $id])) ?>">Editar</a>
            <?php if (puede_eliminar($e)) echo boton_post(url('empresas', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar la empresa? Se borrarán sus socios y credenciales; tareas, contactos y documentos se conservarán sin empresa.'); ?>
        </div>
    </div>
    <div class="columnas">
        <section class="panel">
            <h2>Datos</h2>
            <dl class="ficha">
                <dt>RUT</dt><dd><?= e($e['identificacion']) ?></dd>
                <dt>Régimen</dt><dd><?= e($e['regimen']) ?></dd>
                <dt>Inicio actividades</dt><dd><?= e(fecha($e['inicio_actividades'])) ?></dd>
                <dt>Giro</dt><dd><?= e($e['sector']) ?></dd>
                <dt>Teléfono</dt><dd><?= e($e['telefono']) ?></dd>
                <dt>Correo</dt><dd><?php if ($e['email']): ?><a href="mailto:<?= e($e['email']) ?>"><?= e($e['email']) ?></a><?php endif; ?></dd>
                <dt>Sitio web</dt><dd><?php if ($e['sitio_web'] && preg_match('#^https?://#i', $e['sitio_web'])): ?><a href="<?= e($e['sitio_web']) ?>" target="_blank" rel="noopener"><?= e($e['sitio_web']) ?></a><?php else: ?><?= e($e['sitio_web']) ?><?php endif; ?></dd>
                <dt>Dirección</dt><dd><?= e(trim(($e['direccion'] ?? '') . ', ' . ($e['ciudad'] ?? ''), ', ')) ?></dd>
                <dt>Responsable</dt><dd><?= e($e['responsable']) ?></dd>
            </dl>
            <?php if ($e['notas']): ?><p class="notas"><?= nl2br(e($e['notas'])) ?></p><?php endif; ?>
            <?php if ($participa): ?>
                <h3 class="separado">Es socia de</h3>
                <ul class="lista-simple">
                <?php foreach ($participa as $p): ?>
                    <li><?= enlace('empresas', $p['id'], $p['nombre']) ?> <small class="tenue"><?= e($p['identificacion']) ?><?= $p['porcentaje'] !== null ? ' · ' . e(numero_corto($p['porcentaje'])) . ' %' : '' ?></small></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="panel">
            <div class="encabezado"><h2>Contactos</h2><a href="<?= e(url('contactos', ['a' => 'form', 'empresa_id' => $id])) ?>">+ Agregar</a></div>
            <?php if (!$contactos): ?><p class="vacio">Sin contactos.</p><?php else: ?>
            <table><tbody>
            <?php foreach ($contactos as $c): ?>
                <tr>
                    <td><a href="<?= e(url('contactos', ['a' => 'ver', 'id' => $c['id']])) ?>"><?= e($c['nombre'] . ' ' . $c['apellido']) ?></a><br><small class="tenue"><?= e($c['cargo']) ?></small></td>
                    <td><?= e($c['email']) ?><br><small><?= e($c['telefono'] ?: $c['movil']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel" id="socios">
        <div class="encabezado">
            <h2>Socios</h2>
            <?php if ($socios): ?><span class="tenue">Total participación: <strong class="<?= abs($sumaPct - 100) > 0.01 ? 'texto-aviso' : '' ?>"><?= e(numero_corto($sumaPct, 2)) ?> %</strong></span><?php endif; ?>
        </div>
        <?php if ($socios): ?>
        <table>
            <thead><tr><th>Socio</th><th>RUT</th><th class="derecha">%</th><th>Contacto</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($socios as $s): ?>
                <tr>
                    <td><?= $s['socio_empresa_id'] ? enlace('empresas', $s['socio_empresa_id'], $s['nombre']) : e($s['nombre']) ?>
                        <?php if ($s['tipo'] === 'empresa'): ?><span class="badge">Empresa</span><?php endif; ?>
                        <?php if ($s['representante']): ?><span class="badge tarea-en_proceso">Rep. legal</span><?php endif; ?>
                        <?php if (!empty($otras[$s['id']])): ?><br><a class="chico-texto" href="<?= e(url('empresas', ['a' => 'participaciones', 'rut' => $s['rut']])) ?>">Participa en <?= $otras[$s['id']] ?> empresa(s) más →</a><?php endif; ?></td>
                    <td class="nowrap"><?= e($s['rut']) ?></td>
                    <td class="derecha"><?= $s['porcentaje'] !== null ? e(numero_corto($s['porcentaje'])) : '' ?></td>
                    <td><?= e($s['email']) ?><?= $s['email'] && $s['telefono'] ? '<br>' : '' ?><small><?= e($s['telefono']) ?></small></td>
                    <td class="derecha nowrap">
                        <a class="boton chico secundario" href="<?= e(url('empresas', ['a' => 'ver', 'id' => $id, 'socio_id' => $s['id']])) ?>#form-socio">Editar</a>
                        <form method="post" action="<?= e(url('empresas', ['a' => 'socio_eliminar', 'id' => $id])) ?>" class="en-linea" onsubmit="return confirm('¿Quitar este socio?')">
                            <?= csrf_campo() ?><input type="hidden" name="socio_id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" class="chico peligro">Quitar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><p class="vacio">Sin socios registrados.</p><?php endif; ?>

        <details class="subform" id="form-socio" <?= $editarSocio ? 'open' : '' ?>>
            <summary><?= $editarSocio ? 'Editar socio' : '+ Agregar socio' ?></summary>
            <form method="post" action="<?= e(url('empresas', ['a' => 'socio_guardar', 'id' => $id])) ?>" class="formulario rejilla sin-marco">
                <?= csrf_campo() ?>
                <?php if ($editarSocio): ?><input type="hidden" name="socio_id" value="<?= (int)$editarSocio['id'] ?>"><?php endif; ?>
                <div><?= selector('socio_empresa_id', 'Empresa registrada (si el socio es una empresa)', array_diff_key(opciones_empresas(), [$id => '']), $editarSocio['socio_empresa_id'] ?? '', 'No, es una persona o empresa externa') ?></div>
                <div><?= campo('nombre', 'Nombre o razón social', $editarSocio['nombre'] ?? '', 'text', 'maxlength="150"') ?></div>
                <div><?= campo('rut', 'RUT', $editarSocio['rut'] ?? '', 'text', 'placeholder="12.345.678-9"') ?></div>
                <div><?= campo('porcentaje', 'Participación %', isset($editarSocio['porcentaje']) ? numero_corto($editarSocio['porcentaje']) : '', 'text', 'inputmode="decimal" placeholder="Ej.: 50"') ?></div>
                <div><?= campo('email', 'Correo', $editarSocio['email'] ?? '', 'email') ?></div>
                <div><?= campo('telefono', 'Teléfono', $editarSocio['telefono'] ?? '', 'tel') ?></div>
                <div class="completo">
                    <label class="check"><input type="checkbox" name="representante" value="1" <?= !empty($editarSocio['representante']) ? 'checked' : '' ?>> Representante legal</label>
                    <label class="check"><input type="checkbox" name="tipo" value="empresa" <?= ($editarSocio['tipo'] ?? '') === 'empresa' ? 'checked' : '' ?>> El socio es una persona jurídica</label>
                </div>
                <div class="completo acciones">
                    <button type="submit"><?= $editarSocio ? 'Guardar cambios' : 'Agregar socio' ?></button>
                    <?php if ($editarSocio): ?><a class="boton secundario" href="<?= e(url('empresas', ['a' => 'ver', 'id' => $id])) ?>#socios">Cancelar</a><?php endif; ?>
                </div>
            </form>
        </details>
    </section>

    <?php panel_tareas($tareas, $vinculo); ?>
    <div class="columnas">
        <?php panel_facturas($facturas, $vinculo); ?>
        <?php panel_documentos($documentos, $vinculo); ?>
    </div>
    <?php panel_credenciales($credenciales, $vinculo); ?>

    <?php if ($oportunidades): ?>
    <section class="panel">
        <div class="encabezado"><h2>Oportunidades</h2><a href="<?= e(url('oportunidades', ['a' => 'form', 'empresa_id' => $id])) ?>">+ Agregar</a></div>
        <table>
            <thead><tr><th>Título</th><th>Etapa</th><th>Cierre estimado</th><th class="derecha">Monto</th></tr></thead>
            <tbody>
            <?php foreach ($oportunidades as $o): ?>
                <tr>
                    <td><a href="<?= e(url('oportunidades', ['a' => 'ver', 'id' => $o['id']])) ?>"><?= e($o['titulo']) ?></a></td>
                    <td><?= badge_etapa($o['etapa']) ?></td>
                    <td><?= e(fecha($o['fecha_cierre'])) ?></td>
                    <td class="derecha"><?= e(dinero($o['monto'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>
    <?php
    historial_actividades($actividades, $vinculo);
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
[$limite, $desde] = paginacion_limites();
$desdeTabla = 'FROM empresas e LEFT JOIN clientes c ON c.id = e.cliente_id LEFT JOIN usuarios u ON u.id = e.responsable_id';
$total = (int)q_valor("SELECT COUNT(*) $desdeTabla $where", $params);
$empresas = q_todos(
    "SELECT e.*, u.nombre AS responsable, c.nombre AS cliente,
        (SELECT COUNT(*) FROM socios s WHERE s.empresa_id = e.id) AS n_socios
     $desdeTabla $where ORDER BY e.nombre LIMIT $limite OFFSET $desde",
    $params
);

layout_inicio('Empresas', 'empresas');
?>
<h1>Empresas / RUT</h1>
<?php barra_lista('empresas', '+ Nueva empresa', true, [
    selector('cliente_id', '', opciones_clientes(false), $filtroCliente ?? '', 'Todos los clientes', 'aria-label="Cliente"'),
]); ?>
<form class="barra-lista" method="get">
    <input type="hidden" name="r" value="empresas"><input type="hidden" name="a" value="participaciones">
    <input type="search" name="rut" placeholder="Buscar participaciones de un RUT…" aria-label="RUT del socio">
    <button type="submit" class="secundario">Ver participaciones</button>
</form>
<table>
    <thead><tr><th>Razón social</th><th>RUT</th><th>Cliente</th><th>Régimen</th><th>Socios</th><th>Responsable</th></tr></thead>
    <tbody>
    <?php foreach ($empresas as $e): ?>
        <tr>
            <td><a href="<?= e(url('empresas', ['a' => 'ver', 'id' => $e['id']])) ?>"><?= e($e['nombre']) ?></a></td>
            <td class="nowrap"><?= e($e['identificacion']) ?></td>
            <td><?= enlace('clientes', $e['cliente_id'], $e['cliente']) ?></td>
            <td><?= e($e['regimen']) ?></td>
            <td><?= (int)$e['n_socios'] ?></td>
            <td><?= e($e['responsable']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$empresas): ?><tr><td colspan="6" class="vacio">No se encontraron empresas.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
