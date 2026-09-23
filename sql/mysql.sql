-- CRM ProAction - esquema MySQL / MariaDB
-- Puede importarse desde phpMyAdmin o ejecutarse con install.php

CREATE TABLE IF NOT EXISTS usuarios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol           VARCHAR(20)  NOT NULL DEFAULT 'usuario',
    activo        TINYINT(1)   NOT NULL DEFAULT 1,
    ultimo_login  DATETIME NULL,
    creado_en     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresas (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(150) NOT NULL,
    identificacion VARCHAR(50)  NULL,
    sector         VARCHAR(100) NULL,
    telefono       VARCHAR(50)  NULL,
    email          VARCHAR(150) NULL,
    sitio_web      VARCHAR(200) NULL,
    direccion      VARCHAR(200) NULL,
    ciudad         VARCHAR(100) NULL,
    notas          TEXT NULL,
    responsable_id INT UNSIGNED NULL,
    creado_en      DATETIME NOT NULL,
    actualizado_en DATETIME NOT NULL,
    INDEX idx_empresas_nombre (nombre),
    CONSTRAINT fk_empresas_resp FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contactos (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id     INT UNSIGNED NULL,
    nombre         VARCHAR(100) NOT NULL,
    apellido       VARCHAR(100) NULL,
    cargo          VARCHAR(100) NULL,
    email          VARCHAR(150) NULL,
    telefono       VARCHAR(50)  NULL,
    movil          VARCHAR(50)  NULL,
    notas          TEXT NULL,
    responsable_id INT UNSIGNED NULL,
    creado_en      DATETIME NOT NULL,
    actualizado_en DATETIME NOT NULL,
    INDEX idx_contactos_nombre (nombre, apellido),
    CONSTRAINT fk_contactos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
    CONSTRAINT fk_contactos_resp FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oportunidades (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo         VARCHAR(150) NOT NULL,
    empresa_id     INT UNSIGNED NULL,
    contacto_id    INT UNSIGNED NULL,
    monto          DECIMAL(14,2) NOT NULL DEFAULT 0,
    etapa          VARCHAR(20) NOT NULL DEFAULT 'prospecto',
    probabilidad   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    fecha_cierre   DATE NULL,
    responsable_id INT UNSIGNED NULL,
    notas          TEXT NULL,
    creado_en      DATETIME NOT NULL,
    actualizado_en DATETIME NOT NULL,
    INDEX idx_oport_etapa (etapa),
    CONSTRAINT fk_oport_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
    CONSTRAINT fk_oport_contacto FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE SET NULL,
    CONSTRAINT fk_oport_resp FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS actividades (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo           VARCHAR(20) NOT NULL DEFAULT 'tarea',
    asunto         VARCHAR(200) NOT NULL,
    descripcion    TEXT NULL,
    fecha          DATETIME NOT NULL,
    completada     TINYINT(1) NOT NULL DEFAULT 0,
    empresa_id     INT UNSIGNED NULL,
    contacto_id    INT UNSIGNED NULL,
    oportunidad_id INT UNSIGNED NULL,
    usuario_id     INT UNSIGNED NULL,
    creado_en      DATETIME NOT NULL,
    INDEX idx_act_usuario_fecha (usuario_id, completada, fecha),
    CONSTRAINT fk_act_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
    CONSTRAINT fk_act_contacto FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE SET NULL,
    CONSTRAINT fk_act_oport FOREIGN KEY (oportunidad_id) REFERENCES oportunidades(id) ON DELETE SET NULL,
    CONSTRAINT fk_act_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_intentos (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip        VARCHAR(45) NOT NULL,
    creado_en DATETIME NOT NULL,
    INDEX idx_login_ip (ip, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
