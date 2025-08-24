<?php
// includes/conn.php
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * PRIORIDAD DE FUENTES:
 * 1) DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS (Render u otro hosting)
 * 2) MYSQL_PUBLIC_URL (Railway con host público)
 * 3) MYSQL_URL (Railway interno / proxies)
 * 4) MYSQLHOST / MYSQLPORT / MYSQLDATABASE / MYSQLUSER / MYSQLPASSWORD (Railway)
 */
function parse_mysql_url($url){
  $out = ['host'=>null,'port'=>null,'user'=>null,'pass'=>null,'db'=>null];
  if (!$url) return $out;
  $p = parse_url($url);
  if (!$p) return $out;
  $out['host'] = $p['host'] ?? null;
  $out['port'] = isset($p['port']) ? (int)$p['port'] : 3306;
  $out['user'] = $p['user'] ?? null;
  $out['pass'] = $p['pass'] ?? null;
  // path viene con /db
  $out['db']   = isset($p['path']) ? ltrim($p['path'], '/') : null;
  return $out;
}

// 1) Variables DB_* (preferidas para hosting externo)
$cfg = [
  'host' => getenv('DB_HOST') ?: null,
  'port' => getenv('DB_PORT') ? (int)getenv('DB_PORT') : null,
  'db'   => getenv('DB_NAME') ?: null,
  'user' => getenv('DB_USER') ?: null,
  'pass' => getenv('DB_PASS') ?: null,
];

if (!$cfg['host']) {
  // 2) MYSQL_PUBLIC_URL
  $p = parse_mysql_url(getenv('MYSQL_PUBLIC_URL'));
  if ($p['host']) $cfg = $p;
}
if (!$cfg['host']) {
  // 3) MYSQL_URL (Railway interno/proxy)
  $p = parse_mysql_url(getenv('MYSQL_URL'));
  if ($p['host']) $cfg = $p;
}
if (!$cfg['host']) {
  // 4) Variables sueltas estilo Railway
  $h = getenv('MYSQLHOST'); $po = getenv('MYSQLPORT'); $db = getenv('MYSQLDATABASE');
  $us = getenv('MYSQLUSER'); $pw = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD');
  if ($h && $db && $us) $cfg = ['host'=>$h, 'port'=>$po? (int)$po : 3306, 'db'=>$db, 'user'=>$us, 'pass'=>$pw];
}

if (!$cfg['host'] || !$cfg['db'] || !$cfg['user']) {
  http_response_code(500);
  die('❌ Config DB incompleta. Definí DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS o MYSQL_PUBLIC_URL/MYSQL_URL.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conexion = new mysqli();
  // timeouts razonables
  $conexion->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
  $conexion->real_connect($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], $cfg['port'] ?? 3306);
  @$conexion->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
  http_response_code(500);
  die('❌ Error MySQL: '.$e->getMessage().' (host='.$cfg['host'].':'.($cfg['port']??3306).')');
}
