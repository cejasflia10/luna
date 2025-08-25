<?php
if (session_status()===PHP_SESSION_NONE) session_start();
?><!DOCTYPE html>
<html lang="es"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna Shop — Productos</title>
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
    <h1>Productos</h1><p>Gestión y listado de artículos.</p>
  </div></header>
  <main class="container">
    <div class="card" style="padding:14px"><div class="p">
      <b>Página creada.</b> Luego la conectamos a la base de datos.
    </div></div>
  </main>
</body></html>