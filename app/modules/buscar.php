<?php
declare(strict_types=1);

/*
 * Buscador general: clientes, RUT/empresas, socios, contactos, tareas y credenciales
 * por nombre o RUT (con o sin puntos y guion).
 */

$q = trim(entrada('q'));
const MAX_POR_GRUPO = 25;

/** Condición LIKE sobre un RUT sin puntos ni guion. */
function sql_rut(string $columna, string $param): string
{
    return "REPLACE(REPLACE($columna, '.', ''), '-', '') LIKE :$param";
}

$resultados = [];
$total = 0;
if (mb_strlen($q) >= 2) {
    $texto = '%' . $q . '%';
    // ¿Parece un RUT? (solo dígitos, puntos, guion y K) → buscar también sin formato
    $soloRut = preg_match('/^[\d.\-kK\s]+$/', $q) && preg_match('/\d{3,}/', $q);
    $rutLimpio = '%' . strtoupper(preg_replace('/[^0-9kK]/', '', $q)) . '%';
    $condRut = static fn(string $col, string $p) => $soloRut ? ' OR ' . sql_rut($col, $p) : '';
    $p = static fn(array $extra = []) => ['t' => $texto] + ($soloRut ? ['r' => $rutLimpio] : []) + $extra;

    $resultados['clientes'] = q_todos(
        "SELECT c.id, c.nombre, c.rut, c.categoria, c.situacion, c.activo FROM clientes c
         WHERE c.nombre LIKE :t OR c.rut LIKE :t2" . $condRut('c.rut', 'r') . "
         ORDER BY c.activo DESC, c.nombre LIMIT " . MAX_POR_GRUPO, $p(['t2' => $texto]));

    $resultados['empresas'] = q_todos(
        "SELECT e.id, e.nombre, e.identificacion, e.cliente_id, c.nombre AS cliente FROM empresas e
         LEFT JOIN clientes c ON c.id = e.cliente_id
         WHERE e.nombre LIKE :t OR e.identificacion LIKE :t2" . $condRut('e.identificacion', 'r') . "
         ORDER BY e.nombre LIMIT " . MAX_POR_GRUPO, $p(['t2' => $texto]));

    $resultados['socios'] = q_todos(
        "SELECT s.id, s.nombre, s.rut, s.porcentaje, s.representante, s.notas, s.empresa_id, s.socio_empresa_id,
            e.nombre AS empresa, e.identificacion AS empresa_rut, e.cliente_id
         FROM socios s JOIN empresas e ON e.id = s.empresa_id
         WHERE s.nombre LIKE :t OR s.rut LIKE :t2" . $condRut('s.rut', 'r') . "
         ORDER BY s.nombre, e.nombre LIMIT " . MAX_POR_GRUPO, $p(['t2' => $texto]));

    $resultados['contactos'] = q_todos(
        "SELECT ct.id, ct.nombre, ct.apellido, ct.cargo, ct.email, ct.telefono, ct.movil, e.nombre AS empresa, e.id AS empresa_id
         FROM contactos ct LEFT JOIN empresas e ON e.id = ct.empresa_id
         WHERE ct.nombre LIKE :t OR ct.apellido LIKE :t2 OR ct.email LIKE :t3 OR "
            . (db_driver() === 'sqlite' ? "(ct.nombre || ' ' || COALESCE(ct.apellido, ''))" : "CONCAT(ct.nombre, ' ', COALESCE(ct.apellido, ''))") . " LIKE :t4
         ORDER BY ct.nombre LIMIT " . MAX_POR_GRUPO, ['t' => $texto, 't2' => $texto, 't3' => $texto, 't4' => $texto]);

    $resultados['tareas'] = q_todos(
        "SELECT t.id, t.titulo, t.estado, t.vencimiento, c.nombre AS cliente, c.id AS cliente_id FROM tareas t
         LEFT JOIN clientes c ON c.id = t.cliente_id LEFT JOIN empresas e ON e.id = t.empresa_id
         WHERE t.estado <> 'completada' AND (t.titulo LIKE :t OR c.nombre LIKE :t2 OR e.identificacion LIKE :t3"
            . $condRut('e.identificacion', 'r') . ")
         ORDER BY t.vencimiento IS NULL, t.vencimiento LIMIT " . MAX_POR_GRUPO, $p(['t2' => $texto, 't3' => $texto]));

    // Credenciales: de los clientes y RUT encontrados, de los RUT de los socios encontrados,
    // y las que usan el RUT buscado como usuario (típico en SII).
    if (puede_ver_credenciales()) {
        $clientes = array_column($resultados['clientes'], 'id');
        $empresas = array_column($resultados['empresas'], 'id');
        $rutsSocios = array_values(array_filter(array_unique(array_column($resultados['socios'], 'rut'))));
        if ($rutsSocios) {
            $marcas = implode(',', array_fill(0, count($rutsSocios), '?'));
            foreach (q_todos("SELECT id, cliente_id FROM empresas WHERE identificacion IN ($marcas)", $rutsSocios) as $e) {
                $empresas[] = (int)$e['id'];
                if ($e['cliente_id']) {
                    $clientes[] = (int)$e['cliente_id'];
                }
            }
            foreach (q_todos("SELECT id FROM clientes WHERE rut IN ($marcas)", $rutsSocios) as $c) {
                $clientes[] = (int)$c['id'];
            }
        }
        $condiciones = ['k.usuario LIKE ?', 'k.institucion LIKE ?'];
        $params = [$texto, $texto];
        if ($soloRut) {
            $condiciones[] = "REPLACE(REPLACE(k.usuario, '.', ''), '-', '') LIKE ?";
            $params[] = $rutLimpio;
        }
        foreach (['k.cliente_id' => array_unique($clientes), 'k.empresa_id' => array_unique($empresas)] as $col => $ids) {
            if ($ids) {
                $condiciones[] = "$col IN (" . implode(',', array_map('intval', $ids)) . ')';
            }
        }
        $resultados['credenciales'] = q_todos(
            'SELECT k.id, k.institucion, k.usuario, k.url, k.clave_cifrada IS NOT NULL AS tiene_clave,
                c.nombre AS cliente, c.id AS cliente_id, e.nombre AS empresa, e.identificacion AS rut
             FROM credenciales k LEFT JOIN clientes c ON c.id = k.cliente_id LEFT JOIN empresas e ON e.id = k.empresa_id
             WHERE ' . implode(' OR ', $condiciones) . '
             ORDER BY c.nombre, k.institucion LIMIT 60', $params);
    }
    $total = array_sum(array_map('count', $resultados));
}

layout_inicio($q !== '' ? "Buscar: $q" : 'Buscar', 'buscar');
?>
<form method="get" class="barra-lista buscador-grande">
    <input type="hidden" name="r" value="buscar">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre o RUT de cliente, empresa, socio, contacto…" autofocus aria-label="Buscar">
    <button type="submit">Buscar</button>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
    <p class="vacio">Escriba al menos 2 caracteres.</p>
<?php elseif ($q !== '' && !$total): ?>
    <p class="vacio">No se encontró nada para «<?= e($q) ?>».</p>
<?php endif; ?>

<?php if (!empty($resultados['credenciales'])): ?>
<section class="panel">
    <h2>Credenciales (<?= count($resultados['credenciales']) ?>)</h2>
    <table><tbody>
    <?php foreach ($resultados['credenciales'] as $k): ?>
        <tr>
            <td><a href="<?= e(url('credenciales', ['a' => 'ver', 'id' => $k['id']])) ?>"><strong><?= e($k['institucion']) ?></strong></a>
                <br><small class="tenue"><?= e($k['cliente']) ?><?= $k['empresa'] && $k['empresa'] !== $k['cliente'] ? ' · ' . e($k['empresa']) : '' ?></small></td>
            <td class="nowrap"><span id="u<?= (int)$k['id'] ?>"><?= e($k['usuario']) ?></span>
                <?php if ($k['usuario']): ?><button type="button" class="chico secundario" data-copiar="u<?= (int)$k['id'] ?>">Copiar</button><?php endif; ?></td>
            <td class="revelar-en-linea" data-credencial="<?= (int)$k['id'] ?>">
                <?php if ($k['tiene_clave']): ?><button type="button" class="chico" data-revelar>Mostrar clave</button><?php else: ?><span class="tenue">Sin clave</span><?php endif; ?>
            </td>
            <td class="derecha"><?php if ($k['url']): ?><a href="<?= e($k['url']) ?>" target="_blank" rel="noopener noreferrer">Abrir sitio ↗</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
</section>
<?php elseif ($total && !puede_ver_credenciales()): ?>
    <p class="tenue">Las credenciales no se muestran: su usuario no tiene permiso de credenciales.</p>
<?php endif; ?>

<div class="columnas">
<?php if (!empty($resultados['clientes'])): ?>
<section class="panel">
    <h2>Clientes (<?= count($resultados['clientes']) ?>)</h2>
    <table><tbody>
    <?php foreach ($resultados['clientes'] as $c): ?>
        <tr class="<?= $c['activo'] ? '' : 'hecha' ?>"><td><?= enlace('clientes', $c['id'], $c['nombre']) ?>
            <?php if ($c['categoria']): ?><br><small class="tenue"><?= e($c['categoria']) ?></small><?php endif; ?></td>
            <td class="nowrap"><?= e($c['rut']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</section>
<?php endif; ?>

<?php if (!empty($resultados['empresas'])): ?>
<section class="panel">
    <h2>Empresas / RUT (<?= count($resultados['empresas']) ?>)</h2>
    <table><tbody>
    <?php foreach ($resultados['empresas'] as $em): ?>
        <tr><td><?= enlace('empresas', $em['id'], $em['nombre']) ?>
            <?php if ($em['cliente'] && $em['cliente'] !== $em['nombre']): ?><br><small class="tenue">Cliente: <?= e($em['cliente']) ?></small><?php endif; ?></td>
            <td class="nowrap"><?= e($em['identificacion']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</section>
<?php endif; ?>
</div>

<?php if (!empty($resultados['socios'])): ?>
<section class="panel">
    <h2>Socios y representantes (<?= count($resultados['socios']) ?>)</h2>
    <table>
        <thead><tr><th>Socio</th><th>RUT</th><th>Empresa</th><th class="derecha">%</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($resultados['socios'] as $s): ?>
            <tr>
                <td><?= $s['socio_empresa_id'] ? enlace('empresas', $s['socio_empresa_id'], $s['nombre']) : e($s['nombre']) ?>
                    <?php if ($s['representante']): ?><span class="badge tarea-en_proceso">Rep. legal</span><?php endif; ?>
                    <?php if ($s['notas'] && strpos($s['notas'], 'Rol por definir') !== false): ?><span class="badge">Rol por definir</span><?php endif; ?></td>
                <td class="nowrap"><?= e($s['rut']) ?></td>
                <td><?= enlace('empresas', $s['empresa_id'], $s['empresa']) ?><br><small class="tenue"><?= e($s['empresa_rut']) ?></small></td>
                <td class="derecha"><?= $s['porcentaje'] !== null ? e(numero_corto($s['porcentaje'])) : '' ?></td>
                <td class="derecha nowrap"><?php if ($s['rut']): ?><a href="<?= e(url('empresas', ['a' => 'participaciones', 'rut' => $s['rut']])) ?>">Todas sus empresas →</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<div class="columnas">
<?php if (!empty($resultados['contactos'])): ?>
<section class="panel">
    <h2>Contactos (<?= count($resultados['contactos']) ?>)</h2>
    <table><tbody>
    <?php foreach ($resultados['contactos'] as $ct): ?>
        <tr><td><?= enlace('contactos', $ct['id'], trim($ct['nombre'] . ' ' . $ct['apellido'])) ?>
            <br><small class="tenue"><?= e(implode(' · ', array_filter([$ct['cargo'], $ct['empresa']]))) ?></small></td>
            <td><?= e($ct['email']) ?><br><small><?= e($ct['movil'] ?: $ct['telefono']) ?></small></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</section>
<?php endif; ?>

<?php if (!empty($resultados['tareas'])): ?>
<section class="panel">
    <h2>Tareas abiertas (<?= count($resultados['tareas']) ?>)</h2>
    <table><tbody>
    <?php foreach ($resultados['tareas'] as $t): ?>
        <tr class="<?= clase_vencimiento($t['vencimiento'], $t['estado']) ?>">
            <td class="nowrap"><?= e(fecha($t['vencimiento'])) ?></td>
            <td><?= enlace('tareas', $t['id'], $t['titulo']) ?><br><small class="tenue"><?= e($t['cliente']) ?></small></td>
            <td><?= badge_tarea($t['estado']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</section>
<?php endif; ?>
</div>

<?php if ($total): ?><p class="tenue">Se muestran hasta <?= MAX_POR_GRUPO ?> resultados por grupo; escriba más letras para acotar.</p><?php endif; ?>
<template id="plantilla-pedir-clave">
    <form class="revelar-clave">
        <input type="password" required autocomplete="current-password" placeholder="Su contraseña del CRM" aria-label="Su contraseña del CRM">
        <button type="submit" class="chico">Ver</button>
    </form>
</template>
<?php
layout_fin();
