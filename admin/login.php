<?php require __DIR__ . '/../includes/auth.php'; require __DIR__.'/../includes/helpers.php';
if (is_post()){
if (auth_login($_POST['email']??'', $_POST['pass']??'')) redirect('/admin/dashboard.php');
$msg = err('Credenciales inválidas');
}
?><!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/public/assets/styles.css"><title>Login</title></head><body class="container">
<h1>Acceso</h1>
<?= $msg ?? '' ?>
<form method="post" class="card" style="padding:16px">
<label>Email</label><input class="input" name="email" type="email" required>
<label>Contraseña</label><input class="input" name="pass" type="password" required>
<p><button class="btn" type="submit">Entrar</button></p>
</form>
</body></html>