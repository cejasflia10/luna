<?php // header.php
$config = require __DIR__ . '/../config/env.php';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($config['STORE_NAME'])?></title>
<link rel="stylesheet" href="/public/assets/styles.css">
</head>
<body>
<header class="site-header">
<a class="brand" href="/public/index.php">LUNA</a>
<nav>
<a href="/public/index.php">Inicio</a>
<a href="/public/cart.php">Carrito</a>
</nav>
</header>
<main class="container">