<?php
declare(strict_types=1);

$id = entrada_int('id');
$uid = (int)usuario_actual()['id'];

/** Siguiente vencimiento de una tarea recurrente (conserva el día, ajustado a fin de mes). */
function siguiente_vencimiento(string $fecha, string $recurrencia): ?string
{
    $meses = ['mensual' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12][$recurrencia] ?? 0;
    if (!$meses) {
        return null;
    }
    $d = new DateTime($fecha);
    $dia = (int)$d->format('d');
    $d->modify('first day of this month')->modify("+$meses months");
    $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($dia, (int)$d->format('t')));
    return $d->format('Y-m-d');
}

/** Marca la tarea como completada y, si es recurrente, crea la siguiente. Devuelve el id nuevo o null. */
function completar_tarea(array $t): ?int
{
    q("UPDATE tareas SET estado = 'completada', completada_en = ?, actualizado_en = ? WHERE id = ?", [ahora(), ahora(), $t['id']]);
    $siguiente = $t['vencimiento'] ? siguiente_vencimiento($t['vencimiento'], $t['recurrencia']) : null;
    if (!$siguiente) {
        return null;
    }
    return insertar('tareas', [
        'titulo' => $t['titulo'], 'descripcion' => $t['descripcion'], 'cliente_id' => $t['cliente_id'],
        'empresa_id' => $t['empresa_id'], 'responsable_id' => $t['responsable_id'], 'vencimiento' => $siguiente,
        'estado' => 'pendiente', 'prioridad' => $t['prioridad'], 'recurrencia' => $t['recurrencia'],
        'creado_por' => (int)usuario_actual()['id'], 'creado_en' => ahora(), 'actualizado_en' => ahora(),
    ]);
}

function volver_desde_tarea(array $t): string
{
    $volver = entrada('volver');
    if ($volver === 'dashboard') {
        return url();
    }
    if ($volver === 'lista') {
        return url('tareas');
    }
    return url('tareas', ['a' => 'ver', 'id' => $t['id']]);
}

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $estado = entrada('estado');
    $prioridad = entrada('prioridad');
    $recurrencia = entrada('recurrencia');
    $datos = [
        'titulo'         => entrada('titulo'),
        'descripcion'    => nulo_si_vacio(entrada('descripcion')),
        'cliente_id'     => entrada_int('cliente_id'),
        'empresa_id'     => entrada_int('empresa_id'),
        'responsable_id' => entrada_int('responsable_id'),
        'vencimiento'    => entrada_fecha('vencimiento'),
        'estado'         => isset(ESTADOS_TAREA[$estado]) ? $estado : 'pendiente',
        'prioridad'      => isset(PRIORIDADES[$prioridad]) ? $prioridad : 'normal',
        'recurrencia'    => isset(RECURRENCIAS[$recurrencia]) ? $recurrencia : 'ninguna',
        'actualizado_en' => ahora(),
    ];
    // Si se eligió un RUT sin cliente, se toma el cliente del RUT.
    if (!$datos['cliente_id'] && $datos['empresa_id']) {
        $datos['cliente_id'] = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$datos['empresa_id']]);
    }
    if ($datos['titulo'] === '') {
        flash('error', 'El título de la tarea es obligatorio.');
        redirigir(url('tareas', ['a' => 'form', 'id' => $id]));
    }
    if ($datos['recurrencia'] !== 'ninguna' && !$datos['vencimiento']) {
        flash('error', 'Una tarea recurrente necesita fecha de vencimiento.');
        redirigir(url('tareas', ['a' => 'form', 'id' => $id]));
    }

    $completando = $datos['estado'] === 'completada';
    if ($completando) {
        $datos['estado'] = 'pendiente'; // completar_tarea() lo cambia y genera la siguiente
    }
    if ($id) {
        $antes = q_uno('SELECT estado FROM tareas WHERE id = ?', [$id]);
        actualizar('tareas', $id, $datos);
        $completando = $completando && ($antes['estado'] ?? '') !== 'completada';
        if (!$completando && ($antes['estado'] ?? '') === 'completada' && entrada('estado') === 'completada') {
            q("UPDATE tareas SET estado = 'completada' WHERE id = ?", [$id]);
        }
        flash('ok', 'Tarea actualizada.');
    } else {
        $datos['creado_por'] = $uid;
        $datos['creado_en'] = ahora();
        $id = insertar('tareas', $datos);
        flash('ok', 'Tarea creada.');
    }
    if ($completando) {
        $nueva = completar_tarea(q_uno('SELECT * FROM tareas WHERE id = ?', [$id]));
        if ($nueva) {
            flash('ok', 'Se creó la próxima tarea recurrente con vencimiento ' . fecha(q_valor('SELECT vencimiento FROM tareas WHERE id = ?', [$nueva])) . '.');
        }
    }
    redirigir(url('tareas', ['a' => 'ver', 'id' => $id]));
}

/* ---------- Cambio rápido de estado ---------- */
if ($accion === 'estado' && es_post() && $id) {
    $t = q_uno('SELECT * FROM tareas WHERE id = ?', [$id]);
    $estado = entrada('estado');
    if ($t && isset(ESTADOS_TAREA[$estado])) {
        if ($estado === 'completada' && $t['estado'] !== 'completada') {
            $nueva = completar_tarea($t);
            flash('ok', 'Tarea completada.' . ($nueva ? ' Se creó la próxima (' . fecha(q_valor('SELECT vencimiento FROM tareas WHERE id = ?', [$nueva])) . ').' : ''));
        } else {
            q('UPDATE tareas SET estado = ?, completada_en = NULL, actualizado_en = ? WHERE id = ?', [$estado, ahora(), $id]);
            flash('ok', 'Estado actualizado: ' . ESTADOS_TAREA[$estado] . '.');
        }
        redirigir(volver_desde_tarea($t));
    }
    redirigir(url('tareas'));
}

/* ---------- Registrar gestión dentro de la tarea ---------- */
if ($accion === 'gestion' && es_post() && $id) {
    $t = q_uno('SELECT * FROM tareas WHERE id = ?', [$id]);
    $detalle = entrada('detalle');
    $tipo = entrada('tipo');
    if ($t && $detalle !== '') {
        insertar('actividades', [
            'tipo'        => isset(TIPOS_ACTIVIDAD[$tipo]) ? $tipo : 'nota',
            'asunto'      => mb_substr(strtok($detalle, "\n"), 0, 200),
            'descripcion' => $detalle,
            'fecha'       => ahora(),
            'completada'  => 1,
            'cliente_id'  => $t['cliente_id'],
            'empresa_id'  => $t['empresa_id'],
            'tarea_id'    => $id,
            'usuario_id'  => $uid,
            'creado_en'   => ahora(),
        ]);
        $nuevoEstado = entrada('nuevo_estado');
        if ($nuevoEstado === 'completada' && $t['estado'] !== 'completada') {
            $nueva = completar_tarea($t);
            flash('ok', 'Gestión registrada y tarea completada.' . ($nueva ? ' Se creó la próxima (' . fecha(q_valor('SELECT vencimiento FROM tareas WHERE id = ?', [$nueva])) . ').' : ''));
        } else {
            if (isset(ESTADOS_TAREA[$nuevoEstado]) && $nuevoEstado !== $t['estado']) {
                q('UPDATE tareas SET estado = ?, actualizado_en = ? WHERE id = ?', [$nuevoEstado, ahora(), $id]);
            } else {
                q('UPDATE tareas SET actualizado_en = ? WHERE id = ?', [ahora(), $id]);
            }
            flash('ok', 'Gestión registrada.');
        }
    }
    redirigir(url('tareas', ['a' => 'ver', 'id' => $id]));
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $t = q_uno('SELECT * FROM tareas WHERE id = ?', [$id]);
    if (!es_admin() && (int)($t['creado_por'] ?? 0) !== $uid && (int)($t['responsable_id'] ?? 0) !== $uid) {
        flash('error', 'Solo el responsable, quien la creó o un administrador puede eliminar la tarea.');
        redirigir(url('tareas', ['a' => 'ver', 'id' => $id]));
    }
    q('UPDATE actividades SET tarea_id = NULL WHERE tarea_id = ?', [$id]);
    q('DELETE FROM tareas WHERE id = ?', [$id]);
    flash('ok', 'Tarea eliminada.');
    redirigir($t && $t['cliente_id'] ? url('clientes', ['a' => 'ver', 'id' => $t['cliente_id']]) : url('tareas'));
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $t = $id ? q_uno('SELECT * FROM tareas WHERE id = ?', [$id]) : [
        'cliente_id'     => entrada_int('cliente_id'),
        'empresa_id'     => entrada_int('empresa_id'),
        'responsable_id' => $uid,
        'estado'         => 'pendiente',
        'prioridad'      => 'normal',
        'recurrencia'    => 'ninguna',
    ];
    if ($id && !$t) {
        redirigir(url('tareas'));
    }
    if (!$id && !$t['cliente_id'] && $t['empresa_id']) {
        $t['cliente_id'] = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$t['empresa_id']]);
    }
    layout_inicio($id ? 'Editar tarea' : 'Nueva tarea', 'tareas');
    ?>
    <h1><?= $id ? 'Editar tarea' : 'Nueva tarea' ?></h1>
    <form method="post" action="<?= e(url('tareas', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div class="completo">
            <?= campo('titulo', 'Tarea *', $t['titulo'] ?? '', 'text', 'required maxlength="200" list="obligaciones" placeholder="Escriba o elija una obligación habitual"') ?>
            <datalist id="obligaciones"><?php foreach (OBLIGACIONES as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist>
        </div>
        <div><?= selector('cliente_id', 'Cliente', opciones_clientes(), $t['cliente_id'] ?? '', 'Sin cliente (tarea interna)') ?></div>
        <div><?= selector_empresa('empresa_id', 'RUT / Empresa', $t['empresa_id'] ?? '', 'Todas / no aplica') ?></div>
        <div><?= selector('responsable_id', 'Responsable', opciones_responsables($t['cliente_id'] ? (int)$t['cliente_id'] : null), $t['responsable_id'] ?? '') ?></div>
        <div><?= campo('vencimiento', 'Vencimiento', $t['vencimiento'] ?? '', 'date') ?></div>
        <div><?= selector('estado', 'Estado', ESTADOS_TAREA, $t['estado'], false) ?></div>
        <div><?= selector('prioridad', 'Prioridad', PRIORIDADES, $t['prioridad'], false) ?></div>
        <div><?= selector('recurrencia', 'Se repite', RECURRENCIAS, $t['recurrencia'], false) ?>
            <small class="tenue">Al completarla se crea automáticamente la siguiente.</small></div>
        <div class="completo"><?= area('descripcion', 'Detalle / instrucciones', $t['descripcion'] ?? '') ?></div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e($id ? url('tareas', ['a' => 'ver', 'id' => $id]) : url('tareas')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Ficha con seguimiento ---------- */
if ($accion === 'ver' && $id) {
    $t = q_uno(
        'SELECT t.*, c.nombre AS cliente, e.nombre AS empresa, e.identificacion AS empresa_rut,
            u.nombre AS responsable, cr.nombre AS creador
         FROM tareas t LEFT JOIN clientes c ON c.id = t.cliente_id LEFT JOIN empresas e ON e.id = t.empresa_id
         LEFT JOIN usuarios u ON u.id = t.responsable_id LEFT JOIN usuarios cr ON cr.id = t.creado_por
         WHERE t.id = ?', [$id]);
    if (!$t) {
        redirigir(url('tareas'));
    }
    $gestiones = q_todos(
        'SELECT a.*, u.nombre AS usuario FROM actividades a LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.tarea_id = ? ORDER BY a.fecha DESC', [$id]);

    layout_inicio($t['titulo'], 'tareas');
    ?>
    <div class="encabezado">
        <div class="titulo">
            <h1><?= e($t['titulo']) ?></h1>
            <p class="tenue"><?= $t['cliente'] ? enlace('clientes', $t['cliente_id'], $t['cliente']) : 'Tarea interna' ?>
                <?php if ($t['empresa']): ?> · <?= enlace('empresas', $t['empresa_id'], $t['empresa'] . ' (' . $t['empresa_rut'] . ')') ?><?php endif; ?></p>
        </div>
        <div>
            <a class="boton" href="<?= e(url('tareas', ['a' => 'form', 'id' => $id])) ?>">Editar</a>
            <?= boton_post(url('tareas', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar esta tarea? Las gestiones registradas se conservan.') ?>
        </div>
    </div>

    <div class="columnas">
        <section class="panel">
            <h2>Detalle</h2>
            <dl class="ficha">
                <dt>Estado</dt><dd><?= badge_tarea($t['estado']) ?><?= $t['estado'] === 'completada' && $t['completada_en'] ? ' <small class="tenue">el ' . e(fecha($t['completada_en'], true)) . '</small>' : '' ?></dd>
                <dt>Vencimiento</dt><dd class="<?= clase_vencimiento($t['vencimiento'], $t['estado']) ?>"><?= e(fecha($t['vencimiento'])) ?: '—' ?></dd>
                <dt>Responsable</dt><dd><?= e($t['responsable']) ?></dd>
                <dt>Prioridad</dt><dd><?= e(PRIORIDADES[$t['prioridad']] ?? '') ?></dd>
                <dt>Se repite</dt><dd><?= e(RECURRENCIAS[$t['recurrencia']] ?? '') ?></dd>
                <dt>Creada por</dt><dd><?= e($t['creador']) ?> · <?= e(fecha($t['creado_en'])) ?></dd>
            </dl>
            <?php if ($t['descripcion']): ?><p class="notas"><?= nl2br(e($t['descripcion'])) ?></p><?php endif; ?>
            <?php if ($t['estado'] !== 'completada'): ?>
            <div class="acciones">
                <?php foreach (ESTADOS_TAREA as $clave => $texto): if ($clave === $t['estado']) continue; ?>
                    <form method="post" action="<?= e(url('tareas', ['a' => 'estado', 'id' => $id])) ?>" class="en-linea">
                        <?= csrf_campo() ?><input type="hidden" name="estado" value="<?= e($clave) ?>">
                        <button type="submit" class="chico <?= $clave === 'completada' ? '' : 'secundario' ?>"><?= $clave === 'completada' ? '✓ Completar' : e($texto) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <?= boton_post(url('tareas', ['a' => 'estado', 'id' => $id]) . '&estado=pendiente', 'Reabrir tarea', 'chico secundario') ?>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Registrar gestión</h2>
            <form method="post" action="<?= e(url('tareas', ['a' => 'gestion', 'id' => $id])) ?>" class="formulario">
                <?= csrf_campo() ?>
                <?= selector('tipo', 'Tipo', TIPOS_ACTIVIDAD, 'tramite', false) ?>
                <?= area('detalle', '¿Qué se hizo? (la primera línea es el resumen)', '') ?>
                <?= selector('nuevo_estado', 'Dejar la tarea en', ESTADOS_TAREA, $t['estado'], false) ?>
                <button type="submit">Registrar</button>
            </form>
        </section>
    </div>

    <section class="panel">
        <h2>Seguimiento (<?= count($gestiones) ?>)</h2>
        <?php if (!$gestiones): ?><p class="vacio">Aún no hay gestiones registradas para esta tarea.</p><?php else: ?>
        <ul class="linea-tiempo">
            <?php foreach ($gestiones as $g): ?>
            <li>
                <span class="badge"><?= e(TIPOS_ACTIVIDAD[$g['tipo']] ?? $g['tipo']) ?></span>
                <a href="<?= e(url('actividades', ['a' => 'form', 'id' => $g['id']])) ?>"><?= e($g['asunto']) ?></a>
                <small class="tenue"><?= e(fecha($g['fecha'], true)) ?> · <?= e($g['usuario'] ?? '') ?></small>
                <?php if ($g['descripcion'] && trim($g['descripcion']) !== trim($g['asunto'])): ?><div class="notas"><?= nl2br(e($g['descripcion'])) ?></div><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['t.titulo', 't.descripcion', 'c.nombre', 'e.nombre', 'e.identificacion'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
$estado = entrada('estado', 'abiertas');
if ($estado === 'abiertas') {
    $condiciones[] = "t.estado <> 'completada'";
} elseif ($estado === 'vencidas') {
    $condiciones[] = "t.estado <> 'completada' AND t.vencimiento < :hoy";
    $params['hoy'] = date('Y-m-d');
} elseif (isset(ESTADOS_TAREA[$estado])) {
    $condiciones[] = 't.estado = :estado';
    $params['estado'] = $estado;
}
$quien = entrada('quien', 'mias');
if ($quien === 'mias') {
    $condiciones[] = 't.responsable_id = :uid';
    $params['uid'] = $uid;
} elseif (ctype_digit($quien)) {
    $condiciones[] = 't.responsable_id = :uid';
    $params['uid'] = (int)$quien;
}
$filtroCliente = entrada_int('cliente_id');
if ($filtroCliente) {
    $condiciones[] = 't.cliente_id = :cliente';
    $params['cliente'] = $filtroCliente;
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$orden = $estado === 'completada' ? 't.completada_en DESC' : 't.vencimiento IS NULL, t.vencimiento, t.id';

$desdeTabla = 'FROM tareas t LEFT JOIN clientes c ON c.id = t.cliente_id LEFT JOIN empresas e ON e.id = t.empresa_id
     LEFT JOIN usuarios u ON u.id = t.responsable_id';

if ($accion === 'csv') {
    $filas = q_todos("SELECT t.vencimiento, t.titulo, c.nombre AS cliente, e.nombre AS empresa, e.identificacion, u.nombre AS responsable,
        t.estado, t.prioridad, t.recurrencia $desdeTabla $where ORDER BY $orden", $params);
    $filas = array_map(static fn($f) => [fecha($f['vencimiento']), $f['titulo'], $f['cliente'], $f['empresa'], $f['identificacion'],
        $f['responsable'], ESTADOS_TAREA[$f['estado']] ?? $f['estado'], PRIORIDADES[$f['prioridad']] ?? '', RECURRENCIAS[$f['recurrencia']] ?? ''], $filas);
    exportar_csv('tareas_' . date('Ymd') . '.csv',
        ['Vencimiento', 'Tarea', 'Cliente', 'Empresa', 'RUT', 'Responsable', 'Estado', 'Prioridad', 'Se repite'], $filas);
}

[$limite, $desde] = paginacion_limites();
$total = (int)q_valor("SELECT COUNT(*) $desdeTabla $where", $params);
$tareas = q_todos(
    "SELECT t.*, c.nombre AS cliente, e.nombre AS empresa, u.nombre AS responsable,
        (SELECT COUNT(*) FROM actividades a WHERE a.tarea_id = t.id) AS n_gestiones
     $desdeTabla $where ORDER BY $orden LIMIT $limite OFFSET $desde",
    $params
);

layout_inicio('Tareas', 'tareas');
?>
<h1>Tareas</h1>
<?php barra_lista('tareas', '+ Nueva tarea', false, [
    selector('estado', '', ['abiertas' => 'Abiertas', 'vencidas' => 'Vencidas'] + ESTADOS_TAREA + ['todas' => 'Todas'], $estado, false, 'aria-label="Estado"'),
    selector('quien', '', ['mias' => 'Mías', 'todos' => 'De todo el equipo'] + opciones_usuarios(), $quien, false, 'aria-label="Responsable"'),
    selector('cliente_id', '', opciones_clientes(), $filtroCliente ?? '', 'Todos los clientes', 'aria-label="Cliente"'),
]); ?>
<p class="derecha"><a href="<?= e(url('tareas', ['a' => 'csv'] + array_intersect_key($_GET, array_flip(['q', 'estado', 'quien', 'cliente_id'])))) ?>">Exportar CSV</a></p>
<table>
    <thead><tr><th>Vence</th><th>Tarea</th><th>Cliente / RUT</th><th>Estado</th><th>Responsable</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tareas as $t): ?>
        <tr class="<?= $t['estado'] === 'completada' ? 'hecha' : clase_vencimiento($t['vencimiento'], $t['estado']) ?>">
            <td class="nowrap"><?= e(fecha($t['vencimiento'])) ?></td>
            <td><a href="<?= e(url('tareas', ['a' => 'ver', 'id' => $t['id']])) ?>"><?= e($t['titulo']) ?></a>
                <?php if ($t['prioridad'] === 'alta'): ?><span class="badge prioridad-alta">Alta</span><?php endif; ?>
                <?php if ($t['recurrencia'] !== 'ninguna'): ?><span class="tenue" title="<?= e(RECURRENCIAS[$t['recurrencia']]) ?>">↻</span><?php endif; ?>
                <?php if ($t['n_gestiones']): ?><small class="tenue"> · <?= (int)$t['n_gestiones'] ?> gestión(es)</small><?php endif; ?></td>
            <td><?= enlace('clientes', $t['cliente_id'], $t['cliente']) ?><?php if ($t['empresa']): ?><br><small class="tenue"><?= e($t['empresa']) ?></small><?php endif; ?></td>
            <td><?= badge_tarea($t['estado']) ?></td>
            <td><?= e($t['responsable']) ?></td>
            <td class="derecha nowrap"><?php if ($t['estado'] !== 'completada'): ?>
                <form method="post" action="<?= e(url('tareas', ['a' => 'estado', 'id' => $t['id'], 'volver' => 'lista'])) ?>" class="en-linea">
                    <?= csrf_campo() ?><input type="hidden" name="estado" value="completada">
                    <button type="submit" class="chico secundario">✓ Hecho</button>
                </form>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$tareas): ?><tr><td colspan="6" class="vacio">No hay tareas con estos filtros.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
