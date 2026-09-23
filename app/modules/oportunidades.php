<?php
declare(strict_types=1);

$id = entrada_int('id');

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $etapa = entrada('etapa');
    $monto = str_replace(',', '.', entrada('monto', '0'));
    $datos = [
        'titulo'         => entrada('titulo'),
        'empresa_id'     => entrada_int('empresa_id'),
        'contacto_id'    => entrada_int('contacto_id'),
        'monto'          => is_numeric($monto) ? (float)$monto : 0,
        'etapa'          => isset(ETAPAS[$etapa]) ? $etapa : 'prospecto',
        'probabilidad'   => min(100, max(0, entrada_int('probabilidad') ?? 0)),
        'fecha_cierre'   => nulo_si_vacio(entrada('fecha_cierre')),
        'responsable_id' => entrada_int('responsable_id'),
        'notas'          => nulo_si_vacio(entrada('notas')),
        'actualizado_en' => ahora(),
    ];
    if ($datos['titulo'] === '') {
        flash('error', 'El título es obligatorio.');
        redirigir(url('oportunidades', ['a' => 'form', 'id' => $id]));
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

/* ---------- Cambio rápido de etapa (desde el tablero) ---------- */
if ($accion === 'etapa' && es_post() && $id) {
    $etapa = entrada('etapa');
    if (isset(ETAPAS[$etapa])) {
        q('UPDATE oportunidades SET etapa = ?, actualizado_en = ? WHERE id = ?', [$etapa, ahora(), $id]);
        flash('ok', 'Etapa actualizada a "' . ETAPAS[$etapa] . '".');
    }
    redirigir(entrada('volver') === 'ver' ? url('oportunidades', ['a' => 'ver', 'id' => $id]) : url('oportunidades'));
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $op = q_uno('SELECT * FROM oportunidades WHERE id = ?', [$id]);
    if (!puede_eliminar($op)) {
        flash('error', 'Solo el responsable o un administrador puede eliminar esta oportunidad.');
        redirigir(url('oportunidades', ['a' => 'ver', 'id' => $id]));
    }
    q('DELETE FROM oportunidades WHERE id = ?', [$id]);
    flash('ok', 'Oportunidad eliminada.');
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
            'responsable_id' => usuario_actual()['id'],
        ];
    if ($id && !$o) {
        redirigir(url('oportunidades'));
    }
    layout_inicio($id ? 'Editar oportunidad' : 'Nueva oportunidad', 'oportunidades');
    ?>
    <h1><?= $id ? 'Editar oportunidad' : 'Nueva oportunidad' ?></h1>
    <form method="post" action="<?= e(url('oportunidades', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div class="completo"><?= campo('titulo', 'Título *', $o['titulo'] ?? '', 'text', 'required maxlength="150" placeholder="Ej.: Renovación de licencias 2027"') ?></div>
        <div><?= selector('empresa_id', 'Empresa', opciones_empresas(), $o['empresa_id'] ?? '') ?></div>
        <div><?= selector('contacto_id', 'Contacto', opciones_contactos(), $o['contacto_id'] ?? '') ?></div>
        <div><?= campo('monto', 'Monto estimado (' . config('moneda', '$') . ')', $o['monto'] ?? '', 'number', 'step="0.01" min="0"') ?></div>
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
        'SELECT o.*, e.nombre AS empresa, c.nombre AS c_nombre, c.apellido AS c_apellido, u.nombre AS responsable
         FROM oportunidades o
         LEFT JOIN empresas e ON e.id = o.empresa_id
         LEFT JOIN contactos c ON c.id = o.contacto_id
         LEFT JOIN usuarios u ON u.id = o.responsable_id
         WHERE o.id = ?', [$id]);
    if (!$o) {
        redirigir(url('oportunidades'));
    }
    $actividades = q_todos(
        'SELECT a.*, u.nombre AS usuario FROM actividades a LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.oportunidad_id = ? ORDER BY a.fecha DESC LIMIT 30', [$id]);

    layout_inicio($o['titulo'], 'oportunidades');
    ?>
    <div class="encabezado">
        <h1><?= e($o['titulo']) ?> <?= badge_etapa($o['etapa']) ?></h1>
        <div>
            <a class="boton" href="<?= e(url('oportunidades', ['a' => 'form', 'id' => $id])) ?>">Editar</a>
            <?php if (puede_eliminar($o)) echo boton_post(url('oportunidades', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar esta oportunidad?'); ?>
        </div>
    </div>
    <section class="panel">
        <form method="post" action="<?= e(url('oportunidades', ['a' => 'etapa', 'id' => $id, 'volver' => 'ver'])) ?>" class="etapas">
            <?= csrf_campo() ?>
            <?php foreach (ETAPAS as $clave => $nombre): ?>
                <button type="submit" name="etapa" value="<?= e($clave) ?>" class="paso <?= $clave === $o['etapa'] ? 'actual etapa-' . e($clave) : '' ?>"><?= e($nombre) ?></button>
            <?php endforeach; ?>
        </form>
        <dl class="ficha">
            <dt>Monto</dt><dd><?= e(dinero($o['monto'])) ?></dd>
            <dt>Probabilidad</dt><dd><?= (int)$o['probabilidad'] ?>% (ponderado: <?= e(dinero($o['monto'] * $o['probabilidad'] / 100)) ?>)</dd>
            <dt>Cierre estimado</dt><dd><?= e(fecha($o['fecha_cierre'])) ?></dd>
            <dt>Empresa</dt><dd><?php if ($o['empresa_id']): ?><a href="<?= e(url('empresas', ['a' => 'ver', 'id' => $o['empresa_id']])) ?>"><?= e($o['empresa']) ?></a><?php endif; ?></dd>
            <dt>Contacto</dt><dd><?php if ($o['contacto_id']): ?><a href="<?= e(url('contactos', ['a' => 'ver', 'id' => $o['contacto_id']])) ?>"><?= e(trim($o['c_nombre'] . ' ' . $o['c_apellido'])) ?></a><?php endif; ?></dd>
            <dt>Responsable</dt><dd><?= e($o['responsable']) ?></dd>
            <dt>Creada</dt><dd><?= e(fecha($o['creado_en'], true)) ?></dd>
        </dl>
        <?php if ($o['notas']): ?><p class="notas"><?= nl2br(e($o['notas'])) ?></p><?php endif; ?>
    </section>
    <?php
    historial_actividades($actividades, ['oportunidad_id' => $id, 'empresa_id' => $o['empresa_id'], 'contacto_id' => $o['contacto_id']]);
    layout_fin();
    return;
}

/* ---------- Tablero (lista por etapas) ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['o.titulo', 'e.nombre'], entrada('q'));
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
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$oportunidades = q_todos(
    "SELECT o.*, e.nombre AS empresa, u.nombre AS responsable
     FROM oportunidades o
     LEFT JOIN empresas e ON e.id = o.empresa_id
     LEFT JOIN usuarios u ON u.id = o.responsable_id
     $where ORDER BY o.fecha_cierre IS NULL, o.fecha_cierre, o.actualizado_en DESC",
    $params
);
$columnas = array_fill_keys(array_keys(ETAPAS), []);
foreach ($oportunidades as $o) {
    $columnas[$o['etapa']][] = $o;
}
if (isset(ETAPAS[$etapaFiltro])) {
    $columnas = [$etapaFiltro => $columnas[$etapaFiltro]];
}

layout_inicio('Oportunidades', 'oportunidades');
?>
<h1>Oportunidades</h1>
<?php barra_lista('oportunidades', '+ Nueva oportunidad', false, [
    selector('responsable', '', ['mias' => 'Solo las mías'], entrada('responsable'), 'Todos los responsables', 'aria-label="Responsable"'),
    selector('etapa', '', ETAPAS, $etapaFiltro, 'Todas las etapas', 'aria-label="Etapa"'),
]); ?>
<div class="tablero">
    <?php foreach ($columnas as $etapa => $items):
        $suma = array_sum(array_map(static fn($o) => (float)$o['monto'], $items)); ?>
        <div class="columna-tablero">
            <h3><?= badge_etapa($etapa) ?> <small><?= count($items) ?> · <?= e(dinero($suma)) ?></small></h3>
            <?php foreach ($items as $o): ?>
                <div class="tarjeta-op">
                    <a href="<?= e(url('oportunidades', ['a' => 'ver', 'id' => $o['id']])) ?>"><strong><?= e($o['titulo']) ?></strong></a>
                    <div class="tenue"><?= e($o['empresa']) ?></div>
                    <div><?= e(dinero($o['monto'])) ?><?= $o['fecha_cierre'] ? ' · ' . e(fecha($o['fecha_cierre'])) : '' ?></div>
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
