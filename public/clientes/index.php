<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ====== RUTAS: subir dos niveles (clientes -> public -> raíz) ====== */
$root = dirname(__DIR__, 2); // C:\xampp\htdocs\luna-shop
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php'; // opcional

/* ====== Helpers ====== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ====== BASES WEB (NO redefinir url(), la define header.php) ======
   - $PUBLIC_BASE: /public (funciona aunque estemos en /public/clientes)
   - url_public($p): genera /public/$p
   - urlc($p): genera /public/clientes/$p
*/
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$dir    = rtrim(dirname($script), '/\\'); // /.../public o /.../public/clientes
$PUBLIC_BASE = (preg_match('~/(clientes)(/|$)~', $dir)) ? rtrim(dirname($dir), '/\\') : $dir;

if (!function_exists('url_public')) {
  function url_public($path){
    global $PUBLIC_BASE;
    $b = rtrim($PUBLIC_BASE, '/');
    $p = '/'.ltrim((string)$path, '/');
    return ($b===''?'':$b).$p;
  }
}
if (!function_exists('urlc')) {
  function urlc($path){ return url_public('clientes/'.ltrim((string)$path,'/')); }
}

/* ====== Datos desde BD ====== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$has_products=$has_variants=false;
$has_image_url=$has_created_at=false; $has_variant_price=false;
$sql_err=''; $prods=null;

if ($db_ok) {
  $t1=@$conexion->query("SHOW TABLES LIKE 'products'");         $has_products=($t1 && $t1->num_rows>0);
  $t2=@$conexion->query("SHOW TABLES LIKE 'product_variants'"); $has_variants=($t2 && $t2->num_rows>0);

  if ($has_products) {
    $c1=@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'");   $has_image_url=($c1 && $c1->num_rows>0);
    $c2=@$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'");  $has_created_at=($c2 && $c2->num_rows>0);
  }
  if ($has_variants) {
    $v1=@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'"); $has_variant_price=($v1 && $v1->num_rows>0);
  }

  if ($has_products) {
    $select = "p.id,p.name".($has_image_url?",p.image_url":"")
            .",".(($has_variants&&$has_variant_price)?"(SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id=p.id)":"0")." AS min_price";
    $order = $has_created_at ? "p.created_at DESC" : "p.id DESC";
    $sql   = "SELECT $select FROM products p WHERE p.active=1 ORDER BY $order LIMIT 24";
    $prods = @$conexion->query($sql);
    if ($prods===false) $sql_err=$conexion->error;
  }
}

/* ====== Carrito en sesión ====== */
$cart_count = isset($_SESSION['cart_count']) ? (int)$_SESSION['cart_count'] : 0;

/* ====== Header layout ====== */
$header_path = $root.'/includes/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna — Tienda</title>
  <!-- desde /public/clientes los assets viven en /public/assets -->
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>" />
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .card .p{padding:12px}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    header.tienda{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 0}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--ring);border-radius:999px}
    nav.breadcrumb a{opacity:.8;text-decoration:none}
    nav.breadcrumb span{opacity:.5}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <nav class="breadcrumb" style="margin:8px 0 2px">
      <a href="<?= url_public('index.php') ?>">Inicio</a> <span>›</span> <strong>Tienda</strong>
    </nav>

    <header class="tienda">
      <h1 style="margin:0">🛍️ Tienda</h1>
      <a class="pill" href="<?= urlc('carrito.php') ?>">🛒 Carrito <b><?= $cart_count ?></b></a>
    </header>

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
            <a href="<?= urlc('ver.php?id='.(int)$p['id']) ?>">
              <img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" loading="lazy" width="640" height="480">
            </a>
            <div class="p">
              <h3 style="margin:.2rem 0 .4rem"><?= h($p['name']) ?></h3>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <span class="badge">Desde $ <?= money($p['min_price']) ?></span>
                <a class="cta" href="<?= urlc('ver.php?id='.(int)$p['id']) ?>">Ver</a>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        Aún no hay productos cargados. Volvé más tarde 🙌
      </div></div>
    <?php endif; ?>
  </div>

</body>
</html>
