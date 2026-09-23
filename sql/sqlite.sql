-- CRM ProAction - esquema SQLite (pruebas o hosting sin MySQL)

CREATE TABLE IF NOT EXISTS usuarios (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre        VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol           VARCHAR(20)  NOT NULL DEFAULT 'usuario',
    activo        INTEGER   NOT NULL DEFAULT 1,
    ultimo_login  DATETIME NULL,
    creado_en     DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS empresas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre         VARCHAR(150) NOT NULL,
    identificacion VARCHAR(50)  NULL,
    sector         VARCHAR(100) NULL,
    telefono       VARCHAR(50)  NULL,
    email          VARCHAR(150) NULL,
    sitio_web      VARCHAR(200) NULL,
    direccion      VARCHAR(200) NULL,
    ciudad         VARCHAR(100) NULL,
    notas          TEXT NULL,
    responsable_id INTEGER NULL,
    creado_en      DATETIME NOT NULL,
    actualizado_en DATETIME NOT NULL,
    FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS contactos (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    empresa_id     INTEGER NULL,
    nombre         VARCHAR(100) NOT NULL,
    apellido       VARCHAR(100) NULL,
    cargo          VARCHAR(100) NULL,
    email          VARCHAR(150) NULL,
    telefono       VARCHAR(50)  NULL,
    movil          VARCHAR(50)  NULL,
    notas          TEXT NULL,
    responsable_id INTEGER NULL,
    creado_en      DATETIME NOT NULL,
    actualizado_en DATETIME NOT NULL,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
    FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS oportunidades (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    titulo         VARCHAR(150) NOT NULL,
    empresa_id     INTEGER NULL,
    contacto_id    INTEGER NULL,
    monto          DECIMAL(14,2) NOT NULL DEFAULT 0,
    etapa          VARCHAR(20) NOT NULL DEFAULT 'prospecto',
    probabilidad   TINYINTEGER NOT NULL DEFAULT 0,
    fecha_cierre   DATE NULL,
    responsable_id INTEGER NULL,
    notas          TEXT NULL,
    creado_en      DATETIME NOT NULL,
    actualizado_en DATETIME NOT NULL,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
    FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE SET NULL,
    FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS actividades (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo           VARCHAR(20) NOT NULL DEFAULT 'tarea',
    asunto         VARCHAR(200) NOT NULL,
    descripcion    TEXT NULL,
    fecha          DATETIME NOT NULL,
    completada     INTEGER NOT NULL DEFAULT 0,
    empresa_id     INTEGER NULL,
    contacto_id    INTEGER NULL,
    oportunidad_id INTEGER NULL,
    usuario_id     INTEGER NULL,
    creado_en      DATETIME NOT NULL,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
    FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE SET NULL,
    FOREIGN KEY (oportunidad_id) REFERENCES oportunidades(id) ON DELETE SET NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS login_intentos (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    ip        VARCHAR(45) NOT NULL,
    creado_en DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_empresas_nombre ON empresas (nombre);
CREATE INDEX IF NOT EXISTS idx_contactos_nombre ON contactos (nombre, apellido);
CREATE INDEX IF NOT EXISTS idx_oport_etapa ON oportunidades (etapa);
CREATE INDEX IF NOT EXISTS idx_act_usuario_fecha ON actividades (usuario_id, completada, fecha);
CREATE INDEX IF NOT EXISTS idx_login_ip ON login_intentos (ip, creado_en);
