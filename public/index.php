<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require dirname(__DIR__).'/includes/conn.php';
require dirname(__DIR__).'/includes/helpers.php';


$prods = $conexion->query("SELECT p.id,p.name,p.image_url, COALESCE(MIN(v.price),0) as min_price
FROM products p LEFT JOIN product_variants v ON v.product_id=p.id
WHERE p.active=1 GROUP BY p.id ORDER BY p.created_at DESC LIMIT 12");
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Luna Shop — Inicio</title>
<link rel="stylesheet" href="/assets/css/styles.css">
</head><body>
<div class="nav"><div class="row container">
<div class="brand">Luna<span class="dot">•</span>Shop</div>
<div style="flex:1"></div>
<a href="/productos.php">Productos</a>
<a href="/compras.php">Compras</a>
<a href="/ventas.php">Ventas</a>
<a href="/reportes.php">Reportes</a>
</div></div>


<header class="hero">
<div class="container">
<h1>Moda que inspira. Gestión simple.</h1>
<p>Cargá productos, controlá stock, vendé online o presencial y mirá tus ganancias en segundos.</p>
<a class="cta" href="/productos.php">➕ Cargar producto</a>
</div>
</header>


<main class="container">
<h2>Novedades</h2>
<div class="grid">
<?php while($p=$prods->fetch_assoc()): ?>
<div class="card">
<img src="<?=h($p['image_url']?:'https://picsum.photos/640/480?random='.((int)$p['id']))?>" alt="<?=h($p['name'])?>">
<div class="p">
<h3><?=h($p['name'])?></h3>
<span class="badge">Desde $ <?=money($p['min_price'])?></span>
</div>
</div>
<?php endwhile; ?>
</div>
</main>
</body></html>