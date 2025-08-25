<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';

if (!isset($_SESSION['carrito'])) $_SESSION['carrito'] = [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2, ',', '.'); }

/* Rutas base desde /clientes */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('urlc')) {
  function urlc($p){ global $BASE; return $BASE.'/'.ltrim($p,'/'); }
}

/* Cargar items del carrito */
$items = [];
$total = 0.0;

if (!empty($_SESSION['carrito'])) {
  $ids = array_map('intval', array_keys($_SESSION['carrito']));
  $in  = implode(',', $ids);

  $sql = "
    SELECT 
      p.id, p.name, p.image_url,
      (SELECT MIN(v.price) FROM product_variants v WHERE v.product_id=p.id) AS price
    FROM products p
    WHERE p.active=1 AND p.id IN ($in)
  ";
  $res = $conexion->query($sql);
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $pid = (int)$r['id'];
      $qty = (int)($_SESSION['carrito'][$pid] ?? 0);
      if ($qty<=0) continue;
      $price = (float)($r['price'] ?? 0);
      $sub   = $price * $qty;
      $total += $sub;
      $items[] = [
        'id'=>$pid,'name'=>$r['name'],'image_url'=>$r['image_url'],
        'price'=>$price,'qty'=>$qty,'sub'=>$sub
      ];
    }
  }
}

if (empty($items)) {
  header('Location: '.urlc('index.php')); exit;
}

/* Confirmación */
$okMsg = $errMsg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['confirm'])) {
  // Datos "cliente" muy básicos para prototipo
  $nombre = trim($_POST['nombre'] ?? '');
  $telefono = trim($_POST['telefono'] ?? '');
  $metodo = $_POST['metodo'] ?? 'efectivo';
  $reserva = !empty($_POST['reserva']);

  if ($nombre==='') $errMsg = 'Indicá un nombre para identificar tu pedido.';
  if (!$errMsg) {
    // Prototipo: guardamos en sesión y vaciamos carrito
    $_SESSION['last_order'] = [
      'nombre'=>$nombre,
      'telefono'=>$telefono,
      'metodo'=>$metodo,
      'reserva'=>$reserva,
      'items'=>$items,
      'total'=>$total,
      'created_at'=>date('Y-m-d H:i:s'),
    ];
    $_SESSION['carrito'] = [];

    $okMsg = $reserva ? '✅ Reserva realizada. Te contactaremos para coordinar el retiro/pago.'
                      : '✅ Pedido confirmado. ¡Gracias por tu compra!';
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Pago</title>
  <link rel="stylesheet" href="../assets/css/styles.css">
  <link rel="icon" type="image/png" href="../assets/img/logo.png">
</head>
<body>

<header class="hero">
  <div class="container">
    <h1>Checkout</h1>
    <p>Elegí el método de pago o realizá una reserva sin pagar ahora.</p>
    <a class="cta" href="<?=urlc('carrito.php')?>">⬅️ Volver al carrito</a>
  </div>
</header>

<main class="container">
  <?php if($okMsg): ?>
    <div class="kpi"><div class="box"><b>OK</b> <?=h($okMsg)?></div></div>

    <div class="card" style="padding:14px">
      <div class="p">
        <h3>Resumen</h3>
        <ul>
          <?php foreach($items as $it): ?>
            <li><?=h($it['qty'])?> × <?=h($it['name'])?> — $ <?=money($it['sub'])?></li>
          <?php endforeach; ?>
        </ul>
        <p><b>Total:</b> $ <?=money($total)?></p>

        <p class="mt-2">
          <?php if($_SESSION['last_order']['reserva']): ?>
            Tu reserva quedará activa por <b>48 horas</b>.  
            Te contactaremos al teléfono: <b><?=h($_SESSION['last_order']['telefono'] ?: '—')?></b>.
          <?php else: ?>
            Método elegido: <b><?=h($_SESSION['last_order']['metodo'])?></b>.  
            Si seleccionaste transferencia:  
            <br>Alias: <code>luna.shop.tienda</code> — CBU: <code>00000000-0000-00000000</code> (reemplazar por tus datos).
          <?php endif; ?>
        </p>

        <a class="cta" href="<?=urlc('index.php')?>">Volver al catálogo</a>
      </div>
    </div>
  <?php else: ?>

    <div class="grid">
      <div class="card">
        <div class="p">
          <h3>Resumen</h3>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Producto</th><th>Cant</th><th class="right">Subtotal</th></tr></thead>
              <tbody>
              <?php foreach($items as $it): ?>
                <tr>
                  <td><?=h($it['name'])?></td>
                  <td><?= (int)$it['qty'] ?></td>
                  <td class="right">$ <?=money($it['sub'])?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr><th colspan="2" class="right">Total</th><th class="right">$ <?=money($total)?></th></tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="p">
          <h3>Datos y pago</h3>
          <?php if($errMsg): ?><div class="kpi"><div class="box"><b>Error</b> <?=h($errMsg)?></div></div><?php endif; ?>
          <form method="post">
            <div class="row">
              <label>Nombre y apellido <input class="input" name="nombre" required></label>
              <label>Teléfono <input class="input" name="telefono" placeholder="WhatsApp"></label>
              <label>Método de pago
                <select class="input" name="metodo">
                  <option value="efectivo">Efectivo (al retirar)</option>
                  <option value="tarjeta">Tarjeta</option>
                  <option value="transferencia">Transferencia</option>
                  <option value="mp">Mercado Pago</option>
                </select>
              </label>
              <label>
                <input type="checkbox" name="reserva" value="1"> Reservar por 48h (sin pagar ahora)
              </label>
            </div>
            <button type="submit" name="confirm" value="1">Confirmar</button>
          </form>
        </div>
      </div>
    </div>

  <?php endif; ?>
</main>
</body>
</html>
