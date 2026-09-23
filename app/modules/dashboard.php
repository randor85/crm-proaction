<?php
declare(strict_types=1);

$uid = (int)usuario_actual()['id'];
$hoy = date('Y-m-d');
$en7dias = date('Y-m-d 23:59:59', strtotime('+7 days'));

$totales = [
    'Empresas'  => (int)q_valor('SELECT COUNT(*) FROM empresas'),
    'Contactos' => (int)q_valor('SELECT COUNT(*) FROM contactos'),
    'Oportunidades abiertas' => (int)q_valor("SELECT COUNT(*) FROM oportunidades WHERE etapa NOT IN ('ganada','perdida')"),
];

$pipeline = q_todos(
    "SELECT etapa, COUNT(*) AS n, COALESCE(SUM(monto), 0) AS total
     FROM oportunidades GROUP BY etapa"
);
$porEtapa = array_column($pipeline, null, 'etapa');
$abierto = 0;
foreach ($pipeline as $p) {
    if (!in_array($p['etapa'], ['ganada', 'perdida'], true)) {
        $abierto += (float)$p['total'];
    }
}

$ganadoMes = (float)q_valor(
    "SELECT COALESCE(SUM(monto), 0) FROM oportunidades WHERE etapa = 'ganada' AND actualizado_en >= ?",
    [date('Y-m-01 00:00:00')]
);

$vencidas = q_todos(
    'SELECT a.*, e.nombre AS empresa FROM actividades a
     LEFT JOIN empresas e ON e.id = a.empresa_id
     WHERE a.usuario_id = ? AND a.completada = 0 AND a.fecha < ?
     ORDER BY a.fecha LIMIT 10',
    [$uid, $hoy . ' 00:00:00']
);

$proximas = q_todos(
    'SELECT a.*, e.nombre AS empresa FROM actividades a
     LEFT JOIN empresas e ON e.id = a.empresa_id
     WHERE a.usuario_id = ? AND a.completada = 0 AND a.fecha >= ? AND a.fecha <= ?
     ORDER BY a.fecha LIMIT 10',
    [$uid, $hoy . ' 00:00:00', $en7dias]
);

$recientes = q_todos(
    'SELECT o.*, e.nombre AS empresa FROM oportunidades o
     LEFT JOIN empresas e ON e.id = o.empresa_id
     ORDER BY o.actualizado_en DESC LIMIT 8'
);

function tabla_actividades(array $filas, string $vacio): void
{
    if (!$filas) {
        echo '<p class="vacio">' . e($vacio) . '</p>';
        return;
    }
    echo '<table><tbody>';
    foreach ($filas as $a) {
        echo '<tr><td class="nowrap">' . e(fecha($a['fecha'], true)) . '</td>'
            . '<td><a href="' . e(url('actividades', ['a' => 'form', 'id' => $a['id']])) . '">' . e($a['asunto']) . '</a>'
            . ' <small class="tenue">' . e(TIPOS_ACTIVIDAD[$a['tipo']] ?? '') . ($a['empresa'] ? ' · ' . e($a['empresa']) : '') . '</small></td>'
            . '<td class="derecha">' . boton_post(url('actividades', ['a' => 'completar', 'id' => $a['id'], 'volver' => 'dashboard']), '✓ Hecho', 'chico secundario') . '</td></tr>';
    }
    echo '</tbody></table>';
}

layout_inicio('Inicio', 'dashboard');
?>
<h1>Hola, <?= e(usuario_actual()['nombre']) ?></h1>

<section class="tarjetas">
    <?php foreach ($totales as $texto => $n): ?>
        <div class="tarjeta"><span class="cifra"><?= $n ?></span><span><?= e($texto) ?></span></div>
    <?php endforeach; ?>
    <div class="tarjeta"><span class="cifra"><?= e(dinero($abierto)) ?></span><span>Monto en curso</span></div>
    <div class="tarjeta"><span class="cifra"><?= e(dinero($ganadoMes)) ?></span><span>Ganado este mes</span></div>
</section>

<section class="panel">
    <h2>Embudo de ventas</h2>
    <div class="embudo">
        <?php
        $max = max(1, ...array_map(static fn($p) => (int)$p['n'], $pipeline ?: [['n' => 1]]));
        foreach (ETAPAS as $clave => $nombre):
            $n = (int)($porEtapa[$clave]['n'] ?? 0);
            $total = (float)($porEtapa[$clave]['total'] ?? 0);
        ?>
            <a class="fila-embudo" href="<?= e(url('oportunidades', ['etapa' => $clave])) ?>">
                <span class="etiqueta"><?= e($nombre) ?></span>
                <span class="barra-embudo"><span class="relleno etapa-<?= e($clave) ?>" style="width: <?= round($n / $max * 100) ?>%"></span></span>
                <span class="valor"><?= $n ?> · <?= e(dinero($total)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<div class="columnas">
    <section class="panel">
        <h2>Mis actividades vencidas</h2>
        <?php tabla_actividades($vencidas, 'No tiene actividades vencidas.'); ?>
    </section>
    <section class="panel">
        <h2>Mis próximos 7 días</h2>
        <?php tabla_actividades($proximas, 'Nada agendado para los próximos días.'); ?>
        <p><a href="<?= e(url('actividades', ['a' => 'form'])) ?>">+ Nueva actividad</a></p>
    </section>
</div>

<section class="panel">
    <h2>Oportunidades actualizadas recientemente</h2>
    <?php if (!$recientes): ?>
        <p class="vacio">Aún no hay oportunidades. <a href="<?= e(url('oportunidades', ['a' => 'form'])) ?>">Cree la primera</a>.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Oportunidad</th><th>Empresa</th><th>Etapa</th><th class="derecha">Monto</th></tr></thead>
        <tbody>
        <?php foreach ($recientes as $o): ?>
            <tr>
                <td><a href="<?= e(url('oportunidades', ['a' => 'ver', 'id' => $o['id']])) ?>"><?= e($o['titulo']) ?></a></td>
                <td><?= e($o['empresa']) ?></td>
                <td><?= badge_etapa($o['etapa']) ?></td>
                <td class="derecha"><?= e(dinero($o['monto'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php
layout_fin();
