<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; require_admin(); include __DIR__.'/../includes/header.php';
if(is_post()){
    require __DIR__ . '/conexion.php'; // te deja $conexion listo

$method=$_POST['method'] ?? 'efectivo'; $buyer=$_POST['buyer'] ?? 'Mostrador';
$st=$conexion->prepare("INSERT INTO sales (channel,status,payment_method,subtotal,total_paid,buyer_name) VALUES ('presencial','paid',?,0,0,?)");
$st->bind_param('ss',$method,$buyer); $st->execute(); $sid=$st->insert_id;
$sti=$conexion->prepare('INSERT INTO sale_items (sale_id,product_id,variant_id,price,cost,qty) VALUES (?,?,?,?,?,?)');
$sum=0; foreach($_POST['items']??[] as $it){ $v=(int)$it['variant_id']; $q=max(1,(int)$it['qty']); $row=$conexion->query('SELECT p.id pid,p.price,p.cost FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id='.$v)->fetch_assoc(); $sum += $row['price']*$q; $sti->bind_param('iiiidi',$sid,$row['pid'],$v,$row['price'],$row['cost'],$q); $sti->execute(); $conexion->query('UPDATE product_variants SET stock=GREATEST(stock-'.$q.',0) WHERE id='.$v); }
$conexion->query('UPDATE sales SET subtotal='.(float)$sum.', total_paid='.(float)$sum.' WHERE id='.$sid);
echo ok('Venta registrada (#'.$sid.')');
}
$vars = $conexion->query('SELECT v.id, CONCAT(p.name," · ",v.talla,"/",v.color) name, v.stock FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.active=1 ORDER BY p.name');
?>
<h1>POS</h1>
<form method="post" class="card p">
<label>Cliente</label><input class="input" name="buyer" placeholder="Consumidor Final">
<div id="items"></div>
<label>Método</label>
<select class="input" name="method"><option value="efectivo">Efectivo</option><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option></select>
<p><button class="btn secondary" type="button" onclick="addRow()">+ Agregar ítem</button> <button class="btn" type="submit">Cerrar venta</button></p>
</form>
<script>
const opts = `<?php while($r=$vars->fetch_assoc()){ echo '<option value="'.$r['id'].'">'.h($r['name']).' (Stock: '.(int)$r['stock'].')</option>'; } ?>`;
function addRow(){ const wrap=document.getElementById('items'); const row=document.createElement('div'); row.innerHTML=`<div class="row"><div><label>Producto</label><select class="input" name="items[][variant_id]" required>${opts}</select></div><div><label>Cant.</label><input class="input" name="items[][qty]" type="number" min="1" value="1" required></div></div>`; wrap.appendChild(row); }
addRow();
</script>
<?php include __DIR__.'/../includes/footer.php'; ?>