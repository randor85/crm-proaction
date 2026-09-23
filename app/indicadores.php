<?php
declare(strict_types=1);

/*
 * Indicadores económicos de Chile (UF, UTM, dólar observado).
 * Fuente: https://mindicador.cl (publica los valores del Banco Central y el SII).
 * Cada valor se consulta una sola vez y queda guardado en la tabla `indicadores`.
 */

const INDICADORES = [
    'uf'    => 'UF',
    'utm'   => 'UTM',
    'dolar' => 'Dólar',
];

/**
 * Valor de un indicador para una fecha (Y-m-d). Devuelve null si no hay dato
 * (por ejemplo, fin de semana para el dólar o sin conexión a la fuente).
 */
function indicador(string $codigo, ?string $fecha = null): ?float
{
    if (!isset(INDICADORES[$codigo])) {
        return null;
    }
    $fecha = $fecha ?: date('Y-m-d');
    // La UTM es mensual: se guarda con el día 1 del mes.
    if ($codigo === 'utm') {
        $fecha = substr($fecha, 0, 7) . '-01';
    }

    $v = q_valor('SELECT valor FROM indicadores WHERE codigo = ? AND fecha = ?', [$codigo, $fecha]);
    if ($v !== null) {
        return (float)$v;
    }

    // Evitar reintentar una consulta fallida en cada carga de página.
    $claveFallo = "indicador_fallo_{$codigo}_{$fecha}";
    if (isset($_SESSION[$claveFallo]) && time() - $_SESSION[$claveFallo] < 600) {
        return null;
    }

    $valor = indicador_consultar($codigo, $fecha);
    if ($valor === null) {
        $_SESSION[$claveFallo] = time();
        return null;
    }
    try {
        q('INSERT INTO indicadores (codigo, fecha, valor) VALUES (?, ?, ?)', [$codigo, $fecha, $valor]);
    } catch (PDOException $ex) {
        // Otro usuario lo guardó al mismo tiempo: no pasa nada.
    }
    return $valor;
}

/** Consulta mindicador.cl. */
function indicador_consultar(string $codigo, string $fecha): ?float
{
    $url = 'https://mindicador.cl/api/' . $codigo . '/' . date('d-m-Y', strtotime($fecha));
    $json = http_get($url);
    if ($json === null) {
        return null;
    }
    $datos = json_decode($json, true);
    $serie = $datos['serie'][0] ?? null;
    if (!$serie || !isset($serie['valor'])) {
        return null;
    }
    // La API devuelve el último valor conocido si la fecha no tiene dato; aceptarlo solo si coincide.
    if ($codigo !== 'utm' && substr((string)$serie['fecha'], 0, 10) !== $fecha) {
        return null;
    }
    return (float)$serie['valor'];
}

/** Guarda un valor manual (cuando la fuente no responde). */
function indicador_guardar(string $codigo, string $fecha, float $valor): void
{
    q('DELETE FROM indicadores WHERE codigo = ? AND fecha = ?', [$codigo, $fecha]);
    q('INSERT INTO indicadores (codigo, fecha, valor) VALUES (?, ?, ?)', [$codigo, $fecha, $valor]);
}

function http_get(string $url, int $timeout = 6): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'CRM ProAction',
        ]);
        $r = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($r !== false && $codigo === 200) {
            return (string)$r;
        }
        // Si cURL falla por configuración (p. ej. certificados), se intenta con los flujos de PHP.
        if (!$error || !ini_get('allow_url_fopen')) {
            return null;
        }
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: CRM ProAction\r\n"]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}

function formato_uf($valor, int $decimales = 2): string
{
    return number_format((float)$valor, $decimales, ',', '.');
}

/** Número con hasta $max decimales, sin ceros sobrantes (12,5 en vez de 12,5000). */
function numero_corto($valor, int $max = 4): string
{
    $txt = number_format((float)$valor, $max, ',', '.');
    return strpos($txt, ',') !== false ? rtrim(rtrim($txt, '0'), ',') : $txt;
}
