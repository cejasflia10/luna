<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require dirname(__DIR__).'/includes/conn.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function slugify($text){
  $text = iconv('UTF-8','ASCII//TRANSLIT', $text);
  $text = preg_replace('~[^\\pL\\d]+~u', '-', $text);
  $text = trim($text, '-');
  $text = strtolower($text);
  $text = preg_replace('~[^-a-z0-9]+~', '', $text);
  return $text ?: 'categoria';
}

$okMsg = $errMsg = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create') {
  try {
    $name = trim($_POST['name'] ?? '');
    if ($name==='') throw new Exception('El nombre es obligatorio.');
    $slug = slugify($name);
    $stmt = $conexion->prepare("INSERT INTO categories (name, slug, active) VALUES (?,?,1)");
    $stmt->bind_param('ss', $name, $slug);
    $stmt->execute(); $stmt->close();
    $okMsg = "✅ Categoría creada.";
  } catch(Throwable $e){ $errMsg = "❌ ".$e->getMessage(); }
}

if (isset($_GET['toggle'])) {
  $id = (int)$_GET['toggle'];
  $conexion->query("UPDATE categories SET active=1-active WHERE id=".$id);
  header('Location: categorias.php'); exit;
}

if (isset($_GET['delete'])) {
  $id = (int)$_GET['delete'];
  $rs = $conexion->query("SELECT COUNT(*) c FROM products WHERE category_id=".$id);
  $c = $rs ? (int)$rs->fetch_assoc()['c'] : 0;
  if ($c>0) { $errMsg = "❌ No se puede borrar: hay productos en esta categoría."; }
  else { $conexion->query("DELETE FROM categories WHERE id=".$id); header('Location: categorias.php'); exit; }
}

$cats = $conexion->query("SELECT id,name,slug,active,created_at FROM categories ORDER BY active DESC, name ASC");
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Luna Shop — Categorías</title>
<link rel="stylesheet" href="assets/css/styles.css">
</head><body>
<div class="nav"><div class="row container">
<a class="brand" href="index.php" aria-label="Inicio">
  <img src="assets/img/logo.png" alt="Luna Clothing" style="height:40px;display:block">
</a>
  <div style="flex:1"></div>
  <a href="productos.php">Productos</a>
  <a href="compras.php">Compras</a>
  <a href="ventas.php">Ventas</a>
  <a href="reportes.php">Reportes</a>
  <a href="categorias.php">Categorías</a>
</div></div>

<header class="hero"><div class="container">
  <h1>Categorías</h1>
  <p>Creá y administrá las categorías del catálogo.</p>
</div></header>

<main class="container">
  <?php if($okMsg): ?><div class="kpi"><div class="box"><b>OK</b><?=h($okMsg)?></div></div><?php endif; ?>
  <?php if($errMsg): ?><div class="kpi"><div class="box"><b>Error</b><?=h($errMsg)?></div></div><?php endif; ?>

  <h2>➕ Nueva categoría</h2>
  <form method="post" class="card" style="padding:14px;max-width:640px">
    <input type="hidden" name="__action" value="create">
    <label>Nombre
      <input class="input" name="name" required placeholder="Ej: Remeras">
    </label>
    <button type="submit">Guardar</button>
  </form>

  <h2 style="margin-top:20px">Listado</h2>
  <table class="table">
    <thead><tr><th>Nombre</th><th>Slug</th><th>Estado</th><th>Creada</th><th></th></tr></thead>
    <tbody>
      <?php if($cats && $cats->num_rows>0): while($c=$cats->fetch_assoc()): ?>
      <tr>
        <td><?=h($c['name'])?></td>
        <td><?=h($c['slug'])?></td>
        <td><?= $c['active'] ? 'Activa' : 'Inactiva' ?></td>
        <td><?=h($c['created_at'])?></td>
        <td style="white-space:nowrap">
          <a href="categorias.php?toggle=<?=$c['id']?>">Alternar</a> &nbsp;|&nbsp;
          <a href="categorias.php?delete=<?=$c['id']?>" onclick="return confirm('¿Eliminar categoría? Solo si no tiene productos.');">Eliminar</a>
        </td>
      </tr>
      <?php endwhile; else: ?>
      <tr><td colspan="5">Sin categorías aún.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</main>
</body></html>
