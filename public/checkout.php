<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php';
$method = $_POST['method'] ?? 'mercadopago';


// 1) Crear venta
$st = $conexion->prepare('INSERT INTO sales (channel,status,payment_method,subtotal,total_paid,buyer_name,buyer_email) VALUES ("online","pending",?,?,0,?,?)');
$st->bind_param('sdss', $method, $subtotal, $buyer_name, $buyer_email);
$st->execute(); $sale_id = $st->insert_id;


// 2) Items + (no descontamos stock hasta pago confirmado / POS)
$sti = $conexion->prepare('INSERT INTO sale_items (sale_id,product_id,variant_id,price,cost,qty) VALUES (?,?,?,?,?,?)');
foreach($items as $it){
// leemos costo del producto para calcular ganancia más tarde
$q = $conexion->query('SELECT cost FROM products WHERE id='.(int)$it['product_id']);
$cost = ($q && $r=$q->fetch_assoc()) ? (float)$r['cost'] : 0;
$sti->bind_param('iiiidi',$sale_id,$it['product_id'],$it['variant_id'],$it['price'],$cost,$it['qty']);
$sti->execute();
}


if ($method==='mercadopago' && $config['MP_ACCESS_TOKEN']){
// 3) Preferencia MP sencilla por REST
$pref = [
'items' => array_map(function($it){ return [
'title' => $it['name'], 'quantity' => (int)$it['qty'], 'currency_id' => 'ARS', 'unit_price' => (float)$it['price']
]; }, $items),
'external_reference' => (string)$sale_id,
'back_urls' => [
'success' => (isset($_SERVER['HTTP_ORIGIN'])?$_SERVER['HTTP_ORIGIN']:'') . '/public/checkout_result.php?ok=1&id='.$sale_id,
'failure' => (isset($_SERVER['HTTP_ORIGIN'])?$_SERVER['HTTP_ORIGIN']:'') . '/public/checkout_result.php?ok=0&id='.$sale_id,
'pending' => (isset($_SERVER['HTTP_ORIGIN'])?$_SERVER['HTTP_ORIGIN']:'') . '/public/checkout_result.php?ok=0&id='.$sale_id,
],
'notification_url' => (isset($_SERVER['HTTP_ORIGIN'])?$_SERVER['HTTP_ORIGIN']:'') . '/webhooks/mercadopago.php'
];
$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>[
'Authorization: Bearer '.$config['MP_ACCESS_TOKEN'], 'Content-Type: application/json'
],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($pref)]);
$res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$data = json_decode($res,true);
if ($http>=200 && $http<300 && isset($data['init_point'])){
$pref_id = $data['id'] ?? '';
$stp=$conexion->prepare('INSERT INTO payments (sale_id,gateway,ext_preference_id,status,amount) VALUES (?,?,?,?,?)');
$stp->bind_param('isssd',$sale_id,$gw='mercadopago',$pref_id,$st='pending',$subtotal); $stp->execute();
// vaciar carrito y redirigir a MP
$_SESSION['cart']=[]; header('Location: '.$data['init_point']); exit;
} else {
echo err('Error creando pago en Mercado Pago.');
}
}


// Transferencia o efectivo: mostrar instrucciones
include __DIR__.'/../includes/header.php';
echo '<h1>Pedido creado</h1>';
echo '<p>N° de pedido: <strong>#'.(int)$sale_id.'</strong></p>';
if ($method==='transferencia'){
echo ok('Elegiste Transferencia. '.$config['BANK_INFO'].' · Enviá comprobante por WhatsApp o email.');
} else {
echo ok('Elegiste pagar en efectivo al retirar. Te esperamos en tienda.');
}
$_SESSION['cart']=[];
include __DIR__.'/../includes/footer.php';