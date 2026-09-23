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
    'tramite' => 'Trámite',
    'tarea'   => 'Tarea',
    'nota'    => 'Nota',
];

const ROLES = [
    'admin'   => 'Administrador',
    'usuario' => 'Usuario',
];

const TIPOS_CLIENTE = [
    'empresa' => 'Empresa',
    'grupo'   => 'Grupo de empresas',
    'persona' => 'Persona natural',
];

const ESTADOS_TAREA = [
    'pendiente'  => 'Pendiente',
    'en_proceso' => 'En proceso',
    'esperando'  => 'Esperando al cliente',
    'completada' => 'Completada',
];

const PRIORIDADES = [
    'baja'    => 'Baja',
    'normal'  => 'Normal',
    'alta'    => 'Alta',
];

const RECURRENCIAS = [
    'ninguna'    => 'No se repite',
    'mensual'    => 'Mensual',
    'trimestral' => 'Trimestral',
    'semestral'  => 'Semestral',
    'anual'      => 'Anual',
];

/** Sugerencias para el título de las tareas (obligaciones habituales). */
const OBLIGACIONES = [
    'Declaración mensual F29',
    'Declaración anual de renta F22',
    'Declaraciones juradas anuales',
    'DJ 1887 (sueldos)',
    'DJ 1879 (honorarios)',
    'Libro de remuneraciones electrónico (LRE)',
    'Pago de cotizaciones Previred',
    'Registro de compras y ventas (RCV)',
    'Balance y estados financieros',
    'Renovación de patente municipal',
    'Actualización de información SII',
    'Revisión de situación tributaria',
    'Término de giro',
    'Inicio de actividades',
];

const TIPOS_DOCUMENTO_VENTA = [
    'factura_afecta' => 'Factura afecta',
    'factura_exenta' => 'Factura exenta',
    'boleta_honorarios' => 'Boleta de honorarios',
];

const ESTADOS_FACTURA = [
    'borrador' => 'Borrador',
    'emitida'  => 'Emitida',
    'pagada'   => 'Pagada',
    'anulada'  => 'Anulada',
];

const CATEGORIAS_DOCUMENTO = [
    'legal'       => 'Legal / societario',
    'tributario'  => 'Tributario',
    'contable'    => 'Contable',
    'laboral'     => 'Laboral / remuneraciones',
    'bancario'    => 'Bancario',
    'contrato'    => 'Contrato de servicios',
    'otro'        => 'Otro',
];

/** Sugerencias de institución para el gestor de credenciales. */
const INSTITUCIONES = [
    'SII', 'Previred', 'Dirección del Trabajo', 'Tesorería General', 'Municipalidad',
    'Mutual de Seguridad', 'ACHS', 'IST', 'AFC', 'Banco', 'ClaveÚnica', 'Registro de Empresas y Sociedades',
    'Conservador de Bienes Raíces', 'Portal de facturación',
];

const IVA = 0.19;

/* ---------------- RUT ---------------- */

function rut_limpiar(string $rut): string
{
    return strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
}

function rut_valido(string $rut): bool
{
    $rut = rut_limpiar($rut);
    if (strlen($rut) < 2) {
        return false;
    }
    $cuerpo = substr($rut, 0, -1);
    $dv = substr($rut, -1);
    if (!ctype_digit($cuerpo)) {
        return false;
    }
    $suma = 0;
    $factor = 2;
    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += (int)$cuerpo[$i] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $esperado = 11 - ($suma % 11);
    $esperado = $esperado === 11 ? '0' : ($esperado === 10 ? 'K' : (string)$esperado);
    return $dv === $esperado;
}

/** 12345678K → 12.345.678-K */
function rut_formatear(string $rut): string
{
    $rut = rut_limpiar($rut);
    if (strlen($rut) < 2) {
        return $rut;
    }
    return number_format((int)substr($rut, 0, -1), 0, '', '.') . '-' . substr($rut, -1);
}

/**
 * Normaliza un RUT ingresado en un formulario.
 * Devuelve [valor|null, error|null].
 */
function rut_entrada(string $campo): array
{
    $v = entrada($campo);
    if ($v === '') {
        return [null, null];
    }
    if (!rut_valido($v)) {
        return [null, "El RUT $v no es válido (revise el dígito verificador)."];
    }
    return [rut_formatear($v), null];
}

/** Entrada numérica con coma o punto decimal ("1.234,56" o "1234.56"). */
function entrada_decimal(string $clave): ?float
{
    $v = str_replace([' ', '$'], '', entrada($clave));
    if ($v === '') {
        return null;
    }
    // Formato chileno: "1.234.567,89" o "1.000.000" (punto como separador de miles)
    if (strpos($v, ',') !== false || preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

function entrada_fecha(string $clave): ?string
{
    $v = entrada($clave);
    $ts = $v !== '' ? strtotime($v) : false;
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Enlace a la ficha de un cliente o empresa (o texto vacío). */
function enlace(string $ruta, $id, $texto): string
{
    if (!$id) {
        return e($texto);
    }
    return '<a href="' . e(url($ruta, ['a' => 'ver', 'id' => $id])) . '">' . e($texto) . '</a>';
}

function tamano_legible(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

/** Carpeta de los documentos subidos (fuera de public_html en el servidor). */
function documentos_ruta(): string
{
    return rtrim((string)config('documentos_ruta', BASE_DIR . '/data/documentos'), '/\\');
}

/** Valor de un selector_etiqueta(): el elegido o el escrito en "Otra…", en mayúsculas; null si está vacío. */
function entrada_etiqueta(string $nombre): ?string
{
    $v = entrada($nombre);
    if ($v === '__otra__') {
        $v = entrada($nombre . '_nueva');
    }
    return nulo_si_vacio(mb_strtoupper(trim($v)));
}
