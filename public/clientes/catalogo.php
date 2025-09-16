<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__, 2);
require $root.'/includes/conn.php';
@require $root.'/includes/helpers.php';

if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

$script = $_SERVER['SCRIPT_NAME'] ?? '';
$BASE   = rtrim(dirname($script), '/\\');
if (!function_exists('url')) { function url($path){ global $BASE; $b=rtrim($BASE,'/'); $p='/'.ltrim((string)$path,'/'); return ($b===''?'':$b).$p; } }
if (!function_exists('url_public')) { function url_public($p){ global $BASE; $b=rtrim(dirname($BASE),'/'); return ($b===''?'':$b).'/'.ltrim((string)$p,'/'); } }
if (!function_exists('urlc')) { function urlc($p){ return url_public('clientes/'.ltrim((string)$p,'/')); } }

$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
function hascol($t,$c){ global $conexion; $rs=@$conexion->query("SHOW COLUMNS FROM `$t` LIKE '$c'"); return ($rs && $rs->num_rows>0); }

$has_products=$has_variants=false; $has_image_url=$has_created_at=false; $has_variant_price=false; $sql_err='';
if ($db_ok) {
  $has_products = (@$conexion->query("SHOW TABLES LIKE 'products'")?->num_rows ?? 0)>0;
  $has_variants = (@$conexion->query("SHOW TABLES LIKE 'product_variants'")?->num_rows ?? 0)>0;
  if ($has_products) {
    $has_image_url   = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'image_url'")?->num_rows ?? 0)>0;
    $has_created_at  = (@$conexion->query("SHOW COLUMNS FROM products LIKE 'created_at'")?->num_rows ?? 0)>0;
  }
  if ($has_variants) {
    $has_variant_price = (@$conexion->query("SHOW COLUMNS FROM product_variants LIKE 'price'")?->num_rows ?? 0)>0;
  }
}

/* filtros (si tenés) */
$qtext = trim($_GET['q'] ?? '');
$cat_id = (int)($_GET['cat'] ?? 0);

$baseWhere = ["p.active=1"];
if ($qtext!=='') $baseWhere[] = "p.name LIKE '%".@$conexion->real_escape_string($qtext)."%'";
if ($cat_id>0 && (@$conexion->query("SHOW COLUMNS FROM products LIKE 'category_id'")?->num_rows ?? 0)>0) $baseWhere[]="p.category_id=".$cat_id;
$where = implode(' AND ', $baseWhere);

$select = "p.id,p.name".($has_image_url?",p.image_url":"")
        .",".(($has_variants&&$has_variant_price)?"(SELECT COALESCE(MIN(v2.price),0) FROM product_variants v2 WHERE v2.product_id=p.id)":"0")." AS min_price";
$order = $has_created_at ? "p.created_at DESC" : "p.id DESC";
$sql   = "SELECT $select FROM products p WHERE $where ORDER BY $order LIMIT 48";
$prods = $db_ok ? @$conexion->query($sql) : null;

$cart_count = isset($_SESSION['cart_count']) ? (int)$_SESSION['cart_count'] : 0;
$header_path = $root.'/includes/header.php';

$price_col = $has_variants && hascol('product_variants','price') ? 'price' : (hascol('product_variants','precio') ? 'precio' : null);
$stock_col = $has_variants && hascol('product_variants','stock') ? 'stock' : (hascol('product_variants','existencia') ? 'existencia' : null);
$size_col  = $has_variants && hascol('product_variants','size')  ? 'size'  : (hascol('product_variants','talla') ? 'talla' : null);
$color_col = $has_variants && hascol('product_variants','color') ? 'color' : null;
$sku_col   = $has_variants && hascol('product_variants','sku')   ? 'sku'   : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Catálogo</title>
  <link rel="stylesheet" href="<?= url_public('assets/css/styles.css') ?>">
  <link rel="icon" type="image/png" href="<?= url_public('assets/img/logo.png') ?>">
  <style>
    .container{max-width:1100px;margin:0 auto;padding:0 14px}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
    .card{background:var(--card,#12141a);border:1px solid var(--ring,#2d323d);border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
    .p{padding:12px}
    .badge{display:inline-block;padding:.2rem .5rem;border:1px solid var(--ring);border-radius:.5rem;font-size:.8rem;opacity:.9}
    .btn,.cta{display:inline-block;padding:.5rem .9rem;border:1px solid var(--ring);border-radius:.6rem;text-decoration:none;background:transparent;color:inherit;cursor:pointer}
    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .input{min-width:120px}
    .muted{opacity:.8}
  </style>
</head>
<body>

  <?php if (file_exists($header_path)) { require $header_path; } ?>

  <div class="container">
    <header class="tienda" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 0">
      <h2 style="margin:0">📚 Catálogo</h2>
      <a class="btn" href="<?= urlc('carrito.php') ?>">🛒 Carrito <b><?= $cart_count ?></b></a>
    </header>

    <?php if($db_ok && $prods && $prods->num_rows>0): ?>
      <div class="grid">
      <?php while($p=$prods->fetch_assoc()): ?>
        <?php
          $img = ($has_image_url && !empty($p['image_url'])) ? $p['image_url'] : ('https://picsum.photos/seed/'.(int)$p['id'].'/640/480');
          $variants = [];
          if ($has_variants) {
            $cols = ['id'];
            $cols[] = $sku_col   ? "$sku_col AS sku"     : "'' AS sku";
            $cols[] = $size_col  ? "$size_col AS size"   : "'' AS size";
            $cols[] = $color_col ? "$color_col AS color" : "'' AS color";
            $cols[] = $price_col ? "$price_col AS price" : "0 AS price";
            $cols[] = $stock_col ? "$stock_col AS stock" : "0 AS stock";
            $vsql = "SELECT ".implode(',', $cols)." FROM product_variants WHERE product_id=".(int)$p['id']." ORDER BY id ASC";
            if ($vrs = @$conexion->query($vsql)) while($vv=$vrs->fetch_assoc()){
              $variants[] = ['id'=>(int)$vv['id'],'sku'=>(string)$vv['sku'],'size'=>(string)$vv['size'],'color'=>(string)$vv['color'],'price'=>(float)$vv['price'],'stock'=>(int)$vv['stock']];
            }
          }
          $sizes=[]; $colors=[];
          foreach($variants as $v){ if($v['size']!=='') $sizes[$v['size']]=true; if($v['color']!=='') $colors[$v['color']]=true; }
          $sizes=array_keys($sizes); $colors=array_keys($colors);
        ?>
        <div class="card" data-prodid="<?= (int)$p['id'] ?>">
          <a href="<?= urlc('ver.php?id='.(int)$p['id']) ?>"><img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" loading="lazy" width="640" height="480"></a>
          <div class="p">
            <h3 style="margin:.2rem 0 .4rem"><?= h($p['name']) ?></h3>
            <div class="row">
              <span class="badge">Desde $ <?= money($p['min_price']) ?></span>
              <?php if ($variants): ?>
                <span class="badge muted" id="price-<?= (int)$p['id'] ?>" style="display:none"></span>
                <span class="badge muted" id="stock-<?= (int)$p['id'] ?>" style="display:none"></span>
              <?php endif; ?>
            </div>

            <?php if ($variants): ?>
              <form class="p" style="padding-left:0" action="<?= urlc('carrito.php') ?>" method="post" onsubmit="return window._pickAndSubmit(this, <?= (int)$p['id'] ?>)">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="variant_id" value="">
                <div class="row">
                  <label>Talle
                    <select class="input" name="size" data-role="size">
                      <option value="">Elegí</option>
                      <?php foreach($sizes as $sz): ?><option value="<?= h($sz) ?>"><?= h($sz) ?></option><?php endforeach; ?>
                    </select>
                  </label>
                  <label>Color
                    <select class="input" name="color" data-role="color">
                      <option value="">Elegí</option>
                      <?php foreach($colors as $co): ?><option value="<?= h($co) ?>"><?= h($co) ?></option><?php endforeach; ?>
                    </select>
                  </label>
                  <label>Cantidad
                    <input class="input" type="number" name="qty" value="1" min="1" step="1" data-role="qty">
                  </label>
                </div>
                <div class="row" style="margin-top:8px">
                  <button type="submit" class="btn" data-role="addbtn" disabled>➕ Agregar</button>
                  <a class="btn" href="<?= urlc('ver.php?id='.(int)$p['id']) ?>">Ver</a>
                </div>
                <script type="application/json" id="vdata-<?= (int)$p['id'] ?>"><?= json_encode($variants, JSON_UNESCAPED_UNICODE) ?></script>
              </form>
            <?php else: ?>
              <div class="row"><a class="btn" href="<?= urlc('ver.php?id='.(int)$p['id']) ?>">Ver</a></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endwhile; ?>
      </div>
    <?php else: ?>
      <div class="card" style="padding:14px;margin-bottom:12px"><div class="p">No encontramos productos<?= ($qtext!=='' || ($cat_id??0)>0) ? " con ese filtro" : "" ?>. 🙌</div></div>
    <?php endif; ?>
  </div>

  <script>
  (function(){
    function findVariant(vlist, size, color){ return vlist.find(v => (size? v.size===size : true) && (color? v.color===color : true)) || null; }
    window._pickAndSubmit = function(form, prodId){
      const sizeSel=form.querySelector('[data-role="size"]'), colorSel=form.querySelector('[data-role="color"]'), qtyIn=form.querySelector('[data-role="qty"]');
      const hidVar=form.querySelector('input[name="variant_id"]'); const vjsonEl=document.getElementById('vdata-'+prodId);
      const priceEl=document.getElementById('price-'+prodId), stockEl=document.getElementById('stock-'+prodId);
      if (!vjsonEl) return false; const variants = JSON.parse(vjsonEl.textContent || '[]');
      const size = sizeSel? sizeSel.value:''; const color = colorSel? colorSel.value:'';
      const v = findVariant(variants, size, color); if (!v){ alert('Elegí talle y color disponibles.'); return false; }
      const qty = Math.max(1, parseInt(qtyIn.value || '1', 10)); if (v.stock>=0 && qty>v.stock){ alert('Stock insuficiente. Disponible: '+v.stock); return false; }
      hidVar.value = v.id; if (priceEl){priceEl.style.display='inline-block'; priceEl.textContent='Var: $ '+Number(v.price||0).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2});}
      if (stockEl){stockEl.style.display='inline-block'; stockEl.textContent='Stock: '+(v.stock??0);} return true;
    };
    document.querySelectorAll('form').forEach(form=>{
      const card=form.closest('.card'); const prodId=parseInt(card?.getAttribute('data-prodid')||'0',10);
      const vjsonEl=document.getElementById('vdata-'+prodId); if (!vjsonEl) return; const variants=JSON.parse(vjsonEl.textContent||'[]');
      const sizeSel=form.querySelector('[data-role="size"]'), colorSel=form.querySelector('[data-role="color"]'), btnAdd=form.querySelector('[data-role="addbtn"]');
      const priceEl=document.getElementById('price-'+prodId), stockEl=document.getElementById('stock-'+prodId);
      function refresh(){ const size=sizeSel?sizeSel.value:''; const color=colorSel?colorSel.value:''; const v = findVariant(variants,size,color);
        if (v){ btnAdd.disabled=false; if(priceEl){priceEl.style.display='inline-block';priceEl.textContent='Var: $ '+Number(v.price||0).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2});}
               if(stockEl){stockEl.style.display='inline-block';stockEl.textContent='Stock: '+(v.stock??0);} }
        else { btnAdd.disabled=true; if(priceEl)priceEl.style.display='none'; if(stockEl)stockEl.style.display='none'; }
      }
      if (sizeSel) sizeSel.addEventListener('change', refresh);
      if (colorSel) colorSel.addEventListener('change', refresh);
      refresh();
    });
  })();
  </script>
</body>
</html>
