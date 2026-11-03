<?php
require_once 'config.php';
requireStaff();
$pageTitle = 'จัดการคำสั่งซื้อ';

$db = getDB();

$statusLabels = [
    'pending' => ['รอดำเนินการ', 'bg-warning text-dark', 'bi-clock'],
    'confirmed' => ['ยืนยันแล้ว', 'bg-info text-white', 'bi-check-circle'],
    'processing' => ['กำลังจัดเตรียม', 'bg-primary', 'bi-gear'],
    'shipped' => ['จัดส่งแล้ว', 'bg-success', 'bi-truck'],
    'delivered' => ['ส่งมอบแล้ว', 'bg-success', 'bi-check2-all'],
    'cancelled' => ['ยกเลิก', 'bg-danger', 'bi-x-circle'],
];

$statusFlow = [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['processing', 'cancelled'],
    'processing' => ['shipped', 'cancelled'],
    'shipped' => ['delivered'],
    'delivered' => [],
    'cancelled' => [],
];

// ===== อัพเดทสถานะ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $orderId = (int)$_POST['order_id'];
    $newStatus = $_POST['new_status'];
    
    // ตรวจสอบว่า order มีจริง
    $stmtCheck = $db->prepare("SELECT o.*, u.fullname AS customer_name FROM orders o JOIN users u ON o.customer_id = u.id WHERE o.id = ?");
    $stmtCheck->execute([$orderId]);
    $order = $stmtCheck->fetch();
    
    if ($order && in_array($newStatus, $statusFlow[$order['status']] ?? [])) {
        $stmtUpdate = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmtUpdate->execute([$newStatus, $orderId]);
        
        // แจ้งเตือนลูกค้า
        $statusText = $statusLabels[$newStatus][0] ?? $newStatus;
        createNotification(
            $order['customer_id'],
            'อัพเดทคำสั่งซื้อ #' . $order['order_number'],
            'คำสั่งซื้อของคุณได้ถูกอัพเดทเป็น: ' . $statusText,
            'new_order',
            'orders.php'
        );
        
        setFlash('success', 'อัพเดทสถานะคำสั่งซื้อ #' . $order['order_number'] . ' เป็น "' . $statusText . '" สำเร็จ');
    } else {
        setFlash('danger', 'ไม่สามารถอัพเดทสถานะได้');
    }
    header('Location: manage_orders.php' . (isset($_POST['view']) ? '?view=' . $orderId : ''));
    exit;
}

// ===== ดูรายละเอียดคำสั่งซื้อ =====
$order = null;
$items = [];
if (isset($_GET['view'])) {
    $orderId = (int)$_GET['view'];
    $stmtOrder = $db->prepare("
        SELECT o.*, u.fullname AS customer_name, u.phone AS customer_phone, u.email AS customer_email, u.address AS customer_address,
            mv.license_plate, mv.model AS vehicle_model, mv.year AS vehicle_year, mv.color AS vehicle_color,
            cb.name AS vehicle_brand
        FROM orders o
        JOIN users u ON o.customer_id = u.id
        LEFT JOIN member_vehicles mv ON o.vehicle_id = mv.id
        LEFT JOIN car_brands cb ON mv.brand_id = cb.id
        WHERE o.id = ?
    ");
    $stmtOrder->execute([$orderId]);
    $order = $stmtOrder->fetch();
    
    if (!$order) {
        setFlash('danger', 'ไม่พบคำสั่งซื้อ');
        header('Location: manage_orders.php');
        exit;
    }
    
    // ดึงรายการสินค้า
    $stmtItems = $db->prepare("
        SELECT oi.*, p.name AS product_name, p.sku, p.image AS product_image, bb.name AS battery_brand
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll();
    
    $st = $statusLabels[$order['status']] ?? ['ไม่ทราบ', 'bg-secondary', 'bi-question'];
    $nextStatuses = $statusFlow[$order['status']] ?? [];
    
    require_once 'header.php';
    ?>
    
    <div class="page-header">
        <h4><i class="bi bi-receipt"></i> รายละเอียดคำสั่งซื้อ #<?= htmlspecialchars($order['order_number']) ?></h4>
        <a href="manage_orders.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> กลับ
        </a>
    </div>
    
    <div class="row g-4">
        <!-- ข้อมูลคำสั่งซื้อ -->
        <div class="col-lg-8">
            <!-- สถานะ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h6 class="mb-1">สถานะ</h6>
                            <span class="badge <?= $st[1] ?> badge-status fs-6">
                                <i class="bi <?= $st[2] ?>"></i> <?= $st[0] ?>
                            </span>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <a href="<?= SITE_URL ?>receipts.php" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-receipt"></i> ออกใบเสร็จรับเงิน
                            </a>
                            <?php if (!empty($nextStatuses)): ?>
                            <?php foreach ($nextStatuses as $ns): ?>
                            <?php $nsl = $statusLabels[$ns] ?? ['', '']; ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันเปลี่ยนสถานะเป็น: <?= $nsl[0] ?>')">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $ns ?>">
                                <input type="hidden" name="view" value="1">
                                <button type="submit" class="btn btn-sm <?= $ns === 'cancelled' ? 'btn-outline-danger' : 'btn-primary' ?>">
                                    <i class="bi <?= $nsl[2] ?>"></i> <?= $nsl[0] ?>
                                </button>
                            </form>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- รายการสินค้า -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-box-seam"></i> รายการสินค้า</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>สินค้า</th>
                                <th class="text-center">จำนวน</th>
                                <th class="text-end">ราคา/ชิ้น</th>
                                <th class="text-end">รวม</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($item['product_image']): ?>
                                        <img src="<?= SITE_URL . htmlspecialchars($item['product_image']) ?>" width="40" height="40" class="rounded" style="object-fit:cover;">
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($item['battery_brand'] ?? '') ?> | SKU: <?= htmlspecialchars($item['sku'] ?? '-') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center"><?= $item['quantity'] ?></td>
                                <td class="text-end"><?= formatMoney($item['price']) ?></td>
                                <td class="text-end"><strong><?= formatMoney($item['price'] * $item['quantity']) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="3" class="text-end"><strong>ยอดรวมทั้งหมด</strong></td>
                                <td class="text-end"><strong class="text-primary fs-5"><?= formatMoney($order['total_amount']) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <!-- ที่อยู่จัดส่ง -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-geo-alt"></i> ที่อยู่จัดส่ง</h6>
                </div>
                <div class="card-body">
                    <p><?= nl2br(htmlspecialchars($order['delivery_address'] ?? 'ไม่ระบุ')) ?></p>
                    <?php if ($order['delivery_lat'] && $order['delivery_lng']): ?>
                    <a href="https://www.google.com/maps?q=<?= $order['delivery_lat'] ?>,<?= $order['delivery_lng'] ?>" target="_blank" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-pin-map-fill"></i> เปิด Google Maps (<?= round($order['delivery_lat'], 6) ?>, <?= round($order['delivery_lng'], 6) ?>)
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- รูปแบตเก่า -->
            <?php if ($order['old_battery_image']): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-camera"></i> รูปแบตเตอรี่เก่า</h6>
                </div>
                <div class="card-body text-center">
                    <img src="<?= SITE_URL . htmlspecialchars($order['old_battery_image']) ?>" class="img-fluid rounded" style="max-height: 400px;">
                </div>
            </div>
            <?php endif; ?>
            
            <!-- หมายเหตุ -->
            <?php if ($order['notes']): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-chat-dots"></i> หมายเหตุ</h6>
                </div>
                <div class="card-body">
                    <p><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ข้อมูลลูกค้าและรถ -->
        <div class="col-lg-4">
            <!-- ข้อมูลลูกค้า -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-person"></i> ข้อมูลลูกค้า</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
                    <p class="mb-1"><i class="bi bi-telephone"></i> <?= htmlspecialchars($order['customer_phone']) ?></p>
                    <?php if ($order['customer_email']): ?>
                    <p class="mb-1"><i class="bi bi-envelope"></i> <?= htmlspecialchars($order['customer_email']) ?></p>
                    <?php endif; ?>
                    <?php if ($order['customer_address']): ?>
                    <p class="mb-0"><i class="bi bi-house"></i> <?= htmlspecialchars($order['customer_address']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ข้อมูลรถ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-car-front"></i> ข้อมูลรถ</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= htmlspecialchars(($order['vehicle_brand'] ?? '') . ' ' . ($order['vehicle_model'] ?? '')) ?></strong></p>
                    <p class="mb-1"><i class="bi bi-credit-card"></i> ทะเบียน: <?= htmlspecialchars($order['license_plate'] ?? '-') ?></p>
                    <?php if ($order['vehicle_year']): ?>
                    <p class="mb-1"><i class="bi bi-calendar"></i> ปี: <?= htmlspecialchars($order['vehicle_year']) ?></p>
                    <?php endif; ?>
                    <?php if ($order['vehicle_color']): ?>
                    <p class="mb-0"><i class="bi bi-palette"></i> สี: <?= htmlspecialchars($order['vehicle_color']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- เวลา -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-clock"></i> เวลา</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><small class="text-muted">สร้างเมื่อ:</small><br><?= date('d/m/Y H:i:s', strtotime($order['created_at'])) ?></p>
                    <p class="mb-0"><small class="text-muted">อัพเดทล่าสุด:</small><br><?= date('d/m/Y H:i:s', strtotime($order['updated_at'])) ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    // End detail view
} else {
    // ===== รายการคำสั่งซื้อทั้งหมด =====
    
    // Filters
    $filterStatus = $_GET['status'] ?? '';
    $filterDateFrom = $_GET['date_from'] ?? '';
    $filterDateTo = $_GET['date_to'] ?? '';
    $filterCustomer = trim($_GET['customer'] ?? '');
    
    $where = [];
    $params = [];
    
    if ($filterStatus) {
        $where[] = "o.status = ?";
        $params[] = $filterStatus;
    }
    if ($filterDateFrom) {
        $where[] = "DATE(o.created_at) >= ?";
        $params[] = $filterDateFrom;
    }
    if ($filterDateTo) {
        $where[] = "DATE(o.created_at) <= ?";
        $params[] = $filterDateTo;
    }
    if ($filterCustomer) {
        $where[] = "(u.fullname LIKE ? OR o.order_number LIKE ? OR u.phone LIKE ?)";
        $params[] = "%{$filterCustomer}%";
        $params[] = "%{$filterCustomer}%";
        $params[] = "%{$filterCustomer}%";
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $stmtOrders = $db->prepare("
        SELECT o.*, u.fullname AS customer_name, u.phone AS customer_phone,
            mv.license_plate, mv.model AS vehicle_model, cb.name AS vehicle_brand,
            (SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR ', ')
             FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id) AS items_summary
        FROM orders o
        JOIN users u ON o.customer_id = u.id
        LEFT JOIN member_vehicles mv ON o.vehicle_id = mv.id
        LEFT JOIN car_brands cb ON mv.brand_id = cb.id
        {$whereClause}
        ORDER BY o.created_at DESC
    ");
    $stmtOrders->execute($params);
    $orders = $stmtOrders->fetchAll();
    
    // นับสถิติ
    $stmtStats = $db->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
            SUM(total_amount) AS total_revenue
        FROM orders
    ");
    $stats = $stmtStats->fetch();
    
    require_once 'header.php';
    ?>
    
    <div class="page-header">
        <h4><i class="bi bi-list-check"></i> จัดการคำสั่งซื้อ</h4>
    </div>
    
    <!-- สถิติ -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-cart3"></i></div>
                    <div>
                        <small class="text-muted">ทั้งหมด</small>
                        <h5 class="mb-0"><?= number_format($stats['total']) ?></h5>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-clock"></i></div>
                    <div>
                        <small class="text-muted">รอดำเนินการ</small>
                        <h5 class="mb-0"><?= number_format($stats['pending_count']) ?></h5>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-gear"></i></div>
                    <div>
                        <small class="text-muted">กำลังจัดเตรียม</small>
                        <h5 class="mb-0"><?= number_format($stats['processing_count']) ?></h5>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-check2-all"></i></div>
                    <div>
                        <small class="text-muted">ส่งมอบแล้ว</small>
                        <h5 class="mb-0"><?= number_format($stats['delivered_count']) ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ตัวกรอง -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">สถานะ</label>
                    <select name="status" class="form-select">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($statusLabels as $key => $val): ?>
                        <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= $val[0] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">จากวันที่</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filterDateFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">ถึงวันที่</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filterDateTo) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">ค้นหาลูกค้า / เลขที่</label>
                    <input type="text" name="customer" class="form-control" value="<?= htmlspecialchars($filterCustomer) ?>" placeholder="ชื่อ, เลขที่, เบอร์โทร...">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> ค้นหา</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- รายการคำสั่งซื้อ -->
    <div class="table-container">
        <?php if (empty($orders)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox display-4 text-muted"></i>
            <p class="mt-2 text-muted">ไม่พบคำสั่งซื้อ</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>เลขที่</th>
                        <th>ลูกค้า</th>
                        <th>รถ</th>
                        <th>สินค้า</th>
                        <th class="text-end">ยอดรวม</th>
                        <th class="text-center">สถานะ</th>
                        <th>วันที่</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                    <?php $ost = $statusLabels[$o['status']] ?? ['ไม่ทราบ', 'bg-secondary', '']; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                        <td>
                            <?= htmlspecialchars($o['customer_name']) ?><br>
                            <small class="text-muted"><?= htmlspecialchars($o['customer_phone']) ?></small>
                        </td>
                        <td>
                            <small><?= htmlspecialchars(($o['vehicle_brand'] ?? '') . ' ' . ($o['vehicle_model'] ?? '')) ?></small><br>
                            <small class="text-muted"><?= htmlspecialchars($o['license_plate'] ?? '') ?></small>
                        </td>
                        <td><small><?= htmlspecialchars($o['items_summary'] ?? '-') ?></small></td>
                        <td class="text-end"><?= formatMoney($o['total_amount']) ?></td>
                        <td class="text-center">
                            <span class="badge <?= $ost[1] ?> badge-status"><?= $ost[0] ?></span>
                        </td>
                        <td><small><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></small></td>
                        <td class="text-center">
                            <a href="manage_orders.php?view=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i> ดู
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <?php
}
?>

<?php require_once 'footer.php'; ?>
