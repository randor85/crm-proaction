<?php
declare(strict_types=1);

/*
 * Bandeja de correos.
 * Cola de propuestas (tareas, reuniones, gestiones y prospectos) detectadas en los correos
 * del equipo. Las deja api_bandeja.php; nada pasa a las tablas reales hasta que una persona
 * la aprueba desde el módulo "Bandeja de correos".
 */

const TIPOS_BANDEJA = [
    'tarea'     => 'Tarea',
    'reunion'   => 'Reunión',
    'gestion'   => 'Gestión',
    'prospecto' => 'Prospecto',
];

const ESTADOS_BANDEJA = [
    'pendiente' => 'Por revisar',
    'aprobada'  => 'Aprobada',
    'rechazada' => 'Rechazada',
    'omitida'   => 'Omitida (ya existía)',
];

/** Qué hacer con la propuesta frente a lo que ya existe en el CRM. */
const ACCIONES_BANDEJA = [
    'nueva'        => 'Crear nueva',
    'complementar' => 'Complementar existente',
    'actualizar'   => 'Actualizar existente',
    'saltar'       => 'Omitir (ya está registrada)',
];

/** Tipo de registro existente al que puede apuntar una propuesta → [tabla, columna de notas, ruta, acción de la ficha, nombre]. */
const DESTINOS_BANDEJA = [
    'tarea'       => ['tareas', 'descripcion', 'tareas', 'ver', 'tarea'],
    'actividad'   => ['actividades', 'descripcion', 'actividades', 'form', 'gestión'],
    'oportunidad' => ['oportunidades', 'notas', 'oportunidades', 'ver', 'prospecto'],
];

/** Campos que una propuesta puede cambiar en el registro existente (completar una tarea se hace a mano, por la recurrencia). */
function bandeja_cambios_permitidos(string $destinoTipo): array
{
    return match ($destinoTipo) {
        'tarea'       => ['estado' => array_diff_key(ESTADOS_TAREA, ['completada' => 1]), 'vencimiento' => null, 'prioridad' => PRIORIDADES],
        'oportunidad' => ['etapa' => ETAPAS],
        default       => [],
    };
}

const CONFIANZAS_BANDEJA = [
    'alta'  => 'Alta',
    'media' => 'Media',
    'baja'  => 'Baja',
];

/** Propuestas por revisar (para el contador del menú). Devuelve 0 si la tabla aún no existe. */
function bandeja_pendientes(): int
{
    try {
        return (int)q_valor("SELECT COUNT(*) FROM bandeja_correos WHERE estado = 'pendiente'");
    } catch (PDOException $ex) {
        return 0;
    }
}

/** Identificador estable de un correo (el Message-ID, sin distinguir mayúsculas). */
function bandeja_hash_correo(string $correoId): string
{
    return sha1(mb_strtolower(trim($correoId)));
}

/** Fecha o fecha y hora en cualquier formato razonable → 'Y-m-d H:i:s' (o null si no se entiende). */
function bandeja_fecha($valor): ?string
{
    $v = trim((string)$valor);
    if ($v === '') {
        return null;
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** ¿La fecha guardada trae hora (distinta de medianoche)? */
function bandeja_con_hora(?string $fecha): bool
{
    return $fecha !== null && substr($fecha, 11, 8) !== '00:00:00';
}

/** Cliente activo cuyo nombre coincide con el texto mencionado en el correo (solo si es único). */
function bandeja_cliente_sugerido(?string $texto): ?int
{
    $texto = trim((string)$texto);
    if (mb_strlen($texto) < 3) {
        return null;
    }
    $filas = q_todos('SELECT id FROM clientes WHERE activo = 1 AND nombre LIKE ? LIMIT 2', ['%' . $texto . '%']);
    return count($filas) === 1 ? (int)$filas[0]['id'] : null;
}

/** Usuario cuyo correo coincide con el buzón sugerido por la propuesta. */
function bandeja_usuario_sugerido(?string $email): ?int
{
    $email = mb_strtolower(trim((string)$email));
    if ($email === '') {
        return null;
    }
    $id = q_valor('SELECT id FROM usuarios WHERE LOWER(email) = ? AND activo = 1', [$email]);
    return $id ? (int)$id : null;
}

/** Recorta un texto a un largo máximo; vacío pasa a null. */
function bandeja_texto($valor, int $max): ?string
{
    $v = trim((string)$valor);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/**
 * Guarda propuestas en la cola (solo escribe en bandeja_correos). Una misma propuesta
 * (correo_id + tipo + ordinal) que llega dos veces se cuenta como duplicada.
 * @return array{nuevas: int, duplicadas: int, invalidas: array}
 */
function bandeja_guardar_propuestas(array $propuestas): array
{
    $nuevas = 0;
    $duplicadas = 0;
    $invalidas = [];
    foreach ($propuestas as $i => $p) {
        if (!is_array($p)) {
            $invalidas[] = ['posicion' => $i, 'motivo' => 'no es un objeto'];
            continue;
        }
        $tipo = (string)($p['tipo'] ?? '');
        $correoId = trim((string)($p['correo_id'] ?? ''));
        $titulo = bandeja_texto($p['titulo'] ?? '', 200);
        if (!isset(TIPOS_BANDEJA[$tipo]) || $correoId === '' || $titulo === null) {
            $invalidas[] = ['posicion' => $i, 'motivo' => 'faltan correo_id, titulo o un tipo válido'];
            continue;
        }
        $confianza = (string)($p['confianza'] ?? 'media');
        $ordinal = max(1, min(99, (int)($p['ordinal'] ?? 1)));

        // Vínculo con lo que ya existe en el CRM (la rutina compara antes de proponer)
        $accion = (string)($p['accion'] ?? 'nueva');
        $destinoTipo = (string)($p['destino_tipo'] ?? '');
        $destinoId = (int)($p['destino_id'] ?? 0);
        $motivo = bandeja_texto($p['motivo'] ?? '', 300);
        $cambios = null;
        if (!isset(ACCIONES_BANDEJA[$accion])) {
            $accion = 'nueva';
        }
        if ($accion !== 'nueva' && !bandeja_destino($destinoTipo, $destinoId)) {
            $motivo = trim('El registro indicado (' . ($destinoTipo ?: '?') . ' #' . $destinoId . ') no existe; se deja como propuesta nueva. ' . $motivo);
            $accion = 'nueva';
            $destinoTipo = '';
            $destinoId = 0;
        }
        if ($accion === 'nueva') {
            $dup = bandeja_posible_duplicado($tipo, (string)$titulo, bandeja_texto($p['cliente_texto'] ?? '', 150));
            if ($dup) {
                [$destinoTipo, $destinoId] = $dup;
                $motivo = trim('Revisar: se parece (' . $dup[2] . ' %) a un registro existente. ' . $motivo);
            } else {
                $destinoTipo = '';
                $destinoId = 0;
            }
        } elseif (is_array($p['cambios'] ?? null)) {
            $limpios = array_intersect_key($p['cambios'], bandeja_cambios_permitidos($destinoTipo));
            $cambios = $limpios ? json_encode($limpios, JSON_UNESCAPED_UNICODE) : null;
        }
        $hash = bandeja_hash_correo($correoId);
        if (q_valor('SELECT 1 FROM bandeja_correos WHERE correo_hash = ? AND tipo = ? AND ordinal = ?', [$hash, $tipo, $ordinal])) {
            $duplicadas++;
            continue;
        }
        try {
            insertar('bandeja_correos', [
                'correo_hash'       => $hash,
                'correo_ref'        => mb_substr($correoId, 0, 250),
                'ordinal'           => $ordinal,
                'tipo'              => $tipo,
                'titulo'            => $titulo,
                'detalle'           => bandeja_texto($p['detalle'] ?? '', 2000),
                'fecha_evento'      => bandeja_fecha($p['fecha_evento'] ?? ''),
                'cliente_texto'     => bandeja_texto($p['cliente_texto'] ?? '', 150),
                'contacto_nombre'   => bandeja_texto($p['contacto_nombre'] ?? '', 100),
                'contacto_email'    => bandeja_texto($p['contacto_email'] ?? '', 150),
                'responsable_email' => bandeja_texto($p['responsable_email'] ?? '', 150),
                'confianza'         => isset(CONFIANZAS_BANDEJA[$confianza]) ? $confianza : 'media',
                'remitente'         => bandeja_texto($p['remitente'] ?? '', 200),
                'asunto'            => bandeja_texto($p['asunto'] ?? '', 250),
                'recibido_en'       => bandeja_fecha($p['recibido_en'] ?? ''),
                'buzones'           => bandeja_texto($p['buzones'] ?? '', 250),
                'estado'            => $accion === 'saltar' ? 'omitida' : 'pendiente',
                'accion'            => $accion,
                'destino_tipo'      => $destinoTipo ?: null,
                'destino_id'        => $destinoId ?: null,
                'motivo'            => $motivo,
                'cambios'           => $cambios,
                'creado_en'         => ahora(),
            ]);
            $nuevas++;
        } catch (PDOException $ex) {
            // Dos envíos simultáneos de la misma propuesta: el índice único gana.
            if (!preg_match('/unique|duplicate/i', $ex->getMessage())) {
                throw $ex;
            }
            $duplicadas++;
        }
    }
    return ['nuevas' => $nuevas, 'duplicadas' => $duplicadas, 'invalidas' => $invalidas];
}


/** Texto sin tildes ni signos, en minúsculas, para comparar títulos. */
function bandeja_normalizar(string $t): string
{
    $t = mb_strtolower(trim($t));
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $t));
}

/** Registro existente (fila) al que apunta un destino, o null si no existe. */
function bandeja_destino(?string $tipo, $id): ?array
{
    if (!isset(DESTINOS_BANDEJA[$tipo ?? '']) || !$id) {
        return null;
    }
    return q_uno('SELECT * FROM ' . DESTINOS_BANDEJA[$tipo][0] . ' WHERE id = ?', [(int)$id]);
}

/** Título legible de un registro existente (para mostrarlo en la Bandeja). */
function bandeja_destino_titulo(array $fila): string
{
    return (string)($fila['titulo'] ?? $fila['asunto'] ?? '');
}

/**
 * Segunda línea de defensa contra duplicados, independiente de la rutina: busca una tarea, gestión
 * o prospecto parecido a una propuesta «nueva». Solo avisa; la decisión es de quien revisa.
 * @return array{0:string,1:int,2:int}|null [tipo destino, id, porcentaje de parecido]
 */
function bandeja_posible_duplicado(string $tipo, string $titulo, ?string $clienteTexto): ?array
{
    $n = bandeja_normalizar($titulo);
    if (mb_strlen($n) < 6) {
        return null;
    }
    $clienteId = bandeja_cliente_sugerido($clienteTexto);
    if ($tipo === 'tarea') {
        $filas = q_todos("SELECT id, titulo, cliente_id FROM tareas WHERE estado <> 'completada' OR completada_en >= ?", [date('Y-m-d', strtotime('-30 days'))]);
        $dest = 'tarea';
    } elseif ($tipo === 'prospecto') {
        $filas = q_todos("SELECT o.id, o.titulo, e.cliente_id FROM oportunidades o LEFT JOIN empresas e ON e.id = o.empresa_id WHERE o.etapa <> 'perdida'");
        $dest = 'oportunidad';
    } else {
        $filas = q_todos('SELECT id, asunto AS titulo, cliente_id FROM actividades WHERE fecha >= ?', [date('Y-m-d', strtotime('-60 days'))]);
        $dest = 'actividad';
    }
    $mejor = null;
    foreach ($filas as $f) {
        similar_text($n, bandeja_normalizar((string)$f['titulo']), $pct);
        $umbral = ($clienteId && (int)$f['cliente_id'] === $clienteId) ? 60 : 80;
        if ($pct >= $umbral && (!$mejor || $pct > $mejor[2])) {
            $mejor = [$dest, (int)$f['id'], (int)round($pct)];
        }
    }
    return $mejor;
}

/** Propuestas ya vistas (pendientes y de los últimos 30 días), para que la rutina no repita lo ya propuesto. */
function bandeja_resumen_para_rutina(): array
{
    return q_todos(
        "SELECT id, tipo, accion, titulo, cliente_texto, asunto, estado, destino_tipo, destino_id
         FROM bandeja_correos
         WHERE estado = 'pendiente' OR creado_en >= ? ORDER BY id DESC LIMIT 300",
        [date('Y-m-d', strtotime('-30 days'))]
    );
}

/**
 * Aplica una propuesta a un registro existente: agrega una nota fechada con el detalle del correo
 * y, si corresponde, cambia estado, vencimiento, prioridad o etapa. Nunca completa tareas.
 * @param array $campos ['nota' => string, 'estado'|'vencimiento'|'prioridad'|'etapa' => valor]
 * @throws InvalidArgumentException si no hay nada que aplicar o el registro ya no existe
 */
function bandeja_aplicar_a_existente(array $propuesta, string $tipo, int $id, array $campos): string
{
    $fila = bandeja_destino($tipo, $id);
    if (!$fila) {
        throw new InvalidArgumentException('El registro existente ya no está disponible.');
    }
    [$tabla, $colNotas] = DESTINOS_BANDEJA[$tipo];
    $datos = [];
    $nota = trim((string)($campos['nota'] ?? ''));
    if ($nota !== '') {
        $origen = trim((string)($propuesta['remitente'] ?? '') . (!empty($propuesta['asunto']) ? ' · ' . $propuesta['asunto'] : ''));
        $linea = '[' . date('d-m-Y') . ' · correo' . ($origen !== '' ? ' de ' . mb_substr($origen, 0, 120) : '') . '] ' . $nota;
        $datos[$colNotas] = trim((string)($fila[$colNotas] ?? '') . "\n" . $linea);
    }
    foreach (bandeja_cambios_permitidos($tipo) as $campo => $opciones) {
        $v = trim((string)($campos[$campo] ?? ''));
        if ($v === '') {
            continue;
        }
        if ($campo === 'vencimiento') {
            $v = substr((string)bandeja_fecha($v), 0, 10);
            if ($v === '') {
                continue;
            }
        } elseif (!isset($opciones[$v])) {
            continue;
        }
        if ((string)($fila[$campo] ?? '') !== $v) {
            $datos[$campo] = $v;
        }
    }
    if (!$datos) {
        throw new InvalidArgumentException('No hay nada que aplicar: escriba una nota o elija algún cambio.');
    }
    if (array_key_exists('actualizado_en', $fila)) {
        $datos['actualizado_en'] = ahora();
    }
    actualizar($tabla, $id, $datos);
    return 'Registro existente actualizado: ' . bandeja_destino_titulo($fila);
}
