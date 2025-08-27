<?php
/* ===== DEBUG (apagá en prod) ===== */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status()===PHP_SESSION_NONE) session_start();

/* ===== Buscar raíz con includes/conn.php ===== */
$root = __DIR__;
for ($i=0; $i<6; $i++) {
  if (file_exists($root.'/includes/conn.php')) break;
  $root = dirname($root);
}
$has_conn = file_exists($root.'/includes/conn.php');
if ($has_conn) { require $root.'/includes/conn.php'; }
@require $root.'/includes/helpers.php';

/* ===== Helpers ===== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ===== BASES WEB (sin duplicar /clientes) ===== */
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$dir    = rtrim(dirname($script), '/\\'); // /.../public o /.../public/clientes
$PUBLIC_BASE = (preg_match('~/(clientes)(/|$)~', $dir)) ? rtrim(dirname($dir), '/\\') : $dir;
if (!function_exists('url_public')) {
  function url_public($path){ global $PUBLIC_BASE; $b=rtrim($PUBLIC_BASE,'/'); return ($b===''?'':$b).'/'.ltrim((string)$path,'/'); }
}
if (!function_exists('urlc')) {
  function urlc($path){ return url_public('clientes/'.ltrim((string)$path,'/')); }
}

/* ========= Utilidades de BD ========= */
function db_has_table($table){
  global $conexion;
  $rs = @$conexion->query("SHOW TABLES LIKE '". $conexion->real_escape_string($table) ."'");
  return ($rs && $rs->num_rows>0);
}
function db_cols($table){
  global $conexion;
  $cols = [];
  if ($rs=@$conexion->query("SHOW COLUMNS FROM `$table`")) {
    while($r=$rs->fetch_assoc()){ $cols[$r['Field']] = $r; }
  }
  return $cols;
}
function hascol($table,$col){
  global $conexion;
  $rs = @$conexion->query("SHOW COLUMNS FROM `$table` LIKE '".$conexion->real_escape_string($col)."'");
  return ($rs && $rs->num_rows>0);
}
function coltype($table,$col){
  $c = db_cols($table);
  return strtolower($c[$col]['Type'] ?? '');
}
function infer_type($v){
  if (is_int($v)) return 'i';
  if (is_float($v)) return 'd';
  if (is_numeric($v)) return (str_contains((string)$v,'.')?'d':'i');
  return 's';
}
/** Inserta en $table sólo columnas que existan (tolerante a esquemas distintos) */
function insert_dynamic_row($table, array $data){
  global $conexion;
  $cols_info = db_cols($table);
  if (!$cols_info) throw new Exception("La tabla `$table` no existe o no se pudo leer.");
  $avail = array_fill_keys(array_keys($cols_info), true);

  $filtered = [];
  foreach ($data as $k=>$v) if (isset($avail[$k])) $filtered[$k] = $v;
  if (!$filtered) throw new Exception("No hay columnas compatibles para `$table`.");

  $columns = array_keys($filtered);
  $place   = array_fill(0, count($columns), '?');
  $types=''; $params=[];
  foreach ($columns as $c){ $types .= infer_type($filtered[$c]); $params[]=$filtered[$c]; }

  $sql = "INSERT INTO `$table` (`".implode("`,`",$columns)."`) VALUES (".implode(',',$place).")";
  $stmt = $conexion->prepare($sql);
  if (!$stmt) throw new Exception("SQL PREPARE ($table): ".$conexion->error." — ".$sql);
  $stmt->bind_param($types, ...$params);
  if (!$stmt->execute()){ $e=$stmt->error; $stmt->close(); throw new Exception("SQL EXEC ($table): $e — ".$sql); }
  $id = $stmt->insert_id; $stmt->close();
  return (int)$id;
}

/* ====== Payloads tolerantes (sinónimos) ====== */
function sales_payload($args){
  $now = date('Y-m-d H:i:s');
  return [
    // cliente
    'customer_name'=>$args['name'],'name'=>$args['name'],'cliente'=>$args['name'],'buyer_name'=>$args['name'],
    'customer_phone'=>$args['phone'],'phone'=>$args['phone'],'telefono'=>$args['phone'],
    'customer_email'=>$args['email'],'email'=>$args['email'],
    // pago
    'payment_method'=>$args['method'],'method'=>$args['method'],'metodo'=>$args['method'],
    'installments'=>$args['cuotas'],'cuotas'=>$args['cuotas'],
    // totales
    'subtotal'=>$args['subtotal'],'total'=>$args['total'],
    'discount'=>0.0,'descuento'=>0.0,'fee'=>$args['fee'],'recargo'=>$args['fee'],
    // envío
    'shipping_method'=>$args['ship_method'],'delivery_method'=>$args['ship_method'],'tipo_envio'=>$args['ship_method'],
    'shipping_cost'=>$args['ship_cost'],'delivery_cost'=>$args['ship_cost'],'costo_envio'=>$args['ship_cost'],
    'shipping_address'=>$args['addr'],'address'=>$args['addr'],'direccion'=>$args['addr'],
    'shipping_city'=>$args['city'],'city'=>$args['city'],'ciudad'=>$args['city'],
    'shipping_province'=>$args['prov'],'province'=>$args['prov'],'provincia'=>$args['prov'],
    'shipping_postal'=>$args['post'],'postal'=>$args['post'],'cp'=>$args['post'],
    'shipping_notes'=>$args['notes'],'notes'=>$args['notes'],'observaciones'=>$args['notes'],
    // tracking (los seteamos luego según el tipo real de columna)
    'origin'=>'online','origen'=>'online',
    'created_at'=>$now,'fecha'=>$now,
  ];
}
function sale_item_payload($sale_id, $it){
  return [
    'sale_id'=>$sale_id,'venta_id'=>$sale_id,
    'product_id'=>(int)$it['pid'],'producto_id'=>(int)$it['pid'],
    'variant_id'=>(int)$it['vid'],'product_variant_id'=>(int)$it['vid'],'variante_id'=>(int)$it['vid'],
    'name'=>(string)$it['name'],'title'=>(string)$it['name'],'descripcion'=>(string)$it['name'],
    'qty'=>(int)$it['qty'],'quantity'=>(int)$it['qty'],'cantidad'=>(int)$it['qty'],
    'price_unit'=>(float)$it['price'],'unit_price'=>(float)$it['price'],'precio_unit'=>(float)$it['price'],'price'=>(float)$it['price'],
    'line_total'=>(float)$it['line_total'],'total'=>(float)$it['line_total'],'importe'=>(float)$it['line_total'],
  ];
}

/* ===== Normalizador de STATUS según esquema real ===== */
function choose_status_value($method, $table, $col){
  $type = coltype($table,$col);
  $unpaid_methods = ['efectivo','transferencia','cuenta_corriente'];
  $is_unpaid = in_array($method,$unpaid_methods,true);

  // Preferencias por idioma/uso
  $pref_unpaid = ['pendiente','reservado','pending','hold','new','nuevo'];
  $pref_paid   = ['pagado','paid','completada','completed','cerrada','closed'];

  // Si es ENUM('...','...')
  if (str_starts_with($type,'enum(')) {
    preg_match_all("/'([^']+)'/",$type,$m);
    $opts = $m[1] ?? [];
    $prefs = $is_unpaid ? $pref_unpaid : $pref_paid;
    // Buscar case-insensitive y devolver la opción tal como está en el enum
    foreach ($prefs as $p) {
      foreach ($opts as $opt) {
        if (strcasecmp($p,$opt)===0) return $opt;
      }
    }
    // sino, primera opción del enum
    return $opts[0] ?? ($is_unpaid ? 'pendiente' : 'pagado');
  }

  // Si es numérica -> 0/1
  if (preg_match('~^(tinyint|smallint|int|bigint|decimal|double|float)~',$type)) {
    return $is_unpaid ? 0 : 1;
  }

  // Por defecto (varchar/text)
  return $is_unpaid ? 'pendiente' : 'pagado';
}

/* ===== Manejo de STOCK y Reservas ===== */
function adjust_stock($product_id, $variant_id, $qty_change){
  global $conexion;
  $product_id=(int)$product_id; $variant_id=(int)$variant_id; $qty_change=(int)$qty_change;

  // Primero variantes
  if (db_has_table('product_variants') && $variant_id>0) {
    $col = hascol('product_variants','stock') ? 'stock' : (hascol('product_variants','existencia') ? 'existencia' : null);
    if ($col) {
      if ($st=$conexion->prepare("UPDATE product_variants SET `$col`=GREATEST(0, `$col`+?) WHERE id=? AND product_id=?")) {
        $st->bind_param('iii',$qty_change,$variant_id,$product_id);
        $st->execute(); $st->close(); return;
      }
    }
  }
  // Si no, en products
  if (db_has_table('products')) {
    $colp = hascol('products','stock') ? 'stock' : (hascol('products','existencia') ? 'existencia' : null);
    if ($colp) {
      if ($st=$conexion->prepare("UPDATE products SET `$colp`=GREATEST(0, `$colp`+?) WHERE id=?")) {
        $st->bind_param('ii',$qty_change,$product_id);
        $st->execute(); $st->close();
      }
    }
  }
}
function ensure_reservations_table(){
  global $conexion;
  @$conexion->query("CREATE TABLE IF NOT EXISTS stock_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NULL,
    product_id INT NOT NULL,
    variant_id INT NOT NULL DEFAULT 0,
    qty INT NOT NULL,
    expires_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(product_id,variant_id), INDEX(expires_at),
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function release_expired_reservations(){
  global $conexion;
  if (!db_has_table('stock_reservations')) return;

  $sql="SELECT r.id,r.sale_id,r.product_id,r.variant_id,r.qty
        FROM stock_reservations r
        LEFT JOIN sales s ON s.id=r.sale_id
        WHERE r.released_at IS NULL
          AND r.expires_at<NOW()
          AND (s.id IS NULL OR s.status IS NULL OR s.status NOT IN ('paid','pagado','completed','completada'))";
  if ($rs=@$conexion->query($sql)) {
    while($r=$rs->fetch_assoc()){
      adjust_stock((int)$r['product_id'], (int)$r['variant_id'], + (int)$r['qty']);
      if ($st=$conexion->prepare("UPDATE stock_reservations SET released_at=NOW() WHERE id=?")) {
        $st->bind_param('i',$r['id']); $st->execute(); $st->close();
      }
      if (hascol('sales','status') && (int)$r['sale_id']>0) {
        if ($st=$conexion->prepare("UPDATE sales SET status=IF(status IN ('paid','pagado','completed','completada'),status,'expired') WHERE id=?")) {
          $st->bind_param('i',$r['sale_id']); $st->execute(); $st->close();
        }
      }
    }
  }
}

/* ===== Config pagos ===== */
$PAY_METHODS = [
  'efectivo'         => ['label'=>'Efectivo'],
  'transferencia'    => ['label'=>'Transferencia'],
  'debito'           => ['label'=>'Débito',  'installments'=>[1=>0]],
  'credito'          => ['label'=>'Crédito', 'installments'=>[1=>0, 3=>10, 6=>20, 12=>35]],
  'cuenta_corriente' => ['label'=>'Cuenta Corriente'],
];
$UNPAID_METHODS = ['efectivo','transferencia','cuenta_corriente'];

/* ===== Config envío ===== */
$SHIPPING = [
  'retiro' => ['label'=>'Retiro en tienda', 'flat'=>0,    'free_over'=>0],
  'envio'  => ['label'=>'Envío a domicilio','flat'=>2500, 'free_over'=>50000],
];

/* ===== Estado DB / esquema ===== */
$db_ok=false; $has_products=$has_variants=false;
$has_image_url=$has_product_price=false; $has_variant_price=false;
if ($has_conn && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno) {
  $db_ok=true;
  $has_products      = (@$conexion->query("SHOW TABLES LIKE 'products'")?->num_rows ?? 0) > 0;
  $has_variants      = (@$conexion->query("SHOW TABLES LIKE 'product_variants'")?->num_rows ?? 0) > 0;
  if ($has_products) {
    $has_image_url     = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")?->num_rows ?? 0) > 0;
    $has_product_price = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'price'")?->num_rows ?? 0) > 0;
  }
  if ($has_variants) {
    $has_variant_price = (@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'")?->num_rows ?? 0) > 0;
  }
}

/* ===== Limpieza de reservas vencidas (cada visita) ===== */
if ($db_ok) { ensure_reservations_table(); release_expired_reservations(); }

/* ===== Carrito & pago & envío (sesión) ===== */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart     =& $_SESSION['cart'];

if (!isset($_SESSION['payment']) || !is_array($_SESSION['payment'])) {
  $_SESSION['payment'] = ['method'=>'efectivo','installments'=>1];
}
$payment  =& $_SESSION['payment'];

if (!isset($_SESSION['shipping']) || !is_array($_SESSION['shipping'])) {
  $_SESSION['shipping'] = [
    'method'=>'retiro','address'=>'','city'=>'','province'=>'','postal'=>'','notes'=>''
  ];
}
$shipping =& $_SESSION['shipping'];

/* ===== Acciones (POST) ===== */
$errors = []; $ok_sale_id = 0;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'set_shipping') {
    $m = $_POST['ship_method'] ?? 'retiro';
    if (!isset($SHIPPING[$m])) $m = 'retiro';
    $shipping['method']   = $m;
    if ($m==='envio') {
      $shipping['address']  = trim((string)($_POST['ship_address']  ?? ''));
      $shipping['city']     = trim((string)($_POST['ship_city']     ?? ''));
      $shipping['province'] = trim((string)($_POST['ship_province'] ?? ''));
      $shipping['postal']   = trim((string)($_POST['ship_postal']   ?? ''));
      $shipping['notes']    = trim((string)($_POST['ship_notes']    ?? ''));
    } else {
      $shipping['address']=$shipping['city']=$shipping['province']=$shipping['postal']=$shipping['notes']='';
    }
    header('Location: '.urlc('checkout.php')); exit;
  }

  if ($action === 'confirm') {
    // Validaciones básicas
    $name  = trim((string)($_POST['customer_name']  ?? ''));
    $phone = trim((string)($_POST['customer_phone'] ?? ''));
    $email = trim((string)($_POST['customer_email'] ?? ''));
    if ($name==='')  $errors[]='Ingresá tu nombre.';
    if ($phone==='') $errors[]='Ingresá un teléfono.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[]='Email inválido.';
    if (empty($cart)) $errors[]='El carrito está vacío.';
    if (!$db_ok) $errors[]='No hay conexión a la BD.';

    if (empty($errors)) {
      // Recalcular items y subtotal
      $items=[]; $subtotal=0.0;
      foreach ($cart as $it) {
        $pid=(int)($it['product_id']??0); $vid=(int)($it['variant_id']??0); $qty=max(0,(int)($it['qty']??0));
        if ($pid<=0||$qty<=0) continue;
        $nameP='Producto #'.$pid; $price=0.0;
        if ($db_ok && $has_products) {
          if ($has_variants && $has_variant_price && $vid>0) {
            if ($rv=@$conexion->query("SELECT price FROM product_variants WHERE id={$vid} AND product_id={$pid} LIMIT 1")) {
              if ($rr=$rv->fetch_assoc()) $price=(float)($rr['price']??0);
            }
          }
          if ($price<=0 && $has_variants && $has_variant_price) {
            if ($rv=@$conexion->query("SELECT MIN(price) AS p FROM product_variants WHERE product_id={$pid}")) {
              if ($rr=$rv->fetch_assoc()) $price=(float)($rr['p']??0);
            }
          }
          if ($price<=0 && $has_product_price) {
            if ($rp=@$conexion->query("SELECT price FROM products WHERE id={$pid} LIMIT 1")) {
              if ($rr=$rp->fetch_assoc()) $price=(float)($rr['price']??0);
            }
          }
          if ($rp2=@$conexion->query("SELECT name FROM products WHERE id={$pid} LIMIT 1")) {
            if ($rr2=$rp2->fetch_assoc()) $nameP=$rr2['name']?:$nameP;
          }
        }
        $lt=$price*$qty; $subtotal+=$lt;
        $items[]=['pid'=>$pid,'vid'=>$vid,'name'=>$nameP,'qty'=>$qty,'price'=>$price,'line_total'=>$lt];
      }

      // Pago
      $method   = $payment['method'] ?? 'efectivo';
      $cuotas   = (int)($payment['installments'] ?? 1);
      $discount = 0.0;
      $fee      = 0.0;
      if ($method==='credito' && !empty($PAY_METHODS['credito']['installments'][$cuotas])) {
        $fee = $subtotal * ($PAY_METHODS['credito']['installments'][$cuotas]/100);
      }

      // Envío
      $ship_method = $shipping['method'] ?? 'retiro';
      $ship_cfg = $SHIPPING[$ship_method] ?? $SHIPPING['retiro'];
      $ship_cost = 0.0;
      if ($ship_method==='envio') {
        $flat = (float)($ship_cfg['flat']??0);
        $free = (float)($ship_cfg['free_over']??0);
        $ship_cost = ($free>0 && $subtotal>=$free) ? 0.0 : $flat;
        if (($shipping['address']??'')==='') $errors[]='Ingresá la dirección para el envío.';
        if (($shipping['city']??'')==='')    $errors[]='Ingresá la ciudad.';
        if (($shipping['province']??'')==='')$errors[]='Ingresá la provincia.';
        if (($shipping['postal']??'')==='')  $errors[]='Ingresá el código postal.';
      }
      $total = max(0.0, $subtotal + $fee + $ship_cost);

      if (empty($errors)) {
        try {
          // Crear tablas si no existen (por si la BD está vacía)
          @$conexion->query("CREATE TABLE IF NOT EXISTS sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_name VARCHAR(120) NULL,
            customer_phone VARCHAR(60) NULL,
            customer_email VARCHAR(120) NULL,
            payment_method VARCHAR(30) NULL,
            installments INT NOT NULL DEFAULT 1,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_method VARCHAR(30) NULL,
            shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_address TEXT NULL,
            shipping_city VARCHAR(120) NULL,
            shipping_province VARCHAR(120) NULL,
            shipping_postal VARCHAR(20) NULL,
            shipping_notes TEXT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            origin VARCHAR(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

          @$conexion->query("CREATE TABLE IF NOT EXISTS sale_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            product_id INT NOT NULL,
            variant_id INT NOT NULL DEFAULT 0,
            name VARCHAR(255) NOT NULL,
            qty INT NOT NULL,
            price_unit DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

          ensure_reservations_table();

          $conexion->begin_transaction();

          // Insert venta (tolerante) + STATUS compatible con el esquema
          $addr=(string)($shipping['address']??''); $city=(string)($shipping['city']??'');
          $prov=(string)($shipping['province']??''); $post=(string)($shipping['postal']??''); $notes=(string)($shipping['notes']??'');

          $sale_payload = sales_payload([
            'name'=>$name,'phone'=>$phone,'email'=>$email,
            'method'=>$method,'cuotas'=>$cuotas,
            'subtotal'=>$subtotal,'fee'=>$fee,
            'ship_method'=>$ship_method,'ship_cost'=>$ship_cost,
            'addr'=>$addr,'city'=>$city,'prov'=>$prov,'post'=>$post,'notes'=>$notes,
            'total'=>$total
          ]);

          // Ajustar status/estado según tipo real de columna
          if (hascol('sales','status')) {
            $sale_payload['status'] = choose_status_value($method,'sales','status');
          }
          if (hascol('sales','estado')) {
            $sale_payload['estado'] = choose_status_value($method,'sales','estado');
          }

          $sale_id = insert_dynamic_row('sales', $sale_payload);

          // Ítems + stock + reserva si corresponde
          foreach ($items as $it) {
            insert_dynamic_row('sale_items', sale_item_payload($sale_id,$it));

            // Descontar stock
            adjust_stock((int)$it['pid'], (int)$it['vid'], - (int)$it['qty']);

            // Reservar 24h para métodos no pago
            if (in_array($method,$UNPAID_METHODS,true) && db_has_table('stock_reservations')) {
              if ($st=$conexion->prepare("INSERT INTO stock_reservations (sale_id,product_id,variant_id,qty,expires_at) VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 1 DAY))")) {
                $pid=(int)$it['pid']; $vid=(int)$it['vid']; $q=(int)$it['qty'];
                $st->bind_param('iiii',$sale_id,$pid,$vid,$q); $st->execute(); $st->close();
              }
            }
          }

          $conexion->commit();
          $ok_sale_id = $sale_id;

          // Vaciar carrito
          $_SESSION['cart'] = [];
          $_SESSION['cart_count'] = 0;

        } catch (Throwable $e) {
          @$conexion->rollback();
          $errors[] = 'Error al guardar la venta: '.$e->getMessage();
        }
      }
    }
  }
}

/* ===== Reconstruir items/subtotal para la vista ===== */
$items = []; $subtotal = 0.0;
foreach ($cart as $k => $it) {
  $pid=(int)($it['product_id']??0); $vid=(int)($it['variant_id']??0); $qty=max(0,(int)($it['qty']??0));
  if ($pid<=0||$qty<=0) continue;
  $nameP='Producto #'.$pid; $img='https://picsum.photos/seed/'.$pid.'/640/480'; $price=0.0;
  if ($db_ok && $has_products) {
    $sqlp = $has_image_url ? "SELECT name,image_url FROM products WHERE id={$pid} LIMIT 1"
                           : "SELECT name FROM products WHERE id={$pid} LIMIT 1";
    if ($res=@$conexion->query($sqlp)) if ($row=$res->fetch_assoc()) {
      if (!empty($row['name'])) $nameP=$row['name'];
      if ($has_image_url && !empty($row['image_url'])) $img=$row['image_url'];
    }
    if ($has_variants && $has_variant_price && $vid>0) {
      if ($rv=@$conexion->query("SELECT price FROM product_variants WHERE id={$vid} AND product_id={$pid} LIMIT 1"))
        if ($rr=$rv->fetch_assoc()) $price=(float)($rr['price']??0);
    }
    if ($price<=0 && $has_variants && $has_variant_price) {
      if ($rv=@$conexion->query("SELECT MIN(price) AS p FROM product_variants WHERE product_id={$pid}"))
        if ($rr=$rv->fetch_assoc()) $price=(float)($rr['p']??0);
    }
    if ($price<=0 && $has_product_price) {
      if ($rp=@$conexion->query("SELECT price FROM products WHERE id={$pid} LIMIT 1"))
        if ($rr=$rp->fetch_assoc()) $price=(float)($rr['price']??0);
    }
  }
  $lt=$price*$qty; $subtotal+=$lt;
  $items[]=['pid'=>$pid,'vid'=>$vid,'name'=>$nameP,'img'=>$img,'qty'=>$qty,'price'=>$price,'line_total'=>$lt];
}

/* ===== Totales ===== */
$method   = $payment['method'] ?? 'efectivo';
$cuotas   = (int)($payment['installments'] ?? 1);
$discount = 0.0;
$fee      = 0.0;
if ($method==='credito' && !empty($PAY_METHODS['credito']['installments'][$cuotas])) {
  $fee = $subtotal * ($PAY_METHODS['credito']['installments'][$cuotas]/100);
}
$ship_method = $shipping['method'] ?? 'retiro';
$ship_cfg = $SHIPPING[$ship_method] ?? $SHIPPING['retiro'];
$ship_cost = 0.0;
if ($ship_method==='envio') {
  $flat=(float)($ship_cfg['flat']??0);
  $free=(float)($ship_cfg['free_over']??0);
  $ship_cost = ($free>0 && $subtotal>=$free) ? 0.0 : $flat;
}
$total = max(0.0, $subtotal + $fee + $ship_cost);
$cuota_monto = ($method==='credito' && $cuotas>1) ? ($total/$cuotas) : 0.0;

$header_path = $root.'/includes/header.php';
$cart_empty = empty($items);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna — Checkout</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>" />
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width:900px){ .grid2{grid-template-columns:1fr} }
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;padding:12px}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--ring,#2d323d);padding:10px;text-align:left}
    .cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--ring);border-radius:999px}
    .alert{background:#2a1b1b;border:1px solid #7f1d1d;color:#fecaca;border-radius:8px;padding:10px;margin:10px 0}
    input,textarea,select{width:100%;padding:.5rem;border:1px solid var(--ring);background:transparent;color:var(--fg);border-radius:.5rem}
    label{display:block;margin:.4rem 0 .2rem}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <nav class="breadcrumb" style="margin:8px 0 2px">
      <a href="<?= url_public('index.php') ?>">Inicio</a> <span>›</span>
      <a href="<?= urlc('index.php') ?>">Tienda</a> <span>›</span>
      <strong>Checkout</strong>
    </nav>

    <header style="display:flex;align-items:center;justify-content:space-between;padding:16px 0">
      <h1 style="margin:0">✅ Checkout</h1>
      <a class="pill" href="<?= urlc('carrito.php') ?>">Volver al carrito</a>
    </header>

    <?php if ($cart_empty): ?>
      <div class="card">
        Tu carrito está vacío. <a class="cta" href="<?= urlc('index.php') ?>">Ir a la tienda</a>
      </div>
    <?php else: ?>

      <?php if (!empty($errors)): ?>
        <div class="alert">
          <?php foreach ($errors as $e): ?>• <?= h($e) ?><br><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="grid2">
        <!-- Resumen -->
        <div class="card">
          <h3 style="margin-top:0">🧾 Resumen</h3>
          <div style="overflow:auto">
            <table class="table">
              <thead><tr><th>Producto</th><th>Cant.</th><th>Unit.</th><th>Total</th></tr></thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                  <tr>
                    <td><?= h($it['name']) ?></td>
                    <td><?= (int)$it['qty'] ?></td>
                    <td>$ <?= money($it['price']) ?></td>
                    <td>$ <?= money($it['line_total']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div style="margin-top:10px">
            <div style="display:flex;justify-content:space-between"><span>Subtotal</span><b>$ <?= money($subtotal) ?></b></div>
            <?php if ($fee>0): ?>
              <div style="display:flex;justify-content:space-between"><span>Recargo</span><b>+ $ <?= money($fee) ?></b></div>
            <?php endif; ?>
            <?php if ($ship_cost>0): ?>
              <div style="display:flex;justify-content:space-between"><span>Envío</span><b>+ $ <?= money($ship_cost) ?></b></div>
            <?php else: ?>
              <div style="display:flex;justify-content:space-between;opacity:.85"><span>Envío</span><b>$ <?= money($ship_cost) ?><?= $ship_method==='envio' ? ' (gratis)' : '' ?></b></div>
            <?php endif; ?>
            <hr style="border:0;border-top:1px solid var(--ring,#2d323d);margin:8px 0">
            <div style="display:flex;justify-content:space-between;font-size:1.05rem"><span>Total</span><b>$ <?= money($total) ?></b></div>
            <?php if ($cuota_monto>0): ?>
              <div style="text-align:right;opacity:.9">En <?= (int)$cuotas ?> cuotas de <b>$ <?= money($cuota_monto) ?></b></div>
            <?php endif; ?>
            <div style="margin-top:8px;opacity:.9">
              Pago: <b><?= h($PAY_METHODS[$method]['label'] ?? $method) ?></b><?php if ($method==='credito'): ?> — <?= (int)$cuotas ?> cuotas<?php endif; ?>
              <a class="cta" href="<?= urlc('carrito.php') ?>" style="margin-left:8px">Cambiar</a>
            </div>
          </div>
        </div>

        <!-- Envío + Datos del cliente -->
        <div class="card">
          <h3 style="margin-top:0">🚚 Envío</h3>
          <form method="post" action="<?= urlc('checkout.php') ?>">
            <input type="hidden" name="action" value="set_shipping">
            <label><input type="radio" name="ship_method" value="retiro" <?= ($ship_method==='retiro'?'checked':'') ?>> <?= h($SHIPPING['retiro']['label']) ?></label>
            <label><input type="radio" name="ship_method" value="envio"  <?= ($ship_method==='envio'?'checked':'')  ?>> <?= h($SHIPPING['envio']['label']) ?></label>

            <div id="addr" style="margin-top:8px;<?= $ship_method==='envio' ? '' : 'display:none' ?>">
              <label for="ship_address">Dirección</label>
              <input id="ship_address" name="ship_address" value="<?= h($shipping['address']??'') ?>">

              <label for="ship_city">Ciudad</label>
              <input id="ship_city" name="ship_city" value="<?= h($shipping['city']??'') ?>">

              <label for="ship_province">Provincia</label>
              <input id="ship_province" name="ship_province" value="<?= h($shipping['province']??'') ?>">

              <label for="ship_postal">Código postal</label>
              <input id="ship_postal" name="ship_postal" value="<?= h($shipping['postal']??'') ?>">

              <label for="ship_notes">Notas para el envío (opcional)</label>
              <textarea id="ship_notes" name="ship_notes" rows="2"><?= h($shipping['notes']??'') ?></textarea>
            </div>

            <div style="text-align:right;margin-top:8px">
              <button type="submit" class="cta">Guardar envío</button>
            </div>
          </form>

          <h3 style="margin-top:16px">👤 Tus datos</h3>
          <form method="post" action="<?= urlc('checkout.php') ?>">
            <input type="hidden" name="action" value="confirm">
            <label for="customer_name">Nombre y apellido</label>
            <input id="customer_name" name="customer_name" required value="<?= h($_POST['customer_name'] ?? '') ?>">

            <label for="customer_phone">Teléfono</label>
            <input id="customer_phone" name="customer_phone" required value="<?= h($_POST['customer_phone'] ?? '') ?>">

            <label for="customer_email">Email</label>
            <input id="customer_email" name="customer_email" type="email" required value="<?= h($_POST['customer_email'] ?? '') ?>">

            <div style="margin-top:10px;text-align:right">
              <a class="cta" href="<?= urlc('index.php') ?>">Seguir comprando</a>
              <button type="submit" class="cta">Confirmar compra</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script>
    // Mostrar/ocultar dirección si se selecciona Envío
    (function(){
      const radios = document.querySelectorAll('input[name="ship_method"]');
      const addr = document.getElementById('addr');
      function sync(){
        let sel = document.querySelector('input[name="ship_method"]:checked');
        addr.style.display = (sel && sel.value==='envio') ? '' : 'none';
      }
      radios.forEach(r => r.addEventListener('change', sync));
      sync();
    })();
  </script>

</body>
</html>
