<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$brandId = $_GET['brand_id'] ?? '';
$vehicleType = $_GET['vehicle_type'] ?? '';

$where = ["p.status = 'active'"];
$params = [];

if ($brandId) {
    $where[] = "p.battery_brand_id = ?";
    $params[] = (int)$brandId;
}

if ($vehicleType && in_array($vehicleType, ['car', 'motorcycle', 'both'])) {
    $where[] = "(p.vehicle_type = ? OR p.vehicle_type = 'both')";
    $params[] = $vehicleType;
}

$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT p.id, p.name, p.sku, p.price, p.quantity, p.warranty_months, p.vehicle_type, p.image,
           bb.name AS brand_name, c.name AS category_name
    FROM products p
    LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE {$whereClause}
    ORDER BY p.name
");
$stmt->execute($params);
$products = $stmt->fetchAll();

echo json_encode($products);
