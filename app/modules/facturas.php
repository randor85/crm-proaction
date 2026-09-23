<?php
declare(strict_types=1);

$id = entrada_int('id');

/** Tasa de retención de boletas de honorarios (%), configurable por año. */
function tasa_retencion(): float
{
    return (float)config('retencion_honorarios', 15.25);
}

/** @return array{neto: float, impuesto: float, total: float} */
function calcular_montos(string $tipo, float $neto): array
{
    $neto = round($neto);
    if ($tipo === 'factura_afecta') {
        $imp = round($neto * IVA);
        return ['neto' => $neto, 'impuesto' => $imp, 'total' => $neto + $imp];
    }
    if ($tipo === 'boleta_honorarios') {
        $ret = round($neto * tasa_retencion() / 100);
        return ['neto' => $neto, 'impuesto' => $ret, 'total' => $neto - $ret];
    }
    return ['neto' => $neto, 'impuesto' => 0.0, 'total' => $neto];
}

/* ---------- Valor de la UF (JSON para el formulario) ---------- */
if ($accion === 'uf') {
    $f = entrada_fecha('fecha') ?? date('Y-m-d');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['fecha' => $f, 'valor' => indicador('uf', $f)]);
    exit;
}

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $tipo = entrada('tipo_documento');
    $tipo = isset(TIPOS_DOCUMENTO_VENTA[$tipo]) ? $tipo : 'factura_afecta';
    $moneda = entrada('moneda') === 'CLP' ? 'CLP' : 'UF';
    $estado = entrada('estado');
    $montoUf = entrada_decimal('monto_uf');
    $valorUf = entrada_decimal('valor_uf');
    $fechaEmision = entrada_fecha('fecha_emision') ?? date('Y-m-d');
    $volver = url('facturas', ['a' => 'form', 'id' => $id]);

    if ($moneda === 'UF') {
        if (!$valorUf) {
            $valorUf = indicador('uf', $fechaEmision);
        }
        if (!$montoUf || !$valorUf) {
            flash('error', $montoUf ? 'No se pudo obtener la UF de la fecha de emisión; ingrésela manualmente.' : 'Indique el monto en UF.');
            redirigir($volver);
        }
        $neto = $montoUf * $valorUf;
    } else {
        $neto = entrada_decimal('neto') ?? 0;
        $montoUf = null;
        $valorUf = null;
    }

    $datos = [
        'cliente_id'        => entrada_int('cliente_id'),
        'empresa_id'        => entrada_int('empresa_id'),
        'tipo_documento'    => $tipo,
        'folio'             => nulo_si_vacio(entrada('folio')),
        'fecha_emision'     => $fechaEmision,
        'fecha_vencimiento' => entrada_fecha('fecha_vencimiento'),
        'periodo'           => preg_match('/^\d{4}-\d{2}$/', entrada('periodo')) ? entrada('periodo') : null,
        'glosa'             => entrada('glosa'),
        'moneda'            => $moneda,
        'monto_uf'          => $montoUf,
        'valor_uf'          => $valorUf,
        'estado'            => isset(ESTADOS_FACTURA[$estado]) ? $estado : 'borrador',
        'fecha_pago'        => entrada_fecha('fecha_pago'),
        'notas'             => nulo_si_vacio(entrada('notas')),
        'actualizado_en'    => ahora(),
    ] + calcular_montos($tipo, $neto);
    if (!$id) {
        $datos['cobro_id'] = entrada_int('cobro_id');
    }

    if (!$datos['cliente_id'] || $datos['glosa'] === '' || $datos['neto'] <= 0) {
        flash('error', 'Cliente, glosa y un monto mayor a cero son obligatorios.');
        redirigir($volver);
    }
    if ($datos['estado'] === 'pagada' && !$datos['fecha_pago']) {
        $datos['fecha_pago'] = date('Y-m-d');
    }
    if ($id) {
        actualizar('facturas', $id, $datos);
        flash('ok', 'Documento actualizado.');
    } else {
        $datos['creado_por'] = (int)usuario_actual()['id'];
        $datos['creado_en'] = ahora();
        $id = insertar('facturas', $datos);
        flash('ok', 'Documento registrado.');
    }
    redirigir(entrada('volver') === 'cliente' ? url('clientes', ['a' => 'ver', 'id' => $datos['cliente_id']]) : url('facturas'));
}

/* ---------- Cambio rápido de estado ---------- */
if ($accion === 'estado' && es_post() && $id) {
    $estado = entrada('estado');
    if (isset(ESTADOS_FACTURA[$estado])) {
        q('UPDATE facturas SET estado = ?, fecha_pago = ?, actualizado_en = ? WHERE id = ?',
            [$estado, $estado === 'pagada' ? date('Y-m-d') : null, ahora(), $id]);
        flash('ok', 'Documento marcado como ' . mb_strtolower(ESTADOS_FACTURA[$estado]) . '.');
    }
    redirigir(url('facturas', array_intersect_key($_GET, array_flip(['estado_f', 'periodo', 'cliente_id']))));
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $f = q_uno('SELECT * FROM facturas WHERE id = ?', [$id]);
    if ($f && $f['estado'] !== 'borrador' && !es_admin()) {
        flash('error', 'Solo se pueden eliminar borradores. Para un documento emitido, márquelo como anulado.');
        redirigir(url('facturas', ['a' => 'form', 'id' => $id]));
    }
    q('DELETE FROM facturas WHERE id = ?', [$id]);
    flash('ok', 'Documento eliminado.');
    redirigir(url('facturas'));
}

/* ================================================================
 * Planes de cobro (cobros recurrentes de cada cliente)
 * ================================================================ */

if ($accion === 'cobro_guardar' && es_post()) {
    $cobroId = entrada_int('cobro_id');
    $periodicidad = entrada('periodicidad');
    $doc = entrada('documento_tipo');
    $datos = [
        'cliente_id'     => entrada_int('cliente_id'),
        'empresa_id'     => entrada_int('empresa_id'),
        'concepto'       => entrada('concepto'),
        'monto'          => entrada_decimal('monto') ?? 0,
        'moneda'         => entrada('moneda') === 'CLP' ? 'CLP' : 'UF',
        'documento_tipo' => isset(TIPOS_DOCUMENTO_VENTA[$doc]) ? $doc : 'factura_afecta',
        'periodicidad'   => isset(PERIODICIDADES[$periodicidad]) ? $periodicidad : 'mensual',
        'mes_inicio'     => max(1, min(12, entrada_int('mes_inicio') ?? 1)),
        'activo'         => entrada('activo') === '1' ? 1 : 0,
        'notas'          => nulo_si_vacio(entrada('notas')),
        'actualizado_en' => ahora(),
    ];
    if (!$datos['cliente_id'] || $datos['concepto'] === '' || $datos['monto'] <= 0) {
        flash('error', 'Cliente, concepto y un monto mayor a cero son obligatorios.');
        redirigir(url('facturas', ['a' => 'cobro_form', 'cobro_id' => $cobroId, 'cliente_id' => $datos['cliente_id']]));
    }
    if ($cobroId) {
        actualizar('cobros', $cobroId, $datos);
        flash('ok', 'Plan de cobro actualizado.');
    } else {
        $datos['creado_en'] = ahora();
        insertar('cobros', $datos);
        flash('ok', 'Plan de cobro agregado: ' . cobro_resumen($datos) . '.');
    }
    redirigir(url('clientes', ['a' => 'ver', 'id' => $datos['cliente_id']]) . '#cobros');
}

if ($accion === 'cobro_eliminar' && es_post()) {
    $k = q_uno('SELECT * FROM cobros WHERE id = ?', [entrada_int('cobro_id')]);
    if ($k) {
        q('UPDATE facturas SET cobro_id = NULL WHERE cobro_id = ?', [$k['id']]);
        q('DELETE FROM cobros WHERE id = ?', [$k['id']]);
        flash('ok', 'Plan de cobro eliminado. Los documentos ya emitidos se conservan.');
    }
    redirigir($k ? url('clientes', ['a' => 'ver', 'id' => $k['cliente_id']]) . '#cobros' : url('facturas', ['a' => 'cobros']));
}

if ($accion === 'cobro_form') {
    $cobroId = entrada_int('cobro_id');
    $k = $cobroId ? q_uno('SELECT * FROM cobros WHERE id = ?', [$cobroId]) : [
        'cliente_id' => entrada_int('cliente_id'), 'empresa_id' => null, 'concepto' => entrada('concepto') ?: 'Honorarios asesoría tributaria',
        'monto' => null, 'moneda' => 'UF', 'documento_tipo' => q_valor('SELECT documento_tipo FROM cobros WHERE cliente_id = ? ORDER BY id LIMIT 1', [entrada_int('cliente_id') ?? 0]) ?: 'factura_afecta',
        'periodicidad' => entrada('periodicidad') ?: 'mensual', 'mes_inicio' => entrada_int('mes_inicio') ?? 1, 'activo' => 1,
    ];
    if ($cobroId && !$k) {
        redirigir(url('facturas', ['a' => 'cobros']));
    }
    layout_inicio($cobroId ? 'Editar plan de cobro' : 'Nuevo plan de cobro', 'facturas');
    ?>
    <div class="encabezado">
        <h1><?= $cobroId ? 'Editar plan de cobro' : 'Nuevo plan de cobro' ?></h1>
        <?php if ($cobroId): ?>
            <form method="post" action="<?= e(url('facturas', ['a' => 'cobro_eliminar'])) ?>" class="en-linea" onsubmit="return confirm('¿Eliminar este plan de cobro? Los documentos ya emitidos se conservan.')">
                <?= csrf_campo() ?><input type="hidden" name="cobro_id" value="<?= (int)$cobroId ?>">
                <button type="submit" class="peligro">Eliminar</button>
            </form>
        <?php endif; ?>
    </div>
    <form method="post" action="<?= e(url('facturas', ['a' => 'cobro_guardar'])) ?>" class="formulario rejilla" id="form-cobro">
        <?= csrf_campo() ?>
        <?php if ($cobroId): ?><input type="hidden" name="cobro_id" value="<?= (int)$cobroId ?>"><?php endif; ?>
        <div><?= selector('cliente_id', 'Cliente *', opciones_clientes(false), $k['cliente_id'] ?? '', true, 'required') ?></div>
        <div><?= selector_empresa('empresa_id', 'RUT al que se factura', $k['empresa_id'] ?? '', 'El primer RUT del cliente') ?></div>
        <div class="completo">
            <?= campo('concepto', 'Concepto (inicio de la glosa) *', $k['concepto'], 'text', 'required maxlength="200" list="conceptos"') ?>
            <datalist id="conceptos">
                <?php foreach (['Honorarios asesoría tributaria', 'Declaración anual de renta F22', 'Declaraciones juradas anuales',
                    'Balance y estados financieros', 'Remuneraciones', 'Contabilidad'] as $sug): ?><option value="<?= e($sug) ?>"><?php endforeach; ?>
            </datalist>
            <small class="tenue">Al facturar se agrega el período, p. ej. «<?= e(cobro_glosa(['concepto' => $k['concepto']] + $k, date('Y-m'))) ?>».</small>
        </div>
        <div><?= campo('monto', 'Monto neto por cobro *', $k['monto'] ? numero_corto($k['monto']) : '', 'text', 'required inputmode="decimal" placeholder="Ej.: 3,5"') ?></div>
        <div><?= selector('moneda', 'Moneda', ['UF' => 'UF', 'CLP' => 'Pesos'], $k['moneda'], false) ?></div>
        <div><?= selector('periodicidad', 'Se cobra', PERIODICIDADES, $k['periodicidad'], false) ?></div>
        <div><?= selector('mes_inicio', 'Mes (anual) o primer mes del ciclo', array_map('ucfirst', MESES), $k['mes_inicio'], false) ?>
            <small class="tenue" id="calendario-cobro"></small></div>
        <div><?= selector('documento_tipo', 'Documento que se emite', TIPOS_DOCUMENTO_VENTA, $k['documento_tipo'], false) ?></div>
        <div class="completo"><?= area('notas', 'Notas', $k['notas'] ?? '') ?></div>
        <div class="completo"><label class="check"><input type="checkbox" name="activo" value="1" <?= $k['activo'] ? 'checked' : '' ?>> Activo (se incluye al generar los cobros del mes)</label></div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e($k['cliente_id'] ? url('clientes', ['a' => 'ver', 'id' => $k['cliente_id']]) : url('facturas', ['a' => 'cobros'])) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Todos los planes de cobro ---------- */
if ($accion === 'cobros') {
    $filtroP = entrada('periodicidad');
    $filtroMes = entrada_int('mes');
    $cobros = q_todos(
        'SELECT k.*, c.nombre AS cliente, c.activo AS cliente_activo FROM cobros k JOIN clientes c ON c.id = k.cliente_id
         ' . (isset(PERIODICIDADES[$filtroP]) ? 'WHERE k.periodicidad = ?' : '') . ' ORDER BY c.nombre, k.concepto',
        isset(PERIODICIDADES[$filtroP]) ? [$filtroP] : []);
    if ($filtroMes) {
        $cobros = array_values(array_filter($cobros, static fn($k) => cobro_aplica($k, $filtroMes)));
    }
    $anual = ['UF' => 0.0, 'CLP' => 0.0];
    foreach ($cobros as $k) {
        if ($k['activo'] && $k['cliente_activo']) {
            $anual[$k['moneda']] += $k['monto'] * cobro_veces_anio($k);
        }
    }
    layout_inicio('Planes de cobro', 'facturas');
    ?>
    <div class="encabezado">
        <h1>Planes de cobro</h1>
        <div><a class="boton" href="<?= e(url('facturas', ['a' => 'cobro_form'])) ?>">+ Nuevo plan de cobro</a></div>
    </div>
    <form class="barra-lista" method="get">
        <input type="hidden" name="r" value="facturas"><input type="hidden" name="a" value="cobros">
        <?= selector('periodicidad', '', PERIODICIDADES, $filtroP, 'Todas las periodicidades', 'aria-label="Periodicidad"') ?>
        <?= selector('mes', '', array_map('ucfirst', MESES), $filtroMes ?? '', 'Cualquier mes', 'aria-label="Se factura en"') ?>
        <button type="submit" class="secundario">Filtrar</button>
    </form>
    <p class="tenue">Ingreso anual estimado de los planes listados (activos):
        <strong>UF <?= e(numero_corto($anual['UF'], 2)) ?></strong><?= $anual['CLP'] > 0 ? ' + <strong>' . e(dinero($anual['CLP'])) . '</strong>' : '' ?> (neto).</p>
    <table>
        <thead><tr><th>Cliente</th><th>Concepto</th><th class="derecha">Monto</th><th>Se cobra</th><th>Documento</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cobros as $k): ?>
            <tr class="<?= $k['activo'] && $k['cliente_activo'] ? '' : 'hecha' ?>">
                <td><?= enlace('clientes', $k['cliente_id'], $k['cliente']) ?></td>
                <td><?= e($k['concepto']) ?></td>
                <td class="derecha nowrap"><?= e(cobro_monto_texto($k)) ?></td>
                <td><?= e(PERIODICIDADES[$k['periodicidad']] ?? '') ?><br><small class="tenue"><?= e(cobro_calendario($k)) ?></small></td>
                <td><?= e(TIPOS_DOCUMENTO_VENTA[$k['documento_tipo']] ?? '') ?></td>
                <td class="derecha"><a class="boton chico secundario" href="<?= e(url('facturas', ['a' => 'cobro_form', 'cobro_id' => $k['id']])) ?>">Editar</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$cobros): ?><tr><td colspan="6" class="vacio">No hay planes de cobro con estos filtros.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php
    layout_fin();
    return;
}

/* ---------- Cobros del mes: vista previa y generación de borradores ---------- */
if ($accion === 'programar' || ($accion === 'generar' && es_post())) {
    $periodo = preg_match('/^\d{4}-\d{2}$/', entrada('periodo')) ? entrada('periodo') : date('Y-m');
    $fechaEmision = entrada_fecha('fecha_emision') ?? (substr(date('Y-m-d'), 0, 7) === $periodo ? date('Y-m-d') : $periodo . '-01');
    $uf = indicador('uf', $fechaEmision);
    $cobros = cobros_del_periodo($periodo);

    if ($accion === 'generar') {
        $elegidos = array_map('intval', (array)($_POST['cobros'] ?? []));
        $aGenerar = array_filter($cobros, static fn($k) => in_array((int)$k['id'], $elegidos, true) && !$k['factura_id']);
        if (!$aGenerar) {
            flash('error', 'No eligió ningún cobro pendiente.');
            redirigir(url('facturas', ['a' => 'programar', 'periodo' => $periodo]));
        }
        if (!$uf && array_filter($aGenerar, static fn($k) => $k['moneda'] === 'UF')) {
            flash('error', 'No se pudo obtener la UF del ' . fecha($fechaEmision) . '. Inténtelo más tarde o regístrela en Sistema.');
            redirigir(url('facturas', ['a' => 'programar', 'periodo' => $periodo]));
        }
        foreach ($aGenerar as $k) {
            $esUf = $k['moneda'] === 'UF';
            $empresaId = $k['empresa_id'] ?: q_valor('SELECT MIN(id) FROM empresas WHERE cliente_id = ?', [$k['cliente_id']]);
            insertar('facturas', [
                'cliente_id' => $k['cliente_id'], 'empresa_id' => $empresaId, 'cobro_id' => $k['id'],
                'tipo_documento' => $k['documento_tipo'], 'fecha_emision' => $fechaEmision, 'periodo' => $periodo,
                'glosa' => mb_substr(cobro_glosa($k, $periodo), 0, 250), 'moneda' => $k['moneda'],
                'monto_uf' => $esUf ? $k['monto'] : null, 'valor_uf' => $esUf ? $uf : null, 'estado' => 'borrador',
                'creado_por' => (int)usuario_actual()['id'], 'creado_en' => ahora(), 'actualizado_en' => ahora(),
            ] + calcular_montos($k['documento_tipo'], $esUf ? $k['monto'] * $uf : (float)$k['monto']));
        }
        flash('ok', count($aGenerar) . ' borrador(es) creado(s) para ' . $periodo . ($uf ? ' con UF $' . formato_uf($uf) : '')
            . '. Revíselos, emítalos en el SII y registre el folio.');
        redirigir(url('facturas', ['periodo' => $periodo, 'estado_f' => 'borrador']));
    }

    [$anioP, $mesP] = [(int)substr($periodo, 0, 4), (int)substr($periodo, 5, 2)];
    layout_inicio('Cobros del mes', 'facturas');
    ?>
    <h1>Cobros de <?= e(MESES[$mesP] . ' ' . $anioP) ?></h1>
    <form method="get" class="barra-lista">
        <input type="hidden" name="r" value="facturas"><input type="hidden" name="a" value="programar">
        <label for="f_periodo_p">Período</label>
        <input type="month" id="f_periodo_p" name="periodo" value="<?= e($periodo) ?>" aria-label="Período">
        <button type="submit" class="secundario">Ver</button>
        <span class="espaciador"></span>
        <a href="<?= e(url('facturas', ['a' => 'cobros'])) ?>">Todos los planes de cobro →</a>
    </form>
    <?php if (!$cobros): ?>
        <p class="vacio">Ningún plan de cobro activo corresponde a este mes.</p>
    <?php else: ?>
    <form method="post" action="<?= e(url('facturas', ['a' => 'generar'])) ?>">
        <?= csrf_campo() ?>
        <input type="hidden" name="periodo" value="<?= e($periodo) ?>">
        <table>
            <thead><tr><th class="casilla"><input type="checkbox" data-marcar-todos checked aria-label="Marcar todos"></th><th>Cliente</th><th>Glosa</th><th>Se cobra</th><th class="derecha">Monto</th><th class="derecha">Neto estimado</th><th></th></tr></thead>
            <tbody>
            <?php $sumaNeto = 0;
            foreach ($cobros as $k):
                $neto = $k['moneda'] === 'UF' ? ($uf ? round($k['monto'] * $uf) : null) : round((float)$k['monto']);
                if (!$k['factura_id'] && $neto) { $sumaNeto += $neto; } ?>
                <tr class="<?= $k['factura_id'] ? 'hecha' : '' ?>">
                    <td class="casilla"><?php if (!$k['factura_id']): ?><input type="checkbox" name="cobros[]" value="<?= (int)$k['id'] ?>" checked aria-label="Generar <?= e($k['cliente']) ?>"><?php endif; ?></td>
                    <td><?= enlace('clientes', $k['cliente_id'], $k['cliente']) ?></td>
                    <td><?= e(cobro_glosa($k, $periodo)) ?><br><small class="tenue"><?= e(TIPOS_DOCUMENTO_VENTA[$k['documento_tipo']] ?? '') ?></small></td>
                    <td><?= e(PERIODICIDADES[$k['periodicidad']] ?? '') ?></td>
                    <td class="derecha nowrap"><?= e(cobro_monto_texto($k)) ?></td>
                    <td class="derecha nowrap"><?= $neto !== null ? e(dinero($neto)) : '—' ?></td>
                    <td class="nowrap"><?= $k['factura_id'] ? '<a href="' . e(url('facturas', ['a' => 'form', 'id' => $k['factura_id']])) . '">Ya generado</a>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="5">Total neto pendiente de generar</th><th class="derecha nowrap"><?= e(dinero($sumaNeto)) ?></th><th></th></tr></tfoot>
        </table>
        <div class="fila-formulario formulario">
            <div><?= campo('fecha_emision', 'Fecha de emisión (define la UF)', $fechaEmision, 'date', 'required') ?></div>
            <div><button type="submit">Generar borradores marcados</button></div>
        </div>
        <p class="tenue">UF usada para el estimado: <?= $uf ? '$ ' . e(formato_uf($uf)) . ' (' . e(fecha($fechaEmision)) . ')' : 'no disponible' ?>. Luego emita cada documento en el SII y registre el folio.</p>
    </form>
    <?php endif; ?>
    <?php
    layout_fin();
    return;
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $f = $id ? q_uno('SELECT * FROM facturas WHERE id = ?', [$id]) : null;
    if ($id && !$f) {
        redirigir(url('facturas'));
    }
    if (!$f) {
        // Nuevo: precargar con los datos del cliente
        $clienteId = entrada_int('cliente_id');
        $empresaId = entrada_int('empresa_id');
        if (!$clienteId && $empresaId) {
            $clienteId = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$empresaId]);
        }
        // Prellenar desde un plan de cobro (el indicado o el primero activo del cliente)
        $cobro = entrada_int('cobro_id') ? q_uno('SELECT * FROM cobros WHERE id = ?', [entrada_int('cobro_id')]) : null;
        if (!$cobro && $clienteId) {
            $cobro = q_uno('SELECT * FROM cobros WHERE cliente_id = ? AND activo = 1 ORDER BY id LIMIT 1', [$clienteId]);
        }
        $clienteId = $clienteId ?: ($cobro['cliente_id'] ?? null);
        $esUf = ($cobro['moneda'] ?? 'UF') === 'UF';
        $f = [
            'cliente_id'     => $clienteId,
            'empresa_id'     => $empresaId ?: ($cobro['empresa_id'] ?? null) ?: ($clienteId ? q_valor('SELECT MIN(id) FROM empresas WHERE cliente_id = ?', [$clienteId]) : null),
            'cobro_id'       => entrada_int('cobro_id') ? $cobro['id'] ?? null : null,
            'tipo_documento' => $cobro['documento_tipo'] ?? 'factura_afecta',
            'fecha_emision'  => date('Y-m-d'),
            'periodo'        => date('Y-m'),
            'moneda'         => $cobro['moneda'] ?? 'UF',
            'monto_uf'       => $cobro && $esUf ? $cobro['monto'] : null,
            'valor_uf'       => indicador('uf'),
            'neto'           => $cobro && !$esUf ? $cobro['monto'] : null,
            'estado'         => 'borrador',
            'glosa'          => $cobro ? cobro_glosa($cobro, date('Y-m')) : 'Honorarios asesoría tributaria',
        ];
    }
    $valorUf = $f['valor_uf'] ? formato_uf($f['valor_uf']) : '';
    layout_inicio($id ? 'Editar documento' : 'Registrar documento', 'facturas');
    ?>
    <div class="encabezado">
        <h1><?= $id ? 'Editar documento' : 'Registrar documento de venta' ?></h1>
        <?php if ($id && ($f['estado'] === 'borrador' || es_admin())) echo boton_post(url('facturas', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar este documento?'); ?>
    </div>
    <form method="post" action="<?= e(url('facturas', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla" id="form-factura" data-retencion="<?= e(tasa_retencion()) ?>">
        <?= csrf_campo() ?>
        <?php if (!$id && !empty($f['cobro_id'])): ?><input type="hidden" name="cobro_id" value="<?= (int)$f['cobro_id'] ?>"><?php endif; ?>
        <div><?= selector('cliente_id', 'Cliente *', opciones_clientes(false), $f['cliente_id'] ?? '', true, 'required') ?></div>
        <div><?= selector_empresa('empresa_id', 'RUT al que se factura', $f['empresa_id'] ?? '') ?></div>
        <div><?= selector('tipo_documento', 'Tipo de documento', TIPOS_DOCUMENTO_VENTA, $f['tipo_documento'], false) ?></div>
        <div><?= campo('folio', 'Folio SII', $f['folio'] ?? '', 'text', 'placeholder="Se completa al emitir"') ?></div>
        <div><?= campo('fecha_emision', 'Fecha de emisión', $f['fecha_emision'], 'date', 'required') ?></div>
        <div><?= campo('periodo', 'Período de servicio', $f['periodo'] ?? '', 'month') ?></div>
        <div class="completo"><?= campo('glosa', 'Glosa / detalle *', $f['glosa'] ?? '', 'text', 'required maxlength="250"') ?></div>
        <div><?= selector('moneda', 'Moneda', ['UF' => 'UF', 'CLP' => 'Pesos'], $f['moneda'], false) ?></div>
        <div class="solo-uf"><?= campo('monto_uf', 'Monto en UF (neto)', $f['monto_uf'] !== null ? numero_corto($f['monto_uf']) : '', 'text', 'inputmode="decimal" placeholder="Ej.: 3,5"') ?></div>
        <div class="solo-uf">
            <label for="f_valor_uf">Valor UF</label>
            <div class="grupo-input">
                <input type="text" id="f_valor_uf" name="valor_uf" value="<?= e($valorUf) ?>" inputmode="decimal">
                <button type="button" class="secundario" id="obtener-uf">UF de la fecha</button>
            </div>
            <small class="tenue" id="uf-aviso"><?= $f['valor_uf'] ? '' : 'Sin conexión a la fuente: ingrese la UF manualmente.' ?></small>
        </div>
        <div class="solo-clp"><?= campo('neto', 'Monto neto en pesos', $f['neto'] ?? '', 'text', 'inputmode="decimal"') ?></div>
        <div class="completo">
            <table class="resumen-montos">
                <tr><th>Neto</th><td id="calc-neto" class="derecha"></td></tr>
                <tr><th id="calc-imp-etiqueta">IVA</th><td id="calc-imp" class="derecha"></td></tr>
                <tr class="total"><th id="calc-total-etiqueta">Total</th><td id="calc-total" class="derecha"></td></tr>
            </table>
        </div>
        <div><?= selector('estado', 'Estado', ESTADOS_FACTURA, $f['estado'], false) ?></div>
        <div><?= campo('fecha_vencimiento', 'Vencimiento del pago', $f['fecha_vencimiento'] ?? '', 'date') ?></div>
        <div><?= campo('fecha_pago', 'Fecha de pago', $f['fecha_pago'] ?? '', 'date') ?></div>
        <div class="completo"><?= area('notas', 'Notas', $f['notas'] ?? '') ?></div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e(url('facturas')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['f.glosa', 'f.folio', 'c.nombre', 'e.identificacion'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
$estadoF = entrada('estado_f', 'vigentes');
if ($estadoF === 'vigentes') {
    $condiciones[] = "f.estado <> 'anulada'";
} elseif ($estadoF === 'por_cobrar') {
    $condiciones[] = "f.estado = 'emitida'";
} elseif (isset(ESTADOS_FACTURA[$estadoF])) {
    $condiciones[] = 'f.estado = :estado';
    $params['estado'] = $estadoF;
}
$periodo = preg_match('/^\d{4}-\d{2}$/', entrada('periodo')) ? entrada('periodo') : '';
if ($periodo) {
    $condiciones[] = 'f.periodo = :periodo';
    $params['periodo'] = $periodo;
}
$filtroCliente = entrada_int('cliente_id');
if ($filtroCliente) {
    $condiciones[] = 'f.cliente_id = :cliente';
    $params['cliente'] = $filtroCliente;
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$desdeTabla = 'FROM facturas f JOIN clientes c ON c.id = f.cliente_id LEFT JOIN empresas e ON e.id = f.empresa_id';

if ($accion === 'csv') {
    $filas = q_todos("SELECT f.*, c.nombre AS cliente, e.nombre AS empresa, e.identificacion $desdeTabla $where ORDER BY f.fecha_emision, f.id", $params);
    $filas = array_map(static fn($f) => [fecha($f['fecha_emision']), TIPOS_DOCUMENTO_VENTA[$f['tipo_documento']] ?? '', $f['folio'],
        $f['cliente'], $f['empresa'], $f['identificacion'], $f['periodo'], $f['glosa'], $f['monto_uf'] !== null ? numero_corto($f['monto_uf']) : '',
        $f['valor_uf'] !== null ? formato_uf($f['valor_uf']) : '', round((float)$f['neto']), round((float)$f['impuesto']), round((float)$f['total']),
        ESTADOS_FACTURA[$f['estado']] ?? $f['estado'], fecha($f['fecha_pago'])], $filas);
    exportar_csv('facturacion_' . date('Ymd') . '.csv',
        ['Emisión', 'Documento', 'Folio', 'Cliente', 'Razón social', 'RUT', 'Período', 'Glosa', 'UF', 'Valor UF', 'Neto', 'IVA / Retención', 'Total', 'Estado', 'Fecha de pago'], $filas);
}

[$limite, $desde] = paginacion_limites();
$total = (int)q_valor("SELECT COUNT(*) $desdeTabla $where", $params);
$sumas = q_uno("SELECT COALESCE(SUM(f.neto), 0) AS neto, COALESCE(SUM(f.total), 0) AS total $desdeTabla $where", $params);
$facturas = q_todos("SELECT f.*, c.nombre AS cliente, e.nombre AS empresa, e.identificacion AS rut
     $desdeTabla $where ORDER BY f.fecha_emision DESC, f.id DESC LIMIT $limite OFFSET $desde", $params);
$porCobrar = (float)q_valor("SELECT COALESCE(SUM(total), 0) FROM facturas WHERE estado = 'emitida'");
$vencidas = (float)q_valor("SELECT COALESCE(SUM(total), 0) FROM facturas WHERE estado = 'emitida' AND fecha_vencimiento < ?", [date('Y-m-d')]);
$borradores = (int)q_valor("SELECT COUNT(*) FROM facturas WHERE estado = 'borrador'");
$ufHoy = indicador('uf');
$filtrosActuales = array_intersect_key($_GET, array_flip(['estado_f', 'periodo', 'cliente_id', 'q']));

layout_inicio('Facturación', 'facturas');
?>
<h1>Facturación</h1>
<section class="tarjetas">
    <div class="tarjeta"><span class="cifra"><?= $ufHoy ? '$ ' . e(formato_uf($ufHoy)) : '—' ?></span><span>UF hoy (<?= e(date('d/m')) ?>)</span></div>
    <a class="tarjeta" href="<?= e(url('facturas', ['estado_f' => 'por_cobrar'])) ?>"><span class="cifra"><?= e(dinero($porCobrar)) ?></span><span>Por cobrar</span></a>
    <div class="tarjeta"><span class="cifra <?= $vencidas > 0 ? 'texto-peligro' : '' ?>"><?= e(dinero($vencidas)) ?></span><span>Cobranza vencida</span></div>
    <a class="tarjeta" href="<?= e(url('facturas', ['estado_f' => 'borrador'])) ?>"><span class="cifra"><?= $borradores ?></span><span>Borradores por emitir</span></a>
</section>

<?php $pendientesMes = count(array_filter(cobros_del_periodo(date('Y-m')), static fn($k) => !$k['factura_id'])); ?>
<section class="panel fila-acciones">
    <div>
        <strong>Cobros de <?= e(MESES[(int)date('n')]) ?>:</strong>
        <?= $pendientesMes ? $pendientesMes . ' por generar' : 'todos generados' ?>
    </div>
    <div>
        <a class="boton" href="<?= e(url('facturas', ['a' => 'programar'])) ?>">Generar cobros del mes</a>
        <a class="boton secundario" href="<?= e(url('facturas', ['a' => 'cobros'])) ?>">Planes de cobro</a>
    </div>
</section>

<?php barra_lista('facturas', '+ Registrar documento', false, [
    selector('estado_f', '', ['vigentes' => 'Todos (sin anulados)', 'por_cobrar' => 'Por cobrar'] + ESTADOS_FACTURA + ['todas' => 'Todos'], $estadoF, false, 'aria-label="Estado"'),
    '<input type="month" name="periodo" value="' . e($periodo) . '" aria-label="Período">',
    selector('cliente_id', '', opciones_clientes(false), $filtroCliente ?? '', 'Todos los clientes', 'aria-label="Cliente"'),
]); ?>
<p class="derecha"><a href="<?= e(url('facturas', ['a' => 'csv'] + $filtrosActuales)) ?>">Exportar CSV</a></p>
<table>
    <thead><tr><th>Emisión</th><th>Documento</th><th>Cliente / RUT</th><th>Glosa</th><th class="derecha">UF</th><th class="derecha">Neto</th><th class="derecha">Total</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($facturas as $f): ?>
        <tr class="<?= $f['estado'] === 'anulada' ? 'hecha' : ($f['estado'] === 'emitida' && $f['fecha_vencimiento'] && $f['fecha_vencimiento'] < date('Y-m-d') ? 'vencida' : '') ?>">
            <td class="nowrap"><?= e(fecha($f['fecha_emision'])) ?></td>
            <td class="nowrap"><?= e(TIPOS_DOCUMENTO_VENTA[$f['tipo_documento']] ?? '') ?><br><small class="tenue"><?= $f['folio'] ? 'N° ' . e($f['folio']) : 'sin folio' ?></small></td>
            <td><?= enlace('clientes', $f['cliente_id'], $f['cliente']) ?><?php if ($f['rut']): ?><br><small class="tenue"><?= e($f['rut']) ?></small><?php endif; ?></td>
            <td><a href="<?= e(url('facturas', ['a' => 'form', 'id' => $f['id']])) ?>"><?= e($f['glosa']) ?></a><?php if ($f['periodo']): ?><br><small class="tenue"><?= e($f['periodo']) ?></small><?php endif; ?></td>
            <td class="derecha nowrap"><?= $f['monto_uf'] !== null ? e(numero_corto($f['monto_uf'])) : '' ?></td>
            <td class="derecha nowrap"><?= e(dinero($f['neto'])) ?></td>
            <td class="derecha nowrap"><?= e(dinero($f['total'])) ?></td>
            <td><?= badge_factura($f['estado']) ?></td>
            <td class="derecha nowrap">
                <?php $siguiente = ['borrador' => 'emitida', 'emitida' => 'pagada'][$f['estado']] ?? null;
                if ($siguiente): ?>
                    <form method="post" action="<?= e(url('facturas', ['a' => 'estado', 'id' => $f['id']] + $filtrosActuales)) ?>" class="en-linea">
                        <?= csrf_campo() ?><input type="hidden" name="estado" value="<?= $siguiente ?>">
                        <button type="submit" class="chico secundario"><?= $siguiente === 'emitida' ? 'Marcar emitida' : '✓ Pagada' ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$facturas): ?><tr><td colspan="9" class="vacio">No hay documentos con estos filtros.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($facturas): ?>
    <tfoot><tr><th colspan="5">Total filtrado (<?= $total ?>)</th><th class="derecha nowrap"><?= e(dinero($sumas['neto'])) ?></th><th class="derecha nowrap"><?= e(dinero($sumas['total'])) ?></th><th colspan="2"></th></tr></tfoot>
    <?php endif; ?>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
