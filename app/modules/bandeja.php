<?php
declare(strict_types=1);

/*
 * Bandeja de correos: propuestas detectadas en los correos del equipo (ver app/bandeja.php).
 * Aquí una persona las revisa: al aprobar se crea la tarea, gestión u oportunidad real;
 * al rechazar se descartan. Nada se crea solo.
 */

$id = entrada_int('id');
$uid = (int)usuario_actual()['id'];

function bandeja_int($valor): ?int
{
    return $valor ? (int)$valor : null;
}

/**
 * Crea el registro real a partir del formulario de revisión.
 * @return array{0:string,1:int,2:string} [tipo creado, id creado, mensaje]
 * @throws InvalidArgumentException con un mensaje para el usuario si falta algún dato
 */
function bandeja_crear(string $como, int $uid): array
{
    $titulo = trim(entrada('titulo'));
    if ($titulo === '') {
        throw new InvalidArgumentException('El título es obligatorio.');
    }
    $detalle = nulo_si_vacio(entrada('detalle'));
    $responsable = entrada_int('responsable_id') ?? $uid;
    $clienteId = entrada_int('cliente_id');
    $empresaId = entrada_int('empresa_id');

    /* ---- Tarea ---- */
    if ($como === 'tarea') {
        if (!$clienteId && $empresaId) {
            $clienteId = bandeja_int(q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$empresaId]));
        }
        $prioridad = entrada('prioridad');
        $idNuevo = insertar('tareas', [
            'titulo'         => mb_substr($titulo, 0, 200),
            'descripcion'    => $detalle,
            'cliente_id'     => $clienteId,
            'empresa_id'     => $empresaId,
            'oportunidad_id' => null,
            'responsable_id' => $responsable,
            'vencimiento'    => entrada_fecha('vencimiento'),
            'estado'         => 'pendiente',
            'prioridad'      => isset(PRIORIDADES[$prioridad]) ? $prioridad : 'normal',
            'recurrencia'    => 'ninguna',
            'creado_por'     => $uid,
            'creado_en'      => ahora(),
            'actualizado_en' => ahora(),
        ]);
        return ['tarea', $idNuevo, 'Tarea creada: ' . $titulo];
    }

    /* ---- Prospecto (oportunidad en etapa «Prospecto») ---- */
    if ($como === 'prospecto') {
        $nuevoRutNombre = trim(entrada('nuevo_rut_nombre'));
        [$nuevoRut, $errorRut] = rut_entrada('nuevo_rut');
        if ($errorRut) {
            throw new InvalidArgumentException($errorRut);
        }
        if (!$empresaId && ($nuevoRutNombre !== '' || $nuevoRut)) {
            $existente = $nuevoRut ? q_valor('SELECT id FROM empresas WHERE identificacion = ?', [$nuevoRut]) : null;
            $empresaId = $existente ? (int)$existente : insertar('empresas', [
                'nombre' => mb_substr($nuevoRutNombre ?: ($titulo ?: (string)$nuevoRut), 0, 150), 'identificacion' => $nuevoRut,
                'responsable_id' => $responsable, 'creado_en' => ahora(), 'actualizado_en' => ahora(),
            ]);
        }

        $contactoId = null;
        $contactoNombre = trim(entrada('nuevo_contacto_nombre'));
        $contactoEmail = mb_strtolower(trim(entrada('nuevo_contacto_email')));
        if ($contactoEmail !== '') {
            $contactoId = bandeja_int(q_valor('SELECT id FROM contactos WHERE LOWER(email) = ?', [$contactoEmail]));
        }
        if (!$contactoId && $contactoNombre !== '') {
            $contactoId = insertar('contactos', [
                'nombre' => mb_substr($contactoNombre, 0, 100), 'cargo' => nulo_si_vacio(entrada('nuevo_contacto_rol')),
                'email' => nulo_si_vacio($contactoEmail), 'telefono' => nulo_si_vacio(entrada('nuevo_contacto_telefono')),
                'responsable_id' => $responsable, 'creado_en' => ahora(), 'actualizado_en' => ahora(),
            ]);
        }
        if ($contactoId && $empresaId) {
            vincular_contacto($contactoId, $empresaId, nulo_si_vacio(entrada('nuevo_contacto_rol')) ?? 'Contacto');
        }

        $nombreProspecto = $titulo;
        if ($nombreProspecto === '' && $empresaId) {
            $nombreProspecto = (string)q_valor('SELECT nombre FROM empresas WHERE id = ?', [$empresaId]);
        }
        $idNuevo = insertar('oportunidades', [
            'titulo'         => mb_substr($nombreProspecto, 0, 150),
            'empresa_id'     => $empresaId,
            'contacto_id'    => $contactoId,
            'servicio'       => nulo_si_vacio(mb_substr(entrada('servicio'), 0, 150)),
            'tarifa_tipo'    => 'mensual',
            'tarifa_moneda'  => 'UF',
            'monto'          => 0,
            'tarifa_mes'     => null,
            'etapa'          => 'prospecto',
            'probabilidad'   => 10,
            'fecha_cierre'   => null,
            'responsable_id' => $responsable,
            'notas'          => $detalle,
            'actualizado_en' => ahora(),
            'creado_en'      => ahora(),
        ]);
        return ['oportunidad', $idNuevo, 'Prospecto creado: ' . $titulo];
    }

    /* ---- Reunión o gestión (ambas son una gestión de la bitácora) ---- */
    if (!$clienteId && $empresaId) {
        $clienteId = bandeja_int(q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$empresaId]));
    }
    $tipoActividad = entrada('tipo_actividad');
    if (!isset(TIPOS_ACTIVIDAD[$tipoActividad])) {
        $tipoActividad = $como === 'reunion' ? 'reunion' : 'email';
    }
    $idNuevo = insertar('actividades', [
        'tipo'           => $tipoActividad,
        'asunto'         => mb_substr($titulo, 0, 200),
        'descripcion'    => $detalle,
        'fecha'          => bandeja_fecha(entrada('fecha')) ?? ahora(),
        'completada'     => entrada('completada') === '1' ? 1 : 0,
        'cliente_id'     => $clienteId,
        'tarea_id'       => null,
        'empresa_id'     => $empresaId,
        'contacto_id'    => entrada_int('contacto_id'),
        'oportunidad_id' => null,
        'usuario_id'     => $responsable,
        'creado_en'      => ahora(),
    ]);
    return ['actividad', $idNuevo, ($como === 'reunion' ? 'Reunión' : 'Gestión') . ' registrada: ' . $titulo];
}

/* ---------- Aprobar: crea el registro real ---------- */
if ($accion === 'aprobar' && es_post() && $id) {
    $p = q_uno('SELECT * FROM bandeja_correos WHERE id = ?', [$id]);
    if (!$p || $p['estado'] !== 'pendiente') {
        flash('aviso', 'Esa propuesta ya fue revisada por alguien más.');
        redirigir(url('bandeja'));
    }
    $como = entrada('como');
    if (!isset(TIPOS_BANDEJA[$como])) {
        $como = $p['tipo'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Se reserva primero: si dos personas aprueban a la vez, solo una crea el registro.
        $reservada = q("UPDATE bandeja_correos SET estado = 'aprobada', revisado_por = ?, revisado_en = ? WHERE id = ? AND estado = 'pendiente'",
            [$uid, ahora(), $id])->rowCount();
        if ($reservada !== 1) {
            $pdo->rollBack();
            flash('aviso', 'Esa propuesta ya fue revisada por alguien más.');
            redirigir(url('bandeja'));
        }
        [$creadoTipo, $creadoId, $mensaje] = bandeja_crear($como, $uid);
        q('UPDATE bandeja_correos SET creado_tipo = ?, creado_id = ? WHERE id = ?', [$creadoTipo, $creadoId, $id]);
        $pdo->commit();
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $ex->getMessage());
        redirigir(url('bandeja', ['a' => 'revisar', 'id' => $id, 'como' => $como]));
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('CRM - bandeja, al aprobar: ' . $ex->getMessage());
        flash('error', 'No se pudo crear el registro. Revise los datos e intente de nuevo.');
        redirigir(url('bandeja', ['a' => 'revisar', 'id' => $id, 'como' => $como]));
    }
    flash('ok', $mensaje . '. Puede abrirlo desde la columna «Resultado».');
    redirigir(url('bandeja'));
}

/* ---------- Aplicar a un registro existente (complementar o actualizar) en vez de crear uno nuevo ---------- */
if ($accion === 'aplicar' && es_post() && $id) {
    $p = q_uno('SELECT * FROM bandeja_correos WHERE id = ?', [$id]);
    if (!$p || $p['estado'] !== 'pendiente') {
        flash('aviso', 'Esa propuesta ya fue revisada por alguien más.');
        redirigir(url('bandeja'));
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $reservada = q("UPDATE bandeja_correos SET estado = 'aprobada', revisado_por = ?, revisado_en = ? WHERE id = ? AND estado = 'pendiente'",
            [$uid, ahora(), $id])->rowCount();
        if ($reservada !== 1) {
            $pdo->rollBack();
            flash('aviso', 'Esa propuesta ya fue revisada por alguien más.');
            redirigir(url('bandeja'));
        }
        $mensaje = bandeja_aplicar_a_existente($p, (string)$p['destino_tipo'], (int)$p['destino_id'], [
            'nota' => entrada('nota'), 'estado' => entrada('estado'), 'vencimiento' => entrada('vencimiento'),
            'prioridad' => entrada('prioridad'), 'etapa' => entrada('etapa'),
        ]);
        q('UPDATE bandeja_correos SET creado_tipo = ?, creado_id = ? WHERE id = ?', [$p['destino_tipo'], (int)$p['destino_id'], $id]);
        $pdo->commit();
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $ex->getMessage());
        redirigir(url('bandeja', ['a' => 'revisar', 'id' => $id]));
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('CRM - bandeja, al aplicar: ' . $ex->getMessage());
        flash('error', 'No se pudo actualizar el registro existente.');
        redirigir(url('bandeja', ['a' => 'revisar', 'id' => $id]));
    }
    flash('ok', $mensaje . '.');
    redirigir(url('bandeja'));
}

/* ---------- Rechazar / volver a dejar por revisar ---------- */
if ($accion === 'rechazar' && es_post() && $id) {
    $n = q("UPDATE bandeja_correos SET estado = 'rechazada', revisado_por = ?, revisado_en = ? WHERE id = ? AND estado = 'pendiente'",
        [$uid, ahora(), $id])->rowCount();
    flash($n ? 'ok' : 'aviso', $n ? 'Propuesta rechazada.' : 'Esa propuesta ya fue revisada.');
    redirigir(url('bandeja'));
}
if ($accion === 'reabrir' && es_post() && $id) {
    q("UPDATE bandeja_correos SET estado = 'pendiente', revisado_por = NULL, revisado_en = NULL WHERE id = ? AND estado IN ('rechazada', 'omitida')", [$id]);
    flash('ok', 'La propuesta volvió a «Por revisar».');
    redirigir(url('bandeja', ['estado' => 'pendiente']));
}

/* ---------- Revisión: formulario precargado ---------- */
if ($accion === 'revisar' && $id) {
    $p = q_uno('SELECT * FROM bandeja_correos WHERE id = ?', [$id]);
    if (!$p) {
        redirigir(url('bandeja'));
    }
    if ($p['estado'] !== 'pendiente') {
        flash('aviso', 'Esa propuesta ya fue revisada.');
        redirigir(url('bandeja', ['estado' => 'todas']));
    }
    $como = isset(TIPOS_BANDEJA[entrada('como')]) ? entrada('como') : $p['tipo'];
    $fechaEvento = $p['fecha_evento'];
    $clienteSugerido = bandeja_cliente_sugerido($p['cliente_texto']);
    $responsableSugerido = bandeja_usuario_sugerido($p['responsable_email']) ?? $uid;
    $detalleInicial = (string)($p['detalle'] ?? '');
    if (in_array($como, ['reunion', 'gestion'], true) && ($p['contacto_nombre'] || $p['contacto_email'])) {
        $detalleInicial = trim($detalleInicial . "\nContacto: " . trim($p['contacto_nombre'] . ' ' . ($p['contacto_email'] ? '<' . $p['contacto_email'] . '>' : '')));
    }

    layout_inicio('Revisar propuesta', 'bandeja');
    ?>
    <div class="encabezado">
        <h1>Revisar propuesta</h1>
        <a class="boton secundario" href="<?= e(url('bandeja')) ?>">← Volver a la bandeja</a>
    </div>

    <section class="panel">
        <h2>Correo de origen</h2>
        <p>
            <strong>De:</strong> <?= e($p['remitente'] ?? '—') ?><br>
            <strong>Asunto:</strong> <?= e($p['asunto'] ?? '—') ?><br>
            <strong>Recibido:</strong> <?= e(fecha($p['recibido_en'], true)) ?><?= $p['buzones'] ? ' · <span class="tenue">' . e($p['buzones']) . '</span>' : '' ?><br>
            <strong>Confianza de la detección:</strong> <?= e(CONFIANZAS_BANDEJA[$p['confianza']] ?? $p['confianza']) ?>
            <?php if ($p['cliente_texto']): ?><br><strong>Cliente o empresa mencionados:</strong> <?= e($p['cliente_texto']) ?><?php endif; ?>
        </p>
    </section>

    <?php
    $existente = bandeja_destino($p['destino_tipo'], $p['destino_id']);
    if ($existente):
        [$dTabla, $dNotas, $dRuta, $dAccion, $dNombre] = DESTINOS_BANDEJA[$p['destino_tipo']];
        $cambiosProp = $p['cambios'] ? (json_decode((string)$p['cambios'], true) ?: []) : [];
        $permitidos = bandeja_cambios_permitidos($p['destino_tipo']);
        $esDuplicado = $p['accion'] === 'nueva';
        ?>
    <section class="panel" style="border-left:4px solid #b45309">
        <h2><?= $esDuplicado ? 'Posible duplicado: ya existe ' : 'Ya existe ' ?>una <?= e($dNombre) ?> parecida</h2>
        <p><a href="<?= e(url($dRuta, ['a' => $dAccion, 'id' => $existente['id']])) ?>" target="_blank" rel="noopener"><strong><?= e(bandeja_destino_titulo($existente)) ?></strong></a>
            <?php if (isset($existente['estado'])): ?> · <?= e(ESTADOS_TAREA[$existente['estado']] ?? $existente['estado']) ?><?php endif; ?>
            <?php if (!empty($existente['vencimiento'])): ?> · vence <?= e(fecha($existente['vencimiento'])) ?><?php endif; ?>
            <?php if (isset($existente['etapa'])): ?> · etapa <?= e(ETAPAS[$existente['etapa']] ?? $existente['etapa']) ?><?php endif; ?>
            <?php if ($p['motivo']): ?><br><small class="tenue"><?= e($p['motivo']) ?></small><?php endif; ?></p>
        <form method="post" action="<?= e(url('bandeja', ['a' => 'aplicar', 'id' => $id])) ?>" class="formulario rejilla">
            <?= csrf_campo() ?>
            <div class="completo"><?= area('nota', 'Nota que se agrega a la ' . $dNombre . ' existente (queda fechada con el correo de origen)', $p['detalle'] ?? '') ?></div>
            <?php foreach ($permitidos as $campoCambio => $opcionesCambio): ?>
                <div><?php
                    $valorInicial = $cambiosProp[$campoCambio] ?? '';
                    if ($campoCambio === 'vencimiento') {
                        echo campo('vencimiento', 'Cambiar vencimiento', $valorInicial ? substr((string)bandeja_fecha($valorInicial), 0, 10) : '', 'date');
                    } else {
                        echo selector($campoCambio, 'Cambiar ' . ($campoCambio === 'estado' ? 'estado' : ($campoCambio === 'prioridad' ? 'prioridad' : 'etapa')), $opcionesCambio, $valorInicial, '— Sin cambio —');
                    }
                    ?></div>
            <?php endforeach; ?>
            <div class="completo acciones"><button type="submit">Aplicar a la <?= e($dNombre) ?> existente</button>
                <span class="tenue">Si no es lo mismo, cree una nueva más abajo.</span></div>
        </form>
    </section>
    <?php endif; ?>

    <p>Crear como:
        <?php foreach (TIPOS_BANDEJA as $clave => $texto): ?>
            <?= $clave === $como
                ? '<strong>' . e($texto) . '</strong>'
                : '<a href="' . e(url('bandeja', ['a' => 'revisar', 'id' => $id, 'como' => $clave])) . '">' . e($texto) . '</a>' ?>
            <?= $clave !== array_key_last(TIPOS_BANDEJA) ? ' · ' : '' ?>
        <?php endforeach; ?>
        <?php if ($como !== $p['tipo']): ?><small class="tenue"> (se detectó como «<?= e(TIPOS_BANDEJA[$p['tipo']] ?? $p['tipo']) ?>»)</small><?php endif; ?>
    </p>

    <form method="post" action="<?= e(url('bandeja', ['a' => 'aprobar', 'id' => $id])) ?>" class="formulario rejilla">
        <?= csrf_campo() ?>
        <input type="hidden" name="como" value="<?= e($como) ?>">
        <div class="completo"><?= campo('titulo', ($como === 'prospecto' ? 'Nombre del prospecto' : ($como === 'tarea' ? 'Título de la tarea' : 'Asunto')) . ' *', $p['titulo'], 'text', 'required maxlength="200"') ?></div>

        <?php if ($como === 'tarea'): ?>
            <div><?= campo('vencimiento', 'Vencimiento', $fechaEvento ? substr($fechaEvento, 0, 10) : '', 'date') ?></div>
            <div><?= selector('prioridad', 'Prioridad', PRIORIDADES, 'normal', false) ?></div>
        <?php elseif ($como === 'prospecto'): ?>
            <div class="completo"><?= campo('servicio', 'Servicio de interés', '', 'text', 'maxlength="150" list="lista-servicios"') ?>
                <datalist id="lista-servicios"><?php foreach (SERVICIOS as $s) echo '<option value="' . e($s) . '">'; ?></datalist></div>
        <?php else: ?>
            <div><?= selector('tipo_actividad', 'Tipo', TIPOS_ACTIVIDAD, $como === 'reunion' ? 'reunion' : 'email', false) ?></div>
            <div><?= campo('fecha', 'Fecha y hora', date('Y-m-d\TH:i', strtotime($fechaEvento ?: 'now')), 'datetime-local', 'required') ?></div>
        <?php endif; ?>

        <div><?= selector('cliente_id', 'Cliente', opciones_clientes(), $clienteSugerido ?? '') ?></div>
        <div><?= selector_empresa('empresa_id', 'RUT / Empresa', '') ?></div>
        <div><?= selector('responsable_id', $como === 'reunion' || $como === 'gestion' ? 'Asignada a' : 'Responsable', opciones_usuarios(), $responsableSugerido, false) ?></div>

        <?php if ($como === 'prospecto'): ?>
            <div><?= campo('nuevo_rut_nombre', 'Empresa o persona (si es nueva)', $p['cliente_texto'] ?? '', 'text', 'maxlength="150"') ?></div>
            <div><?= campo('nuevo_rut', 'RUT (opcional)', '', 'text', 'maxlength="20"') ?></div>
            <div><?= campo('nuevo_contacto_nombre', 'Contacto', $p['contacto_nombre'] ?? '', 'text', 'maxlength="100"') ?></div>
            <div><?= campo('nuevo_contacto_email', 'Correo del contacto', $p['contacto_email'] ?? '', 'email', 'maxlength="150"') ?></div>
            <div><?= campo('nuevo_contacto_rol', 'Cargo o rol del contacto', '', 'text', 'maxlength="100"') ?></div>
            <div><?= campo('nuevo_contacto_telefono', 'Teléfono del contacto', '', 'text', 'maxlength="50"') ?></div>
        <?php elseif ($como !== 'tarea'): ?>
            <div><?= selector('contacto_id', 'Contacto', opciones_contactos(), '') ?></div>
            <div class="completo"><label class="check"><input type="checkbox" name="completada" value="1"> Ya ocurrió (marcar como completada)</label></div>
        <?php endif; ?>

        <div class="completo"><?= area('detalle', $como === 'prospecto' ? 'Notas' : 'Descripción', $detalleInicial) ?></div>
        <div class="completo acciones">
            <button type="submit">Aprobar y crear</button>
            <a class="boton secundario" href="<?= e(url('bandeja')) ?>">Cancelar</a>
        </div>
    </form>
    <form method="post" action="<?= e(url('bandeja', ['a' => 'rechazar', 'id' => $id])) ?>" onsubmit="return confirm('¿Rechazar esta propuesta?')">
        <?= csrf_campo() ?>
        <button type="submit" class="peligro">Rechazar propuesta</button>
    </form>
    <?php
    layout_fin();
    return;
}

/* ---------- Lista ---------- */
$estado = entrada('estado', 'pendiente');
if ($estado !== 'todas' && !isset(ESTADOS_BANDEJA[$estado])) {
    $estado = 'pendiente';
}
$tipoFiltro = entrada('tipo');
$condiciones = [];
[$filtro, $params] = filtro_busqueda(['b.titulo', 'b.detalle', 'b.remitente', 'b.asunto', 'b.cliente_texto'], entrada('q'));
if ($filtro) {
    $condiciones[] = $filtro;
}
if ($estado !== 'todas') {
    $condiciones[] = 'b.estado = :estado';
    $params['estado'] = $estado;
}
if (isset(TIPOS_BANDEJA[$tipoFiltro])) {
    $condiciones[] = 'b.tipo = :tipo';
    $params['tipo'] = $tipoFiltro;
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

[$limite, $desde] = paginacion_limites();
$total = (int)q_valor("SELECT COUNT(*) FROM bandeja_correos b $where", $params);
$filas = q_todos(
    "SELECT b.*, u.nombre AS revisor FROM bandeja_correos b LEFT JOIN usuarios u ON u.id = b.revisado_por
     $where ORDER BY COALESCE(b.recibido_en, b.creado_en) DESC, b.id DESC LIMIT $limite OFFSET $desde",
    $params
);
$destinos = ['tarea' => ['tareas', 'ver'], 'actividad' => ['actividades', 'form'], 'oportunidad' => ['oportunidades', 'ver']];
$etiquetaDestino = ['tarea' => 'Ver tarea', 'actividad' => 'Ver gestión', 'oportunidad' => 'Ver prospecto'];

layout_inicio('Bandeja de correos', 'bandeja');
?>
<div class="encabezado">
    <h1>Bandeja de correos</h1>
    <?php if (es_admin()): ?><div><a class="boton secundario" href="<?= e(url('correos')) ?>">Buzones y rutina</a></div><?php endif; ?>
</div>
<p class="tenue">Tareas, reuniones, gestiones y prospectos detectados en los correos del equipo. Lo que ya existe en el CRM se propone como complemento o actualización (o se omite); nada cambia hasta que usted lo aprueba.
    <?php $pendientes = bandeja_pendientes(); ?>
    <strong><?= $pendientes ?></strong> por revisar.</p>

<form class="barra-lista" method="get">
    <input type="hidden" name="r" value="bandeja">
    <input type="search" name="q" value="<?= e(entrada('q')) ?>" placeholder="Buscar…">
    <?= selector('estado', '', ['pendiente' => 'Por revisar', 'aprobada' => 'Aprobadas', 'rechazada' => 'Rechazadas', 'omitida' => 'Omitidas (ya existían)', 'todas' => 'Todas'], $estado, false, 'aria-label="Estado"') ?>
    <?= selector('tipo', '', TIPOS_BANDEJA, $tipoFiltro, 'Todos los tipos', 'aria-label="Tipo"') ?>
    <button type="submit" class="secundario">Filtrar</button>
</form>

<table>
    <thead><tr><th>Tipo</th><th>Propuesta</th><th>Cuándo</th><th>Cliente mencionado</th><th>Correo de origen</th><th>Confianza</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($filas as $b): ?>
        <tr class="<?= in_array($b['estado'], ['rechazada', 'omitida'], true) ? 'hecha' : '' ?>">
            <td><span class="badge"><?= e(TIPOS_BANDEJA[$b['tipo']] ?? $b['tipo']) ?></span></td>
            <td>
                <?php if ($b['estado'] === 'pendiente'): ?>
                    <a href="<?= e(url('bandeja', ['a' => 'revisar', 'id' => $b['id']])) ?>"><?= e($b['titulo']) ?></a>
                <?php else: ?>
                    <?= e($b['titulo']) ?>
                <?php endif; ?>
                <?php if (($b['accion'] ?? 'nueva') !== 'nueva'): ?><br><span class="badge"><?= e(ACCIONES_BANDEJA[$b['accion']] ?? $b['accion']) ?></span><?php endif; ?>
                <?php if ($b['destino_tipo'] && $b['destino_id'] && isset(DESTINOS_BANDEJA[$b['destino_tipo']])): [, , $lr, $la] = DESTINOS_BANDEJA[$b['destino_tipo']]; ?>
                    <small><a href="<?= e(url($lr, ['a' => $la, 'id' => $b['destino_id']])) ?>" target="_blank" rel="noopener">ver <?= e(DESTINOS_BANDEJA[$b['destino_tipo']][4]) ?> existente</a></small>
                <?php endif; ?>
                <?php if ($b['motivo']): ?><br><small class="tenue"><?= e(mb_strimwidth($b['motivo'], 0, 160, '…')) ?></small><?php endif; ?>
                <?php if ($b['detalle']): ?><br><small class="tenue"><?= e(mb_strimwidth($b['detalle'], 0, 140, '…')) ?></small><?php endif; ?>
            </td>
            <td class="nowrap"><?= $b['fecha_evento'] ? e(fecha($b['fecha_evento'], bandeja_con_hora($b['fecha_evento']))) : '<span class="tenue">—</span>' ?></td>
            <td><?= e($b['cliente_texto'] ?? '') ?></td>
            <td><?= e($b['remitente'] ?? '') ?><br><small class="tenue"><?= e(mb_strimwidth((string)$b['asunto'], 0, 70, '…')) ?> · <?= e(fecha($b['recibido_en'])) ?></small></td>
            <td><span class="badge"><?= e(CONFIANZAS_BANDEJA[$b['confianza']] ?? $b['confianza']) ?></span></td>
            <td class="derecha nowrap">
                <?php if ($b['estado'] === 'pendiente'): ?>
                    <a class="boton chico" href="<?= e(url('bandeja', ['a' => 'revisar', 'id' => $b['id']])) ?>">Revisar</a>
                    <?= boton_post(url('bandeja', ['a' => 'rechazar', 'id' => $b['id']]), 'Rechazar', 'chico secundario', '¿Rechazar esta propuesta?') ?>
                <?php elseif ($b['estado'] === 'aprobada'): ?>
                    <?php if ($b['creado_tipo'] && $b['creado_id'] && isset($destinos[$b['creado_tipo']])): ?>
                        <a href="<?= e(url($destinos[$b['creado_tipo']][0], ['a' => $destinos[$b['creado_tipo']][1], 'id' => $b['creado_id']])) ?>"><?= e($etiquetaDestino[$b['creado_tipo']]) ?></a>
                    <?php endif; ?>
                    <br><small class="tenue">Aprobada<?= $b['revisor'] ? ' por ' . e($b['revisor']) : '' ?></small>
                <?php else: ?>
                    <?= boton_post(url('bandeja', ['a' => 'reabrir', 'id' => $b['id']]), $b['estado'] === 'omitida' ? 'Revisar igualmente' : 'Volver a revisar', 'chico secundario') ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$filas): ?><tr><td colspan="7" class="vacio">No hay propuestas con estos filtros. Cada tarde el asistente revisa los correos y deja aquí lo que detecte.</td></tr><?php endif; ?>
    </tbody>
</table>
<?= paginacion_html($total) ?>
<?php
layout_fin();
