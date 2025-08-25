<?php
/* ===== DEBUG (apagá en prod) ===== */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status()===PHP_SESSION_NONE) session_start();

/* ===== Buscar raíz con includes/conn.php ===== */
$root = __DIR__;
for ($i=0; $i<6; $i++) {
  if (file_exists($root.'/includes/conn.php')) break;
  $root = dirname($root);
}
$has_conn = file_exists($root.'/includes/conn.php');
if ($has_conn) { require $root.'/includes/conn.php'; }
@require $root.'/includes/helpers.php';

/* ===== Helpers ===== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ===== BASES WEB (sin duplicar /clientes) ===== */
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$dir    = rtrim(dirname($script), '/\\'); // /.../public o /.../public/clientes
$PUBLIC_BASE = (preg_match('~/(clientes)(/|$)~', $dir)) ? rtrim(dirname($dir), '/\\') : $dir;
if (!function_exists('url_public')) {
  function url_public($path){ global $PUBLIC_BASE; $b=rtrim($PUBLIC_BASE,'/'); return ($b===''?'':$b).'/'.ltrim((string)$path,'/'); }
}
if (!function_exists('urlc')) {
  function urlc($path){ return url_public('clientes/'.ltrim((string)$path,'/')); }
}

/* ===== Config pagos ===== */
$PAY_METHODS = [
  'efectivo'         => ['label'=>'Efectivo',          'discount_pct'=>10],
  'transferencia'    => ['label'=>'Transferencia',     'discount_pct'=>5],
  'debito'           => ['label'=>'Débito',            'fee_pct'=>0, 'installments'=>[1=>0]],
  'credito'          => ['label'=>'Crédito',           'installments'=>[1=>0, 3=>10, 6=>20, 12=>35]],
  'cuenta_corriente' => ['label'=>'Cuenta Corriente',  'fee_pct'=>0],
];

/* ===== Config envío ===== */
$SHIPPING = [
  'retiro' => ['label'=>'Retiro en tienda', 'flat'=>0,    'free_over'=>0],
  'envio'  => ['label'=>'Envío a domicilio','flat'=>2500, 'free_over'=>50000], // gratis desde $50.000
];

/* ===== Estado DB / esquema ===== */
$db_ok=false; $has_products=$has_variants=false;
$has_image_url=$has_product_price=false; $has_variant_price=false;
if ($has_conn && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno) {
  $db_ok=true;
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

/* ===== Carrito & pago & envío (sesión) ===== */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart     =& $_SESSION['cart'];

if (!isset($_SESSION['payment']) || !is_array($_SESSION['payment'])) {
  $_SESSION['payment'] = ['method'=>'efectivo','installments'=>1];
}
$payment  =& $_SESSION['payment'];

if (!isset($_SESSION['shipping']) || !is_array($_SESSION['shipping'])) {
  $_SESSION['shipping'] = [
    'method'=>'retiro','address'=>'','city'=>'','province'=>'','postal'=>'','notes'=>''
  ];
}
$shipping =& $_SESSION['shipping'];

/* ===== Acciones (POST) ===== */
$errors = []; $ok_sale_id = 0;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'set_shipping') {
    $m = $_POST['ship_method'] ?? 'retiro';
    if (!isset($SHIPPING[$m])) $m = 'retiro';
    $shipping['method']   = $m;
    if ($m==='envio') {
      $shipping['address']  = trim((string)($_POST['ship_address']  ?? ''));
      $shipping['city']     = trim((string)($_POST['ship_city']     ?? ''));
      $shipping['province'] = trim((string)($_POST['ship_province'] ?? ''));
      $shipping['postal']   = trim((string)($_POST['ship_postal']   ?? ''));
      $shipping['notes']    = trim((string)($_POST['ship_notes']    ?? ''));
    } else {
      $shipping['address']=$shipping['city']=$shipping['province']=$shipping['postal']=$shipping['notes']='';
    }
    header('Location: '.urlc('checkout.php')); exit;
  }

  if ($action === 'confirm') {
    // Validaciones básicas
    $name  = trim((string)($_POST['customer_name']  ?? ''));
    $phone = trim((string)($_POST['customer_phone'] ?? ''));
    $email = trim((string)($_POST['customer_email'] ?? ''));
    if ($name==='')  $errors[]='Ingresá tu nombre.';
    if ($phone==='') $errors[]='Ingresá un teléfono.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[]='Email inválido.';
    if (empty($cart)) $errors[]='El carrito está vacío.';
    if (!$db_ok) $errors[]='No hay conexión a la BD.';

    if (empty($errors)) {
      // Calcular totales otra vez (seguro)
      // --- Items
      $items=[]; $subtotal=0.0;
      foreach ($cart as $it) {
        $pid=(int)($it['product_id']??0); $vid=(int)($it['variant_id']??0); $qty=max(0,(int)($it['qty']??0));
        if ($pid<=0||$qty<=0) continue;
        $nameP='Producto #'.$pid; $price=0.0;
        if ($db_ok && $has_products) {
          if ($has_variants && $has_variant_price && $vid>0) {
            if ($rv=@$conexion->query("SELECT price FROM product_variants WHERE id={$vid} AND product_id={$pid} LIMIT 1")) {
              if ($rr=$rv->fetch_assoc()) $price=(float)($rr['price']??0);
            }
          }
          if ($price<=0 && $has_variants && $has_variant_price) {
            if ($rv=@$conexion->query("SELECT MIN(price) AS p FROM product_variants WHERE product_id={$pid}")) {
              if ($rr=$rv->fetch_assoc()) $price=(float)($rr['p']??0);
            }
          }
          if ($price<=0 && $has_product_price) {
            if ($rp=@$conexion->query("SELECT price FROM products WHERE id={$pid} LIMIT 1")) {
              if ($rr=$rp->fetch_assoc()) $price=(float)($rr['price']??0);
            }
          }
          if ($rp2=@$conexion->query("SELECT name FROM products WHERE id={$pid} LIMIT 1")) {
            if ($rr2=$rp2->fetch_assoc()) $nameP=$rr2['name']?:$nameP;
          }
        }
        $lt=$price*$qty; $subtotal+=$lt;
        $items[]=['pid'=>$pid,'vid'=>$vid,'name'=>$nameP,'qty'=>$qty,'price'=>$price,'line_total'=>$lt];
      }
      // --- Pago
      $method   = $payment['method'] ?? 'efectivo';
      $cuotas   = (int)($payment['installments'] ?? 1);
      $discount=0.0; $fee=0.0;
      if (isset($PAY_METHODS[$method])) {
        $cfg=$PAY_METHODS[$method];
        if (!empty($cfg['discount_pct'])) $discount=$subtotal*$cfg['discount_pct']/100;
        if (!empty($cfg['fee_pct']))      $fee=$subtotal*$cfg['fee_pct']/100;
        if (!empty($cfg['installments'])) {
          $map=$cfg['installments']; if (!array_key_exists($cuotas,$map)) $cuotas=(int)array_key_first($map);
          $discount=0.0; $fee=$subtotal*$map[$cuotas]/100;
        }
      }
      // --- Envío
      $ship_method = $shipping['method'] ?? 'retiro';
      $ship_cfg = $SHIPPING[$ship_method] ?? $SHIPPING['retiro'];
      $ship_cost = 0.0;
      if ($ship_method==='envio') {
        $flat = (float)($ship_cfg['flat']??0);
        $free = (float)($ship_cfg['free_over']??0);
        $ship_cost = ($free>0 && $subtotal>=$free) ? 0.0 : $flat;
        // Validar dirección mínima
        if (($shipping['address']??'')==='') $errors[]='Ingresá la dirección para el envío.';
        if (($shipping['city']??'')==='')    $errors[]='Ingresá la ciudad.';
        if (($shipping['province']??'')==='')$errors[]='Ingresá la provincia.';
        if (($shipping['postal']??'')==='')  $errors[]='Ingresá el código postal.';
      }
      $total = max(0.0, $subtotal - $discount + $fee + $ship_cost);

      if (empty($errors)) {
        // Crear tablas si no existen (con columnas de envío)
        @$conexion->query("CREATE TABLE IF NOT EXISTS sales (
          id INT AUTO_INCREMENT PRIMARY KEY,
          customer_name VARCHAR(120) NULL,
          customer_phone VARCHAR(60) NULL,
          customer_email VARCHAR(120) NULL,
          payment_method VARCHAR(30) NOT NULL,
          installments INT NOT NULL DEFAULT 1,
          subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
          discount DECIMAL(12,2) NOT NULL DEFAULT 0,
          fee DECIMAL(12,2) NOT NULL DEFAULT 0,
          shipping_method VARCHAR(30) NULL,
          shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
          shipping_address TEXT NULL,
          shipping_city VARCHAR(120) NULL,
          shipping_province VARCHAR(120) NULL,
          shipping_postal VARCHAR(20) NULL,
          shipping_notes TEXT NULL,
          total DECIMAL(12,2) NOT NULL DEFAULT 0,
          status VARCHAR(20) NOT NULL DEFAULT 'new',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @$conexion->query("CREATE TABLE IF NOT EXISTS sale_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          sale_id INT NOT NULL,
          product_id INT NOT NULL,
          variant_id INT NOT NULL DEFAULT 0,
          name VARCHAR(255) NOT NULL,
          qty INT NOT NULL,
          price_unit DECIMAL(12,2) NOT NULL DEFAULT 0,
          line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
          FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conexion->begin_transaction();
        try {
          // Intento extendido (con columnas de envío)
          $stmt = $conexion->prepare("INSERT INTO sales
            (customer_name, customer_phone, customer_email, payment_method, installments, subtotal, discount, fee,
             shipping_method, shipping_cost, shipping_address, shipping_city, shipping_province, shipping_postal, shipping_notes,
             total, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'new')");
          $types = "ssssidddsssssssd";
          $addr  = (string)($shipping['address']??'');
          $city  = (string)($shipping['city']??'');
          $prov  = (string)($shipping['province']??'');
          $post  = (string)($shipping['postal']??'');
          $notes = (string)($shipping['notes']??'');
          $shipm = (string)$ship_method;
          $stmt->bind_param($types,
            $name,$phone,$email,$method,$cuotas,$subtotal,$discount,$fee,
            $shipm,$ship_cost,$addr,$city,$prov,$post,$notes,
            $total
          );
          $stmt->execute();
          $sale_id = (int)$stmt->insert_id;
          $stmt->close();
        } catch (Throwable $e) {
          // Fallback para schema antiguo (sin columnas de envío)
          $conexion->rollback(); $conexion->begin_transaction();
          $stmt = $conexion->prepare("INSERT INTO sales
            (customer_name, customer_phone, customer_email, payment_method, installments, subtotal, discount, fee, total, status)
            VALUES (?,?,?,?,?,?,?,?,?, 'new')");
          $stmt->bind_param("ssssidddd", $name,$phone,$email,$method,$cuotas,$subtotal,$discount,$fee,$total);
          $stmt->execute();
          $sale_id = (int)$stmt->insert_id;
          $stmt->close();
        }

        // Ítems
        $sti = $conexion->prepare("INSERT INTO sale_items
          (sale_id, product_id, variant_id, name, qty, price_unit, line_total)
          VALUES (?,?,?,?,?,?,?)");
        foreach ($items as $it) {
          $sti->bind_param("iiisidd", $sale_id, (int)$it['pid'], (int)$it['vid'], $it['name'], (int)$it['qty'], (float)$it['price'], (float)$it['line_total']);
          $sti->execute();
        }
        $sti->close();

        $conexion->commit();
        $ok_sale_id = $sale_id;

        // Vaciar carrito
        $_SESSION['cart'] = [];
        $_SESSION['cart_count'] = 0;
      }
    }
  }
}

/* ===== Reconstruir items/subtotal para la vista ===== */
$items = []; $subtotal = 0.0;
foreach ($cart as $k => $it) {
  $pid=(int)($it['product_id']??0); $vid=(int)($it['variant_id']??0); $qty=max(0,(int)($it['qty']??0));
  if ($pid<=0||$qty<=0) continue;
  $nameP='Producto #'.$pid; $img='https://picsum.photos/seed/'.$pid.'/640/480'; $price=0.0;
  if ($db_ok && $has_products) {
    $sqlp = $has_image_url ? "SELECT name,image_url FROM products WHERE id={$pid} LIMIT 1"
                           : "SELECT name FROM products WHERE id={$pid} LIMIT 1";
    if ($res=@$conexion->query($sqlp)) if ($row=$res->fetch_assoc()) {
      if (!empty($row['name'])) $nameP=$row['name'];
      if ($has_image_url && !empty($row['image_url'])) $img=$row['image_url'];
    }
    if ($has_variants && $has_variant_price && $vid>0) {
      if ($rv=@$conexion->query("SELECT price FROM product_variants WHERE id={$vid} AND product_id={$pid} LIMIT 1"))
        if ($rr=$rv->fetch_assoc()) $price=(float)($rr['price']??0);
    }
    if ($price<=0 && $has_variants && $has_variant_price) {
      if ($rv=@$conexion->query("SELECT MIN(price) AS p FROM product_variants WHERE product_id={$pid}"))
        if ($rr=$rv->fetch_assoc()) $price=(float)($rr['p']??0);
    }
    if ($price<=0 && $has_product_price) {
      if ($rp=@$conexion->query("SELECT price FROM products WHERE id={$pid} LIMIT 1"))
        if ($rr=$rp->fetch_assoc()) $price=(float)($rr['price']??0);
    }
  }
  $lt=$price*$qty; $subtotal+=$lt;
  $items[]=['pid'=>$pid,'vid'=>$vid,'name'=>$nameP,'img'=>$img,'qty'=>$qty,'price'=>$price,'line_total'=>$lt];
}

/* ===== Totales (pago + envío) ===== */
$method   = $payment['method'] ?? 'efectivo';
$cuotas   = (int)($payment['installments'] ?? 1);
$discount=0.0; $fee=0.0;

if (isset($PAY_METHODS[$method])) {
  $cfg=$PAY_METHODS[$method];
  if (!empty($cfg['discount_pct'])) $discount=$subtotal*$cfg['discount_pct']/100;
  if (!empty($cfg['fee_pct']))      $fee=$subtotal*$cfg['fee_pct']/100;
  if (!empty($cfg['installments'])) {
    $map=$cfg['installments']; if (!array_key_exists($cuotas,$map)) $cuotas=(int)array_key_first($map);
    $discount=0.0; $fee=$subtotal*$map[$cuotas]/100;
  }
}

$ship_method = $shipping['method'] ?? 'retiro';
$ship_cfg = $SHIPPING[$ship_method] ?? $SHIPPING['retiro'];
$ship_cost = 0.0;
if ($ship_method==='envio') {
  $flat=(float)($ship_cfg['flat']??0);
  $free=(float)($ship_cfg['free_over']??0);
  $ship_cost = ($free>0 && $subtotal>=$free) ? 0.0 : $flat;
}
$total = max(0.0, $subtotal - $discount + $fee + $ship_cost);
$cuota_monto = ($method==='credito' && $cuotas>1) ? ($total/$cuotas) : 0.0;

$header_path = $root.'/includes/header.php';
$cart_empty = empty($items);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna — Checkout</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>" />
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width:900px){ .grid2{grid-template-columns:1fr} }
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;padding:12px}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--ring,#2d323d);padding:10px;text-align:left}
    .cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--ring);border-radius:999px}
    .alert{background:#2a1b1b;border:1px solid #7f1d1d;color:#fecaca;border-radius:8px;padding:10px;margin:10px 0}
    input,textarea,select{width:100%;padding:.5rem;border:1px solid var(--ring);background:transparent;color:var(--fg);border-radius:.5rem}
    label{display:block;margin:.4rem 0 .2rem}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <nav class="breadcrumb" style="margin:8px 0 2px">
      <a href="<?= url_public('index.php') ?>">Inicio</a> <span>›</span>
      <a href="<?= urlc('index.php') ?>">Tienda</a> <span>›</span>
      <strong>Checkout</strong>
    </nav>

    <header style="display:flex;align-items:center;justify-content:space-between;padding:16px 0">
      <h1 style="margin:0">✅ Checkout</h1>
      <a class="pill" href="<?= urlc('carrito.php') ?>">Volver al carrito</a>
    </header>

    <?php if ($cart_empty): ?>
      <div class="card">
        Tu carrito está vacío. <a class="cta" href="<?= urlc('index.php') ?>">Ir a la tienda</a>
      </div>
    <?php else: ?>

      <?php if (!empty($errors)): ?>
        <div class="alert">
          <?php foreach ($errors as $e): ?>• <?= h($e) ?><br><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="grid2">
        <!-- Resumen -->
        <div class="card">
          <h3 style="margin-top:0">🧾 Resumen</h3>
          <div style="overflow:auto">
            <table class="table">
              <thead><tr><th>Producto</th><th>Cant.</th><th>Unit.</th><th>Total</th></tr></thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                  <tr>
                    <td><?= h($it['name']) ?></td>
                    <td><?= (int)$it['qty'] ?></td>
                    <td>$ <?= money($it['price']) ?></td>
                    <td>$ <?= money($it['line_total']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div style="margin-top:10px">
            <div style="display:flex;justify-content:space-between"><span>Subtotal</span><b>$ <?= money($subtotal) ?></b></div>
            <?php if ($discount>0): ?>
              <div style="display:flex;justify-content:space-between"><span>Descuento</span><b>− $ <?= money($discount) ?></b></div>
            <?php endif; ?>
            <?php if ($fee>0): ?>
              <div style="display:flex;justify-content:space-between"><span>Recargo</span><b>+ $ <?= money($fee) ?></b></div>
            <?php endif; ?>
            <?php if ($ship_cost>0): ?>
              <div style="display:flex;justify-content:space-between"><span>Envío</span><b>+ $ <?= money($ship_cost) ?></b></div>
            <?php else: ?>
              <div style="display:flex;justify-content:space-between;opacity:.85"><span>Envío</span><b>$ <?= money($ship_cost) ?><?= $ship_method==='envio' ? ' (gratis)' : '' ?></b></div>
            <?php endif; ?>
            <hr style="border:0;border-top:1px solid var(--ring,#2d323d);margin:8px 0">
            <div style="display:flex;justify-content:space-between;font-size:1.05rem"><span>Total</span><b>$ <?= money($total) ?></b></div>
            <?php if ($cuota_monto>0): ?>
              <div style="text-align:right;opacity:.9">En <?= (int)$cuotas ?> cuotas de <b>$ <?= money($cuota_monto) ?></b></div>
            <?php endif; ?>
            <div style="margin-top:8px;opacity:.9">
              Pago: <b><?= h($PAY_METHODS[$method]['label'] ?? $method) ?></b><?php if ($method==='credito'): ?> — <?= (int)$cuotas ?> cuotas<?php endif; ?>
              <a class="cta" href="<?= urlc('carrito.php') ?>" style="margin-left:8px">Cambiar</a>
            </div>
          </div>
        </div>

        <!-- Envío + Datos del cliente -->
        <div class="card">
          <h3 style="margin-top:0">🚚 Envío</h3>
          <form method="post" action="<?= urlc('checkout.php') ?>">
            <input type="hidden" name="action" value="set_shipping">
            <label><input type="radio" name="ship_method" value="retiro" <?= ($ship_method==='retiro'?'checked':'') ?>> <?= h($SHIPPING['retiro']['label']) ?></label>
            <label><input type="radio" name="ship_method" value="envio"  <?= ($ship_method==='envio'?'checked':'')  ?>> <?= h($SHIPPING['envio']['label']) ?></label>

            <div id="addr" style="margin-top:8px;<?= $ship_method==='envio' ? '' : 'display:none' ?>">
              <label for="ship_address">Dirección</label>
              <input id="ship_address" name="ship_address" value="<?= h($shipping['address']??'') ?>">

              <label for="ship_city">Ciudad</label>
              <input id="ship_city" name="ship_city" value="<?= h($shipping['city']??'') ?>">

              <label for="ship_province">Provincia</label>
              <input id="ship_province" name="ship_province" value="<?= h($shipping['province']??'') ?>">

              <label for="ship_postal">Código postal</label>
              <input id="ship_postal" name="ship_postal" value="<?= h($shipping['postal']??'') ?>">

              <label for="ship_notes">Notas para el envío (opcional)</label>
              <textarea id="ship_notes" name="ship_notes" rows="2"><?= h($shipping['notes']??'') ?></textarea>
            </div>

            <div style="text-align:right;margin-top:8px">
              <button type="submit" class="cta">Guardar envío</button>
            </div>
          </form>

          <h3 style="margin-top:16px">👤 Tus datos</h3>
          <form method="post" action="<?= urlc('checkout.php') ?>">
            <input type="hidden" name="action" value="confirm">
            <label for="customer_name">Nombre y apellido</label>
            <input id="customer_name" name="customer_name" required value="<?= h($_POST['customer_name'] ?? '') ?>">

            <label for="customer_phone">Teléfono</label>
            <input id="customer_phone" name="customer_phone" required value="<?= h($_POST['customer_phone'] ?? '') ?>">

            <label for="customer_email">Email</label>
            <input id="customer_email" name="customer_email" type="email" required value="<?= h($_POST['customer_email'] ?? '') ?>">

            <div style="margin-top:10px;text-align:right">
              <a class="cta" href="<?= urlc('index.php') ?>">Seguir comprando</a>
              <button type="submit" class="cta">Confirmar compra</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script>
    // Mostrar/ocultar dirección si se selecciona Envío
    (function(){
      const radios = document.querySelectorAll('input[name="ship_method"]');
      const addr = document.getElementById('addr');
      function sync(){ 
        let sel = document.querySelector('input[name="ship_method"]:checked');
        addr.style.display = (sel && sel.value==='envio') ? '' : 'none';
      }
      radios.forEach(r => r.addEventListener('change', sync));
      sync();
    })();
  </script>

</body>
</html>
