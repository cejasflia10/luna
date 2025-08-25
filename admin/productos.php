<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';
require $root.'/includes/page_head.php'; // función page_head()

/* Helpers por si no existen */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* BASE dinámica para rutas */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('url')) {
  function url($path){ global $BASE; return $BASE.'/'.ltrim($path,'/'); }
}

/* ====== Flags de esquema para evitar errores si falta algo ====== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$has_products = $has_variants = $has_categories = false;
$has_measure  = $has_created  = false;

if ($db_ok) {
  $has_products   = !!@$conexion->query("SHOW TABLES LIKE 'products'")->num_rows;
  $has_variants   = !!@$conexion->query("SHOW TABLES LIKE 'product_variants'")->num_rows;
  $has_categories = !!@$conexion->query("SHOW TABLES LIKE 'categories'")->num_rows;

  if ($has_products) {
    $has_created = !!@$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'")->num_rows;
  }
  if ($has_variants) {
    $has_measure = !!@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'measure_text'")->num_rows;
  }
}

/* ====== Datos para selects (si existe categories) ====== */
$cats = null;
if ($db_ok && $has_categories) {
  $cats = @$conexion->query("SELECT id,name FROM categories WHERE active=1 ORDER BY name ASC");
}

/* ====== Alta de producto + variante ====== */
$okMsg = $errMsg = '';
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create_product') {
  try {
    if (!$has_products || !$has_variants) {
      throw new Exception('Faltan tablas products/product_variants. Ejecutá el schema.sql.');
    }

    $conexion->begin_transaction();

    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $img   = trim($_POST['image_url'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $catId = (int)($_POST['category_id'] ?? 0);

    if ($name==='')  throw new Exception('El nombre es obligatorio.');
    if ($has_categories && $catId<=0) throw new Exception('Seleccioná una categoría.');

    $stmt = $conexion->prepare("INSERT INTO products(name,description,image_url,active,category_id) VALUES (?,?,?,?,?)");
    $one=1;
    $stmt->bind_param('sssii', $name, $desc, $img, $one, $catId);
    $stmt->execute();
    $pid = (int)$stmt->insert_id; $stmt->close();

    $sku     = trim($_POST['sku'] ?? '');
    $size    = trim($_POST['size'] ?? '');
    $color   = trim($_POST['color'] ?? '');
    $measure = trim($_POST['measure_text'] ?? '');
    $stock   = (int)($_POST['stock'] ?? 0);
    $avg     = (float)($_POST['avg_cost'] ?? 0);

    if ($has_measure) {
      $stmt = $conexion->prepare("
        INSERT INTO product_variants(product_id,sku,size,color,measure_text,price,stock,avg_cost)
        VALUES (?,?,?,?,?,?,?,?)
      ");
      $stmt->bind_param('isssssid', $pid, $sku, $size, $color, $measure, $price, $stock, $avg);
    } else {
      $stmt = $conexion->prepare("
        INSERT INTO product_variants(product_id,sku,size,color,price,stock,avg_cost)
        VALUES (?,?,?,?,?,?,?)
      ");
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

/* ====== Listado ====== */
$rows = null;
if ($db_ok && $has_products) {
  $order = $has_created ? "p.created_at DESC, p.id DESC" : "p.id DESC";
  $selectVariantCols = "v.sku, v.size, v.color, ".($has_measure ? "v.measure_text" : "'' AS measure_text").", v.price, v.stock";
  $rows = @$conexion->query("
    SELECT p.id,p.name,p.image_url, c.name AS category, $selectVariantCols
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
  <title>Luna — Productos</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php
// Cabecera unificada (misma que index)
page_head('Productos', 'Gestión y listado de artículos', ['label'=>'➕ Cargar producto','href'=>'compras.php']);
?>

<main class="container">
  <?php if($okMsg): ?>
    <div class="kpi"><div class="box"><b>OK</b> <?=h($okMsg)?></div></div>
  <?php endif; ?>
  <?php if($errMsg): ?>
    <div class="kpi"><div class="box"><b>Error</b> <?=h($errMsg)?></div></div>
  <?php endif; ?>

  <h2>➕ Nuevo producto</h2>
  <form method="post" class="card" style="padding:14px">
    <input type="hidden" name="__action" value="create_product">
    <div class="row">
      <label>Nombre <input class="input" name="name" required></label>
      <label>Precio sugerido ($) <input class="input" name="price" type="number" step="0.01" min="0" value="0"></label>
      <label>Imagen (URL) <input class="input" name="image_url" placeholder="https://..."></label>
      <label>Categoría
        <select class="input" name="category_id" <?= $has_categories ? 'required' : 'disabled'?>>
          <?php if($has_categories && $cats && $cats->num_rows>0): while($cat=$cats->fetch_assoc()): ?>
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
      <label>Medidas <input class="input" name="measure_text" placeholder="Ancho x Largo..." <?= $has_measure?'':'disabled' ?>></label>
      <label>Stock inicial <input class="input" name="stock" type="number" min="0" value="0"></label>
      <label>Costo promedio inicial ($) <input class="input" name="avg_cost" type="number" step="0.01" min="0" value="0"></label>
    </div>
    <button type="submit">Guardar</button>
  </form>

  <h2 class="mt-4">Listado</h2>
  <?php if($rows && $rows->num_rows>0): ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Producto</th><th>Categoría</th><th>Variante</th><th>Precio</th><th>Stock</th></tr>
        </thead>
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
