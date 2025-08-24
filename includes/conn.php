<?php
if (session_status() === PHP_SESSION_NONE) session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function parse_mysql_url($url){
  $out = ['host'=>null,'port'=>3306,'user'=>null,'pass'=>null,'db'=>null];
  if (!$url) return $out;
  $p = parse_url($url);
  if (!$p) return $out;
  $out['host'] = $p['host'] ?? null;
  $out['port'] = isset($p['port']) ? (int)$p['port'] : 3306;
  $out['user'] = $p['user'] ?? null;
  $out['pass'] = $p['pass'] ?? null;
  $out['db']   = isset($p['path']) ? ltrim($p['path'], '/') : null;
  return $out;
}

// 1) Preferir MYSQL_URL (Railway)
$cfg = parse_mysql_url(getenv('MYSQL_URL') ?: '');

// 2) Si no hay MYSQL_URL, usar variables sueltas Railway
if (!$cfg['host']) {
  $h = getenv('MYSQLHOST');
  $po = getenv('MYSQLPORT');
  $db = getenv('MYSQLDATABASE');
  $us = getenv('MYSQLUSER');
  $pw = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD');
  if ($h && $db && $us) {
    $cfg = [
      'host' => $h,
      'port' => $po ? (int)$po : 3306,
      'db'   => $db,
      'user' => $us,
      'pass' => $pw,
    ];
  }
}

if (!$cfg['host'] || !$cfg['db'] || !$cfg['user']) {
  http_response_code(500);
  die('❌ Config DB incompleta. Definí MYSQL_URL o MYSQLHOST/MYSQLPORT/MYSQLDATABASE/MYSQLUSER/MYSQLPASSWORD.');
}

try {
  $conexion = new mysqli();
  $conexion->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
  $conexion->real_connect($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], $cfg['port']);
  @$conexion->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
  http_response_code(500);
  die('❌ Error MySQL: '.$e->getMessage().' (host='.$cfg['host'].':'.$cfg['port'].')');
}
