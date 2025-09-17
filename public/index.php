<?php
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
if (session_status()===PHP_SESSION_NONE) session_start();

/* ========= Resolver $root ========= */
$root = __DIR__;
for ($i=0;$i<6;$i++){ if (file_exists($root.'/includes/conn.php')) break; $root = dirname($root); }
$has_conn = file_exists($root.'/includes/conn.php');
if ($has_conn) require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

/* ========= Helpers ========= */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n,2,',','.'); } }

/* ========= URL helpers ========= */
$script = $_SERVER['SCRIPT_NAME'] ?? ''; $dir = rtrim(dirname($script), '/\\');
$PUBLIC_BASE = preg_match('~/(clientes)(/|$)~',$dir) ? rtrim(dirname($dir),'/\\') : $dir;
if ($PUBLIC_BASE==='') $PUBLIC_BASE='/';
if (!function_exists('url_public')) { function url_public($p){ global $PUBLIC_BASE; return rtrim($PUBLIC_BASE,'/').'/'.ltrim((string)$p,'/'); } }
if (!function_exists('urlc'))       { function urlc($p){ return url_public('clientes/'.ltrim((string)$p,'/')); } }

/* ========= DB ok? ========= */
$db_ok = $has_conn && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

/* ========= Banco: tabla y funciones ========= */
function bank_table_ensure(){
  global $conexion, $db_ok; if(!$db_ok) return false;
  $sql1 = "CREATE TABLE IF NOT EXISTS `bank_info` (
    `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    `holder`    VARCHAR(120) NOT NULL DEFAULT '',
    `bank_name` VARCHAR(120) NOT NULL DEFAULT '',
    `alias`     VARCHAR(120) NOT NULL DEFAULT '',
    `cbu`       VARCHAR(40)  NOT NULL DEFAULT '',
    `updated_at` TIMESTAMP NULL DEFAULT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  if (!@$conexion->query($sql1)) return false;
  // asegura fila única id=1
  @$conexion->query("INSERT IGNORE INTO `bank_info` (`id`) VALUES (1)");
  return true;
}
function bank_get(){
  global $conexion, $db_ok; if(!$db_ok) return ['holder'=>'','bank_name'=>'','alias'=>'','cbu'=>''];
  bank_table_ensure();
  $rs = @$conexion->query("SELECT holder,bank_name,alias,cbu FROM `bank_info` WHERE id=1");
  if ($rs && $row=$rs->fetch_assoc()) return [
    'holder'=>$row['holder']??'','bank_name'=>$row['bank_name']??'','alias'=>$row['alias']??'','cbu'=>$row['cbu']??'',
  ];
  return ['holder'=>'','bank_name'=>'','alias'=>'','cbu'=>''];
}
function bank_save($holder,$bank,$alias,$cbu,&$err=null){
  global $conexion, $db_ok; if(!$db_ok){ $err='No hay conexión a la base de datos.'; return false; }
  if (!bank_table_ensure()){ $err='No se pudo preparar la tabla bank_info.'; return false; }
  $sql = "UPDATE `bank_info` SET `holder`=?,`bank_name`=?,`alias`=?,`cbu`=?,`updated_at`=NOW() WHERE id=1";
  $st = $conexion->prepare($sql);
  if (!$st){ $err = 'prepare: '.$conexion->error; return false; }
  $st->bind_param('ssss',$holder,$bank,$alias,$cbu);
  if (!$st->execute()){ $err='execute: '.$st->error; $st->close(); return false; }
  $st->close(); return true;
}

/* ========= Flash ========= */
if (!isset($_SESSION['flash'])) $_SESSION['flash']=[];
function flash_set($k,$v){ $_SESSION['flash'][$k]=$v; }
function flash_get($k){ $v=$_SESSION['flash'][$k]??''; unset($_SESSION['flash'][$k]); return $v; }

/* ========= Guardar banco (POST) ========= */
if (($_SERVER['REQUEST_METHOD']??'')==='POST' && ($_POST['action']??'')==='save_bank'){
  $holder = trim($_POST['holder']??'');
  $bank   = trim($_POST['bank_name']??'');
  $alias  = trim($_POST['alias']??'');
  $cbu    = trim($_POST['cbu']??'');
  if ($alias==='' && $cbu===''){
    flash_set('err_bank','Cargá al menos ALIAS o CBU.');
  } else {
    $err=''; if (bank_save($holder,$bank,$alias,$cbu,$err)) flash_set('ok_bank','Datos guardados.');
    else flash_set('err_bank','No se pudo guardar ('.$err.').');
  }
  header('Location: '.( $_SERVER['PHP_SELF'] ?? 'index.php' ).'#conf-banco'); exit;
}

/* ========= Leer banco actual ========= */
$BANK = bank_get();
$ok_bank  = flash_get('ok_bank');
$err_bank = flash_get('err_bank');

/* ========= Productos (Novedades) — opcional, intacto ========= */
function hascol($t,$c){ global $conexion; $rs=@$conexion->query("SHOW COLUMNS FROM `$t` LIKE '$c'"); return ($rs && $rs->num_rows>0); }
$has_products=$has_variants=$has_categories_table=false;
$has_image_url=$has_created_at=$has_category_id=$has_variant_price=false;
$sql_err=''; $prods=null;

if ($db_ok){
  $t1=@$conexion->query("SHOW TABLES LIKE 'products'");         $has_products=($t1 && $t1->num_rows>0);
  $t2=@$conexion->query("SHOW TABLES LIKE 'product_variants'"); $has_variants=($t2 && $t2->num_rows>0);
  $t3=@$conexion->query("SHOW TABLES LIKE 'categories'");       $has_categories_table=($t3 && $t3->num_rows>0);
  if ($has_products){
    $has_image_url   = hascol('products','image_url');
    $has_created_at  = hascol('products','created_at');
    $has_category_id = hascol('products','category_id');
  }
  if ($has_variants){ $has_variant_price = hascol('product_variants','price'); }
  if ($has_products){
    $select = "p.id,p.name"
            .($has_image_url?",p.image_url":"")
            .",".(($has_categories_table&&$has_category_id)?"(SELECT name FROM categories c WHERE c.id=p.category_id)":"NULL")." AS category_name"
            .",".(($has_variants&&$has_variant_price)?"(SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id=p.id)":"0")." AS min_price";
    $order = $has_created_at ? "p.created_at DESC" : "p.id DESC";
    $sql   = "SELECT $select FROM products p WHERE p.active=1 ORDER BY $order LIMIT 12";
    $prods = @$conexion->query($sql);
    if ($prods===false) $sql_err=$conexion->error;
  }
}

/* ========= Header ========= */
$header_path = $root.'/includes/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna — Inicio</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>" />
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--ring);border-radius:999px}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .card .p{padding:12px}
    .cta{display:inline-block;padding:.6rem 1rem;border:1px solid var(--ring);border-radius:10px;text-decoration:none}
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .hero{padding:36px 0;text-align:center}
    .info{background:#142625;border:1px solid #1f6f6a;border-radius:12px;padding:12px;margin:14px 0}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--ring);padding:8px;text-align:left;font-size:.95rem}
    .kv{display:grid;grid-template-columns:130px 1fr;gap:8px;max-width:560px}
    .muted{opacity:.8}
  </style>
</head>
<body>

<?php if (file_exists($header_path)) require $header_path; ?>

<div class="container">

  <!-- ======= CONFIGURACIÓN ALIAS/CBU SIMPLE (SIN PIN) ======= -->
  <section id="conf-banco" class="card" style="margin:14px 0">
    <div class="p">
      <h2 style="margin:.2rem 0 .6rem">💳 Datos bancarios</h2>

      <?php if(!$db_ok): ?>
        <div class="badge" style="border-color:#ef4444;color:#ef4444">⚠ No hay conexión a la base de datos (no se pueden guardar cambios).</div>
      <?php endif; ?>

      <?php if($ok_bank): ?>
        <div class="badge" style="border-color:#22c55e;color:#22c55e">✔ <?= h($ok_bank) ?></div>
      <?php endif; ?>
      <?php if($err_bank): ?>
        <div class="badge" style="border-color:#ef4444;color:#ef4444">❌ <?= h($err_bank) ?></div>
      <?php endif; ?>

      <form action="<?= h($_SERVER['PHP_SELF'] ?? 'index.php') ?>#conf-banco" method="post" style="display:grid;gap:10px;max-width:560px;margin-top:8px">
        <input type="hidden" name="action" value="save_bank">
        <label>Titular
          <input class="input" name="holder" value="<?= h($BANK['holder']) ?>" <?= $db_ok?'':'disabled' ?> placeholder="Nombre y Apellido">
        </label>
        <label>Banco
          <input class="input" name="bank_name" value="<?= h($BANK['bank_name']) ?>" <?= $db_ok?'':'disabled' ?> placeholder="Banco">
        </label>
        <label>Alias
          <input class="input" name="alias" value="<?= h($BANK['alias']) ?>" <?= $db_ok?'':'disabled' ?> placeholder="mi.alias.banco">
        </label>
        <label>CBU
          <input class="input" name="cbu" value="<?= h($BANK['cbu']) ?>" <?= $db_ok?'':'disabled' ?> placeholder="0000000000000000000000">
        </label>
        <div>
          <button class="cta" type="submit" <?= $db_ok?'':'disabled' ?>>💾 Guardar</button>
        </div>
        <div class="muted">Se guarda en la tabla <code>bank_info</code> (fila única <code>id=1</code>).</div>
      </form>
    </div>
  </section>

  <h2>Novedades</h2>

  <?php if($db_ok && $has_products && $prods && $prods->num_rows>0): ?>
    <div class="grid">
      <?php while($p=$prods->fetch_assoc()): ?>
        <div class="card">
          <?php $img = (!empty($p['image_url'])) ? $p['image_url'] : ('https://picsum.photos/seed/'.(int)$p['id'].'/640/480'); ?>
          <img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" loading="lazy" width="640" height="480">
          <div class="p">
            <h3><?= h($p['name']) ?></h3>
            <?php if(!empty($p['category_name'])): ?><span class="badge"><?= h($p['category_name']) ?></span><?php endif; ?>
            <span class="badge">Desde $ <?= money($p['min_price']) ?></span>
          </div>
        </div>
      <?php endwhile; ?>
    </div>
  <?php else: ?>
    <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
      <b>Sin productos aún.</b> Creá el primero desde <a class="cta" href="<?= url_public('productos.php') ?>">Productos</a>.
    </div></div>
  <?php endif; ?>

</div>

</body>
</html>
