<?php
if (session_status()===PHP_SESSION_NONE) session_start();

$root = dirname(__DIR__);
require $root.'/includes/conn.php';
require $root.'/includes/helpers.php';
require $root.'/includes/page_head.php'; // HERO unificado

/* Helpers por si faltan */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* Rutas dinámicas (localhost/Render) */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!function_exists('url')) {
  function url($p){ global $BASE; return $BASE.'/'.ltrim($p,'/'); }
}

/* ====== Flags de DB/esquema ====== */
$db_ok = isset($conexion) && $conexion instanceof mysqli && !$conexion->connect_errno;
$has_categories = $has_products = $has_created = false;

if ($db_ok) {
  $has_categories = !!(@$conexion->query("SHOW TABLES LIKE 'categories'")->num_rows ?? 0);
  $has_products   = !!(@$conexion->query("SHOW TABLES LIKE 'products'")->num_rows ?? 0);
  if ($has_categories) {
    $has_created = !!(@$conexion->query("SHOW COLUMNS FROM categories LIKE 'created_at'")->num_rows ?? 0);
  }
}

/* ====== Utilidades ====== */
function slugify($text){
  $text = iconv('UTF-8','ASCII//TRANSLIT', $text);
  $text = preg_replace('~[^\\pL\\d]+~u', '-', $text);
  $text = trim($text, '-');
  $text = strtolower($text);
  $text = preg_replace('~[^-a-z0-9]+~', '', $text);
  return $text ?: 'categoria';
}

$okMsg = $errMsg = '';

/* ====== Crear categoría ====== */
if ($db_ok && $has_categories && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action']??'')==='create') {
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

/* ====== Alternar activo ====== */
if ($db_ok && $has_categories && isset($_GET['toggle'])) {
  $id = (int)$_GET['toggle'];
  @$conexion->query("UPDATE categories SET active=1-active WHERE id=".$id);
  header('Location: '.url('categorias.php')); exit;
}

/* ====== Eliminar ====== */
if ($db_ok && $has_categories && isset($_GET['delete'])) {
  $id = (int)$_GET['delete'];
  if ($has_products) {
    $rs = @$conexion->query("SELECT COUNT(*) c FROM products WHERE category_id=".$id);
    $c = $rs ? (int)$rs->fetch_assoc()['c'] : 0;
  } else {
    $c = 0; // si no existe products, permitimos borrar
  }
  if ($c>0) {
    $errMsg = "❌ No se puede borrar: hay productos en esta categoría.";
  } else {
    @$conexion->query("DELETE FROM categories WHERE id=".$id);
    header('Location: '.url('categorias.php')); exit;
  }
}

/* ====== Listado ====== */
$cats = null;
if ($db_ok && $has_categories) {
  $order = $has_created ? "active DESC, name ASC" : "active DESC, name ASC";
  $selectCreated = $has_created ? "created_at" : "NULL AS created_at";
  $cats = @$conexion->query("SELECT id,name,slug,active,$selectCreated FROM categories ORDER BY $order");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Luna — Categorías</title>
  <link rel="stylesheet" href="<?=url('assets/css/styles.css')?>">
  <link rel="icon" type="image/png" href="<?=url('assets/img/logo.png')?>">
</head>
<body>

<?php require $root.'/includes/header.php'; ?>

<?php
page_head('Categorías', 'Creá y administrá las categorías del catálogo.');
?>

<main class="container">
  <?php if(!$db_ok): ?>
    <div class="kpi"><div class="box"><b>Error</b> ❌ No se pudo conectar a la base de datos.</div></div>
  <?php elseif(!$has_categories): ?>
    <div class="kpi"><div class="box"><b>Falta tabla</b> ❌ No existe la tabla <code>categories</code>. Ejecutá el <code>schema.sql</code>.</div></div>
  <?php endif; ?>

  <?php if($okMsg): ?><div class="kpi"><div class="box"><b>OK</b> <?=h($okMsg)?></div></div><?php endif; ?>
  <?php if($errMsg): ?><div class="kpi"><div class="box"><b>Error</b> <?=h($errMsg)?></div></div><?php endif; ?>

  <h2>➕ Nueva categoría</h2>
  <form method="post" class="card" style="padding:14px;max-width:640px" <?= ($db_ok && $has_categories)?'':'onSubmit="return false;"'?>>
    <input type="hidden" name="__action" value="create">
    <label>Nombre
      <input class="input" name="name" required placeholder="Ej: Remeras" <?= ($db_ok && $has_categories)?'':'disabled'?>>
    </label>
    <button type="submit" <?= ($db_ok && $has_categories)?'':'disabled'?> >Guardar</button>
  </form>

  <h2 class="mt-3">Listado</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nombre</th><th>Slug</th><th>Estado</th><th>Creada</th><th></th></tr></thead>
      <tbody>
        <?php if($cats && $cats->num_rows>0): while($c=$cats->fetch_assoc()): ?>
        <tr>
          <td><?=h($c['name'])?></td>
          <td><?=h($c['slug'])?></td>
          <td><?= $c['active'] ? 'Activa' : 'Inactiva' ?></td>
          <td><?=h($c['created_at'] ?: '—')?></td>
          <td style="white-space:nowrap">
            <a href="<?=url('categorias.php?toggle='.(int)$c['id'])?>">Alternar</a> &nbsp;|&nbsp;
            <a href="<?=url('categorias.php?delete='.(int)$c['id'])?>" onclick="return confirm('¿Eliminar categoría? Solo si no tiene productos.');">Eliminar</a>
          </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="5">Sin categorías aún.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<?php require $root.'/includes/footer.php'; ?>
</body>
</html>
