<?php
require_once __DIR__ . '/../config.php';
requireStaff();

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_GET['user_id'] ?? 0);
if (!$userId) {
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

$db = getDB();

// ดึงรถของสมาชิก
$stmtV = $db->prepare("
    SELECT mv.*, cb.name as brand_name 
    FROM member_vehicles mv 
    LEFT JOIN car_brands cb ON mv.brand_id = cb.id 
    WHERE mv.user_id = ? 
    ORDER BY mv.id DESC
");
$stmtV->execute([$userId]);
$vehicles = $stmtV->fetchAll();

// ดึงคำสั่งซื้อล่าสุด 5 รายการ
$stmtO = $db->prepare("SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 5");
$stmtO->execute([$userId]);
$orders = $stmtO->fetchAll();

// ดึงประกันที่ยังใช้งาน
$stmtW = $db->prepare("
    SELECT w.*, p.name as product_name 
    FROM warranties w 
    LEFT JOIN products p ON w.product_id = p.id 
    WHERE w.customer_id = ? 
    ORDER BY w.warranty_end_date DESC
");
$stmtW->execute([$userId]);
$warranties = $stmtW->fetchAll();

echo json_encode([
    'vehicles' => $vehicles,
    'orders' => $orders,
    'warranties' => $warranties
]);

