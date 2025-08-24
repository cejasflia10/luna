<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; require_admin(); include __DIR__.'/../includes/header.php';
$act = $_GET['act'] ?? '';
if ($act==='save' && is_post()){
$id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $sku=trim($_POST['sku']??''); $price=(float)($_POST['price']??0); $cost=(float)($_POST['cost']??0); $desc=trim($_POST['description']??''); $active=isset($_POST['active'])?1:0;
if($id){ $st=$conexion->prepare('UPDATE products SET name=?, sku=?, price=?, cost=?, description=?, active=? WHERE id=?'); $st->bind_param('ssddssi',$name,$sku,$price,$cost,$desc,$active,$id); }
else { $st=$conexion->prepare('INSERT INTO products (name,sku,price,cost,description,active) VALUES (?,?,?,?,?,?)'); $st->bind_param('ssddsi',$name,$sku,$price,$cost,$desc,$active); }
$st->execute(); if(!$id) $id=$st->insert_id;
// Imagen opcional (URL por ahora; para local subir a /uploads o usar Cloudinary)
if(($img=trim($_POST['image_url']??''))!==''){ $si=$conexion->prepare('INSERT INTO product_images (product_id,url) VALUES (?,?)'); $si->bind_param('is',$id,$img); $si->execute(); }
// Variante opcional
if(($t=trim($_POST['talla']??''))!==''){ $sv=$conexion->prepare('INSERT INTO product_variants (product_id,talla,color,stock) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE stock=VALUES(stock)'); $sv->bind_param('isss',$id,$t,$_POST['color']??'',$_POST['stock']??0); $sv->execute(); }
echo ok('Guardado');
}
if ($act==='del'){
$id=(int)($_GET['id']??0); $conexion->query('DELETE FROM products WHERE id='.$id); echo ok('Eliminado');
}
$rows = $conexion->query('SELECT p.id,p.name,p.sku,p.price,p.active,COALESCE(MIN(pi.url),"") img FROM products p LEFT JOIN product_images pi ON pi.product_id=p.id GROUP BY p.id ORDER BY p.id DESC');
?>
<h1>Productos</h1>
<form method="post" action="?act=save" class="card p">
<input type="hidden" name="id" value="<?= (int)($_GET['id']??0) ?>">
<label>Nombre</label><input class="input" name="name" required>
<label>SKU</label><input class="input" name="sku" required>
<div class="row"><div>
<label>Precio</label><input class="input" name="price" type="number" step="0.01" required>
</div><div>
<label>Costo</label><input class="input" name="cost" type="number" step="0.01" required>
</div></div>
<label>Descripción</label><textarea class="input" name="description" rows="4"></textarea>
<label>Imagen (URL)</label><input class="input" name="image_url" placeholder="https://...">
<div class="row"><div>
<label>Talle</label><input class="input" name="talla" placeholder="S, M, L...">
</div><div>
<label>Color</label><input class="input" name="color" placeholder="Negro, Azul...">
</div><div>
<label>Stock</label><input class="input" name="stock" type="number" value="0">
</div></div>
<label><input type="checkbox" name="active" checked> Activo</label>
<p><button class="btn" type="submit">Guardar</button></p>
</form>


<h2>Listado</h2>
<div class="grid">
<?php while($r=$rows->fetch_assoc()): ?>
<article class="card">
<img src="<?=h($r['img']?:'/public/assets/placeholder.jpg')?>">
<div class="p">
<strong><?=h($r['name'])?></strong><br>
SKU: <?=h($r['sku'])?> · <?=h($r['active']?'Activo':'Inactivo')?>
<div class="price"><?=money($r['price'])?></div>
<p>
<a class="btn secondary" href="?id=<?=$r['id']?>">Editar</a>
<a class="btn secondary" href="?act=del&id=<?=$r['id']?>" onclick="return confirm('¿Eliminar?')">Eliminar</a>
</p>
</div>
</article>
<?php endwhile; ?>
</div>
<?php include __DIR__.'/../includes/footer.php'; ?>