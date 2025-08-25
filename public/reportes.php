<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* === Rutas / includes === */
$root = dirname(__DIR__);
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';
@require $root.'/includes/page_head.php'; // si no existe, no rompe

/* === Helpers mínimos === */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* === URL base === */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('url')) {
  function url($p){ global $BASE; return $BASE.'/'.ltrim((string)$p,'/'); }
}

/* === Estado DB === */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

/* === Utilidades esquema === */
function table_exists($cx, $name){
  $r = @$cx->query("SHOW TABLES LIKE '". $cx->real_escape_string($name) ."'");
  return ($r && $r->num_rows>0);
}
function col_exists($cx, $table, $col){
  $r = @$cx->query("SHOW COLUMNS FROM `$table` LIKE '". $cx->real_escape_string($col) ."'");
  return ($r && $r->num_rows>0);
}

/* === Asegurar tabla de gastos (expenses) === */
if ($db_ok) {
  @$conexion->query("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    concept VARCHAR(200) NOT NULL,
    category VARCHAR(80) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* === Alta de gasto (POST) === */
$flash_msg = '';
if ($db_ok && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_expense') {
  $concept  = trim((string)($_POST['concept'] ?? ''));
  $category = trim((string)($_POST['category'] ?? ''));
  $amount   = (float)str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
  if ($concept !== '' && $amount > 0) {
    $st = $conexion->prepare("INSERT INTO expenses (concept, category, amount) VALUES (?,?,?)");
    $st->bind_param('ssd', $concept, $category, $amount);
    $st->execute(); $st->close();
    $flash_msg = 'Gasto registrado.';
  } else {
    $flash_msg = 'Completá concepto y monto válido.';
  }
}

/* === Cálculos en vivo === */
$total_sales = 0.0;
$total_purchases = 0.0;
$total_expenses = 0.0;
$tickets = 0;

if ($db_ok) {
  /* Ventas */
  if (table_exists($conexion, 'sales')) {
    if (col_exists($conexion, 'sales', 'total')) {
      $rs = @$conexion->query("SELECT COALESCE(SUM(total),0) s, COUNT(*) c FROM sales");
      if ($rs && ($row=$rs->fetch_assoc())) { $total_sales = (float)$row['s']; $tickets = (int)$row['c']; }
    } elseif (table_exists($conexion, 'sale_items')) {
      $rs = @$conexion->query("SELECT COALESCE(SUM(line_total),0) s FROM sale_items");
      if ($rs && ($row=$rs->fetch_assoc())) { $total_sales = (float)$row['s']; }
      $rc = @$conexion->query("SELECT COUNT(DISTINCT sale_id) c FROM sale_items");
      if ($rc && ($rowc=$rc->fetch_assoc())) { $tickets = (int)$rowc['c']; }
    }
  }

  /* Compras */
  if (table_exists($conexion, 'purchases')) {
    if (col_exists($conexion, 'purchases', 'total')) {
      $rp = @$conexion->query("SELECT COALESCE(SUM(total),0) s FROM purchases");
      if ($rp && ($row=$rp->fetch_assoc())) { $total_purchases = (float)$row['s']; }
    } elseif (table_exists($conexion, 'purchase_items')) {
      if (col_exists($conexion, 'purchase_items','line_total')) {
        $rp = @$conexion->query("SELECT COALESCE(SUM(line_total),0) s FROM purchase_items");
      } else {
        // intentar qty * price_unit
        $rp = @$conexion->query("SELECT COALESCE(SUM(qty * price_unit),0) s FROM purchase_items");
      }
      if ($rp && ($row=$rp->fetch_assoc())) { $total_purchases = (float)$row['s']; }
    }
  }

  /* Gastos */
  if (table_exists($conexion, 'expenses')) {
    $re = @$conexion->query("SELECT COALESCE(SUM(amount),0) s FROM expenses");
    if ($re && ($row=$re->fetch_assoc())) { $total_expenses = (float)$row['s']; }
  }
}

$profit = $total_sales - $total_purchases - $total_expenses;

/* === Últimas ventas (si existe tabla) === */
$ult_ventas = [];
if ($db_ok && table_exists($conexion,'sales')) {
  // traer columnas disponibles de forma tolerante
  $has_created = col_exists($conexion,'sales','created_at');
  $has_method  = col_exists($conexion,'sales','payment_method');
  $has_cust    = col_exists($conexion,'sales','customer_name');
  $has_total   = col_exists($conexion,'sales','total');

  $cols = "id".
          ($has_created? ",created_at":"").
          ($has_method?  ",payment_method":"").
          ($has_cust?    ",customer_name":"").
          ($has_total?   ",total":"");
  $order = $has_created ? "created_at DESC" : "id DESC";
  $rs = @$conexion->query("SELECT $cols FROM sales ORDER BY $order LIMIT 10");
  if ($rs) while ($r=$rs->fetch_assoc()) $ult_ventas[] = $r;
}

/* === Últimos gastos === */
$ult_gastos = [];
if ($db_ok && table_exists($conexion,'expenses')) {
  $rg = @$conexion->query("SELECT id,created_at,concept,category,amount FROM expenses ORDER BY created_at DESC, id DESC LIMIT 10");
  if ($rg) while($g=$rg->fetch_assoc()) $ult_gastos[] = $g;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Reportes</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
  <style>
    .kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:14px 0}
    .kpi .box{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;padding:12px}
    .kpi .box b{display:block;opacity:.85;margin-bottom:4px}
    .right{text-align:right}
    .table-wrap{overflow:auto}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--ring,#2d323d);padding:10px;text-align:left}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width:900px){ .grid2{grid-template-columns:1fr} }
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;padding:12px}
    .cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    .muted{opacity:.8}
    .ok{color:#86efac}
    .warn{color:#facc15}
    .bad{color:#fda4af}
    .flash{margin:8px 0;padding:8px;border:1px solid var(--ring);border-radius:8px}
  </style>
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php if (function_exists('page_head')) { page_head('Reportes', 'Balance en vivo'); } ?>

<main class="container">

  <?php if($flash_msg): ?>
    <div class="flash"><?= h($flash_msg) ?></div>
  <?php endif; ?>

  <!-- KPIs en vivo -->
  <section class="kpi">
    <div class="box">
      <b>Total Ventas (acumulado)</b>
      <div style="font-size:1.1rem">$ <?= money($total_sales) ?></div>
    </div>
    <div class="box">
      <b>Total Compras (acumulado)</b>
      <div style="font-size:1.1rem">$ <?= money($total_purchases) ?></div>
    </div>
    <div class="box">
      <b>Gastos acumulados</b>
      <div style="font-size:1.1rem">$ <?= money($total_expenses) ?></div>
    </div>
    <div class="box">
      <b>Ganancia (ventas − compras − gastos)</b>
      <div style="font-size:1.1rem;<?= $profit>=0?'color:#86efac':'color:#fda4af' ?>">
        $ <?= money($profit) ?>
      </div>
    </div>
    <div class="box">
      <b>Tickets</b>
      <div style="font-size:1.1rem"><?= (int)$tickets ?></div>
    </div>
  </section>

  <div class="grid2">
    <!-- Últimas ventas -->
    <section class="card">
      <h2 style="margin-top:0">Últimas ventas</h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Comprobante</th>
              <th>Método</th>
              <th>Cliente</th>
              <th class="right">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($ult_ventas)): ?>
              <tr><td colspan="5" class="muted">Sin datos aún.</td></tr>
            <?php else: ?>
              <?php foreach ($ult_ventas as $v): ?>
                <tr>
                  <td><?= h($v['created_at'] ?? '-') ?></td>
                  <td>#<?= (int)($v['id'] ?? 0) ?></td>
                  <td><?= h($v['payment_method'] ?? '-') ?></td>
                  <td><?= h($v['customer_name'] ?? '-') ?></td>
                  <td class="right">$ <?= money((float)($v['total'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Cargar gasto + últimos gastos -->
    <section class="card">
      <h2 style="margin-top:0">Gastos del local</h2>

      <form method="post" action="<?= url('reportes.php') ?>" style="display:grid;grid-template-columns:1fr 1fr 140px;gap:8px;align-items:end">
        <input type="hidden" name="action" value="add_expense">
        <div>
          <label>Concepto</label>
          <input name="concept" required placeholder="Alquiler, Luz, Internet..." />
        </div>
        <div>
          <label>Categoría (opcional)</label>
          <input name="category" placeholder="Fijos, Servicios, Impuestos..." />
        </div>
        <div>
          <label>Monto</label>
          <input name="amount" inputmode="decimal" required placeholder="0,00" />
        </div>
        <div style="grid-column: 1 / -1; text-align:right">
          <button class="cta" type="submit">Agregar gasto</button>
        </div>
      </form>

      <h3 style="margin:12px 0 6px">Últimos gastos</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Fecha</th><th>Concepto</th><th>Categoría</th><th class="right">Monto</th></tr></thead>
          <tbody>
            <?php if(empty($ult_gastos)): ?>
              <tr><td colspan="4" class="muted">Sin gastos cargados.</td></tr>
            <?php else: ?>
              <?php foreach($ult_gastos as $g): ?>
                <tr>
                  <td><?= h($g['created_at']) ?></td>
                  <td><?= h($g['concept']) ?></td>
                  <td><?= h($g['category']) ?></td>
                  <td class="right">$ <?= money((float)$g['amount']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <?php if(!$db_ok): ?>
    <div class="card" style="margin-top:12px">
      <b>Nota:</b> No hay conexión a la base de datos; los totales se muestran en 0.
    </div>
  <?php endif; ?>

  <?php if($db_ok && !table_exists($conexion,'purchases')): ?>
    <div class="card" style="margin-top:12px">
      <b>Tip:</b> No encuentro la tabla <code>purchases</code>. Si la vas a cargar luego,
      este reporte la tomará automáticamente. Mientras tanto, <i>Total Compras</i> queda en 0.
    </div>
  <?php endif; ?>

</main>

<?php require $root.'/includes/footer.php'; ?>
</body>
</html>
