<?php
declare(strict_types=1);

/** Escapa texto para HTML. */
function e($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $ruta = 'dashboard', array $params = []): string
{
    return 'index.php?' . http_build_query(['r' => $ruta] + $params);
}

function redirigir(string $destino): void
{
    header('Location: ' . $destino);
    exit;
}

function ahora(): string
{
    return date('Y-m-d H:i:s');
}

function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function tomar_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verificar(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Token de seguridad inválido. Vuelva atrás y recargue la página.');
    }
}

function es_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ---------------- Entrada ---------------- */

function entrada(string $clave, string $defecto = ''): string
{
    $v = $_POST[$clave] ?? $_GET[$clave] ?? $defecto;
    return is_string($v) ? trim($v) : $defecto;
}

function entrada_int(string $clave): ?int
{
    $v = entrada($clave);
    return ctype_digit($v) ? (int)$v : null;
}

/** Devuelve null si el texto está vacío (útil para columnas opcionales). */
function nulo_si_vacio(string $v): ?string
{
    return $v === '' ? null : $v;
}

/* ---------------- Formato ---------------- */

function dinero($monto): string
{
    return config('moneda', '$') . ' ' . number_format((float)$monto, (int)config('decimales', 0), ',', '.');
}

function fecha($valor, bool $conHora = false): string
{
    if (!$valor) {
        return '';
    }
    $ts = strtotime((string)$valor);
    return $ts ? date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '';
}

/* ---------------- Formularios ---------------- */

function campo(string $nombre, string $etiqueta, $valor = '', string $tipo = 'text', string $extra = ''): string
{
    $id = 'f_' . $nombre;
    return '<label for="' . $id . '">' . e($etiqueta) . '</label>'
        . '<input type="' . e($tipo) . '" id="' . $id . '" name="' . e($nombre) . '" value="' . e($valor) . '" ' . $extra . '>';
}

function area(string $nombre, string $etiqueta, $valor = ''): string
{
    $id = 'f_' . $nombre;
    return '<label for="' . $id . '">' . e($etiqueta) . '</label>'
        . '<textarea id="' . $id . '" name="' . e($nombre) . '" rows="4">' . e($valor) . '</textarea>';
}

/**
 * @param array       $opciones valor => texto
 * @param bool|string $vacio    true = opción vacía genérica; string = texto de la opción vacía
 */
function selector(string $nombre, string $etiqueta, array $opciones, $actual = '', $vacio = true, string $extra = ''): string
{
    $id = 'f_' . $nombre;
    $html = '<label for="' . $id . '">' . e($etiqueta) . '</label>'
        . '<select id="' . $id . '" name="' . e($nombre) . '" ' . $extra . '>';
    if ($vacio) {
        $html .= '<option value="">' . e(is_string($vacio) ? $vacio : '— Seleccione —') . '</option>';
    }
    foreach ($opciones as $valor => $texto) {
        $sel = ((string)$valor === (string)$actual) ? ' selected' : '';
        $html .= '<option value="' . e($valor) . '"' . $sel . '>' . e($texto) . '</option>';
    }
    return $html . '</select>';
}

/** Formulario POST de un solo botón (para eliminar, completar, etc.). */
function boton_post(string $accion, string $texto, string $clase = '', string $confirmar = ''): string
{
    $onsubmit = $confirmar !== '' ? ' onsubmit="return confirm(\'' . e($confirmar) . '\')"' : '';
    return '<form method="post" action="' . e($accion) . '" class="en-linea"' . $onsubmit . '>'
        . csrf_campo() . '<button type="submit" class="' . e($clase) . '">' . e($texto) . '</button></form>';
}

/* ---------------- CSV ---------------- */

function exportar_csv(string $archivo, array $encabezados, array $filas): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel reconozca UTF-8
    fputcsv($out, $encabezados, ';', '"', '');
    foreach ($filas as $fila) {
        // Evitar inyección de fórmulas en Excel
        $fila = array_map(static function ($v) {
            $v = (string)($v ?? '');
            return ($v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
        }, $fila);
        fputcsv($out, $fila, ';', '"', '');
    }
    fclose($out);
    exit;
}

/* ---------------- Catálogos ---------------- */

const ETAPAS = [
    'prospecto'   => 'Prospecto',
    'calificado'  => 'Calificado',
    'propuesta'   => 'Propuesta',
    'negociacion' => 'Negociación',
    'ganada'      => 'Ganada',
    'perdida'     => 'Perdida',
];

const TIPOS_ACTIVIDAD = [
    'llamada' => 'Llamada',
    'reunion' => 'Reunión',
    'email'   => 'Correo',
    'tarea'   => 'Tarea',
    'nota'    => 'Nota',
];

const ROLES = [
    'admin'   => 'Administrador',
    'usuario' => 'Usuario',
];
