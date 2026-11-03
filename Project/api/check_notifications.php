<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['count' => 0, 'new_notifications' => []]);
    exit;
}

$db = getDB();
$newNotifications = [];

// ตรวจสอบสินค้าใกล้หมดและสร้าง notification อัตโนมัติ (สำหรับ staff/admin เท่านั้น)
if (isStaff()) {
    $lowStockProducts = checkLowStock();
    
    foreach ($lowStockProducts as $product) {
        // ตรวจสอบว่ามี notification ของสินค้านี้ในวันนี้หรือยัง
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE type = 'low_stock' 
            AND message LIKE ? 
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute(['%' . $product['name'] . '%']);
        $exists = $stmt->fetchColumn();
        
        if ($exists == 0) {
            $title = 'สินค้าใกล้หมด!';
            $message = $product['name'] . ' เหลือ ' . $product['quantity'] . ' ชิ้น (ขั้นต่ำ: ' . $product['min_quantity'] . ')';
            $link = 'stock.php';
            
            // สร้าง notification สำหรับ admin ทั้งหมด
            $admins = $db->query("SELECT id FROM users WHERE role IN ('admin','staff')")->fetchAll();
            foreach ($admins as $admin) {
                createNotification($admin['id'], $title, $message, 'low_stock', $link);
            }
            
            $newNotifications[] = [
                'title' => $title,
                'message' => $message,
                'type' => 'low_stock'
            ];
        }
    }
}

// นับ notification ที่ยังไม่อ่านของผู้ใช้งานปัจจุบัน
$count = getUnreadNotificationCount($_SESSION['user_id']);

echo json_encode([
    'count' => (int)$count,
    'new_notifications' => $newNotifications
]);
