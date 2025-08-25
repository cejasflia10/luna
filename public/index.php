<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
@require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* BASE dinámica (localhost / Render) */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('url')) {
  function url($path){ global $BASE; return $BASE.'/'.ltrim($path,'/'); }
}

/* ==== Chequeos de esquema ==== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$has_products=$has_variants=$has_categories_table=false;
$has_image_url=$has_created_at=$has_category_id=$has_variant_price=false;
$sql_err=''; $prods=null;

if ($db_ok) {
  $t1=@$conexion->query("SHOW TABLES LIKE 'products'");         $has_products=($t1 && $t1->num_rows>0);
  $t2=@$conexion->query("SHOW TABLES LIKE 'product_variants'"); $has_variants=($t2 && $t2->num_rows>0);
  $t3=@$conexion->query("SHOW TABLES LIKE 'categories'");       $has_categories_table=($t3 && $t3->num_rows>0);

  if ($has_products) {
    $c1=@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'");   $has_image_url=($c1 && $c1->num_rows>0);
    $c2=@$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'");  $has_created_at=($c2 && $c2->num_rows>0);
    $c3=@$conexion->query("SHOW COLUMNS FROM products LIKE 'category_id'"); $has_category_id=($c3 && $c3->num_rows>0);
  }
  if ($has_variants) {
    $v1=@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'"); $has_variant_price=($v1 && $v1->num_rows>0);
  }

  if ($has_products) {
    $select = "p.id,p.name".($has_image_url?",p.image_url":"")
            .",".(($has_categories_table&&$has_category_id)?"(SELECT name FROM categories c WHERE c.id=p.category_id)":"NULL")." AS category_name"
            .",".(($has_variants&&$has_variant_price)?"(SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id=p.id)":"0")." AS min_price";
    $order = $has_created_at ? "p.created_at DESC" : "p.id DESC";
    $sql = "SELECT $select FROM products p WHERE p.active=1 ORDER BY $order LIMIT 12";
    $prods = @$conexion->query($sql);
    if ($prods===false) $sql_err=$conexion->error;
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna  — Inicio</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>" />
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
</head>
<body>

  <?php require dirname(__DIR__).'/includes/header.php'; ?><!-- NAV unificado -->

  <!-- HERO -->
  <header class="hero">
    <div class="container">
      <h1>Moda que inspira. Gestión simple.</h1>
      <p>Cargá productos, controlá stock, vendé online o presencial y mirá tus ganancias en segundos.</p>
      <a class="cta" href="<?=url('productos.php')?>">➕ Cargar producto</a>
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
            <?php $img = ($has_image_url && !empty($p['image_url'])) ? $p['image_url'] : ('https://picsum.photos/640/480?random='.(int)$p['id']); ?>
            <img src="<?=h($img)?>" alt="<?=h($p['name'])?>" loading="lazy" width="640" height="480">
            <div class="p">
              <h3><?=h($p['name'])?></h3>
              <?php if(!empty($p['category_name'])): ?><span class="badge"><?=h($p['category_name'])?></span><?php endif; ?>
              <span class="badge">Desde $ <?=money($p['min_price'])?></span>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        <b>Sin productos aún.</b> Creá el primero desde <a class="cta" href="<?=url('productos.php')?>">Productos</a>.
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
