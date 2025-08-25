<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require dirname(__DIR__).'/includes/conn.php';

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2, ',', '.'); }

/* ===== Cargar lista de productos para sugerir (opcional) ===== */
$prods = $conexion->query("SELECT id, name FROM products WHERE active=1 ORDER BY created_at DESC LIMIT 100");

/* ===== Lógica de guardado ===== */
$okMsg=$errMsg=''; $created=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_purchase') {
  $conexion->begin_transaction();
  try {
    /* 1) Subida de imagen (opcional) */
    $image_url = trim($_POST['image_url'] ?? '');
    if (!empty($_FILES['image_file']['name'])) {
      $f = $_FILES['image_file'];
      if ($f['error']===UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp'];
        if (!isset($allowed[$f['type']])) { throw new Exception('Formato de imagen no permitido. Usa JPG/PNG/WEBP.'); }
        if ($f['size'] > 4*1024*1024) { throw new Exception('Imagen muy pesada (máx 4MB).'); }

        // carpeta uploads/AAAA/MM/
        $baseDir = dirname(__DIR__).'/public/uploads';
        $subDir  = date('Y').'/'.date('m');
        $targetDir = $baseDir.'/'.$subDir;
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
        $name = bin2hex(random_bytes(8)).$allowed[$f['type']];
        $full = $targetDir.'/'.$name;
        if (!@move_uploaded_file($f['tmp_name'], $full)) {
          throw new Exception('No se pudo guardar la imagen.');
        }
        // URL relativa para <img src="...">
        $image_url = 'uploads/'.$subDir.'/'.$name;
      } else if ($f['error']!==UPLOAD_ERR_NO_FILE) {
        throw new Exception('Error al subir la imagen (código '.$f['error'].').');
      }
    }

    /* 2) Datos del producto/variante y compra */
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $sku   = trim($_POST['sku'] ?? '');
    $size  = trim($_POST['size'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $meas  = trim($_POST['measure_text'] ?? '');
    $sale_price = (float)($_POST['sale_price'] ?? 0);

    $qty   = (int)($_POST['quantity'] ?? 0);
    $unit_cost = (float)($_POST['unit_cost'] ?? 0);
    $supplier  = trim($_POST['supplier'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if ($name==='')          throw new Exception('El nombre del producto es obligatorio.');
    if ($qty<=0)             throw new Exception('La cantidad debe ser mayor a cero.');
    if ($unit_cost<0)        throw new Exception('El costo unitario no puede ser negativo.');
    if ($sale_price<0)       throw new Exception('El precio de venta no puede ser negativo.');

    /* 3) Crear producto */
    $stmt = $conexion->prepare("INSERT INTO products(name,description,image_url,active) VALUES (?,?,?,1)");
    $stmt->bind_param('sss', $name, $desc, $image_url);
    $stmt->execute();
    $product_id = (int)$stmt->insert_id; $stmt->close();

    /* 4) Crear variante (con precio de venta sugerido) */
    $stmt = $conexion->prepare("INSERT INTO product_variants(product_id,sku,size,color,measure_text,price,stock,avg_cost) VALUES (?,?,?,?,?,?,0,0)");
    $stmt->bind_param('isssss', $product_id, $sku, $size, $color, $meas, $sale_price);
    $stmt->execute();
    $variant_id = (int)$stmt->insert_id; $stmt->close();

    /* 5) Registrar la compra */
    $stmt = $conexion->prepare("INSERT INTO purchases(purchased_at,supplier,notes,total) VALUES (NOW(),?,?,0)");
    $stmt->bind_param('ss', $supplier, $notes);
    $stmt->execute();
    $purchase_id = (int)$stmt->insert_id; $stmt->close();

    $subtotal = $unit_cost * $qty;

    $stmt = $conexion->prepare("INSERT INTO purchase_items(purchase_id,variant_id,quantity,unit_cost,subtotal) VALUES (?,?,?,?,?)");
    $stmt->bind_param('iiidd', $purchase_id, $variant_id, $qty, $unit_cost, $subtotal);
    $stmt->execute(); $stmt->close();

    $stmt = $conexion->prepare("UPDATE purchases SET total=? WHERE id=?");
    $stmt->bind_param('di', $subtotal, $purchase_id);
    $stmt->execute(); $stmt->close();

    /* 6) Actualizar stock y costo promedio de la variante */
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
      'variant_id'=>$variant_id,
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
?>
<!DOCTYPE html>
<html lang="es"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna Shop — Compras</title>
  <link rel="stylesheet" href="assets/css/styles.css">
</head><body>
  <!-- NAV -->
  <div class="nav"><div class="row container">
    <a class="brand" href="index.php">Luna<span class="dot">•</span>Shop</a>
    <div style="flex:1"></div>
    <a href="productos.php">Productos</a>
    <a href="compras.php">Compras</a>
    <a href="ventas.php">Ventas</a>
    <a href="reportes.php">Reportes</a>
  </div></div>

  <!-- HERO -->
  <header class="hero"><div class="container">
    <h1>Cargar compras y fotos</h1>
    <p>Creá el producto, subí la foto desde el celular, cargá cantidad y costo; fijá precio de venta.</p>
  </div></header>

  <main class="container">
    <?php if($okMsg): ?><div class="kpi"><div class="box"><b>OK</b><?=h($okMsg)?></div></div><?php endif; ?>
    <?php if($errMsg): ?><div class="kpi"><div class="box"><b>Error</b><?=h($errMsg)?></div></div><?php endif; ?>

    <h2>➕ Nueva compra</h2>
    <form method="post" enctype="multipart/form-data" class="card" style="padding:14px">
      <input type="hidden" name="__action" value="create_purchase">

      <h3>Producto</h3>
      <div class="row">
        <label>Nombre <input class="input" name="name" required placeholder="Ej: Remera Luna"></label>
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
        <label>Medidas <input class="input" name="measure_text" placeholder="Ancho x Largo…"></label>
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
        <label>Notas <input class="input" name="notes" placeholder="Opcional (ej: lote, condición)"></label>
      </div>

      <button type="submit">Guardar compra</button>
    </form>

    <?php if($created): ?>
      <h2 style="margin-top:20px">Vista previa</h2>
      <div class="grid">
        <div class="card">
          <img src="<?= h($created['image_url'] ?: 'https://picsum.photos/640/480?random='.((int)$created['product_id'])) ?>"
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

    <h2 style="margin-top:20px">Productos recientes</h2>
    <div class="grid">
      <?php if($prods && $prods->num_rows>0): while($p=$prods->fetch_assoc()): ?>
        <div class="card">
          <div class="p"><h3><?= h($p['name']) ?></h3></div>
        </div>
      <?php endwhile; else: ?>
        <div class="card" style="padding:14px"><div class="p">Aún no hay productos cargados.</div></div>
      <?php endif; ?>
    </div>
  </main>
</body></html>
