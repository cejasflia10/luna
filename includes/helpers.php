<?php
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n, $cur='ARS'){ return '$' . number_format((float)$n, 2, ',', '.'); }
function ok($m){ return "<div class='alert ok'>".h($m)."</div>"; }
function err($m){ return "<div class='alert err'>".h($m)."</div>"; }
function redirect($url){ header('Location: '.$url); exit; }
function is_post(){ return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function require_admin(){ if (empty($_SESSION['admin_id'])) { header('Location: /admin/login.php'); exit; } }