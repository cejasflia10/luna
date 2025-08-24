<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php';
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$action = $_GET['action'] ?? '';
if ($action==='add' && is_post()){
$pid = (int)($_POST['product_id']??0);
$vid = (int)($_POST['variant_id']??0);
$qty = max(1,(int)($_POST['qty']??1));
$st = $conexion->prepare('SELECT p.id,p.name,p.price,v.id vid FROM products p JOIN product_variants v ON v.product_id=p.id WHERE p.id=? AND v.id=? AND p.active=1');
$st->bind_param('ii',$pid,$vid); $st->execute(); $row=$st->get_result()->fetch_assoc();
if($row){
$key = $row['id'].'-'.$row['vid'];
if(isset($_SESSION['cart'][$key])) $_SESSION['cart'][$key]['qty'] += $qty;
else $_SESSION['cart'][$key] = ['product_id'=>$row['id'],'variant_id'=>$row['vid'],'name'=>$row['name'],'price'=>$row['price'],'qty'=>$qty];
redirect('/public/cart.php');
}
}
if ($action==='del'){
$k = $_GET['k'] ?? '';
unset($_SESSION['cart'][$k]);
redirect('/public/cart.php');
}
include __DIR__.'/../includes/header.php';
$items = array_values($_SESSION['cart']);
$subtotal = 0; foreach($items as $it){ $subtotal += $it['price']*$it['qty']; }
?>
<h1>Carrito</h1>
<?php if(!$items){ echo '<p>Tu carrito está vacío.</p>'; include __DIR__.'/../includes/footer.php'; exit; } ?>
<table style="width:100%;border-collapse:collapse;">
<tr><th style="text-align:left">Producto</th><th>Cant.</th><th>Precio</th><th>Total</th><th></th></tr>
<?php foreach($_SESSION['cart'] as $k=>$it): $t=$it['price']*$it['qty']; ?>
<tr>
<td><?=h($it['name'])?></td>
<td style="text-align:center"><?=$it['qty']?></td>
<td style="text-align:right"><?=money($it['price'])?></td>
<td style="text-align:right"><?=money($t)?></td>
<td style="text-align:right"><a class="btn secondary" href="/public/cart.php?action=del&k=<?=h($k)?>">Quitar</a></td>
</tr>
<?php endforeach; ?>
</table>
<p style="text-align:right;font-size:20px">Subtotal: <strong><?=money($subtotal)?></strong></p>
<form method="post" action="/public/checkout.php">
<label>Tu nombre</label><input class="input" name="buyer_name" required>
<label>Tu email</label><input class="input" name="buyer_email" type="email" required>
<label>Método de pago</label>
<select name="method" required>
<option value="mercadopago">Mercado Pago (online)</option>
<option value="transferencia">Transferencia</option>
<option value="efectivo">Efectivo al retirar</option>
</select>
<p><button class="btn" type="submit">Ir a pagar</button></p>
</form>
<?php include __DIR__.'/../includes/footer.php'; ?>