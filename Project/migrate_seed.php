<?php
require_once __DIR__ . '/config.php';

$db = getDB();

// 1. ตรวจสอบ/เพิ่มคอลัมน์ใน receipts
try {
    $db->exec("ALTER TABLE receipts ADD COLUMN customer_name VARCHAR(200) AFTER customer_id");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE receipts ADD COLUMN customer_phone VARCHAR(50) AFTER customer_name");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE receipts ADD COLUMN vehicle_plate VARCHAR(50) AFTER customer_phone");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE receipts ADD COLUMN items_detail TEXT AFTER vehicle_plate");
} catch (Exception $e) {}

// 2. อัพเดทรหัสผ่าน Admin
$adminHash = password_hash('admin123', PASSWORD_DEFAULT);
$stmt = $db->prepare("UPDATE users SET password = ?, fullname = 'ผู้ดูแลระบบ (Admin)' WHERE username = 'admin'");
$stmt->execute([$adminHash]);

// 3. สร้าง/อัพเดทบัญชีลูกค้าตัวอย่าง
$custHash = password_hash('customer123', PASSWORD_DEFAULT);
$stmt = $db->prepare("SELECT id FROM users WHERE username = 'customer'");
$stmt->execute();
$custId = $stmt->fetchColumn();

if (!$custId) {
    $stmt = $db->prepare("INSERT INTO users (username, password, fullname, phone, email, address, role) VALUES (?, ?, ?, ?, ?, ?, 'customer')");
    $stmt->execute(['customer', $custHash, 'สมชาย มีทรัพย์ (ลูกค้าตัวอย่าง)', '089-999-8888', 'somchai@example.com', '123/45 หมู่ 2 ต.บางพลีใหญ่ อ.บางพลี จ.สมุทรปราการ 10540']);
    $custId = $db->lastInsertId();
} else {
    $stmt = $db->prepare("UPDATE users SET password = ?, fullname = 'สมชาย มีทรัพย์ (ลูกค้าตัวอย่าง)', phone = '089-999-8888', email = 'somchai@example.com', address = '123/45 หมู่ 2 ต.บางพลีใหญ่ อ.บางพลี จ.สมุทรปราการ 10540' WHERE id = ?");
    $stmt->execute([$custHash, $custId]);
}

// 4. สร้างรถตัวอย่างให้ลูกค้า (อย่างน้อย 2 คัน)
$stmt = $db->prepare("DELETE FROM member_vehicles WHERE user_id = ?");
$stmt->execute([$custId]);

$stmtV = $db->prepare("INSERT INTO member_vehicles (user_id, brand_id, model, license_plate, vehicle_type, year, color, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
// Toyota (id 1)
$stmtV->execute([$custId, 1, 'Vios', '1กข 1234 กทม', 'car', '2021', 'ขาว', 'รถยนต์ใช้งานประจำวัน']);
$v1 = $db->lastInsertId();
// Honda Motorcycle (id 20)
$stmtV->execute([$custId, 20, 'Wave 110i', '9กก 5678 สมุทรปราการ', 'motorcycle', '2023', 'แดง-ดำ', 'มอเตอร์ไซค์จ่ายตลาด']);
$v2 = $db->lastInsertId();

// 5. สร้างการรับประกันตัวอย่าง (Active)
$stmt = $db->prepare("DELETE FROM warranties WHERE customer_id = ?");
$stmt->execute([$custId]);

$stmtW = $db->prepare("INSERT INTO warranties (product_id, customer_id, vehicle_id, serial_number, purchase_date, warranty_end_date, warranty_months, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)");
// ประกันแบต 3K NS60L ซื้อเมื่อ 3 เดือนก่อน รับประกัน 12 เดือน
$purchaseDate = date('Y-m-d', strtotime('-3 months'));
$endDate = date('Y-m-d', strtotime('+9 months'));
$stmtW->execute([1, $custId, $v1, 'SN-3K-2026-8899', $purchaseDate, $endDate, 12, 'รับประกันเปลี่ยนลูกใหม่หากมีปัญหาจากโรงงาน']);
$wId = $db->lastInsertId();

// 6. เพิ่มตัวอย่างข้อมูลบัญชี (Accounting)
$stmt = $db->prepare("SELECT COUNT(*) FROM accounting");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $transactions = [
        ['income', 'ขายสินค้าหน้าร้าน', 2200.00, 'ขายแบตเตอรี่ 3K NS60L ให้ลูกค้าหน้าร้าน', date('Y-m-d', strtotime('-5 days'))],
        ['income', 'บริการเปลี่ยนแบตนอกสถานที่', 3800.00, 'เปลี่ยนแบต GS MF 80D26L แถวบางพลี ซอย 5', date('Y-m-d', strtotime('-4 days'))],
        ['expense', 'สั่งซื้อสต๊อกแบตเตอรี่', 24000.00, 'สั่งแบตเตอรี่ 3K และ FB เข้าสต๊อก 10 ลูก', date('Y-m-d', strtotime('-3 days'))],
        ['income', 'ขายสินค้าออนไลน์', 2800.00, 'จัดส่งแบตเตอรี่ FB 46B24L', date('Y-m-d', strtotime('-2 days'))],
        ['expense', 'ค่าน้ำมันรถช่างบริการ', 800.00, 'เติมน้ำมันรถกระบะบริการนอกสถานที่', date('Y-m-d', strtotime('-1 days'))],
        ['income', 'ขายสินค้าหน้าร้าน', 4500.00, 'เปลี่ยนแบตเตอรี่ Bosch DIN55 รถ Benz', date('Y-m-d')],
    ];
    $stmtAcc = $db->prepare("INSERT INTO accounting (type, category, amount, description, transaction_date, created_by) VALUES (?, ?, ?, ?, ?, 1)");
    foreach ($transactions as $t) {
        $stmtAcc->execute($t);
    }
}

// 7. เพิ่มตัวอย่างประวัติการรับเข้าและขายออกใน stock_in / stock_out
$stmt = $db->prepare("SELECT COUNT(*) FROM stock_in");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $db->prepare("INSERT INTO stock_in (product_id, quantity, cost_per_unit, supplier, received_date, notes, created_by) VALUES 
        (1, 10, 1600.00, 'บจก. ผลิตภัณฑ์ 3K แบตเตอรี่', DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'ล็อตนำเข้าประจำเดือน', 1),
        (3, 5, 2100.00, 'บจก. สยามฟูรูกาวา', DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'สั่งเติมสต๊อก', 1),
        (8, 15, 450.00, 'บจก. ยัวซ่า แบตเตอรี่ ประเทศไทย', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'แบตมอเตอร์ไซค์', 1)
    ")->execute();
}

$stmt = $db->prepare("SELECT COUNT(*) FROM stock_out");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $db->prepare("INSERT INTO stock_out (product_id, quantity, sale_price, customer_id, sold_date, notes, created_by) VALUES 
        (1, 1, 2200.00, ?, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'ติดตั้งให้รถ Toyota Vios', 1),
        (8, 1, 650.00, ?, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'ขายหน้าร้าน', 1)
    ")->execute([$custId, $custId]);
}

// 8. เพิ่มตัวอย่างใบเสร็จใน receipts
$stmt = $db->prepare("SELECT COUNT(*) FROM receipts");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $db->prepare("INSERT INTO receipts (receipt_number, customer_id, customer_name, customer_phone, vehicle_plate, total_amount, tax_amount, discount_amount, net_amount, payment_method, notes, items_detail, created_by) VALUES 
        ('REC-20261025-0001', ?, 'สมชาย มีทรัพย์ (ลูกค้าตัวอย่าง)', '089-999-8888', '1กข 1234 กทม', 2200.00, 0.00, 0.00, 2200.00, 'transfer', 'เปลี่ยนแบตเตอรี่หน้าร้าน พร้อมตรวจเช็คไดชาร์จฟรี', '3K แบตเตอรี่ NS60L x1', 1)
    ")->execute([$custId]);
}

echo "MIGRATION_AND_SEEDING_COMPLETE\n";

