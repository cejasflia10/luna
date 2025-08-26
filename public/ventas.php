<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';
require $root.'/includes/page_head.php';

/* ===== Helpers ===== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ===== Rutas ===== */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if (!function_exists('url')) {
  function url($p){ global $BASE; return rtrim($BASE,'/').'/'.ltrim((string)$p,'/'); }
}

/* ===== Estado DB ===== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

/* ===== Utilidades DB ===== */
function t_exists($t){ global $conexion; $r=@$conexion->query("SHOW TABLES LIKE '". $conexion->real_escape_string($t) ."'"); return ($r && $r->num_rows>0); }
function hascol($t,$c){ global $conexion; $r=@$conexion->query("SHOW COLUMNS FROM `$t` LIKE '".$conexion->real_escape_string($c)."'"); return ($r && $r->num_rows>0); }

/* ===== AJAX: buscar por SKU ===== */
if (($db_ok) && (($_GET['__ajax'] ?? '')==='find_sku')) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $sku = trim((string)($_GET['sku'] ?? ''));
    if ($sku==='') throw new Exception('SKU vacío');
    $out = ['ok'=>false];

    // 1) Variante por SKU exacto
    $sql = "SELECT v.id AS variant_id, v.price AS vprice, p.id AS product_id, p.name AS pname
            FROM product_variants v
            JOIN products p ON p.id=v.product_id
            WHERE v.sku=? LIMIT 1";
    if ($st = $conexion->prepare($sql)) {
      $st->bind_param('s',$sku);
      $st->execute();
      $res = $st->get_result();
      if ($row = $res->fetch_assoc()) {
        $out = [
          'ok'=>true,
          'product_id'=>(int)$row['product_id'],
          'variant_id'=>(int)$row['variant_id'],
          'name'=>(string)$row['pname'],
          'price'=>(float)($row['vprice'] ?? 0),
          'source'=>'variant'
        ];
      }
      $st->close();
    }

    // 2) Producto por SKU si no hubo variante
    if (!$out['ok']) {
      $sql = "SELECT id, name, price FROM products WHERE sku=? LIMIT 1";
      if ($st = $conexion->prepare($sql)) {
        $st->bind_param('s',$sku);
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) {
          $out = [
            'ok'=>true,
            'product_id'=>(int)$row['id'],
            'variant_id'=>0,
            'name'=>(string)$row['name'],
            'price'=>(float)($row['price'] ?? 0),
            'source'=>'product'
          ];
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

/* ===== Config POS ===== */
$PAY_METHODS = [
  'efectivo'      => 'Efectivo',
  'debito'        => 'Débito',
  'credito'       => 'Crédito',
  'transferencia' => 'Transferencia',
  'mp'            => 'Mercado Pago',
];
$DISCOUNT_OPTIONS = [0,5,10,15,20,25]; // % para POS

/* ===== Guardar venta ===== */
$okMsg=''; $errMsg='';
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_sale') {
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

    $sold_at      = trim((string)($_POST['sold_at'] ?? '')); // informativo (no siempre se guarda si la tabla no tiene)
    $customer     = trim((string)($_POST['customer'] ?? ''));
    $payment      = trim((string)($_POST['payment_method'] ?? 'efectivo'));
    if (!isset($PAY_METHODS[$payment])) $payment = 'efectivo';
    $installments = max(1, (int)($_POST['installments'] ?? 1));
    $receipt      = trim((string)($_POST['receipt'] ?? ''));
    $notes        = trim((string)($_POST['notes'] ?? ''));
    $discount_pct = (int)($_POST['discount_pct'] ?? 0);
    if (!in_array($discount_pct,$DISCOUNT_OPTIONS,true)) $discount_pct = 0;

    // Ítems
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

      // 1) Variante por SKU
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

      // 2) Producto por SKU si no se halló variante
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

      // Si no se encontró nada, uso el SKU como nombre
      if ($pid===0) { $name = $sku; }

      // Precio final de línea (permite override manual)
      if ($priceIn > 0) $price = $priceIn;

      $line = $price * $qty;
      $subtotal += $line;
      $items[] = ['pid'=>$pid,'vid'=>$vid,'name'=>$name,'qty'=>$qty,'price'=>$price,'line_total'=>$line];
    }

    if (!$items) { throw new Exception('Agregá al menos un ítem.'); }

    // Descuento POS
    $discount = round($subtotal * ($discount_pct/100), 2);
    $fee = 0.0; // si querés recargos por cuotas, calcular acá
    $total = max(0.0, $subtotal - $discount + $fee);

    // Guardar venta
    $conexion->begin_transaction();

    $sql = "INSERT INTO sales (customer_name, customer_phone, customer_email, payment_method, installments, subtotal, discount, fee, total, status)
            VALUES (?,?,?,?,?,?,?,?,?, 'done')";
    $st = $conexion->prepare($sql);
    if (!$st) throw new Exception('SQL PREPARE sales: '.$conexion->error);
    $empty = '';
    $st->bind_param('ssssidddd', $customer, $empty, $empty, $payment, $installments, $subtotal, $discount, $fee, $total);
    $st->execute();
    $sale_id = (int)$st->insert_id;
    $st->close();

    // Ítems + stock
    $sqlI = "INSERT INTO sale_items (sale_id, product_id, variant_id, name, qty, price_unit, line_total) VALUES (?,?,?,?,?,?,?)";
    $sti = $conexion->prepare($sqlI);
    if (!$sti) throw new Exception('SQL PREPARE sale_items: '.$conexion->error);

    foreach ($items as $it) {
      $sti->bind_param('iiisidd', $sale_id, (int)$it['pid'], (int)$it['vid'], $it['name'], (int)$it['qty'], (float)$it['price'], (float)$it['line_total']);
      $sti->execute();

      // Restar stock si hay variante
      if ($it['vid']>0 && t_exists('product_variants') && hascol('product_variants','stock')) {
        $upd = $conexion->prepare("UPDATE product_variants SET stock = GREATEST(stock-?,0) WHERE id=?");
        if ($upd) { $upd->bind_param('ii',$it['qty'],$it['vid']); $upd->execute(); $upd->close(); }
      }
    }
    $sti->close();

    $conexion->commit();
    $okMsg = "✅ Venta #$sale_id guardada. Total $ ".money($total);

  } catch (Throwable $e) {
    if ($conexion && $conexion instanceof mysqli) { @$conexion->rollback(); }
    $errMsg = '❌ '.$e->getMessage();
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Ventas (POS)</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
  <style>
    .btn{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring,#2d323d);border-radius:.6rem;background:transparent;color:inherit;text-decoration:none;cursor:pointer}
    .btn[disabled]{opacity:.6;cursor:not-allowed}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--ring,#2d323d);padding:8px;text-align:left}
    .row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
    @media (max-width:900px){ .row{grid-template-columns:1fr} }
    input,select{width:100%;padding:.5rem;border:1px solid var(--ring,#2d323d);background:transparent;color:var(--fg);border-radius:.5rem}
    .tot{display:flex;justify-content:space-between;margin-top:8px}
    .alert{background:#2a1b1b;border:1px solid #7f1d1d;color:#fecaca;border-radius:8px;padding:10px;margin:10px 0}
    .ok{background:#1b2a1d;border:1px solid #14532d;color:#bbf7d0;border-radius:8px;padding:10px;margin:10px 0}
  </style>
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php
page_head('Ventas (Local)', 'Registra ventas en el local con descuento opcional.');
?>

<main class="container">
  <?php if($okMsg): ?><div class="ok"><?=h($okMsg)?></div><?php endif; ?>
  <?php if($errMsg): ?><div class="alert"><?=h($errMsg)?></div><?php endif; ?>

  <h2>➕ Nueva venta</h2>
  <form class="card" style="padding:14px" method="post">
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
          <?php foreach($PAY_METHODS as $k=>$v): ?>
            <option value="<?=$k?>"><?=$v?></option>
          <?php endforeach; ?>
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
          <?php foreach($DISCOUNT_OPTIONS as $d): ?>
            <option value="<?=$d?>"><?=$d?>%</option>
          <?php endforeach; ?>
        </select>
      </label>
      <div></div>
    </div>

    <h3>Ítems</h3>
    <table class="table" id="items">
      <thead>
        <tr><th style="width:36%">SKU</th><th>Cant.</th><th>Unit. $</th><th>Nombre</th><th></th></tr>
      </thead>
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

<?php require $root.'/includes/footer.php'; ?>

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

  // fila inicial
  addRow();
})();
</script>
</body>
</html>
