<?php
require_once __DIR__ . '/config.php';
requireStaff();

$pageTitle = 'ระบบสต๊อกสินค้า';
$db = getDB();

// ===== จัดการ POST requests =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. รับสินค้าเข้าสต๊อก (Stock In)
    if ($action === 'stock_in') {
        $productId = (int)$_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        $costPerUnit = (float)($_POST['cost_per_unit'] ?? 0);
        $supplier = trim($_POST['supplier'] ?? '');
        $receivedDate = !empty($_POST['received_date']) ? $_POST['received_date'] : date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $recordAccounting = isset($_POST['record_accounting']);

        if ($productId <= 0 || $quantity <= 0) {
            setFlash('danger', 'กรุณาเลือกสินค้าและระบุจำนวนที่รับเข้าให้ถูกต้อง');
        } else {
            try {
                $db->beginTransaction();

                // บันทึกลง stock_in
                $stmtIn = $db->prepare("
                    INSERT INTO stock_in (product_id, quantity, cost_per_unit, supplier, received_date, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIn->execute([$productId, $quantity, $costPerUnit, $supplier ?: null, $receivedDate, $notes ?: null, $_SESSION['user_id']]);
                $stockInId = $db->lastInsertId();

                // อัพเดทยอดคงเหลือในตาราง products
                $stmtUp = $db->prepare("UPDATE products SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?");
                $stmtUp->execute([$quantity, $productId]);

                // ดึงชื่อสินค้า
                $stmtP = $db->prepare("SELECT name FROM products WHERE id = ?");
                $stmtP->execute([$productId]);
                $pName = $stmtP->fetchColumn();

                // บันทึกลงบัญชีรายจ่าย (ถ้าเลือก)
                if ($recordAccounting && $costPerUnit > 0) {
                    $totalCost = $costPerUnit * $quantity;
                    $stmtAcc = $db->prepare("
                        INSERT INTO accounting (type, category, amount, description, reference_id, reference_type, transaction_date, created_by)
                        VALUES ('expense', 'ซื้อสต๊อกแบตเตอรี่', ?, ?, ?, 'stock_in', ?, ?)
                    ");
                    $stmtAcc->execute([$totalCost, "รับเข้าสต๊อก: {$pName} จำนวน {$quantity} ลูก (ผู้ส่ง: {$supplier})", $stockInId, $receivedDate, $_SESSION['user_id']]);
                }

                $db->commit();
                setFlash('success', 'บันทึกการรับเข้าสินค้า "' . htmlspecialchars($pName) . '" จำนวน ' . $quantity . ' ชิ้น เรียบร้อยแล้ว');
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('danger', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            }
        }
        header('Location: ' . SITE_URL . 'stock.php');
        exit;
    }

    // 2. บันทึกการขายสินค้า / ตัดสต๊อก (Stock Out)
    if ($action === 'stock_out') {
        $productId = (int)$_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        $salePrice = (float)($_POST['sale_price'] ?? 0);
        $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $customerName = trim($_POST['customer_name'] ?? '');
        $soldDate = !empty($_POST['sold_date']) ? $_POST['sold_date'] : date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $recordAccounting = isset($_POST['record_accounting']);

        // ตรวจสอบสต๊อกคงเหลือก่อนตัด
        $stmtChk = $db->prepare("SELECT name, quantity, price FROM products WHERE id = ?");
        $stmtChk->execute([$productId]);
        $prod = $stmtChk->fetch();

        if (!$prod) {
            setFlash('danger', 'ไม่พบสินค้าที่เลือก');
        } elseif ($quantity <= 0) {
            setFlash('danger', 'กรุณาระบุจำนวนที่ขายให้ถูกต้อง');
        } elseif ($prod['quantity'] < $quantity) {
            setFlash('danger', 'สต๊อกสินค้าคงเหลือไม่เพียงพอ (คงเหลือ ' . $prod['quantity'] . ' ชิ้น)');
        } else {
            try {
                $db->beginTransaction();

                if ($salePrice <= 0) {
                    $salePrice = $prod['price'];
                }

                $fullNote = $notes;
                if (!empty($customerName)) {
                    $fullNote = "ลูกค้า: {$customerName} " . ($fullNote ? "({$fullNote})" : "");
                }

                // บันทึกลง stock_out
                $stmtOut = $db->prepare("
                    INSERT INTO stock_out (product_id, quantity, sale_price, customer_id, sold_date, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtOut->execute([$productId, $quantity, $salePrice, $customerId, $soldDate, $fullNote ?: null, $_SESSION['user_id']]);
                $stockOutId = $db->lastInsertId();

                // ตัดสต๊อก
                $stmtUp = $db->prepare("UPDATE products SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?");
                $stmtUp->execute([$quantity, $productId]);

                // บันทึกลงบัญชีรายรับ (ถ้าเลือก)
                if ($recordAccounting && $salePrice > 0) {
                    $totalIncome = $salePrice * $quantity;
                    $stmtAcc = $db->prepare("
                        INSERT INTO accounting (type, category, amount, description, reference_id, reference_type, transaction_date, created_by)
                        VALUES ('income', 'ขายสินค้าหน้าร้าน', ?, ?, ?, 'stock_out', ?, ?)
                    ");
                    $stmtAcc->execute([$totalIncome, "ขายแบตเตอรี่: {$prod['name']} จำนวน {$quantity} ลูก {$fullNote}", $stockOutId, $soldDate, $_SESSION['user_id']]);
                }

                $db->commit();
                setFlash('success', 'บันทึกการขายสินค้า "' . htmlspecialchars($prod['name']) . '" จำนวน ' . $quantity . ' ชิ้น เรียบร้อยแล้ว');
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('danger', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            }
        }
        header('Location: ' . SITE_URL . 'stock.php?tab=stock_out');
        exit;
    }
}

// สินค้าใกล้หมด (Low stock alerts)
$lowStockItems = checkLowStock();

// ตัวกรองสินค้า
$search = trim($_GET['search'] ?? '');
$stockStatus = $_GET['status'] ?? '';
$activeTab = $_GET['tab'] ?? 'inventory';

$where = ["p.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(p.name LIKE ? OR p.sku LIKE ? OR bb.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($stockStatus === 'low') {
    $where[] = "p.quantity <= p.min_quantity AND p.quantity > 0";
} elseif ($stockStatus === 'out') {
    $where[] = "p.quantity <= 0";
} elseif ($stockStatus === 'normal') {
    $where[] = "p.quantity > p.min_quantity";
}

$whereClause = implode(' AND ', $where);

// ดึงรายการสต๊อกปัจจุบัน
$stmtProducts = $db->prepare("
    SELECT p.*, bb.name as brand_name, c.name as category_name 
    FROM products p 
    LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE {$whereClause} 
    ORDER BY p.quantity ASC, p.name ASC
");
$stmtProducts->execute($params);
$productList = $stmtProducts->fetchAll();

// ดึงประวัติการรับเข้า (Stock In Logs)
$stmtInLogs = $db->query("
    SELECT si.*, p.name as product_name, p.sku, u.fullname as creator_name 
    FROM stock_in si 
    LEFT JOIN products p ON si.product_id = p.id 
    LEFT JOIN users u ON si.created_by = u.id 
    ORDER BY si.received_date DESC, si.id DESC 
    LIMIT 100
");
$stockInLogs = $stmtInLogs->fetchAll();

// ดึงประวัติการขายออก (Stock Out Logs)
$stmtOutLogs = $db->query("
    SELECT so.*, p.name as product_name, p.sku, u.fullname as customer_name, staff.fullname as creator_name 
    FROM stock_out so 
    LEFT JOIN products p ON so.product_id = p.id 
    LEFT JOIN users u ON so.customer_id = u.id 
    LEFT JOIN users staff ON so.created_by = staff.id 
    ORDER BY so.sold_date DESC, so.id DESC 
    LIMIT 100
");
$stockOutLogs = $stmtOutLogs->fetchAll();

// สมาชิกสำหรับ dropdown ขายออก
$customers = $db->query("SELECT id, fullname, phone FROM users WHERE role = 'customer' ORDER BY fullname ASC")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div>
        <h4 class="mb-1"><i class="bi bi-boxes text-primary me-2"></i>ระบบสต๊อกสินค้า</h4>
        <small class="text-muted">บันทึกรับเข้า-ขายออก ตรวจสอบประวัติสินค้า และระบบแจ้งเตือนสินค้าใกล้หมดอัตโนมัติ</small>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#stockInModal">
            <i class="bi bi-box-arrow-in-down me-1"></i> รับสินค้าเข้า (+สต๊อก)
        </button>
        <button type="button" class="btn btn-warning text-dark" data-bs-toggle="modal" data-bs-target="#stockOutModal">
            <i class="bi bi-box-arrow-up me-1"></i> บันทึกขายออก (-ตัดสต๊อก)
        </button>
    </div>
</div>

<!-- ส่วนแจ้งเตือนสินค้าใกล้หมดอัตโนมัติ (Automated Low Stock Alert) -->
<?php if (!empty($lowStockItems)): ?>
<div class="card border-danger shadow-sm mb-4">
    <div class="card-header bg-danger text-white py-2 d-flex justify-content-between align-items-center">
        <div class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>การแจ้งเตือนสินค้าใกล้หมดอัตโนมัติ (พบ <?= count($lowStockItems) ?> รายการ)</div>
        <span class="badge bg-white text-danger">ต้องสั่งซื้อเพิ่มทันที</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ชื่อสินค้า</th>
                        <th>ยี่ห้อ</th>
                        <th class="text-center">คงเหลือ</th>
                        <th class="text-center">เกณฑ์เตือน</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-end">การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStockItems as $low): ?>
                    <tr class="<?= $low['quantity'] <= 0 ? 'table-danger' : 'table-warning' ?>">
                        <td class="fw-bold"><?= htmlspecialchars($low['name']) ?></td>
                        <td><?= htmlspecialchars($low['brand_name'] ?? '-') ?></td>
                        <td class="text-center">
                            <span class="badge <?= $low['quantity'] <= 0 ? 'bg-danger' : 'bg-warning text-dark' ?> fs-6 px-3">
                                <?= $low['quantity'] ?> ลูก
                            </span>
                        </td>
                        <td class="text-center text-muted"><?= $low['min_quantity'] ?> ลูก</td>
                        <td class="text-center">
                            <?php if ($low['quantity'] <= 0): ?>
                                <span class="badge bg-danger">สินค้าหมดสต๊อก</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">ใกล้หมด (เหลือน้อยกว่าเกณฑ์)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-success" onclick="quickRestock(<?= $low['id'] ?>, '<?= htmlspecialchars(addslashes($low['name'])) ?>', <?= (float)$low['cost_price'] ?>)">
                                <i class="bi bi-plus-lg me-1"></i> รับเข้าสต๊อกด่วน
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- แท็บการทำงาน -->
<ul class="nav nav-pills mb-3 gap-2" id="stockTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'inventory' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-inventory">
            <i class="bi bi-clipboard2-data me-1"></i> สต๊อกสินค้าคงเหลือปัจจุบัน
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'stock_in' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-stockin">
            <i class="bi bi-box-arrow-in-down me-1"></i> ประวัติการรับสินค้าเข้า (Stock In)
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $activeTab === 'stock_out' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-stockout">
            <i class="bi bi-box-arrow-up me-1"></i> ประวัติการขายสินค้า (Stock Out)
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- แท็บ 1: สต๊อกสินค้าคงเหลือ -->
    <div class="tab-pane fade <?= $activeTab === 'inventory' ? 'show active' : '' ?>" id="tab-inventory">
        <!-- ช่องค้นหาและตัวกรอง -->
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-body py-2">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" value="inventory">
                    <div class="col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control border-start-0" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อสินค้า, SKU, ยี่ห้อ...">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <select name="status" class="form-select">
                            <option value="">ทุกสถานะสต๊อก</option>
                            <option value="low" <?= $stockStatus === 'low' ? 'selected' : '' ?>>ใกล้หมด (ต้องสั่งเพิ่ม)</option>
                            <option value="out" <?= $stockStatus === 'out' ? 'selected' : '' ?>>สินค้าหมด (0 ลูก)</option>
                            <option value="normal" <?= $stockStatus === 'normal' ? 'selected' : '' ?>>สต๊อกปกติ</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-secondary w-100"><i class="bi bi-funnel me-1"></i> ค้นหา</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="table-container shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>สินค้า / SKU</th>
                            <th>หมวดหมู่</th>
                            <th>ยี่ห้อแบตเตอรี่</th>
                            <th>ราคาต้นทุน</th>
                            <th>ราคาขาย</th>
                            <th class="text-center">สต๊อกคงเหลือ</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productList)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">ไม่พบข้อมูลสินค้าตามที่ค้นหา</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($productList as $p): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($p['name']) ?></div>
                                <small class="text-muted">SKU: <?= htmlspecialchars($p['sku'] ?? '-') ?> | ประกัน: <?= $p['warranty_months'] ?> เดือน</small>
                            </td>
                            <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['brand_name'] ?? '-') ?></span></td>
                            <td><?= number_format($p['cost_price'], 2) ?> ฿</td>
                            <td class="fw-bold text-success"><?= number_format($p['price'], 2) ?> ฿</td>
                            <td class="text-center">
                                <span class="badge <?= $p['quantity'] <= 0 ? 'bg-danger' : ($p['quantity'] <= $p['min_quantity'] ? 'bg-warning text-dark' : 'bg-success') ?> fs-6 px-3">
                                    <?= $p['quantity'] ?> ลูก
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($p['quantity'] <= 0): ?>
                                    <span class="badge bg-danger rounded-pill">หมด</span>
                                <?php elseif ($p['quantity'] <= $p['min_quantity']): ?>
                                    <span class="badge bg-warning text-dark rounded-pill">ใกล้หมด</span>
                                <?php else: ?>
                                    <span class="badge bg-success rounded-pill">พร้อมจำหน่าย</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-success" title="รับเข้าสต๊อก" onclick="quickRestock(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', <?= (float)$p['cost_price'] ?>)">
                                        <i class="bi bi-plus-lg"></i> รับเข้า
                                    </button>
                                    <button type="button" class="btn btn-outline-warning" title="ตัดสต๊อกขาย" onclick="quickSell(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', <?= (float)$p['price'] ?>, <?= $p['quantity'] ?>)">
                                        <i class="bi bi-dash-lg"></i> ขายออก
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- แท็บ 2: ประวัติการรับเข้า (Stock In) -->
    <div class="tab-pane fade <?= $activeTab === 'stock_in' ? 'show active' : '' ?>" id="tab-stockin">
        <div class="table-container shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>วันที่รับสินค้า</th>
                            <th>สินค้า</th>
                            <th>จำนวนรับเข้า</th>
                            <th>ราคาต้นทุน/ชิ้น</th>
                            <th>ยอดรวม</th>
                            <th>ผู้จัดจำหน่าย / แหล่งที่มา</th>
                            <th>หมายเหตุ</th>
                            <th>ผู้บันทึก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stockInLogs)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">ยังไม่มีประวัติการรับสินค้าเข้า</td></tr>
                        <?php else: ?>
                        <?php foreach ($stockInLogs as $in): ?>
                        <tr>
                            <td>
                                <strong><?= date('d/m/Y', strtotime($in['received_date'])) ?></strong>
                                <small class="d-block text-muted"><?= date('H:i', strtotime($in['created_at'])) ?> น.</small>
                            </td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($in['product_name']) ?></td>
                            <td><span class="badge bg-success fs-6">+<?= $in['quantity'] ?> ลูก</span></td>
                            <td><?= number_format($in['cost_per_unit'], 2) ?> ฿</td>
                            <td class="fw-bold"><?= number_format($in['quantity'] * $in['cost_per_unit'], 2) ?> ฿</td>
                            <td><i class="bi bi-truck me-1"></i><?= htmlspecialchars($in['supplier'] ?? 'ไม่ระบุ') ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($in['notes'] ?? '-') ?></small></td>
                            <td><small class="text-secondary"><?= htmlspecialchars($in['creator_name'] ?? 'ระบบ') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- แท็บ 3: ประวัติการขายออก (Stock Out) -->
    <div class="tab-pane fade <?= $activeTab === 'stock_out' ? 'show active' : '' ?>" id="tab-stockout">
        <div class="table-container shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>วันที่ขายสินค้า</th>
                            <th>สินค้า</th>
                            <th>จำนวนขาย</th>
                            <th>ราคาขาย/ชิ้น</th>
                            <th>ยอดรวม</th>
                            <th>ลูกค้า / รายละเอียด</th>
                            <th>ผู้บันทึก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stockOutLogs)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">ยังไม่มีประวัติการขายสินค้าออก</td></tr>
                        <?php else: ?>
                        <?php foreach ($stockOutLogs as $out): ?>
                        <tr>
                            <td>
                                <strong><?= date('d/m/Y', strtotime($out['sold_date'])) ?></strong>
                                <small class="d-block text-muted"><?= date('H:i', strtotime($out['created_at'])) ?> น.</small>
                            </td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($out['product_name']) ?></td>
                            <td><span class="badge bg-warning text-dark fs-6">-<?= $out['quantity'] ?> ลูก</span></td>
                            <td><?= number_format($out['sale_price'], 2) ?> ฿</td>
                            <td class="fw-bold text-success"><?= number_format($out['quantity'] * $out['sale_price'], 2) ?> ฿</td>
                            <td>
                                <div><i class="bi bi-person me-1"></i><?= htmlspecialchars($out['customer_name'] ?: 'ลูกค้าหน้าร้าน') ?></div>
                                <?php if ($out['notes']): ?><small class="text-muted"><?= htmlspecialchars($out['notes']) ?></small><?php endif; ?>
                            </td>
                            <td><small class="text-secondary"><?= htmlspecialchars($out['creator_name'] ?? 'ระบบ') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: บันทึกรับเข้าสินค้า (Stock In) -->
<div class="modal fade" id="stockInModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="stock_in">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-down me-2"></i>บันทึกรับสินค้าเข้าสต๊อก</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เลือกสินค้า <span class="text-danger">*</span></label>
                        <select name="product_id" id="in_product_id" class="form-select" required>
                            <option value="">-- กรุณาเลือกสินค้า --</option>
                            <?php foreach ($productList as $p): ?>
                            <option value="<?= $p['id'] ?>" data-cost="<?= $p['cost_price'] ?>"><?= htmlspecialchars($p['name']) ?> (คงเหลือ: <?= $p['quantity'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">จำนวนที่รับเข้า (ลูก) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="quantity" min="1" value="1" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">ราคาต้นทุนต่อลูก (บาท)</label>
                            <input type="number" step="0.01" class="form-control" name="cost_per_unit" id="in_cost_per_unit" placeholder="0.00">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">วันที่รับสินค้า <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="received_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">ผู้จัดจำหน่าย / ซัพพลายเออร์</label>
                            <input type="text" class="form-control" name="supplier" placeholder="เช่น บจก. 3K แบตเตอรี่">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุเพิ่มเติม</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="เลขล็อตการผลิต, สถานที่เก็บ, ฯลฯ"></textarea>
                    </div>
                    <div class="form-check p-3 bg-light rounded border">
                        <input class="form-check-input" type="checkbox" name="record_accounting" id="in_record_accounting" value="1" checked>
                        <label class="form-check-label fw-bold" for="in_record_accounting">
                            บันทึกเป็นรายจ่ายในระบบบัญชีอัตโนมัติ
                        </label>
                        <div class="small text-muted">ระบบจะคำนวณ ต้นทุน x จำนวน และลงเป็นรายจ่ายในหมวดซื้อสต๊อก</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>บันทึกรับเข้า</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: บันทึกการขายสินค้า (Stock Out) -->
<div class="modal fade" id="stockOutModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="stock_out">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-up me-2"></i>บันทึกการขายสินค้า / ตัดสต๊อก</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เลือกสินค้าที่จะขาย <span class="text-danger">*</span></label>
                        <select name="product_id" id="out_product_id" class="form-select" required>
                            <option value="">-- กรุณาเลือกสินค้า --</option>
                            <?php foreach ($productList as $p): ?>
                            <option value="<?= $p['id'] ?>" data-price="<?= $p['price'] ?>" data-stock="<?= $p['quantity'] ?>" <?= $p['quantity'] <= 0 ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?> (คงเหลือ: <?= $p['quantity'] ?> ลูก)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">จำนวนที่ขาย (ลูก) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="quantity" id="out_quantity" min="1" value="1" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">ราคาขายต่อลูก (บาท)</label>
                            <input type="number" step="0.01" class="form-control" name="sale_price" id="out_sale_price" placeholder="0.00">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">วันที่ขายสินค้า <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="sold_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">สมาชิกลูกค้า (ถ้ามี)</label>
                            <select name="customer_id" class="form-select">
                                <option value="">-- ลูกค้าทั่วไป / ไม่ได้เป็นสมาชิก --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['fullname']) ?> (<?= $c['phone'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อลูกค้า / ทะเบียนรถ (กรณีลูกค้าหน้าร้าน)</label>
                        <input type="text" class="form-control" name="customer_name" placeholder="เช่น คุณสมศักดิ์ / ทะเบียน 2กบ 4567">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุเพิ่มเติม</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="รายละเอียดการเปลี่ยน, เทิร์นแบตเก่า, ฯลฯ"></textarea>
                    </div>
                    <div class="form-check p-3 bg-light rounded border">
                        <input class="form-check-input" type="checkbox" name="record_accounting" id="out_record_accounting" value="1" checked>
                        <label class="form-check-label fw-bold" for="out_record_accounting">
                            บันทึกเป็นรายรับในระบบบัญชีอัตโนมัติ
                        </label>
                        <div class="small text-muted">ระบบจะคำนวณ ราคาขาย x จำนวน และลงเป็นรายรับขายสินค้า</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-lg me-1"></i>บันทึกการขาย</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function quickRestock(productId, productName, costPrice) {
    document.getElementById('in_product_id').value = productId;
    document.getElementById('in_cost_per_unit').value = costPrice || '';
    new bootstrap.Modal(document.getElementById('stockInModal')).show();
}

function quickSell(productId, productName, salePrice, stock) {
    if (stock <= 0) {
        alert('สินค้านี้หมดสต๊อก ไม่สามารถขายได้');
        return;
    }
    const select = document.getElementById('out_product_id');
    select.value = productId;
    document.getElementById('out_sale_price').value = salePrice || '';
    document.getElementById('out_quantity').max = stock;
    new bootstrap.Modal(document.getElementById('stockOutModal')).show();
}

document.getElementById('in_product_id').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const cost = selected.getAttribute('data-cost');
    if (cost) {
        document.getElementById('in_cost_per_unit').value = cost;
    }
});

document.getElementById('out_product_id').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const price = selected.getAttribute('data-price');
    const stock = selected.getAttribute('data-stock');
    if (price) {
        document.getElementById('out_sale_price').value = price;
    }
    if (stock) {
        document.getElementById('out_quantity').max = stock;
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

