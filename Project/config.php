<?php
ob_start();
// ===================================================
// Config - ร้านพรชัยแบตเตอรี่ บางพลี
// ===================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'pornchai_battery');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SITE_NAME', 'พรชัยแบตเตอรี่ บางพลี');
define('SHOP_ADDRESS', '88/9 หมู่ 1 ต.บางพลีใหญ่ อ.บางพลี จ.สมุทรปราการ 10540');
define('SHOP_PHONE', '081-234-5678, 089-876-5432');
define('SHOP_TAX_ID', '0105562012345');
define('SHOP_LINE', '@pornchaibattery');

// Dynamic SITE_URL detection
if (!defined('SITE_URL')) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($scriptName));
    if (basename($dir) === 'api') {
        $dir = dirname($dir);
    }
    $dir = rtrim($dir, '/');
    define('SITE_URL', ($dir === '' || $dir === '.') ? '/' : $dir . '/');
}

define('UPLOAD_DIR', __DIR__ . '/uploads/');

// สร้างโฟลเดอร์ uploads ถ้ายังไม่มี
$upload_dirs = ['uploads', 'uploads/products', 'uploads/claims', 'uploads/profiles', 'uploads/orders', 'uploads/receipts'];
foreach ($upload_dirs as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

// Database connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Session safe start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ฟังก์ชันตรวจสอบ login & สิทธิ์
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isStaff() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . 'index.php');
        exit;
    }
}

function requireStaff() {
    requireLogin();
    if (!isStaff()) {
        header('Location: ' . SITE_URL . 'index.php');
        exit;
    }
}

// Flash messages
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Notification count
function getUnreadNotificationCount($userId = null) {
    $db = getDB();
    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
    }
    if ($userId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
    return 0;
}

// สร้าง notification
function createNotification($userId, $title, $message, $type = 'general', $link = '') {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $title, $message, $type, $link]);
}

// ตรวจสอบสินค้าใกล้หมด
function checkLowStock() {
    $db = getDB();
    $stmt = $db->query("SELECT p.*, bb.name as brand_name FROM products p LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id WHERE p.quantity <= p.min_quantity AND p.status = 'active' ORDER BY p.quantity ASC");
    return $stmt->fetchAll();
}

// ฟอร์แมตเงิน
function formatMoney($amount) {
    return number_format($amount, 2) . ' บาท';
}

// สร้างเลขที่เอกสาร
function generateOrderNumber() {
    return 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateReceiptNumber() {
    return 'REC-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

// Upload file (รองรับไฟล์รูปภาพทุกฟอร์แมตจากมือถือและคอมพิวเตอร์)
function uploadFile($file, $directory) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'jfif', 'heic', 'heif', 'bmp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // ตรวจสอบนามสกุลไฟล์
    if (!in_array($ext, $allowedExtensions, true)) {
        // Fallback ตรวจสอบ MIME type
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/x-png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/bmp'];
        if (!empty($file['type']) && in_array(strtolower($file['type']), $allowedMimes, true)) {
            $ext = 'jpg';
        } else {
            return false;
        }
    }
    
    if ($ext === 'jpeg' || $ext === 'jfif') $ext = 'jpg';

    $filename = uniqid('img_', true) . '_' . time() . '.' . $ext;
    $targetDir = UPLOAD_DIR . $directory . '/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $targetPath = $targetDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/' . $directory . '/' . $filename;
    }
    return false;
}

// Upload Base64 Image (บันทึกรูปภาพที่ถ่ายจากกล้องเว็บแคมหรือมือถือผ่าน Canvas)
function uploadBase64Image($base64Data, $directory) {
    if (empty($base64Data)) {
        return false;
    }
    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
        $data = substr($base64Data, strpos($base64Data, ',') + 1);
        $ext = strtolower($type[1]);
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $ext = 'jpg';
        }
        if ($ext === 'jpeg') $ext = 'jpg';
        $decoded = base64_decode($data);
        if ($decoded === false) {
            return false;
        }
        $filename = uniqid('cam_', true) . '_' . time() . '.' . $ext;
        $targetDir = UPLOAD_DIR . $directory . '/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $targetPath = $targetDir . $filename;
        if (file_put_contents($targetPath, $decoded) !== false) {
            return 'uploads/' . $directory . '/' . $filename;
        }
    }
    return false;
}
