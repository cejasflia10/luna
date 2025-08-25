<?php
if (!function_exists('h')) {
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
function money($n){ return number_format((float)$n, 2, ',', '.'); }
}
if (!function_exists('post')) {
function post($k,$d=''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
}
if (!function_exists('db_begin')) { function db_begin(mysqli $db){ $db->begin_transaction(); } }
if (!function_exists('db_commit')) { function db_commit(mysqli $db){ $db->commit(); } }
if (!function_exists('db_rollback')) { function db_rollback(mysqli $db){ $db->rollback(); } }


// Busca (o crea) una variante por atributos básicos
function find_or_create_variant(mysqli $db, int $product_id, string $size, string $color, string $measure_text, float $price): int {
$stmt = $db->prepare("SELECT id FROM product_variants WHERE product_id=? AND COALESCE(size,'')=? AND COALESCE(color,'')=? AND COALESCE(measure_text,'')=? LIMIT 1");
$stmt->bind_param('isss', $product_id, $size, $color, $measure_text);
$stmt->execute(); $stmt->bind_result($vid);
if ($stmt->fetch()) { $stmt->close(); return (int)$vid; }
$stmt->close();


$stmt = $db->prepare("INSERT INTO product_variants(product_id,size,color,measure_text,price,stock,avg_cost) VALUES (?,?,?,?,?,0,0.00)");
$stmt->bind_param('isssd', $product_id, $size, $color, $measure_text, $price);
$stmt->execute();
$id = (int)$stmt->insert_id; $stmt->close();
return $id;
}


// Recalcula costo promedio móvil al recibir compras
function update_avg_cost_on_purchase(mysqli $db, int $variant_id, int $qty, float $unit_cost): void {
$row = $db->query("SELECT stock, avg_cost FROM product_variants WHERE id={$variant_id} FOR UPDATE")->fetch_assoc();
$stock = (int)($row['stock'] ?? 0);
$avg = (float)($row['avg_cost'] ?? 0);


$new_stock = $stock + $qty;
$new_avg = $new_stock > 0 ? (($stock * $avg) + ($qty * $unit_cost)) / $new_stock : $unit_cost;


$stmt = $db->prepare("UPDATE product_variants SET stock=?, avg_cost=? WHERE id=?");
$stmt->bind_param('idi', $new_stock, $new_avg, $variant_id);
$stmt->execute(); $stmt->close();
}


// Descuenta stock al vender y devuelve costo actual para registrar en sale_items
function take_stock_on_sale(mysqli $db, int $variant_id, int $qty): float {
$row = $db->query("SELECT stock, avg_cost FROM product_variants WHERE id={$variant_id} FOR UPDATE")->fetch_assoc();
$stock = (int)($row['stock'] ?? 0);
$avg = (float)($row['avg_cost'] ?? 0);
if ($qty > $stock) { throw new Exception('Stock insuficiente'); }
$new_stock = $stock - $qty;
$stmt = $db->prepare("UPDATE product_variants SET stock=? WHERE id=?");
$stmt->bind_param('ii', $new_stock, $variant_id);
$stmt->execute(); $stmt->close();
return $avg; // costo registrado en ese momento
}