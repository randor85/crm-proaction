<?php
/*
 * Copie este archivo como config.php y ajuste los valores de su hosting.
 * config.php NO se sube al repositorio (ver .gitignore).
 * Opcional: config.local.php (mismo formato, solo las claves a cambiar) se aplica
 * encima de config.php; útil para rutas del servidor sin tocar la clave de la base.
 */
return [
    'app_nombre' => 'CRM ProAction',
    // Nombre del estudio (se usa cuando "lo representamos nosotros")
    'nombre_estudio' => 'ProAction Consultores',

    'db' => [
        // 'mysql' (recomendado en hosting corporativo) o 'sqlite' (pruebas / sin MySQL)
        'driver'      => 'mysql',
        'host'        => 'localhost',
        'puerto'      => 3306,
        'nombre'      => 'crm',
        'usuario'     => 'crm_usuario',
        'clave'       => 'cambie_esta_clave',
        'sqlite_ruta' => __DIR__ . '/data/crm.sqlite',
    ],

    // Zona horaria: https://www.php.net/manual/es/timezones.php
    'zona_horaria' => 'America/Santiago',

    'moneda' => '$',
    // Decimales en montos (0 para pesos chilenos, 2 para dólares/euros)
    'decimales' => 0,

    // Restricción de intranet: lista de IPs o rangos CIDR con acceso.
    // Vacío = sin restricción (se recomienda restringir).
    // Ejemplo: ['192.168.1.0/24', '10.0.0.0/8', '200.1.2.3']
    'ips_permitidas' => [],

    // Minutos de inactividad antes de cerrar la sesión.
    'sesion_minutos' => 120,

    // Exigir verificación en dos pasos (app autenticadora) para ver credenciales.
    // Con false, cada usuario puede activarla voluntariamente en Mi perfil; para mostrar
    // una clave siempre se pide confirmar la contraseña del CRM.
    'credenciales_requiere_2fa' => false,

    // Gestor de credenciales: archivo con la llave de cifrado (se crea desde Sistema).
    // En el hosting DEBE quedar fuera de public_html, p. ej. '/home/USUARIO/crm_privado/llave.key'.
    // Respáldelo aparte: sin él no se pueden recuperar las claves guardadas.
    'llave_archivo' => __DIR__ . '/data/llave_credenciales.key',

    // Repositorio de documentos: carpeta fuera de public_html, p. ej. '/home/USUARIO/crm_privado/documentos'.
    'documentos_ruta' => __DIR__ . '/data/documentos',
    'documentos_max_mb' => 20,

    // Correos (invitaciones y recuperación de contraseña). 'mail' usa el correo del hosting;
    // 'archivo' los guarda en data/correos.log (pruebas locales).
    'correo_modo' => 'mail',
    'correo_remitente' => 'no-reply@suempresa.com',

    // Retención de boletas de honorarios (%): 2026 = 15,25 · 2027 = 16 · 2028 = 17.
    'retencion_honorarios' => 15.25,
];
