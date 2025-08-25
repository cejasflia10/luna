<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';
require $root.'/includes/page_head.php'; // HERO unificado

/* Helpers por si faltan */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* Rutas dinámicas (localhost/Render) */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('url')) {
  function url($p){ global $BASE; return $BASE.'/'.ltrim($p,'/'); }
}

/* ====== Flags de esquema para evitar errores ====== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$has_categories=$has_products=$has_variants=$has_purchases=$has_purchase_items=false;
$has_created_at=false; $has_measure=false;

if ($db_ok) {
  $has_categories    = !!(@$conexion->query("SHOW TABLES LIKE 'categories'")->num_rows ?? 0);
  $has_products      = !!(@$conexion->query("SHOW TABLES LIKE 'products'")->num_rows ?? 0);
  $has_variants      = !!(@$conexion->query("SHOW TABLES LIKE 'product_variants'")->num_rows ?? 0);
  $has_purchases     = !!(@$conexion->query("SHOW TABLES LIKE 'purchases'")->num_rows ?? 0);
  $has_purchase_items= !!(@$conexion->query("SHOW TABLES LIKE 'purchase_items'")->num_rows ?? 0);

  if ($has_products) {
    $has_created_at = !!(@$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'")->num_rows ?? 0);
  }
  if ($has_variants) {
    $has_measure    = !!(@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'measure_text'")->num_rows ?? 0);
  }
}

/* ====== Datos para selects ====== */
$cats = null;
if ($db_ok && $has_categories) {
  $cats = @$conexion->query("SELECT id,name FROM categories WHERE active=1 ORDER BY name ASC");
}

/* (Opcional) productos recientes para mostrar en algún lado */
$prods = null;
if ($db_ok && $has_products) {
  $order = $has_created_at ? "created_at DESC" : "id DESC";
  $prods = @$conexion->query("SELECT id, name FROM products WHERE active=1 ORDER BY $order LIMIT 100");
}

/* ====== Alta de compra con creación de producto/variante ====== */
$okMsg=$errMsg=''; $created=[];
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_purchase') {
  if (!$has_products || !$has_variants || !$has_purchases || !$has_purchase_items) {
    $errMsg = '❌ Faltan tablas (products, product_variants, purchases o purchase_items). Ejecutá el schema.sql.';
  } else {
    $conexion->begin_transaction();
    try {
      /* ---- Imagen: archivo o URL ---- */
      $image_url = trim($_POST['image_url'] ?? '');
      if (!empty($_FILES['image_file']['name'])) {
        $f = $_FILES['image_file'];
        if ($f['error']===UPLOAD_ERR_OK) {
          $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp'];
          if (!isset($allowed[$f['type']])) { throw new Exception('Formato no permitido (JPG/PNG/WEBP).'); }
          if ($f['size'] > 4*1024*1024) { throw new Exception('Imagen muy pesada (máx 4MB).'); }

          $baseDir   = $root.'/public/uploads';
          $subDir    = date('Y').'/'.date('m');
          $targetDir = $baseDir.'/'.$subDir;
          if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true)) {
            throw new Exception('No se pudo crear la carpeta de uploads.');
          }
          $nameFile = bin2hex(random_bytes(8)).$allowed[$f['type']];
          if (!@move_uploaded_file($f['tmp_name'], $targetDir.'/'.$nameFile)) {
            throw new Exception('No se pudo guardar la imagen.');
          }
          // Guardamos ruta relativa desde /public
          $image_url = 'uploads/'.$subDir.'/'.$nameFile;
        } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE) {
          throw new Exception('Error al subir la imagen (código '.$f['error'].').');
        }
      }

      /* ---- Datos del formulario ---- */
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

      if ($name==='')    throw new Exception('El nombre del producto es obligatorio.');
      if ($has_categories && $catId<=0) throw new Exception('Seleccioná una categoría.');
      if ($qty<=0)       throw new Exception('La cantidad debe ser mayor a cero.');
      if ($unit_cost<0)  throw new Exception('El costo unitario no puede ser negativo.');
      if ($sale_price<0) throw new Exception('El precio de venta no puede ser negativo.');

      /* ---- Crear producto ---- */
      $stmt = $conexion->prepare("INSERT INTO products(name,description,image_url,active,category_id) VALUES (?,?,?,?,?)");
      $one=1;
      $stmt->bind_param('sssii', $name, $desc, $image_url, $one, $catId);
      $stmt->execute();
      $product_id = (int)$stmt->insert_id; $stmt->close();

      /* ---- Crear variante (precio de venta) ---- */
      if ($has_measure) {
        $stmt = $conexion->prepare("
          INSERT INTO product_variants(product_id,sku,size,color,measure_text,price,stock,avg_cost)
          VALUES (?,?,?,?,?,?,0,0)
        ");
        $stmt->bind_param('isssss', $product_id, $sku, $size, $color, $meas, $sale_price);
      } else {
        $stmt = $conexion->prepare("
          INSERT INTO product_variants(product_id,sku,size,color,price,stock,avg_cost)
          VALUES (?,?,?,?,?,0,0)
        ");
        $stmt->bind_param('isssd', $product_id, $sku, $size, $color, $sale_price);
      }
      $stmt->execute();
      $variant_id = (int)$stmt->insert_id; $stmt->close();

      /* ---- Crear compra + ítem ---- */
      $stmt = $conexion->prepare("INSERT INTO purchases(purchased_at,supplier,notes,total) VALUES (NOW(),?,?,0)");
      $stmt->bind_param('ss', $supplier, $notes);
      $stmt->execute();
      $purchase_id = (int)$stmt->insert_id; $stmt->close();

      $subtotal = $unit_cost * $qty;
      $stmt = $conexion->prepare("
        INSERT INTO purchase_items(purchase_id,variant_id,quantity,unit_cost,subtotal)
        VALUES (?,?,?,?,?)
      ");
      $stmt->bind_param('iiidd', $purchase_id, $variant_id, $qty, $unit_cost, $subtotal);
      $stmt->execute(); $stmt->close();

      $stmt = $conexion->prepare("UPDATE purchases SET total=? WHERE id=?");
      $stmt->bind_param('di', $subtotal, $purchase_id);
      $stmt->execute(); $stmt->close();

      /* ---- Stock + costo promedio ---- */
      $res = $conexion->query("SELECT stock, avg_cost FROM product_variants WHERE id=".$variant_id." FOR UPDATE");
      $row = $res->fetch_assoc();
      $old_stock = (int)$row['stock'];
      $old_avg   = (float)$row['avg_cost'];
      $new_stock = $old_stock + $qty;
      $new_avg   = $new_stock > 0 ? (($old_avg*$old_stock) + ($unit_cost*$qty)) / $new_stock : $unit_cost;

      $stmt = $conexion->prepare("UPDATE product_variants SET stock=?, avg_cost=?, price=? WHERE id=?");
      $stmt->bind_param('iddi', $new_stock, $new_avg, $sale_price, $variant_id);
      $stmt->execute(); $stmt->close();

      $conexion->commit();

      $okMsg = "✅ Compra cargada. Stock actualizado y precio de venta establecido.";
      $created = [
        'product_id'=>$product_id,
        'name'=>$name,
        'image_url'=>$image_url,
        'sale_price'=>$sale_price,
        'qty'=>$qty,
        'unit_cost'=>$unit_cost
      ];
    } catch (Throwable $e) {
      $conexion->rollback();
      $errMsg = '❌ '.$e->getMessage();
    }
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
      <label>Medidas <input class="input" name="measure_text" placeholder="Ancho x Largo…" <?= $has_measure?'':'disabled' ?>></label>
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
          $img = $created['image_url'] ? url($created['image_url']) : ('https://picsum.photos/640/480?random='.(int)$created['product_id']);
        ?>
        <img src="<?= h($img) ?>"
             alt="<?= h($created['name']) ?>" loading="lazy" width="640" height="480">
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
