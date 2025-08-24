<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; require_admin(); include __DIR__.'/../includes/header.php';
$ym = $_GET['ym'] ?? date('Y-m');
$st = $conexion->prepare("SELECT
IFNULL(SUM(s.total_paid),0) total_ventas,
IFNULL(SUM(si.cost*si.qty),0) costo_vendido,
IFNULL(SUM(s.fees),0) comisiones
FROM sales s JOIN sale_items si ON si.sale_id=s.id
WHERE s.status='paid' AND DATE_FORMAT(s.created_at,'%Y-%m')=?");
$st->bind_param('s',$ym); $st->execute(); $m = $st->get_result()->fetch_assoc();
$gan = (float)$m['total_ventas'] - (float)$m['costo_vendido'] - (float)$m['comisiones'];
?>
<h1>Balance</h1>
<form>
<label>Mes (YYYY-MM)</label><input class="input" name="ym" value="<?=h($ym)?>">
<p><button class="btn" type="submit">Ver</button></p>
</form>
<div class="grid">
<div class="card p"><h3>Ventas</h3><div class="price"><?=money($m['total_ventas'])?></div></div>
<div class="card p"><h3>Costo vendido</h3><div class="price"><?=money($m['costo_vendido'])?></div></div>
<div class="card p"><h3>Comisiones</h3><div class="price"><?=money($m['comisiones'])?></div></div>
<div class="card p"><h3>Ganancia</h3><div class="price"><?=money($gan)?></div></div>
</div>
<?php include __DIR__.'/../includes/footer.php'; ?>