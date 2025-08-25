<?php
if (session_status()===PHP_SESSION_NONE) session_start();

// Includes (opcionales si aún no están)
$root = dirname(__DIR__);
@require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

// Helpers mínimos por si faltan
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

// ¿Tenemos DB y tablas?
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$tables_ok = false;
if ($db_ok) {
  $t1 = @$conexion->query("SHOW TABLES LIKE 'products'");
  $t2 = @$conexion->query("SHOW TABLES LIKE 'product_variants'");
  $tables_ok = ($t1 && $t1->num_rows>0 && $t2 && $t2->num_rows>0);
}

// Cargar productos (si hay DB)
$prods = null; $sql_err = '';
if ($db_ok && $tables_ok) {
  $sql = "
    SELECT 
      p.id, p.name, p.image_url,
      (SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id=p.id) AS min_price
    FROM products p
    WHERE p.active=1
    ORDER BY p.created_at DESC
    LIMIT 12
  ";
  $prods = $conexion->query($sql);
  if ($prods === false) { $sql_err = $conexion->error; }
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

    <?php if($db_ok && $tables_ok && $prods && $prods->num_rows>0): ?>
      <div class="grid">
        <?php while($p=$prods->fetch_assoc()): ?>
          <div class="card">
            <img src="<?= h($p['image_url'] ?: ('https://picsum.photos/640/480?random='.(int)$p['id'])) ?>" 
                 alt="<?= h($p['name']) ?>" loading="lazy" width="640" height="480">
            <div class="p">
              <h3><?= h($p['name']) ?></h3>
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
