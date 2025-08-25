<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';
require $root.'/includes/page_head.php'; // HERO unificado

/* Helpers por si faltan */
if (!function_exists('h'))     { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n){ return number_format((float)$n, 2, ',', '.'); } }

/* Rutas dinámicas (localhost/Render) */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('url')) {
  function url($p){ global $BASE; return $BASE.'/'.ltrim($p,'/'); }
}

/* (Opcional) flags mínimos si luego calculamos métricas */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Reportes</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php
page_head('Reportes', 'Balance y métricas');
?>

<main class="container">
  <!-- KPIs (placeholder, luego lo calculamos desde DB) -->
  <section class="kpi">
    <div class="box">
      <b>Ingresos (30d)</b>
      <div>$ 0,00</div>
    </div>
    <div class="box">
      <b>Costos (30d)</b>
      <div>$ 0,00</div>
    </div>
    <div class="box">
      <b>Ganancia (30d)</b>
      <div>$ 0,00</div>
    </div>
    <div class="box">
      <b>Tickets</b>
      <div>0</div>
    </div>
  </section>

  <!-- Tabla de últimas ventas (placeholder) -->
  <h2 class="mt-3">Últimas ventas</h2>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Comprobante</th>
          <th>Método</th>
          <th>Cliente</th>
          <th class="right">Total</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td colspan="5">Sin datos aún. Conectaremos esta sección a la base de datos.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Nota -->
  <div class="card mt-3" style="padding:14px">
    <div class="p">
      <b>Página creada.</b> Próximo paso: conectar cálculos (ingresos, costos, margen) a las tablas
      <code>sales</code>, <code>sale_items</code>, <code>purchases</code> y <code>purchase_items</code>.
    </div>
  </div>
</main>

<?php require $root.'/includes/footer.php'; ?>
</body>
</html>
