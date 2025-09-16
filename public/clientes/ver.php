<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ====== Encontrar raíz ====== */
$root = __DIR__;
for ($i=0; $i<6; $i++) { if (file_exists($root.'/includes/conn.php')) break; $root = dirname($root); }
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* URL helpers */
$script = $_SERVER['SCRIPT_NAME'] ?? ''; $dir = rtrim(dirname($script), '/\\');
$PUBLIC_BASE = (preg_match('~/(clientes)(/|$)~', $dir)) ? rtrim(dirname($dir), '/\\') : $dir;
if (!function_exists('url_public')) { function url_public($path){ global $PUBLIC_BASE; $b=rtrim($PUBLIC_BASE,'/'); return ($b===''?'':$b).'/'.ltrim((string)$path,'/'); } }
if (!function_exists('urlc')) { function urlc($p){ return url_public('clientes/'.ltrim((string)$p,'/')); } }

$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
function hascol($t,$c){ global $conexion; $rs=@$conexion->query("SHOW COLUMNS FROM `$t` LIKE '$c'"); return ($rs && $rs->num_rows>0); }

$id = (int)($_GET['id'] ?? 0);
if (!$db_ok || $id<=0){ http_response_code(404); echo "Producto no encontrado."; exit; }

/* Producto */
$has_img=hascol('products','image_url'); $has_desc=hascol('products','description');
$prod_sql="SELECT id,name".($has_img?",image_url":"").($has_desc?",description":"")." FROM products WHERE id=? AND active=1 LIMIT 1";
$stmt=$conexion->prepare($prod_sql); $stmt->bind_param('i',$id); $stmt->execute(); $prod=$stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$prod){ http_response_code(404); echo "Producto no encontrado."; exit; }

$price_col = hascol('product_variants','price') ? 'price' : (hascol('product_variants','precio') ? 'precio' : null);
$stock_col = hascol('product_variants','stock') ? 'stock' : (hascol('product_variants','existencia') ? 'existencia' : null);
$size_col  = hascol('product_variants','size') ? 'size' : (hascol('product_variants','talla') ? 'talla' : null);
$color_col = hascol('product_variants','color') ? 'color' : null;
$sku_col   = hascol('product_variants','sku')   ? 'sku'   : null;

$cols=['id','product_id']; $cols[]=$sku_col?"$sku_col AS sku":"'' AS sku"; $cols[]=$size_col?"$size_col AS size":"'' AS size"; $cols[]=$color_col?"$color_col AS color":"'' AS color";
$cols[]=$price_col?"$price_col AS price":"0 AS price"; $cols[]=$stock_col?"$stock_col AS stock":"0 AS stock";
$sql="SELECT ".implode(',', $cols)." FROM product_variants WHERE product_id=? ORDER BY id ASC";
$stmt=$conexion->prepare($sql); $stmt->bind_param('i',$id); $stmt->execute(); $vres=$stmt->get_result(); $stmt->close();

$variants=[]; $sizes=[]; $colors=[]; $min_price=null;
while($v=$vres->fetch_assoc()){
  $variants[]=['id'=>(int)$v['id'],'sku'=>(string)$v['sku'],'size'=>(string)$v['size'],'color'=>(string)$v['color'],'price'=>(float)$v['price'],'stock'=>(int)$v['stock']];
  if ($v['size']!=='')  $sizes[$v['size']]=true;
  if ($v['color']!=='') $colors[$v['color']]=true;
  if ($min_price===null || $v['price']<$min_price) $min_price=(float)$v['price'];
}
$sizes=array_keys($sizes); $colors=array_keys($colors);

$header_path = $root.'/includes/header.php';
$img = (!empty($prod['image_url'])) ? $prod['image_url'] : ('https://picsum.photos/seed/'.(int)$prod['id'].'/640/480');
$cart_count = isset($_SESSION['cart_count']) ? (int)$_SESSION['cart_count'] : 0;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($prod['name']) ?> — Luna</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>">
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .product{display:grid;grid-template-columns:1fr 1fr;gap:18px}
    @media (max-width:860px){ .product{grid-template-columns:1fr} }
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden}
    .p{padding:14px}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .cta{display:inline-block;padding:.6rem 1rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none}
    .price{font-size:1.5rem;margin:.5rem 0}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    select.input, input.input{min-width:140px}
  </style>
</head>
<body>
  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <nav style="margin:10px 0"><a class="cta" href="<?= urlc('index.php') ?>">← Volver</a>
      <a class="cta" href="<?= urlc('carrito.php') ?>" style="float:right">🛒 Carrito <b><?= $cart_count ?></b></a>
    </nav>

    <section class="product">
      <div class="card"><img src="<?= h($img) ?>" alt="<?= h($prod['name']) ?>" width="640" height="480" loading="lazy"></div>
      <div class="card">
        <div class="p">
          <h1 style="margin:0 0 .4rem"><?= h($prod['name']) ?></h1>
          <?php if ($min_price!==null): ?>
            <div class="price">$ <?= money($min_price) ?> <span class="badge" id="varPrice" style="display:none"></span></div>
          <?php endif; ?>
          <?php if (!empty($prod['description'])): ?><p style="opacity:.9"><?= nl2br(h($prod['description'])) ?></p><?php endif; ?>

          <?php if (!$variants): ?>
            <div class="badge">Sin variantes cargadas</div>
          <?php else: ?>
            <form action="<?= urlc('carrito.php') ?>" method="post" id="addForm" class="p" style="padding-left:0" onsubmit="return pickDetail()">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>">
              <input type="hidden" name="variant_id" id="variant_id" value="">
              <div class="row" style="margin:10px 0">
                <?php if ($sizes): ?>
                  <label>Talle
                    <select class="input" id="selSize"><option value="">Elegí</option><?php foreach($sizes as $sz): ?><option value="<?= h($sz) ?>"><?= h($sz) ?></option><?php endforeach; ?></select>
                  </label>
                <?php endif; ?>
                <?php if ($colors): ?>
                  <label>Color
                    <select class="input" id="selColor"><option value="">Elegí</option><?php foreach($colors as $co): ?><option value="<?= h($co) ?>"><?= h($co) ?></option><?php endforeach; ?></select>
                  </label>
                <?php endif; ?>
                <label>Cantidad <input class="input" type="number" min="1" value="1" name="qty" id="qty"></label>
              </div>
              <div class="row" style="margin:10px 0">
                <span class="badge" id="stockLbl" style="display:none"></span>
                <span class="badge" id="skuLbl" style="display:none"></span>
              </div>
              <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" class="cta">🛒 Agregar al carrito</button>
                <a href="<?= urlc('index.php') ?>" class="cta">← Seguir viendo</a>
              </div>
              <script type="application/json" id="vdata"><?= json_encode($variants, JSON_UNESCAPED_UNICODE) ?></script>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <script>
  (function(){
    const VARS = JSON.parse(document.getElementById('vdata')?.textContent || '[]');
    const sizeEl = document.getElementById('selSize'), colorEl=document.getElementById('selColor');
    const priceEl=document.getElementById('varPrice'), stockLbl=document.getElementById('stockLbl'), skuLbl=document.getElementById('skuLbl');
    const hidVar=document.getElementById('variant_id');

    function findVariant(size, color){ return VARS.find(v => (size? v.size===size : true) && (color? v.color===color : true)) || null; }
    function refresh(){
      const s = sizeEl? sizeEl.value : ''; const c = colorEl? colorEl.value : '';
      const v = findVariant(s,c);
      if (v){ if(priceEl){priceEl.style.display='inline-block'; priceEl.textContent='Var: $ '+Number(v.price||0).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2});}
              if(stockLbl){stockLbl.style.display='inline-block'; stockLbl.textContent='Stock: '+(v.stock??0);}
              if(skuLbl){skuLbl.style.display='inline-block'; skuLbl.textContent='SKU: '+(v.sku||'-');}
              hidVar.value = v.id; }
      else { if(priceEl)priceEl.style.display='none'; if(stockLbl)stockLbl.style.display='none'; if(skuLbl)skuLbl.style.display='none'; hidVar.value=''; }
    }
    if (sizeEl) sizeEl.addEventListener('change', refresh);
    if (colorEl) colorEl.addEventListener('change', refresh);
    window.pickDetail = function(){ refresh(); if(!hidVar.value){alert('Elegí talle y color disponibles.'); return false;} return true; };
    refresh();
  })();
  </script>
</body>
</html>
