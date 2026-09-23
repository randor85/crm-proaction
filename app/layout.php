<?php
declare(strict_types=1);

const MENU = [
    'dashboard'     => 'Inicio',
    'empresas'      => 'Empresas',
    'contactos'     => 'Contactos',
    'oportunidades' => 'Oportunidades',
    'actividades'   => 'Actividades',
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
        <?php endif; ?>
    </nav>
    <div class="usuario">
        <a href="<?= e(url('perfil')) ?>"><?= e($u['nombre']) ?></a>
        <a href="logout.php" class="salir">Salir</a>
    </div>
</header>
<?php endif; ?>
<main class="contenido">
    <?php foreach (tomar_flashes() as $f): ?>
        <div class="alerta alerta-<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
    <?php endforeach; ?>
<?php
}

function layout_fin(): void
{
    ?>
</main>
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
            <h2>Actividades</h2>
            <a href="<?= e(url('actividades', ['a' => 'form'] + $vinculo)) ?>">+ Registrar actividad</a>
        </div>
        <?php if (!$actividades): ?>
            <p class="vacio">Sin actividades registradas.</p>
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
