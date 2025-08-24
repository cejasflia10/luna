<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; require_admin(); include __DIR__.'/../includes/header.php';
if(is_post()){

$supplier=trim($_POST['supplier']??''); $st=$conexion->prepare('INSERT INTO purchases (supplier,total) VALUES (?,0)'); $st->bind_param('s',$supplier); $st->execute(); $pid=$st->insert_id;
$sti=$conexion->prepare('INSERT INTO purchase_items (purchase_id,product_id,variant_id,cost,qty) VALUES (?,?,?,?,?)');
$sum=0; foreach($_POST['items']??[] as $it){ $p=(int)$it['product_id']; $v=(int)$it['variant_id']; $c=(float)$it['cost']; $q=max(1,(int)$it['qty']); $sum += $c*$q; $sti->bind_param('iii di',$pid,$p,$v,$c,$q); $sti->execute(); $conexion->query('UPDATE product_variants SET stock=stock+'.$q.' WHERE id='.$v); }
$conexion->query('UPDATE purchases SET total='.(float)$sum.' WHERE id='.$pid);
echo ok('Compra registrada (#'.$pid.')');
}
$prods = $conexion->query('SELECT p.id,p.name,v.id vid,v.talla,v.color FROM products p JOIN product_variants v ON v.product_id=p.id ORDER BY p.name');
?>
<h1>Compras</h1>
<form method="post" class="card p">
<label>Proveedor</label><input class="input" name="supplier" placeholder="Ej: Mayorista XYZ">
<h3>Ítems</h3>
<div id="items"></div>
<p><button class="btn secondary" type="button" onclick="addRow()">+ Agregar ítem</button></p>
<p><button class="btn" type="submit">Registrar compra</button></p>
</form>
<script>
const opts = `<?php while($r=$prods->fetch_assoc()){ $label=h($r['name'].' · '.$r['talla'].'/'.$r['color']); echo '<option value="'.$r['vid'].'" data-pid="'.$r['id'].'">'.$label.'</option>'; } ?>`;
function addRow(){
const wrap=document.getElementById('items');
const row=document.createElement('div');
row.innerHTML=`<div class="row"><div><label>Variante</label><select class="input" name="items[][variant_id]" required onchange="this.nextElementSibling.value=this.options[this.selectedIndex].dataset.pid"></select><input type="hidden" name="items[][product_id]"></div><div><label>Costo</label><input class="input" name="items[][cost]" type="number" step="0.01" required></div><div><label>Cant.</label><input class="input" name="items[][qty]" type="number" min="1" value="1" required></div></div>`;
wrap.appendChild(row); const sel=row.querySelector('select'); sel.innerHTML=opts; }
addRow();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
