<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';
require $root.'/includes/page_head.php'; // HERO unificado

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

/* ===== Utilidades tolerantes al esquema ===== */
function t_exists($table){
  global $conexion;
  $rs = @$conexion->query("SHOW TABLES LIKE '$table'");
  return ($rs && $rs->num_rows>0);
}
function table_cols($table){
  global $conexion; $out=[];
  if ($rs=@$conexion->query("SHOW COLUMNS FROM `$table`")) {
    while($r=$rs->fetch_assoc()) $out[$r['Field']]=true;
  }
  return $out;
}
function hascol($table,$col){
  global $conexion;
  $rs = @$conexion->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return ($rs && $rs->num_rows>0);
}
function infer_type($v){
  if (is_int($v)) return 'i';
  if (is_float($v)) return 'd';
  if (is_numeric($v)) return (str_contains((string)$v,'.')?'d':'i');
  return 's';
}
function insert_filtered($table, $data){
  global $conexion;
  $cols_exist = table_cols($table);
  $data2 = [];
  foreach($data as $k=>$v){ if(isset($cols_exist[$k])) $data2[$k]=$v; }
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

/* ===== Datos para selects ===== */
$has_categories = $db_ok && t_exists('categories');
$cats = null;
if ($has_categories) {
  $cats = @$conexion->query("SELECT id,name FROM categories WHERE active=1 ORDER BY name ASC");
}
$has_measure_input = $db_ok && hascol('product_variants','measure_text');

/* ===== Alta de compra + creación producto/variante ===== */
$okMsg=$errMsg=''; $created=[];
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_purchase') {
  try {
    if (!t_exists('products') || !t_exists('product_variants')) {
      throw new Exception('Faltan tablas mínimas: products y/o product_variants.');
    }

    /* Imagen (archivo o URL) */
    $image_url = trim($_POST['image_url'] ?? '');
    if (!empty($_FILES['image_file']['name'])) {
      $f = $_FILES['image_file'];
      if ($f['error']===UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp'];
        if (!isset($allowed[$f['type']]))  throw new Exception('Formato no permitido (JPG/PNG/WEBP).');
        if ($f['size'] > 4*1024*1024)      throw new Exception('Imagen muy pesada (máx 4MB).');

        $baseDir   = $root.'/public/uploads';
        $subDir    = date('Y').'/'.date('m');
        $targetDir = $baseDir.'/'.$subDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true))
          throw new Exception('No se pudo crear la carpeta de uploads.');

        $nameFile  = bin2hex(random_bytes(8)).$allowed[$f['type']];
        if (!@move_uploaded_file($f['tmp_name'], $targetDir.'/'.$nameFile))
          throw new Exception('No se pudo guardar la imagen.');

        $image_url = 'uploads/'.$subDir.'/'.$nameFile; // relativa a /public
      } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE) {
        throw new Exception('Error al subir la imagen (código '.$f['error'].').');
      }
    }

    /* Datos del formulario */
    $name   = trim($_POST['name'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $sku    = trim($_POST['sku'] ?? '');
    $size   = trim($_POST['size'] ?? '');
    $color  = trim($_POST['color'] ?? '');
    $meas   = trim($_POST['measure_text'] ?? '');
    $sale_price = (float)($_POST['sale_price'] ?? 0);
    $catId  = (int)($_POST['category_id'] ?? 0);

    $qty        = (int)($_POST['quantity'] ?? 0);
    $unit_cost  = (float)($_POST['unit_cost'] ?? 0);
    $supplier   = trim($_POST['supplier'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    if ($name==='') throw new Exception('El nombre del producto es obligatorio.');
    if ($has_categories && $catId<=0) throw new Exception('Seleccioná una categoría.');
    if ($qty<=0) throw new Exception('La cantidad debe ser mayor a cero.');
    if ($unit_cost<0 || $sale_price<0) throw new Exception('Costos/precios no pueden ser negativos.');

    $conexion->begin_transaction();

    /* SKU para products: siempre lo mandamos; si está vacío generamos uno.
       Si la columna no existe, insert_filtered lo descartará sin romperse. */
    $sku_for_product = $sku;
    if ($sku_for_product === '') {
      $sku_for_product = 'P'.date('ymdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));
    }

    /* INSERT products (sólo columnas existentes) */
    $product_id = insert_filtered('products', [
      'name'        => $name,
      'description' => $desc,
      'image_url'   => $image_url,
      'active'      => 1,
      'category_id' => $catId,
      'sku'         => $sku_for_product,   // <-- SIEMPRE presente
    ]);

    /* INSERT product_variants (stock inicial y costos) */
    $variant_id = insert_filtered('product_variants', [
      'product_id'   => $product_id,
      'sku'          => $sku,
      'size'         => $size,
      'color'        => $color,
      'measure_text' => $meas,
      'price'        => $sale_price,
      'stock'        => $qty,
      'avg_cost'     => $unit_cost,
    ]);

    /* Registrar compra si existen las tablas */
    if (t_exists('purchases') && t_exists('purchase_items')) {
      $p_date_col = hascol('purchases','purchased_at') ? 'purchased_at' : (hascol('purchases','created_at') ? 'created_at' : null);
      $pdata = [];
      if ($p_date_col) $pdata[$p_date_col] = date('Y-m-d H:i:s');
      if (hascol('purchases','supplier')) $pdata['supplier'] = $supplier;
      if (hascol('purchases','notes'))    $pdata['notes']    = $notes;
      if (hascol('purchases','status'))   $pdata['status']   = 'done';
      if (hascol('purchases','total'))    $pdata['total']    = $unit_cost * $qty;

      if ($pdata) {
        $purchase_id = insert_filtered('purchases', $pdata);

        $pi = ['purchase_id'=>$purchase_id];
        if (hascol('purchase_items','variant_id')) $pi['variant_id'] = $variant_id;
        if (hascol('purchase_items','product_id')) $pi['product_id'] = $product_id;

        if (hascol('purchase_items','quantity')) $pi['quantity'] = $qty;
        elseif (hascol('purchase_items','qty'))  $pi['qty']      = $qty;

        if (hascol('purchase_items','unit_cost'))     $pi['unit_cost'] = $unit_cost;
        elseif (hascol('purchase_items','cost_unit')) $pi['cost_unit'] = $unit_cost;
        elseif (hascol('purchase_items','price_unit'))$pi['price_unit']= $unit_cost;

        if (hascol('purchase_items','subtotal')) $pi['subtotal'] = $unit_cost * $qty;

        foreach(['name'=>$name,'sku'=>$sku,'size'=>$size,'color'=>$color,'measure_text'=>$meas,'image_url'=>$image_url] as $k=>$v){
          if (hascol('purchase_items',$k)) $pi[$k] = $v;
        }
        insert_filtered('purchase_items', $pi);
      }
    }

    $conexion->commit();

    $okMsg = "✅ Compra cargada. Producto y variante creados correctamente.";
    $created = [
      'product_id'=>$product_id,
      'name'=>$name,
      'image_url'=>$image_url,
      'sale_price'=>$sale_price,
      'qty'=>$qty,
      'unit_cost'=>$unit_cost
    ];
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
  <title>Luna — Compras</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php
page_head('Cargar compras y fotos', 'Creá el producto con categoría, subí la foto, cargá costo y cantidad; fijá precio de venta.');
?>

<main class="container">
  <?php if($okMsg): ?><div class="kpi"><div class="box"><b>OK</b> <?=h($okMsg)?></div></div><?php endif; ?>
  <?php if($errMsg): ?><div class="kpi"><div class="box"><b>Error</b> <?=h($errMsg)?></div></div><?php endif; ?>

  <h2>➕ Nueva compra</h2>
  <form method="post" enctype="multipart/form-data" class="card" style="padding:14px">
    <input type="hidden" name="__action" value="create_purchase">

    <h3>Producto</h3>
    <div class="row">
      <label>Nombre <input class="input" name="name" required placeholder="Ej: Remera Luna"></label>
      <label>Categoría
        <select class="input" name="category_id" <?= $has_categories ? 'required' : 'disabled' ?>>
          <?php if($has_categories && $cats && $cats->num_rows>0): while($cat=$cats->fetch_assoc()): ?>
            <option value="<?=$cat['id']?>"><?=h($cat['name'])?></option>
          <?php endwhile; else: ?>
            <option value="">(Creá categorías primero)</option>
          <?php endif; ?>
        </select>
      </label>
      <label>Descripción <input class="input" name="description" placeholder="Tela, composición, etc."></label>
    </div>

    <div class="row">
      <label>Imagen (subir del celu)
        <input class="input" type="file" name="image_file" accept="image/*" capture="environment">
      </label>
      <label>…o URL de imagen
        <input class="input" name="image_url" placeholder="https://… (opcional)">
      </label>
    </div>

    <h3>Variante inicial</h3>
    <div class="row">
      <label>SKU <input class="input" name="sku" placeholder="Opcional"></label>
      <label>Talle <input class="input" name="size" placeholder="S / M / L…"></label>
      <label>Color <input class="input" name="color" placeholder="Negro / Azul…"></label>
      <label>Medidas <input class="input" name="measure_text" placeholder="Ancho x Largo…" <?= $has_measure_input?'':'disabled' ?>></label>
    </div>

    <h3>Compra</h3>
    <div class="row">
      <label>Cantidad <input class="input" name="quantity" type="number" min="1" value="1" required></label>
      <label>Costo unitario ($) <input class="input" name="unit_cost" type="number" step="0.01" min="0" value="0" required></label>
      <label>Proveedor <input class="input" name="supplier" placeholder="Opcional"></label>
    </div>

    <h3>Precio de venta</h3>
    <div class="row">
      <label>Precio sugerido ($) <input class="input" name="sale_price" type="number" step="0.01" min="0" value="0" required></label>
      <label>Notas <input class="input" name="notes" placeholder="Opcional (lote, condición)"></label>
    </div>

    <button type="submit">Guardar compra</button>
  </form>

  <?php if($created): ?>
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
          <span class="badge">Stock +<?= (int)$created['qty'] ?></span>
          <span class="badge">Costo $ <?= money($created['unit_cost']) ?></span>
          <span class="badge">Venta $ <?= money($created['sale_price']) ?></span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</main>

<?php require $root.'/includes/footer.php'; ?>
</body>
</html>
