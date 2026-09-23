<?php
/*
 * Copie este archivo como config.php y ajuste los valores de su hosting.
 * config.php NO se sube al repositorio (ver .gitignore).
 */
return [
    'app_nombre' => 'CRM ProAction',

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
    'zona_horaria' => 'America/Mexico_City',

    'moneda' => '$',

    // Restricción de intranet: lista de IPs o rangos CIDR con acceso.
    // Vacío = sin restricción (se recomienda restringir).
    // Ejemplo: ['192.168.1.0/24', '10.0.0.0/8', '200.1.2.3']
    'ips_permitidas' => [],

    // Minutos de inactividad antes de cerrar la sesión.
    'sesion_minutos' => 120,
];
