<?php
declare(strict_types=1);

$id = entrada_int('id');

/* ---------- Requisitos de acceso ---------- */
if (!puede_ver_credenciales() || !llave_existe()) {
    $u = usuario_actual();
    layout_inicio('Credenciales', 'credenciales');
    ?>
    <h1>Credenciales</h1>
    <section class="panel">
        <p>El gestor de credenciales guarda las claves de los clientes (SII, Previred, municipalidades, bancos…) cifradas con AES-256.
            Para usarlo se necesita:</p>
        <ul class="lista-requisitos">
            <li class="<?= (int)$u['ver_credenciales'] === 1 ? 'cumplido' : '' ?>">Permiso de credenciales otorgado por un administrador (en <em>Usuarios</em>).</li>
            <?php if (credenciales_requieren_2fa()): ?>
            <li class="<?= $u['totp_activo'] ? 'cumplido' : '' ?>">Verificación en dos pasos activa en su cuenta: <a href="<?= e(url('perfil')) ?>#dos-pasos">actívela en Mi perfil</a>.</li>
            <?php endif; ?>
            <li class="<?= llave_existe() ? 'cumplido' : '' ?>">Llave de cifrado configurada en el servidor<?= es_admin() ? ' (<a href="' . e(url('sistema')) . '">configurar en Sistema</a>)' : ' (tarea del administrador)' ?>.</li>
        </ul>
    </section>
    <?php
    layout_fin();
    return;
}

$uid = (int)usuario_actual()['id'];
// Las páginas con claves no deben quedar en la caché del navegador.
header('Cache-Control: no-store, max-age=0');

/* ---------- Guardar ---------- */
if ($accion === 'guardar' && es_post()) {
    $datos = [
        'cliente_id'      => entrada_int('cliente_id'),
        'empresa_id'      => entrada_int('empresa_id'),
        'institucion'     => entrada('institucion'),
        'usuario'         => nulo_si_vacio(entrada('usuario')),
        'url'             => nulo_si_vacio(entrada('url')),
        'notas'           => nulo_si_vacio(entrada('notas')),
        'actualizado_por' => $uid,
        'actualizado_en'  => ahora(),
    ];
    if (!$datos['cliente_id'] && $datos['empresa_id']) {
        $datos['cliente_id'] = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$datos['empresa_id']]);
    }
    if ($datos['url'] && !preg_match('#^https?://#i', $datos['url'])) {
        $datos['url'] = 'https://' . $datos['url'];
    }
    $clave = (string)($_POST['clave'] ?? '');
    if ($clave !== '') {
        $datos['clave_cifrada'] = cifrar($clave);
    }
    if ($datos['institucion'] === '' || (!$datos['cliente_id'] && !$datos['empresa_id'])) {
        flash('error', 'Indique la institución y el cliente o RUT al que pertenece.');
        redirigir(url('credenciales', ['a' => 'form', 'id' => $id]));
    }
    if ($id) {
        actualizar('credenciales', $id, $datos);
        registrar_credencial($id, 'editar', $clave !== '' ? 'Cambió la clave' : 'Editó los datos');
        flash('ok', 'Credencial actualizada.');
    } else {
        $datos['creado_en'] = ahora();
        $id = insertar('credenciales', $datos);
        registrar_credencial($id, 'crear');
        flash('ok', 'Credencial guardada.');
    }
    redirigir(url('credenciales', ['a' => 'ver', 'id' => $id]));
}

/* ---------- Mostrar clave sin salir de la página (buscador) ---------- */
if ($accion === 'revelar_json' && es_post() && $id) {
    header('Content-Type: application/json; charset=UTF-8');
    $k = q_uno('SELECT id, clave_cifrada FROM credenciales WHERE id = ?', [$id]);
    if (!$k) {
        exit(json_encode(['error' => 'La credencial no existe.']));
    }
    if (!clave_confirmada()) {
        $clave = (string)($_POST['clave_usuario'] ?? '');
        if ($clave === '') {
            exit(json_encode(['pedir_clave' => true]));
        }
        if (login_bloqueado()) {
            exit(json_encode(['error' => 'Demasiados intentos fallidos. Espere ' . MINUTOS_BLOQUEO . ' minutos.']));
        }
        if (!confirmar_clave($clave)) {
            exit(json_encode(['error' => 'Contraseña incorrecta.', 'pedir_clave' => true]));
        }
    }
    try {
        $texto = descifrar($k['clave_cifrada']);
        registrar_credencial($id, 'ver', 'Desde el buscador');
        exit(json_encode(['clave' => $texto], JSON_UNESCAPED_UNICODE));
    } catch (RuntimeException $ex) {
        exit(json_encode(['error' => $ex->getMessage()]));
    }
}

/* ---------- Eliminar ---------- */
if ($accion === 'eliminar' && es_post() && $id) {
    $k = q_uno('SELECT * FROM credenciales WHERE id = ?', [$id]);
    registrar_credencial($id, 'eliminar', ($k['institucion'] ?? '') . ' · ' . ($k['usuario'] ?? ''));
    q('DELETE FROM credenciales WHERE id = ?', [$id]);
    flash('ok', 'Credencial eliminada.');
    redirigir($k && $k['cliente_id'] ? url('clientes', ['a' => 'ver', 'id' => $k['cliente_id']]) : url('credenciales'));
}

/* ---------- Formulario ---------- */
if ($accion === 'form') {
    $k = $id ? q_uno('SELECT * FROM credenciales WHERE id = ?', [$id])
        : ['cliente_id' => entrada_int('cliente_id'), 'empresa_id' => entrada_int('empresa_id')];
    if ($id && !$k) {
        redirigir(url('credenciales'));
    }
    if (!$id && !$k['cliente_id'] && $k['empresa_id']) {
        $k['cliente_id'] = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$k['empresa_id']]);
    }
    layout_inicio($id ? 'Editar credencial' : 'Nueva credencial', 'credenciales');
    ?>
    <h1><?= $id ? 'Editar credencial' : 'Nueva credencial' ?></h1>
    <form method="post" action="<?= e(url('credenciales', ['a' => 'guardar', 'id' => $id])) ?>" class="formulario rejilla" autocomplete="off">
        <?= csrf_campo() ?>
        <div><?= selector('cliente_id', 'Cliente', opciones_clientes(false), $k['cliente_id'] ?? '') ?></div>
        <div><?= selector_empresa('empresa_id', 'RUT / Empresa', $k['empresa_id'] ?? '', 'Todas / del cliente') ?></div>
        <div>
            <?= campo('institucion', 'Institución *', $k['institucion'] ?? '', 'text', 'required maxlength="80" list="instituciones"') ?>
            <datalist id="instituciones"><?php foreach (INSTITUCIONES as $i): ?><option value="<?= e($i) ?>"><?php endforeach; ?></datalist>
        </div>
        <div><?= campo('url', 'Sitio de acceso', $k['url'] ?? '', 'text', 'placeholder="https://www.sii.cl"') ?></div>
        <div><?= campo('usuario', 'Usuario / RUT de acceso', $k['usuario'] ?? '', 'text', 'autocomplete="off"') ?></div>
        <div>
            <label for="f_clave"><?= $id ? 'Nueva clave (vacío = no cambiar)' : 'Clave' ?></label>
            <div class="grupo-input">
                <input type="password" id="f_clave" name="clave" autocomplete="new-password">
                <button type="button" class="secundario" data-alternar="f_clave">Mostrar</button>
            </div>
        </div>
        <div class="completo"><?= area('notas', 'Notas (preguntas de seguridad, clave de segundo factor, etc. — no se cifran)', $k['notas'] ?? '') ?></div>
        <div class="completo acciones">
            <button type="submit">Guardar</button>
            <a class="boton secundario" href="<?= e($id ? url('credenciales', ['a' => 'ver', 'id' => $id]) : url('credenciales')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Ficha (la clave se muestra solo tras pulsar "Mostrar", y queda registrado) ---------- */
if (($accion === 'ver' || $accion === 'revelar') && $id) {
    $k = q_uno(
        'SELECT k.*, c.nombre AS cliente, e.nombre AS empresa, e.identificacion AS rut, u.nombre AS editor
         FROM credenciales k LEFT JOIN clientes c ON c.id = k.cliente_id LEFT JOIN empresas e ON e.id = k.empresa_id
         LEFT JOIN usuarios u ON u.id = k.actualizado_por WHERE k.id = ?', [$id]);
    if (!$k) {
        redirigir(url('credenciales'));
    }
    $clave = null;
    $error = null;
    if ($accion === 'revelar' && es_post()) {
        if (!clave_confirmada()) {
            if (login_bloqueado()) {
                $error = 'Demasiados intentos fallidos. Espere ' . MINUTOS_BLOQUEO . ' minutos.';
            } elseif (!confirmar_clave((string)($_POST['clave_usuario'] ?? ''))) {
                $error = 'Contraseña incorrecta.';
            }
        }
        if (!$error) {
            try {
                $clave = descifrar($k['clave_cifrada']);
                registrar_credencial($id, 'ver');
            } catch (RuntimeException $ex) {
                $error = $ex->getMessage();
            }
        }
    }
    $historial = q_todos(
        'SELECT l.*, u.nombre AS usuario FROM credenciales_log l LEFT JOIN usuarios u ON u.id = l.usuario_id
         WHERE l.credencial_id = ? ORDER BY l.creado_en DESC LIMIT 20', [$id]);

    layout_inicio($k['institucion'], 'credenciales');
    ?>
    <div class="encabezado">
        <div class="titulo">
            <h1><?= e($k['institucion']) ?></h1>
            <p class="tenue"><?= enlace('clientes', $k['cliente_id'], $k['cliente']) ?><?php if ($k['empresa']): ?> · <?= enlace('empresas', $k['empresa_id'], $k['empresa'] . ' (' . $k['rut'] . ')') ?><?php endif; ?></p>
        </div>
        <div>
            <a class="boton" href="<?= e(url('credenciales', ['a' => 'form', 'id' => $id])) ?>">Editar</a>
            <?= boton_post(url('credenciales', ['a' => 'eliminar', 'id' => $id]), 'Eliminar', 'peligro', '¿Eliminar esta credencial? No se puede deshacer.') ?>
        </div>
    </div>
    <div class="columnas">
        <section class="panel">
            <h2>Acceso</h2>
            <?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>
            <dl class="ficha">
                <dt>Sitio</dt><dd><?php if ($k['url']): ?><a href="<?= e($k['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($k['url']) ?></a><?php endif; ?></dd>
                <dt>Usuario</dt><dd><span id="cred-usuario"><?= e($k['usuario']) ?></span>
                    <?php if ($k['usuario']): ?><button type="button" class="chico secundario" data-copiar="cred-usuario">Copiar</button><?php endif; ?></dd>
                <dt>Clave</dt><dd>
                    <?php if (!$k['clave_cifrada']): ?><span class="tenue">Sin clave guardada</span>
                    <?php elseif ($clave !== null): ?>
                        <div class="grupo-input"><input type="text" id="cred-clave" value="<?= e($clave) ?>" readonly class="clave-visible">
                        <button type="button" class="secundario" data-copiar="cred-clave">Copiar</button></div>
                        <small class="tenue">Visible solo en esta página; queda registrado en el historial.</small>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('credenciales', ['a' => 'revelar', 'id' => $id])) ?>" class="revelar-clave">
                            <?= csrf_campo() ?>
                            <?php if (!clave_confirmada()): ?>
                                <input type="password" name="clave_usuario" required autocomplete="current-password"
                                    placeholder="Su contraseña del CRM" aria-label="Su contraseña del CRM">
                            <?php else: ?><span class="tenue">••••••••</span><?php endif; ?>
                            <button type="submit" class="chico">Mostrar clave</button>
                        </form>
                        <?php if (!clave_confirmada()): ?><small class="tenue">Por seguridad se pide su contraseña; no se vuelve a pedir durante <?= MINUTOS_CONFIRMACION ?> minutos.</small><?php endif; ?>
                    <?php endif; ?>
                </dd>
                <dt>Actualizada</dt><dd><?= e(fecha($k['actualizado_en'], true)) ?> · <?= e($k['editor']) ?></dd>
            </dl>
            <?php if ($k['notas']): ?><p class="notas"><?= nl2br(e($k['notas'])) ?></p><?php endif; ?>
        </section>
        <section class="panel">
            <h2>Historial de acceso</h2>
            <?php if (!$historial): ?><p class="vacio">Sin registros.</p><?php else: ?>
            <table><tbody>
            <?php foreach ($historial as $h): ?>
                <tr><td class="nowrap"><?= e(fecha($h['creado_en'], true)) ?></td><td><?= e($h['usuario']) ?></td>
                    <td><?= e(['ver' => 'Vio la clave', 'crear' => 'Creó', 'editar' => 'Editó', 'eliminar' => 'Eliminó'][$h['accion']] ?? $h['accion']) ?>
                        <?php if ($h['detalle']): ?><small class="tenue"> · <?= e($h['detalle']) ?></small><?php endif; ?></td>
                    <td class="tenue"><?= e($h['ip']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </section>
    </div>
    <?php
    layout_fin();
    return;
}

/* ================================================================
 * Importación desde planilla (solo administradores)
 * 1) subir  2) asignar columnas  3) importar
 * Entre pasos, las filas se guardan cifradas con la llave del CRM en un
 * archivo temporal fuera de la carpeta pública, y se borran al terminar.
 * ================================================================ */

const ROLES_COLUMNA = [
    ''            => 'Ignorar',
    'cliente'     => 'Nombre del cliente',
    'rut'         => 'RUT',
    'institucion' => 'Institución',
    'usuario'     => 'Usuario',
    'clave'       => 'Clave',
    'url'         => 'Sitio web',
    'notas'       => 'Notas',
    'clave_de'    => 'Clave de la institución →',
    'usuario_de'  => 'Usuario de la institución →',
];

function importacion_ruta(): ?string
{
    $token = $_SESSION['importacion'] ?? '';
    return preg_match('/^[a-f0-9]{32}$/', $token) ? dirname(llave_ruta()) . '/importacion_' . $token . '.tmp' : null;
}

function importacion_borrar(): void
{
    $ruta = importacion_ruta();
    if ($ruta && is_file($ruta)) {
        @unlink($ruta);
    }
    unset($_SESSION['importacion'], $_SESSION['importacion_nombre']);
}

/** Contenido del temporal: ['filas' => [...]] o ['archivo' => base64, 'hojas' => [...]] (falta elegir hoja). */
function importacion_datos(): ?array
{
    $ruta = importacion_ruta();
    if (!$ruta || !is_file($ruta) || time() - filemtime($ruta) > 3600) {
        return null;
    }
    try {
        return json_decode(descifrar((string)file_get_contents($ruta)), true);
    } catch (RuntimeException $ex) {
        return null;
    }
}

function importacion_guardar(array $datos): void
{
    file_put_contents(importacion_ruta(), cifrar(json_encode($datos, JSON_UNESCAPED_UNICODE)), LOCK_EX);
    @chmod(importacion_ruta(), 0600);
}

function importacion_filas(): ?array
{
    return importacion_datos()['filas'] ?? null;
}

/** Sugiere el rol de una columna según su encabezado. @return array{0:string,1:string} [rol, institución] */
function sugerir_rol(string $encabezado): array
{
    $h = mb_strtolower($encabezado);
    $instituciones = ['sii', 'previred', 'dirección del trabajo', 'direccion del trabajo', 'dt', 'tesorería', 'tesoreria', 'tgr',
        'municipalidad', 'mutual', 'achs', 'ist', 'afc', 'banco', 'clave única', 'clave unica', 'claveunica', 'facturación', 'facturacion'];
    $inst = '';
    foreach ($instituciones as $i) {
        if (preg_match('/\b' . preg_quote($i, '/') . '\b/u', $h)) {
            $inst = $i;
            break;
        }
    }
    $nombreInst = trim(preg_replace('/\b(clave|contraseña|contrasena|password|pass|usuario|user|login|de|del)\b/iu', '', $encabezado), " \t-_:/.");
    if (preg_match('/\b(clave|contraseña|contrasena|password|pass)\b/u', $h) && !preg_match('/\bclave\s*[uú]nica\b/u', $h)) {
        return $inst ? ['clave_de', $nombreInst ?: strtoupper($inst)] : ['clave', ''];
    }
    if (preg_match('/\b(usuario|user|login)\b/u', $h)) {
        return $inst ? ['usuario_de', $nombreInst ?: strtoupper($inst)] : ['usuario', ''];
    }
    if (preg_match('/\brut\b/u', $h)) {
        return ['rut', ''];
    }
    if (preg_match('/instituc|^sistema$|^servicio$|^plataforma$/u', $h)) {
        return ['institucion', ''];
    }
    if (preg_match('/\b(cliente|nombre|raz[oó]n social|empresa)\b/u', $h)) {
        return ['cliente', ''];
    }
    if (preg_match('/\b(url|sitio|web|p[aá]gina)\b/u', $h)) {
        return ['url', ''];
    }
    if (preg_match('/\b(nota|notas|observaci)/u', $h)) {
        return ['notas', ''];
    }
    if ($inst) {
        return ['clave_de', $encabezado];
    }
    return ['', ''];
}

if (in_array($accion, ['importar', 'importar_subir', 'importar_hoja', 'importar_ejecutar', 'importar_cancelar'], true)) {
    requerir_admin();
}

if ($accion === 'importar_cancelar' && es_post()) {
    importacion_borrar();
    flash('ok', 'Importación cancelada; el archivo temporal fue borrado.');
    redirigir(url('credenciales'));
}

if ($accion === 'importar_subir' && es_post()) {
    importacion_borrar();
    $archivo = $_FILES['planilla'] ?? null;
    if (!$archivo || (int)$archivo['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'No se recibió el archivo.');
        redirigir(url('credenciales', ['a' => 'importar']));
    }
    require_once APP_DIR . '/importar.php';
    $nombre = basename((string)$archivo['name']);
    try {
        $hojas = preg_match('/\.xlsx?m?$/i', $nombre) && strncmp((string)file_get_contents($archivo['tmp_name'], false, null, 0, 4), "\xD0\xCF\x11\xE0", 4) !== 0
            ? hojas_xlsx($archivo['tmp_name']) : [];
        $datos = count($hojas) > 1
            ? ['archivo' => base64_encode((string)file_get_contents($archivo['tmp_name'])), 'hojas' => $hojas]
            : ['filas' => leer_planilla($archivo['tmp_name'], $nombre)];
    } catch (RuntimeException $ex) {
        @unlink($archivo['tmp_name']);
        flash('error', $ex->getMessage());
        redirigir(url('credenciales', ['a' => 'importar']));
    }
    @unlink($archivo['tmp_name']);
    $_SESSION['importacion'] = bin2hex(random_bytes(16));
    $_SESSION['importacion_nombre'] = $nombre;
    importacion_guardar($datos);
    redirigir(url('credenciales', ['a' => 'importar']));
}

if ($accion === 'importar_hoja' && es_post()) {
    require_once APP_DIR . '/importar.php';
    $datos = importacion_datos();
    $hoja = entrada('hoja');
    if (!$datos || empty($datos['archivo']) || !in_array($hoja, $datos['hojas'], true)) {
        importacion_borrar();
        flash('error', 'La importación expiró; vuelva a subir la planilla.');
        redirigir(url('credenciales', ['a' => 'importar']));
    }
    // El archivo se descifra a un temporal solo el tiempo necesario para leer la hoja.
    $tmp = tempnam(dirname(llave_ruta()), 'imp');
    file_put_contents($tmp, base64_decode($datos['archivo']));
    try {
        $filas = leer_planilla($tmp, 'planilla.xlsx', $hoja);
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        $filas = null;
    } finally {
        @unlink($tmp);
    }
    if ($filas) {
        $_SESSION['importacion_nombre'] .= ' · hoja ' . $hoja;
        importacion_guardar(['filas' => $filas]);
    }
    redirigir(url('credenciales', ['a' => 'importar']));
}

if ($accion === 'importar_ejecutar' && es_post()) {
    $filas = importacion_filas();
    if (!$filas) {
        importacion_borrar();
        flash('error', 'La importación expiró; vuelva a subir la planilla.');
        redirigir(url('credenciales', ['a' => 'importar']));
    }
    $encabezados = array_shift($filas);
    $roles = (array)($_POST['rol'] ?? []);
    $instCol = (array)($_POST['inst'] ?? []);
    $col = static function (string $rol) use ($roles): ?int {
        $i = array_search($rol, $roles, true);
        return $i === false ? null : (int)$i;
    };
    // Grupos por institución (formato ancho: una columna de clave por institución)
    $grupos = [];
    foreach ($roles as $i => $rol) {
        if ($rol === 'clave_de' || $rol === 'usuario_de') {
            $nombre = trim((string)($instCol[$i] ?? '')) ?: (string)$encabezados[$i];
            $grupos[mb_strtolower($nombre)]['nombre'] = $nombre;
            $grupos[mb_strtolower($nombre)][$rol === 'clave_de' ? 'clave' : 'usuario'] = (int)$i;
        }
    }
    $cCliente = $col('cliente');
    $cRut = $col('rut');
    $cInst = $col('institucion');
    if ($cCliente === null && $cRut === null) {
        flash('error', 'Indique qué columna tiene el nombre del cliente o el RUT.');
        redirigir(url('credenciales', ['a' => 'importar']));
    }
    if ($cInst === null && !$grupos) {
        flash('error', 'Indique la columna de institución, o marque columnas como "Clave de la institución".');
        redirigir(url('credenciales', ['a' => 'importar']));
    }
    $crearClientes = entrada('crear_clientes') === '1';
    $usuarioPorRut = entrada('usuario_por_rut') === '1';
    $actualizar = entrada('actualizar') === '1';
    $origen = 'Importado de ' . ($_SESSION['importacion_nombre'] ?? 'planilla');

    $res = ['nuevas' => 0, 'actualizadas' => 0, 'iguales' => 0, 'clientes' => 0, 'omitidas' => [], 'avisos' => []];
    $uid = (int)usuario_actual()['id'];
    db()->beginTransaction();
    try {
        foreach ($filas as $n => $f) {
            $numFila = $n + 2;
            $nombre = $cCliente !== null ? trim((string)$f[$cCliente]) : '';
            $rutTxt = $cRut !== null ? trim((string)$f[$cRut]) : '';
            $rut = $rutTxt !== '' && rut_valido($rutTxt) ? rut_formatear($rutTxt) : null;
            if ($rutTxt !== '' && !$rut) {
                $res['avisos'][] = "Fila $numFila: RUT inválido ($rutTxt), se usó solo el nombre";
            }
            if ($nombre === '' && !$rut) {
                if (implode('', $f) !== '') {
                    $res['omitidas'][] = "Fila $numFila: sin nombre ni RUT válido";
                    array_pop($res['avisos']);
                }
                continue;
            }

            // Buscar o crear cliente / empresa
            $empresa = $rut ? q_uno('SELECT id, cliente_id, nombre FROM empresas WHERE identificacion = ?', [$rut]) : null;
            $clienteId = $empresa['cliente_id'] ?? null;
            if (!$clienteId && $rut) {
                $clienteId = q_valor('SELECT id FROM clientes WHERE rut = ?', [$rut]);
            }
            if (!$clienteId && $nombre !== '') {
                $clienteId = q_valor('SELECT id FROM clientes WHERE LOWER(nombre) = ?', [mb_strtolower($nombre)]);
            }
            if (!$clienteId) {
                if (!$crearClientes) {
                    $res['omitidas'][] = "Fila $numFila: cliente no encontrado (" . ($nombre ?: $rut) . ')';
                    continue;
                }
                $esPersona = $rut && (int)substr(rut_limpiar($rut), 0, -1) < 50000000;
                $clienteId = insertar('clientes', [
                    'nombre' => $nombre ?: ($empresa['nombre'] ?? $rut), 'tipo' => $esPersona ? 'persona' : 'empresa', 'rut' => $rut,
                    'ejecutivo_id' => $uid, 'activo' => 1, 'creado_en' => ahora(), 'actualizado_en' => ahora(),
                ]);
                $res['clientes']++;
            }
            if ($empresa && !$empresa['cliente_id']) {
                q('UPDATE empresas SET cliente_id = ? WHERE id = ?', [$clienteId, $empresa['id']]);
            }
            if (!$empresa && $rut) {
                $empresa = ['id' => insertar('empresas', [
                    'nombre' => $nombre ?: $rut, 'identificacion' => $rut, 'cliente_id' => $clienteId,
                    'responsable_id' => $uid, 'creado_en' => ahora(), 'actualizado_en' => ahora(),
                ])];
            }
            $empresaId = $empresa['id'] ?? null;

            // Credenciales de la fila
            $items = [];
            if ($cInst !== null && trim((string)$f[$cInst]) !== '') {
                $items[] = [
                    'institucion' => trim((string)$f[$cInst]),
                    'usuario'     => $col('usuario') !== null ? trim((string)$f[$col('usuario')]) : '',
                    'clave'       => $col('clave') !== null ? (string)$f[$col('clave')] : '',
                ];
            }
            foreach ($grupos as $g) {
                $items[] = [
                    'institucion' => $g['nombre'],
                    'usuario'     => isset($g['usuario']) ? trim((string)$f[$g['usuario']]) : '',
                    'clave'       => isset($g['clave']) ? (string)$f[$g['clave']] : '',
                ];
            }
            $url = $col('url') !== null ? trim((string)$f[$col('url')]) : '';
            $notas = $col('notas') !== null ? trim((string)$f[$col('notas')]) : '';

            foreach ($items as $it) {
                if (trim($it['clave']) === '' && $it['usuario'] === '') {
                    continue;
                }
                $usuario = $it['usuario'] !== '' ? $it['usuario'] : ($usuarioPorRut && $rut ? $rut : '');
                // Comparar columnas directamente (sin COALESCE) para que SQLite respete el tipo numérico.
                $existente = q_uno(
                    'SELECT id, clave_cifrada FROM credenciales WHERE cliente_id = ? AND '
                    . ($empresaId ? 'empresa_id = ?' : 'empresa_id IS NULL') . ' AND LOWER(institucion) = ? AND '
                    . ($usuario !== '' ? 'usuario = ?' : 'usuario IS NULL'),
                    array_merge([$clienteId], $empresaId ? [$empresaId] : [], [mb_strtolower($it['institucion'])], $usuario !== '' ? [$usuario] : []));
                $datos = ['actualizado_por' => $uid, 'actualizado_en' => ahora()];
                if (trim($it['clave']) !== '') {
                    $datos['clave_cifrada'] = cifrar($it['clave']);
                }
                if ($url !== '') {
                    $datos['url'] = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
                }
                if ($existente) {
                    if (!$actualizar || trim($it['clave']) === '' || descifrar($existente['clave_cifrada']) === $it['clave']) {
                        $res['iguales']++;
                        continue;
                    }
                    actualizar('credenciales', (int)$existente['id'], $datos);
                    registrar_credencial((int)$existente['id'], 'editar', $origen . ' (fila ' . $numFila . ')');
                    $res['actualizadas']++;
                } else {
                    $nuevoId = insertar('credenciales', $datos + [
                        'cliente_id' => $clienteId, 'empresa_id' => $empresaId, 'institucion' => mb_substr($it['institucion'], 0, 80),
                        'usuario' => nulo_si_vacio($usuario), 'notas' => nulo_si_vacio($notas), 'creado_en' => ahora(),
                    ]);
                    registrar_credencial($nuevoId, 'crear', $origen . ' (fila ' . $numFila . ')');
                    $res['nuevas']++;
                }
            }
        }
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        error_log('CRM - error al importar credenciales: ' . $ex->getMessage());
        flash('error', 'La importación se canceló por un error y no se guardó nada: ' . $ex->getMessage());
        redirigir(url('credenciales', ['a' => 'importar']));
    }
    importacion_borrar();
    flash('ok', "Importación terminada: {$res['nuevas']} credenciales nuevas, {$res['actualizadas']} actualizadas, "
        . "{$res['iguales']} sin cambios, {$res['clientes']} clientes creados. El archivo temporal fue borrado.");
    foreach (['omitidas' => 'fila(s) omitida(s)', 'avisos' => 'aviso(s)'] as $clave => $texto) {
        if ($res[$clave]) {
            flash('aviso', count($res[$clave]) . " $texto: " . implode(' · ', array_slice($res[$clave], 0, 15))
                . (count($res[$clave]) > 15 ? ' …' : ''));
        }
    }
    redirigir(url('credenciales'));
}

if ($accion === 'importar') {
    require_once APP_DIR . '/importar.php';
    $datos = importacion_datos();
    $filas = $datos['filas'] ?? null;
    layout_inicio('Importar credenciales', 'credenciales');
    ?>
    <h1>Importar credenciales desde planilla</h1>
    <?php if (!$filas && !empty($datos['hojas'])):
        $sugerida = '';
        foreach ($datos['hojas'] as $h) {
            if (preg_match('/cred|clave|contrase|pass/i', $h)) {
                $sugerida = $h;
                break;
            }
        } ?>
        <section class="panel">
            <p>El archivo <strong><?= e($_SESSION['importacion_nombre'] ?? '') ?></strong> tiene <?= count($datos['hojas']) ?> hojas. ¿Cuál contiene las credenciales?</p>
            <form method="post" action="<?= e(url('credenciales', ['a' => 'importar_hoja'])) ?>" class="formulario angosto">
                <?= csrf_campo() ?>
                <?= selector('hoja', 'Hoja', array_combine($datos['hojas'], $datos['hojas']), $sugerida, false) ?>
                <button type="submit">Continuar</button>
            </form>
        </section>
        <?= boton_post(url('credenciales', ['a' => 'importar_cancelar']), 'Cancelar y borrar el archivo temporal', 'secundario') ?>
    <?php elseif (!$filas): ?>
        <section class="panel">
            <ol>
                <li>Si su planilla tiene contraseña, ábrala en Excel y guarde <strong>una copia sin contraseña</strong>
                    (<em>Archivo → Información → Proteger libro → Cifrar con contraseña</em>, borre la contraseña y guarde como otro archivo).</li>
                <li>Súbala aquí (.xlsx o .csv). Si el libro tiene varias hojas, podrá elegir cuál importar; la primera fila debe ser el encabezado.</li>
                <li>En el paso siguiente indique qué es cada columna. Nada se guarda hasta que pulse <em>Importar</em>.</li>
                <li>Al terminar, <strong>borre la copia sin contraseña</strong> de su computador (y de la papelera).</li>
            </ol>
            <form method="post" enctype="multipart/form-data" action="<?= e(url('credenciales', ['a' => 'importar_subir'])) ?>" class="formulario angosto">
                <?= csrf_campo() ?>
                <label for="f_planilla">Planilla</label>
                <input type="file" id="f_planilla" name="planilla" accept=".xlsx,.xlsm,.csv" required>
                <button type="submit">Continuar</button>
            </form>
        </section>
    <?php else:
        $encabezados = $filas[0];
        $muestra = array_slice($filas, 1, 3);
        ?>
        <p>Archivo: <strong><?= e($_SESSION['importacion_nombre'] ?? '') ?></strong> · <?= count($filas) - 1 ?> filas de datos.</p>
        <form method="post" action="<?= e(url('credenciales', ['a' => 'importar_ejecutar'])) ?>" class="formulario">
            <?= csrf_campo() ?>
            <section class="panel">
                <div class="encabezado">
                    <h2>¿Qué contiene cada columna?</h2>
                    <label class="check"><input type="checkbox" id="ver-muestra"> Mostrar valores de ejemplo</label>
                </div>
                <p class="tenue">Dos formatos posibles: <strong>una fila por credencial</strong> (columnas Institución, Usuario, Clave) o
                    <strong>una fila por cliente</strong> con una columna de clave por institución (marque cada una como
                    "Clave de la institución →" y escriba el nombre de la institución).</p>
                <table class="tabla-importar">
                    <thead><tr><th>Col.</th><th>Encabezado</th><th>Contiene</th><th>Institución</th><th>Ejemplos</th></tr></thead>
                    <tbody>
                    <?php foreach ($encabezados as $i => $h):
                        [$rolSugerido, $instSugerida] = sugerir_rol((string)$h); ?>
                        <tr>
                            <td class="tenue"><?= e(columna_letra($i)) ?></td>
                            <td><strong><?= e($h) ?></strong></td>
                            <td><select name="rol[<?= $i ?>]" aria-label="Rol de la columna <?= e($h) ?>" data-rol-columna>
                                <?php foreach (ROLES_COLUMNA as $v => $t): ?><option value="<?= e($v) ?>" <?= $v === $rolSugerido ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                            </select></td>
                            <td><input type="text" name="inst[<?= $i ?>]" value="<?= e($instSugerida ?: $h) ?>" aria-label="Institución" list="instituciones" data-inst-columna></td>
                            <td class="muestra"><?php foreach ($muestra as $m): ?><span data-valor="<?= e($m[$i] ?? '') ?>"><?= ($m[$i] ?? '') === '' ? '—' : '•••' ?></span><?php endforeach; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <datalist id="instituciones"><?php foreach (INSTITUCIONES as $inst): ?><option value="<?= e($inst) ?>"><?php endforeach; ?></datalist>
            </section>
            <section class="panel">
                <h2>Opciones</h2>
                <label class="check"><input type="checkbox" name="crear_clientes" value="1" checked> Crear los clientes (y su RUT) que no existan en el CRM</label>
                <label class="check"><input type="checkbox" name="usuario_por_rut" value="1" checked> Si falta el usuario, usar el RUT del cliente (habitual en SII)</label>
                <label class="check"><input type="checkbox" name="actualizar" value="1" checked> Si la credencial ya existe, actualizar su clave</label>
                <div class="acciones">
                    <button type="submit">Importar</button>
                </div>
            </section>
        </form>
        <?= boton_post(url('credenciales', ['a' => 'importar_cancelar']), 'Cancelar y borrar el archivo temporal', 'secundario') ?>
        <script>
            (function () {
                var ver = document.getElementById('ver-muestra');
                ver.addEventListener('change', function () {
                    document.querySelectorAll('.muestra span').forEach(function (s) {
                        var v = s.getAttribute('data-valor');
                        s.textContent = v === '' ? '—' : (ver.checked ? v : '•••');
                    });
                });
                function actualizar() {
                    document.querySelectorAll('[data-rol-columna]').forEach(function (sel) {
                        var inst = sel.closest('tr').querySelector('[data-inst-columna]');
                        inst.hidden = !(sel.value === 'clave_de' || sel.value === 'usuario_de');
                    });
                }
                document.addEventListener('change', actualizar);
                actualizar();
            })();
        </script>
    <?php endif; ?>
    <?php
    layout_fin();
    return;
}

/* ---------- Registro global de accesos (administradores) ---------- */
if ($accion === 'registro') {
    requerir_admin();
    $filas = q_todos(
        'SELECT l.*, u.nombre AS usuario, k.institucion, c.nombre AS cliente
         FROM credenciales_log l LEFT JOIN usuarios u ON u.id = l.usuario_id
         LEFT JOIN credenciales k ON k.id = l.credencial_id LEFT JOIN clientes c ON c.id = k.cliente_id
         ORDER BY l.creado_en DESC LIMIT 300');
    layout_inicio('Registro de accesos', 'credenciales');
    ?>
    <h1>Registro de accesos a credenciales</h1>
    <table>
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Credencial</th><th>Cliente</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $h): ?>
            <tr><td class="nowrap"><?= e(fecha($h['creado_en'], true)) ?></td><td><?= e($h['usuario']) ?></td><td><?= e($h['accion']) ?></td>
                <td><?= $h['credencial_id'] ? enlace('credenciales', $h['credencial_id'], $h['institucion']) : e($h['detalle']) ?></td>
                <td><?= e($h['cliente']) ?></td><td class="tenue"><?= e($h['ip']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?><tr><td colspan="6" class="vacio">Sin registros.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['k.institucion', 'k.usuario', 'c.nombre', 'e.nombre', 'e.identificacion'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
$filtroCliente = entrada_int('cliente_id');
if ($filtroCliente) {
    $condiciones[] = 'k.cliente_id = :cliente';
    $params['cliente'] = $filtroCliente;
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$desdeTabla = 'FROM credenciales k LEFT JOIN clientes c ON c.id = k.cliente_id LEFT JOIN empresas e ON e.id = k.empresa_id';
[$limite, $desde] = paginacion_limites();
$total = (int)q_valor("SELECT COUNT(*) $desdeTabla $where", $params);
$credenciales = q_todos("SELECT k.*, c.nombre AS cliente, e.nombre AS empresa, e.identificacion AS rut
    $desdeTabla $where ORDER BY c.nombre, k.institucion LIMIT $limite OFFSET $desde", $params);

layout_inicio('Credenciales', 'credenciales');
?>
<div class="encabezado">
    <h1>Credenciales</h1>
    <?php if (es_admin()): ?><div>
        <a class="boton secundario" href="<?= e(url('credenciales', ['a' => 'importar'])) ?>">Importar desde planilla</a>
        <a class="boton secundario" href="<?= e(url('credenciales', ['a' => 'registro'])) ?>">Registro de accesos</a>
    </div><?php endif; ?>
</div>
<?php barra_lista('credenciales', '+ Nueva credencial', false, [
    selector('cliente_id', '', opciones_clientes(false), $filtroCliente ?? '', 'Todos los clientes', 'aria-label="Cliente"'),
]); ?>
<table>
    <thead><tr><th>Institución</th><th>Cliente</th><th>RUT / Empresa</th><th>Usuario</th><th>Actualizada</th></tr></thead>
    <tbody>
    <?php foreach ($credenciales as $k): ?>
        <tr>
            <td><a href="<?= e(url('credenciales', ['a' => 'ver', 'id' => $k['id']])) ?>"><?= e($k['institucion']) ?></a></td>
            <td><?= enlace('clientes', $k['cliente_id'], $k['cliente']) ?></td>
            <td><?= e($k['empresa']) ?><?php if ($k['rut']): ?><br><small class="tenue"><?= e($k['rut']) ?></small><?php endif; ?></td>
            <td><?= e($k['usuario']) ?></td>
            <td class="tenue nowrap"><?= e(fecha($k['actualizado_en'])) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$credenciales): ?><tr><td colspan="5" class="vacio">No hay credenciales con estos filtros.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
