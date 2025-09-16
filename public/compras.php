<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';
require $root.'/includes/page_head.php';

/* ===== Helpers ===== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ===== Rutas ===== */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if (!function_exists('url')) {
  function url($p){ global $BASE; return rtrim($BASE,'/').'/'.ltrim((string)$p,'/'); }
}

/* ===== Conexión/flags ===== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

/* ===== Utilidades de esquema ===== */
function t_exists($table){
  global $conexion; $rs=@$conexion->query("SHOW TABLES LIKE '$table'"); return ($rs && $rs->num_rows>0);
}
function table_cols($table){
  global $conexion; $out=[]; if ($rs=@$conexion->query("SHOW COLUMNS FROM `$table`")) { while($r=$rs->fetch_assoc()) $out[$r['Field']]=$r; } return $out;
}
function hascol($table,$col){ global $conexion; $rs=@$conexion->query("SHOW COLUMNS FROM `$table` LIKE '$col'"); return ($rs && $rs->num_rows>0); }
function infer_type($v){ if (is_int($v)) return 'i'; if (is_float($v)) return 'd'; if (is_numeric($v)) return (str_contains((string)$v,'.')?'d':'i'); return 's'; }
function default_for_type($mysqlType){
  $t = strtolower($mysqlType);
  if (str_starts_with($t,'int')||str_starts_with($t,'tinyint')||str_starts_with($t,'smallint')||str_starts_with($t,'bigint')) return 0;
  if (str_starts_with($t,'decimal')||str_starts_with($t,'float')||str_starts_with($t,'double')) return 0.0;
  if (str_starts_with($t,'datetime')||str_starts_with($t,'timestamp')) return date('Y-m-d H:i:s');
  if (str_starts_with($t,'date')) return date('Y-m-d');
  if (str_starts_with($t,'time')) return date('H:i:s');
  return '';
}
function insert_filtered($table, $data){
  global $conexion;
  $cols_info = table_cols($table);
  $data2 = [];
  foreach($data as $k=>$v){ if(isset($cols_info[$k])) $data2[$k]=$v; }

  foreach ($cols_info as $name=>$info) {
    $isNotNull = (strtoupper($info['Null']??'YES')==='NO');
    $hasDefault= !is_null($info['Default']);
    if ($isNotNull && !$hasDefault && !array_key_exists($name,$data2)) {
      $data2[$name] = default_for_type($info['Type'] ?? 'varchar(255)');
    }
  }
  if (!$data2) throw new Exception("No hay columnas compatibles para `$table`.");

  $cols = array_keys($data2);
  $ph   = array_fill(0, count($cols), '?');
  $types=''; $params=[];
  foreach($cols as $c){ $types .= infer_type($data2[$c]); $params[]=$data2[$c]; }

  $sql = "INSERT INTO `$table` (`".implode("`,`",$cols)."`) VALUES (".implode(',',$ph).")";
  $stmt = $conexion->prepare($sql);
  if (!$stmt) throw new Exception("SQL PREPARE ($table): ".$conexion->error." — ".$sql);
  $stmt->bind_param($types, ...$params);
  if (!$stmt->execute()){ $e=$stmt->error; $stmt->close(); throw new Exception("SQL EXEC ($table): $e — ".$sql); }
  $id=$stmt->insert_id; $stmt->close(); return $id;
}

/* ===== Env ===== */
function envv($k){
  if (isset($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
  if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return $_SERVER[$k];
  $v = getenv($k); return $v!==false ? $v : null;
}

/* ===== Cloudinary requerido ===== */
function cloud_is_enabled(){
  return !!envv('CLOUDINARY_CLOUD_NAME') && !!envv('CLOUDINARY_UPLOAD_PRESET');
}

/* ===== Alta de compra + variantes múltiples ===== */
$okMsg=''; $errMsg=''; $created=[];
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_purchase') {
  try {
    if (!cloud_is_enabled()) throw new Exception('Cloudinary no está configurado (CLOUDINARY_CLOUD_NAME / CLOUDINARY_UPLOAD_PRESET).');
    if (!t_exists('products') || !t_exists('product_variants')) throw new Exception('Faltan tablas mínimas: products y/o product_variants.');

    /* Imagen: subida directa desde el front, recibimos URL */
    $image_url = trim($_POST['image_url'] ?? '');

    /* Datos del producto */
    $name   = trim($_POST['name'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $catId  = (int)($_POST['category_id'] ?? 0);
    if ($name==='') throw new Exception('El nombre del producto es obligatorio.');
    if ($db_ok && t_exists('categories') && $catId<=0) throw new Exception('Seleccioná una categoría.');

    /* Variantes (arrays) */
    $skus   = $_POST['sku'] ?? [];
    $sizes  = $_POST['size'] ?? [];
    $colors = $_POST['color'] ?? [];
    $meass  = $_POST['measure_text'] ?? [];
    $qtys   = $_POST['quantity'] ?? [];
    $costs  = $_POST['unit_cost'] ?? [];
    $prices = $_POST['sale_price'] ?? [];

    // Normalizar a arrays
    foreach (['skus','sizes','colors','meass','qtys','costs','prices'] as $var) {
      if (!is_array($$var)) $$var = [];
    }

    $rows = max(count($sizes), count($colors), count($qtys), count($costs), count($prices));
    if ($rows < 1) throw new Exception('Agregá al menos una variante (talle/color/cantidad).');

    // Validación y sumatoria total
    $totalCompra = 0.0; $linhasValidas = 0;
    for ($i=0; $i<$rows; $i++){
      $q = (int)($qtys[$i] ?? 0);
      $c = (float)($costs[$i] ?? 0);
      $p = (float)($prices[$i] ?? 0);
      if ($q < 0 || $c < 0 || $p < 0) throw new Exception('Cantidades/costos/precios no pueden ser negativos.');
      if ($q === 0) continue; // ignorar filas vacías
      $linhasValidas++;
      $totalCompra += $q * $c;
    }
    if ($linhasValidas === 0) throw new Exception('Cargá cantidad > 0 en al menos una variante.');

    /* Info de compra */
    $supplier   = trim($_POST['supplier'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    $conexion->begin_transaction();

    // SKU para products (general)
    $sku_for_product = 'P'.date('ymdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));

    /* INSERT products */
    $product_id = insert_filtered('products', [
      'name'        => $name,
      'description' => $desc,
      'image_url'   => $image_url,
      'active'      => 1,
      'category_id' => $catId,
      'sku'         => $sku_for_product,
    ]);

    // Registrar compra general si existen las tablas
    $purchase_id = null;
    if (t_exists('purchases') && t_exists('purchase_items')) {
      $pdata = [];
      $p_date_col = hascol('purchases','purchased_at') ? 'purchased_at' : (hascol('purchases','created_at') ? 'created_at' : null);
      if ($p_date_col) $pdata[$p_date_col] = date('Y-m-d H:i:s');
      if (hascol('purchases','supplier')) $pdata['supplier'] = $supplier;
      if (hascol('purchases','notes'))    $pdata['notes']    = $notes;
      if (hascol('purchases','status'))   $pdata['status']   = 'done';
      if (hascol('purchases','total'))    $pdata['total']    = $totalCompra;
      $purchase_id = insert_filtered('purchases', $pdata);
    }

    /* INSERT de cada variante + item de compra */
    for ($i=0; $i<$rows; $i++){
      $size  = trim((string)($sizes[$i] ?? ''));
      $color = trim((string)($colors[$i] ?? ''));
      $meas  = trim((string)($meass[$i] ?? ''));
      $sku   = trim((string)($skus[$i]  ?? ''));
      $qty   = (int)($qtys[$i]   ?? 0);
      $cost  = (float)($costs[$i] ?? 0);
      $price = (float)($prices[$i]?? 0);

      if ($qty <= 0) continue; // saltar filas vacías

      $pv = [
        'product_id' => $product_id,
        'sku'        => $sku,
        'color'      => $color,
        'price'      => $price,
        'avg_cost'   => $cost,
        'stock'      => $qty,
      ];
      if (hascol('product_variants','size'))         $pv['size']         = $size;
      if (hascol('product_variants','talla'))        $pv['talla']        = $size;
      if (hascol('product_variants','measure_text')) $pv['measure_text'] = $meas;
      if (hascol('product_variants','medidas'))      $pv['medidas']      = $meas;
      if (hascol('product_variants','precio'))       $pv['precio']       = $price;
      if (hascol('product_variants','existencia'))   $pv['existencia']   = $qty;
      if (hascol('product_variants','average_cost')) $pv['average_cost'] = $cost;
      if (hascol('product_variants','costo_promedio')) $pv['costo_promedio'] = $cost;
      if (hascol('product_variants','costo_prom'))     $pv['costo_prom']     = $cost;

      $variant_id = insert_filtered('product_variants', $pv);

      // purchase_items por variante
      if ($purchase_id) {
        $pi = ['purchase_id'=>$purchase_id];
        if (hascol('purchase_items','variant_id')) $pi['variant_id'] = $variant_id;
        if (hascol('purchase_items','product_id')) $pi['product_id'] = $product_id;

        if (hascol('purchase_items','quantity')) $pi['quantity'] = $qty; elseif (hascol('purchase_items','qty')) $pi['qty']=$qty;

        if (hascol('purchase_items','unit_cost')) $pi['unit_cost']=$cost;
        if (hascol('purchase_items','cost_unit')) $pi['cost_unit']=$cost;
        if (hascol('purchase_items','price_unit'))$pi['price_unit']=$cost;

        if (hascol('purchase_items','subtotal'))  $pi['subtotal'] = $qty * $cost;

        if (hascol('purchase_items','size'))      $pi['size']   = $size;
        if (hascol('purchase_items','talla'))     $pi['talla']  = $size;
        if (hascol('purchase_items','measure_text')) $pi['measure_text'] = $meas;
        if (hascol('purchase_items','medidas'))      $pi['medidas']      = $meas;

        foreach (['name'=>$name,'sku'=>$sku,'color'=>$color,'image_url'=>$image_url] as $k=>$v) {
          if (hascol('purchase_items',$k)) $pi[$k] = $v;
        }

        insert_filtered('purchase_items', $pi);
      }
    }

    $conexion->commit();

    $okMsg = "✅ Compra cargada. Producto y variantes creadas correctamente.";
    $created = ['product_id'=>$product_id,'name'=>$name,'image_url'=>$image_url,'total'=>$totalCompra];
  } catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof mysqli) { @$conexion->rollback(); }
    $errMsg = '❌ '.$e->getMessage();
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Compras (Variantes múltiples)</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
  <style>
    .note{font-size:.9rem;opacity:.8}
    .hint{font-size:.85rem;opacity:.8;margin-top:6px}
    .btn{display:inline-block;padding:.55rem .9rem;border:1px solid var(--ring,#2d323d);border-radius:.7rem;background:transparent;color:inherit;text-decoration:none;cursor:pointer}
    .btn[disabled]{opacity:.6;cursor:not-allowed}
    .btn.primary{background:#0ea5e9;border-color:#0ea5e9;color:white}
    .btn.danger{border-color:#c62828;color:#c62828}
    .preview{margin-top:8px;border:1px dashed var(--ring,#2d323d);border-radius:10px;padding:8px;display:inline-block}
    .btns-row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
    .variants{width:100%; border-collapse:collapse; margin-top:8px}
    .variants th,.variants td{border:1px solid var(--ring,#2d323d); padding:6px}
    .variants th{background:rgba(0,0,0,.06); text-align:left}
    .input.small{max-width:120px}
    .input.xs{max-width:90px}
  </style>
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php page_head('Cargar compras y fotos', 'Subida directa a Cloudinary y carga de múltiples talles/variantes.'); ?>

<main class="container">
  <?php if ($okMsg!=='') { ?>
    <div class="kpi"><div class="box"><b>OK</b> <?=h($okMsg)?></div></div>
  <?php } ?>
  <?php if ($errMsg!=='') { ?>
    <div class="kpi error"><div class="box"><b>Error</b> <?=h($errMsg)?></div></div>
  <?php } ?>
  <?php if (!cloud_is_enabled()) { ?>
    <div class="kpi error"><div class="box"><b>Cloudinary requerido</b> Definí <code>CLOUDINARY_CLOUD_NAME</code> y <code>CLOUDINARY_UPLOAD_PRESET</code> (unsigned).</div></div>
  <?php } ?>

  <h2>➕ Nueva compra</h2>
  <form method="post" class="card" style="padding:14px" id="purchaseForm" <?= cloud_is_enabled() ? '' : 'onsubmit="return false;"' ?>>
    <input type="hidden" name="__action" value="create_purchase">

    <h3>Producto</h3>
    <div class="row">
      <label>Nombre <input class="input" name="name" required placeholder="Ej: Remera Luna"></label>
      <label>Categoría
        <select class="input" name="category_id" <?= ($db_ok && t_exists('categories')) ? 'required' : 'disabled' ?>>
          <?php
            if ($db_ok && t_exists('categories')) {
              $cats=@$conexion->query("SELECT id,name FROM categories WHERE active=1 ORDER BY name ASC");
              if($cats && $cats->num_rows>0){ while($cat=$cats->fetch_assoc()){ echo '<option value="'.(int)$cat['id'].'">'.h($cat['name']).'</option>'; } }
              else { echo '<option value="">(Creá categorías primero)</option>'; }
            } else { echo '<option value="">(Creá categorías primero)</option>'; }
          ?>
        </select>
      </label>
      <label>Descripción <input class="input" name="description" placeholder="Tela, composición, etc."></label>
    </div>

    <h3>Imagen del producto</h3>
    <div class="row">
      <div class="btns-row">
        <button type="button" class="btn" id="btnCamera" <?= cloud_is_enabled() ? '' : 'disabled' ?>>📸 Sacar foto</button>
        <button type="button" class="btn" id="btnGallery" <?= cloud_is_enabled() ? '' : 'disabled' ?>>🖼️ Elegir de galería</button>
        <span id="upStatus" class="note"></span>
      </div>
      <input type="file" id="image_file_camera" accept="image/*" capture="environment" style="display:none">
      <input type="file" id="image_file_gallery" accept="image/*" style="display:none">

      <label style="margin-top:8px">URL de imagen
        <input class="input" id="image_url" name="image_url" placeholder="https://… (se completa automáticamente)" readonly>
        <div class="note">La misma imagen se aplica a todas las variantes.</div>
      </label>
    </div>

    <div id="imgPreviewWrap" class="preview" style="display:none">
      <img id="imgPreview" src="" alt="preview" style="max-width:240px;height:auto;display:block">
    </div>

    <h3 style="margin-top:16px">Variantes a crear</h3>
    <div class="note">Cargá una fila por talle/color con su cantidad, costo y precio.</div>
    <table class="variants" id="variantsTable">
      <thead>
        <tr>
          <th>SKU</th>
          <th>Talle</th>
          <th>Color</th>
          <th><?= (t_exists('product_variants') && (hascol('product_variants','measure_text')||hascol('product_variants','medidas'))) ? 'Medidas' : 'Medidas (no disponible)' ?></th>
          <th>Cantidad</th>
          <th>Costo unit.</th>
          <th>Precio venta</th>
          <th style="width:1%"></th>
        </tr>
      </thead>
      <tbody id="varBody"><!-- filas dinámicas --></tbody>
    </table>
    <div style="margin-top:8px" class="btns-row">
      <button type="button" class="btn" id="btnAddRow">➕ Agregar fila</button>
      <button type="button" class="btn" id="btnAddSM">S, M, L (rápido)</button>
      <span class="note" id="sumNote"></span>
    </div>

    <h3 style="margin-top:16px">Datos de compra</h3>
    <div class="row">
      <label>Proveedor <input class="input" name="supplier" placeholder="Opcional"></label>
      <label>Notas <input class="input" name="notes" placeholder="Opcional (lote, condición)"></label>
    </div>

    <button type="submit" class="btn primary" <?= cloud_is_enabled() ? '' : 'disabled' ?>>Guardar compra</button>
  </form>

  <?php if (!empty($created)) { ?>
    <h2 class="mt-4">Vista previa</h2>
    <div class="grid">
      <div class="card">
        <?php
          $img = $created['image_url'];
          if ($img && !preg_match('~^https?://~i',$img)) { $img = url($img); }
          if (!$img) { $img = 'https://picsum.photos/640/480?random='.(int)$created['product_id']; }
        ?>
        <img src="<?= h($img) ?>" alt="<?= h($created['name']) ?>" loading="lazy" width="640" height="480">
        <div class="p">
          <h3><?= h($created['name']) ?></h3>
          <span class="badge">Total compra $ <?= money($created['total'] ?? 0) ?></span>
        </div>
      </div>
    </div>
  <?php } ?>
</main>

<?php require $root.'/includes/footer.php'; ?>

<script>
(function(){
  /* ======= Cloudinary Direct Upload (unsigned) ======= */
  const btnCam = document.getElementById('btnCamera');
  const btnGal = document.getElementById('btnGallery');
  const inCam  = document.getElementById('image_file_camera');
  const inGal  = document.getElementById('image_file_gallery');
  const urlIn  = document.getElementById('image_url');
  const prevW  = document.getElementById('imgPreviewWrap');
  const prev   = document.getElementById('imgPreview');
  const st     = document.getElementById('upStatus');

  const CLOUD = {
    name: <?= json_encode(envv('CLOUDINARY_CLOUD_NAME')) ?>,
    preset: <?= json_encode(envv('CLOUDINARY_UPLOAD_PRESET')) ?>,
    folder: <?= json_encode(envv('CLOUDINARY_FOLDER') ?: 'luna-shop/products') ?>
  };

  function humanSize(bytes){
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1024/1024).toFixed(1) + ' MB';
  }

  async function uploadDirectToCloudinary(blob){
    const fd = new FormData();
    fd.append('file', blob);
    fd.append('upload_preset', CLOUD.preset);
    fd.append('folder', CLOUD.folder);
    const endpoint = `https://api.cloudinary.com/v1_1/${CLOUD.name}/image/upload`;
    const res = await fetch(endpoint, { method:'POST', body: fd });
    const data = await res.json();
    if (!res.ok || !data.secure_url) {
      throw new Error((data && data.error && data.error.message) ? data.error.message : 'Fallo al subir a Cloudinary');
    }
    return data.secure_url;
  }

  async function handleFiles(input){
    if (!input.files || !input.files[0]) return;
    const f = input.files[0];
    const MAX = 12 * 1024 * 1024;
    if (f.size > MAX) { alert('La imagen es muy pesada ('+humanSize(f.size)+'). Máx ~12MB.'); input.value=''; return; }
    try{
      if (st) st.textContent = 'Subiendo…';
      urlIn.value = 'Subiendo…';
      const url = await uploadDirectToCloudinary(f);
      urlIn.value = url;
      prev.src = url;
      prevW.style.display = 'inline-block';
      if (st) st.textContent = 'Listo ✔';
    }catch(e){
      if (st) st.textContent = '';
      alert('Error subiendo a Cloudinary: ' + e.message);
      urlIn.value = '';
    }finally{
      input.value = '';
    }
  }

  if (btnCam) btnCam.addEventListener('click', ()=> inCam && inCam.click());
  if (btnGal) btnGal.addEventListener('click', ()=> inGal && inGal.click());
  if (inCam)  inCam.addEventListener('change', ()=> handleFiles(inCam));
  if (inGal)  inGal.addEventListener('change', ()=> handleFiles(inGal));

  /* ======= Variantes dinámicas ======= */
  const varBody = document.getElementById('varBody');
  const btnAdd  = document.getElementById('btnAddRow');
  const btnSM   = document.getElementById('btnAddSM');
  const sumNote = document.getElementById('sumNote');

  function rowTemplate(values={}){
    const canMeasure = true; // el input existe; si la tabla no lo tiene en BD será ignorado por el backend
    return `
      <tr>
        <td><input class="input small" name="sku[]" placeholder="Opcional" value="${values.sku||''}"></td>
        <td><input class="input xs" name="size[]" placeholder="S/M/L" value="${values.size||''}"></td>
        <td><input class="input xs" name="color[]" placeholder="Color" value="${values.color||''}"></td>
        <td><input class="input small" name="measure_text[]" placeholder="Ancho x Largo" value="${values.measure_text||''}"></td>
        <td><input class="input xs" name="quantity[]" type="number" min="0" step="1" value="${values.quantity??0}"></td>
        <td><input class="input xs" name="unit_cost[]" type="number" min="0" step="0.01" value="${values.unit_cost??0}"></td>
        <td><input class="input xs" name="sale_price[]" type="number" min="0" step="0.01" value="${values.sale_price??0}"></td>
        <td><button type="button" class="btn danger btn-del">✖</button></td>
      </tr>
    `;
  }

  function addRow(values) {
    const tmp = document.createElement('tbody');
    tmp.innerHTML = rowTemplate(values);
    const tr = tmp.firstElementChild;
    varBody.appendChild(tr);
    tr.querySelector('.btn-del').addEventListener('click', () => {
      tr.remove(); updateSum();
    });
    updateSum();
  }

  function updateSum(){
    let total = 0;
    varBody.querySelectorAll('tr').forEach(tr=>{
      const q = parseFloat(tr.querySelector('[name="quantity[]"]').value||'0');
      const c = parseFloat(tr.querySelector('[name="unit_cost[]"]').value||'0');
      if (q>0 && c>=0) total += q*c;
    });
    sumNote.textContent = 'Total (estimado): $ ' + total.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
  }

  if (btnAdd) btnAdd.addEventListener('click', ()=> addRow({quantity:1}));
  if (btnSM)  btnSM.addEventListener('click', ()=>{
    ['S','M','L'].forEach(sz=> addRow({size:sz, quantity:1}));
  });

  // Fila inicial
  addRow({quantity:1});
  varBody.addEventListener('input', (e)=>{
    if (e.target && (e.target.name==='quantity[]' || e.target.name==='unit_cost[]')) updateSum();
  });
})();
</script>
</body>
</html>
