<?php
require __DIR__.'/conn.php';
require __DIR__.'/helpers.php';


function auth_login($email, $pass){
global $conexion;
$st = $conexion->prepare('SELECT id, name, email, pass_hash, role FROM users WHERE email = ? LIMIT 1');
$st->bind_param('s', $email);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) return false;
$hash = $row['pass_hash'] ?? '';
$ok = (str_starts_with($hash, '$2y$') ? password_verify($pass, $hash) : hash_equals($hash, $pass));
if ($ok){
$_SESSION['admin_id'] = (int)$row['id'];
$_SESSION['admin_name'] = $row['name'];
$_SESSION['admin_role'] = $row['role'];
return true;
}
return false;
}


function auth_logout(){ session_destroy(); }