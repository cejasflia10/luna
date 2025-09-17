<?php
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
if (session_status()===PHP_SESSION_NONE) session_start();

/* ========= Resolver $root robusto ========= */
$root = __DIR__;
for ($i=0; $i<6; $i++) {
  if (file_exists($root.'/includes/conn.php')) break;
  $root = dirname($root);
}
$has_conn = file_exists($root.'/includes/conn.php');
if ($has_conn) { require $root.'/includes/conn.php'; }
@require $root.'/includes/helpers.php';

/* ========= Helpers básicos ========= */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ========= BASE / URL helpers (sin duplicar /clientes) ========= */
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$dir    = rtrim(dirname($script), '/\\');                 // /.../public  o  /.../public/clientes
$PUBLIC_BASE = preg_match('~/(clientes)(/|$)~', $dir) ? rtrim(dirname($dir), '/\\') : $dir;
if ($PUBLIC_BASE==='') $PUBLIC_BASE = '/';

if (!function_exists('url_public')) {
  function url_public($path){
    global $PUBLIC_BASE;
    return rtrim($PUBLIC_BASE,'/').'/'.ltrim((string)$path,'/');
  }
}
if (!function_exists('urlc')) { // linkear a la tienda
  function urlc($path){ return url_public('clientes/'.ltrim((string)$path,'/')); }
}

/* ========= DB ok? y utilidades ========= */
$db_ok = $has_conn && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

function db_cols($table){
  global $conexion; $out=[];
  if ($rs=@$conexion->query("SHOW COLUMNS FROM `$table`")) while($r=$rs->fetch_assoc()) $out[$r['Field']]=$r;
  return $out;
}
function hascol($table,$col){
  global $conexion;
  $rs=@$conexion->query("SHOW COLUMNS FROM `$table` LIKE '". $conexion->real_escape_string($col) ."'");
  return ($rs && $rs->num_rows>0);
}
function coltype($table,$col){
  $c=db_cols($table); return strtolower($c[$col]['Type'] ?? '');
}

/* ========= Compatibilidad SETTINGS (acepta 'key' o 'name') ========= */
function settings_ensure_dual(){
  global $conexion, $db_ok; if(!$db_ok) return false;

  // Si la tabla no existe, crear con ambas columnas
  $rs = @$conexion->query("SHOW TABLES LIKE 'settings'");
  if (!$rs || $rs->num_rows===0) {
    $sql = "CREATE TABLE `settings` (
      `key`  varchar(64) NOT NULL,
      `name` varchar(64) DEFAULT NULL,
      `value` text NULL,
      PRIMARY KEY (`key`),
      UNIQUE KEY `uniq_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    return !!@$conexion->query($sql);
  }

  // Existe: asegurar columna name y el índice único
  $cols = db_cols('settings');

  if (!isset($cols['name'])) {
    if (!@$conexion->query("ALTER TABLE `settings` ADD COLUMN `name` varchar(64) DEFAULT NULL")) {
      // si falla por permisos o lo que sea, seguimos igual
    } else {
      $cols['name'] = ['Field'=>'name'];
    }
  }
  if (isset($cols['name'])) {
    // intentar crear índice único si no existe
    @$conexion->query("ALTER TABLE `settings` ADD UNIQUE KEY `uniq_name` (`name`)");
  }
  return true;
}

function settings_get_compat($k){
  global $conexion, $db_ok; if(!$db_ok) return null;
  settings_ensure_dual();
  $k2 = $conexion->real_escape_string($k);
  // Busca por key o por name (prioridad key)
  $sql = "SELECT `value` FROM `settings`
          WHERE `key`='$k2' OR `name`='$k2'
          ORDER BY CASE WHEN `key`='$k2' THEN 1 ELSE 0 END DESC
          LIMIT 1";
  if ($rs=@$conexion->query($sql)) {
    if ($rs->num_rows>0) { $r=$rs->fetch_row(); return (string)$r[0]; }
  }
  return null;
}

function settings_upsert_compat($k,$v,&$err=null){
  global $conexion, $db_ok; if(!$db_ok){ $err='sin DB'; return false; }
  settings_ensure_dual();
  $sql = "INSERT INTO `settings` (`key`,`name`,`value`)
          VALUES (?,?,?)
          ON DUPLICATE KEY UPDATE
            `value`=VALUES(`value`),
            `key`=VALUES(`key`),
            `name`=VALUES(`name`)";
  $st = $conexion->prepare($sql);
  if (!$st){ $err='prepare UPSERT: '.$conexion->error; return false; }
  $st->bind_param('sss', $k, $k, $v);
  if (!$st->execute()){ $err='execute UPSERT: '.$st->error; $st->close(); return false; }
  $st->close(); return true;
}

/* ========= Banco: tabla simple bank_info (fila única id=1) ========= */
function bank_table_ensure(){
  global $conexion, $db_ok; if(!$db_ok) return false;
  $sql = "CREATE TABLE IF NOT EXISTS `bank_info` (
    `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    `holder`    VARCHAR(120) NOT NULL DEFAULT '',
    `bank_name` VARCHAR(120) NOT NULL DEFAULT '',
    `alias`     VARCHAR(120) NOT NULL DEFAULT '',
    `cbu`       VARCHAR(40)  NOT NULL DEFAULT '',
    `updated_at` TIMESTAMP NULL DEFAULT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  if (!@$conexion->query($sql)) return false;
  // asegurar fila única id=1
  @$conexion->query("INSERT IGNORE INTO `bank_info` (`id`) VALUES (1)");
  return true;
}
function bank_get(){
  global $conexion, $db_ok; if(!$db_ok) return ['holder'=>'','bank_name'=>'','alias'=>'','cbu'=>''];
  bank_table_ensure();
  $rs = @$conexion->query("SELECT holder,bank_name,alias,cbu FROM `bank_info` WHERE id=1");
  if ($rs && $row=$rs->fetch_assoc()) {
    return ['holder'=>$row['holder']??'','bank_name'=>$row['bank_name']??'','alias'=>$row['alias']??'','cbu'=>$row['cbu']??''];
  }
  return ['holder'=>'','bank_name'=>'','alias'=>'','cbu'=>''];
}
function bank_save($holder,$bank,$alias,$cbu,&$err=null){
  global $conexion, $db_ok; if(!$db_ok){ $err='No hay conexión a la base de datos.'; return false; }
  if (!bank_table_ensure()){ $err='No se pudo preparar la tabla bank_info.'; return false; }
  $sql="UPDATE `bank_info` SET `holder`=?,`bank_name`=?,`alias`=?,`cbu`=?,`updated_at`=NOW() WHERE id=1";
  $st=$conexion->prepare($sql);
  if(!$st){ $err='prepare: '.$conexion->error; return false; }
  $st->bind_param('ssss',$holder,$bank,$alias,$cbu);
  if(!$st->execute()){ $err='execute: '.$st->error; $st->close(); return false; }
  $st->close(); return true;
}

/* ========= Flash msgs ========= */
if (!isset($_SESSION['flash'])) $_SESSION['flash']=[];
function flash_set($k,$v){ $_SESSION['flash'][$k]=$v; }
function flash_get($k){ $v=$_SESSION['flash'][$k]??''; unset($_SESSION['flash'][$k]); return $v; }

/* ========= Guardar banco (POST, sin PIN) ========= */
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && ($_POST['action'] ?? '')==='save_bank') {
  $holder = trim($_POST['holder'] ?? '');
  $bank   = trim($_POST['bank_name'] ?? '');
  $alias  = trim($_POST['alias'] ?? '');
  $cbu    = trim($_POST['cbu'] ?? '');

  if ($alias==='' && $cbu==='') {
    flash_set('err_bank','Cargá al menos ALIAS o CBU.');
  } else {
    $err=''; 
    if (bank_save($holder,$bank,$alias,$cbu,$err)) {
      // Espejar en settings para compatibilidad con otras páginas (carrito, etc)
      $okA = settings_upsert_compat('bank_alias', $alias ?: '', $eA);
      $okB = settings_upsert_compat('bank_cbu',   $cbu   ?: '', $eB);
      $okC = settings_upsert_compat('bank_holder',$holder?: '', $eC);
      $okD = settings_upsert_compat('bank_name',  $bank  ?: '', $eD);
      if (!$okA || !$okB || !$okC || !$okD) {
        $det = trim(($eA??'').' '.($eB??'').' '.($eC??'').' '.($eD??''));
        flash_set('ok_bank','Datos bancarios guardados (con compatibilidad parcial).');
        if ($det) flash_set('err_bank','Nota compatibilidad: '.$det);
      } else {
        flash_set('ok_bank','Datos bancarios guardados.');
      }
    } else {
      flash_set('err_bank','No se pudo guardar ('.$err.').');
    }
  }
  header('Location: '.( $_SERVER['PHP_SELF'] ?? 'index.php' ).'#conf-banco'); exit;
}

/* ========= Leer banco actual ========= */
$BANK = bank_get();
$ok_bank  = flash_get('ok_bank');
$err_bank = flash_get('err_bank');

/* ========= Productos (para Novedades) ========= */
$has_products=$has_variants=$has_categories_table=false;
$has_image_url=$has_created_at=$has_category_id=$has_variant_price=false;
$sql_err=''; $prods=null;

if ($db_ok) {
  $t1=@$conexion->query("SHOW TABLES LIKE 'products'");         $has_products=($t1 && $t1->num_rows>0);
  $t2=@$conexion->query("SHOW TABLES LIKE 'product_variants'"); $has_variants=($t2 && $t2->num_rows>0);
  $t3=@$conexion->query("SHOW TABLES LIKE 'categories'");       $has_categories_table=($t3 && $t3->num_rows>0);

  if ($has_products) {
    $has_image_url   = hascol('products','image_url');
    $has_created_at  = hascol('products','created_at');
    $has_category_id = hascol('products','category_id');
  }
  if ($has_variants) { $has_variant_price = hascol('product_variants','price'); }

  if ($has_products) {
    $select = "p.id,p.name"
            .($has_image_url ? ",p.image_url" : "")
            .",".(($has_categories_table && $has_category_id) ? "(SELECT name FROM categories c WHERE c.id=p.category_id)" : "NULL")." AS category_name"
            .",".(($has_variants && $has_variant_price) ? "(SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id=p.id)" : "0")." AS min_price";

    $order = $has_created_at ? "p.created_at DESC" : "p.id DESC";
    $sql = "SELECT $select FROM products p WHERE p.active=1 ORDER BY $order LIMIT 12";
    $prods = @$conexion->query($sql);
    if ($prods===false) $sql_err=$conexion->error;
  }
}

/* ========= Contadores rápidos (pill en HERO) ========= */
$newCount = 0; $envioCount = 0; $retiroCount = 0; $has_sales=false;
if ($db_ok) {
  // Ventas
  $rs = @$conexion->query("SHOW TABLES LIKE 'sales'");
  $has_sales = ($rs && $rs->num_rows>0);
  if ($has_sales) {
    $has_status  = hascol('sales','status');
    $has_created = hascol('sales','created_at');
    $has_shipmeth= hascol('sales','shipping_method');

    if ($has_status) {
      if ($rs=@$conexion->query("SELECT COUNT(*) c FROM sales WHERE LOWER(status) IN ('new','pendiente','pending','reservado','hold','unpaid','sin_pago')")) {
        $row=$rs->fetch_assoc(); $newCount += (int)($row['c'] ?? 0);
      }
      if ($has_shipmeth) {
        if ($rs=@$conexion->query("SELECT COUNT(*) c FROM sales WHERE LOWER(status) IN ('new','pendiente','pending','reservado','hold','unpaid','sin_pago') AND shipping_method='envio'"))  { $row=$rs->fetch_assoc(); $envioCount  = (int)($row['c'] ?? 0); }
        if ($rs=@$conexion->query("SELECT COUNT(*) c FROM sales WHERE LOWER(status) IN ('new','pendiente','pending','reservado','hold','unpaid','sin_pago') AND shipping_method='retiro'")) { $row=$rs->fetch_assoc(); $retiroCount = (int)($row['c'] ?? 0); }
      }
    } elseif ($has_created) {
      if ($rs=@$conexion->query("SELECT COUNT(*) c FROM sales WHERE created_at >= NOW() - INTERVAL 2 DAY")) {
        $row=$rs->fetch_assoc(); $newCount += (int)($row['c'] ?? 0);
      }
      if ($has_shipmeth) {
        if ($rs=@$conexion->query("SELECT COUNT(*) c FROM sales WHERE shipping_method='envio' AND created_at >= NOW() - INTERVAL 2 DAY"))  { $row=$rs->fetch_assoc(); $envioCount  = (int)($row['c'] ?? 0); }
        if ($rs=@$conexion->query("SELECT COUNT(*) c FROM sales WHERE shipping_method='retiro' AND created_at >= NOW() - INTERVAL 2 DAY")) { $row=$rs->fetch_assoc(); $retiroCount = (int)($row['c'] ?? 0); }
      }
    }
  }

  // Reservas (si existiera otra tabla)
  $rs = @$conexion->query("SHOW TABLES LIKE 'reservations'");
  $has_res = ($rs && $rs->num_rows>0);
  if ($has_res) {
    $has_rstatus  = hascol('reservations','status');
    $has_rcreated = hascol('reservations','created_at');
    if ($has_rstatus) {
      if ($rs=@$conexion->query("SELECT COUNT(*) c FROM reservations WHERE LOWER(status) IN ('new','pendiente','pending')")) {
        $row=$rs->fetch_assoc(); $newCount += (int)($row['c'] ?? 0);
      }
    } elseif ($has_rcreated) {
      if ($rs=@$conexion->query("SELECT COUNT(*) c FROM reservations WHERE created_at >= NOW() - INTERVAL 2 DAY")) {
        $row=$rs->fetch_assoc(); $newCount += (int)($row['c'] ?? 0);
      }
    }
  }
}

/* ========= AVISO con lista de compras online sin confirmar ========= */
$pending_rows = []; $pending_count = 0;
if ($db_ok && $has_sales) {
  $sales_cols = db_cols('sales');
  $has_origin   = isset($sales_cols['origin']) || isset($sales_cols['origen']);
  $has_status   = isset($sales_cols['status']) || isset($sales_cols['estado']);
  $has_createdS = isset($sales_cols['created_at']) || isset($sales_cols['fecha']);
  $has_total    = isset($sales_cols['total']);

  $name_candidates = array_values(array_intersect(['customer_name','buyer_name','name','cliente'], array_keys($sales_cols)));
  $name_expr = $name_candidates ? ('COALESCE('.implode(',', array_map(fn($c)=>"s.`$c`",$name_candidates)).')') : "CONCAT('Cliente #', s.id)";

  $ship_candidates = array_values(array_intersect(['shipping_method','delivery_method','tipo_envio'], array_keys($sales_cols)));
  $ship_expr = $ship_candidates ? ('COALESCE('.implode(',', array_map(fn($c)=>"s.`$c`",$ship_candidates)).')') : "NULL";

  $created_expr = isset($sales_cols['created_at']) ? 's.created_at' : (isset($sales_cols['fecha']) ? 's.fecha' : 'NULL');

  $wheres = [];
  if ($has_origin) {
    $wheres[] = "(LOWER(COALESCE(s.origin,s.origen))='online')";
  }
  if (isset($sales_cols['status'])) {
    $type = coltype('sales','status');
    if (preg_match('~^(tinyint|smallint|int|bigint|decimal|double|float)~',$type)) {
      $wheres[] = "(s.status IS NULL OR s.status=0)";
    } else {
      $wheres[] = "LOWER(s.status) IN ('new','pendiente','pending','reservado','hold','unpaid','sin_pago')";
    }
  } elseif (isset($sales_cols['estado'])) {
    $type = coltype('sales','estado');
    if (preg_match('~^(tinyint|smallint|int|bigint|decimal|double|float)~',$type)) {
      $wheres[] = "(s.estado IS NULL OR s.estado=0)";
    } else {
      $wheres[] = "LOWER(s.estado) IN ('new','pendiente','pending','reservado','hold','unpaid','sin_pago')";
    }
  } else {
    if ($has_createdS) $wheres[] = "($created_expr >= NOW() - INTERVAL 2 DAY)";
  }
  $where = $wheres ? ('WHERE '.implode(' AND ',$wheres)) : '';

  // Conteo
  $sqlC = "SELECT COUNT(*) c FROM sales s $where";
  if ($rc=@$conexion->query($sqlC)) { $pending_count = (int)($rc->fetch_assoc()['c'] ?? 0); }

  // Listado corto
  $sel = "s.id, $name_expr AS buyer, ".($has_total?'s.total':'0')." AS total, $ship_expr AS ship_method";
  if ($has_status)  { $sel .= ", ".(isset($sales_cols['status'])?'s.status':'s.estado')." AS status"; }
  if ($has_createdS){ $sel .= ", $created_expr AS created_at"; }
  $order = $has_createdS ? "$created_expr DESC, s.id DESC" : "s.id DESC";
  $sqlR = "SELECT $sel FROM sales s $where ORDER BY $order LIMIT 5";
  if ($rr=@$conexion->query($sqlR)) while($r=$rr->fetch_assoc()) $pending_rows[]=$r;
}

/* ========= Header ========= */
$header_path = $root.'/includes/header.php';

/* ========= Palabras para rotador (admin) ========= */
$rot_words = [
  'Gestión simple', 'Stock al día', 'Carga en segundos',
  'Reportes claros', 'Más ventas', 'Control total'
];
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
    .pill b{font-weight:700}
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
    .rotator{display:inline-block;position:relative;font-weight:800;letter-spacing:.02em;animation:twinkle 2.4s ease-in-out infinite}
    .rot-out{opacity:.08;filter:blur(1px);transition:opacity .26s linear,filter .26s linear}
    @keyframes twinkle{0%,100%{text-shadow:0 0 0px #fff}50%{text-shadow:0 0 10px rgba(255,255,255,.7),0 0 28px rgba(255,255,255,.25)}}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <!-- AVISO: Compras online sin confirmar -->
  <div class="container">
    <?php if ($pending_count>0): ?>
      <div class="info">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div><b>🔔 Hay <?= (int)$pending_count ?> compra<?= $pending_count>1?'s':'' ?> online para confirmar pago / entrega.</b></div>
          <div><a class="cta" href="<?= url_public('ventas.php') ?>">Ir a Ventas</a></div>
        </div>
        <?php if (!empty($pending_rows)): ?>
          <div style="margin-top:10px;overflow:auto">
            <table class="table">
              <thead><tr>
                <th>#</th><th>Cliente</th><th>Total</th><th>Entrega</th><th>Estado</th><th>Fecha</th>
              </tr></thead>
              <tbody>
                <?php foreach ($pending_rows as $r): ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($r['buyer'] ?? '—') ?></td>
                    <td>$ <?= money((float)($r['total'] ?? 0)) ?></td>
                    <td>
                      <?php
                        $m = strtolower(trim((string)($r['ship_method'] ?? '')));
                        echo $m==='envio' ? '🚚 Envío' : ($m==='retiro' ? '🏬 Retiro' : '—');
                      ?>
                    </td>
                    <td><?= h((string)($r['status'] ?? 'pendiente')) ?></td>
                    <td><?= h(isset($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <div style="opacity:.8;margin-top:6px">Tip: marcá la venta como <i>pagado/completada</i> desde “Ventas”. Si no se confirma dentro de 24h (y tenés reservas activas), el stock se libera solo.</div>
      </div>
    <?php endif; ?>
  </div>

  <!-- HERO -->
  <header class="hero">
    <div class="container">
      <h1 style="margin-bottom:.4rem">
        Moda que inspira.
        <span class="rotator" id="hero-rot" aria-live="polite"></span>
      </h1>
      <p>Cargá productos, controlá stock, vendé online o presencial y mirá tus ganancias en segundos.</p>

      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a class="cta" href="<?= url_public('productos.php') ?>">➕ Cargar producto</a>
        <a class="cta" href="<?= urlc('index.php') ?>">🛍️ Ir a la tienda</a>

        <?php if($newCount>0): ?>
          <?php if($envioCount>0 || $retiroCount>0): ?>
            <span class="pill">
              🔔 <b><?= (int)$newCount ?></b> nuevos —
              <?= $envioCount>0 ? ("🚚 ".$envioCount." env&iacute;o".($envioCount>1?'s':'')) : '' ?>
              <?= ($envioCount>0 && $retiroCount>0) ? " · " : "" ?>
              <?= $retiroCount>0 ? ("🏬 ".$retiroCount." retiro".($retiroCount>1?'s':'')) : '' ?>
            </span>
          <?php else: ?>
            <span class="pill">🔔 <b><?= (int)$newCount ?></b> nuevo<?= $newCount>1?'s':'' ?> (pedidos / reservas)</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="container">
    <?php if($sql_err): ?>
      <div class="card" style="padding:14px"><div class="p">
        <b>❌ Error SQL:</b> <?= h($sql_err) ?>
      </div></div>
    <?php endif; ?>

    <!-- ======= CONFIGURACIÓN ALIAS/CBU/TITULAR/BANCO (SIN PIN) ======= -->
    <section id="conf-banco" class="card" style="margin:14px 0">
      <div class="p">
        <h2 style="margin:.2rem 0 .6rem">💳 Datos bancarios</h2>

        <?php if(!$db_ok): ?>
          <div class="badge" style="border-color:#ef4444;color:#ef4444">⚠ No hay conexión a la base de datos (no se pueden guardar cambios).</div>
        <?php endif; ?>

        <?php if (!empty($ok_bank)): ?>
          <div class="badge" style="border-color:#22c55e;color:#22c55e">✔ <?= h($ok_bank) ?></div>
        <?php endif; ?>
        <?php if (!empty($err_bank)): ?>
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
          <div class="badge" style="opacity:.8">
            Se guarda en <code>bank_info</code> (fila única id=1) y se espeja en <code>settings</code> con claves
            <code>bank_alias</code>, <code>bank_cbu</code>, <code>bank_holder</code>, <code>bank_name</code>.
          </div>
        </form>
      </div>
    </section>

    <h2>Novedades</h2>

    <?php if($db_ok && $has_products && $prods && $prods->num_rows>0): ?>
      <div class="grid">
        <?php while($p=$prods->fetch_assoc()): ?>
          <div class="card">
            <?php
              $img = ($has_image_url && !empty($p['image_url']))
                    ? $p['image_url']
                    : ('https://picsum.photos/seed/'.(int)$p['id'].'/640/480');
            ?>
            <img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" loading="lazy" width="640" height="480">
            <div class="p">
              <h3><?= h($p['name']) ?></h3>
              <?php if(!empty($p['category_name'])): ?>
                <span class="badge"><?= h($p['category_name']) ?></span>
              <?php endif; ?>
              <span class="badge">Desde $ <?= money($p['min_price']) ?></span>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        <b>Sin productos aún.</b> Creá el primero desde <a class="cta" href="<?= url_public('productos.php') ?>">Productos</a>.
      </div></div>
      <div class="grid">
        <?php foreach([101,102,103,104,105,106] as $seed): ?>
          <div class="card">
            <img src="https://picsum.photos/seed/<?= (int)$seed ?>/640/480" alt="Producto demo" loading="lazy" width="640" height="480">
            <div class="p">
              <h3>Producto demo <?= (int)$seed ?></h3>
              <span class="badge">Desde $ 9.999</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <!-- Rotador JS (ligero, sin dependencias) -->
  <script>
  (function(){
    const WORDS = <?= json_encode($rot_words, JSON_UNESCAPED_UNICODE) ?>;
    const el = document.getElementById('hero-rot');
    if (!el || !WORDS.length) return;
    let i = 0;
    const interval = 2200, fade = 260;
    el.textContent = WORDS[0];
    setInterval(()=>{
      el.classList.add('rot-out');
      setTimeout(()=>{
        i = (i + 1) % WORDS.length;
        el.textContent = WORDS[i];
        el.classList.remove('rot-out');
      }, fade);
    }, interval);
  })();
  </script>

</body>
</html>
