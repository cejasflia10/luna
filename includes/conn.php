<?php
/* ===========================================================
 * conn.php — Conexión universal para Render + Railway
 * =========================================================== */

/* Helpers (se definen solo una vez) */
if (!function_exists('envv')) {
  function envv($k, $d = null) {
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $d;
  }
}
if (!function_exists('parse_mysql_url')) {
  function parse_mysql_url($url) {
    if (!$url) return [];
    $p = @parse_url($url);
    if (!$p || !isset($p['host'])) return [];
    return [
      'host' => $p['host'] ?? null,
      'port' => isset($p['port']) ? (int)$p['port'] : 3306,
      'user' => $p['user'] ?? null,
      'pass' => $p['pass'] ?? null,
      'db'   => isset($p['path']) ? ltrim($p['path'], '/') : null
    ];
  }
}

/* 1) Preferir MYSQL_URL (ej: mysql://root:pass@host:port/railway) */
$cfg = parse_mysql_url(envv('MYSQL_URL'));

/* 2) Si no hay URL, usar variables separadas */
if (empty($cfg)) {
  $cfg = [
    'host' => envv('MYSQLHOST'),
    'port' => (int)envv('MYSQLPORT', 3306),
    'user' => envv('MYSQLUSER'),
    'pass' => envv('MYSQLPASSWORD'),
    'db'   => envv('MYSQLDATABASE'),
  ];
}

/* Validar */
if (empty($cfg['host']) || empty($cfg['user']) || empty($cfg['db'])) {
  die("❌ Config DB incompleta. Revisá variables en Render.");
}

/* Conexión */
mysqli_report(MYSQLI_REPORT_OFF);
$conexion = mysqli_init();
@mysqli_options($conexion, MYSQLI_OPT_CONNECT_TIMEOUT, (int)envv('MYSQL_TIMEOUT', 10));

$ok = @mysqli_real_connect(
  $conexion,
  $cfg['host'],
  $cfg['user'],
  $cfg['pass'],
  $cfg['db'],
  (int)$cfg['port']
);

if (!$ok) {
  die("❌ No se pudo conectar a MySQL: " . mysqli_connect_error());
}

/* Charset */
@mysqli_set_charset($conexion, 'utf8mb4');

/* Retornar conexión lista */
return $conexion;
