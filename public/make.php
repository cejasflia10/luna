<?php
// make.php — crea una página mínima: ?p=productos|compras|ventas|reportes
$tpl = function($title, $text){
return <<<PHP
<?php
if (session_status()===PHP_SESSION_NONE) session_start();
?><!DOCTYPE html>
<html lang="es"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna Shop — {$title}</title>
  <link rel="stylesheet" href="assets/css/styles.css">
</head><body>
  <div class="nav"><div class="row container">
    <a class="brand" href="index.php">Luna<span class="dot">•</span>Shop</a>
    <div style="flex:1"></div>
    <a href="productos.php">Productos</a>
    <a href="compras.php">Compras</a>
    <a href="ventas.php">Ventas</a>
    <a href="reportes.php">Reportes</a>
  </div></div>
  <header class="hero"><div class="container">
    <h1>{$title}</h1><p>{$text}</p>
  </div></header>
  <main class="container">
    <div class="card" style="padding:14px"><div class="p">
      <b>Página creada.</b> Luego la conectamos a la base de datos.
    </div></div>
  </main>
</body></html>
PHP;
};

$pages = [
  'productos' => ['Productos','Gestión y listado de artículos.'],
  'compras'   => ['Compras','Entradas de stock.'],
  'ventas'    => ['Ventas','Registro de ventas y pagos.'],
  'reportes'  => ['Reportes','Balance y métricas.'],
];

$p = strtolower($_GET['p'] ?? '');
if (!isset($pages[$p])) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Usá: make.php?p=productos | compras | ventas | reportes\n";
  exit;
}
[$t,$txt] = $pages[$p];
$file = __DIR__ . "/{$p}.php";
$ok = @file_put_contents($file, $tpl($t,$txt)) !== false;

header('Content-Type: text/plain; charset=utf-8');
echo ($ok ? "CREADO/ACTUALIZADO ✅ " : "ERROR ❌ ") . basename($file) . "\n";
echo "Abrir: http://localhost/luna-shop/public/{$p}.php\n";
