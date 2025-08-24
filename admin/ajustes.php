<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; require_admin(); include __DIR__.'/../includes/header.php';
if(is_post()){
foreach(['TRANSFER_ALIAS','BANK_INFO','MP_ACCESS_TOKEN','STORE_NAME'] as $k){
$v=trim($_POST[$k]??''); $ks=strtolower($k);
$st=$conexion->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
$st->bind_param('ss',$ks,$v); $st->execute();
}
echo ok('Ajustes guardados');
}
$rows = $conexion->query("SELECT k,v FROM settings"); $s=[]; while($r=$rows->fetch_assoc()) $s[$r['k']]=$r['v'];
?>
<h1>Ajustes</h1>
<form method="post" class="card p">
<label>Nombre tienda</label><input class="input" name="STORE_NAME" value="<?=h($s['store_name']??'LUNA — Tienda de Ropa')?>">
<label>Alias/CBU para transferencia</label><input class="input" name="TRANSFER_ALIAS" value="<?=h($s['transfer_alias']??'voleyppp')?>">
<label>Datos bancarios (se muestran al cliente)</label><input class="input" name="BANK_INFO" value="<?=h($s['bank_info']??'Alias: voleyppp · CBU: ... · Titular: LUNA')?>">
<label>Mercado Pago Access Token</label><input class="input" name="MP_ACCESS_TOKEN" value="<?=h($s['mp_access_token']??'')?>">
<p><button class="btn" type="submit">Guardar</button></p>
</form>
<?php include __DIR__.'/../includes/footer.php'; ?>