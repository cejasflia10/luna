<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ====== Encontrar la raíz del proyecto (busca includes/conn.php hacia arriba) ====== */
$root = __DIR__;
for ($i=0; $i<6; $i++) {
  if (file_exists($root.'/includes/conn.php')) break;
  $root = dirname($root);
}
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php'; // opcional

/* ====== Helpers mínimos ====== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ====== BASES WEB (NO redefinir header.php) ======
   - $PUBLIC_BASE: /public
   - url_public($p):   /public/$p
   - urlc($p):         /public/clientes/$p
*/
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$dir    = rtrim(dirname($script), '/\\'); // /.../public  o  /.../public/clientes
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

/* ====== Estado DB / Esquema ====== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

$has_products=$has_variants=$has_categories=false;
$has_image=$has_description=$has_product_price=false; $has_variant_price=false;

if ($db_ok) {
  $has_products   = !!(@$conexion->query("SHOW TABLES LIKE 'products'")->num_rows ?? 0);
  $has_variants   = !!(@$conexion->query("SHOW TABLES LIKE 'product_variants'")->num_rows ?? 0);
  $has_categories = !!(@$conexion->query("SHOW TABLES LIKE 'categories'")->num_rows ?? 0);

  if ($has_products) {
    $has_image         = !!(@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")->num_rows ?? 0);
    $has_description   = !!(@$conexion->query("SHOW COLUMNS FROM products LIKE 'description'")->num_rows ?? 0);
    $has_product_price = !!(@$conexion->query("SHOW COLUMNS FROM products LIKE 'price'")->num_rows ?? 0);
  }
  if ($has_variants) {
    $has_variant_price = !!(@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'")->num_rows ?? 0);
  }
}

/* ====== ID del producto ====== */
$id = (int)($_GET['id'] ?? 0);

/* ====== Traer producto ====== */
$product = null;
$variants = [];
$min_price = 0.0;
$sql_err = '';

if ($db_ok && $has_products && $id>0) {
  $sel = "p.id,p.name";
  if ($has_description) $sel.=",p.description";
  if ($has_image)       $sel.=",p.image_url";
  if ($has_categories)  $sel.=", (SELECT name FROM categories c WHERE c.id=p.category_id) AS category_name";
  if ($has_product_price) $sel.=", p.price";

  $rs = @$conexion->query("SELECT $sel FROM products p WHERE p.id=$id LIMIT 1");
  if ($rs) $product = $rs->fetch_assoc(); else $sql_err = $conexion->error;

  if ($has_variants) {
    $rs = @$conexion->query("SELECT id, sku, size, color".($has_variant_price?", price":"").", stock FROM product_variants WHERE product_id=$id ORDER BY id ASC");
    if ($rs) {
      while($row=$rs->fetch_assoc()) {
        $variants[] = $row;
        $p = (float)($row['price'] ?? 0);
        if ($p>0 && ($min_price==0 || $p<$min_price)) $min_price = $p;
      }
    }
  }
  if ($min_price==0 && $has_product_price && isset($product['price'])) {
    $min_price = (float)$product['price'];
  }
}

/* ====== Header layout ====== */
$header_path = $root.'/includes/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Luna — Ver producto</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>" />
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:800px){ .grid2{grid-template-columns:1fr} }
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .card .p{padding:12px}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    .input, select{background:transparent;border:1px solid var(--ring);border-radius:.5rem;padding:.4rem .6rem;color:inherit}
    .row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    @media(max-width:600px){ .row{grid-template-columns:1fr} }
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <nav class="breadcrumb" style="margin:8px 0 2px">
      <a href="<?= url_public('index.php') ?>">Inicio</a> <span>›</span>
      <a href="<?= urlc('index.php') ?>">Tienda</a> <span>›</span>
      <strong>Producto</strong>
    </nav>

    <?php if($sql_err): ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        <b>❌ Error SQL:</b> <?= h($sql_err) ?>
      </div></div>
    <?php endif; ?>

    <?php if(!$product): ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">
        <b>No se encontró el producto.</b> <a class="cta" href="<?= urlc('index.php') ?>">Volver a la tienda</a>
      </div></div>
    <?php else: ?>
      <div class="grid2">
        <div class="card">
          <?php
            $img = '';
            if (!empty($product['image_url'])) {
              $img = $product['image_url'];
              if (!preg_match('~^https?://~i',$img)) $img = url_public($img);
            } else {
              $img = 'https://picsum.photos/seed/'.(int)$product['id'].'/800/600';
            }
          ?>
          <img src="<?= h($img) ?>" alt="<?= h($product['name']) ?>" style="width:100%;height:auto;display:block">
        </div>

        <div class="card">
          <div class="p">
            <h1 style="margin-top:0"><?= h($product['name']) ?></h1>
            <?php if(!empty($product['category_name'])): ?>
              <div style="margin:.2rem 0 .8rem">
                <span class="badge"><?= h($product['category_name']) ?></span>
              </div>
            <?php endif; ?>

            <?php if($min_price>0): ?>
              <div style="font-size:1.4rem;margin:.4rem 0">Desde $ <b><?= money($min_price) ?></b></div>
            <?php endif; ?>

            <?php if(!empty($product['description'])): ?>
              <p style="opacity:.9"><?= h($product['description']) ?></p>
            <?php endif; ?>

            <form method="post" action="<?= urlc('carrito.php') ?>" style="margin-top:12px">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

              <?php if(!empty($variants)): ?>
                <div class="row">
                  <label>Variante
                    <select name="variant_id" class="input" required>
                      <?php foreach($variants as $v): ?>
                        <?php
                          $txt = trim(($v['size'] ?? '').' '.($v['color'] ?? '').' '.(!empty($v['sku'])?('#'. $v['sku']):''));
                          if ($txt==='') $txt = 'Variante '.$v['id'];
                          $vp = (float)($v['price'] ?? 0);
                          $txt .= $vp>0 ? (' — $ '.money($vp)) : '';
                        ?>
                        <option value="<?= (int)$v['id'] ?>"><?= h($txt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Cantidad
                    <input class="input" type="number" name="qty" min="1" value="1">
                  </label>
                </div>
              <?php else: ?>
                <input type="hidden" name="variant_id" value="0">
                <label>Cantidad
                  <input class="input" type="number" name="qty" min="1" value="1">
                </label>
              <?php endif; ?>

              <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" class="cta">🛒 Agregar al carrito</button>
                <a href="<?= urlc('index.php') ?>" class="cta">← Seguir viendo</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

</body>
</html>
