<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';
require $root.'/includes/page_head.php'; // HERO unificado

/* Helpers por si faltan */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* Rutas dinámicas (localhost/Render) */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('url')) {
  function url($p){ global $BASE; return $BASE.'/'.ltrim($p,'/'); }
}

/* (Opcional) flags mínimos de esquema para evitar errores si querés listar algo */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Ventas</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php
page_head('Ventas', 'Registro de ventas y pagos.');
?>

<main class="container">
  <!-- Mensajes (placeholder para futuro uso) -->
  <?php if (!empty($_GET['ok'])): ?>
    <div class="kpi"><div class="box"><b>OK</b> <?=h($_GET['ok'])?></div></div>
  <?php endif; ?>
  <?php if (!empty($_GET['err'])): ?>
    <div class="kpi"><div class="box"><b>Error</b> <?=h($_GET['err'])?></div></div>
  <?php endif; ?>

  <!-- Card placeholder -->
  <div class="card" style="padding:14px">
    <div class="p">
      <b>Página creada.</b> Próximo paso: conectar formulario de venta a la base de datos
      (cliente, items, método de pago, total y comprobante).
    </div>
  </div>

  <!-- Sugerencia de estructura de formulario (a futuro, sin acción todavía) -->
  <h2 class="mt-4">➕ Nueva venta (borrador)</h2>
  <form class="card" style="padding:14px" method="post" onsubmit="alert('Este formulario aún no está conectado.');return false;">
    <div class="row">
      <label>Fecha <input class="input" type="datetime-local" name="sold_at" value="<?=date('Y-m-d\TH:i')?>"></label>
      <label>Cliente <input class="input" name="customer" placeholder="Opcional"></label>
      <label>Método de pago
        <select class="input" name="payment_method">
          <option value="efectivo">Efectivo</option>
          <option value="tarjeta">Tarjeta</option>
          <option value="transferencia">Transferencia</option>
          <option value="mp">Mercado Pago</option>
        </select>
      </label>
      <label>Comprobante <input class="input" name="receipt" placeholder="Opcional (número)"></label>
    </div>

    <h3>Ítems</h3>
    <div class="row">
      <label>SKU / Producto <input class="input" name="item_1_sku" placeholder="Ej: REM-LUNA-M"></label>
      <label>Cantidad <input class="input" type="number" min="1" value="1" name="item_1_qty"></label>
      <label>Precio unitario ($) <input class="input" type="number" step="0.01" min="0" value="0" name="item_1_price"></label>
    </div>

    <div class="row">
      <label>Notas <input class="input" name="notes" placeholder="Opcional"></label>
      <label>Total ($) <input class="input" type="number" step="0.01" min="0" value="0" name="total"></label>
    </div>

    <button type="submit">Guardar venta</button>
  </form>
</main>

<?php require $root.'/includes/footer.php'; ?>
</body>
</html>
