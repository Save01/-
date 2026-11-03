<?php
require_once __DIR__ . '/config.php';
requireLogin();

$pageTitle = 'การแจ้งเตือน';
$db = getDB();
$userId = (int)$_SESSION['user_id'];
$isStaffUser = isStaff();

// จัดการการกระทำ POST หรือ GET
if ((isset($_POST['action']) && $_POST['action'] === 'mark_all_read') || (isset($_GET['action']) && $_GET['action'] === 'read_all')) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL)");
    $stmt->execute([$userId]);
    setFlash('success', 'ทำเครื่องหมายว่าอ่านแล้วทั้งหมดเรียบร้อยแล้ว');
    header('Location: ' . SITE_URL . 'notifications.php');
    exit;
}

// มาร์คอ่านรายตัว
if (isset($_GET['read_id'])) {
    $readId = (int)$_GET['read_id'];
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$readId]);
    if (!empty($_GET['redirect'])) {
        header('Location: ' . $_GET['redirect']);
        exit;
    }
    header('Location: ' . SITE_URL . 'notifications.php');
    exit;
}

// ตรวจสอบสินค้าใกล้หมดเพื่อสร้าง notification ถ้ายังไม่มี (สำหรับ Staff)
if ($isStaffUser) {
    $lowProds = checkLowStock();
    foreach ($lowProds as $lp) {
        $stmtChk = $db->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE type = 'low_stock' AND message LIKE ? AND DATE(created_at) = CURDATE()
        ");
        $stmtChk->execute(['%' . $lp['name'] . '%']);
        if ($stmtChk->fetchColumn() == 0) {
            createNotification(
                $userId,
                'แจ้งเตือน: สินค้าใกล้หมดสต๊อก!',
                "สินค้า {$lp['name']} คงเหลือเพียง {$lp['quantity']} ชิ้น (เกณฑ์เตือน: {$lp['min_quantity']} ชิ้น)",
                'low_stock',
                'stock.php'
            );
        }
    }
}

// ดึงการแจ้งเตือน
$where = $isStaffUser ? "(user_id = ? OR user_id IS NULL)" : "user_id = ?";
$stmt = $db->prepare("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$typeIcons = [
    'low_stock' => ['bi-exclamation-triangle-fill', 'text-danger', 'bg-danger-subtle'],
    'warranty_expire' => ['bi-shield-exclamation', 'text-warning', 'bg-warning-subtle'],
    'new_order' => ['bi-cart-check-fill', 'text-success', 'bg-success-subtle'],
    'claim' => ['bi-tools', 'text-info', 'bg-info-subtle'],
    'general' => ['bi-bell-fill', 'text-primary', 'bg-primary-subtle']
];

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div>
        <h4 class="mb-1"><i class="bi bi-bell-fill text-primary me-2"></i>ศูนย์การแจ้งเตือน</h4>
        <small class="text-muted">แจ้งเตือนสินค้าใกล้หมดอัตโนมัติ สถานะคำสั่งซื้อ อัพเดทการเคลม และการรับประกัน</small>
    </div>
    <form method="POST" action="">
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-check2-all me-1"></i> ทำเครื่องหมายว่าอ่านแล้วทั้งหมด
        </button>
    </form>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
            <h5>ไม่มีการแจ้งเตือนในขณะนี้</h5>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $n): ?>
            <?php
            $iconMeta = $typeIcons[$n['type']] ?? $typeIcons['general'];
            $targetLink = !empty($n['link']) ? SITE_URL . ltrim($n['link'], '/') : '#';
            ?>
            <a href="<?= SITE_URL ?>notifications.php?read_id=<?= $n['id'] ?>&redirect=<?= urlencode($targetLink) ?>" 
               class="list-group-item list-group-item-action p-3 d-flex align-items-start gap-3 <?= !$n['is_read'] ? 'bg-light fw-medium' : '' ?>">
                <div class="rounded-circle p-2 d-flex align-items-center justify-content-center <?= $iconMeta[2] ?> <?= $iconMeta[1] ?>" style="width: 44px; height: 44px;">
                    <i class="bi <?= $iconMeta[0] ?> fs-5"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong class="text-dark"><?= htmlspecialchars($n['title']) ?></strong>
                        <small class="text-muted"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?> น.</small>
                    </div>
                    <p class="mb-1 text-secondary small"><?= htmlspecialchars($n['message']) ?></p>
                </div>
                <?php if (!$n['is_read']): ?>
                <span class="badge bg-primary rounded-pill mt-1">ใหม่</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

