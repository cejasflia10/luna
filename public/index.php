<?php
if (session_status()===PHP_SESSION_NONE) session_start();

// Includes (opcionales si aún no están)
$root = dirname(__DIR__);
@require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

// Helpers mínimos por si faltan
if (!function_exists('h'))    { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')){ function money($n){ return number_format((float)$n, 2, ',', '.'); } }

// ¿Tenemos DB y tablas básicas?
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$has_products = $has_variants = $has_categories_table = false;
$has_image_url = $has_created_at = $has_category_id = $has_variant_price = false;
$sql_err = '';
$prods = null;

if ($db_ok) {
  // Tablas
  $t1 = @$conexion->query("SHOW TABLES LIKE 'products'");            $has_products = ($t1 && $t1->num_rows>0);
  $t2 = @$conexion->query("SHOW TABLES LIKE 'product_variants'");    $has_variants = ($t2 && $t2->num_rows>0);
  $t3 = @$conexion->query("SHOW TABLES LIKE 'categories'");          $has_categories_table = ($t3 && $t3->num_rows>0);

  if ($has_products) {
    // Columnas de products
    $c1 = @$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'");   $has_image_url  = ($c1 && $c1->num_rows>0);
    $c2 = @$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'");  $has_created_at = ($c2 && $c2->num_rows>0);
    $c3 = @$conexion->query("SHOW COLUMNS FROM products LIKE 'category_id'"); $has_category_id= ($c3 && $c3->num_rows>0);
  }
  if ($has_variants) {
    // Columnas de product_variants
    $v1 = @$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'"); $has_variant_price = ($v1 && $v1->num_rows>0);
  }

  // Si hay tablas mínimas, armamos la consulta
  if ($has_products) {
    // SELECT base
    $select = "p.id, p.name";
    if ($has_image_url) {
      $select .= ", p.image_url";
    }

    // Categoría (solo si existen tabla y FK)
    if ($has_categories_table && $has_category_id) {
      $select .= ", (SELECT name FROM categories c WHERE c.id = p.category_id) AS category_name";
    } else {
      $select .= ", NULL AS category_name";
    }

    // Precio mínimo (si variants.price existe)
    if ($has_variants && $has_variant_price) {
      $select .= ", (SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id = p.id) AS min_price";
    } else {
      $select .= ", 0 AS min_price";
    }

    // ORDER BY
    $order = $has_created_at ? "p.created_at DESC" : "p.id DESC";

    $sql = "
      SELECT $select
      FROM products p
      WHERE p.active = 1
      ORDER BY $order
      LIMIT 12
    ";

    $prods = @$conexion->query($sql);
    if ($prods === false) {
      $sql_err = $conexion->error;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna Shop — Inicio</title>
  <link rel="stylesheet" href="assets/css/styles.css" />
</head>
<body>
  <!-- NAV -->
  <nav class="nav" aria-label="Navegación principal">
    <div class="row container">
      <a class="brand" href="index.php" aria-label="Inicio">Luna<span class="dot">•</span>Shop</a>
      <div style="flex:1"></div>
      <a href="productos.php">Productos</a>
      <a href="compras.php">Compras</a>
      <a href="ventas.php">Ventas</a>
      <a href="reportes.php">Reportes</a>
      <a href="categorias.php">Categorías</a>
    </div>
  </nav>

  <!-- HERO -->
  <header class="hero">
    <div class="container">
      <h1>Moda que inspira. Gestión simple.</h1>
      <p>Cargá productos, controlá stock, vendé online o presencial y mirá tus ganancias en segundos.</p>
      <a class="cta" href="productos.php">➕ Cargar producto</a>
    </div>
  </header>

  <main class="container">
    <?php if($sql_err): ?>
      <div class="card" style="padding:14px"><div class="p">
        <b>❌ Error SQL:</b> <?=h($sql_err)?>
      </div></div>
    <?php endif; ?>

    <h2>Novedades</h2>

    <?php if($db_ok && $has_products && $prods && $prods->num_rows>0): ?>
      <div class="grid">
        <?php while($p=$prods->fetch_assoc()): ?>
          <div class="card">
            <?php
              $img = 'https://picsum.photos/640/480?random='.(int)$p['id'];
              if ($has_image_url && !empty($p['image_url'])) { $img = $p['image_url']; }
            ?>
            <img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" loading="lazy" width="640" height="480">
            <div class="p">
              <h3><?= h($p['name']) ?></h3>
              <?php if (!empty($p['category_name'])): ?>
                <span class="badge"><?= h($p['category_name']) ?></span>
              <?php endif; ?>
              <span class="badge">Desde $ <?= money($p['min_price']) ?></span>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <!-- Demo si no hay DB o no hay productos -->
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        <b>Sin productos aún.</b> Creá el primero desde <a class="cta" href="productos.php">Productos</a>.
      </div></div>

      <div class="grid">
        <?php foreach([101,102,103,104,105,106] as $seed): ?>
          <div class="card">
            <img src="https://picsum.photos/seed/<?= $seed ?>/640/480" alt="Producto demo" loading="lazy" width="640" height="480">
            <div class="p">
              <h3>Producto demo <?= $seed ?></h3>
              <span class="badge">Desde $ 9.999</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
