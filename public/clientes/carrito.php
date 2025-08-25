<?php
/* ===== DEBUG: activar errores (apagalo en prod) ===== */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status()===PHP_SESSION_NONE) session_start();

/* ===== Localizar $root (busca includes/conn.php hacia arriba) ===== */
$root = __DIR__;
for ($i=0; $i<6; $i++) {
  if (file_exists($root.'/includes/conn.php')) break;
  $root = dirname($root);
}
$has_conn = file_exists($root.'/includes/conn.php');
if ($has_conn) { require $root.'/includes/conn.php'; }
@require $root.'/includes/helpers.php';

/* ===== Helpers mínimos ===== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ===== BASES WEB (NO redefinir url() del header) =====
   - url_public($p) => /public/...
   - urlc($p)       => /public/clientes/...
*/
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$dir    = rtrim(dirname($script), '/\\'); // /.../public o /.../public/clientes
$PUBLIC_BASE = (preg_match('~/(clientes)(/|$)~', $dir)) ? rtrim(dirname($dir), '/\\') : $dir;

if (!function_exists('url_public')) {
  function url_public($path){
    global $PUBLIC_BASE;
    $b = rtrim($PUBLIC_BASE,'/');
    return ($b===''?'':$b).'/'.ltrim((string)$path,'/');
  }
}
if (!function_exists('urlc')) {
  function urlc($path){ return url_public('clientes/'.ltrim((string)$path,'/')); }
}

/* ===== Config de formas de pago =====
   Editá porcentajes/cuotas a gusto
*/
$PAY_METHODS = [
  'efectivo'         => ['label'=>'Efectivo',          'discount_pct'=>10],
  'transferencia'    => ['label'=>'Transferencia',     'discount_pct'=>5],
  'debito'           => ['label'=>'Débito',            'fee_pct'=>0, 'installments'=>[1=>0]],
  'credito'          => ['label'=>'Crédito',           'installments'=>[1=>0, 3=>10, 6=>20, 12=>35]],
  'cuenta_corriente' => ['label'=>'Cuenta Corriente',  'fee_pct'=>0],
];

/* ===== Estado de conexión/esquema ===== */
$db_ok = $has_conn && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

$has_products=$has_variants=false;
$has_image_url=$has_product_price=false; $has_variant_price=false;
if ($db_ok) {
  $has_products      = (@$conexion->query("SHOW TABLES LIKE 'products'")?->num_rows ?? 0) > 0;
  $has_variants      = (@$conexion->query("SHOW TABLES LIKE 'product_variants'")?->num_rows ?? 0) > 0;
  if ($has_products) {
    $has_image_url     = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")?->num_rows ?? 0) > 0;
    $has_product_price = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'price'")?->num_rows ?? 0) > 0;
  }
  if ($has_variants) {
    $has_variant_price = (@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'")?->num_rows ?? 0) > 0;
  }
}

/* ===== Carrito en sesión ===== */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart =& $_SESSION['cart'];

/* ===== Estado de pago en sesión ===== */
if (!isset($_SESSION['payment']) || !is_array($_SESSION['payment'])) {
  $_SESSION['payment'] = ['method'=>'efectivo', 'installments'=>1];
}
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
    if (!isset($PAY_METHODS[$m])) $m = 'efectivo';
    if (!empty($PAY_METHODS[$m]['installments'])) {
      $valid = array_keys($PAY_METHODS[$m]['installments']);
      if (!in_array($cuotas, $valid, true)) $cuotas = (int)reset($valid);
    } else {
      $cuotas = 1;
    }
    $payment = ['method'=>$m,'installments'=>$cuotas];
  }

  $_SESSION['cart_count'] = array_sum(array_column($cart, 'qty'));
  header('Location: '.urlc('carrito.php'));
  exit;
}

/* ===== Armar ítems del carrito ===== */
$items = [];
$subtotal = 0.0;

foreach ($cart as $k => $it) {
  $pid = (int)($it['product_id'] ?? 0);
  $vid = (int)($it['variant_id'] ?? 0);
  $qty = max(0, (int)($it['qty'] ?? 0));
  if ($pid<=0 || $qty<=0) continue;

  $name = 'Producto #'.$pid;
  $img  = 'https://picsum.photos/seed/'.$pid.'/640/480';
  $price = 0.0;

  if ($db_ok && $has_products) {
    // Nombre + imagen
    $sqlp = $has_image_url
      ? "SELECT name, image_url FROM products WHERE id={$pid} LIMIT 1"
      : "SELECT name FROM products WHERE id={$pid} LIMIT 1";
    if ($res = @$conexion->query($sqlp)) {
      if ($row = $res->fetch_assoc()) {
        if (!empty($row['name'])) $name = $row['name'];
        if ($has_image_url && !empty($row['image_url'])) $img = $row['image_url'];
      }
    }
    // Precio por variante o producto
    if ($has_variants && $has_variant_price) {
      if ($vid>0) {
        if ($rv = @$conexion->query("SELECT price FROM product_variants WHERE id={$vid} AND product_id={$pid} LIMIT 1")) {
          if ($rr = $rv->fetch_assoc()) $price = (float)($rr['price'] ?? 0);
        }
      }
      if ($price<=0) {
        if ($rv = @$conexion->query("SELECT MIN(price) AS p FROM product_variants WHERE product_id={$pid}")) {
          if ($rr = $rv->fetch_assoc()) $price = (float)($rr['p'] ?? 0);
        }
      }
    }
    if ($price<=0 && $has_product_price) {
      if ($rp = @$conexion->query("SELECT price FROM products WHERE id={$pid} LIMIT 1")) {
        if ($rr = $rp->fetch_assoc()) $price = (float)($rr['price'] ?? 0);
      }
    }
  }

  $line_total = $price * $qty;
  $subtotal  += $line_total;

  $items[] = [
    'key'=>$k,'pid'=>$pid,'vid'=>$vid,'name'=>$name,'img'=>$img,
    'price'=>$price,'qty'=>$qty,'line_total'=>$line_total
  ];
}

$cart_count = array_sum(array_column($cart, 'qty'));
$_SESSION['cart_count'] = $cart_count;

/* ===== Calcular totales según forma de pago ===== */
$method = $payment['method'] ?? 'efectivo';
$cuotas = (int)($payment['installments'] ?? 1);
$discount = 0.0; $fee = 0.0;

if (isset($PAY_METHODS[$method])) {
  $cfg = $PAY_METHODS[$method];
  if (!empty($cfg['discount_pct'])) $discount = $subtotal * ((float)$cfg['discount_pct'])/100.0;
  if (!empty($cfg['fee_pct']))      $fee      = $subtotal * ((float)$cfg['fee_pct'])/100.0;

  if (!empty($cfg['installments'])) {
    $map = $cfg['installments'];
    if (!array_key_exists($cuotas, $map)) $cuotas = (int)array_key_first($map);
    $pct = (float)$map[$cuotas];
    $discount = 0.0;                    // prioridad a recargo en métodos con cuotas
    $fee = $subtotal * $pct/100.0;
  }
}
$total = max(0.0, $subtotal - $discount + $fee);
$cuota_monto = ($method==='credito' && $cuotas>1) ? ($total / $cuotas) : 0.0;

/* ===== Header ===== */
$header_path = $root.'/includes/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna — Carrito</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>" />
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--ring,#2d323d);padding:10px;text-align:left;vertical-align:middle}
    .qty-input{width:70px;text-align:center}
    .actions a,.actions button{margin-right:8px}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--ring);border-radius:999px}
    .cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    .totals{display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;padding:12px 0}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;padding:12px}
    .pay-card .row{display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
    .pay-card label{display:inline-flex;align-items:center;gap:8px;cursor:pointer}
    .pay-card select{padding:.45rem .6rem;border:1px solid var(--ring);background:transparent;color:var(--fg);border-radius:.5rem}
    .alert{background:#2a1b1b;border:1px solid #7f1d1d;color:#fecaca;border-radius:8px;padding:10px;margin:10px 0}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <?php if (!$has_conn): ?>
      <div class="alert">⚠️ No se encontró <code>includes/conn.php</code>. Ruta base: <code><?= h($root) ?></code></div>
    <?php elseif (!$db_ok): ?>
      <div class="alert">⚠️ Sin conexión a la base de datos (revisar credenciales).</div>
    <?php endif; ?>

    <nav class="breadcrumb" style="margin:8px 0 2px">
      <a href="<?= urlc('index.php') ?>">Tienda</a> <span>›</span>
      <strong>Carrito</strong>
    </nav>

    <header style="display:flex;align-items:center;justify-content:space-between;padding:16px 0">
      <h1 style="margin:0">🛒 Carrito</h1>
      <span class="pill">Ítems: <b><?= (int)$cart_count ?></b></span>
    </header>

    <?php if (empty($items)): ?>
      <div class="card" style="margin-bottom:12px">
        Tu carrito está vacío. <a class="cta" href="<?= urlc('index.php') ?>">Ir a la tienda</a>
      </div>
    <?php else: ?>
      <!-- Tabla de ítems -->
      <div style="overflow:auto;margin-top:10px">
        <table class="table">
          <thead>
            <tr>
              <th style="width:64px">Img</th>
              <th>Producto</th>
              <th>Precio</th>
              <th style="width:120px">Cantidad</th>
              <th>Total</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $row): ?>
              <tr>
                <td><img src="<?= h($row['img']) ?>" alt="<?= h($row['name']) ?>" style="width:64px;height:64px;object-fit:cover;border-radius:8px"></td>
                <td><?= h($row['name']) ?></td>
                <td>$ <?= money($row['price']) ?></td>
                <td>
                  <form method="post" action="<?= urlc('carrito.php') ?>" style="display:flex;gap:6px;align-items:center">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="product_id" value="<?= (int)$row['pid'] ?>">
                    <input type="hidden" name="variant_id" value="<?= (int)$row['vid'] ?>">
                    <input class="qty-input" type="number" name="qty" min="0" step="1" value="<?= (int)$row['qty'] ?>">
                    <button type="submit" class="cta">Actualizar</button>
                  </form>
                </td>
                <td>$ <?= money($row['line_total']) ?></td>
                <td class="actions">
                  <form method="post" action="<?= urlc('carrito.php') ?>" onsubmit="return confirm('¿Quitar este producto?')">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="product_id" value="<?= (int)$row['pid'] ?>">
                    <input type="hidden" name="variant_id" value="<?= (int)$row['vid'] ?>">
                    <button type="submit" class="cta">Quitar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Acciones generales -->
      <div style="margin-top:10px">
        <form method="post" action="<?= urlc('carrito.php') ?>">
          <input type="hidden" name="action" value="clear">
          <button type="submit" class="cta" onclick="return confirm('¿Vaciar carrito?')">Vaciar carrito</button>
        </form>
      </div>

      <div class="totals">
        <!-- Formas de pago -->
        <div class="card pay-card" style="flex:1;min-width:300px">
          <h3 style="margin-top:0">💳 Forma de pago</h3>
          <form method="post" action="<?= urlc('carrito.php') ?>">
            <input type="hidden" name="action" value="set_payment">

            <?php foreach ($PAY_METHODS as $key=>$cfg): ?>
              <div class="row">
                <label>
                  <input type="radio" name="method" value="<?= h($key) ?>" <?= ($method===$key?'checked':'') ?>>
                  <?= h($cfg['label']) ?>
                </label>

                <?php if (!empty($cfg['discount_pct'])): ?>
                  <span style="opacity:.8">— Descuento <?= (float)$cfg['discount_pct'] ?>%</span>
                <?php endif; ?>

                <?php if (!empty($cfg['fee_pct']) && empty($cfg['installments'])): ?>
                  <span style="opacity:.8">— Recargo <?= (float)$cfg['fee_pct'] ?>%</span>
                <?php endif; ?>

                <?php if (!empty($cfg['installments'])): ?>
                  <select name="installments" <?= ($method===$key ? '' : 'disabled') ?> data-installments-for="<?= h($key) ?>">
                    <?php foreach ($cfg['installments'] as $n=>$pct): ?>
                      <option value="<?= (int)$n ?>" <?= ($method===$key && $cuotas===$n?'selected':'') ?>>
                        <?= (int)$n ?> cuota<?= $n>1?'s':'' ?> (<?= (float)$pct ?>%)
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <button type="submit" class="cta" style="margin-top:8px">Aplicar forma de pago</button>
          </form>
        </div>

        <!-- Totales -->
        <div class="card" style="min-width:280px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <span>Subtotal</span><b>$ <?= money($subtotal) ?></b>
          </div>
          <?php if ($discount>0): ?>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
              <span>Descuento (<?= h($PAY_METHODS[$method]['label'] ?? '') ?>)</span>
              <b>− $ <?= money($discount) ?></b>
            </div>
          <?php endif; ?>
          <?php if ($fee>0): ?>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
              <span>Recargo (<?= h($PAY_METHODS[$method]['label'] ?? '') ?><?= $method==='credito' ? " {$cuotas} cuotas" : "" ?>)</span>
              <b>+ $ <?= money($fee) ?></b>
            </div>
          <?php endif; ?>
          <hr style="border:0;border-top:1px solid var(--ring,#2d323d);margin:8px 0">
          <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:1.05rem">
            <span>Total</span><b>$ <?= money($total) ?></b>
          </div>
          <?php if ($cuota_monto>0): ?>
            <div style="text-align:right;opacity:.9">En <?= (int)$cuotas ?> cuotas de <b>$ <?= money($cuota_monto) ?></b></div>
          <?php endif; ?>
          <div style="margin-top:10px;text-align:right">
            <a class="cta" href="<?= urlc('index.php') ?>">Seguir comprando</a>
            <a class="cta" href="<?= urlc('checkout.php') ?>">Ir a pagar</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script>
    // Habilitar/deshabilitar selector de cuotas según método elegido
    (function(){
      const radios = document.querySelectorAll('input[name="method"]');
      const selects = document.querySelectorAll('select[data-installments-for]');
      function sync() {
        let sel = document.querySelector('input[name="method"]:checked');
        let method = sel ? sel.value : '';
        selects.forEach(s => { s.disabled = (s.getAttribute('data-installments-for') !== method); });
      }
      radios.forEach(r => r.addEventListener('change', sync));
      sync();
    })();
  </script>

</body>
</html>
