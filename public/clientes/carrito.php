<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status()===PHP_SESSION_NONE) session_start();

/* ===== Rutas ===== */
$root = __DIR__; for ($i=0; $i<6; $i++){ if (file_exists($root.'/includes/conn.php')) break; $root = dirname($root); }
$has_conn = file_exists($root.'/includes/conn.php');
if ($has_conn) { require $root.'/includes/conn.php'; }
@require $root.'/includes/helpers.php';

if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* URL helpers */
$script = $_SERVER['SCRIPT_NAME'] ?? ''; $dir = rtrim(dirname($script), '/\\');
$PUBLIC_BASE = (preg_match('~/(clientes)(/|$)~', $dir)) ? rtrim(dirname($dir), '/\\') : $dir;
if (!function_exists('url_public')) { function url_public($path){ global $PUBLIC_BASE; $b=rtrim($PUBLIC_BASE,'/'); return ($b===''?'':$b).'/'.ltrim((string)$path,'/'); } }
if (!function_exists('urlc')) { function urlc($p){ return url_public('clientes/'.ltrim((string)$p,'/')); } }

/* ===== Env helper (simple) ===== */
if (!function_exists('envv')) {
  function envv($k){
    if (isset($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
    if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return $_SERVER[$k];
    $v = getenv($k); return $v!==false ? $v : null;
  }
}

/* ===== Esquema ===== */
$db_ok = $has_conn && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
function hascol($t,$c){ global $conexion; $rs=@$conexion->query("SHOW COLUMNS FROM `$t` LIKE '$c'"); return ($rs && $rs->num_rows>0); }

/* ===== settings: helper para leer ALIAS/CBU desde BD ===== */
if (!function_exists('setting_get')) {
  function setting_get($key){
    global $conexion, $db_ok; if(!$db_ok) return null;
    // Crear tabla si no existe (seguro y sin efectos si ya está)
    @$conexion->query("CREATE TABLE IF NOT EXISTS `settings` (
      `key` varchar(64) NOT NULL PRIMARY KEY,
      `value` text NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $k = $conexion->real_escape_string($key);
    $rs = @$conexion->query("SELECT `value` FROM `settings` WHERE `key`='$k' LIMIT 1");
    if ($rs && $rs->num_rows>0){ $r=$rs->fetch_row(); return (string)$r[0]; }
    return null;
  }
}

/* ===== Detección de columnas ===== */
$has_products      = $db_ok && ((@$conexion->query("SHOW TABLES LIKE 'products'")?->num_rows ?? 0) > 0);
$has_variants      = $db_ok && ((@$conexion->query("SHOW TABLES LIKE 'product_variants'")?->num_rows ?? 0) > 0);
$has_image_url     = $has_products && ((@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")?->num_rows ?? 0) > 0);
$has_product_price = $has_products && ((@$conexion->query("SHOW COLUMNS FROM products LIKE 'price'")?->num_rows ?? 0) > 0);
$has_variant_price = $has_variants && ((@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'")?->num_rows ?? 0) > 0);

$price_col = $has_variants && hascol('product_variants','price') ? 'price' : (hascol('product_variants','precio') ? 'precio' : null);
$stock_col = $has_variants && hascol('product_variants','stock') ? 'stock' : (hascol('product_variants','existencia') ? 'existencia' : null);
$size_col  = $has_variants && hascol('product_variants','size')  ? 'size'  : (hascol('product_variants','talla') ? 'talla' : null);
$color_col = $has_variants && hascol('product_variants','color') ? 'color' : null;
$sku_col   = $has_variants && hascol('product_variants','sku')   ? 'sku'   : null;

/* ===== Carrito en sesión ===== */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart =& $_SESSION['cart'];

/* ===== Pago/Entrega en sesión ===== */
if (!isset($_SESSION['payment']) || !is_array($_SESSION['payment'])) {
  $_SESSION['payment'] = [
    'method'=>'efectivo',
    'installments'=>1,
    'delivery_method'=>'retirar', // retirar | envio
    'reserve72'=>0,
    'address'=>[
      'fullname'=>'','phone'=>'','street'=>'','number'=>'','city'=>'','notes'=>''
    ],
    'receipt_url'=>''
  ];
}
$payment =& $_SESSION['payment'];

/* ===== Acciones (POST) ===== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = $_POST['action'] ?? '';
  $pid = isset($_POST['product_id']) ? max(0, (int)$_POST['product_id']) : 0;
  $vid = isset($_POST['variant_id']) ? max(0, (int)$_POST['variant_id']) : 0;
  $qty = isset($_POST['qty'])        ? max(0, (int)$_POST['qty'])        : 0;
  $key = $pid.':'.$vid;

  if ($action === 'add' && $pid>0 && $qty>0) {
    if (!isset($cart[$key])) $cart[$key] = ['product_id'=>$pid,'variant_id'=>$vid,'qty'=>0];
    $cart[$key]['qty'] += $qty;
  } elseif ($action === 'update' && isset($cart[$key])) {
    if ($qty<=0) unset($cart[$key]); else $cart[$key]['qty'] = $qty;
  } elseif ($action === 'remove' && isset($cart[$key])) {
    unset($cart[$key]);
  } elseif ($action === 'clear') {
    $cart = [];
  } elseif ($action === 'set_payment') {
    // Método y cuotas
    $m = $_POST['method'] ?? 'efectivo';
    $cuotas = isset($_POST['installments']) ? max(1,(int)$_POST['installments']) : 1;
    // Entrega
    $delivery = $_POST['delivery_method'] ?? 'retirar'; // retirar | envio
    $reserve72 = isset($_POST['reserve72']) ? 1 : 0;
    $addr = [
      'fullname'=>trim($_POST['addr_fullname'] ?? ''),
      'phone'   =>trim($_POST['addr_phone'] ?? ''),
      'street'  =>trim($_POST['addr_street'] ?? ''),
      'number'  =>trim($_POST['addr_number'] ?? ''),
      'city'    =>trim($_POST['addr_city'] ?? ''),
      'notes'   =>trim($_POST['addr_notes'] ?? ''),
    ];
    // Comprobante (URL ya subida por JS si se usó Cloudinary)
    $receipt_url = trim($_POST['receipt_url'] ?? '');

    $payment = [
      'method'=>$m,
      'installments'=>$cuotas,
      'delivery_method'=>$delivery,
      'reserve72'=>$reserve72,
      'address'=>$addr,
      'receipt_url'=>$receipt_url
    ];
  }
  $_SESSION['cart_count'] = array_sum(array_column($cart, 'qty'));
  header('Location: '.urlc('carrito.php')); exit;
}

/* ===== Calcular items ===== */
$items=[]; $subtotal=0.0;
foreach ($cart as $k=>$row){
  $pid=(int)($row['product_id']??0); $vid=(int)($row['variant_id']??0); $qty=(int)($row['qty']??0);
  if ($pid<=0 || $qty<=0) continue;

  $name='Producto'; $img=''; $price=0.0; $size=''; $color='';

  if ($db_ok && $has_products) {
    if ($rs=@$conexion->query("SELECT name".($has_image_url?",image_url":"")." FROM products WHERE id={$pid} LIMIT 1")) {
      if ($pr=$rs->fetch_assoc()){ $name=$pr['name']; $img=$has_image_url?($pr['image_url']??''):$img; }
    }
  }
  if (!$img) $img='https://picsum.photos/seed/'.$pid.'/640/480';

  if ($db_ok && $has_variants && $vid>0) {
    $cols=['id']; $cols[]=$price_col?"$price_col AS price":"0 AS price"; $cols[]=$size_col?"$size_col AS size":"'' AS size"; $cols[]=$color_col?"$color_col AS color":"'' AS color";
    if ($sku_col) $cols[]="$sku_col AS sku";
    if ($rv=@$conexion->query("SELECT ".implode(',', $cols)." FROM product_variants WHERE id={$vid} AND product_id={$pid} LIMIT 1")) {
      if ($vv=$rv->fetch_assoc()){ $price=(float)($vv['price']??0); $size=(string)($vv['size']??''); $color=(string)($vv['color']??''); }
    }
  }
  if ($price<=0 && $db_ok && $has_variant_price) {
    if ($rv = @$conexion->query("SELECT MIN(price) AS p FROM product_variants WHERE product_id={$pid}")) if ($rr=$rv->fetch_assoc()) $price=(float)($rr['p']??0);
  }
  if ($price<=0 && $db_ok && $has_product_price) {
    if ($rp = @$conexion->query("SELECT price FROM products WHERE id={$pid} LIMIT 1")) if ($rr=$rp->fetch_assoc()) $price=(float)($rr['price']??0);
  }

  $line_total = $price * $qty; $subtotal += $line_total;

  $attrs = [];
  if ($size!=='')  $attrs[]="Talle: ".h($size);
  if ($color!=='') $attrs[]="Color: ".h($color);
  $attr_text = $attrs ? ('<div class="muted" style="margin-top:2px">'.implode(' · ',$attrs).'</div>') : '';

  $items[] = ['key'=>$k,'pid'=>$pid,'vid'=>$vid,'name'=>$name,'img'=>$img,'price'=>$price,'qty'=>$qty,'line_total'=>$line_total,'attrs_html'=>$attr_text];
}

$cart_count = array_sum(array_column($cart, 'qty'));
$_SESSION['cart_count'] = $cart_count;

/* ===== Totales simples ===== */
$method = $payment['method'] ?? 'efectivo';
$cuotas = (int)($payment['installments'] ?? 1);
$delivery_method = $payment['delivery_method'] ?? 'retirar';
$reserve72 = (int)($payment['reserve72'] ?? 0);
$addr = $payment['address'] ?? ['fullname'=>'','phone'=>'','street'=>'','number'=>'','city'=>'','notes'=>''];
$receipt_url = $payment['receipt_url'] ?? '';

$fee = 0; $discount = 0;
$total = max(0,$subtotal + $fee - $discount);
$cuota_monto = ($cuotas>1) ? ($total / $cuotas) : 0;

/* ===== Alias/CBU (leer de BD primero; si no, ENV) ===== */
$BANK_ALIAS = '';
$BANK_CBU   = '';
if ($db_ok) {
  $BANK_ALIAS = setting_get('bank_alias') ?? '';
  $BANK_CBU   = setting_get('bank_cbu')   ?? '';
}
if ($BANK_ALIAS==='') $BANK_ALIAS = envv('BANK_ALIAS') ?: '';
if ($BANK_CBU==='')   $BANK_CBU   = envv('BANK_CBU')   ?: '';

/* ===== Cloudinary para comprobantes ===== */
$CLD_NAME   = envv('CLOUDINARY_CLOUD_NAME') ?: '';
$CLD_PRESET = envv('CLOUDINARY_UPLOAD_PRESET') ?: '';
$CLD_FOLDER = envv('CLOUDINARY_FOLDER') ?: 'luna-shop/comprobantes';

$header_path = $root.'/includes/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Carrito — Luna</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>">
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1000px;margin:0 auto;padding:0 14px}
    .grid{display:grid;grid-template-columns:1fr 360px;gap:14px}
    @media (max-width:900px){ .grid{grid-template-columns:1fr} }
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .p{padding:12px}
    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .cta,.btn{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none;background:transparent;color:inherit;cursor:pointer}
    .muted{opacity:.85}
    .qty{width:90px}
    .pay-card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:10px;padding:12px}
    .input{min-width:120px}
    .field{display:flex;flex-direction:column;gap:4px;flex:1}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .hide{display:none}
    .ok{color:#22c55e}
    .err{color:#ef4444}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <nav class="breadcrumb" style="margin:8px 0 2px">
      <a href="<?= urlc('index.php') ?>">Tienda</a> <span>›</span>
      <strong>Carrito</strong>
    </nav>

    <?php if (!$items): ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">Tu carrito está vacío.</div></div>
    <?php else: ?>
      <div class="grid">
        <!-- LISTA DE ITEMS -->
        <div class="card">
          <div class="p">
            <?php foreach($items as $it): ?>
              <div class="row" style="align-items:flex-start;margin-bottom:10px">
                <img src="<?= h($it['img']) ?>" alt="" width="100" height="100" style="border-radius:8px;object-fit:cover">
                <div style="flex:1">
                  <div><b><?= h($it['name']) ?></b></div>
                  <?= $it['attrs_html'] ?>
                  <div class="muted">Precio: $ <?= money($it['price']) ?></div>
                  <form action="<?= urlc('carrito.php') ?>" method="post" class="row" style="margin-top:6px">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="product_id" value="<?= (int)$it['pid'] ?>">
                    <input type="hidden" name="variant_id" value="<?= (int)$it['vid'] ?>">
                    <input class="input qty" type="number" name="qty" min="0" value="<?= (int)$it['qty'] ?>">
                    <button class="cta" type="submit">Actualizar</button>
                    <button class="cta" type="submit" name="action" value="remove">Quitar</button>
                  </form>
                </div>
                <div><b>$ <?= money($it['line_total']) ?></b></div>
              </div>
              <hr style="border:0;border-top:1px solid var(--ring,#2d323d);margin:8px 0">
            <?php endforeach; ?>
            <div style="text-align:right"><b>Subtotal:</b> $ <?= money($subtotal) ?></div>
          </div>
        </div>

        <!-- PAGO / ENTREGA / RESERVA -->
        <div class="card">
          <div class="p">
            <h3>Entrega</h3>
            <form action="<?= urlc('carrito.php') ?>" method="post" id="payForm">
              <input type="hidden" name="action" value="set_payment">
              <input type="hidden" name="receipt_url" id="receipt_url" value="<?= h($receipt_url) ?>">

              <div class="pay-card">
                <label class="badge"><input type="radio" name="delivery_method" value="retirar" <?= ($delivery_method==='retirar'?'checked':'') ?>> Retiro en tienda</label>
                <label class="badge"><input type="radio" name="delivery_method" value="envio" <?= ($delivery_method==='envio'?'checked':'') ?>> Envío a domicilio</label>

                <div id="addrWrap" class="<?= ($delivery_method==='envio'?'':'hide') ?>" style="margin-top:10px">
                  <div class="two">
                    <div class="field"><small>Nombre y apellido</small><input class="input" name="addr_fullname" value="<?= h($addr['fullname']??'') ?>"></div>
                    <div class="field"><small>Teléfono</small><input class="input" name="addr_phone" value="<?= h($addr['phone']??'') ?>"></div>
                  </div>
                  <div class="two" style="margin-top:6px">
                    <div class="field"><small>Calle</small><input class="input" name="addr_street" value="<?= h($addr['street']??'') ?>"></div>
                    <div class="field"><small>Número</small><input class="input" name="addr_number" value="<?= h($addr['number']??'') ?>"></div>
                  </div>
                  <div class="field" style="margin-top:6px"><small>Ciudad</small><input class="input" name="addr_city" value="<?= h($addr['city']??'') ?>"></div>
                  <div class="field" style="margin-top:6px"><small>Notas de entrega (opcional)</small><input class="input" name="addr_notes" value="<?= h($addr['notes']??'') ?>"></div>
                </div>

                <div style="margin-top:10px">
                  <label class="badge">
                    <input type="checkbox" name="reserve72" <?= ($reserve72 ? 'checked':'') ?>>
                    Reservar productos por 72 hs
                  </label>
                </div>
              </div>

              <h3 style="margin-top:14px">Pago</h3>
              <div class="pay-card">
                <div class="row">
                  <label class="badge"><input type="radio" name="method" value="efectivo" <?= ($method==='efectivo'?'checked':'') ?>> Efectivo</label>
                  <label class="badge"><input type="radio" name="method" value="debito"   <?= ($method==='debito'?'checked':'') ?>> Débito / Transferencia</label>
                  <label class="badge"><input type="radio" name="method" value="credito"  <?= ($method==='credito'?'checked':'') ?>> Crédito</label>
                </div>

                <div style="margin-top:8px">
                  <label>Cuotas:
                    <select name="installments">
                      <?php foreach ([1,3,6,12] as $c): ?>
                        <option value="<?= $c ?>" <?= ($cuotas===$c?'selected':'') ?>><?= $c ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>

                <!-- Bloque débito/transferencia -->
                <div id="debitBlock" class="<?= ($method==='debito'?'':'hide') ?>" style="margin-top:10px">
                  <div class="pay-card" style="background:rgba(255,255,255,.02)">
                    <div><b>Alias para transferir:</b>
                      <?= $BANK_ALIAS ? '<code>'.h($BANK_ALIAS).'</code>' : '<span class="muted">Configuralo en Admin (ALIAS)</span>' ?>
                    </div>
                    <?php if ($BANK_CBU): ?>
                      <div style="margin-top:4px"><b>CBU:</b> <code><?= h($BANK_CBU) ?></code></div>
                    <?php endif; ?>
                    <div class="muted" style="margin-top:6px">Adjuntá el comprobante de pago (foto/captura). Se valida al preparar el pedido.</div>

                    <div style="margin-top:8px">
                      <input type="file" id="receipt_file" accept="image/*">
                      <span id="upStatus" class="muted"></span>
                      <?php if ($receipt_url): ?>
                        <div style="margin-top:6px"><a class="badge" href="<?= h($receipt_url) ?>" target="_blank">Ver comprobante cargado</a></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <h3 style="margin-top:14px">Resumen</h3>
              <div class="pay-card">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px"><span>Subtotal</span><b>$ <?= money($subtotal) ?></b></div>
                <hr style="border:0;border-top:1px solid var(--ring,#2d323d);margin:8px 0">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:1.05rem"><span>Total</span><b>$ <?= money($total) ?></b></div>
                <?php if ($cuota_monto>0): ?><div style="text-align:right;opacity:.9">En <?= (int)$cuotas ?> cuotas de <b>$ <?= money($cuota_monto) ?></b></div><?php endif; ?>

                <div style="margin-top:10px;text-align:right">
                  <a class="cta" href="<?= urlc('index.php') ?>">Seguir comprando</a>
                  <button class="cta" type="submit">Guardar opciones</button>
                  <a class="cta" href="<?= urlc('checkout.php') ?>">Ir a pagar</a>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

<?php if ($CLD_NAME && $CLD_PRESET): ?>
<script>
(function(){
  const form = document.getElementById('payForm');
  const radiosDelivery = form.querySelectorAll('input[name="delivery_method"]');
  const addrWrap = document.getElementById('addrWrap');

  radiosDelivery.forEach(r => r.addEventListener('change', ()=>{
    addrWrap.classList.toggle('hide', form.delivery_method.value !== 'envio');
  }));

  const radiosMethod = form.querySelectorAll('input[name="method"]');
  const debitBlock = document.getElementById('debitBlock');
  const receiptFile = document.getElementById('receipt_file');
  const receiptUrl = document.getElementById('receipt_url');
  const upStatus = document.getElementById('upStatus');

  radiosMethod.forEach(r => r.addEventListener('change', ()=>{
    debitBlock.classList.toggle('hide', form.method.value !== 'debito');
  }));

  async function uploadReceipt(file){
    if (!file) return;
    if (file.size > 12*1024*1024) { upStatus.textContent='Archivo muy grande (máx 12MB)'; upStatus.className='err'; return; }
    upStatus.textContent = 'Subiendo comprobante…'; upStatus.className='muted';

    const fd = new FormData();
    fd.append('file', file);
    fd.append('upload_preset', <?= json_encode($CLD_PRESET) ?>);
    fd.append('folder', <?= json_encode($CLD_FOLDER) ?>);

    const endpoint = 'https://api.cloudinary.com/v1_1/<?= h($CLD_NAME) ?>/image/upload';
    try{
      const res = await fetch(endpoint, { method:'POST', body: fd });
      const data = await res.json();
      if (!res.ok || !data.secure_url) throw new Error(data?.error?.message || 'Fallo al subir');
      receiptUrl.value = data.secure_url;
      upStatus.textContent = 'Comprobante cargado ✔'; upStatus.className='ok';
    }catch(e){
      upStatus.textContent = 'Error: '+e.message; upStatus.className='err';
      receiptUrl.value = '';
    }finally{
      receiptFile.value = '';
    }
  }

  if (receiptFile) receiptFile.addEventListener('change', ()=> uploadReceipt(receiptFile.files?.[0]));
})();
</script>
<?php else: ?>
<script>
(function(){
  // Aun sin Cloudinary, mostrar/ocultar bloques para que el flujo siga
  const form = document.getElementById('payForm');
  const radiosDelivery = form.querySelectorAll('input[name="delivery_method"]');
  const addrWrap = document.getElementById('addrWrap');
  radiosDelivery.forEach(r => r.addEventListener('change', ()=>{ addrWrap.classList.toggle('hide', form.delivery_method.value !== 'envio'); }));

  const radiosMethod = form.querySelectorAll('input[name="method"]');
  const debitBlock = document.getElementById('debitBlock');
  radiosMethod.forEach(r => r.addEventListener('change', ()=>{ debitBlock.classList.toggle('hide', form.method.value !== 'debito'); }));
})();
</script>
<?php endif; ?>

</body>
</html>
