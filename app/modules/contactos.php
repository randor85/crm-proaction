<?php
declare(strict_types=1);

$id = entrada_int('id');

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $datos = [
        'empresa_id'     => entrada_int('empresa_id'),
        'nombre'         => entrada('nombre'),
        'apellido'       => nulo_si_vacio(entrada('apellido')),
        'cargo'          => nulo_si_vacio(entrada('cargo')),
        'email'          => nulo_si_vacio(entrada('email')),
        'telefono'       => nulo_si_vacio(entrada('telefono')),
        'movil'          => nulo_si_vacio(entrada('movil')),
        'notas'          => nulo_si_vacio(entrada('notas')),
        'responsable_id' => entrada_int('responsable_id'),
        'actualizado_en' => ahora(),
    ];
    if ($datos['nombre'] === '') {
        flash('error', 'El nombre del contacto es obligatorio.');
        redirigir(url('contactos', ['a' => 'form', 'id' => $id]));
    }
    if ($id) {
        actualizar('contactos', $id, $datos);
        flash('ok', 'Contacto actualizado.');
    } else {
        $datos['creado_en'] = ahora();
        $id = insertar('contactos', $datos);
        flash('ok', 'Contacto creado.');
    }
    redirigir(url('contactos', ['a' => 'ver', 'id' => $id]));
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $contacto = q_uno('SELECT * FROM contactos WHERE id = ?', [$id]);
    if (!puede_eliminar($contacto)) {
        flash('error', 'Solo el responsable o un administrador puede eliminar este contacto.');
        redirigir(url('contactos', ['a' => 'ver', 'id' => $id]));
    }
    q('DELETE FROM contactos WHERE id = ?', [$id]);
    flash('ok', 'Contacto eliminado.');
    redirigir(url('contactos'));
}

/* ---------- Filtro común para lista y CSV ---------- */
$busqueda = entrada('q');
[$where, $params] = filtro_busqueda(['c.nombre', 'c.apellido', 'c.email', 'c.cargo', 'e.nombre'], $busqueda);
$where = $where ? "WHERE $where" : '';

if ($accion === 'csv') {
    $filas = q_todos(
        "SELECT c.nombre, c.apellido, c.cargo, e.nombre AS empresa, c.email, c.telefono, c.movil, u.nombre AS responsable
         FROM contactos c
         LEFT JOIN empresas e ON e.id = c.empresa_id
         LEFT JOIN usuarios u ON u.id = c.responsable_id
         $where ORDER BY c.nombre, c.apellido",
        $params
    );
    exportar_csv('contactos_' . date('Ymd') . '.csv',
        ['Nombre', 'Apellido', 'Cargo', 'Empresa', 'Correo', 'Teléfono', 'Móvil', 'Responsable'],
        array_map('array_values', $filas));
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $c = $id
        ? q_uno('SELECT * FROM contactos WHERE id = ?', [$id])
        : ['empresa_id' => entrada_int('empresa_id'), 'responsable_id' => usuario_actual()['id']];
    if ($id && !$c) {
        redirigir(url('contactos'));
    }
    layout_inicio($id ? 'Editar contacto' : 'Nuevo contacto', 'contactos');
    ?>
    <h1><?= $id ? 'Editar contacto' : 'Nuevo contacto' ?></h1>
    <form method="post" action="<?= e(url('contactos', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div><?= campo('nombre', 'Nombre *', $c['nombre'] ?? '', 'text', 'required maxlength="100"') ?></div>
        <div><?= campo('apellido', 'Apellido', $c['apellido'] ?? '') ?></div>
        <div><?= selector('empresa_id', 'Empresa', opciones_empresas(), $c['empresa_id'] ?? '') ?></div>
        <div><?= campo('cargo', 'Cargo', $c['cargo'] ?? '') ?></div>
        <div><?= campo('email', 'Correo', $c['email'] ?? '', 'email') ?></div>
        <div><?= campo('telefono', 'Teléfono', $c['telefono'] ?? '', 'tel') ?></div>
        <div><?= campo('movil', 'Móvil', $c['movil'] ?? '', 'tel') ?></div>
        <div><?= selector('responsable_id', 'Responsable', opciones_usuarios(), $c['responsable_id'] ?? '') ?></div>
        <div class="completo"><?= area('notas', 'Notas', $c['notas'] ?? '') ?></div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e($id ? url('contactos', ['a' => 'ver', 'id' => $id]) : url('contactos')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Ficha ---------- */
if ($accion === 'ver' && $id) {
    $c = q_uno(
        'SELECT c.*, e.nombre AS empresa, u.nombre AS responsable
         FROM contactos c
         LEFT JOIN empresas e ON e.id = c.empresa_id
         LEFT JOIN usuarios u ON u.id = c.responsable_id
         WHERE c.id = ?', [$id]);
    if (!$c) {
        redirigir(url('contactos'));
    }
    $oportunidades = q_todos('SELECT * FROM oportunidades WHERE contacto_id = ? ORDER BY actualizado_en DESC', [$id]);
    $actividades = q_todos(
        'SELECT a.*, u.nombre AS usuario FROM actividades a LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.contacto_id = ? ORDER BY a.fecha DESC LIMIT 30', [$id]);

    $nombreCompleto = trim($c['nombre'] . ' ' . $c['apellido']);
    layout_inicio($nombreCompleto, 'contactos');
    ?>
    <div class="encabezado">
        <h1><?= e($nombreCompleto) ?></h1>
        <div>
            <a class="boton" href="<?= e(url('contactos', ['a' => 'form', 'id' => $id])) ?>">Editar</a>
            <?php if (puede_eliminar($c)) echo boton_post(url('contactos', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar este contacto?'); ?>
        </div>
    </div>
    <div class="columnas">
        <section class="panel">
            <h2>Datos</h2>
            <dl class="ficha">
                <dt>Empresa</dt><dd><?php if ($c['empresa_id']): ?><a href="<?= e(url('empresas', ['a' => 'ver', 'id' => $c['empresa_id']])) ?>"><?= e($c['empresa']) ?></a><?php endif; ?></dd>
                <dt>Cargo</dt><dd><?= e($c['cargo']) ?></dd>
                <dt>Correo</dt><dd><?php if ($c['email']): ?><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a><?php endif; ?></dd>
                <dt>Teléfono</dt><dd><?= e($c['telefono']) ?></dd>
                <dt>Móvil</dt><dd><?= e($c['movil']) ?></dd>
                <dt>Responsable</dt><dd><?= e($c['responsable']) ?></dd>
            </dl>
            <?php if ($c['notas']): ?><p class="notas"><?= nl2br(e($c['notas'])) ?></p><?php endif; ?>
        </section>
        <section class="panel">
            <div class="encabezado"><h2>Oportunidades</h2><a href="<?= e(url('oportunidades', ['a' => 'form', 'contacto_id' => $id, 'empresa_id' => $c['empresa_id']])) ?>">+ Agregar</a></div>
            <?php if (!$oportunidades): ?><p class="vacio">Sin oportunidades.</p><?php else: ?>
            <table><tbody>
            <?php foreach ($oportunidades as $o): ?>
                <tr>
                    <td><a href="<?= e(url('oportunidades', ['a' => 'ver', 'id' => $o['id']])) ?>"><?= e($o['titulo']) ?></a></td>
                    <td><?= badge_etapa($o['etapa']) ?></td>
                    <td class="derecha"><?= e(dinero($o['monto'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </section>
    </div>
    <?php
    historial_actividades($actividades, ['contacto_id' => $id, 'empresa_id' => $c['empresa_id']]);
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
[$limite, $desde] = paginacion_limites();
$total = (int)q_valor("SELECT COUNT(*) FROM contactos c LEFT JOIN empresas e ON e.id = c.empresa_id $where", $params);
$contactos = q_todos(
    "SELECT c.*, e.nombre AS empresa
     FROM contactos c LEFT JOIN empresas e ON e.id = c.empresa_id
     $where ORDER BY c.nombre, c.apellido LIMIT $limite OFFSET $desde",
    $params
);

layout_inicio('Contactos', 'contactos');
?>
<h1>Contactos</h1>
<?php barra_lista('contactos', '+ Nuevo contacto', true); ?>
<table>
    <thead><tr><th>Nombre</th><th>Empresa</th><th>Cargo</th><th>Correo</th><th>Teléfono</th></tr></thead>
    <tbody>
    <?php foreach ($contactos as $c): ?>
        <tr>
            <td><a href="<?= e(url('contactos', ['a' => 'ver', 'id' => $c['id']])) ?>"><?= e(trim($c['nombre'] . ' ' . $c['apellido'])) ?></a></td>
            <td><?= e($c['empresa']) ?></td>
            <td><?= e($c['cargo']) ?></td>
            <td><?= e($c['email']) ?></td>
            <td><?= e($c['telefono'] ?: $c['movil']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$contactos): ?><tr><td colspan="5" class="vacio">No se encontraron contactos.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
