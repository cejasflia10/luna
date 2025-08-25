<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ==== RUTAS FS: subir 2 niveles (clientes -> public -> raíz) ==== */
$root = dirname(__DIR__, 2); // C:\xampp\htdocs\luna-shop
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

/* ==== Helpers mínimos ==== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ==== BASE web (para URLs correctas desde /public/clientes) ==== */
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$BASE   = rtrim(dirname($script), '/\\'); // /luna-shop/public/clientes
if (!function_exists('url')) {
  function url($path){ global $BASE; $b=rtrim($BASE,'/'); $p='/'.ltrim((string)$path,'/'); return ($b===''?'':$b).$p; }
}

/* ==== Esquema y datos ==== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

$has_products=$has_variants=$has_categories=false;
$has_image_url=$has_created_at=$has_category_id=$has_variant_price=false;
$sql_err=''; $prods=null; $cats=[];

if ($db_ok) {
  $has_products    = (@$conexion->query("SHOW TABLES LIKE 'products'")?->num_rows ?? 0) > 0;
  $has_variants    = (@$conexion->query("SHOW TABLES LIKE 'product_variants'")?->num_rows ?? 0) > 0;
  $has_categories  = (@$conexion->query("SHOW TABLES LIKE 'categories'")?->num_rows ?? 0) > 0;

  if ($has_products) {
    $has_image_url   = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")?->num_rows ?? 0) > 0;
    $has_created_at  = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'")?->num_rows ?? 0) > 0;
    $has_category_id = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'category_id'")?->num_rows ?? 0) > 0;
  }
  if ($has_variants) {
    $has_variant_price = (@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'")?->num_rows ?? 0) > 0;
  }

  /* Categorías para filtro */
  if ($has_categories) {
    if ($rc=@$conexion->query("SELECT id, name FROM categories ORDER BY name ASC")) {
      while($row=$rc->fetch_assoc()){ $cats[]=['id'=>(int)$row['id'],'name'=>$row['name']]; }
    }
  }

  /* Filtros */
  $cat_id = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
  $qtext  = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

  if ($has_products) {
    $select = "p.id,p.name"
             .($has_image_url?",p.image_url":"")
             .",".(($has_variants&&$has_variant_price)?"(SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id=p.id)":"0")." AS min_price";

    $where  = "p.active=1";
    if ($cat_id>0 && $has_category_id) $where .= " AND p.category_id = ".(int)$cat_id;
    if ($qtext!=='') {
      $like = $conexion->real_escape_string($qtext);
      $where .= " AND p.name LIKE '%{$like}%'";
    }

    $order = $has_created_at ? "p.created_at DESC" : "p.id DESC";
    $sql   = "SELECT $select FROM products p WHERE $where ORDER BY $order LIMIT 72";
    $prods = @$conexion->query($sql);
    if ($prods===false) $sql_err=$conexion->error;
  }
}

/* Carrito */
$cart_count = isset($_SESSION['cart_count']) ? (int)$_SESSION['cart_count'] : 0;

/* Header layout */
$header_path = $root.'/includes/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna — Catálogo</title>
  <!-- desde /public/clientes los assets viven en ../assets -->
  <link rel="stylesheet" href="<?= url('../assets/css/styles.css') ?>" />
  <link rel="icon" type="image/png" href="<?= url('../assets/img/logo.png') ?>">
  <style>
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .card .p{padding:12px}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    header.hd{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 0}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--ring);border-radius:999px}
    .filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:8px 0 16px}
    .filters input,.filters select,.filters button{padding:.45rem .6rem;border:1px solid var(--ring);background:transparent;color:var(--fg);border-radius:.5rem}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>


    <header class="hd">
      <h1 style="margin:0">📒 Catálogo</h1>
      <a class="pill" href="<?= url('carrito.php') ?>">🛒 Carrito <b><?= $cart_count ?></b></a>
    </header>

    <!-- Filtros -->
    <form class="filters" method="get" action="">
      <?php if ($has_categories && count($cats)>0): ?>
        <label for="categoria">Categoría</label>
        <select name="categoria" id="categoria">
          <option value="0">Todas</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ($c['id']==($cat_id??0) ? 'selected' : '') ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <input type="text" name="q" value="<?= h($qtext ?? '') ?>" placeholder="Buscar producto…">
      <button type="submit">Aplicar</button>
      <a class="cta" href="<?= url('catalogo.php') ?>">Limpiar</a>
    </form>

    <?php if($sql_err): ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        <b>❌ Error SQL:</b> <?= h($sql_err) ?>
      </div></div>
    <?php endif; ?>

    <?php if($db_ok && $has_products && $prods && $prods->num_rows>0): ?>
      <div class="grid">
        <?php while($p=$prods->fetch_assoc()): ?>
          <div class="card">
            <?php
              $img = ($has_image_url && !empty($p['image_url']))
                    ? $p['image_url']
                    : ('https://picsum.photos/seed/'.(int)$p['id'].'/640/480');
            ?>
            <a href="<?= url('ver.php?id='.(int)$p['id']) ?>">
              <img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" loading="lazy" width="640" height="480">
            </a>
            <div class="p">
              <h3 style="margin:.2rem 0 .4rem"><?= h($p['name']) ?></h3>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <span class="badge">Desde $ <?= money($p['min_price']) ?></span>
                <a class="cta" href="<?= url('ver.php?id='.(int)$p['id']) ?>">Ver</a>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        No encontramos productos<?= ($qtext!=='' || ($cat_id??0)>0) ? " con ese filtro" : "" ?>. 🙌
      </div></div>
    <?php endif; ?>
  </div>

</body>
</html>
