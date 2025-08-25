<?php
// Fragmento de header: solo NAV (sin <head> / <body>)

/* BASE dinámica (solo si no está definida) */
if (!isset($BASE)) { $BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); }
if (!function_exists('url')) {
  function url($path){ global $BASE; return $BASE.'/'.ltrim($path,'/'); }
}
?>
<nav class="nav" id="topnav" aria-label="Navegación principal">
  <div class="inner container">
    <a class="brand" href="<?=url('index.php')?>" aria-label="Inicio">
      <img src="<?=url('assets/img/logo.png')?>" alt="Luna Clothing" style="height:80px;width:auto;display:block">
      <span class="hide-sm" style="margin-left:8px;font-weight:800">Luna Clothing</span>
    </a>

    <div style="flex:1"></div>

    <!-- Botón hamburguesa (móvil) -->
    <button class="burger" aria-label="Abrir menú" onclick="document.getElementById('topnav').classList.toggle('open')">
      ☰
    </button>

    <!-- Menú -->
    <div class="menu">
      <a href="<?=url('productos.php')?>">Productos</a>
      <a href="<?=url('compras.php')?>">Compras</a>
      <a href="<?=url('ventas.php')?>">Ventas</a>
      <a href="<?=url('reportes.php')?>">Reportes</a>
      <a href="<?=url('categorias.php')?>">Categorías</a>
    </div>
  </div>
</nav>
<script>
  // Cerrar menú al tocar un link (móvil)
  (function(){
    var nav=document.getElementById('topnav');
    document.querySelectorAll('#topnav .menu a').forEach(function(a){
      a.addEventListener('click', function(){ nav.classList.remove('open'); });
    });
  })();
</script>
