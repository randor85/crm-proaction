<?php
declare(strict_types=1);

/*
 * Planes de cobro: cobros recurrentes de cada cliente (tabla `cobros`).
 * Un cobro se factura en los meses que le corresponden según su periodicidad,
 * contando desde `mes_inicio` (p. ej. trimestral desde enero = ene, abr, jul, oct;
 * anual con mes_inicio 4 = cada abril).
 */

const PERIODICIDADES = [
    'mensual'    => 'Mensual',
    'trimestral' => 'Trimestral',
    'semestral'  => 'Semestral',
    'anual'      => 'Anual',
];

const MESES_POR_PERIODO = ['mensual' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
    'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** Meses (1-12) en que se factura un cobro. */
function cobro_meses(array $cobro): array
{
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
    $meses = cobro_meses($cobro);
    if ($p === 'anual') {
        return 'cada ' . MESES[$meses[0]];
    }
    return implode(', ', array_map(static fn($m) => mb_substr(MESES[$m], 0, 3), $meses));
}

function cobro_monto_texto(array $cobro): string
{
    return $cobro['moneda'] === 'UF' ? 'UF ' . numero_corto($cobro['monto']) : dinero($cobro['monto']);
}

/** Texto corto para listas: "UF 12 mensual" / "UF 3 trimestral (ene, abr, jul, oct)" */
function cobro_resumen(array $cobro): string
{
    $p = $cobro['periodicidad'];
    return cobro_monto_texto($cobro) . ' ' . mb_strtolower(PERIODICIDADES[$p] ?? $p)
        . ($p === 'mensual' ? '' : ' (' . cobro_calendario($cobro) . ')');
}

/** Glosa del documento para el período YYYY-MM en que se factura. */
function cobro_glosa(array $cobro, string $periodo): string
{
    $anio = (int)substr($periodo, 0, 4);
    $mes = (int)substr($periodo, 5, 2);
    $cada = MESES_POR_PERIODO[$cobro['periodicidad']] ?? 1;
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

/** Veces que se factura en un año (para estimar el ingreso anual). */
function cobro_veces_anio(array $cobro): int
{
    return intdiv(12, MESES_POR_PERIODO[$cobro['periodicidad']] ?? 1);
}

/** Cobros activos (con datos del cliente) que corresponden a un período YYYY-MM. */
function cobros_del_periodo(string $periodo): array
{
    $mes = (int)substr($periodo, 5, 2);
    $filas = q_todos(
        'SELECT k.*, c.nombre AS cliente, e.nombre AS empresa, e.identificacion AS rut,
            (SELECT f.id FROM facturas f WHERE f.cobro_id = k.id AND f.periodo = ? AND f.estado <> \'anulada\' ORDER BY f.id LIMIT 1) AS factura_id
         FROM cobros k JOIN clientes c ON c.id = k.cliente_id LEFT JOIN empresas e ON e.id = k.empresa_id
         WHERE k.activo = 1 AND c.activo = 1 AND k.monto > 0
         ORDER BY c.nombre, k.concepto', [$periodo]);
    return array_values(array_filter($filas, static fn($k) => cobro_aplica($k, $mes)));
}
