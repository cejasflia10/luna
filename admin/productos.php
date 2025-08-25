<?php
$rows = $conexion->query("SELECT p.id,p.name,p.image_url, v.id AS vid, v.sku, v.size, v.color, v.measure_text, v.price, v.stock
FROM products p LEFT JOIN product_variants v ON v.product_id=p.id
WHERE p.active=1 ORDER BY p.created_at DESC, p.id DESC LIMIT 100");
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Productos</title>
<link rel="stylesheet" href="/assets/css/styles.css">
</head><body>
<div class="nav"><div class="row container">
<a class="brand" href="/">Luna<span class="dot">•</span>Shop</a>
<div style="flex:1"></div>
<a href="/compras.php">Compras</a>
<a href="/ventas.php">Ventas</a>
<a href="/reportes.php">Reportes</a>
</div></div>


<main class="container">
<h2>➕ Nuevo producto</h2>
<?php if($okMsg): ?><div class="kpi"><div class="box"><b>OK</b><?=$okMsg?></div></div><?php endif; ?>
<?php if($errMsg): ?><div class="kpi"><div class="box"><b>Error</b><?=$errMsg?></div></div><?php endif; ?>


<form method="post" class="card" style="padding:14px">
<input type="hidden" name="__action" value="create_product">
<div class="row">
<label>Nombre <input class="input" name="name" required></label>
<label>Precio sugerido ($) <input class="input" name="price" type="number" step="0.01" min="0" value="0"></label>
<label>Imagen (URL) <input class="input" name="image_url" placeholder="https://..." ></label>
<label>Descripción <input class="input" name="description"></label>
</div>
<h3>Variante inicial</h3>
<div class="row">
<label>SKU <input class="input" name="sku" ></label>
<label>Talle <input class="input" name="size" placeholder="S / M / L ..."></label>
<label>Color <input class="input" name="color" ></label>
<label>Medidas <input class="input" name="measure_text" placeholder="Ancho x Alto ..."></label>
<label>Stock inicial <input class="input" name="stock" type="number" min="0" value="0"></label>
<label>Costo promedio inicial ($) <input class="input" name="avg_cost" type="number" step="0.01" min="0" value="0"></label>
</div>
<button type="submit">Guardar</button>
</form>


<h2 style="margin-top:20px">Listado</h2>
<table class="table">
<thead><tr><th>Producto</th><th>Variante</th><th>Precio</th><th>Stock</th></tr></thead>
<tbody>
<?php while($r=$rows->fetch_assoc()): ?>
<tr>
<td><b><?=h($r['name'])?></b></td>
<td><?=h(trim(($r['sku']?('#'.$r['sku'].' '):'').' '.($r['size']?:'').' '.($r['color']?:'').' '.($r['measure_text']?:'')))?></td>
<td>$ <?=money($r['price'])?></td>
<td><?= (int)$r['stock'] ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</main>
</body></html>