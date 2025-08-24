<?php
/* ============================================================================
 * conexion.php — Conexión MySQL universal (Railway/Render/local XAMPP)
 * - Soporta: MYSQL_URL o variables separadas (MYSQLHOST, MYSQLPORT, etc.)
 * - Fallback local: localhost:3306 / root / "" / multi_gimnasio
 * - Charset: utf8mb4  |  Timeout y mensajes claros  |  SSL opcional
 * ========================================================================== */

/* ===== Debug (activar con APP_DEBUG=1 en variables de entorno) ===== */
$APP_DEBUG = getenv('APP_DEBUG') === '1';
if ($APP_DEBUG) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', 0);
  error_reporting(0);
}

/* ===== Helpers ===== */
function envv(string $k, $default = null) {
  $v = getenv($k);
  return ($v !== false && $v !== '') ? $v : $default;
}

function parse_mysql_url(?string $url): array {
  if (!$url) return [];
  $p = @parse_url($url);
  if (!$p || !isset($p['scheme']) || stripos($p['scheme'], 'mysql') === false) return [];
  return [
    'host' => $p['host'] ?? null,
    'port' => isset($p['port']) ? (int)$p['port'] : null,
    'user' => $p['user'] ?? null,
    'pass' => $p['pass'] ?? null,
    'db'   => isset($p['path']) ? ltrim($p['path'], '/') : null,
  ];
}

/* ===== 1) Preferir MYSQL_URL si existe ===== */
$cfg = parse_mysql_url(envv('MYSQL_URL'));

/* ===== 2) Si no hay MYSQL_URL, usar variables separadas ===== */
if (!$cfg) {
  $cfg = [
    'host' => envv('MYSQLHOST'),
    'port' => envv('MYSQLPORT') ? (int)envv('MYSQLPORT') : null,
    'user' => envv('MYSQLUSER'),
    'pass' => envv('MYSQLPASSWORD'),
    'db'   => envv('MYSQLDATABASE'),
  ];
}

/* ===== 3) Fallback local (XAMPP) si sigue incompleto ===== */
$needs_fallback = empty($cfg['host']) || empty($cfg['user']) || empty($cfg['db']);
if ($needs_fallback) {
  $cfg = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'user' => 'root',
    'pass' => '',
    'db'   => 'multi_gimnasio',
  ];
}

/* ===== SSL y opciones =====
 * - Forzar SSL si MYSQLSSL=1 o si el host parece proxy de Railway.
 */
$host_lc = strtolower((string)$cfg['host']);
$force_ssl = envv('MYSQLSSL') === '1'
          || str_ends_with($host_lc, '.proxy.rlwy.net')
          || str_ends_with($host_lc, '.railway.internal')
          || str_contains($host_lc, 'proxy.rlwy.net');

$timeout_seconds = (int)(envv('MYSQL_TIMEOUT', 10));

/* ===== Validaciones mínimas (sin exponer password) ===== */
$missing = [];
if (empty($cfg['host'])) $missing[] = 'MYSQLHOST/host';
if (empty($cfg['user'])) $missing[] = 'MYSQLUSER/user';
if (empty($cfg['db']))   $missing[] = 'MYSQLDATABASE/db';
if (!isset($cfg['port']) || !$cfg['port']) $cfg['port'] = 3306;

/* ===== Crear conexión ===== */
mysqli_report(MYSQLI_REPORT_OFF);
$conexion = mysqli_init();

if (!$conexion) {
  die('❌ No se pudo inicializar MySQLi.');
}

/* Timeouts */
@mysqli_options($conexion, MYSQLI_OPT_CONNECT_TIMEOUT, $timeout_seconds);
if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
  @mysqli_options($conexion, MYSQLI_OPT_READ_TIMEOUT, $timeout_seconds);
}

/* SSL (certs nulos = usar trust del sistema). DONT_VERIFY para entornos sin CA. */
$client_flags = 0;
if ($force_ssl) {
  @mysqli_ssl_set($conexion, null, null, null, null, null);
  if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
    $client_flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
  } elseif (defined('MYSQLI_CLIENT_SSL')) {
    $client_flags |= MYSQLI_CLIENT_SSL;
  }
}

/* Conexión */
$ok = @mysqli_real_connect(
  $conexion,
  $cfg['host'],
  $cfg['user'],
  $cfg['pass'],
  $cfg['db'],
  (int)$cfg['port'],
  null,
  $client_flags
);

if (!$ok) {
  $ssl_txt = $force_ssl ? 'sí' : 'no';
  $msg = '❌ No se pudo conectar a MySQL. '
       . $cfg['host'] . ':' . $cfg['port'] . ' (SSL: ' . $ssl_txt . ')';

  if (!empty($missing)) {
    $msg .= ' | Config DB incompleta. Falta(n): ' . implode(', ', $missing);
  }

  if ($APP_DEBUG) {
    $msg .= ' | MySQLi: ' . mysqli_connect_error();
  }

  die($msg);
}

/* Charset y collation */
if (!@mysqli_set_charset($conexion, 'utf8mb4')) {
  // No cortar ejecución; seguir con el default del servidor
}

/* ===== Exponer $conexion listo ===== */
return $conexion;

/* ===== Notas de uso:
 * 1) En Railway podés usar UNO de estos esquemas:
 *    a) MYSQL_URL = mysql://user:pass@host:puerto/railway
 *    b) Variables separadas:
 *       - MYSQLHOST (p.ej. metro.proxy.rlwy.net)
 *       - MYSQLPORT (p.ej. 28858)
 *       - MYSQLUSER (p.ej. root)
 *       - MYSQLPASSWORD
 *       - MYSQLDATABASE (p.ej. railway)
 *
 * 2) Opcionales:
 *    - APP_DEBUG=1 para ver errores detallados
 *    - MYSQLSSL=1 para forzar SSL
 *    - MYSQL_TIMEOUT=10 (segundos)
 *
 * 3) Local (XAMPP): si no hay envs, usa 127.0.0.1:3306 | root | "" | multi_gimnasio
 * ========================================================================== */
