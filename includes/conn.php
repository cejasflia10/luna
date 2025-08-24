<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$config = require __DIR__ . '/../config/env.php';


$conexion = @new mysqli(
$config['DB_HOST'],
$config['DB_USER'],
$config['DB_PASS'],
$config['DB_NAME'],
(int)$config['DB_PORT']
);
if ($conexion->connect_errno) {
http_response_code(500);
die('❌ No se pudo conectar a MySQL: ' . $conexion->connect_error);
}
@$conexion->set_charset('utf8mb4');