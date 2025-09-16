<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ========= Resolver $root ========= */
$root = __DIR__;
for ($i=0; $i<6; $i++) { if (file_exists($root.'/includes/conn.php')) break; $root = dirname($root); }
@require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* ========= BD ok + tabla settings ========= */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
if (!$db_ok) { die('Sin conexión a la base de datos.'); }

@$conexion->query("CREATE TABLE IF NOT EXISTS `settings` (
  `key`   varchar(64) NOT NULL PRIMARY KEY,
  `value` text NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function setting_get($key){
  global $conexion;
  $k=$conexion->real_escape_string($key);
  $rs=@$conexion->query("SELECT `value` FROM `settings` WHERE `key`='$k' LIMIT 1");
  if ($rs && $rs->num_rows>0) { $r=$rs->fetch_row(); return (string)$r[0]; }
  return null;
}
function setting_set($key,$val){
  global $conexion;
  $k=$conexion->real_escape_string($key);
  $v=$conexion->real_escape_string($val);
  return !!@$conexion->query("REPLACE INTO `settings` (`key`,`value`) VALUES ('$k','$v')");
}

/* ========= Guardar PIN ========= */
$ok=''; $err='';
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
  $pin  = trim($_POST['new_pin'] ?? '');
  $pin2 = trim($_POST['new_pin_confirm'] ?? '');

  if ($pin==='' || $pin2==='') {
    $err='Completá ambos campos.';
  } elseif ($pin !== $pin2) {
    $err='Los PIN no coinciden.';
  } elseif (strlen($pin) < 4) {
    $err='El PIN debe tener al menos 4 caracteres.';
  } else {
    $hash = password_hash($pin, PASSWORD_BCRYPT);
    if (setting_set('admin_pin_hash', $hash)) {
      $ok='PIN actualizado correctamente.';
    } else {
      $err='No se pudo guardar. Verificá permisos/BD.';
    }
  }
}

/* ========= Estado actual ========= */
$has_hash = setting_get('admin_pin_hash') ? true : false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configurar PIN admin</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <style>
    .container{max-width:640px;margin:24px auto;padding:0 14px}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px}
    .p{padding:14px}
    .input{width:100%}
    .row{display:grid;gap:8px}
    .btn{display:inline-block;padding:.55rem .9rem;border:1px solid var(--ring);border-radius:.6rem;background:transparent;color:inherit;cursor:pointer}
    .ok{color:#22c55e}
    .err{color:#ef4444}
    .note{opacity:.85}
  </style>
</head>
<body>
  <div class="container">
    <div class="card"><div class="p">
      <h1 style="margin:.2rem 0 .6rem">🔒 Configurar PIN de administración</h1>

      <?php if($ok): ?><div class="ok">✔ <?= h($ok) ?></div><?php endif; ?>
      <?php if($err): ?><div class="err">❌ <?= h($err) ?></div><?php endif; ?>

      <p class="note">Este PIN permite guardar configuraciones sensibles (por ejemplo, ALIAS/CBU). Se guarda en <code>settings.admin_pin_hash</code> (hash BCRYPT).</p>

      <form method="post" class="row" style="margin-top:10px">
        <label>Nuevo PIN
          <input class="input" type="password" name="new_pin" required placeholder="Mín. 4 caracteres">
        </label>
        <label>Confirmar PIN
          <input class="input" type="password" name="new_pin_confirm" required>
        </label>
        <div>
          <button class="btn" type="submit">💾 Guardar PIN</button>
        </div>
      </form>

      <div class="note" style="margin-top:10px">
        Estado: <?= $has_hash ? 'PIN configurado ✅' : 'PIN no configurado ⚠' ?>
      </div>
    </div></div>
  </div>
</body>
</html>
