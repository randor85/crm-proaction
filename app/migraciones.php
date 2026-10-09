<?php
declare(strict_types=1);

/*
 * Migraciones de base de datos.
 * Se aplican solas al cargar cualquier página (una sola vez cada una) y quedan
 * registradas en la tabla `migraciones`. Para cambiar el esquema agregue una
 * migración nueva al final; nunca modifique una que ya se aplicó en el servidor.
 *
 * Marcadores que se traducen según el motor:
 *   {ID}   clave primaria autoincremental
 *   {INT}  entero para claves foráneas
 *   {BOOL} booleano 0/1
 *   {FIN}  cierre de CREATE TABLE (motor y juego de caracteres en MySQL)
 */
const MIGRACIONES = [
    '2026_09_23_gestion_tributaria' => [
        // Clientes: la unidad de relación y cobro (grupo, empresa o persona)
        'CREATE TABLE clientes (
            id               {ID},
            nombre           VARCHAR(150) NOT NULL,
            tipo             VARCHAR(20)  NOT NULL DEFAULT \'empresa\',
            rut              VARCHAR(20)  NULL,
            email            VARCHAR(150) NULL,
            telefono         VARCHAR(50)  NULL,
            direccion        VARCHAR(200) NULL,
            ejecutivo_id     {INT} NULL,
            honorario_monto  DECIMAL(14,4) NOT NULL DEFAULT 0,
            honorario_moneda VARCHAR(3)   NOT NULL DEFAULT \'UF\',
            documento_tipo   VARCHAR(20)  NOT NULL DEFAULT \'factura_afecta\',
            activo           {BOOL} NOT NULL DEFAULT 1,
            notas            TEXT NULL,
            creado_en        DATETIME NOT NULL,
            actualizado_en   DATETIME NOT NULL,
            FOREIGN KEY (ejecutivo_id) REFERENCES usuarios(id) ON DELETE SET NULL
        {FIN}',
        'CREATE INDEX idx_clientes_nombre ON clientes (nombre)',

        // Empresas pasan a ser los RUT de cada cliente
        'ALTER TABLE empresas ADD COLUMN cliente_id {INT} NULL',
        'ALTER TABLE empresas ADD COLUMN regimen VARCHAR(60) NULL',
        'ALTER TABLE empresas ADD COLUMN inicio_actividades DATE NULL',
        'CREATE INDEX idx_empresas_cliente ON empresas (cliente_id)',

        // Socios de cada empresa (personas u otras empresas)
        'CREATE TABLE socios (
            id               {ID},
            empresa_id       {INT} NOT NULL,
            nombre           VARCHAR(150) NOT NULL,
            rut              VARCHAR(20)  NULL,
            tipo             VARCHAR(10)  NOT NULL DEFAULT \'persona\',
            socio_empresa_id {INT} NULL,
            porcentaje       DECIMAL(7,4) NULL,
            representante    {BOOL} NOT NULL DEFAULT 0,
            email            VARCHAR(150) NULL,
            telefono         VARCHAR(50)  NULL,
            notas            TEXT NULL,
            creado_en        DATETIME NOT NULL,
            FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
            FOREIGN KEY (socio_empresa_id) REFERENCES empresas(id) ON DELETE SET NULL
        {FIN}',
        'CREATE INDEX idx_socios_rut ON socios (rut)',

        // Tareas pendientes por cliente, con recurrencia
        'CREATE TABLE tareas (
            id             {ID},
            titulo         VARCHAR(200) NOT NULL,
            descripcion    TEXT NULL,
            cliente_id     {INT} NULL,
            empresa_id     {INT} NULL,
            responsable_id {INT} NULL,
            vencimiento    DATE NULL,
            estado         VARCHAR(20) NOT NULL DEFAULT \'pendiente\',
            prioridad      VARCHAR(10) NOT NULL DEFAULT \'normal\',
            recurrencia    VARCHAR(12) NOT NULL DEFAULT \'ninguna\',
            completada_en  DATETIME NULL,
            creado_por     {INT} NULL,
            creado_en      DATETIME NOT NULL,
            actualizado_en DATETIME NOT NULL,
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
            FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
        {FIN}',
        'CREATE INDEX idx_tareas_resp ON tareas (responsable_id, estado, vencimiento)',
        'CREATE INDEX idx_tareas_cliente ON tareas (cliente_id, estado)',

        // Las actividades pasan a ser la bitácora de gestiones
        'ALTER TABLE actividades ADD COLUMN cliente_id {INT} NULL',
        'ALTER TABLE actividades ADD COLUMN tarea_id {INT} NULL',
        'CREATE INDEX idx_act_cliente ON actividades (cliente_id)',
        'CREATE INDEX idx_act_tarea ON actividades (tarea_id)',

        // Facturación interna
        'CREATE TABLE facturas (
            id                {ID},
            cliente_id        {INT} NOT NULL,
            empresa_id        {INT} NULL,
            tipo_documento    VARCHAR(20) NOT NULL DEFAULT \'factura_afecta\',
            folio             VARCHAR(30) NULL,
            fecha_emision     DATE NOT NULL,
            fecha_vencimiento DATE NULL,
            periodo           VARCHAR(7) NULL,
            glosa             VARCHAR(250) NOT NULL,
            moneda            VARCHAR(3) NOT NULL DEFAULT \'UF\',
            monto_uf          DECIMAL(14,4) NULL,
            valor_uf          DECIMAL(12,2) NULL,
            neto              DECIMAL(14,2) NOT NULL DEFAULT 0,
            impuesto          DECIMAL(14,2) NOT NULL DEFAULT 0,
            total             DECIMAL(14,2) NOT NULL DEFAULT 0,
            estado            VARCHAR(12) NOT NULL DEFAULT \'borrador\',
            fecha_pago        DATE NULL,
            notas             TEXT NULL,
            creado_por        {INT} NULL,
            creado_en         DATETIME NOT NULL,
            actualizado_en    DATETIME NOT NULL,
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
            FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
        {FIN}',
        'CREATE INDEX idx_facturas_cliente ON facturas (cliente_id, periodo)',
        'CREATE INDEX idx_facturas_estado ON facturas (estado, fecha_emision)',

        // Caché de indicadores económicos (UF, UTM, dólar)
        'CREATE TABLE indicadores (
            codigo VARCHAR(10)   NOT NULL,
            fecha  DATE          NOT NULL,
            valor  DECIMAL(12,2) NOT NULL,
            PRIMARY KEY (codigo, fecha)
        {FIN}',

        // Gestor de credenciales (clave cifrada con AES-256-GCM)
        'CREATE TABLE credenciales (
            id              {ID},
            cliente_id      {INT} NULL,
            empresa_id      {INT} NULL,
            institucion     VARCHAR(80)  NOT NULL,
            usuario         VARCHAR(150) NULL,
            clave_cifrada   TEXT NULL,
            url             VARCHAR(250) NULL,
            notas           TEXT NULL,
            actualizado_por {INT} NULL,
            creado_en       DATETIME NOT NULL,
            actualizado_en  DATETIME NOT NULL,
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
            FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
        {FIN}',
        'CREATE TABLE credenciales_log (
            id            {ID},
            credencial_id {INT} NULL,
            usuario_id    {INT} NULL,
            accion        VARCHAR(20)  NOT NULL,
            detalle       VARCHAR(250) NULL,
            ip            VARCHAR(45)  NULL,
            creado_en     DATETIME NOT NULL,
            FOREIGN KEY (credencial_id) REFERENCES credenciales(id) ON DELETE SET NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        {FIN}',
        'CREATE INDEX idx_credlog_cred ON credenciales_log (credencial_id, creado_en)',

        // Repositorio de documentos (los archivos viven fuera de public_html)
        'CREATE TABLE documentos (
            id          {ID},
            cliente_id  {INT} NULL,
            empresa_id  {INT} NULL,
            categoria   VARCHAR(30)  NOT NULL DEFAULT \'otro\',
            nombre      VARCHAR(200) NOT NULL,
            archivo     VARCHAR(100) NOT NULL,
            mime        VARCHAR(100) NULL,
            tamano      {INT} NOT NULL DEFAULT 0,
            periodo     VARCHAR(7) NULL,
            descripcion TEXT NULL,
            subido_por  {INT} NULL,
            creado_en   DATETIME NOT NULL,
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
            FOREIGN KEY (subido_por) REFERENCES usuarios(id) ON DELETE SET NULL
        {FIN}',
        'CREATE INDEX idx_docs_cliente ON documentos (cliente_id, categoria)',

        // Usuarios: calendario iCal, verificación en dos pasos y permiso de credenciales
        'ALTER TABLE usuarios ADD COLUMN ical_token VARCHAR(64) NULL',
        'ALTER TABLE usuarios ADD COLUMN totp_secreto VARCHAR(64) NULL',
        'ALTER TABLE usuarios ADD COLUMN ver_credenciales {BOOL} NOT NULL DEFAULT 0',
    ],

    // Etiquetas para ordenar la cartera (vienen de la planilla histórica del estudio)
    '2026_09_23_categorias_cliente' => [
        'ALTER TABLE clientes ADD COLUMN categoria VARCHAR(80) NULL',
        'ALTER TABLE clientes ADD COLUMN situacion VARCHAR(60) NULL',
        'CREATE INDEX idx_clientes_categoria ON clientes (categoria)',
    ],

    // Planes de cobro: cada cliente puede tener varios cobros recurrentes
    // (mensual, trimestral, semestral o anual en un mes dado, p. ej. la renta en abril).
    '2026_09_24_planes_de_cobro' => [
        'CREATE TABLE cobros (
            id             {ID},
            cliente_id     {INT} NOT NULL,
            empresa_id     {INT} NULL,
            concepto       VARCHAR(200) NOT NULL,
            monto          DECIMAL(14,4) NOT NULL DEFAULT 0,
            moneda         VARCHAR(3)  NOT NULL DEFAULT \'UF\',
            documento_tipo VARCHAR(20) NOT NULL DEFAULT \'factura_afecta\',
            periodicidad   VARCHAR(12) NOT NULL DEFAULT \'mensual\',
            mes_inicio     {INT} NOT NULL DEFAULT 1,
            activo         {BOOL} NOT NULL DEFAULT 1,
            notas          TEXT NULL,
            creado_en      DATETIME NOT NULL,
            actualizado_en DATETIME NOT NULL,
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL
        {FIN}',
        'CREATE INDEX idx_cobros_cliente ON cobros (cliente_id, activo)',
        'ALTER TABLE facturas ADD COLUMN cobro_id {INT} NULL',
        'CREATE INDEX idx_facturas_cobro ON facturas (cobro_id, periodo)',
        // El honorario mensual que tenía cada cliente pasa a ser su primer plan de cobro
        "INSERT INTO cobros (cliente_id, concepto, monto, moneda, documento_tipo, periodicidad, mes_inicio, activo, creado_en, actualizado_en)
         SELECT id, 'Honorarios asesoría tributaria', honorario_monto, honorario_moneda, documento_tipo, 'mensual', 1, 1,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
         FROM clientes WHERE honorario_monto > 0",
    ],

    // Representante legal de cada empresa y mandato del estudio para facturar a su nombre
    '2026_09_24_representacion_mandato' => [
        'ALTER TABLE empresas ADD COLUMN representante_nombre VARCHAR(150) NULL',
        'ALTER TABLE empresas ADD COLUMN representante_rut VARCHAR(20) NULL',
        'ALTER TABLE empresas ADD COLUMN representamos {BOOL} NOT NULL DEFAULT 0',
        'ALTER TABLE empresas ADD COLUMN mandato_facturacion {BOOL} NOT NULL DEFAULT 0',
        'ALTER TABLE empresas ADD COLUMN mandato_desde DATE NULL',
        'ALTER TABLE empresas ADD COLUMN mandato_hasta DATE NULL',
        'ALTER TABLE empresas ADD COLUMN mandato_notas TEXT NULL',
    ],

    // Enlaces de un solo uso para crear la contraseña (invitación) o recuperarla
    '2026_09_25_enlaces_clave' => [
        'CREATE TABLE enlaces_clave (
            id         {ID},
            usuario_id {INT} NOT NULL,
            tipo       VARCHAR(15) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expira_en  DATETIME NOT NULL,
            usado_en   DATETIME NULL,
            creado_por {INT} NULL,
            ip         VARCHAR(45) NULL,
            creado_en  DATETIME NOT NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
        {FIN}',
        'CREATE INDEX idx_enlaces_token ON enlaces_clave (token_hash)',
    ],

    // Varios ejecutivos por cliente (además del principal, clientes.ejecutivo_id), cada uno con su área
    '2026_09_25_equipo_cliente' => [
        'CREATE TABLE cliente_ejecutivos (
            cliente_id {INT} NOT NULL,
            usuario_id {INT} NOT NULL,
            area       VARCHAR(60) NULL,
            PRIMARY KEY (cliente_id, usuario_id),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        {FIN}',
        'CREATE INDEX idx_cliente_ejecutivos_usuario ON cliente_ejecutivos (usuario_id)',
    ],

    // Contactos como ficha única por persona, vinculada a varios RUT con un rol en cada uno
    '2026_10_02_contactos_por_rut' => [
        'CREATE TABLE contacto_empresas (
            contacto_id {INT} NOT NULL,
            empresa_id  {INT} NOT NULL,
            rol         VARCHAR(60) NULL,
            PRIMARY KEY (contacto_id, empresa_id),
            FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE CASCADE,
            FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
        {FIN}',
        'CREATE INDEX idx_contacto_empresas_empresa ON contacto_empresas (empresa_id)',
        // Cada contacto existente queda vinculado al RUT que tenía, con su cargo como rol
        'INSERT INTO contacto_empresas (contacto_id, empresa_id, rol)
         SELECT id, empresa_id, cargo FROM contactos WHERE empresa_id IS NOT NULL',
    ],

    // Prospectos como embudo de venta: servicio, tipo de tarifa, tareas propias y cliente resultante
    '2026_10_05_prospectos_pipeline' => [
        'ALTER TABLE oportunidades ADD COLUMN servicio VARCHAR(150) NULL',
        'ALTER TABLE oportunidades ADD COLUMN tarifa_tipo VARCHAR(12) NOT NULL DEFAULT \'mensual\'',
        'ALTER TABLE oportunidades ADD COLUMN tarifa_moneda VARCHAR(3) NOT NULL DEFAULT \'UF\'',
        'ALTER TABLE oportunidades ADD COLUMN tarifa_mes {INT} NULL',
        'ALTER TABLE oportunidades ADD COLUMN cliente_id {INT} NULL',
        // El monto anterior era un estimado en pesos
        "UPDATE oportunidades SET tarifa_moneda = 'CLP' WHERE monto > 0",
        'ALTER TABLE tareas ADD COLUMN oportunidad_id {INT} NULL',
        'CREATE INDEX idx_tareas_oportunidad ON tareas (oportunidad_id, estado)',
        // Las tareas que quedaron en el RUT de un prospecto (sin cliente) pasan a ser tareas de ese prospecto
        'UPDATE tareas SET oportunidad_id = (SELECT MIN(o.id) FROM oportunidades o WHERE o.empresa_id = tareas.empresa_id)
         WHERE cliente_id IS NULL AND empresa_id IS NOT NULL',
        // y las gestiones hechas en esas tareas aparecen también en el prospecto
        'UPDATE actividades SET oportunidad_id = (SELECT t.oportunidad_id FROM tareas t WHERE t.id = actividades.tarea_id)
         WHERE oportunidad_id IS NULL AND tarea_id IS NOT NULL',
    ],

    // Bandeja de correos: cola de propuestas (tareas, reuniones, gestiones, prospectos) detectadas en los
    // correos del equipo, que una persona aprueba o rechaza antes de que pasen a los datos reales
    '2026_10_06_bandeja_correos' => [
        'CREATE TABLE bandeja_correos (
            id                {ID},
            correo_hash       CHAR(40)     NOT NULL,
            correo_ref        VARCHAR(250) NULL,
            ordinal           {INT} NOT NULL DEFAULT 1,
            tipo              VARCHAR(12)  NOT NULL,
            titulo            VARCHAR(200) NOT NULL,
            detalle           TEXT NULL,
            fecha_evento      DATETIME NULL,
            cliente_texto     VARCHAR(150) NULL,
            contacto_nombre   VARCHAR(100) NULL,
            contacto_email    VARCHAR(150) NULL,
            responsable_email VARCHAR(150) NULL,
            confianza         VARCHAR(8)   NOT NULL DEFAULT \'media\',
            remitente         VARCHAR(200) NULL,
            asunto            VARCHAR(250) NULL,
            recibido_en       DATETIME NULL,
            buzones           VARCHAR(250) NULL,
            estado            VARCHAR(10)  NOT NULL DEFAULT \'pendiente\',
            creado_tipo       VARCHAR(12)  NULL,
            creado_id         {INT} NULL,
            revisado_por      {INT} NULL,
            revisado_en       DATETIME NULL,
            creado_en         DATETIME NOT NULL,
            FOREIGN KEY (revisado_por) REFERENCES usuarios(id) ON DELETE SET NULL
        {FIN}',
        'CREATE UNIQUE INDEX uq_bandeja_propuesta ON bandeja_correos (correo_hash, tipo, ordinal)',
        'CREATE INDEX idx_bandeja_estado ON bandeja_correos (estado, creado_en)',
    ],

    // Lectura de correos por IMAP en el servidor: buzones (clave cifrada), extractos por clasificar
    // (se borran a los 14 días) y ajustes sueltos como el hash del token de la rutina de Claude
    '2026_10_07_correos_imap' => [
        'CREATE TABLE ajustes (
            clave          VARCHAR(60) NOT NULL PRIMARY KEY,
            valor          TEXT NULL,
            actualizado_en DATETIME NOT NULL
        {FIN}',
        'CREATE TABLE correo_buzones (
            id             {ID},
            usuario        VARCHAR(150) NOT NULL,
            clave_cifrada  TEXT NOT NULL,
            activo         {BOOL} NOT NULL DEFAULT 1,
            uidvalidity    {INT} NOT NULL DEFAULT 0,
            ultimo_uid     {INT} NOT NULL DEFAULT 0,
            leido_en       DATETIME NULL,
            error          TEXT NULL,
            error_en       DATETIME NULL,
            creado_en      DATETIME NOT NULL
        {FIN}',
        'CREATE UNIQUE INDEX uq_correo_buzones_usuario ON correo_buzones (usuario)',
        'CREATE TABLE correos_entrantes (
            id              {ID},
            correo_hash     CHAR(40)     NOT NULL,
            message_id      VARCHAR(250) NOT NULL,
            buzones         VARCHAR(250) NULL,
            uid             {INT} NULL,
            remitente       VARCHAR(200) NULL,
            remitente_email VARCHAR(150) NULL,
            para            TEXT NULL,
            asunto          VARCHAR(250) NULL,
            recibido_en     DATETIME NULL,
            cuerpo          TEXT NULL,
            automatico      {BOOL} NOT NULL DEFAULT 0,
            estado          VARCHAR(10)  NOT NULL DEFAULT \'nuevo\',
            propuestas      {INT} NOT NULL DEFAULT 0,
            clasificado_en  DATETIME NULL,
            creado_en       DATETIME NOT NULL
        {FIN}',
        'CREATE UNIQUE INDEX uq_correos_entrantes_hash ON correos_entrantes (correo_hash)',
        'CREATE INDEX idx_correos_entrantes_estado ON correos_entrantes (estado, recibido_en)',
    ],

    // La Bandeja reconoce lo que ya existe en el CRM: una propuesta puede apuntar a una tarea, gestión
    // o prospecto existente para complementarla, actualizarla u omitirse (en vez de crear un duplicado)
    '2026_10_09_bandeja_vinculos' => [
        'ALTER TABLE bandeja_correos ADD COLUMN accion VARCHAR(12) NOT NULL DEFAULT \'nueva\'',
        'ALTER TABLE bandeja_correos ADD COLUMN destino_tipo VARCHAR(12) NULL',
        'ALTER TABLE bandeja_correos ADD COLUMN destino_id {INT} NULL',
        'ALTER TABLE bandeja_correos ADD COLUMN motivo VARCHAR(300) NULL',
        'ALTER TABLE bandeja_correos ADD COLUMN cambios TEXT NULL',
    ],
];

function migracion_sql(string $sql): string
{
    $mysql = db_driver() !== 'sqlite';
    return strtr($sql, [
        '{ID}'   => $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
        '{INT}'  => $mysql ? 'INT UNSIGNED' : 'INTEGER',
        '{BOOL}' => $mysql ? 'TINYINT(1)' : 'INTEGER',
        '{FIN}'  => $mysql ? ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ')',
    ]);
}

/** Aplica las migraciones pendientes. Devuelve los identificadores aplicados. */
function migraciones_aplicar(): array
{
    db()->exec('CREATE TABLE IF NOT EXISTS migraciones (id VARCHAR(100) NOT NULL PRIMARY KEY, aplicada_en DATETIME NOT NULL)');
    $hechas = array_column(q_todos('SELECT id FROM migraciones'), 'id');
    $aplicadas = [];
    foreach (MIGRACIONES as $idMigracion => $sentencias) {
        if (in_array($idMigracion, $hechas, true)) {
            continue;
        }
        foreach ($sentencias as $sql) {
            try {
                db()->exec(migracion_sql($sql));
            } catch (PDOException $ex) {
                // MySQL no revierte cambios de estructura si una migración falla a medias:
                // al reintentarla, se omiten las tablas, columnas e índices que ya existen.
                if (!preg_match('/already exists|duplicate column|duplicate key name/i', $ex->getMessage())) {
                    throw $ex;
                }
            }
        }
        q('INSERT INTO migraciones (id, aplicada_en) VALUES (?, ?)', [$idMigracion, ahora()]);
        $aplicadas[] = $idMigracion;
    }
    return $aplicadas;
}

/** Se llama en cada carga: solo actúa si el CRM ya está instalado y hay migraciones nuevas. */
function migraciones_automaticas(): void
{
    try {
        // Se compara por identificador: la tabla también registra herramientas de datos (p. ej. reorganizaciones).
        $hechas = array_column(q_todos('SELECT id FROM migraciones'), 'id');
        if (!array_diff(array_keys(MIGRACIONES), $hechas)) {
            return;
        }
    } catch (PDOException $ex) {
        // La tabla migraciones aún no existe: aplicar solo si el CRM está instalado.
        try {
            q_valor('SELECT COUNT(*) FROM usuarios');
        } catch (PDOException $ex2) {
            return;
        }
    }
    try {
        migraciones_aplicar();
    } catch (PDOException $ex) {
        error_log('CRM - error al aplicar migraciones: ' . $ex->getMessage());
        http_response_code(500);
        exit('No se pudo actualizar la base de datos. Revise el registro de errores del servidor.');
    }
}
