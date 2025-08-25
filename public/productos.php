<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';
@require $root.'/includes/page_head.php'; // page_head()

/* Helpers */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }
if (!function_exists('slug'))  { function slug($s){ $s=iconv('UTF-8','ASCII//TRANSLIT',$s); $s=strtolower(preg_replace('~[^a-z0-9]+~','-',$s)); return trim($s,'-')?:'img'; } }

/* BASE dinámica */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('url')) {
  function url($path){ global $BASE; return $BASE.'/'.ltrim((string)$path,'/'); }
}

/* Flags DB/esquema */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$has_products=$has_variants=$has_categories=false;
$has_created=$has_image=$has_measure=false;

if ($db_ok) {
  $has_products   = !!(@$conexion->query("SHOW TABLES LIKE 'products'")->num_rows ?? 0);
  $has_variants   = !!(@$conexion->query("SHOW TABLES LIKE 'product_variants'")->num_rows ?? 0);
  $has_categories = !!(@$conexion->query("SHOW TABLES LIKE 'categories'")->num_rows ?? 0);

  if ($has_products) {
    $has_created = !!(@$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'")->num_rows ?? 0);
    $has_image   = !!(@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")->num_rows ?? 0);
  }
  if ($has_variants) {
    $has_measure = !!(@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'measure_text'")->num_rows ?? 0);
  }
}

/* Datos para selects */
$cats = null;
if ($db_ok && $has_categories) {
  $cats = @$conexion->query("SELECT id,name FROM categories WHERE active=1 ORDER BY name ASC");
}

/* Ítems recientes de COMPRAS para autocompletar */
$purchase_items = [];
if ($db_ok) {
  $has_pur  = !!(@$conexion->query("SHOW TABLES LIKE 'purchases'")->num_rows ?? 0);
  $has_puri = !!(@$conexion->query("SHOW TABLES LIKE 'purchase_items'")->num_rows ?? 0);
  if ($has_puri) {
    // columnas tolerantes
    $cols = [];
    foreach (['id','product_id','name','sku','size','color','measure_text','qty','price_unit','unit_cost','cost_unit','image_url'] as $c) {
      $r = @$conexion->query("SHOW COLUMNS FROM purchase_items LIKE '$c'");
      if ($r && $r->num_rows>0) $cols[$c] = true;
    }
    $sel = [];
    foreach (['id','product_id','name','sku','size','color','measure_text','qty','price_unit','unit_cost','cost_unit','image_url'] as $c) {
      $sel[] = ($cols[$c]??false) ? "pi.$c" : "NULL AS $c";
    }
    $order = "pi.id DESC";
    if ($has_pur) {
      $has_created_p = !!(@$conexion->query("SHOW COLUMNS FROM purchases LIKE 'created_at'")->num_rows ?? 0);
      if ($has_created_p) $order = "p.created_at DESC, pi.id DESC";
      $sqlpi = "SELECT ".implode(',', $sel)." FROM purchase_items pi LEFT JOIN purchases p ON p.id=pi.purchase_id ORDER BY $order LIMIT 50";
    } else {
      $sqlpi = "SELECT ".implode(',', $sel)." FROM purchase_items pi ORDER BY $order LIMIT 50";
    }
    if ($rs=@$conexion->query($sqlpi)) { while($r=$rs->fetch_assoc()){ $purchase_items[]=$r; } }
  }
}

/* === Subida de imágenes (filesystem) === */
$upload_dir_fs  = $root.'/assets/uploads/products';
$upload_dir_url = url('assets/uploads/products');
if (!is_dir($upload_dir_fs)) @mkdir($upload_dir_fs, 0777, true);

/* Alta de producto + variante (con subida de foto) */
$okMsg = $errMsg = '';
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_product') {
  try {
    if (!$has_products || !$has_variants) throw new Exception('Faltan tablas products/product_variants.');

    $conexion->begin_transaction();

    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $img   = trim($_POST['image_url'] ?? ''); // por si igual te gusta pegar URL
    $price = (float)($_POST['price'] ?? 0);
    $catId = (int)($_POST['category_id'] ?? 0);

    if ($name==='')  throw new Exception('El nombre es obligatorio.');
    if ($has_categories && $catId<=0) throw new Exception('Seleccioná una categoría.');

    /* Procesar archivo subido (si vino) */
    if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
      $tmp = $_FILES['image_file']['tmp_name'];
      $orig= $_FILES['image_file']['name'] ?? 'foto';
      $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      $ok_ext = in_array($ext, ['jpg','jpeg','png','webp']);
      if (!$ok_ext) throw new Exception('Formato de imagen no soportado. Usá JPG, PNG o WEBP.');
      $fname = date('Ymd_His').'_'.$GLOBALS['slug']($name).'_'.substr(md5($orig.microtime(true)),0,6).'.'.$ext;
      $dest  = $upload_dir_fs.'/'.$fname;
      if (!@move_uploaded_file($tmp, $dest)) throw new Exception('No pude guardar la imagen (permisos).');
      $img = $upload_dir_url.'/'.$fname; // URL relativa /assets/uploads/products/...
    }

    /* Insert del producto */
    if ($has_image) {
      $stmt = $conexion->prepare("INSERT INTO products(name,description,image_url,active,category_id) VALUES (?,?,?,?,?)");
      $one=1; $stmt->bind_param('sssii', $name, $desc, $img, $one, $catId);
    } else {
      $stmt = $conexion->prepare("INSERT INTO products(name,description,active,category_id) VALUES (?,?,?,?)");
      $one=1; $stmt->bind_param('ssii', $name, $desc, $one, $catId);
    }
    $stmt->execute();
    $pid = (int)$stmt->insert_id; $stmt->close();

    /* Variante */
    $sku     = trim($_POST['sku'] ?? '');
    $size    = trim($_POST['size'] ?? '');
    $color   = trim($_POST['color'] ?? '');
    $measure = trim($_POST['measure_text'] ?? '');
    $stock   = (int)($_POST['stock'] ?? 0);
    $avg     = (float)($_POST['avg_cost'] ?? 0);

    if ($has_measure) {
      $stmt = $conexion->prepare("INSERT INTO product_variants(product_id,sku,size,color,measure_text,price,stock,avg_cost) VALUES (?,?,?,?,?,?,?,?)");
      $stmt->bind_param('isssssid', $pid, $sku, $size, $color, $measure, $price, $stock, $avg);
    } else {
      $stmt = $conexion->prepare("INSERT INTO product_variants(product_id,sku,size,color,price,stock,avg_cost) VALUES (?,?,?,?,?,?,?)");
      $stmt->bind_param('issssii', $pid, $sku, $size, $color, $price, $stock, $avg);
    }
    $stmt->execute(); $stmt->close();

    $conexion->commit();
    $okMsg = '✅ Producto creado con su variante inicial.';
  } catch (Throwable $e) {
    if ($conexion && $conexion->errno) { $conexion->rollback(); }
    $errMsg = '❌ '.$e->getMessage();
  }
}

/* Listado */
$rows = null;
if ($db_ok && $has_products) {
  $order = $has_created ? "p.created_at DESC, p.id DESC" : "p.id DESC";
  $imgCol = $has_image ? "p.image_url" : "NULL AS image_url";
  $measCol= $has_measure ? "v.measure_text" : "'' AS measure_text";
  $rows = @$conexion->query("
    SELECT p.id,p.name, $imgCol, c.name AS category, v.sku, v.size, v.color, $measCol, v.price, v.stock
    FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    LEFT JOIN product_variants v ON v.product_id=p.id
    WHERE p.active=1
    ORDER BY $order
    LIMIT 100
  ");
  if ($rows===false) { $errMsg = '❌ Error SQL: '.$conexion->error; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Luna Shop — Productos</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
  <style>
    .thumb{width:54px;height:54px;border-radius:8px;object-fit:cover;border:1px solid var(--ring,#2d323d)}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    @media(max-width:900px){.grid2{grid-template-columns:1fr}}
  </style>
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php
page_head('Productos', 'Gestión y listado de artículos', ['label'=>'➕ Cargar por Compras','href'=>'compras.php']);
?>

<main class="container">
  <?php if($okMsg): ?><div class="kpi"><div class="box"><b>OK</b> <?=h($okMsg)?></div></div><?php endif; ?>
  <?php if($errMsg): ?><div class="kpi"><div class="box"><b>Error</b> <?=h($errMsg)?></div></div><?php endif; ?>

  <h2>➕ Nuevo producto</h2>

  <form method="post" enctype="multipart/form-data" class="card" style="padding:14px">
    <input type="hidden" name="__action" value="create_product">

    <div class="grid2">
      <div>
        <label>Nombre
          <input class="input" name="name" required>
        </label>
      </div>
      <div>
        <label>Precio sugerido ($)
          <input class="input" name="price" type="number" step="0.01" min="0" value="0">
        </label>
      </div>

      <div>
        <label>Foto (subir desde tu PC)
          <input class="input" type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp">
        </label>
        <div style="opacity:.8;font-size:.9em;margin-top:4px">Se guardará en <code>/assets/uploads/products/</code></div>
      </div>

      <div>
        <label>Imagen (URL) <small style="opacity:.7">(opcional)</small>
          <input class="input" name="image_url" placeholder="https://...">
        </label>
      </div>

      <div>
        <label>Categoría
          <select class="input" name="category_id" <?= $has_categories ? 'required' : 'disabled'?>>
            <?php if($has_categories && $cats && $cats->num_rows>0): while($cat=$cats->fetch_assoc()): ?>
              <option value="<?=$cat['id']?>"><?=h($cat['name'])?></option>
            <?php endwhile; else: ?>
              <option value="">(Creá categorías primero)</option>
            <?php endif; ?>
          </select>
        </label>
      </div>

      <div>
        <label>Descripción
          <input class="input" name="description">
        </label>
      </div>
    </div>

    <h3 style="margin-top:10px">Variante inicial</h3>
    <div class="grid2">
      <div><label>SKU <input class="input" name="sku"></label></div>
      <div><label>Talle <input class="input" name="size" placeholder="S / M / L..."></label></div>
      <div><label>Color <input class="input" name="color"></label></div>
      <div><label>Medidas <input class="input" name="measure_text" placeholder="Ancho x Largo..." <?= $has_measure?'':'disabled' ?>></label></div>
      <div><label>Stock inicial <input class="input" name="stock" type="number" min="0" value="0"></label></div>
      <div><label>Costo promedio inicial ($) <input class="input" name="avg_cost" type="number" step="0.01" min="0" value="0"></label></div>
    </div>

    <?php if(!empty($purchase_items)): ?>
      <h3 style="margin-top:10px">Tomar datos desde una compra</h3>
      <div class="grid2">
        <div style="grid-column:1/-1">
          <select class="input" id="pickPurchase">
            <option value="">— Elegí un ítem reciente de Compras —</option>
            <?php foreach($purchase_items as $pi):
              $label = trim(($pi['name']??'Ítem').' '.($pi['sku']?'#'.$pi['sku']:'').' '.($pi['size']?:'').' '.($pi['color']?:''));
              $cost = (float)($pi['price_unit'] ?? $pi['unit_cost'] ?? $pi['cost_unit'] ?? 0);
            ?>
              <option
                data-name="<?=h($pi['name']??'')?>"
                data-sku="<?=h($pi['sku']??'')?>"
                data-size="<?=h($pi['size']??'')?>"
                data-color="<?=h($pi['color']??'')?>"
                data-measure="<?=h($pi['measure_text']??'')?>"
                data-cost="<?=h($cost)?>"
                data-img="<?=h($pi['image_url']??'')?>"
              ><?=h($label)?> <?= $cost>0 ? '— $ '.money($cost) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div style="opacity:.8;font-size:.9em;margin-top:4px">Al elegir, se completan nombre, SKU, talle, color, medidas y costo sugerido.</div>
        </div>
      </div>
    <?php endif; ?>

    <div style="text-align:right;margin-top:10px">
      <button type="submit">Guardar</button>
    </div>
  </form>

  <h2 class="mt-4">Listado</h2>
  <?php if($rows && $rows->num_rows>0): ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th></th><th>Producto</th><th>Categoría</th><th>Variante</th><th>Precio</th><th>Stock</th></tr>
        </thead>
        <tbody>
        <?php while($r=$rows->fetch_assoc()): ?>
          <tr>
            <td>
              <?php $img = trim((string)($r['image_url'] ?? '')); if ($img==='') $img='https://picsum.photos/seed/'.(int)$r['id'].'/100/100'; ?>
              <img class="thumb" src="<?=h($img)?>" alt="<?=h($r['name'])?>">
            </td>
            <td><b><?=h($r['name'])?></b></td>
            <td><?=h($r['category'] ?: '—')?></td>
            <td><?=h(trim(($r['sku']?('#'.$r['sku'].' '):'').($r['size']?:'').' '.($r['color']?:'').' '.($r['measure_text']?:'')))?></td>
            <td>$ <?=money($r['price'])?></td>
            <td><?= (int)$r['stock'] ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="card" style="padding:14px"><div class="p">
      <b>No hay productos cargados aún.</b> Usá el formulario de arriba para crear el primero.
    </div></div>
  <?php endif; ?>
</main>

<?php require $root.'/includes/footer.php'; ?>

<!-- Prefill desde Compras -->
<script>
(function(){
  var sel = document.getElementById('pickPurchase');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.dataset) return;
    // campos
    let f = (n)=>document.querySelector('[name="'+n+'"]');
    if (opt.dataset.name)    f('name').value = opt.dataset.name;
    if (opt.dataset.sku)     f('sku').value = opt.dataset.sku;
    if (opt.dataset.size)    f('size').value = opt.dataset.size;
    if (opt.dataset.color)   f('color').value = opt.dataset.color;
    if (opt.dataset.measure) f('measure_text').value = opt.dataset.measure;
    if (opt.dataset.cost)    f('avg_cost').value = opt.dataset.cost;
    if (opt.dataset.img)     f('image_url').value = opt.dataset.img; // por si la compra trajo URL
  });
})();
</script>
</body>
</html>
