<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ========= Resolver $root de forma robusta =========
   Sube hasta 4 niveles buscando /includes/conn.php para evitar
   errores tipo: C:\...\public/includes/conn.php no existe */
$root = __DIR__;
for ($i=0; $i<4; $i++) {
  if (file_exists($root.'/includes/conn.php')) { break; }
  $root = dirname($root);
}
@require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

/* ========= Helpers básicos ========= */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ========= BASE dinámica (localhost / subcarpeta / Render) ========= */
$script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
$BASE   = rtrim(dirname($script), '/\\'); // puede ser '/', '/public', '/tienda', etc.

if (!function_exists('url')) {
  function url($path){
    global $BASE;
    $b = rtrim($BASE, '/');          // evita doble barra
    $p = '/'.ltrim((string)$path,'/'); // fuerza slash inicial
    return ($b === '' ? '' : $b).$p;  // si BASE === '' -> relativo a raíz
  }
}

/* ========= Chequeos de esquema ========= */
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
    $select = "p.id,p.name"
            .($has_image_url ? ",p.image_url" : "")
            .",".(($has_categories_table && $has_category_id) ? "(SELECT name FROM categories c WHERE c.id=p.category_id)" : "NULL")." AS category_name"
            .",".(($has_variants && $has_variant_price) ? "(SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id=p.id)" : "0")." AS min_price";

    $order = $has_created_at ? "p.created_at DESC" : "p.id DESC";
    $sql = "SELECT $select FROM products p WHERE p.active=1 ORDER BY $order LIMIT 12";
    $prods = @$conexion->query($sql);
    if ($prods===false) $sql_err=$conexion->error;
  }
}

/* ========= Indicador de pedidos nuevos (tolerante a esquema) ========= */
$newCount = 0;
if ($db_ok) {
  // SALES
  $q = @$conexion->query("SHOW TABLES LIKE 'sales'");
  $has_sales = ($q && $q->num_rows>0);
  if ($has_sales) {
    $has_status = !!(@$conexion->query("SHOW COLUMNS FROM sales LIKE 'status'")->num_rows ?? 0);
    $has_created_sales = !!(@$conexion->query("SHOW COLUMNS FROM sales LIKE 'created_at'")->num_rows ?? 0);
    if ($has_status) {
      $rs = @$conexion->query("SELECT COUNT(*) c FROM sales WHERE status='new'");
      if ($rs) $newCount += (int)$rs->fetch_assoc()['c'];
    } elseif ($has_created_sales) {
      $rs = @$conexion->query("SELECT COUNT(*) c FROM sales WHERE created_at >= NOW() - INTERVAL 2 DAY");
      if ($rs) $newCount += (int)$rs->fetch_assoc()['c'];
    }
  }
  // RESERVATIONS
  $q = @$conexion->query("SHOW TABLES LIKE 'reservations'");
  $has_res = ($q && $q->num_rows>0);
  if ($has_res) {
    $has_rstatus  = !!(@$conexion->query("SHOW COLUMNS FROM reservations LIKE 'status'")->num_rows ?? 0);
    $has_rcreated = !!(@$conexion->query("SHOW COLUMNS FROM reservations LIKE 'created_at'")->num_rows ?? 0);
    if ($has_rstatus) {
      $rs = @$conexion->query("SELECT COUNT(*) c FROM reservations WHERE status='new'");
      if ($rs) $newCount += (int)$rs->fetch_assoc()['c'];
    } elseif ($has_rcreated) {
      $rs = @$conexion->query("SELECT COUNT(*) c FROM reservations WHERE created_at >= NOW() - INTERVAL 2 DAY");
      if ($rs) $newCount += (int)$rs->fetch_assoc()['c'];
    }
  }
}

/* ========= Resolver header.php de forma segura ========= */
$header_path = $root.'/includes/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna — Inicio</title>
  <link rel="stylesheet" href="<?= url('assets/css/styles.css') ?>" />
  <link rel="icon" type="image/png" href="<?= url('assets/img/logo.png') ?>">
  <style>
    .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--ring);border-radius:999px}
    .pill b{font-weight:700}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .card .p{padding:12px}
    .cta{display:inline-block;padding:.6rem 1rem;border:1px solid var(--ring);border-radius:10px;text-decoration:none}
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .hero{padding:36px 0;text-align:center}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <!-- HERO -->
  <header class="hero">
    <div class="container">
      <h1>Moda que inspira. Gestión simple.</h1>
      <p>Cargá productos, controlá stock, vendé online o presencial y mirá tus ganancias en segundos.</p>

      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a class="cta" href="<?= url('productos.php') ?>">➕ Cargar producto</a>

        <!-- 🔗 Acceso directo a TIENDA (clientes) -->
        <a class="cta" href="<?= url('clientes/index.php') ?>">🛍️ Ir a la tienda</a>

        <!-- 🔔 Indicador de nuevos pedidos/reservas (si hay) -->
        <?php if($newCount>0): ?>
          <span class="pill">🔔 <b><?= (int)$newCount ?></b> nuevo<?= $newCount>1?'s':'' ?> (pedidos / reservas)</span>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="container">
    <?php if($sql_err): ?>
      <div class="card" style="padding:14px"><div class="p">
        <b>❌ Error SQL:</b> <?= h($sql_err) ?>
      </div></div>
    <?php endif; ?>

    <h2>Novedades</h2>

    <?php if($db_ok && $has_products && $prods && $prods->num_rows>0): ?>
      <div class="grid">
        <?php while($p=$prods->fetch_assoc()): ?>
          <div class="card">
            <?php
              // Placeholder seguro por si no hay image_url
              $img = ($has_image_url && !empty($p['image_url']))
                    ? $p['image_url']
                    : ('https://picsum.photos/seed/'.(int)$p['id'].'/640/480');
            ?>
            <img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" loading="lazy" width="640" height="480">
            <div class="p">
              <h3><?= h($p['name']) ?></h3>
              <?php if(!empty($p['category_name'])): ?>
                <span class="badge"><?= h($p['category_name']) ?></span>
              <?php endif; ?>
              <span class="badge">Desde $ <?= money($p['min_price']) ?></span>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        <b>Sin productos aún.</b> Creá el primero desde <a class="cta" href="<?= url('productos.php') ?>">Productos</a>.
      </div></div>
      <div class="grid">
        <?php foreach([101,102,103,104,105,106] as $seed): ?>
          <div class="card">
            <img src="https://picsum.photos/seed/<?= (int)$seed ?>/640/480" alt="Producto demo" loading="lazy" width="640" height="480">
            <div class="p">
              <h3>Producto demo <?= (int)$seed ?></h3>
              <span class="badge">Desde $ 9.999</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

</body>
</html>
