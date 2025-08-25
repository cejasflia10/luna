<?php
// includes/conn.php — conexión única MySQL (Railway) con fallback a MYSQL_URL
if (!defined('DB_CONNECTED')) {
define('DB_CONNECTED', true);
mysqli_report(MYSQLI_REPORT_OFF);


$url = getenv('MYSQL_URL') ?: getenv('CLEARDB_DATABASE_URL') ?: '';
$cfg = [
'host' => getenv('MYSQLHOST') ?: '127.0.0.1',
'port' => (int)(getenv('MYSQLPORT') ?: 3306),
'db' => getenv('MYSQLDATABASE') ?: 'railway',
'user' => getenv('MYSQLUSER') ?: 'root',
'pass' => getenv('MYSQLPASSWORD') ?: '',
];


if ($url) {
// Parse mysql://user:pass@host:port/db
$p = parse_url($url);
if ($p) {
$cfg['host'] = $p['host'] ?? $cfg['host'];
$cfg['port'] = isset($p['port']) ? (int)$p['port'] : $cfg['port'];
$cfg['user'] = $p['user'] ?? $cfg['user'];
$cfg['pass'] = $p['pass'] ?? $cfg['pass'];
$cfg['db'] = ltrim($p['path'] ?? ('/'.$cfg['db']), '/');
}
}


$conexion = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], $cfg['port']);
if ($conexion && !$conexion->connect_errno) {
@$conexion->set_charset('utf8mb4');
} else {
http_response_code(500);
$msg = '❌ No se pudo conectar a MySQL. ' . ($conexion? $conexion->connect_error : 'Sin objeto mysqli');
die($msg);
}
}