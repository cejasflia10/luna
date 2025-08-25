<?php
// create_pages.php — Crea productos.php, compras.php, ventas.php, reportes.php mínimos
$base = __DIR__;
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
      <b>Página creada.</b> Luego la conectamos con la base de datos.
    </div></div>
  </main>
</body></html>
PHP;
};

$files = [
  'productos.php' => $tpl('Productos','Gestión y listado de artículos.'),
  'compras.php'   => $tpl('Compras','Entradas de stock (proveedores e ítems).'),
  'ventas.php'    => $tpl('Ventas','Registro de ventas y pagos.'),
  'reportes.php'  => $tpl('Reportes','Balance y métricas del negocio.'),
];

$results = [];
foreach ($files as $name=>$content){
  $path = $base.DIRECTORY_SEPARATOR.$name;
  $ok = @file_put_contents($path, $content)!==false;
  $results[] = [$name, $ok ? 'CREADO/ACTUALIZADO ✅' : 'ERROR ❌'];
}

header('Content-Type: text/plain; charset=utf-8');
echo "Resultado:\n";
foreach($results as $r){ echo "- {$r[0]}: {$r[1]}\n"; }
echo "\nUbicación: {$base}\n";
echo "Listo. Abrí ahora:\n";
echo "  http://localhost/luna-shop/public/productos.php\n";
echo "  http://localhost/luna-shop/public/compras.php\n";
echo "  http://localhost/luna-shop/public/ventas.php\n";
echo "  http://localhost/luna-shop/public/reportes.php\n";
