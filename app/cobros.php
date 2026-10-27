<?php
declare(strict_types=1);

/*
 * Planes de cobro: cobros recurrentes de cada cliente (tabla `cobros`).
 * Un cobro se factura en los meses que le corresponden según su periodicidad,
 * contando desde `mes_inicio` (p. ej. trimestral desde enero = ene, abr, jul, oct;
 * anual con mes_inicio 4 = cada abril).
 * «Única vez» se factura solo en el mes `periodo_unico` (YYYY-MM); «Variable» (comisiones, cobros por tarea)
 * no se programa: se factura a pedido desde la ficha del cliente, con el monto de cada vez.
 */

const PERIODICIDADES_RECURRENTES = [
    'mensual'    => 'Mensual',
    'trimestral' => 'Trimestral',
    'semestral'  => 'Semestral',
    'anual'      => 'Anual',
];

const PERIODICIDADES = PERIODICIDADES_RECURRENTES + [
    'unico'    => 'Única vez',
    'variable' => 'Variable (comisión o por tarea)',
];

const MESES_POR_PERIODO = ['mensual' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
    'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** Meses (1-12) en que se factura un cobro. */
function cobro_meses(array $cobro): array
{
    if ($cobro['periodicidad'] === 'unico') {
        return !empty($cobro['periodo_unico']) ? [(int)substr($cobro['periodo_unico'], 5, 2)] : [];
    }
    if ($cobro['periodicidad'] === 'variable') {
        return [];
    }
    $cada = MESES_POR_PERIODO[$cobro['periodicidad']] ?? 1;
    $inicio = max(1, min(12, (int)$cobro['mes_inicio']));
    $meses = [];
    for ($m = $inicio; count($meses) < 12 / $cada; $m += $cada) {
        $meses[] = (($m - 1) % 12) + 1;
    }
    sort($meses);
    return $meses;
}

function cobro_aplica(array $cobro, int $mes): bool
{
    return in_array($mes, cobro_meses($cobro), true);
}

/** "trimestral: ene, abr, jul, oct" / "anual: abril" / "mensual" */
function cobro_calendario(array $cobro): string
{
    $p = $cobro['periodicidad'];
    if ($p === 'mensual') {
        return 'todos los meses';
    }
    if ($p === 'variable') {
        return 'se factura a pedido';
    }
    if ($p === 'unico') {
        $per = (string)($cobro['periodo_unico'] ?? '');
        return $per ? 'en ' . MESES[(int)substr($per, 5, 2)] . ' ' . substr($per, 0, 4) : 'sin mes definido';
    }
    $meses = cobro_meses($cobro);
    if ($p === 'anual') {
        return 'cada ' . MESES[$meses[0]];
    }
    return implode(', ', array_map(static fn($m) => mb_substr(MESES[$m], 0, 3), $meses));
}

function cobro_monto_texto(array $cobro): string
{
    if ((float)$cobro['monto'] <= 0) {
        return 'Monto a definir';
    }
    return $cobro['moneda'] === 'UF' ? 'UF ' . numero_corto($cobro['monto']) : dinero($cobro['monto']);
}

/** Texto corto para listas: "UF 12 mensual" / "UF 3 trimestral (ene, abr, jul, oct)" */
function cobro_resumen(array $cobro): string
{
    $p = $cobro['periodicidad'];
    if ($p === 'variable') {
        return 'cobro variable, se factura a pedido' . ((float)$cobro['monto'] > 0 ? ' (referencia ' . cobro_monto_texto($cobro) . ')' : '');
    }
    if ($p === 'unico') {
        return cobro_monto_texto($cobro) . ' por única vez, ' . cobro_calendario($cobro);
    }
    return cobro_monto_texto($cobro) . ' ' . mb_strtolower(PERIODICIDADES[$p] ?? $p)
        . ($p === 'mensual' ? '' : ' (' . cobro_calendario($cobro) . ')');
}

/** Glosa del documento para el período YYYY-MM en que se factura. */
function cobro_glosa(array $cobro, string $periodo): string
{
    $anio = (int)substr($periodo, 0, 4);
    $mes = (int)substr($periodo, 5, 2);
    if (!isset(MESES_POR_PERIODO[$cobro['periodicidad']])) {
        return $cobro['concepto']; // única vez o variable: la glosa es el concepto
    }
    $cada = MESES_POR_PERIODO[$cobro['periodicidad']];
    if ($cada === 1) {
        $texto = MESES[$mes] . ' ' . $anio;
    } elseif ($cada === 12) {
        $texto = (string)$anio;
    } else {
        // Período que cubre el cobro: desde el mes de facturación, $cada meses
        $fin = new DateTime(sprintf('%04d-%02d-01', $anio, $mes));
        $fin->modify('+' . ($cada - 1) . ' months');
        $mesFin = (int)$fin->format('n');
        $anioFin = (int)$fin->format('Y');
        $texto = MESES[$mes] . ($anioFin !== $anio ? " $anio" : '') . ' a ' . MESES[$mesFin] . " $anioFin";
    }
    return $cobro['concepto'] . ' ' . $texto;
}

/** Próximo YYYY-MM con ese mes (este año si aún no pasa, si no el siguiente). */
function proximo_periodo(int $mes): string
{
    $anio = (int)date('Y') + ($mes < (int)date('n') ? 1 : 0);
    return sprintf('%04d-%02d', $anio, $mes);
}

/** Veces que se factura en un año (para estimar el ingreso anual). */
function cobro_veces_anio(array $cobro): int
{
    if ($cobro['periodicidad'] === 'unico') {
        return substr((string)($cobro['periodo_unico'] ?? ''), 0, 4) === date('Y') ? 1 : 0;
    }
    if ($cobro['periodicidad'] === 'variable') {
        return 0;
    }
    return intdiv(12, MESES_POR_PERIODO[$cobro['periodicidad']] ?? 1);
}

/**
 * Cobros activos (con datos del cliente) que corresponden a un período YYYY-MM.
 * Con $conVariables agrega al final los cobros variables (comisiones, por tarea), que se eligen a mano.
 */
function cobros_del_periodo(string $periodo, bool $conVariables = false): array
{
    $mes = (int)substr($periodo, 5, 2);
    $filas = q_todos(
        'SELECT k.*, c.nombre AS cliente, e.nombre AS empresa, e.identificacion AS rut,
            (SELECT f.id FROM facturas f WHERE f.cobro_id = k.id AND f.periodo = ? AND f.estado <> \'anulada\' ORDER BY f.id LIMIT 1) AS factura_id
         FROM cobros k JOIN clientes c ON c.id = k.cliente_id LEFT JOIN empresas e ON e.id = k.empresa_id
         WHERE k.activo = 1 AND c.activo = 1 AND (k.monto > 0 OR k.periodicidad = \'variable\')
         ORDER BY c.nombre, k.concepto', [$periodo]);
    $programados = array_filter($filas, static fn($k) => (float)$k['monto'] > 0 && ($k['periodicidad'] === 'unico'
        ? ($k['periodo_unico'] ?? '') === $periodo : cobro_aplica($k, $mes)));
    $variables = $conVariables ? array_filter($filas, static fn($k) => $k['periodicidad'] === 'variable') : [];
    return array_values(array_merge($programados, $variables));
}

/* ---------------- Tarifas de prospectos (lo que se cotiza antes de ser cliente) ---------------- */

const TARIFAS = PERIODICIDADES_RECURRENTES + ['unico' => 'Servicio único'];

const SERVICIOS = [
    'Contabilidad mensual completa', 'IVA mensual (F29)', 'Remuneraciones', 'Declaración de renta (F22)',
    'Asesoría tributaria', 'Inicio de actividades', 'Término de giro', 'Regularización tributaria',
    'Constitución de sociedad', 'Rectificatoria / revisión SII',
];

/** "UF 3 mensual", "$ 250.000 único", "UF 6 anual (abril)". */
function tarifa_texto(array $o): string
{
    if ((float)$o['monto'] <= 0) {
        return '';
    }
    $monto = $o['tarifa_moneda'] === 'UF' ? 'UF ' . numero_corto($o['monto']) : dinero($o['monto']);
    $tipo = mb_strtolower(TARIFAS[$o['tarifa_tipo']] ?? '');
    $mes = in_array($o['tarifa_tipo'], ['anual', 'unico'], true) && !empty($o['tarifa_mes']) ? ' (' . MESES[(int)$o['tarifa_mes']] . ')' : '';
    return "$monto $tipo$mes";
}

/** Equivalente mensual en pesos (para sumar el embudo). El servicio único no se mensualiza: devuelve null. */
function tarifa_mensual_clp(array $o, ?float $uf): ?float
{
    $meses = MESES_POR_PERIODO[$o['tarifa_tipo']] ?? null;
    if (!$meses || (float)$o['monto'] <= 0) {
        return null;
    }
    $pesos = $o['tarifa_moneda'] === 'UF' ? ($uf ? (float)$o['monto'] * $uf : null) : (float)$o['monto'];
    return $pesos === null ? null : $pesos / $meses;
}
