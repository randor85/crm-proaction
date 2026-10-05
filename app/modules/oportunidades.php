<?php
declare(strict_types=1);

$id = entrada_int('id');

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $etapa = entrada('etapa');
    $tarifaTipo = entrada('tarifa_tipo');
    $mes = entrada_int('tarifa_mes');
    $datos = [
        'titulo'         => entrada('titulo'),
        'empresa_id'     => entrada_int('empresa_id'),
        'contacto_id'    => entrada_int('contacto_id'),
        'servicio'       => nulo_si_vacio(mb_substr(entrada('servicio'), 0, 150)),
        'tarifa_tipo'    => isset(TARIFAS[$tarifaTipo]) ? $tarifaTipo : 'mensual',
        'tarifa_moneda'  => entrada('tarifa_moneda') === 'CLP' ? 'CLP' : 'UF',
        'monto'          => max(0, (float)(entrada_decimal('monto') ?? 0)),
        'tarifa_mes'     => $mes >= 1 && $mes <= 12 ? $mes : null,
        'etapa'          => isset(ETAPAS[$etapa]) ? $etapa : 'prospecto',
        'probabilidad'   => min(100, max(0, entrada_int('probabilidad') ?? 0)),
        'fecha_cierre'   => entrada_fecha('fecha_cierre'),
        'responsable_id' => entrada_int('responsable_id'),
        'notas'          => nulo_si_vacio(entrada('notas')),
        'actualizado_en' => ahora(),
    ];
    $volverForm = url('oportunidades', ['a' => 'form', 'id' => $id]);

    // RUT nuevo escrito en el formulario (si no se eligió uno existente)
    $nuevoRutNombre = trim(entrada('nuevo_rut_nombre'));
    [$nuevoRut, $errorRut] = rut_entrada('nuevo_rut');
    if ($errorRut) {
        flash('error', $errorRut);
        redirigir($volverForm);
    }
    if (!$datos['empresa_id'] && ($nuevoRutNombre !== '' || $nuevoRut)) {
        $existente = $nuevoRut ? q_valor('SELECT id FROM empresas WHERE identificacion = ?', [$nuevoRut]) : null;
        $datos['empresa_id'] = $existente ? (int)$existente : insertar('empresas', [
            'nombre' => mb_substr($nuevoRutNombre ?: ($datos['titulo'] ?: $nuevoRut), 0, 150), 'identificacion' => $nuevoRut,
            'responsable_id' => $datos['responsable_id'], 'creado_en' => ahora(), 'actualizado_en' => ahora(),
        ]);
    }
    if ($datos['titulo'] === '' && $datos['empresa_id']) {
        $datos['titulo'] = (string)q_valor('SELECT nombre FROM empresas WHERE id = ?', [$datos['empresa_id']]);
    }
    if ($datos['titulo'] === '') {
        flash('error', 'Indique un título o el RUT del prospecto.');
        redirigir($volverForm);
    }

    // Contacto nuevo escrito en el formulario
    $nuevoContacto = trim(entrada('nuevo_contacto_nombre'));
    if ($nuevoContacto !== '') {
        $datos['contacto_id'] = insertar('contactos', [
            'nombre' => mb_substr($nuevoContacto, 0, 100), 'cargo' => nulo_si_vacio(entrada('nuevo_contacto_rol')),
            'email' => nulo_si_vacio(entrada('nuevo_contacto_email')), 'telefono' => nulo_si_vacio(entrada('nuevo_contacto_telefono')),
            'responsable_id' => $datos['responsable_id'], 'creado_en' => ahora(), 'actualizado_en' => ahora(),
        ]);
    }
    if ($datos['contacto_id'] && $datos['empresa_id']) {
        vincular_contacto((int)$datos['contacto_id'], (int)$datos['empresa_id'], nulo_si_vacio(entrada('nuevo_contacto_rol')) ?? 'Contacto');
    }

    if ($id) {
        actualizar('oportunidades', $id, $datos);
        flash('ok', 'Oportunidad actualizada.');
    } else {
        $datos['creado_en'] = ahora();
        $id = insertar('oportunidades', $datos);
        flash('ok', 'Oportunidad creada.');
    }
    redirigir(url('oportunidades', ['a' => 'ver', 'id' => $id]));
}

/* ---------- Cambio rápido de etapa (desde el tablero o la ficha) ---------- */
if ($accion === 'etapa' && es_post() && $id) {
    $etapa = entrada('etapa');
    if (isset(ETAPAS[$etapa])) {
        q('UPDATE oportunidades SET etapa = ?, actualizado_en = ? WHERE id = ?', [$etapa, ahora(), $id]);
        flash('ok', 'Etapa actualizada a "' . ETAPAS[$etapa] . '".'
            . ($etapa === 'ganada' && !q_valor('SELECT cliente_id FROM oportunidades WHERE id = ?', [$id]) ? ' Ya puede convertirla en cliente desde su ficha.' : ''));
    }
    redirigir(entrada('volver') === 'ver' ? url('oportunidades', ['a' => 'ver', 'id' => $id]) : url('oportunidades'));
}

/* ---------- Ganada → cliente ---------- */
if ($accion === 'convertir' && es_post() && $id) {
    [$clienteId, $error] = convertir_en_cliente($id);
    if ($clienteId && !$error) {
        flash('ok', 'Prospecto convertido en cliente, con su RUT, tareas, gestiones y plan de cobro.');
        redirigir(url('clientes', ['a' => 'ver', 'id' => $clienteId]));
    }
    flash('error', $error ?? 'No se pudo convertir.');
    redirigir(url('oportunidades', ['a' => 'ver', 'id' => $id]));
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $op = q_uno('SELECT * FROM oportunidades WHERE id = ?', [$id]);
    if (!puede_eliminar($op)) {
        flash('error', 'Solo el responsable o un administrador puede eliminar esta oportunidad.');
        redirigir(url('oportunidades', ['a' => 'ver', 'id' => $id]));
    }
    q('UPDATE tareas SET oportunidad_id = NULL WHERE oportunidad_id = ?', [$id]);
    q('DELETE FROM oportunidades WHERE id = ?', [$id]);
    flash('ok', 'Oportunidad eliminada (sus tareas se conservan en el RUT).');
    redirigir(url('oportunidades'));
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $o = $id
        ? q_uno('SELECT * FROM oportunidades WHERE id = ?', [$id])
        : [
            'empresa_id'     => entrada_int('empresa_id'),
            'contacto_id'    => entrada_int('contacto_id'),
            'etapa'          => 'prospecto',
            'probabilidad'   => 10,
            'tarifa_tipo'    => 'mensual',
            'tarifa_moneda'  => 'UF',
            'responsable_id' => usuario_actual()['id'],
        ];
    if ($id && !$o) {
        redirigir(url('oportunidades'));
    }
    // Contactos del RUT primero, luego el resto
    $contactos = opciones_contactos();
    if (!empty($o['empresa_id'])) {
        $delRut = array_flip(array_column(q_todos('SELECT contacto_id FROM contacto_empresas WHERE empresa_id = ?', [$o['empresa_id']]), 'contacto_id'));
        $contactos = array_intersect_key($contactos, $delRut) + array_diff_key($contactos, $delRut);
    }
    layout_inicio($id ? 'Editar oportunidad' : 'Nueva oportunidad', 'oportunidades');
    ?>
    <h1><?= $id ? 'Editar oportunidad' : 'Nueva oportunidad' ?></h1>
    <form method="post" action="<?= e(url('oportunidades', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div class="completo"><?= campo('titulo', 'Título', $o['titulo'] ?? '', 'text', 'maxlength="150" placeholder="Si lo deja vacío se usa el nombre del RUT"') ?></div>

        <h3 class="completo separado">Prospecto</h3>
        <div><?= selector('empresa_id', 'RUT / contribuyente', opciones_empresas(), $o['empresa_id'] ?? '', '— Nuevo o sin RUT —') ?></div>
        <div class="grupo-nuevo"><?= campo('nuevo_rut_nombre', '…o nuevo: nombre / razón social', '', 'text', 'maxlength="150"') ?>
            <?= campo('nuevo_rut', 'RUT', '', 'text', 'maxlength="15" placeholder="76.123.456-7"') ?></div>
        <div><?= selector('contacto_id', 'Contacto', $contactos, $o['contacto_id'] ?? '', '— Nuevo o sin contacto —') ?>
            <small class="tenue">Primero aparecen los contactos del RUT elegido.</small></div>
        <div class="grupo-nuevo"><?= campo('nuevo_contacto_nombre', '…o nuevo contacto: nombre', '', 'text', 'maxlength="100"') ?>
            <?= campo('nuevo_contacto_rol', 'Rol', '', 'text', 'maxlength="60" placeholder="Gerente, dueño, contador…"') ?>
            <?= campo('nuevo_contacto_email', 'Correo', '', 'email') ?>
            <?= campo('nuevo_contacto_telefono', 'Teléfono', '', 'tel') ?></div>

        <h3 class="completo separado">Propuesta</h3>
        <div class="completo"><?= campo('servicio', 'Servicio a prestar', $o['servicio'] ?? '', 'text', 'maxlength="150" list="servicios" placeholder="Escriba o elija un servicio habitual"') ?>
            <datalist id="servicios"><?php foreach (SERVICIOS as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
        <div><?= selector('tarifa_tipo', 'Tipo de tarifa', TARIFAS, $o['tarifa_tipo'] ?? 'mensual', false) ?></div>
        <div><?= selector('tarifa_moneda', 'Moneda', ['UF' => 'UF', 'CLP' => 'Pesos ($)'], $o['tarifa_moneda'] ?? 'UF', false) ?></div>
        <div><?= campo('monto', 'Monto de la tarifa', isset($o['monto']) && (float)$o['monto'] > 0 ? numero_corto($o['monto'], 2) : '', 'text', 'inputmode="decimal" placeholder="Ej.: 3 o 150.000"') ?></div>
        <div><?= selector('tarifa_mes', 'Mes de cobro (anual / único)', MESES, $o['tarifa_mes'] ?? '', '—') ?>
            <small class="tenue">Para trimestral o semestral: mes del primer cobro.</small></div>

        <h3 class="completo separado">Seguimiento</h3>
        <div><?= selector('etapa', 'Etapa', ETAPAS, $o['etapa'] ?? 'prospecto', false) ?></div>
        <div><?= campo('probabilidad', 'Probabilidad (%)', $o['probabilidad'] ?? '', 'number', 'min="0" max="100"') ?></div>
        <div><?= campo('fecha_cierre', 'Fecha de cierre estimada', $o['fecha_cierre'] ?? '', 'date') ?></div>
        <div><?= selector('responsable_id', 'Responsable', opciones_usuarios(), $o['responsable_id'] ?? '') ?></div>
        <div class="completo"><?= area('notas', 'Notas', $o['notas'] ?? '') ?></div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e($id ? url('oportunidades', ['a' => 'ver', 'id' => $id]) : url('oportunidades')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Ficha ---------- */
if ($accion === 'ver' && $id) {
    $o = q_uno(
        'SELECT o.*, e.nombre AS empresa, e.identificacion, c.nombre AS c_nombre, c.apellido AS c_apellido,
            c.email AS c_email, c.telefono AS c_telefono, c.movil AS c_movil, u.nombre AS responsable, cl.nombre AS cliente
         FROM oportunidades o
         LEFT JOIN empresas e ON e.id = o.empresa_id
         LEFT JOIN contactos c ON c.id = o.contacto_id
         LEFT JOIN usuarios u ON u.id = o.responsable_id
         LEFT JOIN clientes cl ON cl.id = o.cliente_id
         WHERE o.id = ?', [$id]);
    if (!$o) {
        redirigir(url('oportunidades'));
    }
    $tareas = q_todos(
        "SELECT t.*, u.nombre AS responsable FROM tareas t LEFT JOIN usuarios u ON u.id = t.responsable_id
         WHERE t.oportunidad_id = ? AND t.estado <> 'completada'
         ORDER BY t.vencimiento IS NULL, t.vencimiento, t.id", [$id]);
    $hechas = (int)q_valor("SELECT COUNT(*) FROM tareas WHERE oportunidad_id = ? AND estado = 'completada'", [$id]);
    $otrosContactos = $o['empresa_id'] ? q_todos(
        'SELECT c.id, c.nombre, c.apellido, v.rol FROM contacto_empresas v JOIN contactos c ON c.id = v.contacto_id
         WHERE v.empresa_id = ? AND c.id <> ? ORDER BY c.nombre', [$o['empresa_id'], (int)$o['contacto_id']]) : [];
    $actividades = q_todos(
        'SELECT a.*, u.nombre AS usuario FROM actividades a LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.oportunidad_id = ? ORDER BY a.fecha DESC LIMIT 30', [$id]);
    $mensual = tarifa_mensual_clp($o, indicador('uf'));
    $vinculo = ['oportunidad_id' => $id] + ($o['empresa_id'] ? ['empresa_id' => $o['empresa_id']] : []);

    layout_inicio($o['titulo'], 'oportunidades');
    ?>
    <div class="encabezado">
        <div class="titulo">
            <h1><?= e($o['titulo']) ?> <?= badge_etapa($o['etapa']) ?></h1>
            <?php if ($o['cliente_id']): ?><p class="tenue">Ya es cliente: <?= enlace('clientes', $o['cliente_id'], $o['cliente'] ?? 'ver cliente') ?></p><?php endif; ?>
        </div>
        <div>
            <?php if ($o['etapa'] === 'ganada' && !$o['cliente_id']): ?>
                <?= boton_post(url('oportunidades', ['a' => 'convertir', 'id' => $id]), '★ Convertir en cliente', '',
                    '¿Crear el cliente? Se le pasan el RUT, las tareas, gestiones, credenciales y documentos del prospecto, y se crea el plan de cobro con la tarifa.') ?>
            <?php endif; ?>
            <a class="boton <?= $o['etapa'] === 'ganada' && !$o['cliente_id'] ? 'secundario' : '' ?>" href="<?= e(url('oportunidades', ['a' => 'form', 'id' => $id])) ?>">Editar</a>
            <?php if (puede_eliminar($o)) echo boton_post(url('oportunidades', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar esta oportunidad? Sus tareas se conservan en el RUT.'); ?>
        </div>
    </div>
    <section class="panel">
        <form method="post" action="<?= e(url('oportunidades', ['a' => 'etapa', 'id' => $id, 'volver' => 'ver'])) ?>" class="etapas">
            <?= csrf_campo() ?>
            <?php foreach (ETAPAS as $clave => $nombre): ?>
                <button type="submit" name="etapa" value="<?= e($clave) ?>" class="paso <?= $clave === $o['etapa'] ? 'actual etapa-' . e($clave) : '' ?>"><?= e($nombre) ?></button>
            <?php endforeach; ?>
        </form>
        <?php if (!$tareas && !in_array($o['etapa'], ['ganada', 'perdida'], true)): ?>
            <p class="alerta alerta-aviso">Sin próxima acción: agende una tarea (llamar, enviar propuesta, reunión…) para no perder el seguimiento.</p>
        <?php endif; ?>
    </section>
    <div class="columnas">
        <section class="panel">
            <h2>Propuesta</h2>
            <dl class="ficha">
                <dt>Servicio</dt><dd><?= e($o['servicio']) ?: '<span class="tenue">— por definir —</span>' ?></dd>
                <dt>Tarifa</dt><dd><?= e(tarifa_texto($o)) ?: '<span class="tenue">— por definir —</span>' ?>
                    <?php if ($mensual !== null && $o['tarifa_tipo'] !== 'mensual'): ?><small class="tenue"> · ≈ <?= e(dinero($mensual)) ?> al mes</small><?php endif; ?>
                    <?php if ($mensual !== null && $o['tarifa_moneda'] === 'UF' && $o['tarifa_tipo'] === 'mensual'): ?><small class="tenue"> · ≈ <?= e(dinero($mensual)) ?></small><?php endif; ?></dd>
                <dt>Probabilidad</dt><dd><?= (int)$o['probabilidad'] ?>%</dd>
                <dt>Cierre estimado</dt><dd><?= e(fecha($o['fecha_cierre'])) ?: '—' ?></dd>
                <dt>Responsable</dt><dd><?= e($o['responsable']) ?></dd>
                <dt>Creada</dt><dd><?= e(fecha($o['creado_en'])) ?></dd>
            </dl>
            <?php if ($o['notas']): ?><p class="notas"><?= nl2br(e($o['notas'])) ?></p><?php endif; ?>
        </section>
        <section class="panel">
            <h2>RUT y contacto</h2>
            <dl class="ficha">
                <dt>RUT</dt><dd><?php if ($o['empresa_id']): ?><?= enlace('empresas', $o['empresa_id'], $o['empresa']) ?>
                    <small class="tenue"><?= e($o['identificacion']) ?></small> <?= badge_tipo_rut($o['identificacion']) ?><?php else: ?><span class="tenue">— sin RUT —</span><?php endif; ?></dd>
                <dt>Contacto</dt><dd><?php if ($o['contacto_id']): ?><?= enlace('contactos', $o['contacto_id'], trim($o['c_nombre'] . ' ' . $o['c_apellido'])) ?><?php else: ?><span class="tenue">— sin contacto —</span><?php endif; ?></dd>
                <?php if ($o['c_email']): ?><dt>Correo</dt><dd><a href="mailto:<?= e($o['c_email']) ?>"><?= e($o['c_email']) ?></a></dd><?php endif; ?>
                <?php if ($o['c_telefono'] || $o['c_movil']): ?><dt>Teléfono</dt><dd><?= e(implode(' · ', array_filter([$o['c_movil'], $o['c_telefono']]))) ?></dd><?php endif; ?>
            </dl>
            <?php if ($otrosContactos): ?>
                <h3 class="separado">Otros contactos del RUT</h3>
                <ul class="lista-simple">
                <?php foreach ($otrosContactos as $c): ?>
                    <li><?= enlace('contactos', $c['id'], trim($c['nombre'] . ' ' . $c['apellido'])) ?> <small class="tenue"><?= e($c['rol']) ?></small></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
    <?php panel_tareas($tareas, $vinculo, 'Próximas acciones' . ($hechas ? " ($hechas hecha" . ($hechas > 1 ? 's' : '') . ')' : '')); ?>
    <?php
    historial_actividades($actividades, $vinculo + ($o['contacto_id'] ? ['contacto_id' => $o['contacto_id']] : []));
    layout_fin();
    return;
}

/* ---------- Tablero (embudo por etapas) ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['o.titulo', 'o.servicio', 'e.nombre', 'e.identificacion'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
if (entrada('responsable') === 'mias') {
    $condiciones[] = 'o.responsable_id = :uid';
    $params['uid'] = usuario_actual()['id'];
}
$etapaFiltro = entrada('etapa');
if (isset(ETAPAS[$etapaFiltro])) {
    $condiciones[] = 'o.etapa = :etapa';
    $params['etapa'] = $etapaFiltro;
}
if (entrada('accion') === 'sin') {
    $condiciones[] = "o.etapa NOT IN ('ganada', 'perdida') AND NOT EXISTS (SELECT 1 FROM tareas t WHERE t.oportunidad_id = o.id AND t.estado <> 'completada')";
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$oportunidades = q_todos(
    "SELECT o.*, e.nombre AS empresa, u.nombre AS responsable,
        (SELECT MIN(t.vencimiento) FROM tareas t WHERE t.oportunidad_id = o.id AND t.estado <> 'completada') AS proxima_fecha,
        (SELECT COUNT(*) FROM tareas t WHERE t.oportunidad_id = o.id AND t.estado <> 'completada') AS n_tareas
     FROM oportunidades o
     LEFT JOIN empresas e ON e.id = o.empresa_id
     LEFT JOIN usuarios u ON u.id = o.responsable_id
     $where ORDER BY o.fecha_cierre IS NULL, o.fecha_cierre, o.actualizado_en DESC",
    $params
);
$uf = indicador('uf');
$columnas = array_fill_keys(array_keys(ETAPAS), []);
foreach ($oportunidades as $o) {
    $columnas[$o['etapa']][] = $o;
}
if (isset(ETAPAS[$etapaFiltro])) {
    $columnas = [$etapaFiltro => $columnas[$etapaFiltro]];
}
$hoy = date('Y-m-d');

layout_inicio('Oportunidades', 'oportunidades');
?>
<h1>Oportunidades</h1>
<?php barra_lista('oportunidades', '+ Nueva oportunidad', false, [
    selector('responsable', '', ['mias' => 'Solo las mías'], entrada('responsable'), 'Todos los responsables', 'aria-label="Responsable"'),
    selector('etapa', '', ETAPAS, $etapaFiltro, 'Todas las etapas', 'aria-label="Etapa"'),
    selector('accion', '', ['sin' => 'Sin próxima acción'], entrada('accion'), 'Con y sin próxima acción', 'aria-label="Próxima acción"'),
]); ?>
<div class="tablero">
    <?php foreach ($columnas as $etapa => $items):
        $mensual = array_sum(array_map(static fn($o) => tarifa_mensual_clp($o, $uf) ?? 0, $items)); ?>
        <div class="columna-tablero">
            <h3><?= badge_etapa($etapa) ?> <small><?= count($items) ?><?= $mensual > 0 ? ' · ≈ ' . e(dinero($mensual)) . '/mes' : '' ?></small></h3>
            <?php foreach ($items as $o): ?>
                <div class="tarjeta-op">
                    <a href="<?= e(url('oportunidades', ['a' => 'ver', 'id' => $o['id']])) ?>"><strong><?= e($o['titulo']) ?></strong></a>
                    <?php if ($o['empresa'] && $o['empresa'] !== $o['titulo']): ?><div class="tenue"><?= e($o['empresa']) ?></div><?php endif; ?>
                    <?php if ($o['servicio'] || (float)$o['monto'] > 0): ?>
                        <div><?= e($o['servicio']) ?><?= $o['servicio'] && (float)$o['monto'] > 0 ? ' · ' : '' ?><strong><?= e(tarifa_texto($o)) ?></strong></div>
                    <?php endif; ?>
                    <?php if ($o['n_tareas']): ?>
                        <div class="chico-texto <?= $o['proxima_fecha'] && $o['proxima_fecha'] < $hoy ? 'texto-peligro' : 'tenue' ?>">
                            ▸ <?= (int)$o['n_tareas'] ?> tarea<?= $o['n_tareas'] > 1 ? 's' : '' ?><?= $o['proxima_fecha'] ? ' · próxima ' . e(fecha($o['proxima_fecha'])) : '' ?></div>
                    <?php elseif (!in_array($o['etapa'], ['ganada', 'perdida'], true)): ?>
                        <div class="chico-texto texto-aviso">⚠ Sin próxima acción</div>
                    <?php elseif ($o['etapa'] === 'ganada' && !$o['cliente_id']): ?>
                        <div class="chico-texto texto-aviso">★ Falta convertir en cliente</div>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('oportunidades', ['a' => 'etapa', 'id' => $o['id']])) ?>">
                        <?= csrf_campo() ?>
                        <select name="etapa" onchange="this.form.submit()" aria-label="Mover a etapa">
                            <?php foreach (ETAPAS as $k => $n): ?>
                                <option value="<?= e($k) ?>" <?= $k === $etapa ? 'selected' : '' ?>><?= e($n) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php
layout_fin();
