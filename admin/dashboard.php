<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; require_admin(); include __DIR__.'/../includes/header.php';
$kpis = $conexion->query("SELECT
(SELECT IFNULL(SUM(total_paid),0) FROM sales WHERE status='paid' AND DATE(created_at)=CURDATE()) AS hoy,
(SELECT COUNT(*) FROM products WHERE active=1) AS prod,
(SELECT IFNULL(SUM(qty),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='paid' AND DATE(s.created_at)=CURDATE()) AS items
")->fetch_assoc();
?>
<h1>Panel</h1>
<div class="grid">
<div class="card p"><h3>Ventas hoy</h3><div class="price"><?=money($kpis['hoy'])?></div></div>
<div class="card p"><h3>Productos activos</h3><div class="price"><?=h($kpis['prod'])?></div></div>
<div class="card p"><h3>Ítems vendidos hoy</h3><div class="price"><?=h($kpis['items'])?></div></div>
</div>
<p><a class="btn" href="/admin/productos.php">Productos</a> <a class="btn" href="/admin/compras.php">Compras</a> <a class="btn" href="/admin/pos.php">POS</a> <a class="btn" href="/admin/balance.php">Balance</a> <a class="btn secondary" href="/admin/ajustes.php">Ajustes</a> <a class="btn secondary" href="/admin/logout.php">Salir</a></p>
<?php include __DIR__.'/../includes/footer.php'; ?>