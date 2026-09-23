<?php
declare(strict_types=1);

$id = entrada_int('id');

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $datos = [
        'nombre'         => entrada('nombre'),
        'identificacion' => nulo_si_vacio(entrada('identificacion')),
        'sector'         => nulo_si_vacio(entrada('sector')),
        'telefono'       => nulo_si_vacio(entrada('telefono')),
        'email'          => nulo_si_vacio(entrada('email')),
        'sitio_web'      => nulo_si_vacio(entrada('sitio_web')),
        'direccion'      => nulo_si_vacio(entrada('direccion')),
        'ciudad'         => nulo_si_vacio(entrada('ciudad')),
        'notas'          => nulo_si_vacio(entrada('notas')),
        'responsable_id' => entrada_int('responsable_id'),
        'actualizado_en' => ahora(),
    ];
    if ($datos['nombre'] === '') {
        flash('error', 'El nombre de la empresa es obligatorio.');
        redirigir(url('empresas', ['a' => 'form', 'id' => $id]));
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
    redirigir(url('empresas'));
}

/* ---------- Filtro común para lista y CSV ---------- */
$busqueda = entrada('q');
[$where, $params] = filtro_busqueda(['e.nombre', 'e.identificacion', 'e.ciudad', 'e.sector', 'e.email'], $busqueda);
$where = $where ? "WHERE $where" : '';

if ($accion === 'csv') {
    $filas = q_todos(
        "SELECT e.nombre, e.identificacion, e.sector, e.telefono, e.email, e.sitio_web, e.direccion, e.ciudad, u.nombre AS responsable
         FROM empresas e LEFT JOIN usuarios u ON u.id = e.responsable_id $where ORDER BY e.nombre",
        $params
    );
    exportar_csv('empresas_' . date('Ymd') . '.csv',
        ['Nombre', 'Identificación', 'Sector', 'Teléfono', 'Correo', 'Sitio web', 'Dirección', 'Ciudad', 'Responsable'],
        array_map('array_values', $filas));
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $e = $id ? q_uno('SELECT * FROM empresas WHERE id = ?', [$id]) : ['responsable_id' => usuario_actual()['id']];
    if ($id && !$e) {
        redirigir(url('empresas'));
    }
    layout_inicio($id ? 'Editar empresa' : 'Nueva empresa', 'empresas');
    ?>
    <h1><?= $id ? 'Editar empresa' : 'Nueva empresa' ?></h1>
    <form method="post" action="<?= e(url('empresas', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <div><?= campo('nombre', 'Nombre / Razón social *', $e['nombre'] ?? '', 'text', 'required maxlength="150"') ?></div>
        <div><?= campo('identificacion', 'Identificación fiscal (RUT, NIT, RFC…)', $e['identificacion'] ?? '') ?></div>
        <div><?= campo('sector', 'Sector / Industria', $e['sector'] ?? '') ?></div>
        <div><?= campo('telefono', 'Teléfono', $e['telefono'] ?? '', 'tel') ?></div>
        <div><?= campo('email', 'Correo', $e['email'] ?? '', 'email') ?></div>
        <div><?= campo('sitio_web', 'Sitio web', $e['sitio_web'] ?? '', 'url', 'placeholder="https://"') ?></div>
        <div><?= campo('direccion', 'Dirección', $e['direccion'] ?? '') ?></div>
        <div><?= campo('ciudad', 'Ciudad', $e['ciudad'] ?? '') ?></div>
        <div><?= selector('responsable_id', 'Responsable', opciones_usuarios(), $e['responsable_id'] ?? '') ?></div>
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
    $e = q_uno('SELECT e.*, u.nombre AS responsable FROM empresas e LEFT JOIN usuarios u ON u.id = e.responsable_id WHERE e.id = ?', [$id]);
    if (!$e) {
        redirigir(url('empresas'));
    }
    $contactos = q_todos('SELECT * FROM contactos WHERE empresa_id = ? ORDER BY nombre', [$id]);
    $oportunidades = q_todos('SELECT * FROM oportunidades WHERE empresa_id = ? ORDER BY actualizado_en DESC', [$id]);
    $actividades = q_todos(
        'SELECT a.*, u.nombre AS usuario FROM actividades a LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.empresa_id = ? ORDER BY a.fecha DESC LIMIT 30', [$id]);

    layout_inicio($e['nombre'], 'empresas');
    ?>
    <div class="encabezado">
        <h1><?= e($e['nombre']) ?></h1>
        <div>
            <a class="boton" href="<?= e(url('empresas', ['a' => 'form', 'id' => $id])) ?>">Editar</a>
            <?php if (puede_eliminar($e)) echo boton_post(url('empresas', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar la empresa? Sus contactos, oportunidades y actividades se conservarán sin empresa asignada.'); ?>
        </div>
    </div>
    <div class="columnas">
        <section class="panel">
            <h2>Datos</h2>
            <dl class="ficha">
                <dt>Identificación</dt><dd><?= e($e['identificacion']) ?></dd>
                <dt>Sector</dt><dd><?= e($e['sector']) ?></dd>
                <dt>Teléfono</dt><dd><?= e($e['telefono']) ?></dd>
                <dt>Correo</dt><dd><?php if ($e['email']): ?><a href="mailto:<?= e($e['email']) ?>"><?= e($e['email']) ?></a><?php endif; ?></dd>
                <dt>Sitio web</dt><dd><?php if ($e['sitio_web'] && preg_match('#^https?://#i', $e['sitio_web'])): ?><a href="<?= e($e['sitio_web']) ?>" target="_blank" rel="noopener"><?= e($e['sitio_web']) ?></a><?php else: ?><?= e($e['sitio_web']) ?><?php endif; ?></dd>
                <dt>Dirección</dt><dd><?= e(trim(($e['direccion'] ?? '') . ', ' . ($e['ciudad'] ?? ''), ', ')) ?></dd>
                <dt>Responsable</dt><dd><?= e($e['responsable']) ?></dd>
            </dl>
            <?php if ($e['notas']): ?><p class="notas"><?= nl2br(e($e['notas'])) ?></p><?php endif; ?>
        </section>
        <section class="panel">
            <div class="encabezado"><h2>Contactos</h2><a href="<?= e(url('contactos', ['a' => 'form', 'empresa_id' => $id])) ?>">+ Agregar</a></div>
            <?php if (!$contactos): ?><p class="vacio">Sin contactos.</p><?php else: ?>
            <table><tbody>
            <?php foreach ($contactos as $c): ?>
                <tr>
                    <td><a href="<?= e(url('contactos', ['a' => 'ver', 'id' => $c['id']])) ?>"><?= e($c['nombre'] . ' ' . $c['apellido']) ?></a><br><small class="tenue"><?= e($c['cargo']) ?></small></td>
                    <td><?= e($c['email']) ?><br><small><?= e($c['telefono']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </section>
    </div>
    <section class="panel">
        <div class="encabezado"><h2>Oportunidades</h2><a href="<?= e(url('oportunidades', ['a' => 'form', 'empresa_id' => $id])) ?>">+ Agregar</a></div>
        <?php if (!$oportunidades): ?><p class="vacio">Sin oportunidades.</p><?php else: ?>
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
        <?php endif; ?>
    </section>
    <?php
    historial_actividades($actividades, ['empresa_id' => $id]);
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
[$limite, $desde] = paginacion_limites();
$total = (int)q_valor("SELECT COUNT(*) FROM empresas e $where", $params);
$empresas = q_todos(
    "SELECT e.*, u.nombre AS responsable,
        (SELECT COUNT(*) FROM contactos c WHERE c.empresa_id = e.id) AS n_contactos
     FROM empresas e LEFT JOIN usuarios u ON u.id = e.responsable_id
     $where ORDER BY e.nombre LIMIT $limite OFFSET $desde",
    $params
);

layout_inicio('Empresas', 'empresas');
?>
<h1>Empresas</h1>
<?php barra_lista('empresas', '+ Nueva empresa', true); ?>
<table>
    <thead><tr><th>Nombre</th><th>Sector</th><th>Ciudad</th><th>Teléfono</th><th>Contactos</th><th>Responsable</th></tr></thead>
    <tbody>
    <?php foreach ($empresas as $e): ?>
        <tr>
            <td><a href="<?= e(url('empresas', ['a' => 'ver', 'id' => $e['id']])) ?>"><?= e($e['nombre']) ?></a></td>
            <td><?= e($e['sector']) ?></td>
            <td><?= e($e['ciudad']) ?></td>
            <td><?= e($e['telefono']) ?></td>
            <td><?= (int)$e['n_contactos'] ?></td>
            <td><?= e($e['responsable']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$empresas): ?><tr><td colspan="6" class="vacio">No se encontraron empresas.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
