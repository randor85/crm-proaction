<?php
declare(strict_types=1);

$uid = (int)usuario_actual()['id'];
$hoy = date('Y-m-d');
$en7dias = date('Y-m-d', strtotime('+7 days'));

$ufHoy = indicador('uf');
$utm = indicador('utm');

$sqlTareas = "SELECT t.*, c.nombre AS cliente, e.nombre AS empresa FROM tareas t
     LEFT JOIN clientes c ON c.id = t.cliente_id LEFT JOIN empresas e ON e.id = t.empresa_id
     WHERE t.responsable_id = ? AND t.estado <> 'completada'";
$vencidas = q_todos("$sqlTareas AND t.vencimiento < ? ORDER BY t.vencimiento LIMIT 15", [$uid, $hoy]);
$proximas = q_todos("$sqlTareas AND t.vencimiento >= ? AND t.vencimiento <= ? ORDER BY t.vencimiento, t.prioridad = 'alta' DESC LIMIT 15", [$uid, $hoy, $en7dias]);
$esperando = (int)q_valor("SELECT COUNT(*) FROM tareas WHERE responsable_id = ? AND estado = 'esperando'", [$uid]);
$abiertas = (int)q_valor("SELECT COUNT(*) FROM tareas WHERE responsable_id = ? AND estado <> 'completada'", [$uid]);
$vencidasEquipo = (int)q_valor("SELECT COUNT(*) FROM tareas WHERE estado <> 'completada' AND vencimiento < ?", [$hoy]);

$agenda = q_todos(
    'SELECT a.*, c.nombre AS cliente FROM actividades a LEFT JOIN clientes c ON c.id = a.cliente_id
     WHERE a.usuario_id = ? AND a.completada = 0 AND a.fecha <= ?
     ORDER BY a.fecha LIMIT 10',
    [$uid, $en7dias . ' 23:59:59']
);

$porCobrar = (float)q_valor("SELECT COALESCE(SUM(total), 0) FROM facturas WHERE estado = 'emitida'");
$cobranza = q_todos(
    "SELECT f.*, c.nombre AS cliente FROM facturas f JOIN clientes c ON c.id = f.cliente_id
     WHERE f.estado = 'emitida' ORDER BY f.fecha_vencimiento IS NULL, f.fecha_vencimiento, f.fecha_emision LIMIT 8");
$borradores = (int)q_valor("SELECT COUNT(*) FROM facturas WHERE estado = 'borrador'");

$mandatos = q_todos(
    "SELECT id, nombre, identificacion, mandato_facturacion, mandato_hasta FROM empresas
     WHERE mandato_facturacion = 1 AND mandato_hasta IS NOT NULL AND mandato_hasta <= ? ORDER BY mandato_hasta",
    [date('Y-m-d', strtotime('+30 days'))]);

$pipeline = q_todos("SELECT etapa, COUNT(*) AS n, COALESCE(SUM(monto), 0) AS total FROM oportunidades GROUP BY etapa");
$porEtapa = array_column($pipeline, null, 'etapa');

function tabla_tareas_inicio(array $filas, string $vacio): void
{
    if (!$filas) {
        echo '<p class="vacio">' . e($vacio) . '</p>';
        return;
    }
    echo '<table><tbody>';
    foreach ($filas as $t) {
        echo '<tr class="' . clase_vencimiento($t['vencimiento'], $t['estado']) . '">'
            . '<td class="nowrap">' . e(fecha($t['vencimiento'])) . '</td>'
            . '<td><a href="' . e(url('tareas', ['a' => 'ver', 'id' => $t['id']])) . '">' . e($t['titulo']) . '</a>'
            . ($t['prioridad'] === 'alta' ? ' <span class="badge prioridad-alta">Alta</span>' : '')
            . '<br><small class="tenue">' . e(implode(' · ', array_filter([$t['cliente'], $t['empresa']]))) . '</small></td>'
            . '<td>' . badge_tarea($t['estado']) . '</td>'
            . '<td class="derecha"><form method="post" action="' . e(url('tareas', ['a' => 'estado', 'id' => $t['id'], 'volver' => 'dashboard'])) . '" class="en-linea">'
            . csrf_campo() . '<input type="hidden" name="estado" value="completada"><button type="submit" class="chico secundario">✓ Hecho</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

layout_inicio('Inicio', 'dashboard');
?>
<h1>Hola, <?= e(usuario_actual()['nombre']) ?></h1>

<section class="tarjetas">
    <div class="tarjeta"><span class="cifra"><?= $ufHoy ? '$ ' . e(formato_uf($ufHoy)) : '—' ?></span><span>UF hoy</span></div>
    <div class="tarjeta"><span class="cifra"><?= $utm ? e(dinero($utm)) : '—' ?></span><span>UTM <?= e(date('m/Y')) ?></span></div>
    <a class="tarjeta" href="<?= e(url('tareas')) ?>"><span class="cifra"><?= $abiertas ?></span><span>Mis tareas abiertas<?= $esperando ? " ($esperando esperando al cliente)" : '' ?></span></a>
    <a class="tarjeta" href="<?= e(url('tareas', ['estado' => 'vencidas', 'quien' => 'todos'])) ?>"><span class="cifra <?= $vencidasEquipo ? 'texto-peligro' : '' ?>"><?= $vencidasEquipo ?></span><span>Tareas vencidas del equipo</span></a>
    <a class="tarjeta" href="<?= e(url('facturas', ['estado_f' => 'por_cobrar'])) ?>"><span class="cifra"><?= e(dinero($porCobrar)) ?></span><span>Por cobrar<?= $borradores ? " · $borradores borrador(es)" : '' ?></span></a>
</section>

<div class="columnas">
    <section class="panel">
        <h2>Mis tareas vencidas</h2>
        <?php tabla_tareas_inicio($vencidas, 'No tiene tareas vencidas. 🎉'); ?>
    </section>
    <section class="panel">
        <h2>Vencen en los próximos 7 días</h2>
        <?php tabla_tareas_inicio($proximas, 'Nada vence esta semana.'); ?>
        <p><a href="<?= e(url('tareas', ['a' => 'form'])) ?>">+ Nueva tarea</a></p>
    </section>
</div>

<div class="columnas">
    <section class="panel">
        <h2>Mi agenda</h2>
        <?php if (!$agenda): ?><p class="vacio">Sin reuniones ni llamadas agendadas.</p><?php else: ?>
        <table><tbody>
        <?php foreach ($agenda as $a): ?>
            <tr class="<?= $a['fecha'] < ahora() ? 'vencida' : '' ?>">
                <td class="nowrap"><?= e(fecha($a['fecha'], true)) ?></td>
                <td><a href="<?= e(url('actividades', ['a' => 'form', 'id' => $a['id']])) ?>"><?= e($a['asunto']) ?></a>
                    <small class="tenue"><?= e(TIPOS_ACTIVIDAD[$a['tipo']] ?? '') ?><?= $a['cliente'] ? ' · ' . e($a['cliente']) : '' ?></small></td>
                <td class="derecha"><?= boton_post(url('actividades', ['a' => 'completar', 'id' => $a['id'], 'volver' => 'dashboard']), '✓ Hecho', 'chico secundario') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <p><a href="<?= e(url('actividades', ['a' => 'form'])) ?>">+ Agendar gestión</a></p>
    </section>
    <section class="panel">
        <h2>Cobranza pendiente</h2>
        <?php if (!$cobranza): ?><p class="vacio">No hay documentos por cobrar.</p><?php else: ?>
        <table><tbody>
        <?php foreach ($cobranza as $f): ?>
            <tr class="<?= $f['fecha_vencimiento'] && $f['fecha_vencimiento'] < $hoy ? 'vencida' : '' ?>">
                <td class="nowrap"><?= e(fecha($f['fecha_vencimiento'] ?: $f['fecha_emision'])) ?></td>
                <td><?= enlace('clientes', $f['cliente_id'], $f['cliente']) ?><br><small class="tenue"><?= e($f['glosa']) ?></small></td>
                <td class="derecha nowrap"><?= e(dinero($f['total'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>
</div>

<?php if ($mandatos): ?>
<section class="panel">
    <h2>Mandatos de facturación por renovar</h2>
    <table><tbody>
    <?php foreach ($mandatos as $m): ?>
        <tr><td><?= enlace('empresas', $m['id'], $m['nombre']) ?> <small class="tenue"><?= e($m['identificacion']) ?></small></td>
            <td class="derecha"><?= badge_mandato($m) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</section>
<?php endif; ?>

<?php if ($pipeline): ?>
<section class="panel">
    <h2>Prospectos (oportunidades)</h2>
    <div class="embudo">
        <?php
        $max = max(1, ...array_map(static fn($p) => (int)$p['n'], $pipeline));
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
<?php endif; ?>
<?php
layout_fin();
