<?php
$stmt=$conexion->prepare("SELECT COALESCE(SUM(total),0) FROM purchases WHERE DATE(purchased_at) BETWEEN ? AND ?");
$stmt->bind_param('ss',$from,$to); $stmt->execute(); $stmt->bind_result($compras_total); $stmt->fetch(); $stmt->close();


$ganancia_bruta = $ventas_cobradas - $costo_ventas;
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reportes</title>
<link rel="stylesheet" href="/assets/css/styles.css">
</head><body>
<div class="nav"><div class="row container">
<a class="brand" href="/">Luna<span class="dot">•</span>Shop</a>
<div style="flex:1"></div>
<a href="/productos.php">Productos</a>
<a href="/compras.php">Compras</a>
<a href="/ventas.php">Ventas</a>
</div></div>
<main class="container">
<h2>📈 Balance de ganancias</h2>
<form method="get" class="card" style="padding:12px;margin-bottom:12px">
<div class="row">
<label>Desde <input class="input" type="date" name="from" value="<?=h($from)?>"></label>
<label>Hasta <input class="input" type="date" name="to" value="<?=h($to)?>"></label>
</div>
<button type="submit">Actualizar</button>
</form>


<div class="kpi">
<div class="box"><b>Ventas cobradas</b>$ <?=money($ventas_cobradas)?></div>
<div class="box"><b>Costo de ventas</b>$ <?=money($costo_ventas)?></div>
<div class="box"><b>Ganancia bruta</b>$ <?=money($ganancia_bruta)?></div>
<div class="box"><b>Compras (período)</b>$ <?=money($compras_total)?></div>
</div>


<h3>TOP productos vendidos (unidades)</h3>
<table class="table">
<thead><tr><th>Producto</th><th>Variante</th><th>Unidades</th><th>Ingresos</th></tr></thead>
<tbody>
<?php
$q = $conexion->prepare("SELECT p.name, v.size, v.color, v.measure_text, SUM(si.quantity) u, SUM(si.subtotal) r
FROM sale_items si JOIN product_variants v ON v.id=si.variant_id
JOIN products p ON p.id=v.product_id JOIN sales s ON s.id=si.sale_id
WHERE DATE(s.sold_at) BETWEEN ? AND ? AND s.status='paid'
GROUP BY p.name, v.size, v.color, v.measure_text ORDER BY u DESC LIMIT 10");
$q->bind_param('ss',$from,$to); $q->execute(); $res=$q->get_result();
while($r=$res->fetch_assoc()): ?>
<tr>
<td><?=h($r['name'])?></td>
<td><?=h(trim(($r['size']?:'').' '.($r['color']?:'').' '.($r['measure_text']?:'')))?></td>
<td><?= (int)$r['u'] ?></td>
<td>$ <?= money($r['r']) ?></td>
</tr>
<?php endwhile; $q->close(); ?>
</tbody>
</table>
</main>
</body></html>