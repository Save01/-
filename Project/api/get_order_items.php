<?php
require_once __DIR__ . '/../config.php';
requireStaff();

header('Content-Type: application/json; charset=utf-8');

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    echo json_encode([]);
    exit;
}

$db = getDB();
$stmt = $db->prepare("
    SELECT oi.*, p.name 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

echo json_encode($items);

