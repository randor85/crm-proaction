<?php
declare(strict_types=1);

$id = entrada_int('id');

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $tipo = entrada('tipo');
    $fechaTxt = entrada('fecha');
    $ts = $fechaTxt !== '' ? strtotime($fechaTxt) : time();
    $datos = [
        'tipo'           => isset(TIPOS_ACTIVIDAD[$tipo]) ? $tipo : 'tarea',
        'asunto'         => entrada('asunto'),
        'descripcion'    => nulo_si_vacio(entrada('descripcion')),
        'fecha'          => date('Y-m-d H:i:s', $ts ?: time()),
        'completada'     => entrada('completada') === '1' ? 1 : 0,
        'cliente_id'     => entrada_int('cliente_id'),
        'tarea_id'       => entrada_int('tarea_id'),
        'empresa_id'     => entrada_int('empresa_id'),
        'contacto_id'    => entrada_int('contacto_id'),
        'oportunidad_id' => entrada_int('oportunidad_id'),
        'usuario_id'     => entrada_int('usuario_id') ?? (int)usuario_actual()['id'],
    ];
    if (!$datos['cliente_id'] && $datos['empresa_id']) {
        $datos['cliente_id'] = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$datos['empresa_id']]);
    }
    if (!$datos['cliente_id'] && $datos['tarea_id']) {
        $datos['cliente_id'] = q_valor('SELECT cliente_id FROM tareas WHERE id = ?', [$datos['tarea_id']]);
    }
    if ($datos['asunto'] === '') {
        flash('error', 'El asunto es obligatorio.');
        redirigir(url('actividades', ['a' => 'form', 'id' => $id]));
    }
    if ($id) {
        actualizar('actividades', $id, $datos);
        flash('ok', 'Gestión actualizada.');
    } else {
        $datos['creado_en'] = ahora();
        $id = insertar('actividades', $datos);
        flash('ok', 'Gestión registrada.');
    }
    // Volver a la ficha desde la que se creó la gestión
    if ($datos['tarea_id']) {
        redirigir(url('tareas', ['a' => 'ver', 'id' => $datos['tarea_id']]));
    } elseif ($datos['oportunidad_id']) {
        redirigir(url('oportunidades', ['a' => 'ver', 'id' => $datos['oportunidad_id']]));
    } elseif ($datos['contacto_id']) {
        redirigir(url('contactos', ['a' => 'ver', 'id' => $datos['contacto_id']]));
    } elseif ($datos['empresa_id']) {
        redirigir(url('empresas', ['a' => 'ver', 'id' => $datos['empresa_id']]));
    } elseif ($datos['cliente_id']) {
        redirigir(url('clientes', ['a' => 'ver', 'id' => $datos['cliente_id']]));
    }
    redirigir(url('actividades'));
}

/* ---------- Marcar como completada ---------- */
if ($accion === 'completar' && es_post() && $id) {
    q('UPDATE actividades SET completada = 1 WHERE id = ?', [$id]);
    flash('ok', 'Gestión marcada como completada.');
    redirigir(entrada('volver') === 'dashboard' ? url() : url('actividades'));
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $act = q_uno('SELECT * FROM actividades WHERE id = ?', [$id]);
    if (!puede_eliminar($act, 'usuario_id')) {
        flash('error', 'Solo el usuario asignado o un administrador puede eliminar esta gestión.');
        redirigir(url('actividades', ['a' => 'form', 'id' => $id]));
    }
    q('DELETE FROM actividades WHERE id = ?', [$id]);
    flash('ok', 'Gestión eliminada.');
    redirigir(url('actividades'));
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $a = $id
        ? q_uno('SELECT * FROM actividades WHERE id = ?', [$id])
        : [
            'tipo'           => 'llamada',
            'fecha'          => date('Y-m-d H:i:s'),
            'cliente_id'     => entrada_int('cliente_id'),
            'tarea_id'       => entrada_int('tarea_id'),
            'empresa_id'     => entrada_int('empresa_id'),
            'contacto_id'    => entrada_int('contacto_id'),
            'oportunidad_id' => entrada_int('oportunidad_id'),
            'usuario_id'     => usuario_actual()['id'],
            'completada'     => 0,
        ];
    if ($id && !$a) {
        redirigir(url('actividades'));
    }
    $fechaInput = date('Y-m-d\TH:i', strtotime($a['fecha']));
    if (!$id && !$a['cliente_id'] && $a['empresa_id']) {
        $a['cliente_id'] = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$a['empresa_id']]);
    }
    $opcionesTareas = opciones_tareas_abiertas();
    if (!empty($a['tarea_id']) && !isset($opcionesTareas[$a['tarea_id']])) {
        $opcionesTareas[$a['tarea_id']] = (string)q_valor('SELECT titulo FROM tareas WHERE id = ?', [$a['tarea_id']]);
    }
    layout_inicio($id ? 'Editar gestión' : 'Nueva gestión', 'actividades');
    ?>
    <div class="encabezado">
        <h1><?= $id ? 'Editar gestión' : 'Nueva gestión' ?></h1>
        <?php if ($id && puede_eliminar($a, 'usuario_id')) echo boton_post(url('actividades', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar esta gestión?'); ?>
    </div>
    <form method="post" action="<?= e(url('actividades', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div><?= selector('tipo', 'Tipo', TIPOS_ACTIVIDAD, $a['tipo'], false) ?></div>
        <div><?= campo('fecha', 'Fecha y hora', $fechaInput, 'datetime-local', 'required') ?></div>
        <div class="completo"><?= campo('asunto', 'Asunto *', $a['asunto'] ?? '', 'text', 'required maxlength="200"') ?></div>
        <div><?= selector('cliente_id', 'Cliente', opciones_clientes(), $a['cliente_id'] ?? '') ?></div>
        <div><?= selector_empresa('empresa_id', 'RUT / Empresa', $a['empresa_id'] ?? '') ?></div>
        <div><?= selector('tarea_id', 'Tarea relacionada', $opcionesTareas, $a['tarea_id'] ?? '', 'Ninguna') ?></div>
        <div><?= selector('contacto_id', 'Contacto', opciones_contactos(), $a['contacto_id'] ?? '') ?></div>
        <div><?= selector('oportunidad_id', 'Oportunidad', opciones_oportunidades(), $a['oportunidad_id'] ?? '') ?></div>
        <div><?= selector('usuario_id', 'Asignada a', opciones_usuarios(), $a['usuario_id'] ?? '', false) ?></div>
        <div class="completo"><?= area('descripcion', 'Descripción / resultado', $a['descripcion'] ?? '') ?></div>
        <div class="completo">
            <label class="check"><input type="checkbox" name="completada" value="1" <?= $a['completada'] ? 'checked' : '' ?>> Completada</label>
        </div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e(url('actividades')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['a.asunto', 'a.descripcion', 'e.nombre', 'cl.nombre'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
$estado = entrada('estado', 'pendientes');
if ($estado === 'pendientes') {
    $condiciones[] = 'a.completada = 0';
} elseif ($estado === 'completadas') {
    $condiciones[] = 'a.completada = 1';
}
$quien = entrada('quien', 'mias');
if ($quien === 'mias') {
    $condiciones[] = 'a.usuario_id = :uid';
    $params['uid'] = usuario_actual()['id'];
}
$tipo = entrada('tipo');
if (isset(TIPOS_ACTIVIDAD[$tipo])) {
    $condiciones[] = 'a.tipo = :tipo';
    $params['tipo'] = $tipo;
}
$filtroCliente = entrada_int('cliente_id');
if ($filtroCliente) {
    $condiciones[] = 'a.cliente_id = :cliente';
    $params['cliente'] = $filtroCliente;
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$orden = $estado === 'pendientes' ? 'a.fecha ASC' : 'a.fecha DESC';

[$limite, $desde] = paginacion_limites();
$desdeTabla = 'FROM actividades a
     LEFT JOIN empresas e ON e.id = a.empresa_id
     LEFT JOIN clientes cl ON cl.id = a.cliente_id
     LEFT JOIN contactos c ON c.id = a.contacto_id
     LEFT JOIN usuarios u ON u.id = a.usuario_id';
$total = (int)q_valor("SELECT COUNT(*) $desdeTabla $where", $params);
$actividades = q_todos(
    "SELECT a.*, e.nombre AS empresa, cl.nombre AS cliente, c.nombre AS c_nombre, c.apellido AS c_apellido, u.nombre AS usuario
     $desdeTabla $where ORDER BY $orden LIMIT $limite OFFSET $desde",
    $params
);
$ahora = ahora();

layout_inicio('Gestiones', 'actividades');
?>
<h1>Gestiones</h1>
<p class="tenue">Bitácora de llamadas, reuniones, correos y trámites. Las gestiones pendientes también aparecen en su calendario.</p>
<?php barra_lista('actividades', '+ Nueva gestión', false, [
    selector('estado', '', ['pendientes' => 'Pendientes', 'completadas' => 'Completadas', 'todas' => 'Todas'], $estado, false, 'aria-label="Estado"'),
    selector('quien', '', ['mias' => 'Mías', 'todos' => 'De todo el equipo'], $quien, false, 'aria-label="Usuario"'),
    selector('tipo', '', TIPOS_ACTIVIDAD, $tipo, 'Todos los tipos', 'aria-label="Tipo"'),
    selector('cliente_id', '', opciones_clientes(), $filtroCliente ?? '', 'Todos los clientes', 'aria-label="Cliente"'),
]); ?>
<table>
    <thead><tr><th>Fecha</th><th>Tipo</th><th>Asunto</th><th>Cliente / Empresa</th><th>Asignada a</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($actividades as $a):
        $vencida = !$a['completada'] && $a['fecha'] < $ahora; ?>
        <tr class="<?= $a['completada'] ? 'hecha' : ($vencida ? 'vencida' : '') ?>">
            <td class="nowrap"><?= e(fecha($a['fecha'], true)) ?></td>
            <td><span class="badge"><?= e(TIPOS_ACTIVIDAD[$a['tipo']] ?? $a['tipo']) ?></span></td>
            <td><a href="<?= e(url('actividades', ['a' => 'form', 'id' => $a['id']])) ?>"><?= e($a['asunto']) ?></a></td>
            <td><?= enlace('clientes', $a['cliente_id'], $a['cliente']) ?><?php if ($a['empresa'] || $a['c_nombre']): ?><br><small class="tenue"><?= e(implode(' · ', array_filter([$a['empresa'], trim($a['c_nombre'] . ' ' . $a['c_apellido'])]))) ?></small><?php endif; ?></td>
            <td><?= e($a['usuario']) ?></td>
            <td class="derecha"><?php if (!$a['completada']) echo boton_post(url('actividades', ['a' => 'completar', 'id' => $a['id']]), '✓ Hecho', 'chico secundario'); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$actividades): ?><tr><td colspan="6" class="vacio">No hay gestiones con estos filtros.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
