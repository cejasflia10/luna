<?php
error_reporting(E_ALL); ini_set('display_errors', 1);
require __DIR__ . '/../includes/conn.php';
echo "<h1>OK PHP</h1><pre>";
echo "Conectado a: ".$conexion->host_info."\n";
$r = $conexion->query("SHOW TABLES");
$tabs = [];
while($row = $r->fetch_row()) $tabs[] = $row[0];
echo "Tablas: ".($tabs ? implode(', ', $tabs) : '(ninguna)')."\n";
echo "</pre>";
