<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = __DIR__; for ($i=0; $i<6; $i++){ if (file_exists($root.'/includes/conn.php')) break; $root=dirname($root); }
$has_conn = file_exists($root.'/includes/conn.php');
if ($has_conn) { require $root.'/includes/conn.php'; }
@require $root.'/includes/helpers.php';

if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

$script=$_SERVER['SCRIPT_NAME']??''; $dir=rtrim(dirname($script),'/\\');
$PUBLIC_BASE=(preg_match('~/(clientes)(/|$)~',$dir))? rtrim(dirname($dir),'/\\') : $dir;
if (!function_exists('url_public')){ function url_public($p){ global $PUBLIC_BASE; $b=rtrim($PUBLIC_BASE,'/'); return ($b===''?'':$b).'/'.ltrim((string)$p,'/'); } }
if (!function_exists('urlc')){ function urlc($p){ return url_public('clientes/'.ltrim((string)$p,'/')); } }

$db_ok = $has_conn && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
function hascol($t,$c){ global $conexion; $rs=@$conexion->query("SHOW COLUMNS FROM `$t` LIKE '$c'"); return ($rs && $rs->num_rows>0); }

$has_products = $db_ok && ((@$conexion->query("SHOW TABLES LIKE 'products'")?->num_rows ?? 0) > 0);
$has_variants = $db_ok && ((@$conexion->query("SHOW TABLES LIKE 'product_variants'")?->num_rows ?? 0) > 0);
$has_product_price = $has_products && ((@$conexion->query("SHOW COLUMNS FROM products LIKE 'price'")?->num_rows ?? 0) > 0);
$has_variant_price = $has_variants && ((@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'")?->num_rows ?? 0) > 0);
$has_image_url = $has_products && ((@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")?->num_rows ?? 0) > 0);

$price_col = $has_variants && hascol('product_variants','price') ? 'price' : (hascol('product_variants','precio') ? 'precio' : null);
$size_col  = $has_variants && hascol('product_variants','size')  ? 'size'  : (hascol('product_variants','talla') ? 'talla' : null);
$color_col = $has_variants && hascol('product_variants','color') ? 'color' : null;

$cart = $_SESSION['cart'] ?? []; $items=[]; $subtotal=0.0;
foreach ($cart as $row){
  $pid=(int)($row['product_id']??0); $vid=(int)($row['variant_id']??0); $qty=(int)($row['qty']??0);
  if ($pid<=0 || $qty<=0) continue;
  $name='Producto'; $img=''; $price=0.0; $size=''; $color='';

  if ($db_ok && $has_products) {
    if ($rs=@$conexion->query("SELECT name".($has_image_url?",image_url":"")." FROM products WHERE id={$pid} LIMIT 1")) {
      if ($pr=$rs->fetch_assoc()){ $name=$pr['name']; $img=$has_image_url?($pr['image_url']??''):$img; }
    }
  }
  if (!$img) $img='https://picsum.photos/seed/'.$pid.'/640/480';

  if ($db_ok && $has_variants && $vid>0) {
    $cols=['id']; $cols[]=$price_col?"$price_col AS price":"0 AS price"; $cols[]=$size_col?"$size_col AS size":"'' AS size"; $cols[]=$color_col?"$color_col AS color":"'' AS color";
    if ($rv=@$conexion->query("SELECT ".implode(',', $cols)." FROM product_variants WHERE id={$vid} AND product_id={$pid} LIMIT 1")) {
      if ($vv=$rv->fetch_assoc()){ $price=(float)($vv['price']??0); $size=(string)($vv['size']??''); $color=(string)($vv['color']??''); }
    }
  }
  if ($price<=0 && $db_ok && $has_variant_price) {
    if ($rv=@$conexion->query("SELECT MIN(price) AS p FROM product_variants WHERE product_id={$pid}")) if($rr=$rv->fetch_assoc()) $price=(float)($rr['p']??0);
  }
  if ($price<=0 && $db_ok && $has_product_price) {
    if ($rp=@$conexion->query("SELECT price FROM products WHERE id={$pid} LIMIT 1")) if($rr=$rp->fetch_assoc()) $price=(float)($rr['price']??0);
  }

  $line_total = $price * $qty; $subtotal += $line_total;
  $attrs=[]; if ($size!=='') $attrs[]="Talle: ".h($size); if ($color!=='') $attrs[]="Color: ".h($color);
  $items[]=['pid'=>$pid,'vid'=>$vid,'name'=>$name,'img'=>$img,'price'=>$price,'qty'=>$qty,'line_total'=>$line_total,'attrs'=>$attrs];
}

$total=$subtotal;
$header_path = $root.'/includes/header.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pagar — Luna</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>">
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:900px;margin:0 auto;padding:0 14px}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .p{padding:12px}
    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .muted{opacity:.85}
  </style>
</head>
<body>
  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <h2>Pago</h2>
    <?php if (!$items): ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">No hay items.</div></div>
    <?php else: ?>
      <div class="card"><div class="p">
        <?php foreach($items as $it): ?>
          <div class="row" style="align-items:flex-start;margin-bottom:10px">
            <img src="<?= h($it['img']) ?>" alt="" width="80" height="80" style="border-radius:8px;object-fit:cover">
            <div style="flex:1">
              <div><b><?= h($it['name']) ?></b></div>
              <?php if ($it['attrs']): ?><div class="muted"><?= implode(' · ', $it['attrs']) ?></div><?php endif; ?>
              <div class="muted">Precio: $ <?= money($it['price']) ?> — Cant: <?= (int)$it['qty'] ?></div>
            </div>
            <div><b>$ <?= money($it['line_total']) ?></b></div>
          </div>
          <hr style="border:0;border-top:1px solid var(--ring,#2d323d);margin:8px 0">
        <?php endforeach; ?>
        <div style="text-align:right"><b>Total:</b> $ <?= money($total) ?></div>

        <div style="margin-top:10px;text-align:right">
          <a class="cta" href="<?= urlc('checkout.php') ?>">Volver</a>
          <!-- Aquí iría integración de pago real -->
          <a class="cta" href="<?= urlc('index.php') ?>">Finalizar</a>
        </div>
      </div></div>
    <?php endif; ?>
  </div>
</body>
</html>
