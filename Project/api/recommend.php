<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$licensePlate = $_GET['license_plate'] ?? '';
$vehicleId = $_GET['vehicle_id'] ?? '';

$vehicle = null;

// ค้นหาข้อมูลรถ
if ($vehicleId) {
    $stmt = $db->prepare("
        SELECT mv.*, cb.name AS brand_name 
        FROM member_vehicles mv 
        JOIN car_brands cb ON mv.brand_id = cb.id 
        WHERE mv.id = ?
    ");
    $stmt->execute([(int)$vehicleId]);
    $vehicle = $stmt->fetch();
} elseif ($licensePlate) {
    $stmt = $db->prepare("
        SELECT mv.*, cb.name AS brand_name 
        FROM member_vehicles mv 
        JOIN car_brands cb ON mv.brand_id = cb.id 
        WHERE mv.license_plate = ?
    ");
    $stmt->execute([$licensePlate]);
    $vehicle = $stmt->fetch();
}

if (!$vehicle) {
    echo json_encode(['error' => 'ไม่พบข้อมูลรถ', 'products' => []]);
    exit;
}

$brandId = $vehicle['brand_id'];
$model = $vehicle['model'];
$vehicleType = $vehicle['vehicle_type'];

// ค้นหาสินค้าแนะนำจาก recommended_products
$stmt = $db->prepare("
    SELECT p.*, bb.name AS brand_name, c.name AS category_name,
           rp.priority, 'recommended' AS source
    FROM recommended_products rp
    JOIN products p ON rp.product_id = p.id
    LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE rp.car_brand_id = ?
    AND (rp.car_model = ? OR rp.car_model IS NULL OR rp.car_model = '')
    AND rp.is_active = 1
    AND p.status = 'active'
    AND p.quantity > 0
    ORDER BY rp.priority ASC
");
$stmt->execute([$brandId, $model]);
$recommended = $stmt->fetchAll();

// ค้นหาสินค้าที่เข้ากันได้จาก product_compatibility
$stmt = $db->prepare("
    SELECT p.*, bb.name AS brand_name, c.name AS category_name,
           pc.car_model AS compatible_model, 'compatible' AS source
    FROM product_compatibility pc
    JOIN products p ON pc.product_id = p.id
    LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE pc.car_brand_id = ?
    AND (pc.car_model = ? OR pc.car_model IS NULL OR pc.car_model = '')
    AND p.status = 'active'
    AND p.quantity > 0
    ORDER BY p.name
");
$stmt->execute([$brandId, $model]);
$compatible = $stmt->fetchAll();

// รวมผลลัพธ์ โดยไม่ซ้ำ
$productIds = [];
$results = [];

foreach ($recommended as $p) {
    if (!in_array($p['id'], $productIds)) {
        $productIds[] = $p['id'];
        $results[] = $p;
    }
}

foreach ($compatible as $p) {
    if (!in_array($p['id'], $productIds)) {
        $productIds[] = $p['id'];
        $results[] = $p;
    }
}

echo json_encode([
    'vehicle' => [
        'id' => $vehicle['id'],
        'brand' => $vehicle['brand_name'],
        'model' => $vehicle['model'],
        'license_plate' => $vehicle['license_plate'],
        'vehicle_type' => $vehicle['vehicle_type']
    ],
    'products' => $results
]);
