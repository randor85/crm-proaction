<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = config('db');
    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        if (($c['driver'] ?? 'mysql') === 'sqlite') {
            $pdo = new PDO('sqlite:' . $c['sqlite_ruta'], null, null, $opciones);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $c['host'],
                (int)($c['puerto'] ?? 3306),
                $c['nombre']
            );
            $pdo = new PDO($dsn, $c['usuario'], $c['clave'], $opciones);
        }
    } catch (PDOException $ex) {
        error_log('CRM - error de conexión: ' . $ex->getMessage());
        http_response_code(500);
        exit('No se pudo conectar a la base de datos. Revise config.php.');
    }

    return $pdo;
}

function db_driver(): string
{
    return config('db')['driver'] ?? 'mysql';
}

/** Ejecuta una consulta preparada y devuelve el statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_todos(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_uno(string $sql, array $params = []): ?array
{
    $fila = q($sql, $params)->fetch();
    return $fila === false ? null : $fila;
}

function q_valor(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Inserta una fila y devuelve su id. */
function insertar(string $tabla, array $datos): int
{
    $cols = array_keys($datos);
    $sql = 'INSERT INTO ' . $tabla . ' (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
    q($sql, $datos);
    return (int)db()->lastInsertId();
}

function actualizar(string $tabla, int $id, array $datos): void
{
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($datos)));
    $datos['__id'] = $id;
    q('UPDATE ' . $tabla . ' SET ' . $sets . ' WHERE id = :__id', $datos);
}

/* ---------------- Listas para selectores ---------------- */

function opciones_usuarios(): array
{
    return array_column(q_todos('SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre'), 'nombre', 'id');
}

function opciones_empresas(): array
{
    return array_column(q_todos('SELECT id, nombre FROM empresas ORDER BY nombre'), 'nombre', 'id');
}

function opciones_contactos(): array
{
    $filas = q_todos(
        'SELECT c.id, c.nombre, c.apellido, e.nombre AS empresa
         FROM contactos c LEFT JOIN empresas e ON e.id = c.empresa_id
         ORDER BY c.nombre, c.apellido'
    );
    $op = [];
    foreach ($filas as $f) {
        $op[$f['id']] = trim($f['nombre'] . ' ' . $f['apellido']) . ($f['empresa'] ? ' (' . $f['empresa'] . ')' : '');
    }
    return $op;
}

function opciones_oportunidades(): array
{
    return array_column(q_todos('SELECT id, titulo FROM oportunidades ORDER BY titulo'), 'titulo', 'id');
}

/**
 * Construye un "WHERE (col1 LIKE ? OR col2 LIKE ? ...)" para la búsqueda.
 * Usa un parámetro distinto por columna (MySQL no permite repetir placeholders).
 * @return array{0:string,1:array}
 */
function filtro_busqueda(array $columnas, string $texto): array
{
    if ($texto === '') {
        return ['', []];
    }
    $partes = [];
    $params = [];
    foreach ($columnas as $i => $col) {
        $partes[] = "$col LIKE :q$i";
        $params["q$i"] = '%' . $texto . '%';
    }
    return ['(' . implode(' OR ', $partes) . ')', $params];
}
