<?php
error_reporting(E_ALL); ini_set('display_errors', 1);
require __DIR__.'/../includes/conn.php';
echo "<h1>OK PHP</h1><pre>";
echo "Host: ".getenv('MYSQLHOST').":".getenv('MYSQLPORT')."\n";
echo "DB: ".getenv('MYSQLDATABASE')."  User: ".getenv('MYSQLUSER')."\n";
$r = $conexion->query("SELECT 1");
echo "MySQL: CONECTA OK\n";
echo "</pre>";
