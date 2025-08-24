<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; include __DIR__.'/../includes/header.php'; ?>
<h1>¡Bienvenid@ a LUNA!</h1>
<p class="badge">Moda con estilo · Envíos a todo el país</p>
<div class="grid">
<?php
$res = $conexion->query("SELECT p.id, p.name, p.price, COALESCE(MIN(pi.url),'') img FROM products p LEFT JOIN product_images pi ON pi.product_id=p.id WHERE p.active=1 GROUP BY p.id ORDER BY p.id DESC LIMIT 24");
while($row = $res->fetch_assoc()): ?>
<article class="card">
<a href="/public/producto.php?id=<?=$row['id']?>"><img src="<?=h($row['img']?:'/public/assets/placeholder.jpg')?>" alt="<?=h($row['name'])?>"></a>
<div class="p">
<h3><?=h($row['name'])?></h3>
<div class="price"><?=money($row['price'])?></div>
<p><a class="btn" href="/public/producto.php?id=<?=$row['id']?>">Ver</a></p>
</div>
</article>
<?php endwhile; ?>
</div>
<?php include __DIR__.'/../includes/footer.php'; ?>