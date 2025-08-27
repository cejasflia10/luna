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
function update_filtered($table, $id, $data, $idcol='id'){
  global $conexion;
  $cols_info = table_cols($table);
  $data2 = [];
  foreach($data as $k=>$v){ if(isset($cols_info[$k])) $data2[$k]=$v; }
  if (!$data2) return false;
  $set=[]; $types=''; $params=[];
  foreach($data2 as $k=>$v){ $set[]="`$k`=?"; $types .= infer_type($v); $params[]=$v; }
  $sql="UPDATE `$table` SET ".implode(',',$set)." WHERE `$idcol`=? LIMIT 1";
  $types .= 'i'; $params[]=(int)$id;
  $stmt=$conexion->prepare($sql);
  if(!$stmt) throw new Exception("SQL PREPARE ($table): ".$conexion->error." — ".$sql);
  $stmt->bind_param($types, ...$params);
  if(!$stmt->execute()){ $e=$stmt->error; $stmt->close(); throw new Exception("SQL EXEC ($table): $e — ".$sql); }
  $aff=$stmt->affected_rows; $stmt->close(); return $aff>=0;
}

/* Datos para selects */
$cats = null;
if ($db_ok && $has_categories) {
  $cats = @$conexion->query("SELECT id,name FROM categories WHERE active=1 ORDER BY name ASC");
}

/* Subida de imágenes (guardar dentro de /public) */
$upload_dir_fs  = $root.'/public/assets/uploads/products';
$upload_dir_url = url('assets/uploads/products');
if (!is_dir($upload_dir_fs)) @mkdir($upload_dir_fs, 0777, true);

/* --- Mensajes --- */
$okMsg = $errMsg = '';

/* === Crear producto + variante === */
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

    // INSERT products
    $p_data = [
      'name'        => $name,
      'description' => $desc,
      'image_url'   => $img,
      'active'      => 1,
      'category_id' => $catId,
      'sku'         => ($sku!=='' ? $sku : ('P'.date('ymdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6)))),
      'slug'        => slug($name),
    ];
    $pid = insert_filtered('products', $p_data);

    // INSERT product_variants (sinónimos)
    $pv = [
      'product_id'   => $pid,
      'sku'          => $sku,
      'color'        => $color,
      'price'        => $price,
      'avg_cost'     => $avg,
      'stock'        => $stock,
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
    $okMsg = '✅ Producto creado con su variante.';
  } catch (Throwable $e) {
    if ($conexion && $conexion instanceof mysqli) { @$conexion->rollback(); }
    $errMsg = '❌ '.$e->getMessage();
  }
}

/* === Editar producto/variante === */
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='update_product') {
  try {
    if (!$has_products) throw new Exception('Tabla products no existe.');
    $pid = (int)($_POST['product_id'] ?? 0);
    $vid = (int)($_POST['variant_id'] ?? 0);
    if ($pid<=0) throw new Exception('ID de producto inválido.');

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
      if (!in_array($ext,['jpg','jpeg','png','webp'])) throw new Exception('Formato no soportado (JPG/PNG/WEBP).');
      $fname = date('Ymd_His').'_' . slug($name) . '_' . substr(md5($orig.microtime(true)),0,6).'.'.$ext;
      $dest  = $upload_dir_fs.'/'.$fname;
      if (!@move_uploaded_file($tmp, $dest)) throw new Exception('No pude guardar la imagen (permisos).');
      $img = $upload_dir_url.'/'.$fname;
    }

    $sku     = trim($_POST['sku'] ?? '');
    $size    = trim($_POST['size'] ?? '');
    $color   = trim($_POST['color'] ?? '');
    $measure = trim($_POST['measure_text'] ?? '');
    $stock   = (int)($_POST['stock'] ?? 0);
    $avg     = (float)($_POST['avg_cost'] ?? 0);

    $conexion->begin_transaction();

    // UPDATE products (solo columnas existentes)
    $p_upd = [
      'name'        => $name,
      'description' => $desc,
      'category_id' => $catId,
      'slug'        => slug($name),
    ];
    if ($img!=='') $p_upd['image_url'] = $img;
    // si querés guardar sku a nivel producto (si existe la col)
    if ($sku!=='') $p_upd['sku'] = $sku;

    update_filtered('products', $pid, $p_upd, 'id');

    // UPDATE variant si tenemos tabla + id
    if ($has_variants) {
      // Si no vino variant_id, tomar la primera variante del producto
      if ($vid<=0) {
        if ($rs=@$conexion->query("SELECT id FROM product_variants WHERE product_id={$pid} ORDER BY id ASC LIMIT 1")) {
          if ($r=$rs->fetch_assoc()) $vid=(int)$r['id'];
        }
      }
      if ($vid>0) {
        $v_upd = [
          'sku'          => $sku,
          'color'        => $color,
          'price'        => $price,
          'avg_cost'     => $avg,
          'stock'        => $stock,
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
        update_filtered('product_variants', $vid, $v_upd, 'id');
      }
    }

    $conexion->commit();
    $okMsg = '✅ Producto actualizado.';
  } catch (Throwable $e) {
    if ($conexion && $conexion instanceof mysqli) { @$conexion->rollback(); }
    $errMsg = '❌ '.$e->getMessage();
  }
}

/* === Eliminar (baja lógica o hard delete) === */
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='delete_product') {
  try {
    $pid = (int)($_POST['product_id'] ?? 0);
    if ($pid<=0) throw new Exception('ID inválido.');

    if (hascol('products','active')) {
      $stmt=$conexion->prepare("UPDATE products SET active=0 WHERE id=? LIMIT 1");
      if (!$stmt) throw new Exception('SQL PREPARE: '.$conexion->error);
      $stmt->bind_param('i',$pid);
      $stmt->execute(); $stmt->close();
    } else {
      // Borrado duro
      if ($has_variants) {
        $stmt=$conexion->prepare("DELETE FROM product_variants WHERE product_id=?");
        if ($stmt){ $stmt->bind_param('i',$pid); $stmt->execute(); $stmt->close(); }
      }
      $stmt=$conexion->prepare("DELETE FROM products WHERE id=? LIMIT 1");
      if (!$stmt) throw new Exception('SQL PREPARE: '.$conexion->error);
      $stmt->bind_param('i',$pid);
      $stmt->execute(); $stmt->close();
    }

    $okMsg = '🗑️ Producto eliminado.';
  } catch (Throwable $e) {
    $errMsg = '❌ '.$e->getMessage();
  }
}

/* === Cargar datos para edición si viene ?edit=ID === */
$edit = null; // ['p'=>..., 'v'=>...]
if ($db_ok && $has_products && isset($_GET['edit'])) {
  $eid = (int)$_GET['edit'];
  if ($eid>0) {
    $edit = ['p'=>null,'v'=>null];
    if ($rp=@$conexion->query("SELECT * FROM products WHERE id={$eid} LIMIT 1")) {
      $edit['p']=$rp->fetch_assoc();
    }
    $vid = (int)($_GET['vid'] ?? 0);
    if ($has_variants) {
      if ($vid>0) {
        $rv=@$conexion->query("SELECT * FROM product_variants WHERE id={$vid} AND product_id={$eid} LIMIT 1");
      } else {
        $rv=@$conexion->query("SELECT * FROM product_variants WHERE product_id={$eid} ORDER BY id ASC LIMIT 1");
      }
      if ($rv) $edit['v']=$rv->fetch_assoc();
    }
    if (!$edit['p']) $edit=null;
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

  $vidCol = hascol('product_variants','id') ? "v.id AS variant_id" : "0 AS variant_id";

  $activeFilter = hascol('products','active') ? "WHERE p.active=1" : "";
  $rows = @$conexion->query("
    SELECT p.id, p.name, $imgCol, c.name AS category, $vidCol, v.sku, $sizeCol, $colorCol, $measCol, $priceCol, $stockCol
    FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    LEFT JOIN product_variants v ON v.product_id=p.id
    $activeFilter
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
    .actions{white-space:nowrap}
    .btn{display:inline-block;padding:.35rem .6rem;border:1px solid var(--ring,#2d323d);border-radius:.5rem;background:transparent;color:inherit;text-decoration:none;cursor:pointer}
    .btn.del{border-color:#7f1d1d}
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

  <?php if($edit && $edit['p']): ?>
    <h2>✏️ Editar producto</h2>
    <form method="post" enctype="multipart/form-data" class="card" style="padding:14px">
      <input type="hidden" name="__action" value="update_product">
      <input type="hidden" name="product_id" value="<?= (int)$edit['p']['id'] ?>">
      <input type="hidden" name="variant_id" value="<?= (int)($edit['v']['id'] ?? 0) ?>">

      <div class="grid2">
        <div>
          <label>Nombre
            <input class="input" name="name" required value="<?= h($edit['p']['name'] ?? '') ?>">
          </label>
        </div>
        <div>
          <label>Precio ($)
            <?php
              $p_price = $edit['v']['price'] ?? ($edit['v']['precio'] ?? 0);
            ?>
            <input class="input" name="price" type="number" step="0.01" min="0" value="<?= h((float)$p_price) ?>">
          </label>
        </div>

        <div>
          <label>Foto (subir nueva)
            <input class="input" type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp">
          </label>
          <div style="opacity:.8;font-size:.9em;margin-top:4px">Si subís una nueva, se reemplaza la URL actual.</div>
        </div>

        <div>
          <label>Imagen (URL)
            <input class="input" name="image_url" placeholder="https://..." value="<?= h($edit['p']['image_url'] ?? '') ?>">
          </label>
        </div>

        <div>
          <label>Categoría
            <select class="input" name="category_id" <?= $has_categories ? 'required' : 'disabled'?>>
              <?php if($has_categories && $cats && $cats->num_rows>0): 
                $cur=(int)($edit['p']['category_id'] ?? 0);
                while($cat=$cats->fetch_assoc()): ?>
                <option value="<?=$cat['id']?>" <?= $cur== (int)$cat['id'] ? 'selected' : '' ?>><?=h($cat['name'])?></option>
              <?php endwhile; else: ?>
                <option value="">(Creá categorías primero)</option>
              <?php endif; ?>
            </select>
          </label>
        </div>

        <div>
          <label>Descripción
            <input class="input" name="description" value="<?= h($edit['p']['description'] ?? '') ?>">
          </label>
        </div>
      </div>

      <h3 style="margin-top:10px">Variante</h3>
      <div class="grid2">
        <div><label>SKU <input class="input" name="sku" value="<?= h($edit['v']['sku'] ?? ($edit['p']['sku'] ?? '')) ?>"></label></div>
        <?php $vsize = $edit['v']['size'] ?? ($edit['v']['talla'] ?? ''); ?>
        <div><label>Talle <input class="input" name="size" value="<?= h($vsize) ?>" placeholder="S / M / L..."></label></div>
        <div><label>Color <input class="input" name="color" value="<?= h($edit['v']['color'] ?? '') ?>"></label></div>
        <?php $vmeas = $edit['v']['measure_text'] ?? ($edit['v']['medidas'] ?? ''); ?>
        <div><label>Medidas <input class="input" name="measure_text" value="<?= h($vmeas) ?>" placeholder="Ancho x Largo..."></label></div>
        <?php $vstock = $edit['v']['stock'] ?? ($edit['v']['existencia'] ?? 0); ?>
        <div><label>Stock <input class="input" name="stock" type="number" min="0" value="<?= (int)$vstock ?>"></label></div>
        <?php $vavg = $edit['v']['avg_cost'] ?? ($edit['v']['average_cost'] ?? ($edit['v']['costo_promedio'] ?? ($edit['v']['costo_prom'] ?? 0))); ?>
        <div><label>Costo promedio ($) <input class="input" name="avg_cost" type="number" step="0.01" min="0" value="<?= h((float)$vavg) ?>"></label></div>
      </div>

      <div style="text-align:right;margin-top:10px">
        <a class="btn" href="<?= url('productos.php') ?>">Cancelar</a>
        <button type="submit" class="btn">💾 Guardar cambios</button>
      </div>
    </form>
  <?php else: ?>
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

      <div style="text-align:right;margin-top:10px">
        <button type="submit" class="btn">Guardar</button>
      </div>
    </form>
  <?php endif; ?>

  <h2 class="mt-4">Listado</h2>
  <?php if($rows && $rows->num_rows>0): ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th></th><th>Producto</th><th>Categoría</th><th>Variante</th><th>Precio</th><th>Stock</th><th class="actions">Acciones</th></tr>
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
            <td class="actions">
              <a class="btn" href="<?= url('productos.php?edit='.(int)$r['id'].'&vid='.(int)($r['variant_id'] ?? 0)) ?>">✏️ Editar</a>
              <form method="post" action="<?= url('productos.php') ?>" style="display:inline" onsubmit="return confirm('¿Eliminar este producto?')">
                <input type="hidden" name="__action" value="delete_product">
                <input type="hidden" name="product_id" value="<?= (int)$r['id'] ?>">
                <button class="btn del" type="submit">🗑 Eliminar</button>
              </form>
            </td>
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
</body>
</html>
