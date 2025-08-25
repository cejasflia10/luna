<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ====== RUTAS: subir dos niveles (clientes -> public -> raíz) ====== */
$root = dirname(__DIR__, 2); // C:\xampp\htdocs\luna-shop
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php'; // opcional

/* ====== Helpers ====== */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* ====== BASES WEB ======
   $PUBLIC_BASE: /public (funciona aunque estemos en /public/clientes)
   url_public($p): /public/$p
   urlc($p):       /public/clientes/$p
*/
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$dir    = rtrim(dirname($script), '/\\'); // /.../public o /.../public/clientes
$PUBLIC_BASE = (preg_match('~/(clientes)(/|$)~', $dir)) ? rtrim(dirname($dir), '/\\') : $dir;

if (!function_exists('url_public')) {
  function url_public($path){
    global $PUBLIC_BASE;
    $b = rtrim($PUBLIC_BASE, '/'); $p = '/'.ltrim((string)$path, '/');
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

/* ====== Palabras para el ROTADOR de promos ====== */
$promo_words = ['Promos', 'Ofertas', 'Nueva temporada', 'Remeras', 'Pantalones', 'Camperas', 'Denim', 'Accesorios', 'Sale'];
if ($db_ok && $has_products) {
  $has_discount = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'discount'")?->num_rows ?? 0) > 0;
  $has_isoffer  = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'is_offer'")?->num_rows ?? 0) > 0;
  $has_promolbl = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'promo_label'")?->num_rows ?? 0) > 0;

  if ($has_discount || $has_isoffer || $has_promolbl) {
    $conds = ["p.active=1"];
    if ($has_discount)  $conds[] = "p.discount>0";
    if ($has_isoffer)   $conds[] = "p.is_offer=1";
    if ($has_promolbl)  $conds[] = "p.promo_label<>''";

    $cond = implode(' OR ', array_slice($conds,1)); // solo las condiciones "promos"
    $field = $has_promolbl ? "CASE WHEN COALESCE(p.promo_label,'')<>'' THEN p.promo_label ELSE p.name END" : "p.name";
    $sqlw = "SELECT DISTINCT $field AS label FROM products p WHERE p.active=1 AND (".$cond.") ORDER BY p.id DESC LIMIT 20";
    if ($rs = @$conexion->query($sqlw)) {
      $tmp=[]; while($r=$rs->fetch_assoc()){ if(!empty($r['label'])) $tmp[]=$r['label']; }
      if ($tmp) $promo_words = array_values(array_unique($tmp));
    }
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
    .hero{padding:18px 0 6px;text-align:center}

    /* Rotador con destellos */
    .rotator{display:inline-block;position:relative;font-weight:800;letter-spacing:.02em;animation:twinkle 2.4s ease-in-out infinite}
    .rot-out{opacity:.08;filter:blur(1px);transition:opacity .26s linear,filter .26s linear}
    @keyframes twinkle{0%,100%{text-shadow:0 0 0px #fff}50%{text-shadow:0 0 10px rgba(255,255,255,.7),0 0 28px rgba(255,255,255,.25)}}
    .rotator::after{content:"";position:absolute;inset:-.15em;pointer-events:none;background:
      radial-gradient(6px 6px at 20% 40%, rgba(255,255,255,.9), transparent 60%),
      radial-gradient(5px 5px at 70% 30%, rgba(255,255,255,.7), transparent 60%),
      radial-gradient(4px 4px at 45% 70%, rgba(255,255,255,.6), transparent 60%);
      mix-blend-mode:screen;animation:spark 3.6s linear infinite;opacity:.7}
    @keyframes spark{0%{transform:translateX(-6%) translateY(-2%);opacity:.6}50%{transform:translateX(6%) translateY(2%);opacity:.9}100%{transform:translateX(-6%) translateY(-2%);opacity:.6}}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">

    <!-- HERO de la tienda con rotador -->
    <section class="hero">
      <h1 style="margin:0">
        Ofertas:
        <span class="rotator" id="promo-rot" aria-live="polite"></span>
      </h1>
      <div style="opacity:.85;margin-top:6px">Envíos a todo el país · Cambios fáciles · 3 y 6 cuotas</div>
    </section>

    <header class="tienda">
      <h2 style="margin:0">🛍️ Productos</h2>
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

  <!-- Rotador JS (ligero, sin dependencias) -->
  <script>
  (function(){
    // Palabras provenientes de la BD (o fallback)
    const WORDS = <?= json_encode(array_values(array_unique($promo_words)), JSON_UNESCAPED_UNICODE) ?>;
    const el = document.getElementById('promo-rot');
    if (!el || !WORDS.length) return;
    let i = 0;
    const interval = 2200, fade = 260;
    el.textContent = WORDS[0];
    setInterval(()=>{
      el.classList.add('rot-out');
      setTimeout(()=>{
        i = (i + 1) % WORDS.length;
        el.textContent = WORDS[i];
        el.classList.remove('rot-out');
      }, fade);
    }, interval);
  })();
  </script>

</body>
</html>
