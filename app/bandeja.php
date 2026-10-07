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
];

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
                'estado'            => 'pendiente',
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
