<?php
if (session_status()===PHP_SESSION_NONE) session_start();

/* ===== Bases web (robusto para localhost y Render) ===== */
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$dir    = rtrim(str_replace('\\','/', dirname($script)), '/'); // /, /public, /clientes, /public/clientes, etc.

// Si la URL actual está dentro de /clientes, subimos un nivel para obtener /public (o /)
if (preg_match('~/(clientes)(/|$)~', $dir)) {
  $BASE_PUBLIC = rtrim(dirname($dir), '/');   // ej: /luna-shop/public  ó  /
} else {
  $BASE_PUBLIC = $dir;                        // ej: /luna-shop/public  ó  /
}
if ($BASE_PUBLIC === '') $BASE_PUBLIC = '/';  // normalizar raíz

$BASE_CLIENTES  = rtrim($BASE_PUBLIC, '/').'/clientes';
$is_client_area = (strpos($script, '/clientes/') !== false);

/* ===== Helpers de URL (sin dobles barras y sin duplicar /clientes) ===== */
if (!function_exists('url_public')) {
  function url_public($path){
    global $BASE_PUBLIC;
    $b = rtrim($BASE_PUBLIC, '/');
    $p = ltrim((string)$path, '/');
    return ($b === '' ? '' : $b).'/'.$p;      // si $b=='' => '/'.$p
  }
}
if (!function_exists('url_clientes')) {
  function url_clientes($path){
    global $BASE_CLIENTES;
    $p = ltrim((string)$path, '/');
    // si por error viene 'clientes/...' lo limpiamos para evitar clientes/clientes
    if (strpos($p, 'clientes/') === 0) $p = substr($p, 9);
    return rtrim($BASE_CLIENTES, '/').'/'.$p;
  }
}
// Compat con vistas antiguas
if (!function_exists('url')) {
  function url($path){ return url_public($path); }
}

/* ===== Logo (fallback a /public/assets/img/logo.png) ===== */
$logo_url = url_public('assets/img/logo.png');

/* ===== Menús ===== */
$menu_admin = [
  ['href'=>url_public('index.php'),      'text'=>'Inicio'],
  ['href'=>url_public('productos.php'),  'text'=>'Productos'],
  ['href'=>url_public('compras.php'),    'text'=>'Compras'],
  ['href'=>url_public('ventas.php'),     'text'=>'Ventas'],
  ['href'=>url_public('reportes.php'),   'text'=>'Reportes'],
  ['href'=>url_public('categorias.php'), 'text'=>'Categorías'],
];

$menu_client = [
  ['href'=>url_clientes('index.php'),    'text'=>'Tienda'],
  ['href'=>url_clientes('catalogo.php'), 'text'=>'Catálogo'],
  ['href'=>url_clientes('carrito.php'),  'text'=>'Carrito'],
];

$menu = $is_client_area ? $menu_client : $menu_admin;
?>
<nav class="nav" id="topnav" aria-label="Navegación principal">
  <div class="inner container">
    <a class="brand" href="<?= $is_client_area ? url_clientes('index.php') : url_public('index.php') ?>" aria-label="Inicio">
      <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Luna" style="height:80px;width:auto;display:block" onerror="this.style.display='none'">
      <span class="hide-sm" style="margin-left:8px;font-weight:800">Luna Clothing</span>
    </a>

    <div style="flex:1"></div>

    <button class="burger" aria-label="Abrir menú" onclick="document.getElementById('topnav').classList.toggle('open')">☰</button>

    <div class="menu">
      <?php foreach ($menu as $it): ?>
        <a href="<?= htmlspecialchars($it['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($it['text'], ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</nav>
<script>
(function(){
  var nav = document.getElementById('topnav');
  if (!nav) return;
  nav.querySelectorAll('.menu a').forEach(function(a){
    a.addEventListener('click', function(){ nav.classList.remove('open'); });
  });
})();
</script>

<?php
/* ===== Banner: Aviso de nueva compra (solo en index del ADMIN) ===== */
try {
  $is_home_admin = (!$is_client_area) && preg_match('~/index\.php$~', $script);
  if ($is_home_admin && isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno) {

    // ¿existe la tabla sales?
    $has_sales = false;
    if ($rs = @$conexion->query("SHOW TABLES LIKE 'sales'")) {
      $has_sales = ($rs->num_rows > 0);
      @$rs->free();
    }

    if ($has_sales) {
      // ¿existe la columna shipping_method?
      $has_ship = false;
      if ($rs = @$conexion->query("SHOW COLUMNS FROM sales LIKE 'shipping_method'")) {
        $has_ship = ($rs->num_rows > 0);
        @$rs->free();
      }

      // Traer la última venta en estado NEW
      $cols = "id, customer_name, total, created_at".($has_ship ? ", shipping_method" : "");
      $sql  = "SELECT $cols FROM sales WHERE status='new' ORDER BY created_at DESC LIMIT 1";

      if ($rs = @$conexion->query($sql)) {
        if ($row = $rs->fetch_assoc()) {
          $ventaId = (int)$row['id'];
          $nombre  = trim((string)$row['customer_name']);
          if ($nombre==='') $nombre = 'Cliente';
          $totalF  = number_format((float)($row['total'] ?? 0), 2, ',', '.');
          $shipVal = $has_ship ? strtolower((string)$row['shipping_method']) : '';
          // Texto según envío/retiro (si no hay columna, asumimos retiro)
          $modoTxt = ($shipVal === 'envio') ? '🚚 con envío a domicilio' : '🏬 retiro en tienda';

          $linkVentas = url_public('ventas.php');
          // Render del banner
          ?>
          <div id="banner-online" style="margin:10px 14px 0;
               background:#133321;border:1px solid #1f6f49;color:#c6f6d5;
               padding:10px 12px;border-radius:10px;display:flex;align-items:center;gap:10px;">
            <div>
              🛎️ <b>Nueva compra #<?= (int)$ventaId ?></b>
              — <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>
              — <?= htmlspecialchars($modoTxt, ENT_QUOTES, 'UTF-8') ?>
              — <b>$ <?= $totalF ?></b>
            </div>
            <div style="margin-left:auto;display:flex;gap:8px;">
              <a href="<?= htmlspecialchars($linkVentas, ENT_QUOTES, 'UTF-8') ?>"
                 class="cta" style="border:1px solid #1f6f49;color:inherit;text-decoration:none;padding:6px 10px;border-radius:8px;">
                 Ver ventas
              </a>
              <button type="button" onclick="this.closest('#banner-online').remove()"
                      style="background:transparent;color:inherit;border:0;cursor:pointer;font-weight:bold">✕</button>
            </div>
          </div>
          <?php
        }
        @$rs->free();
      }
    }
  }
} catch (Throwable $e) { /* silencioso */ }
?>
