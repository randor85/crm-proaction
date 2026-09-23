<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$_SESSION = [];
session_regenerate_id(true);
session_destroy();
redirigir('login.php');
