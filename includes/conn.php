<?php
// includes/conn.php — autodetecta Railway (MYSQL_URL) y sino usa local XAMPP
if (!defined('DB_CONNECTED')) {
  define('DB_CONNECTED', true);
  mysqli_report(MYSQLI_REPORT_OFF);

  // 1) Intentar con MYSQL_URL (Railway) o CLEARDB_DATABASE_URL (si migrás a Heroku)
  $url = getenv('MYSQL_URL') ?: getenv('CLEARDB_DATABASE_URL') ?: '';

  // Config por defecto: LOCAL (XAMPP)
  $cfg = [
    'host' => getenv('MYSQLHOST') ?: '127.0.0.1',
    'port' => (int)(getenv('MYSQLPORT') ?: 3306),
    'db'   => getenv('MYSQLDATABASE') ?: 'luna_shop',
    'user' => getenv('MYSQLUSER') ?: 'root',
    'pass' => getenv('MYSQLPASSWORD') ?: '' // XAMPP: root sin clave
  ];

  // Si existe MYSQL_URL de Railway (formato mysql://user:pass@host:port/db), lo parseamos
  if ($url) {
    $p = parse_url($url);
    if ($p) {
      $cfg['host'] = $p['host'] ?? $cfg['host'];
      $cfg['port'] = isset($p['port']) ? (int)$p['port'] : $cfg['port'];
      $cfg['user'] = $p['user'] ?? $cfg['user'];
      $cfg['pass'] = $p['pass'] ?? $cfg['pass'];
      $cfg['db']   = isset($p['path']) ? ltrim($p['path'], '/') : $cfg['db'];
    }
  }

  // Conectar
  $conexion = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], $cfg['port']);
  if ($conexion && !$conexion->connect_errno) {
    @$conexion->set_charset('utf8mb4');
  } else {
    die('❌ No se pudo conectar a MySQL: ' . ($conexion ? $conexion->connect_error : 'Sin objeto mysqli'));
  }
}
