<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status()===PHP_SESSION_NONE) session_start();

/* ===== Rutas ===== */
$root = __DIR__; for ($i=0; $i<6; $i++){ if (file_exists($root.'/includes/conn.php')) break; $root = dirname($root); }
$has_conn = file_exists($root.'/includes/conn.php');
if ($has_conn) { require $root.'/includes/conn.php'; }
@require $root.'/includes/helpers.php';

if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* URL helpers */
$script = $_SERVER['SCRIPT_NAME'] ?? ''; $dir = rtrim(dirname($script), '/\\');
$PUBLIC_BASE = (preg_match('~/(clientes)(/|$)~', $dir)) ? rtrim(dirname($dir), '/\\') : $dir;
if (!function_exists('url_public')) { function url_public($path){ global $PUBLIC_BASE; $b=rtrim($PUBLIC_BASE,'/'); return ($b===''?'':$b).'/'.ltrim((string)$path,'/'); } }
if (!function_exists('urlc')) { function urlc($p){ return url_public('clientes/'.ltrim((string)$p,'/')); } }

/* ===== Esquema ===== */
$db_ok = $has_conn && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
function hascol($t,$c){ global $conexion; $rs=@$conexion->query("SHOW COLUMNS FROM `$t` LIKE '$c'"); return ($rs && $rs->num_rows>0); }

$has_products      = $db_ok && ((@$conexion->query("SHOW TABLES LIKE 'products'")?->num_rows ?? 0) > 0);
$has_variants      = $db_ok && ((@$conexion->query("SHOW TABLES LIKE 'product_variants'")?->num_rows ?? 0) > 0);
$has_image_url     = $has_products && ((@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")?->num_rows ?? 0) > 0);
$has_product_price = $has_products && ((@$conexion->query("SHOW COLUMNS FROM products LIKE 'price'")?->num_rows ?? 0) > 0);
$has_variant_price = $has_variants && ((@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'")?->num_rows ?? 0) > 0);

$price_col = $has_variants && hascol('product_variants','price') ? 'price' : (hascol('product_variants','precio') ? 'precio' : null);
$stock_col = $has_variants && hascol('product_variants','stock') ? 'stock' : (hascol('product_variants','existencia') ? 'existencia' : null);
$size_col  = $has_variants && hascol('product_variants','size')  ? 'size'  : (hascol('product_variants','talla') ? 'talla' : null);
$color_col = $has_variants && hascol('product_variants','color') ? 'color' : null;
$sku_col   = $has_variants && hascol('product_variants','sku')   ? 'sku'   : null;

/* ===== Carrito en sesión ===== */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart =& $_SESSION['cart'];

/* ===== Pago en sesión ===== */
if (!isset($_SESSION['payment']) || !is_array($_SESSION['payment'])) { $_SESSION['payment'] = ['method'=>'efectivo', 'installments'=>1]; }
$payment =& $_SESSION['payment'];

/* ===== Acciones (POST) ===== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = $_POST['action'] ?? '';
  $pid = isset($_POST['product_id']) ? max(0, (int)$_POST['product_id']) : 0;
  $vid = isset($_POST['variant_id']) ? max(0, (int)$_POST['variant_id']) : 0;
  $qty = isset($_POST['qty'])        ? max(0, (int)$_POST['qty'])        : 0;
  $key = $pid.':'.$vid;

  if ($action === 'add' && $pid>0 && $qty>0) {
    if (!isset($cart[$key])) $cart[$key] = ['product_id'=>$pid,'variant_id'=>$vid,'qty'=>0];
    $cart[$key]['qty'] += $qty;
  } elseif ($action === 'update' && isset($cart[$key])) {
    if ($qty<=0) unset($cart[$key]); else $cart[$key]['qty'] = $qty;
  } elseif ($action === 'remove' && isset($cart[$key])) {
    unset($cart[$key]);
  } elseif ($action === 'clear') {
    $cart = [];
  } elseif ($action === 'set_payment') {
    $m = $_POST['method'] ?? 'efectivo';
    $cuotas = isset($_POST['installments']) ? max(1,(int)$_POST['installments']) : 1;
    $payment = ['method'=>$m,'installments'=>$cuotas];
  }
  $_SESSION['cart_count'] = array_sum(array_column($cart, 'qty'));
  header('Location: '.urlc('carrito.php')); exit;
}

/* ===== Calcular items ===== */
$items=[]; $subtotal=0.0;
foreach ($cart as $k=>$row){
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
    if ($sku_col) $cols[]="$sku_col AS sku";
    if ($rv=@$conexion->query("SELECT ".implode(',', $cols)." FROM product_variants WHERE id={$vid} AND product_id={$pid} LIMIT 1")) {
      if ($vv=$rv->fetch_assoc()){ $price=(float)($vv['price']??0); $size=(string)($vv['size']??''); $color=(string)($vv['color']??''); }
    }
  }
  if ($price<=0 && $db_ok && $has_variant_price) {
    if ($rv = @$conexion->query("SELECT MIN(price) AS p FROM product_variants WHERE product_id={$pid}")) if ($rr=$rv->fetch_assoc()) $price=(float)($rr['p']??0);
  }
  if ($price<=0 && $db_ok && $has_product_price) {
    if ($rp = @$conexion->query("SELECT price FROM products WHERE id={$pid} LIMIT 1")) if ($rr=$rp->fetch_assoc()) $price=(float)($rr['price']??0);
  }

  $line_total = $price * $qty; $subtotal += $line_total;

  $attrs = [];
  if ($size!=='')  $attrs[]="Talle: ".h($size);
  if ($color!=='') $attrs[]="Color: ".h($color);
  $attr_text = $attrs ? ('<div class="muted" style="margin-top:2px">'.implode(' · ',$attrs).'</div>') : '';

  $items[] = ['key'=>$k,'pid'=>$pid,'vid'=>$vid,'name'=>$name,'img'=>$img,'price'=>$price,'qty'=>$qty,'line_total'=>$line_total,'attrs_html'=>$attr_text];
}

$cart_count = array_sum(array_column($cart, 'qty'));
$_SESSION['cart_count'] = $cart_count;

/* ===== Totales ficticios (tu config de pago) ===== */
$method = $payment['method'] ?? 'efectivo'; $cuotas = (int)($payment['installments'] ?? 1);
$fee = 0; $discount = 0; // ajusta según método si querés
$total = max(0,$subtotal + $fee - $discount);
$cuota_monto = ($cuotas>1) ? ($total / $cuotas) : 0;

$header_path = $root.'/includes/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Carrito — Luna</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>">
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1000px;margin:0 auto;padding:0 14px}
    .grid{display:grid;grid-template-columns:1fr 340px;gap:14px}
    @media (max-width:900px){ .grid{grid-template-columns:1fr} }
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .p{padding:12px}
    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    .muted{opacity:.85}
    .qty{width:90px}
    .pay-card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:10px;padding:12px}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <nav class="breadcrumb" style="margin:8px 0 2px">
      <a href="<?= urlc('index.php') ?>">Tienda</a> <span>›</span>
      <strong>Carrito</strong>
    </nav>

    <?php if (!$items): ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">Tu carrito está vacío.</div></div>
    <?php else: ?>
      <div class="grid">
        <div class="card">
          <div class="p">
            <?php foreach($items as $it): ?>
              <div class="row" style="align-items:flex-start;margin-bottom:10px">
                <img src="<?= h($it['img']) ?>" alt="" width="100" height="100" style="border-radius:8px;object-fit:cover">
                <div style="flex:1">
                  <div><b><?= h($it['name']) ?></b></div>
                  <?= $it['attrs_html'] ?>
                  <div class="muted">Precio: $ <?= money($it['price']) ?></div>
                  <form action="<?= urlc('carrito.php') ?>" method="post" class="row" style="margin-top:6px">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="product_id" value="<?= (int)$it['pid'] ?>">
                    <input type="hidden" name="variant_id" value="<?= (int)$it['vid'] ?>">
                    <input class="input qty" type="number" name="qty" min="0" value="<?= (int)$it['qty'] ?>">
                    <button class="cta" type="submit">Actualizar</button>
                    <button class="cta" type="submit" name="action" value="remove">Quitar</button>
                  </form>
                </div>
                <div><b>$ <?= money($it['line_total']) ?></b></div>
              </div>
              <hr style="border:0;border-top:1px solid var(--ring,#2d323d);margin:8px 0">
            <?php endforeach; ?>
            <div style="text-align:right"><b>Subtotal:</b> $ <?= money($subtotal) ?></div>
          </div>
        </div>

        <div class="card">
          <div class="p">
            <h3>Pago</h3>
            <form action="<?= urlc('carrito.php') ?>" method="post" class="pay-card">
              <input type="hidden" name="action" value="set_payment">
              <div class="row">
                <label class="badge"><input type="radio" name="method" value="efectivo" <?= ($method==='efectivo'?'checked':'') ?>> Efectivo</label>
                <label class="badge"><input type="radio" name="method" value="debito" <?= ($method==='debito'?'checked':'') ?>> Débito</label>
                <label class="badge"><input type="radio" name="method" value="credito" <?= ($method==='credito'?'checked':'') ?>> Crédito</label>
              </div>
              <div style="margin-top:8px">
                <label>Cuotas:
                  <select name="installments">
                    <?php foreach ([1,3,6,12] as $c): ?>
                      <option value="<?= $c ?>" <?= ($cuotas===$c?'selected':'') ?>><?= $c ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div style="margin-top:8px;text-align:right"><button class="cta" type="submit">Aplicar</button></div>
            </form>

            <h3 style="margin-top:16px">Resumen</h3>
            <div class="pay-card">
              <div style="display:flex;justify-content:space-between;margin-bottom:6px"><span>Subtotal</span><b>$ <?= money($subtotal) ?></b></div>
              <hr style="border:0;border-top:1px solid var(--ring,#2d323d);margin:8px 0">
              <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:1.05rem"><span>Total</span><b>$ <?= money($total) ?></b></div>
              <?php if ($cuota_monto>0): ?><div style="text-align:right;opacity:.9">En <?= (int)$cuotas ?> cuotas de <b>$ <?= money($cuota_monto) ?></b></div><?php endif; ?>
              <div style="margin-top:10px;text-align:right">
                <a class="cta" href="<?= urlc('index.php') ?>">Seguir comprando</a>
                <a class="cta" href="<?= urlc('checkout.php') ?>">Ir a pagar</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
