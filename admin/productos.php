<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require dirname(__DIR__).'/includes/conn.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2, ',', '.'); }

$cats = $conexion->query("SELECT id,name FROM categories WHERE active=1 ORDER BY name ASC");

$okMsg = $errMsg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_product') {
  try {
    $conexion->begin_transaction();

    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $img   = trim($_POST['image_url'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $catId = (int)($_POST['category_id'] ?? 0);

    if ($name==='')  throw new Exception('El nombre es obligatorio.');
    if ($catId<=0)   throw new Exception('Seleccioná una categoría.');

    $stmt = $conexion->prepare("INSERT INTO products(name,description,image_url,active,category_id) VALUES (?,?,?,?,?)");
    $one=1;
    $stmt->bind_param('sssii', $name, $desc, $img, $one, $catId);
    $stmt->execute();
    $pid = (int)$stmt->insert_id; $stmt->close();

    $sku    = trim($_POST['sku'] ?? '');
    $size   = trim($_POST['size'] ?? '');
    $color  = trim($_POST['color'] ?? '');
    $measure= trim($_POST['measure_text'] ?? '');
    $stock  = (int)($_POST['stock'] ?? 0);
    $avg    = (float)($_POST['avg_cost'] ?? 0);

    $stmt = $conexion->prepare("INSERT INTO product_variants(product_id,sku,size,color,measure_text,price,stock,avg_cost) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param('isssssid', $pid, $sku, $size, $color, $measure, $price, $stock, $avg);
    $stmt->execute(); $stmt->close();

    $conexion->commit();
    $okMsg = '✅ Producto creado con su variante inicial.';
  } catch (Throwable $e) {
    $conexion->rollback();
    $errMsg = '❌ '.$e->getMessage();
  }
}

$rows = $conexion->query("
  SELECT p.id,p.name,p.image_url, c.name AS category, v.sku, v.size, v.color, v.measure_text, v.price, v.stock
  FROM products p
  LEFT JOIN categories c ON c.id=p.category_id
  LEFT JOIN product_variants v ON v.product_id=p.id
  WHERE p.active=1
  ORDER BY p.created_at DESC, p.id DESC
  LIMIT 100
");
if ($rows===false) { $errMsg = '❌ Error SQL: '.$conexion->error; }
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Luna Shop — Productos</title>
<link rel="stylesheet" href="assets/css/styles.css">
</head><body>
<div class="nav"><div class="row container">
  <a class="brand" href="index.php">Luna<span class="dot">•</span>Shop</a>
  <div style="flex:1"></div>
  <a href="productos.php">Productos</a>
  <a href="compras.php">Compras</a>
  <a href="ventas.php">Ventas</a>
  <a href="reportes.php">Reportes</a>
  <a href="categorias.php">Categorías</a>
</div></div>

<header class="hero"><div class="container">
  <h1>Gestión de productos</h1>
  <p>Cargá y visualizá los artículos del catálogo por categoría.</p>
</div></header>

<main class="container">
  <?php if($okMsg): ?><div class="kpi"><div class="box"><b>OK</b><?=h($okMsg)?></div></div><?php endif; ?>
  <?php if($errMsg): ?><div class="kpi"><div class="box"><b>Error</b><?=h($errMsg)?></div></div><?php endif; ?>

  <h2>➕ Nuevo producto</h2>
  <form method="post" class="card" style="padding:14px">
    <input type="hidden" name="__action" value="create_product">
    <div class="row">
      <label>Nombre <input class="input" name="name" required></label>
      <label>Precio sugerido ($) <input class="input" name="price" type="number" step="0.01" min="0" value="0"></label>
      <label>Imagen (URL) <input class="input" name="image_url" placeholder="https://..."></label>
      <label>Categoría
        <select class="input" name="category_id" required>
          <?php if($cats && $cats->num_rows>0): while($cat=$cats->fetch_assoc()): ?>
            <option value="<?=$cat['id']?>"><?=h($cat['name'])?></option>
          <?php endwhile; else: ?>
            <option value="">(Creá categorías primero)</option>
          <?php endif; ?>
        </select>
      </label>
      <label>Descripción <input class="input" name="description"></label>
    </div>

    <h3>Variante inicial</h3>
    <div class="row">
      <label>SKU <input class="input" name="sku"></label>
      <label>Talle <input class="input" name="size" placeholder="S / M / L..."></label>
      <label>Color <input class="input" name="color"></label>
      <label>Medidas <input class="input" name="measure_text" placeholder="Ancho x Largo..."></label>
      <label>Stock inicial <input class="input" name="stock" type="number" min="0" value="0"></label>
      <label>Costo promedio inicial ($) <input class="input" name="avg_cost" type="number" step="0.01" min="0" value="0"></label>
    </div>
    <button type="submit">Guardar</button>
  </form>

  <h2 style="margin-top:20px">Listado</h2>
  <?php if($rows && $rows->num_rows>0): ?>
    <table class="table">
      <thead><tr><th>Producto</th><th>Categoría</th><th>Variante</th><th>Precio</th><th>Stock</th></tr></thead>
      <tbody>
      <?php while($r=$rows->fetch_assoc()): ?>
        <tr>
          <td><b><?=h($r['name'])?></b></td>
          <td><?=h($r['category'] ?: '—')?></td>
          <td><?=h(trim(($r['sku']?('#'.$r['sku'].' '):'').($r['size']?:'').' '.($r['color']?:'').' '.($r['measure_text']?:'')))?></td>
          <td>$ <?=money($r['price'])?></td>
          <td><?= (int)$r['stock'] ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="card" style="padding:14px"><div class="p">
      <b>No hay productos cargados aún.</b> Usá el formulario de arriba para crear el primero.
    </div></div>
  <?php endif; ?>
</main>
</body></html>
