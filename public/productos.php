<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';
@require $root.'/includes/page_head.php'; // page_head()

/* Helpers */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
  function money($n){ return number_format((float)$n, 2, ',', '.'); }
}
if (!function_exists('slug')) {
  function slug($s){
    $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
    if ($t!==false) $s=$t;
    $s = strtolower(preg_replace('~[^a-z0-9]+~','-',$s));
    return trim($s,'-') ?: 'img';
  }
}

/* BASE dinámica */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if (!function_exists('url')) {
  function url($path){ global $BASE; return rtrim($BASE,'/').'/'.ltrim((string)$path,'/'); }
}

/* Conexión y flags */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$has_products=$has_variants=$has_categories=false;
$has_created=$has_image=false;

if ($db_ok) {
  $has_products   = !!(@$conexion->query("SHOW TABLES LIKE 'products'")->num_rows ?? 0);
  $has_variants   = !!(@$conexion->query("SHOW TABLES LIKE 'product_variants'")->num_rows ?? 0);
  $has_categories = !!(@$conexion->query("SHOW TABLES LIKE 'categories'")->num_rows ?? 0);

  if ($has_products) {
    $has_created = !!(@$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'")->num_rows ?? 0);
    $has_image   = !!(@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")->num_rows ?? 0);
  }
}

/* Utilidades de esquema dinámico */
function hascol($table,$col){
  global $conexion;
  $rs = @$conexion->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return ($rs && $rs->num_rows>0);
}
function table_cols($table){
  global $conexion; $out=[];
  if ($rs=@$conexion->query("SHOW COLUMNS FROM `$table`")) {
    while($r=$rs->fetch_assoc()) $out[$r['Field']]=$r;
  }
  return $out;
}
function infer_type($v){
  if (is_int($v)) return 'i';
  if (is_float($v)) return 'd';
  if (is_numeric($v)) return (str_contains((string)$v,'.')?'d':'i');
  return 's';
}
function default_for_type($mysqlType){
  $t=strtolower($mysqlType);
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

  // Completar NOT NULL sin default
  foreach ($cols_info as $name=>$info) {
    $isNotNull = (strtoupper($info['Null']??'YES')==='NO');
    $hasDefault= !is_null($info['Default']);
    if ($isNotNull && !$hasDefault && !array_key_exists($name,$data2)) {
      $data2[$name] = default_for_type($info['Type'] ?? 'varchar(255)');
    }
  }

  if (!$data2) throw new Exception("No hay columnas compatibles para `$table`.");
  $cols = array_keys($data2);
  $ph   = array_fill(0,count($cols),'?');
  $types=''; $params=[];
  foreach($cols as $c){ $types .= infer_type($data2[$c]); $params[]=$data2[$c]; }

  $sql = "INSERT INTO `$table` (`".implode("`,`",$cols)."`) VALUES (".implode(',',$ph).")";
  $stmt = $conexion->prepare($sql);
  if (!$stmt) throw new Exception("SQL PREPARE ($table): ".$conexion->error." — ".$sql);
  $stmt->bind_param($types, ...$params);
  if (!$stmt->execute()){ $e=$stmt->error; $stmt->close(); throw new Exception("SQL EXEC ($table): $e — ".$sql); }
  $id=$stmt->insert_id; $stmt->close(); return $id;
}

/* Datos para selects */
$cats = null;
if ($db_ok && $has_categories) {
  $cats = @$conexion->query("SELECT id,name FROM categories WHERE active=1 ORDER BY name ASC");
}

/* Ítems recientes de COMPRAS (prefill) */
$purchase_items = [];
if ($db_ok) {
  $has_pur  = !!(@$conexion->query("SHOW TABLES LIKE 'purchases'")->num_rows ?? 0);
  $has_puri = !!(@$conexion->query("SHOW TABLES LIKE 'purchase_items'")->num_rows ?? 0);
  if ($has_puri) {
    $wanted = ['id','product_id','name','sku','size','talla','color','measure_text','medidas','qty','price_unit','unit_cost','cost_unit','image_url'];
    $cols = [];
    foreach ($wanted as $c) {
      $r = @$conexion->query("SHOW COLUMNS FROM purchase_items LIKE '$c'");
      if ($r && $r->num_rows>0) $cols[$c] = true;
    }
    $sel=[];
    foreach ($wanted as $c) { $sel[] = ($cols[$c]??false) ? "pi.$c" : "NULL AS $c"; }

    $order="pi.id DESC";
    if ($has_pur) {
      $has_created_p = !!(@$conexion->query("SHOW COLUMNS FROM purchases LIKE 'created_at'")->num_rows ?? 0);
      if ($has_created_p) $order="p.created_at DESC, pi.id DESC";
      $sqlpi="SELECT ".implode(',',$sel)." FROM purchase_items pi LEFT JOIN purchases p ON p.id=pi.purchase_id ORDER BY $order LIMIT 50";
    } else {
      $sqlpi="SELECT ".implode(',',$sel)." FROM purchase_items pi ORDER BY $order LIMIT 50";
    }
    if ($rs=@$conexion->query($sqlpi)) { while($r=$rs->fetch_assoc()){ $purchase_items[]=$r; } }
  }
}

/* Subida de imágenes (guardar dentro de /public) */
$upload_dir_fs  = $root.'/public/assets/uploads/products';
$upload_dir_url = url('assets/uploads/products');
if (!is_dir($upload_dir_fs)) @mkdir($upload_dir_fs, 0777, true);

/* Alta de producto + variante */
$okMsg = $errMsg = '';
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_product') {
  try {
    if (!$has_products || !$has_variants) throw new Exception('Faltan tablas products/product_variants.');
    $conexion->begin_transaction();

    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $img   = trim($_POST['image_url'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $catId = (int)($_POST['category_id'] ?? 0);

    if ($name==='') throw new Exception('El nombre es obligatorio.');
    if ($has_categories && $catId<=0) throw new Exception('Seleccioná una categoría.');

    // Archivo de imagen (opcional)
    if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
      $tmp  = $_FILES['image_file']['tmp_name'];
      $orig = $_FILES['image_file']['name'] ?? 'foto';
      $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      if (!in_array($ext,['jpg','jpeg','png','webp'])) throw new Exception('Formato no soportado. Usá JPG, PNG o WEBP.');
      $fname = date('Ymd_His').'_' . slug($name) . '_' . substr(md5($orig.microtime(true)),0,6).'.'.$ext;
      $dest  = $upload_dir_fs.'/'.$fname;
      if (!@move_uploaded_file($tmp, $dest)) throw new Exception('No pude guardar la imagen (permisos).');
      $img = $upload_dir_url.'/'.$fname;
    }

    // Datos de variante desde el form
    $sku     = trim($_POST['sku'] ?? '');
    $size    = trim($_POST['size'] ?? '');
    $color   = trim($_POST['color'] ?? '');
    $measure = trim($_POST['measure_text'] ?? '');
    $stock   = (int)($_POST['stock'] ?? 0);
    $avg     = (float)($_POST['avg_cost'] ?? 0);

    // INSERT products (dinámico y tolerante)
    $p_data = [
      'name'        => $name,
      'description' => $desc,
      'image_url'   => $img,
      'active'      => 1,
      'category_id' => $catId,
      // si products.sku existe y es NOT NULL, lo mandamos:
      'sku'         => ($sku!=='' ? $sku : ('P'.date('ymdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6)))),
      // si products.slug existe:
      'slug'        => slug($name),
    ];
    $pid = insert_filtered('products', $p_data);

    // INSERT product_variants (mapa tolerante a size/talla, measure_text/medidas, price/precio, stock/existencia…)
    $pv = [
      'product_id'   => $pid,
      'sku'          => $sku,
      'color'        => $color,
      'price'        => $price,
      'avg_cost'     => $avg,
      'stock'        => $stock,
      // sinónimos:
      'talla'        => $size,
      'size'         => $size,
      'measure_text' => $measure,
      'medidas'      => $measure,
      'precio'       => $price,
      'existencia'   => $stock,
      'average_cost' => $avg,
      'costo_promedio'=> $avg,
      'costo_prom'   => $avg,
    ];
    $vid = insert_filtered('product_variants', $pv);

    $conexion->commit();
    $okMsg = '✅ Producto creado con su variante inicial.';
  } catch (Throwable $e) {
    if ($conexion && $conexion instanceof mysqli) { @$conexion->rollback(); }
    $errMsg = '❌ '.$e->getMessage();
  }
}

/* Listado (SELECT tolerante a columnas) */
$rows = null;
if ($db_ok && $has_products) {
  $order = $has_created ? "p.created_at DESC, p.id DESC" : "p.id DESC";

  $imgCol   = $has_image ? "p.image_url" : "NULL AS image_url";
  // size/talla -> AS size
  if (hascol('product_variants','size'))      $sizeCol="v.size";
  elseif (hascol('product_variants','talla')) $sizeCol="v.talla AS size";
  else                                        $sizeCol="'' AS size";

  // color
  $colorCol = hascol('product_variants','color') ? "v.color" : "'' AS color";

  // price/precio -> AS price
  if (hascol('product_variants','price'))      $priceCol="v.price";
  elseif (hascol('product_variants','precio')) $priceCol="v.precio AS price";
  else                                          $priceCol="0 AS price";

  // stock/existencia -> AS stock
  if (hascol('product_variants','stock'))          $stockCol="v.stock";
  elseif (hascol('product_variants','existencia')) $stockCol="v.existencia AS stock";
  else                                             $stockCol="0 AS stock";

  // measure_text/medidas -> AS measure_text
  if (hascol('product_variants','measure_text'))      $measCol="v.measure_text";
  elseif (hascol('product_variants','medidas'))       $measCol="v.medidas AS measure_text";
  else                                                $measCol="'' AS measure_text";

  $rows = @$conexion->query("
    SELECT p.id, p.name, $imgCol, c.name AS category, v.sku, $sizeCol, $colorCol, $measCol, $priceCol, $stockCol
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
        <label>Foto (subir desde tu PC / celu)
          <input class="input" type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp">
        </label>
        <div style="opacity:.8;font-size:.9em;margin-top:4px">Se guarda en <code>/public/assets/uploads/products/</code></div>
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
      <div><label>Medidas <input class="input" name="measure_text" placeholder="Ancho x Largo..."></label></div>
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
              $label = trim(($pi['name']??'Ítem').' '.(!empty($pi['sku'])?'#'.$pi['sku']:'').' '.(($pi['size']??$pi['talla'])?:'').' '.(($pi['color']??'')?:''));
              $cost = (float)($pi['price_unit'] ?? $pi['unit_cost'] ?? $pi['cost_unit'] ?? 0);
              $meas = (string)($pi['measure_text'] ?? $pi['medidas'] ?? '');
            ?>
              <option
                data-name="<?=h($pi['name']??'')?>"
                data-sku="<?=h($pi['sku']??'')?>"
                data-size="<?=h(($pi['size']??$pi['talla'])??'')?>"
                data-color="<?=h($pi['color']??'')?>"
                data-measure="<?=h($meas)?>"
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
            <td><?=h(trim(($r['sku']?('#'.$r['sku'].' '):'').(($r['size']??'')?:'').' '.(($r['color']??'')?:'').' '.(($r['measure_text']??'')?:'')))?></td>
            <td>$ <?=money((float)($r['price'] ?? 0))?></td>
            <td><?= (int)($r['stock'] ?? 0) ?></td>
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
    let f = (n)=>document.querySelector('[name="'+n+'"]');
    if (opt.dataset.name)    f('name').value = opt.dataset.name;
    if (opt.dataset.sku)     f('sku').value = opt.dataset.sku;
    if (opt.dataset.size)    f('size').value = opt.dataset.size;
    if (opt.dataset.color)   f('color').value = opt.dataset.color;
    if (opt.dataset.measure) f('measure_text').value = opt.dataset.measure;
    if (opt.dataset.cost)    f('avg_cost').value = opt.dataset.cost;
    if (opt.dataset.img)     f('image_url').value = opt.dataset.img;
  });
})();
</script>
</body>
</html>
