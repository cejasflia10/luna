<?php
// Sirve archivos reales; si existen, los entrega tal cual.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
  return false; // deja que PHP sirva css/js/img/.php existentes
}
// Para todo lo demás, carga index.php
require __DIR__ . '/index.php';
