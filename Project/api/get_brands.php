<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$type = $_GET['type'] ?? '';

if ($type && in_array($type, ['car', 'motorcycle', 'both'])) {
    $stmt = $db->prepare("SELECT id, name, type FROM car_brands WHERE type = ? OR type = 'both' ORDER BY name");
    $stmt->execute([$type]);
} else {
    $stmt = $db->query("SELECT id, name, type FROM car_brands ORDER BY name");
}

$brands = $stmt->fetchAll();
echo json_encode($brands);
