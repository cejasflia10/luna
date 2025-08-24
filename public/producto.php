<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; include __DIR__.'/../includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$st = $conexion->prepare('SELECT id,name,description,price FROM products WHERE id=? AND active=1');
$st->bind_param('i',$id); $st->execute(); $p = $st->get_result()->fetch_assoc();
if(!$p){ echo err('Producto no encontrado'); include __DIR__.'/../includes/footer.php'; exit; }
$imgs = $conexion->query("SELECT url FROM product_images WHERE product_id={$id}");
$vars = $conexion->query("SELECT id,talla,color,stock FROM product_variants WHERE product_id={$id} ORDER BY talla");
?>
<div class="row">
<div>
<?php if($imgs->num_rows){ while($i=$imgs->fetch_assoc()){ echo '<img style="border-radius:16px;margin-bottom:10px" src="'.h($i['url']).'">'; } } else { echo '<img src="/public/assets/placeholder.jpg">'; } ?>
</div>
<div>
<h1><?=h($p['name'])?></h1>
<div class="price" style="font-size:22px;"><?=money($p['price'])?></div>
<p><?=nl2br(h($p['description']))?></p>
<form method="post" action="/public/cart.php?action=add">
<input type="hidden" name="product_id" value="<?=$p['id']?>">
<label>Variante (talle/color)</label>
<select name="variant_id" required>
<option value="">Elegí una opción</option>
<?php while($v=$vars->fetch_assoc()): $label = trim($v['talla'].' / '.$v['color']); ?>
<option value="<?=$v['id']?>" <?=$v['stock']<=0?'disabled':''?>><?=h($label)?> <?=$v['stock']>0?"(Stock: {$v['stock']})":"(Sin stock)"?></option>
<?php endwhile; ?>
</select>
<label>Cantidad</label>
<input class="input" type="number" name="qty" min="1" value="1" required>
<p><button class="btn" type="submit">Agregar al carrito</button></p>
</form>
</div>
</div>
<?php include __DIR__.'/../includes/footer.php'; ?>