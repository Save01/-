<?php
require_once 'config.php';
$pageTitle = 'หน้าหลัก';

$db = getDB();

// ===== ถ้าเป็น staff/admin: ดึงข้อมูล Dashboard =====
if (isStaff()) {
    // สินค้าทั้งหมด
    $stmtProducts = $db->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
    $totalProducts = $stmtProducts->fetchColumn();

    // สินค้าใกล้หมด
    $stmtLow = $db->query("SELECT COUNT(*) FROM products WHERE quantity <= min_quantity AND status = 'active'");
    $lowStockCount = $stmtLow->fetchColumn();

    // คำสั่งซื้อรอดำเนินการ
    $stmtPending = $db->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','confirmed','processing')");
    $pendingOrders = $stmtPending->fetchColumn();

    // ประกันที่ยังใช้งาน
    $stmtWarranty = $db->query("SELECT COUNT(*) FROM warranties WHERE status = 'active'");
    $activeWarranties = $stmtWarranty->fetchColumn();

    // รายรับวันนี้
    $stmtIncomeToday = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM accounting WHERE type = 'income' AND transaction_date = CURDATE()");
    $stmtIncomeToday->execute();
    $todayIncome = $stmtIncomeToday->fetchColumn();

    // รายจ่ายวันนี้
    $stmtExpenseToday = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM accounting WHERE type = 'expense' AND transaction_date = CURDATE()");
    $stmtExpenseToday->execute();
    $todayExpense = $stmtExpenseToday->fetchColumn();

    // คำสั่งซื้อล่าสุด
    $stmtRecentOrders = $db->query("
        SELECT o.*, u.fullname AS customer_name 
        FROM orders o 
        LEFT JOIN users u ON o.customer_id = u.id 
        ORDER BY o.created_at DESC LIMIT 10
    ");
    $recentOrders = $stmtRecentOrders->fetchAll();

    // สินค้าใกล้หมด
    $stmtLowProducts = $db->query("
        SELECT p.*, bb.name AS brand_name 
        FROM products p 
        LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id 
        WHERE p.quantity <= p.min_quantity AND p.status = 'active' 
        ORDER BY p.quantity ASC LIMIT 10
    ");
    $lowStockProducts = $stmtLowProducts->fetchAll();

    // การแจ้งเตือนล่าสุด
    $stmtNotif = $db->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 5");
    $recentNotifications = $stmtNotif->fetchAll();
}

// ===== ถ้าเป็น customer: ดึงข้อมูลของลูกค้า =====
if (isLoggedIn() && !isStaff()) {
    $userId = $_SESSION['user_id'];
    
    // รถของลูกค้า
    $stmtVehicles = $db->prepare("
        SELECT mv.*, cb.name AS brand_name 
        FROM member_vehicles mv 
        LEFT JOIN car_brands cb ON mv.brand_id = cb.id 
        WHERE mv.user_id = ?
    ");
    $stmtVehicles->execute([$userId]);
    $myVehicles = $stmtVehicles->fetchAll();

    // คำสั่งซื้อล่าสุด
    $stmtMyOrders = $db->prepare("SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmtMyOrders->execute([$userId]);
    $myOrders = $stmtMyOrders->fetchAll();

    // ประกันของลูกค้า
    $stmtMyWarranty = $db->prepare("
        SELECT w.*, p.name AS product_name 
        FROM warranties w 
        LEFT JOIN products p ON w.product_id = p.id 
        WHERE w.customer_id = ? 
        ORDER BY w.warranty_end_date ASC
    ");
    $stmtMyWarranty->execute([$userId]);
    $myWarranties = $stmtMyWarranty->fetchAll();
}

require_once 'header.php';
?>

<?php if (!isLoggedIn()): ?>
<!-- ===== หน้าต้อนรับ (ยังไม่ได้เข้าสู่ระบบ) ===== -->
<div class="text-center py-5">
    <div class="mb-4">
        <i class="bi bi-battery-charging" style="font-size: 5rem; color: var(--primary);"></i>
    </div>
    <h1 class="fw-bold mb-3" style="color: var(--dark);"><?= SITE_NAME ?></h1>
    <p class="lead text-muted mb-2">จำหน่ายแบตเตอรี่รถยนต์ รถมอเตอร์ไซค์ ทุกยี่ห้อ</p>
    <p class="text-muted mb-4">พร้อมบริการเปลี่ยนแบตเตอรี่ถึงที่ ประกันศูนย์ เคลมง่าย</p>
    
    <div class="row justify-content-center g-4 mb-5">
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <i class="bi bi-geo-alt-fill fs-2 text-primary"></i>
                <h6 class="mt-2 mb-1">ที่ตั้งร้าน</h6>
                <small class="text-muted">บางพลี สมุทรปราการ</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <i class="bi bi-telephone-fill fs-2 text-success"></i>
                <h6 class="mt-2 mb-1">โทรศัพท์</h6>
                <small class="text-muted">081-234-5678</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <i class="bi bi-clock-fill fs-2 text-warning"></i>
                <h6 class="mt-2 mb-1">เวลาเปิด-ปิด</h6>
                <small class="text-muted">08:00 - 18:00 น.</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <i class="bi bi-shield-check fs-2 text-danger"></i>
                <h6 class="mt-2 mb-1">ประกันสินค้า</h6>
                <small class="text-muted">สูงสุด 24 เดือน</small>
            </div>
        </div>
    </div>
    
    <div class="d-flex justify-content-center gap-3 flex-wrap">
        <a href="<?= SITE_URL ?>login.php" class="btn btn-primary btn-lg px-5">
            <i class="bi bi-box-arrow-in-right me-2"></i>เข้าสู่ระบบ
        </a>
        <a href="<?= SITE_URL ?>register.php" class="btn btn-outline-primary btn-lg px-5">
            <i class="bi bi-person-plus me-2"></i>สมัครสมาชิก
        </a>
    </div>
</div>

<?php elseif (isStaff()): ?>
<!-- ===== Dashboard สำหรับ Staff/Admin ===== -->
<div class="page-header">
    <h4><i class="bi bi-speedometer2 me-2"></i>แดชบอร์ด</h4>
    <small class="text-muted">อัปเดตล่าสุด: <?= date('d/m/Y H:i') ?> น.</small>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-box-seam"></i>
                </div>
                <div>
                    <div class="text-muted small">สินค้าทั้งหมด</div>
                    <h5 class="mb-0 fw-bold"><?= number_format($totalProducts) ?></h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card" style="<?= $lowStockCount > 0 ? 'border-left: 3px solid var(--danger);' : '' ?>">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <div class="text-muted small">สินค้าใกล้หมด</div>
                    <h5 class="mb-0 fw-bold text-danger"><?= number_format($lowStockCount) ?></h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-cart3"></i>
                </div>
                <div>
                    <div class="text-muted small">รอดำเนินการ</div>
                    <h5 class="mb-0 fw-bold"><?= number_format($pendingOrders) ?></h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-shield-check"></i>
                </div>
                <div>
                    <div class="text-muted small">ประกันที่ใช้งาน</div>
                    <h5 class="mb-0 fw-bold"><?= number_format($activeWarranties) ?></h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div>
                    <div class="text-muted small">รายรับวันนี้</div>
                    <h5 class="mb-0 fw-bold text-success"><?= formatMoney($todayIncome) ?></h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                    <i class="bi bi-graph-down-arrow"></i>
                </div>
                <div>
                    <div class="text-muted small">รายจ่ายวันนี้</div>
                    <h5 class="mb-0 fw-bold"><?= formatMoney($todayExpense) ?></h5>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- คำสั่งซื้อล่าสุด -->
    <div class="col-lg-8">
        <div class="table-container">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2"></i>คำสั่งซื้อล่าสุด</h6>
                <a href="<?= SITE_URL ?>manage_orders.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>เลขที่</th>
                            <th>ลูกค้า</th>
                            <th>ยอดรวม</th>
                            <th>สถานะ</th>
                            <th>วันที่</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentOrders)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีคำสั่งซื้อ</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                            <td><?= htmlspecialchars($order['customer_name'] ?? '-') ?></td>
                            <td><?= formatMoney($order['total_amount']) ?></td>
                            <td>
                                <?php
                                $statusMap = [
                                    'pending' => ['รอยืนยัน', 'warning'],
                                    'confirmed' => ['ยืนยันแล้ว', 'info'],
                                    'processing' => ['กำลังดำเนินการ', 'primary'],
                                    'shipped' => ['จัดส่งแล้ว', 'success'],
                                    'delivered' => ['ส่งมอบแล้ว', 'success'],
                                    'cancelled' => ['ยกเลิก', 'danger'],
                                ];
                                $s = $statusMap[$order['status']] ?? ['ไม่ทราบ', 'secondary'];
                                ?>
                                <span class="badge bg-<?= $s[1] ?> badge-status"><?= $s[0] ?></span>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- แถบด้านขวา -->
    <div class="col-lg-4">
        <!-- สินค้าใกล้หมด -->
        <?php if (!empty($lowStockProducts)): ?>
        <div class="low-stock-alert mb-4">
            <h6 class="fw-bold text-danger mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i>สินค้าใกล้หมดสต๊อก</h6>
            <?php foreach ($lowStockProducts as $lp): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-white rounded">
                <div>
                    <small class="fw-semibold"><?= htmlspecialchars($lp['name']) ?></small>
                    <br><small class="text-muted"><?= htmlspecialchars($lp['brand_name'] ?? '') ?></small>
                </div>
                <span class="badge bg-danger"><?= $lp['quantity'] ?> / <?= $lp['min_quantity'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- การแจ้งเตือนล่าสุด -->
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-bell me-2"></i>การแจ้งเตือนล่าสุด</h6>
                <a href="<?= SITE_URL ?>notifications.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <?php if (empty($recentNotifications)): ?>
            <p class="text-muted text-center small">ไม่มีการแจ้งเตือน</p>
            <?php else: ?>
            <?php foreach ($recentNotifications as $notif): ?>
            <a href="<?= SITE_URL ?>notifications.php?read_id=<?= $notif['id'] ?>&redirect=<?= urlencode(!empty($notif['link']) ? SITE_URL . ltrim($notif['link'], '/') : SITE_URL . 'notifications.php') ?>" class="text-decoration-none text-dark d-flex gap-2 mb-2 p-2 rounded border <?= $notif['is_read'] ? 'bg-white' : 'bg-light border-primary' ?>">
                <?php
                $notifIcons = [
                    'low_stock' => 'bi-exclamation-triangle text-warning',
                    'warranty_expire' => 'bi-shield-exclamation text-info',
                    'new_order' => 'bi-cart-check text-success',
                    'claim' => 'bi-wrench text-danger',
                    'general' => 'bi-bell text-primary',
                ];
                $icon = $notifIcons[$notif['type']] ?? 'bi-bell text-primary';
                ?>
                <i class="bi <?= $icon ?> mt-1"></i>
                <div class="flex-grow-1">
                    <small class="fw-semibold <?= !$notif['is_read'] ? 'text-primary' : '' ?>"><?= htmlspecialchars($notif['title']) ?></small>
                    <br><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($notif['message'], 0, 50, '...')) ?> &bull; <?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?></small>
                </div>
                <?php if (!$notif['is_read']): ?>
                    <span class="badge bg-primary align-self-center" style="font-size: 0.65rem;">ใหม่</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ลิงก์ด่วน -->
        <div class="stat-card mt-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-lightning-charge me-2"></i>ลิงก์ด่วน</h6>
            <div class="d-grid gap-2">
                <a href="<?= SITE_URL ?>products.php" class="btn btn-outline-primary btn-sm text-start">
                    <i class="bi bi-box-seam me-2"></i>จัดการสินค้า
                </a>
                <a href="<?= SITE_URL ?>manage_orders.php" class="btn btn-outline-primary btn-sm text-start">
                    <i class="bi bi-list-check me-2"></i>จัดการคำสั่งซื้อ
                </a>
                <a href="<?= SITE_URL ?>receipts.php" class="btn btn-outline-primary btn-sm text-start">
                    <i class="bi bi-receipt me-2"></i>สร้างใบเสร็จ
                </a>
                <a href="<?= SITE_URL ?>accounting.php" class="btn btn-outline-primary btn-sm text-start">
                    <i class="bi bi-calculator me-2"></i>รายรับ-รายจ่าย
                </a>
                <a href="<?= SITE_URL ?>stock.php" class="btn btn-outline-primary btn-sm text-start">
                    <i class="bi bi-clipboard-data me-2"></i>สต๊อกสินค้า
                </a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ===== Dashboard สำหรับ Customer ===== -->
<div class="page-header">
    <h4><i class="bi bi-house-door me-2"></i>ยินดีต้อนรับ, <?= htmlspecialchars($_SESSION['fullname'] ?? '') ?></h4>
</div>

<div class="row g-4">
    <!-- รถของฉัน -->
    <div class="col-lg-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-car-front me-2"></i>รถของฉัน</h6>
                <a href="<?= SITE_URL ?>vehicles.php" class="btn btn-sm btn-outline-primary">จัดการ</a>
            </div>
            <?php if (empty($myVehicles)): ?>
            <p class="text-muted text-center">ยังไม่มีข้อมูลรถ</p>
            <a href="<?= SITE_URL ?>vehicles.php" class="btn btn-primary btn-sm w-100">
                <i class="bi bi-plus-circle me-1"></i>เพิ่มข้อมูลรถ
            </a>
            <?php else: ?>
            <?php foreach ($myVehicles as $v): ?>
            <div class="d-flex align-items-center gap-3 p-2 border rounded mb-2">
                <i class="bi bi-<?= $v['vehicle_type'] === 'motorcycle' ? 'bicycle' : 'car-front' ?> fs-3 text-primary"></i>
                <div>
                    <strong><?= htmlspecialchars($v['brand_name']) ?> <?= htmlspecialchars($v['model']) ?></strong>
                    <br><small class="text-muted">ทะเบียน: <?= htmlspecialchars($v['license_plate']) ?></small>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- คำสั่งซื้อล่าสุด -->
    <div class="col-lg-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-bag me-2"></i>คำสั่งซื้อล่าสุด</h6>
                <a href="<?= SITE_URL ?>orders.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <?php if (empty($myOrders)): ?>
            <p class="text-muted text-center">ยังไม่มีคำสั่งซื้อ</p>
            <a href="<?= SITE_URL ?>orders.php" class="btn btn-primary btn-sm w-100">
                <i class="bi bi-cart-plus me-1"></i>สั่งซื้อสินค้า
            </a>
            <?php else: ?>
            <?php foreach ($myOrders as $o): ?>
            <div class="d-flex justify-content-between align-items-center p-2 border rounded mb-2">
                <div>
                    <strong><?= htmlspecialchars($o['order_number']) ?></strong>
                    <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></small>
                </div>
                <div class="text-end">
                    <div><?= formatMoney($o['total_amount']) ?></div>
                    <?php
                    $statusMap = [
                        'pending' => ['รอยืนยัน', 'warning'],
                        'confirmed' => ['ยืนยันแล้ว', 'info'],
                        'processing' => ['กำลังดำเนินการ', 'primary'],
                        'shipped' => ['จัดส่งแล้ว', 'success'],
                        'delivered' => ['ส่งมอบแล้ว', 'success'],
                        'cancelled' => ['ยกเลิก', 'danger'],
                    ];
                    $s = $statusMap[$o['status']] ?? ['ไม่ทราบ', 'secondary'];
                    ?>
                    <span class="badge bg-<?= $s[1] ?> badge-status"><?= $s[0] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ประกันสินค้า -->
    <div class="col-12">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2"></i>ประกันสินค้าของฉัน</h6>
                <a href="<?= SITE_URL ?>warranty.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <?php if (empty($myWarranties)): ?>
            <p class="text-muted text-center">ยังไม่มีข้อมูลประกัน</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>สินค้า</th>
                            <th>วันที่ซื้อ</th>
                            <th>ประกันหมดอายุ</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myWarranties as $w): ?>
                        <tr>
                            <td><?= htmlspecialchars($w['product_name'] ?? '-') ?></td>
                            <td><?= date('d/m/Y', strtotime($w['purchase_date'])) ?></td>
                            <td>
                                <?php
                                $endDate = strtotime($w['warranty_end_date']);
                                $daysLeft = floor(($endDate - time()) / 86400);
                                ?>
                                <?= date('d/m/Y', $endDate) ?>
                                <?php if ($daysLeft > 0 && $daysLeft <= 30): ?>
                                    <span class="badge bg-warning">เหลือ <?= $daysLeft ?> วัน</span>
                                <?php elseif ($daysLeft <= 0): ?>
                                    <span class="badge bg-danger">หมดอายุ</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $wStatus = [
                                    'active' => ['ใช้งาน', 'success'],
                                    'expired' => ['หมดอายุ', 'secondary'],
                                    'claimed' => ['เคลมแล้ว', 'info'],
                                ];
                                $ws = $wStatus[$w['status']] ?? ['ไม่ทราบ', 'secondary'];
                                ?>
                                <span class="badge bg-<?= $ws[1] ?>"><?= $ws[0] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
