<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

requerir_login();

$modulos = ['dashboard', 'clientes', 'empresas', 'tareas', 'actividades', 'facturas', 'documentos', 'credenciales',
    'contactos', 'oportunidades', 'usuarios', 'perfil', 'sistema', 'buscar'];
$ruta = entrada('r', 'dashboard');

if (!in_array($ruta, $modulos, true)) {
    http_response_code(404);
    layout_inicio('No encontrado');
    echo '<h1>Página no encontrada</h1>';
    layout_fin();
    exit;
}

if (es_post()) {
    csrf_verificar();
}

$accion = entrada('a', 'lista');

require APP_DIR . '/modules/' . $ruta . '.php';
