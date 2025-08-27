<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ===== Resolver $root ===== */
$root = __DIR__;
for ($i=0; $i<6; $i++) {
  if (file_exists($root.'/includes/conn.php')) break;
  $root = dirname($root);
}

/* ===== Includes tolerantes ===== */
if (file_exists($root.'/includes/conn.php')) { require $root.'/includes/conn.php'; }
if (file_exists($root.'/includes/helpers.php')) { require $root.'/includes/helpers.php'; }
if (file_exists($root.'/includes/page_head.php')) { require $root.'/includes/page_head.php'; }
if (!function_exists('page_head')) {
  function page_head($title,$sub=''){
    echo '<header class="container" style="padding:16px 0"><h1 style="margin:0">'
         .htmlspecialchars($title).'</h1>'.($sub?'<div style="opacity:.8">'.$sub.'</div>':'').'</header>';
  }
}

/* ===== Helpers ===== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }
function infer_type($v){ if (is_int($v)) return 'i'; if (is_float($v)) return 'd'; if (is_numeric($v)) return (str_contains((string)$v,'.')?'d':'i'); return 's'; }

/* ===== URL base ===== */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if (!function_exists('url')) {
  function url($p){ global $BASE; $b=rtrim($BASE,'/'); return ($b===''?'/':$b.'/').ltrim((string)$p,'/'); }
}

/* ===== Estado DB ===== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

/* ===== Utilidades DB ===== */
function t_exists($t){ global $conexion; $r=@$conexion->query("SHOW TABLES LIKE '". $conexion->real_escape_string($t) ."'"); return ($r && $r->num_rows>0); }
function hascol($t,$c){ global $conexion; $r=@$conexion->query("SHOW COLUMNS FROM `$t` LIKE '".$conexion->real_escape_string($c)."'"); return ($r && $r->num_rows>0); }

/* ===== Stock helpers ===== */
function adjust_stock($product_id, $variant_id, $qty_change){
  global $conexion;
  $product_id=(int)$product_id; $variant_id=(int)$variant_id; $qty_change=(int)$qty_change;

  if (t_exists('product_variants') && $variant_id>0) {
    $col = hascol('product_variants','stock') ? 'stock' : (hascol('product_variants','existencia') ? 'existencia' : null);
    if ($col && ($st=$conexion->prepare("UPDATE product_variants SET `$col`=GREATEST(0,`$col`+?) WHERE id=? AND product_id=?"))) {
      $st->bind_param('iii',$qty_change,$variant_id,$product_id);
      $st->execute(); $st->close(); return;
    }
  }
  if (t_exists('products')) {
    $colp = hascol('products','stock') ? 'stock' : (hascol('products','existencia') ? 'existencia' : null);
    if ($colp && ($st=$conexion->prepare("UPDATE products SET `$colp`=GREATEST(0,`$colp`+?) WHERE id=?"))) {
      $st->bind_param('ii',$qty_change,$product_id);
      $st->execute(); $st->close();
    }
  }
}

/* ===== Reservas ===== */
function reservations_table_exists(){ return t_exists('stock_reservations'); }
function close_reservations_without_restock($sale_id){
  global $conexion;
  if (!reservations_table_exists()) return;
  if ($st=$conexion->prepare("UPDATE stock_reservations SET released_at=NOW() WHERE sale_id=? AND released_at IS NULL")) {
    $st->bind_param('i',$sale_id); $st->execute(); $st->close();
  }
}
function restock_from_reservations($sale_id){
  global $conexion;
  if (!reservations_table_exists()) return false;
  $sql="SELECT id,product_id,variant_id,qty FROM stock_reservations WHERE sale_id=? AND released_at IS NULL";
  if ($st=$conexion->prepare($sql)) {
    $st->bind_param('i',$sale_id);
    $st->execute(); $rs=$st->get_result();
    $found=false;
    while($r=$rs->fetch_assoc()){
      $found=true;
      adjust_stock((int)$r['product_id'], (int)$r['variant_id'], + (int)$r['qty']);
      if ($st2=$conexion->prepare("UPDATE stock_reservations SET released_at=NOW() WHERE id=?")) {
        $id=(int)$r['id']; $st2->bind_param('i',$id); $st2->execute(); $st2->close();
      }
    }
    $st->close();
    return $found;
  }
  return false;
}
function restock_from_sale_items($sale_id){
  global $conexion;
  if (!t_exists('sale_items')) return false;
  $st = $conexion->prepare("SELECT product_id,variant_id,qty FROM sale_items WHERE sale_id=?");
  if (!$st) return false;
  $st->bind_param('i',$sale_id); $st->execute(); $rs=$st->get_result();
  $found=false;
  while($r=$rs->fetch_assoc()){
    $found=true;
    adjust_stock((int)$r['product_id'], (int)$r['variant_id'], + (int)$r['qty']);
  }
  $st->close();
  return $found;
}

/* ===== Update dinámico en sales ===== */
function update_sales_columns($sale_id, array $data){
  global $conexion;
  $set=[]; $types=''; $vals=[];
  foreach($data as $col=>$val){
    if (hascol('sales',$col)){
      $set[]="`$col`=?"; $types .= infer_type($val); $vals[]=$val;
    }
  }
  if (!$set) return false;
  $sql="UPDATE sales SET ".implode(',',$set)." WHERE id=?";
  $types .= 'i'; $vals[]=(int)$sale_id;
  $st=$conexion->prepare($sql);
  if(!$st) throw new Exception('SQL PREPARE update sales: '.$conexion->error);
  $st->bind_param($types, ...$vals);
  if(!$st->execute()){ $e=$st->error; $st->close(); throw new Exception('SQL EXEC update sales: '.$e); }
  $st->close(); return true;
}

/* ===== Setear estado de forma segura ===== */
function safe_update_status($sale_id, array $candidates){
  global $conexion;
  if (!hascol('sales','status')) return;
  foreach ($candidates as $stVal){
    if ($st = $conexion->prepare("UPDATE sales SET status=? WHERE id=?")) {
      $st->bind_param('si',$stVal,$sale_id);
      if ($st->execute()) { $st->close(); return; }
      $st->close();
    }
  }
}

/* ===== AJAX find SKU ===== */
if ($db_ok && (($_GET['__ajax'] ?? '')==='find_sku')) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $sku = trim((string)($_GET['sku'] ?? ''));
    if ($sku==='') throw new Exception('SKU vacío');
    $out = ['ok'=>false];

    if (t_exists('product_variants') && t_exists('products')) {
      $sql = "SELECT v.id AS variant_id, v.price AS vprice, p.id AS product_id, p.name AS pname
              FROM product_variants v
              JOIN products p ON p.id=v.product_id
              WHERE v.sku=? LIMIT 1";
      if ($st = $conexion->prepare($sql)) {
        $st->bind_param('s',$sku);
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) {
          $out = ['ok'=>true,'product_id'=>(int)$row['product_id'],'variant_id'=>(int)$row['variant_id'],
                  'name'=>$row['pname'],'price'=>(float)($row['vprice'] ?? 0),'source'=>'variant'];
        }
        $st->close();
      }
    }
    if (!$out['ok'] && t_exists('products')) {
      $sql = "SELECT id, name, price FROM products WHERE sku=? LIMIT 1";
      if ($st = $conexion->prepare($sql)) {
        $st->bind_param('s',$sku);
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) {
          $out = ['ok'=>true,'product_id'=>(int)$row['id'],'variant_id'=>0,
                  'name'=>$row['name'],'price'=>(float)($row['price'] ?? 0),'source'=>'product'];
        }
        $st->close();
      }
    }
    if (!$out['ok']) throw new Exception('No se encontró SKU');
    echo json_encode($out); exit;
  } catch(Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
  }
}

/* ===== Config POS/pagos ===== */
$PAY_METHODS = [
  'efectivo'      => 'Efectivo',
  'debito'        => 'Débito',
  'credito'       => 'Crédito',
  'transferencia' => 'Transferencia',
  'mp'            => 'Mercado Pago',
];
$DISCOUNT_OPTIONS = [0,5,10,15,20,25];
$UNPAID_METHODS = ['efectivo','transferencia','cuenta_corriente'];

/* ===== Acciones confirmar/cancelar ONLINE ===== */
$okMsg=''; $errMsg='';
if ($db_ok && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
  $act = $_POST['__action'] ?? '';

  if ($act==='mark_online_paid') {
    try{
      $sale_id = max(0,(int)($_POST['sale_id'] ?? 0));
      if ($sale_id<=0) throw new Exception('Venta inválida');

      // leer venta original para calcular totales
      $row=null;
      if ($st=$conexion->prepare("SELECT * FROM sales WHERE id=?")) {
        $st->bind_param('i',$sale_id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
      }
      if (!$row) throw new Exception('Venta no encontrada');

      // total original robusto
      $orig_total = 0.0;
      foreach (['total','grand_total','amount','monto','importe','total_amount','total_final'] as $k) {
        if (isset($row[$k]) && is_numeric($row[$k])) { $orig_total=(float)$row[$k]; break; }
      }
      if ($orig_total<=0){
        $subtotal = (float)($row['subtotal'] ?? 0);
        $discount = (float)($row['discount'] ?? ($row['descuento'] ?? 0));
        $fee      = (float)($row['fee'] ?? ($row['recargo'] ?? 0));
        $ship     = (float)($row['shipping_cost'] ?? ($row['delivery_cost'] ?? ($row['costo_envio'] ?? 0)));
        $orig_total = max(0,$subtotal - $discount + $fee + $ship);
      }

      // inputs de confirmación
      $pay_method   = trim((string)($_POST['pay_method'] ?? 'efectivo'));
      $installments = max(1,(int)($_POST['installments'] ?? 1));
      $disc_pct     = max(0,(float)($_POST['disc_pct'] ?? 0));
      $disc_abs     = max(0,(float)($_POST['disc_abs'] ?? 0));
      $fee_pct      = max(0,(float)($_POST['fee_pct'] ?? 0));
      $fee_abs      = max(0,(float)($_POST['fee_abs'] ?? 0));

      $extra_discount = round(($orig_total * ($disc_pct/100)) + $disc_abs, 2);
      $extra_fee      = round(($orig_total * ($fee_pct/100)) + $fee_abs, 2);
      $final_total    = round(max(0, $orig_total - $extra_discount + $extra_fee), 2);

      $upd = [
        'payment_method' => $pay_method,
        'installments'   => $installments,
        'total'          => $final_total,
        'paid_at'        => date('Y-m-d H:i:s')
      ];

      // sumar descuentos/recargos si existen
      if (hascol('sales','discount')) { $upd['discount'] = (float)($row['discount'] ?? 0) + $extra_discount; }
      if (hascol('sales','fee'))      { $upd['fee']      = (float)($row['fee'] ?? 0)      + $extra_fee; }

      $conexion->begin_transaction();
      update_sales_columns($sale_id, $upd);
      safe_update_status($sale_id, ['done','paid','pagado','completed','completada']);
      close_reservations_without_restock($sale_id);
      $conexion->commit();

      $okMsg = "✅ Venta #$sale_id confirmada. Total original $ ".money($orig_total)
             ." → total final $ ".money($final_total)
             .($extra_discount>0 ? " (descuento $ ".money($extra_discount).")" : "")
             .($extra_fee>0 ? " (recargo $ ".money($extra_fee).")" : "");

    } catch(Throwable $e){
      if ($conexion && $conexion instanceof mysqli) { @$conexion->rollback(); }
      $errMsg = '❌ '.$e->getMessage();
    }
  }

  if ($act==='cancel_online') {
    try{
      $sale_id = max(0,(int)($_POST['sale_id'] ?? 0));
      if ($sale_id<=0) throw new Exception('Venta inválida');
      $conexion->begin_transaction();
      $done = restock_from_reservations($sale_id);
      if (!$done) { restock_from_sale_items($sale_id); }
      safe_update_status($sale_id, ['cancelled','canceled','anulada','rejected','rechazada','expired','expirada']);
      $conexion->commit();
      $okMsg = "↩️ Venta #$sale_id cancelada y stock devuelto.";
    } catch(Throwable $e){
      if ($conexion && $conexion instanceof mysqli) { @$conexion->rollback(); }
      $errMsg = '❌ '.$e->getMessage();
    }
  }
}

/* ===== Guardar venta POS (local) ===== */
if ($db_ok && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && (($_POST['__action'] ?? '')==='create_sale')) {
  try {
    if (!t_exists('sales')) {
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
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'done',
        origin VARCHAR(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!t_exists('sale_items')) {
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
    }

    $customer     = trim((string)($_POST['customer'] ?? ''));
    $payment      = trim((string)($_POST['payment_method'] ?? 'efectivo'));
    if (!isset($PAY_METHODS[$payment])) $payment = 'efectivo';
    $installments = max(1, (int)($_POST['installments'] ?? 1));
    $discount_pct = (int)($_POST['discount_pct'] ?? 0);
    if (!in_array($discount_pct,$DISCOUNT_OPTIONS,true)) $discount_pct = 0;

    $skus   = $_POST['item_sku']   ?? [];
    $qtys   = $_POST['item_qty']   ?? [];
    $prices = $_POST['item_price'] ?? [];
    $items  = [];
    $subtotal = 0.0;

    for ($i=0; $i<count($skus); $i++) {
      $sku = trim((string)($skus[$i] ?? ''));
      $qty = max(0, (int)($qtys[$i] ?? 0));
      $priceIn = (float)($prices[$i] ?? 0);
      if ($sku==='' || $qty<=0) continue;

      $pid=0; $vid=0; $name='Item'; $price=0.0;

      if (t_exists('product_variants') && t_exists('products')) {
        $sql = "SELECT v.id AS vid,v.price AS vprice,p.id AS pid,p.name AS pname
                FROM product_variants v
                JOIN products p ON p.id=v.product_id
                WHERE v.sku=? LIMIT 1";
        if ($st=$conexion->prepare($sql)) {
          $st->bind_param('s',$sku);
          $st->execute();
          $res=$st->get_result();
          if ($r=$res->fetch_assoc()) {
            $pid=(int)$r['pid']; $vid=(int)$r['vid']; $name=(string)$r['pname']; $price=(float)($r['vprice']??0);
          }
          $st->close();
        }
      }
      if ($pid===0 && t_exists('products')) {
        $sql="SELECT id,name,price FROM products WHERE sku=? LIMIT 1";
        if ($st=$conexion->prepare($sql)) {
          $st->bind_param('s',$sku);
          $st->execute();
          $res=$st->get_result();
          if ($r=$res->fetch_assoc()) {
            $pid=(int)$r['id']; $name=(string)$r['name']; $price=(float)($r['price']??0);
          }
          $st->close();
        }
      }
      if ($pid===0) { $name = $sku; }
      if ($priceIn > 0) $price = $priceIn;

      $line = $price * $qty;
      $subtotal += $line;
      $items[] = ['pid'=>$pid,'vid'=>$vid,'name'=>$name,'qty'=>$qty,'price'=>$price,'line_total'=>$line];
    }

    if (!$items) { throw new Exception('Agregá al menos un ítem.'); }

    $discount = round($subtotal * ($discount_pct/100), 2);
    $fee = 0.0;
    $total = max(0.0, $subtotal - $discount + $fee);

    $conexion->begin_transaction();

    $sql = "INSERT INTO sales (customer_name, customer_phone, customer_email, payment_method, installments, subtotal, discount, fee, total, status, origin)
            VALUES (?,?,?,?,?,?,?,?,?, 'done', 'pos')";
    $st = $conexion->prepare($sql);
    if (!$st) throw new Exception('SQL PREPARE sales: '.$conexion->error);
    $empty = '';
    $st->bind_param('ssssidddd', $customer, $empty, $empty, $payment, $installments, $subtotal, $discount, $fee, $total);
    $st->execute();
    $sale_id = (int)$st->insert_id;
    $st->close();

    $sqlI = "INSERT INTO sale_items (sale_id, product_id, variant_id, name, qty, price_unit, line_total) VALUES (?,?,?,?,?,?,?)";
    $sti = $conexion->prepare($sqlI);
    if (!$sti) throw new Exception('SQL PREPARE sale_items: '.$conexion->error);

    foreach ($items as $it) {
      $sti->bind_param('iiisidd', $sale_id, (int)$it['pid'], (int)$it['vid'], $it['name'], (int)$it['qty'], (float)$it['price'], (float)$it['line_total']);
      $sti->execute();
      adjust_stock((int)$it['pid'], (int)$it['vid'], - (int)$it['qty']);
    }
    $sti->close();

    $conexion->commit();
    $okMsg = "✅ Venta POS #$sale_id guardada. Total $ ".money($total);

  } catch (Throwable $e) {
    if ($conexion && $conexion instanceof mysqli) { @$conexion->rollback(); }
    $errMsg = '❌ '.$e->getMessage();
  }
}

/* ===== Pendientes ONLINE ===== */
$pending = [];
if ($db_ok && t_exists('sales')) {
  $cond = [];
  if (hascol('sales','origin')) {
    $cond[] = "origin='online'";
  } elseif (hascol('sales','shipping_method')) {
    $cond[] = "(shipping_method IS NOT NULL AND shipping_method<>'')";
  } elseif (hascol('sales','customer_email')) {
    $cond[] = "(customer_email IS NOT NULL AND customer_email<>'')";
  } else {
    $cond[] = "1=1";
  }
  $finalStates = ["'done'","'paid'","'pagado'","'completed'","'completada'","'cancelled'","'canceled'","'anulada'","'rejected'","'rechazada'","'expired'","'expirada'"];
  if (hascol('sales','status')) {
    $cond[] = "(status IS NULL OR status='' OR status NOT IN(".implode(',',$finalStates)."))";
  } elseif (hascol('sales','created_at')) {
    $cond[] = "created_at >= NOW() - INTERVAL 7 DAY";
  }
  if (hascol('sales','paid_at')) {
    $cond[] = "(paid_at IS NULL)";
  }
  $order = hascol('sales','created_at') ? "ORDER BY created_at DESC" : "ORDER BY id DESC";
  $sql   = "SELECT * FROM sales WHERE ".implode(' AND ',$cond)." $order LIMIT 100";
  if ($rs=@$conexion->query($sql)) {
    while($r=$rs->fetch_assoc()) $pending[]=$r;
  }
}

/* ===== Helper de fila ===== */
function row_get($row, $keys, $default=''){
  foreach ($keys as $k) if (array_key_exists($k,$row) && $row[$k]!==null && $row[$k]!=='') return $row[$k];
  return $default;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Ventas</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
  <style>
    .btn{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring,#2d323d);border-radius:.6rem;background:transparent;color:inherit;text-decoration:none;cursor:pointer}
    .btn[disabled]{opacity:.6;cursor:not-allowed}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--ring,#2d323d);padding:8px;text-align:left;vertical-align:top}
    .row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
    @media (max-width:900px){ .row{grid-template-columns:1fr} }
    input,select{width:100%;padding:.4rem;border:1px solid var(--ring,#2d323d);background:transparent;color:var(--fg);border-radius:.5rem}
    .mini input{width:80px}
    .mini select{width:120px}
    .tot{display:flex;justify-content:space-between;margin-top:8px}
    .alert{background:#2a1b1b;border:1px solid #7f1d1d;color:#fecaca;border-radius:8px;padding:10px;margin:10px 0}
    .ok{background:#1b2a1d;border:1px solid #14532d;color:#bbf7d0;border-radius:8px;padding:10px;margin:10px 0}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;padding:12px}
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
  </style>
</head>
<body>

<?php if (file_exists($root.'/includes/header.php')) require $root.'/includes/header.php'; ?>

<?php page_head('Ventas', 'Confirma pagos online (con descuento/recargo) y registra ventas en el local.'); ?>

<main class="container">

  <?php if($okMsg): ?><div class="ok"><?=h($okMsg)?></div><?php endif; ?>
  <?php if($errMsg): ?><div class="alert"><?=h($errMsg)?></div><?php endif; ?>

  <!-- ===== Pendientes online ===== -->
  <section class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">🛒 Pendientes online para confirmar</h2>
    <?php if (!$db_ok): ?>
      <div class="alert">No hay conexión a la base de datos.</div>
    <?php elseif (!t_exists('sales')): ?>
      <div>No existe la tabla <code>sales</code>.</div>
    <?php elseif (!$pending): ?>
      <div style="opacity:.9">Sin pendientes online detectados.</div>
    <?php else: ?>
      <div style="overflow:auto">
        <table class="table">
          <thead>
            <tr>
              <th>ID / Fecha</th>
              <th>Cliente / Pago</th>
              <th>Tipo / Total</th>
              <th style="min-width:340px">Confirmar pago (método, descuentos/recargos)</th>
              <th>Cancelar</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($pending as $s): ?>
              <?php
                $sid   = (int)($s['id'] ?? 0);
                $fecha = h(row_get($s,['created_at','fecha'],''));
                $cli   = h(row_get($s,['customer_name','name','buyer_name','cliente'],'(sin nombre)'));
                $pay   = h(row_get($s,['payment_method','method','metodo'],''));
                $ship  = h(row_get($s,['shipping_method','delivery_method','tipo_envio'],''));
                $total_val = 0.0;
                foreach (['total','grand_total','amount','monto','importe','total_amount','total_final'] as $k)
                  if (isset($s[$k]) && is_numeric($s[$k])) { $total_val=(float)$s[$k]; break; }
                if ($total_val<=0){
                  $subtotal = (float)($s['subtotal'] ?? 0);
                  $discount = (float)($s['discount'] ?? ($s['descuento'] ?? 0));
                  $fee      = (float)($s['fee'] ?? ($s['recargo'] ?? 0));
                  $shipc    = (float)($s['shipping_cost'] ?? ($s['delivery_cost'] ?? ($s['costo_envio'] ?? 0)));
                  $total_val = max(0,$subtotal - $discount + $fee + $shipc);
                }
              ?>
              <tr>
                <td><b>#<?= $sid ?></b><br><small><?= $fecha ?></small></td>
                <td><?= $cli ?><br><small><?= $pay?:'—' ?></small></td>
                <td><?= $ship?:'—' ?><br><b>$ <?= money($total_val) ?></b></td>
                <td class="mini">
                  <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end">
                    <input type="hidden" name="__action" value="mark_online_paid">
                    <input type="hidden" name="sale_id" value="<?= $sid ?>">

                    <label style="display:flex;flex-direction:column">
                      <span>Método</span>
                      <select name="pay_method">
                        <?php foreach($PAY_METHODS as $k=>$v): ?>
                          <option value="<?=$k?>" <?= ($pay===$k?'selected':'') ?>><?=$v?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>

                    <label style="display:flex;flex-direction:column">
                      <span>Cuotas</span>
                      <input type="number" name="installments" min="1" value="<?= (int)row_get($s,['installments','cuotas'],1) ?>">
                    </label>

                    <label style="display:flex;flex-direction:column">
                      <span>Desc. %</span>
                      <input type="number" step="0.01" min="0" name="disc_pct" placeholder="0">
                    </label>

                    <label style="display:flex;flex-direction:column">
                      <span>Desc. $</span>
                      <input type="number" step="0.01" min="0" name="disc_abs" placeholder="0.00">
                    </label>

                    <label style="display:flex;flex-direction:column">
                      <span>Rec. %</span>
                      <input type="number" step="0.01" min="0" name="fee_pct" placeholder="0">
                    </label>

                    <label style="display:flex;flex-direction:column">
                      <span>Rec. $</span>
                      <input type="number" step="0.01" min="0" name="fee_abs" placeholder="0.00">
                    </label>

                    <button class="btn">✅ Confirmar pago</button>
                  </form>
                  <small style="opacity:.8">Se toma el total registrado (arriba) y se aplican estos descuentos/recargos para calcular el total final.</small>
                </td>
                <td>
                  <form method="post" onsubmit="return confirm('¿Cancelar esta venta y devolver stock?');">
                    <input type="hidden" name="__action" value="cancel_online">
                    <input type="hidden" name="sale_id" value="<?= $sid ?>">
                    <button class="btn">↩️ Cancelar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- ===== POS (local) ===== -->
  <h2>➕ Nueva venta (Local)</h2>
  <form class="card" method="post">
    <input type="hidden" name="__action" value="create_sale">

    <div class="row">
      <label>Fecha (informativa)
        <input class="input" type="datetime-local" name="sold_at" value="<?=date('Y-m-d\TH:i')?>">
      </label>
      <label>Cliente
        <input class="input" name="customer" placeholder="Opcional">
      </label>
      <label>Método de pago
        <select class="input" name="payment_method" required>
          <?php foreach($PAY_METHODS as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Cuotas (si aplica)
        <input class="input" type="number" name="installments" min="1" value="1">
      </label>
    </div>

    <div class="row">
      <label>Comprobante
        <input class="input" name="receipt" placeholder="Opcional">
      </label>
      <label>Notas
        <input class="input" name="notes" placeholder="Opcional">
      </label>
      <label>Descuento POS
        <select class="input" name="discount_pct">
          <?php foreach($DISCOUNT_OPTIONS as $d): ?><option value="<?=$d?>"><?=$d?>%</option><?php endforeach; ?>
        </select>
      </label>
      <div></div>
    </div>

    <h3>Ítems</h3>
    <table class="table" id="items">
      <thead><tr><th style="width:36%">SKU</th><th>Cant.</th><th>Unit. $</th><th>Nombre</th><th></th></tr></thead>
      <tbody></tbody>
    </table>
    <div style="margin:8px 0">
      <button type="button" class="btn" id="addRow">➕ Agregar ítem</button>
    </div>

    <div class="tot"><span>Subtotal:</span><b id="t_sub">$ 0,00</b></div>
    <div class="tot"><span>Descuento:</span><b id="t_disc">$ 0,00</b></div>
    <div class="tot"><span>Total:</span><b id="t_tot">$ 0,00</b></div>

    <div style="text-align:right;margin-top:10px">
      <button type="submit" class="btn">💾 Guardar venta</button>
    </div>
  </form>
</main>

<?php if (file_exists($root.'/includes/footer.php')) require $root.'/includes/footer.php'; ?>

<script>
(function(){
  const tbody = document.querySelector('#items tbody');
  const addBtn = document.getElementById('addRow');
  const discountSel = document.querySelector('select[name="discount_pct"]');
  const fmt = n => new Intl.NumberFormat('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n||0);

  function recalc(){
    let subtotal=0;
    tbody.querySelectorAll('tr').forEach(tr=>{
      const q = parseFloat(tr.querySelector('input[name="item_qty[]"]').value||'0');
      const p = parseFloat(tr.querySelector('input[name="item_price[]"]').value||'0');
      subtotal += q*p;
    });
    const discPct = parseFloat(discountSel.value||'0');
    const disc = subtotal * (discPct/100);
    const total = Math.max(0, subtotal - disc);
    document.getElementById('t_sub').textContent = '$ '+fmt(subtotal);
    document.getElementById('t_disc').textContent= '$ '+fmt(disc);
    document.getElementById('t_tot').textContent = '$ '+fmt(total);
  }

  function addRow(sku='', qty=1, price=0, name=''){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <input class="input" name="item_sku[]" placeholder="SKU" value="${sku}">
        <small style="opacity:.8">Tip: escaneá o escribí SKU y presioná 🔍</small>
      </td>
      <td><input class="input" type="number" min="1" name="item_qty[]" value="${qty}"></td>
      <td><input class="input" type="number" step="0.01" min="0" name="item_price[]" value="${price}"></td>
      <td><input class="input" name="item_name_display[]" value="${name}" placeholder="(se completa al buscar)"></td>
      <td style="white-space:nowrap">
        <button type="button" class="btn find">🔍</button>
        <button type="button" class="btn del">🗑</button>
      </td>
    `;
    tbody.appendChild(tr);

    tr.querySelectorAll('input').forEach(i=>i.addEventListener('input', recalc));
    tr.querySelector('.del').addEventListener('click', ()=>{ tr.remove(); recalc(); });
    tr.querySelector('.find').addEventListener('click', async ()=>{
      const skuIn = tr.querySelector('input[name="item_sku[]"]').value.trim();
      if (!skuIn) return;
      try{
        const res = await fetch('<?=url("ventas.php")?>?__ajax=find_sku&sku='+encodeURIComponent(skuIn));
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'No encontrado');
        tr.querySelector('input[name="item_name_display[]"]').value = data.name || '';
        if (parseFloat(tr.querySelector('input[name="item_price[]"]').value||'0')<=0) {
          tr.querySelector('input[name="item_price[]"]').value = (data.price||0);
        }
        recalc();
      }catch(e){ alert(e.message); }
    });

    recalc();
  }

  addBtn.addEventListener('click', ()=>addRow());
  discountSel.addEventListener('change', recalc);
  addRow(); // fila inicial
})();
</script>
</body>
</html>
