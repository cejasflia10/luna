<?php require __DIR__ . '/../includes/conn.php'; require __DIR__.'/../includes/helpers.php'; include __DIR__.'/../includes/header.php';
$id=(int)($_GET['id']??0); $ok=(int)($_GET['ok']??0);
if($ok){
// El webhook actualiza el pago; aquí sólo mostramos mensaje
echo ok('¡Gracias! Tu pago está en proceso/confirmado. Te avisamos por email. Pedido #'.$id);
} else {
echo err('El pago no se completó o fue cancelado. Podés intentar nuevamente desde el carrito.');
}
include __DIR__.'/../includes/footer.php';