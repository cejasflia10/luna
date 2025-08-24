<?php
require __DIR__ . '/../includes/conn.php';

$raw = file_get_contents('php://input');
$ev = json_decode($raw,true) ?: [];
// Webhook simple: cuando llega notificación de pago, consultamos el pago y actualizamos
if (($ev['type'] ?? '') === 'payment' && ($id=$ev['data']['id'] ?? '')){
$config = require __DIR__ . '/../config/env.php';
$ch = curl_init('https://api.mercadopago.com/v1/payments/'.$id);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>[
'Authorization: Bearer '.$config['MP_ACCESS_TOKEN']
]]);
$res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($http>=200 && $http<300){
$p = json_decode($res,true);
$status = $p['status'] ?? 'pending';
$ext_ref = (int)($p['external_reference'] ?? 0); // sale_id
$fee = (float)($p['fee_details'][0]['amount'] ?? 0);
// Actualizar pago y venta
$st=$conexion->prepare('UPDATE payments SET ext_payment_id=?, status=?, fee=? WHERE sale_id=?');
$st->bind_param('ssdi',$p['id'],$status,$fee,$ext_ref); $st->execute();
if ($status==='approved'){
// marcar venta como pagada, total_paid y descontar stock
$conexion->query("UPDATE sales SET status='paid', total_paid=subtotal, fees=".(float)$fee." WHERE id=".$ext_ref);
// descontar stock por variante
$it = $conexion->query('SELECT variant_id, qty FROM sale_items WHERE sale_id='.(int)$ext_ref);
while($r=$it->fetch_assoc()){
$conexion->query('UPDATE product_variants SET stock=GREATEST(stock-'.(int)$r['qty'].',0) WHERE id='.(int)$r['variant_id']);
}
}
}
}
http_response_code(200); echo 'ok';