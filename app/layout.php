<?php
declare(strict_types=1);

const MENU = [
    'dashboard'     => 'Inicio',
    'clientes'      => 'Clientes',
    'empresas'      => 'Empresas',
    'tareas'        => 'Tareas',
    'actividades'   => 'Gestiones',
    'facturas'      => 'Facturación',
    'documentos'    => 'Documentos',
    'credenciales'  => 'Credenciales',
    'contactos'     => 'Contactos',
    'oportunidades' => 'Oportunidades',
];

function layout_inicio(string $titulo, string $rutaActiva = ''): void
{
    $u = usuario_actual();
    $app = config('app_nombre', 'CRM');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($titulo) ?> · <?= e($app) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <?php if ($u): ?><meta name="csrf" content="<?= e(csrf_token()) ?>"><?php endif; ?>
</head>
<body>
<?php if ($u): ?>
<header class="barra">
    <a class="marca" href="<?= e(url()) ?>"><?= e($app) ?></a>
    <button class="menu-movil" type="button" onclick="document.body.classList.toggle('menu-abierto')" aria-label="Menú">☰</button>
    <nav>
        <?php foreach (MENU as $ruta => $texto): ?>
            <a href="<?= e(url($ruta)) ?>" class="<?= $ruta === $rutaActiva ? 'activo' : '' ?>"><?= e($texto) ?></a>
        <?php endforeach; ?>
        <?php if (es_admin()): ?>
            <a href="<?= e(url('usuarios')) ?>" class="<?= $rutaActiva === 'usuarios' ? 'activo' : '' ?>">Usuarios</a>
            <a href="<?= e(url('sistema')) ?>" class="<?= $rutaActiva === 'sistema' ? 'activo' : '' ?>">Sistema</a>
        <?php endif; ?>
    </nav>
    <form class="buscador" method="get" action="index.php" role="search">
        <input type="hidden" name="r" value="buscar">
        <input type="search" name="q" id="buscar-global" placeholder="Buscar nombre o RUT…  ( / )" aria-label="Buscar en todo el CRM"
            value="<?= $rutaActiva === 'buscar' ? e(entrada('q')) : '' ?>">
    </form>
    <div class="usuario">
        <a href="<?= e(url('perfil')) ?>"><?= e($u['nombre']) ?></a>
        <a href="logout.php" class="salir">Salir</a>
    </div>
</header>
<?php endif; ?>
<main class="contenido">
    <?php if ($u && !empty($_SESSION['clave_debil'])): ?>
        <div class="alerta alerta-aviso">Su contraseña no cumple la política de seguridad del CRM. <a href="<?= e(url('perfil')) ?>#contrasena">Cámbiela en Mi perfil</a>.</div>
    <?php endif; ?>
    <?php foreach (tomar_flashes() as $f): ?>
        <div class="alerta alerta-<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
    <?php endforeach; ?>
<?php
}

function layout_fin(): void
{
    ?>
</main>
<script src="assets/app.js"></script>
</body>
</html>
<?php
}

/** Barra de búsqueda + botones de una lista. */
function barra_lista(string $ruta, string $textoNuevo, bool $csv = false, array $filtrosExtra = []): void
{
    ?>
    <form class="barra-lista" method="get">
        <input type="hidden" name="r" value="<?= e($ruta) ?>">
        <input type="search" name="q" value="<?= e(entrada('q')) ?>" placeholder="Buscar…">
        <?php foreach ($filtrosExtra as $html) echo $html; ?>
        <button type="submit" class="secundario">Filtrar</button>
        <span class="espaciador"></span>
        <?php if ($csv): ?>
            <a class="boton secundario" href="<?= e(url($ruta, ['a' => 'csv', 'q' => entrada('q')])) ?>">Exportar CSV</a>
        <?php endif; ?>
        <a class="boton" href="<?= e(url($ruta, ['a' => 'form'])) ?>"><?= e($textoNuevo) ?></a>
    </form>
    <?php
}

function badge_etapa(string $etapa): string
{
    return '<span class="badge etapa-' . e($etapa) . '">' . e(ETAPAS[$etapa] ?? $etapa) . '</span>';
}

const POR_PAGINA = 25;

/** Devuelve [limit, offset] para la página actual. */
function paginacion_limites(): array
{
    $pagina = max(1, entrada_int('p') ?? 1);
    return [POR_PAGINA, ($pagina - 1) * POR_PAGINA];
}

function paginacion_html(int $total): string
{
    $paginas = (int)ceil($total / POR_PAGINA);
    if ($paginas <= 1) {
        return '<p class="tenue">' . $total . ' registro(s)</p>';
    }
    $actual = max(1, entrada_int('p') ?? 1);
    $params = $_GET;
    $html = '<nav class="paginacion"><span class="tenue">' . $total . ' registros</span>';
    for ($i = 1; $i <= $paginas; $i++) {
        $params['p'] = $i;
        $html .= $i === $actual
            ? '<strong>' . $i . '</strong>'
            : '<a href="index.php?' . e(http_build_query($params)) . '">' . $i . '</a>';
    }
    return $html . '</nav>';
}

/**
 * Historial de actividades para las fichas de empresa, contacto u oportunidad.
 * @param array $vinculo p. ej. ['empresa_id' => 5], se usa para precargar la nueva actividad.
 */
function historial_actividades(array $actividades, array $vinculo): void
{
    ?>
    <section class="panel">
        <div class="encabezado">
            <h2>Gestiones</h2>
            <a href="<?= e(url('actividades', ['a' => 'form'] + $vinculo)) ?>">+ Registrar gestión</a>
        </div>
        <?php if (!$actividades): ?>
            <p class="vacio">Sin gestiones registradas.</p>
        <?php else: ?>
        <ul class="linea-tiempo">
            <?php foreach ($actividades as $a): ?>
            <li class="<?= $a['completada'] ? 'hecha' : '' ?>">
                <span class="badge"><?= e(TIPOS_ACTIVIDAD[$a['tipo']] ?? $a['tipo']) ?></span>
                <a href="<?= e(url('actividades', ['a' => 'form', 'id' => $a['id']])) ?>"><?= e($a['asunto']) ?></a>
                <small class="tenue"><?= e(fecha($a['fecha'], true)) ?> · <?= e($a['usuario'] ?? '') ?><?= $a['completada'] ? ' · completada' : '' ?></small>
                <?php if ($a['descripcion']): ?><div class="notas"><?= nl2br(e($a['descripcion'])) ?></div><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php
}

/* ---------------- Gestión tributaria ---------------- */

function badge_tarea(string $estado): string
{
    return '<span class="badge tarea-' . e($estado) . '">' . e(ESTADOS_TAREA[$estado] ?? $estado) . '</span>';
}

function badge_factura(string $estado): string
{
    return '<span class="badge factura-' . e($estado) . '">' . e(ESTADOS_FACTURA[$estado] ?? $estado) . '</span>';
}

/** Clase CSS según cercanía del vencimiento: vencida, pronto (3 días) o nada. */
function clase_vencimiento(?string $vencimiento, string $estado = 'pendiente'): string
{
    if (!$vencimiento || $estado === 'completada') {
        return '';
    }
    $hoy = date('Y-m-d');
    if ($vencimiento < $hoy) {
        return 'vencida';
    }
    return $vencimiento <= date('Y-m-d', strtotime('+3 days')) ? 'pronto' : '';
}

/**
 * Selector de empresa (RUT) cuyas opciones se filtran según el cliente elegido
 * en el selector #f_cliente_id (ver assets/app.js).
 */
function selector_empresa(string $nombre, string $etiqueta, $actual = '', $vacio = true): string
{
    $id = 'f_' . $nombre;
    $filas = q_todos('SELECT id, nombre, identificacion, cliente_id FROM empresas ORDER BY nombre');
    $html = '<label for="' . $id . '">' . e($etiqueta) . '</label>'
        . '<select id="' . $id . '" name="' . e($nombre) . '" data-filtrar-cliente>';
    if ($vacio) {
        $html .= '<option value="">' . e(is_string($vacio) ? $vacio : '— Seleccione —') . '</option>';
    }
    foreach ($filas as $f) {
        $sel = ((string)$f['id'] === (string)$actual) ? ' selected' : '';
        $texto = $f['nombre'] . ($f['identificacion'] ? ' (' . $f['identificacion'] . ')' : '');
        $html .= '<option value="' . e($f['id']) . '" data-cliente="' . e($f['cliente_id']) . '"' . $sel . '>' . e($texto) . '</option>';
    }
    return $html . '</select>';
}

/** @param array $vinculo p. ej. ['cliente_id' => 3] o ['empresa_id' => 8] */
function panel_tareas(array $tareas, array $vinculo, string $titulo = 'Tareas pendientes'): void
{
    ?>
    <section class="panel">
        <div class="encabezado">
            <h2><?= e($titulo) ?></h2>
            <a href="<?= e(url('tareas', ['a' => 'form'] + $vinculo)) ?>">+ Nueva tarea</a>
        </div>
        <?php if (!$tareas): ?>
            <p class="vacio">Sin tareas pendientes.</p>
        <?php else: ?>
        <table><tbody>
        <?php foreach ($tareas as $t): ?>
            <tr class="<?= clase_vencimiento($t['vencimiento'], $t['estado']) ?>">
                <td class="nowrap"><?= e(fecha($t['vencimiento'])) ?></td>
                <td><a href="<?= e(url('tareas', ['a' => 'ver', 'id' => $t['id']])) ?>"><?= e($t['titulo']) ?></a>
                    <?php if (!empty($t['empresa'])): ?><small class="tenue"> · <?= e($t['empresa']) ?></small><?php endif; ?></td>
                <td><?= badge_tarea($t['estado']) ?></td>
                <td class="tenue"><?= e($t['responsable'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>
    <?php
}

function panel_facturas(array $facturas, array $vinculo): void
{
    ?>
    <section class="panel">
        <div class="encabezado">
            <h2>Facturación</h2>
            <a href="<?= e(url('facturas', ['a' => 'form'] + $vinculo)) ?>">+ Registrar documento</a>
        </div>
        <?php if (!$facturas): ?>
            <p class="vacio">Sin documentos registrados.</p>
        <?php else: ?>
        <table>
            <thead><tr><th>Emisión</th><th>Documento</th><th>Glosa</th><th>Estado</th><th class="derecha">Total</th></tr></thead>
            <tbody>
            <?php foreach ($facturas as $f): ?>
                <tr>
                    <td class="nowrap"><?= e(fecha($f['fecha_emision'])) ?></td>
                    <td><?= e(TIPOS_DOCUMENTO_VENTA[$f['tipo_documento']] ?? '') ?><?= $f['folio'] ? ' N° ' . e($f['folio']) : '' ?></td>
                    <td><a href="<?= e(url('facturas', ['a' => 'form', 'id' => $f['id']])) ?>"><?= e($f['glosa']) ?></a></td>
                    <td><?= badge_factura($f['estado']) ?></td>
                    <td class="derecha nowrap"><?= e(dinero($f['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
    <?php
}

function panel_documentos(array $documentos, array $vinculo): void
{
    ?>
    <section class="panel">
        <div class="encabezado">
            <h2>Documentos</h2>
            <a href="<?= e(url('documentos', ['a' => 'form'] + $vinculo)) ?>">+ Subir documento</a>
        </div>
        <?php if (!$documentos): ?>
            <p class="vacio">Sin documentos.</p>
        <?php else: ?>
        <table><tbody>
        <?php foreach ($documentos as $d): ?>
            <tr>
                <td><a href="<?= e(url('documentos', ['a' => 'descargar', 'id' => $d['id']])) ?>"><?= e($d['nombre']) ?></a>
                    <?php if (!empty($d['empresa'])): ?><small class="tenue"> · <?= e($d['empresa']) ?></small><?php endif; ?></td>
                <td><span class="badge"><?= e(CATEGORIAS_DOCUMENTO[$d['categoria']] ?? $d['categoria']) ?></span></td>
                <td class="tenue nowrap"><?= e($d['periodo'] ?? '') ?></td>
                <td class="tenue nowrap derecha"><?= e(tamano_legible((int)$d['tamano'])) ?> · <?= e(fecha($d['creado_en'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <p><a href="<?= e(url('documentos', $vinculo)) ?>">Ver todos los documentos →</a></p>
    </section>
    <?php
}

function panel_credenciales(array $credenciales, array $vinculo): void
{
    ?>
    <section class="panel">
        <div class="encabezado">
            <h2>Credenciales</h2>
            <?php if (puede_ver_credenciales()): ?><a href="<?= e(url('credenciales', ['a' => 'form'] + $vinculo)) ?>">+ Agregar</a><?php endif; ?>
        </div>
        <?php if (!puede_ver_credenciales()): ?>
            <p class="vacio"><?= credenciales_requieren_2fa() ? 'Necesita permiso de credenciales y verificación en dos pasos activa para ver esta sección.' : 'Necesita permiso de credenciales (lo otorga un administrador en Usuarios).' ?></p>
        <?php elseif (!$credenciales): ?>
            <p class="vacio">Sin credenciales guardadas.</p>
        <?php else: ?>
        <table><tbody>
        <?php foreach ($credenciales as $c): ?>
            <tr>
                <td><a href="<?= e(url('credenciales', ['a' => 'ver', 'id' => $c['id']])) ?>"><?= e($c['institucion']) ?></a>
                    <?php if (!empty($c['empresa'])): ?><small class="tenue"> · <?= e($c['empresa']) ?></small><?php endif; ?></td>
                <td><?= e($c['usuario']) ?></td>
                <td class="tenue">••••••••</td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * Selector de una etiqueta de texto libre (categoría, situación): lista de valores existentes
 * más "Otra…", que muestra un campo para escribir una nueva (ver assets/app.js).
 * @param array $especiales opciones extra al inicio, valor => texto (p. ej. no cambiar / quitar)
 */
function selector_etiqueta(string $nombre, string $etiqueta, array $valores, ?string $actual, array $especiales = ['' => '— Sin asignar —']): string
{
    $id = 'f_' . $nombre;
    $html = ($etiqueta !== '' ? '<label for="' . $id . '">' . e($etiqueta) . '</label>' : '')
        . '<div class="etiqueta-libre"><select id="' . $id . '" name="' . e($nombre) . '" data-etiqueta>';
    foreach ($especiales + ($valores ? array_combine($valores, $valores) : []) as $v => $t) {
        $html .= '<option value="' . e($v) . '"' . ((string)$v === (string)$actual ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    return $html . '<option value="__otra__">Otra… (escribir nueva)</option></select>'
        . '<input type="text" name="' . e($nombre) . '_nueva" maxlength="80" placeholder="Escriba la nueva" hidden aria-label="Nueva ' . e(mb_strtolower($etiqueta ?: $nombre)) . '"></div>';
}
