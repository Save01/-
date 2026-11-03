<?php
require_once __DIR__ . '/config.php';
requireLogin();

$pageTitle = 'ระบบใบเสร็จรับเงิน';
$db = getDB();
$isStaffUser = isStaff();
$currentUserId = (int)$_SESSION['user_id'];

// ===== สร้างใบเสร็จใหม่ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_receipt') {
    requireStaff();

    $receiptNumber = generateReceiptNumber();
    $orderId = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;
    $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $vehiclePlate = trim($_POST['vehicle_plate'] ?? '');
    $paymentMethod = in_array($_POST['payment_method'] ?? '', ['cash', 'transfer', 'credit_card']) ? $_POST['payment_method'] : 'cash';
    $notes = trim($_POST['notes'] ?? '');

    $itemNames = $_POST['item_name'] ?? [];
    $itemQtys = $_POST['item_qty'] ?? [];
    $itemPrices = $_POST['item_price'] ?? [];

    $subtotal = 0;
    $itemsDetailArray = [];

    for ($i = 0; $i < count($itemNames); $i++) {
        $name = trim($itemNames[$i]);
        $qty = max(1, (int)($itemQtys[$i] ?? 1));
        $price = max(0, (float)($itemPrices[$i] ?? 0));

        if (!empty($name)) {
            $lineTotal = $qty * $price;
            $subtotal += $lineTotal;
            $itemsDetailArray[] = [
                'name' => $name,
                'qty' => $qty,
                'price' => $price,
                'total' => $lineTotal
            ];
        }
    }

    $discountAmount = max(0, (float)($_POST['discount_amount'] ?? 0));
    $taxRate = (float)($_POST['tax_rate'] ?? 0);
    $afterDiscount = max(0, $subtotal - $discountAmount);
    $taxAmount = $afterDiscount * ($taxRate / 100);
    $netAmount = $afterDiscount + $taxAmount;

    $itemsJson = json_encode($itemsDetailArray, JSON_UNESCAPED_UNICODE);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO receipts (
                receipt_number, order_id, customer_id, customer_name, customer_phone, vehicle_plate,
                total_amount, tax_amount, discount_amount, net_amount, payment_method, notes, items_detail, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $receiptNumber, $orderId, $customerId, $customerName, $customerPhone, $vehiclePlate,
            $subtotal, $taxAmount, $discountAmount, $netAmount, $paymentMethod, $notes ?: null, $itemsJson, $_SESSION['user_id']
        ]);
        $receiptId = $db->lastInsertId();

        // บันทึกเป็นรายรับลง accounting อัตโนมัติ (ถ้าเลือก)
        if (isset($_POST['record_accounting'])) {
            $stmtAcc = $db->prepare("
                INSERT INTO accounting (type, category, amount, description, reference_id, reference_type, transaction_date, created_by)
                VALUES ('income', 'ขายสินค้าหน้าร้าน (ใบเสร็จ)', ?, ?, ?, 'receipt', CURDATE(), ?)
            ");
            $desc = "ใบเสร็จเลขที่ {$receiptNumber} ลูกค้า: {$customerName} ทะเบียน: {$vehiclePlate}";
            $stmtAcc->execute([$netAmount, $desc, $receiptId, $_SESSION['user_id']]);
        }

        $db->commit();
        setFlash('success', 'ออกใบเสร็จเลขที่ ' . $receiptNumber . ' เรียบร้อยแล้ว');
        header('Location: ' . SITE_URL . 'print_receipt.php?id=' . $receiptId);
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        header('Location: ' . SITE_URL . 'receipts.php');
        exit;
    }
}

// ===== แก้ไขใบเสร็จ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_receipt') {
    requireStaff();

    $receiptId = (int)$_POST['receipt_id'];
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $vehiclePlate = trim($_POST['vehicle_plate'] ?? '');
    $paymentMethod = in_array($_POST['payment_method'] ?? '', ['cash', 'transfer', 'credit_card']) ? $_POST['payment_method'] : 'cash';
    $notes = trim($_POST['notes'] ?? '');

    $itemNames = $_POST['item_name'] ?? [];
    $itemQtys = $_POST['item_qty'] ?? [];
    $itemPrices = $_POST['item_price'] ?? [];

    $subtotal = 0;
    $itemsDetailArray = [];

    for ($i = 0; $i < count($itemNames); $i++) {
        $name = trim($itemNames[$i]);
        $qty = max(1, (int)($itemQtys[$i] ?? 1));
        $price = max(0, (float)($itemPrices[$i] ?? 0));

        if (!empty($name)) {
            $lineTotal = $qty * $price;
            $subtotal += $lineTotal;
            $itemsDetailArray[] = [
                'name' => $name,
                'qty' => $qty,
                'price' => $price,
                'total' => $lineTotal
            ];
        }
    }

    $discountAmount = max(0, (float)($_POST['discount_amount'] ?? 0));
    $taxRate = (float)($_POST['tax_rate'] ?? 0);
    $afterDiscount = max(0, $subtotal - $discountAmount);
    $taxAmount = $afterDiscount * ($taxRate / 100);
    $netAmount = $afterDiscount + $taxAmount;

    $itemsJson = json_encode($itemsDetailArray, JSON_UNESCAPED_UNICODE);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            UPDATE receipts SET
                customer_name = ?, customer_phone = ?, vehicle_plate = ?,
                total_amount = ?, tax_amount = ?, discount_amount = ?, net_amount = ?,
                payment_method = ?, notes = ?, items_detail = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $customerName, $customerPhone, $vehiclePlate,
            $subtotal, $taxAmount, $discountAmount, $netAmount,
            $paymentMethod, $notes ?: null, $itemsJson, $receiptId
        ]);

        // ดึงเลขที่ใบเสร็จ
        $stmtNum = $db->prepare("SELECT receipt_number FROM receipts WHERE id = ?");
        $stmtNum->execute([$receiptId]);
        $recNum = $stmtNum->fetchColumn() ?: '';

        // อัปเดตใน accounting ด้วย (ถ้ามี)
        $stmtCheckAcc = $db->prepare("SELECT id FROM accounting WHERE reference_id = ? AND reference_type = 'receipt'");
        $stmtCheckAcc->execute([$receiptId]);
        if ($stmtCheckAcc->fetch()) {
            $stmtUpdateAcc = $db->prepare("UPDATE accounting SET amount = ?, description = ? WHERE reference_id = ? AND reference_type = 'receipt'");
            $desc = "ใบเสร็จเลขที่ {$recNum} ลูกค้า: {$customerName} ทะเบียน: {$vehiclePlate}";
            $stmtUpdateAcc->execute([$netAmount, $desc, $receiptId]);
        }

        $db->commit();
        setFlash('success', 'แก้ไขข้อมูลใบเสร็จเรียบร้อยแล้ว');
        header('Location: ' . SITE_URL . 'print_receipt.php?id=' . $receiptId);
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', 'เกิดข้อผิดพลาดในการแก้ไขใบเสร็จ: ' . $e->getMessage());
        header('Location: ' . SITE_URL . 'receipts.php');
        exit;
    }
}

// ===== ลบใบเสร็จ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_receipt') {
    requireStaff();

    $receiptId = (int)$_POST['receipt_id'];
    try {
        $db->beginTransaction();
        // ลบรายการใน accounting ที่ผูกไว้
        $stmtAcc = $db->prepare("DELETE FROM accounting WHERE reference_id = ? AND reference_type = 'receipt'");
        $stmtAcc->execute([$receiptId]);

        // ลบใบเสร็จ
        $stmtRec = $db->prepare("DELETE FROM receipts WHERE id = ?");
        $stmtRec->execute([$receiptId]);

        $db->commit();
        setFlash('success', 'ลบใบเสร็จเรียบร้อยแล้ว');
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', 'ไม่สามารถลบใบเสร็จได้: ' . $e->getMessage());
    }
    header('Location: ' . SITE_URL . 'receipts.php');
    exit;
}

// ค้นหาและดึงรายการใบเสร็จ
$search = trim($_GET['search'] ?? '');
$where = ["1=1"];
$params = [];

if (!$isStaffUser) {
    // ลูกค้าดูได้เฉพาะใบเสร็จของตัวเอง
    $where[] = "r.customer_id = ?";
    $params[] = $currentUserId;
}

if ($search !== '') {
    $where[] = "(r.receipt_number LIKE ? OR r.customer_name LIKE ? OR r.vehicle_plate LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

$sql = "
    SELECT r.*, u.fullname as staff_name 
    FROM receipts r 
    LEFT JOIN users u ON r.created_by = u.id 
    WHERE {$whereClause} 
    ORDER BY r.created_at DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$receipts = $stmt->fetchAll();

// รายการคำสั่งซื้อที่ยังไม่ได้ออกใบเสร็จ (สำหรับ Staff)
$pendingOrders = [];
if ($isStaffUser) {
    $pendingOrders = $db->query("
        SELECT o.*, u.fullname as customer_name, u.phone as customer_phone, mv.license_plate 
        FROM orders o 
        LEFT JOIN users u ON o.customer_id = u.id 
        LEFT JOIN member_vehicles mv ON o.vehicle_id = mv.id 
        ORDER BY o.created_at DESC LIMIT 20
    ")->fetchAll();
}

// สมาชิกสำหรับ dropdown
$customers = $db->query("SELECT id, fullname, phone FROM users WHERE role = 'customer' ORDER BY fullname ASC")->fetchAll();
$products = $db->query("SELECT id, name, price FROM products WHERE status = 'active' ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="page-header d-print-none">
    <div>
        <h4 class="mb-1"><i class="bi bi-receipt-cutoff text-primary me-2"></i>ระบบใบเสร็จรับเงิน</h4>
        <small class="text-muted">ออกใบเสร็จรับเงิน ใบส่งของ พิมพ์ใบเสร็จ พร้อมระบุเงื่อนไขการรับประกันแบตเตอรี่</small>
    </div>
    <?php if ($isStaffUser): ?>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createReceiptModal">
        <i class="bi bi-plus-circle-fill me-1"></i> ออกใบเสร็จใหม่
    </button>
    <?php endif; ?>
</div>

<!-- ช่องค้นหา -->
<div class="card border-0 shadow-sm rounded-3 mb-4 d-print-none">
    <div class="card-body">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-md-9">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control border-start-0" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาเลขที่ใบเสร็จ, ชื่อลูกค้า, เลขทะเบียนรถ...">
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-secondary w-100"><i class="bi bi-funnel me-1"></i> ค้นหา</button>
            </div>
        </form>
    </div>
</div>

<!-- ตารางรายการใบเสร็จ -->
<div class="table-container shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>เลขที่ใบเสร็จ</th>
                    <th>วันที่ออกเอกสาร</th>
                    <th>ลูกค้า</th>
                    <th>ทะเบียนรถ</th>
                    <th>วิธีชำระเงิน</th>
                    <th class="text-end">ยอดเงินสุทธิ</th>
                    <th>ผู้ออกบิล</th>
                    <th class="text-center" style="width: 140px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($receipts)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">ไม่พบข้อมูลใบเสร็จรับเงิน</td></tr>
                <?php else: ?>
                <?php foreach ($receipts as $r): ?>
                <tr>
                    <td>
                        <strong class="text-primary"><i class="bi bi-receipt me-1"></i><?= htmlspecialchars($r['receipt_number']) ?></strong>
                        <?php if ($r['order_id']): ?>
                        <small class="d-block text-muted">จากคำสั่งซื้อ #<?= $r['order_id'] ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?> น.</td>
                    <td>
                        <strong><?= htmlspecialchars($r['customer_name'] ?: 'ลูกค้าทั่วไป') ?></strong>
                        <?php if ($r['customer_phone']): ?>
                        <small class="d-block text-muted"><?= htmlspecialchars($r['customer_phone']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['vehicle_plate']): ?>
                            <span class="badge bg-dark"><?= htmlspecialchars($r['vehicle_plate']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $methodNames = ['cash' => 'เงินสด', 'transfer' => 'โอนเงิน', 'credit_card' => 'บัตรเครดิต'];
                        $methodBadges = ['cash' => 'bg-success', 'transfer' => 'bg-info text-dark', 'credit_card' => 'bg-secondary'];
                        ?>
                        <span class="badge <?= $methodBadges[$r['payment_method']] ?? 'bg-light text-dark' ?>">
                            <?= $methodNames[$r['payment_method']] ?? $r['payment_method'] ?>
                        </span>
                    </td>
                    <td class="text-end fw-bold text-success fs-6">
                        <?= number_format($r['net_amount'], 2) ?> ฿
                    </td>
                    <td><small class="text-muted"><?= htmlspecialchars($r['staff_name'] ?? 'ระบบ') ?></small></td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <a href="<?= SITE_URL ?>print_receipt.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-outline-primary" title="ดู/พิมพ์ใบเสร็จ">
                                <i class="bi bi-printer"></i>
                            </a>
                            <?php if ($isStaffUser): ?>
                            <button type="button" class="btn btn-outline-warning" onclick='openEditReceipt(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' title="แก้ไขใบเสร็จ">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันลบใบเสร็จเลขที่ <?= htmlspecialchars($r['receipt_number']) ?> หรือไม่?');">
                                <input type="hidden" name="action" value="delete_receipt">
                                <input type="hidden" name="receipt_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger rounded-start-0" title="ลบใบเสร็จ">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: ออกใบเสร็จใหม่ (Staff only) -->
<?php if ($isStaffUser): ?>
<div class="modal fade" id="createReceiptModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_receipt">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-receipt-cutoff me-2"></i>ออกใบเสร็จรับเงิน / ใบส่งของ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- ดึงข้อมูลจากคำสั่งซื้อ (ถ้ามี) -->
                    <?php if (!empty($pendingOrders)): ?>
                    <div class="mb-3 p-3 bg-light rounded border">
                        <label class="form-label fw-bold text-primary mb-1">
                            <i class="bi bi-box-seam me-1"></i>ดึงข้อมูลจากคำสั่งซื้อล่าสุด (ทางเลือก)
                        </label>
                        <select class="form-select" id="orderSelect" onchange="fillOrderData(this)">
                            <option value="">-- ออกบิลหน้าร้านทั่วไป (ไม่ผูกคำสั่งซื้อ) --</option>
                            <?php foreach ($pendingOrders as $po): ?>
                            <option value="<?= $po['id'] ?>" data-name="<?= htmlspecialchars($po['customer_name'] ?? '') ?>" data-phone="<?= htmlspecialchars($po['customer_phone'] ?? '') ?>" data-plate="<?= htmlspecialchars($po['license_plate'] ?? '') ?>" data-amount="<?= $po['total_amount'] ?>" data-custid="<?= $po['customer_id'] ?>">
                                คำสั่งซื้อ #<?= htmlspecialchars($po['order_number'] ?? $po['id']) ?> - <?= htmlspecialchars($po['customer_name'] ?? '') ?> (<?= number_format($po['total_amount'], 2) ?> ฿)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <input type="hidden" name="order_id" id="rec_order_id" value="">
                    <input type="hidden" name="customer_id" id="rec_customer_id" value="">

                    <div class="row g-2 mb-3">
                        <div class="col-md-5">
                            <label class="form-label">ชื่อลูกค้า / บริษัท <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="customer_name" id="rec_customer_name" required placeholder="เช่น นายสมชาย มีทรัพย์">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">เบอร์โทรศัพท์</label>
                            <input type="text" class="form-control" name="customer_phone" id="rec_customer_phone" placeholder="เช่น 081-234-5678">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">เลขทะเบียนรถ</label>
                            <input type="text" class="form-control" name="vehicle_plate" id="rec_vehicle_plate" placeholder="เช่น 1กข 1234 กทม">
                        </div>
                    </div>

                    <!-- รายการสินค้า / บริการ -->
                    <label class="form-label fw-bold text-dark mb-2">รายการสินค้า / บริการ</label>
                    <div id="receiptItemsContainer">
                        <div class="row g-2 mb-2 item-row">
                            <div class="col-md-6">
                                <input type="text" class="form-control" name="item_name[]" list="productList" required placeholder="ชื่อสินค้า/บริการ เช่น 3K แบตเตอรี่ NS60L">
                            </div>
                            <div class="col-md-2">
                                <input type="number" class="form-control item-qty" name="item_qty[]" value="1" min="1" placeholder="จำนวน" oninput="calculateReceiptTotal()">
                            </div>
                            <div class="col-md-3">
                                <input type="number" step="0.01" class="form-control item-price" name="item_price[]" value="0.00" min="0" placeholder="ราคา/หน่วย" oninput="calculateReceiptTotal()">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-outline-danger w-100" onclick="removeItemRow(this)"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>

                    <datalist id="productList">
                        <?php foreach ($products as $pr): ?>
                        <option value="<?= htmlspecialchars($pr['name']) ?>" data-price="<?= $pr['price'] ?>">
                        <?php endforeach; ?>
                    </datalist>

                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="addItemRow()">
                        <i class="bi bi-plus-lg me-1"></i> เพิ่มรายการสินค้า/บริการ
                    </button>

                    <!-- การคำนวณเงิน -->
                    <div class="p-3 bg-light rounded border mb-3">
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-6 text-end">ส่วนลด (บาท):</div>
                            <div class="col-6">
                                <input type="number" step="0.01" class="form-control form-control-sm" name="discount_amount" id="rec_discount" value="0" oninput="calculateReceiptTotal()">
                            </div>
                        </div>
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-6 text-end">ภาษีมูลค่าเพิ่ม (VAT %):</div>
                            <div class="col-6">
                                <select name="tax_rate" id="rec_tax_rate" class="form-select form-select-sm" onchange="calculateReceiptTotal()">
                                    <option value="0" selected>ไม่มี VAT (0%)</option>
                                    <option value="7">VAT 7%</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center border-top pt-2">
                            <div class="col-6 text-end fw-bold fs-5">ยอดรวมสุทธิ:</div>
                            <div class="col-6">
                                <span class="fw-bold fs-5 text-success" id="rec_grand_total">0.00 ฿</span>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">วิธีการชำระเงิน <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <option value="cash" selected>เงินสด (Cash)</option>
                                <option value="transfer">โอนเงินผ่านธนาคาร (Bank Transfer)</option>
                                <option value="credit_card">บัตรเครดิต (Credit Card)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">หมายเหตุในใบเสร็จ</label>
                            <input type="text" class="form-control" name="notes" placeholder="เช่น รับประกันเปลี่ยนลูกใหม่ 12 เดือน, เทิร์นแบตเดิม">
                        </div>
                    </div>

                    <div class="form-check p-2 bg-white rounded border">
                        <input class="form-check-input ms-1" type="checkbox" name="record_accounting" id="rec_acc" value="1" checked>
                        <label class="form-check-label fw-bold ms-2" for="rec_acc">
                            บันทึกยอดเงินเข้าบัญชีรายรับอัตโนมัติ
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-printer me-1"></i>ออกใบเสร็จและพิมพ์</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขใบเสร็จ (Staff only) -->
<div class="modal fade" id="editReceiptModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_receipt">
                <input type="hidden" name="receipt_id" id="edit_receipt_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขใบเสร็จรับเงิน <span id="edit_modal_rec_no" class="fw-bold"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-5">
                            <label class="form-label">ชื่อลูกค้า / บริษัท <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="customer_name" id="edit_customer_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">เบอร์โทรศัพท์</label>
                            <input type="text" class="form-control" name="customer_phone" id="edit_customer_phone">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">เลขทะเบียนรถ</label>
                            <input type="text" class="form-control" name="vehicle_plate" id="edit_vehicle_plate">
                        </div>
                    </div>

                    <!-- รายการสินค้า / บริการ -->
                    <label class="form-label fw-bold text-dark mb-2">รายการสินค้า / บริการ</label>
                    <div id="editReceiptItemsContainer"></div>

                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="addEditItemRow()">
                        <i class="bi bi-plus-lg me-1"></i> เพิ่มรายการสินค้า/บริการ
                    </button>

                    <!-- การคำนวณเงิน -->
                    <div class="p-3 bg-light rounded border mb-3">
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-6 text-end">ส่วนลด (บาท):</div>
                            <div class="col-6">
                                <input type="number" step="0.01" class="form-control form-control-sm" name="discount_amount" id="edit_rec_discount" value="0" oninput="calculateEditReceiptTotal()">
                            </div>
                        </div>
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-6 text-end">ภาษีมูลค่าเพิ่ม (VAT %):</div>
                            <div class="col-6">
                                <select name="tax_rate" id="edit_rec_tax_rate" class="form-select form-select-sm" onchange="calculateEditReceiptTotal()">
                                    <option value="0" selected>ไม่มี VAT (0%)</option>
                                    <option value="7">VAT 7%</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center border-top pt-2">
                            <div class="col-6 text-end fw-bold text-dark fs-6">ยอดสุทธิ (Net Total):</div>
                            <div class="col-6 text-end fw-bold text-success fs-5" id="edit_rec_grand_total">0.00 ฿</div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">วิธีการชำระเงิน <span class="text-danger">*</span></label>
                            <select name="payment_method" id="edit_payment_method" class="form-select" required>
                                <option value="cash">เงินสด (Cash)</option>
                                <option value="transfer">โอนเงินผ่านธนาคาร (Bank Transfer)</option>
                                <option value="credit_card">บัตรเครดิต (Credit Card)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">หมายเหตุในใบเสร็จ</label>
                            <input type="text" class="form-control" name="notes" id="edit_notes" placeholder="หมายเหตุ">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i>บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function fillOrderData(select) {
    const opt = select.options[select.selectedIndex];
    if (opt.value) {
        document.getElementById('rec_order_id').value = opt.value;
        document.getElementById('rec_customer_id').value = opt.getAttribute('data-custid') || '';
        document.getElementById('rec_customer_name').value = opt.getAttribute('data-name') || '';
        document.getElementById('rec_customer_phone').value = opt.getAttribute('data-phone') || '';
        document.getElementById('rec_vehicle_plate').value = opt.getAttribute('data-plate') || '';

        // Auto load order items via ajax
        fetch('<?= SITE_URL ?>api/get_order_items.php?order_id=' + opt.value)
            .then(res => res.json())
            .then(items => {
                if (items && items.length > 0) {
                    const container = document.getElementById('receiptItemsContainer');
                    container.innerHTML = '';
                    items.forEach(it => {
                        addItemRowWithData(it.name, it.quantity, it.price);
                    });
                    calculateReceiptTotal();
                }
            })
            .catch(() => {});
    }
}

function addItemRow() {
    const container = document.getElementById('receiptItemsContainer');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 item-row';
    row.innerHTML = `
        <div class="col-md-6">
            <input type="text" class="form-control" name="item_name[]" list="productList" required placeholder="ชื่อสินค้า/บริการ">
        </div>
        <div class="col-md-2">
            <input type="number" class="form-control item-qty" name="item_qty[]" value="1" min="1" placeholder="จำนวน" oninput="calculateReceiptTotal()">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control item-price" name="item_price[]" value="0.00" min="0" placeholder="ราคา/หน่วย" oninput="calculateReceiptTotal()">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger w-100" onclick="removeItemRow(this)"><i class="bi bi-trash"></i></button>
        </div>
    `;
    container.appendChild(row);
}

function addItemRowWithData(name, qty, price) {
    const container = document.getElementById('receiptItemsContainer');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 item-row';
    row.innerHTML = `
        <div class="col-md-6">
            <input type="text" class="form-control" name="item_name[]" value="${name}" list="productList" required>
        </div>
        <div class="col-md-2">
            <input type="number" class="form-control item-qty" name="item_qty[]" value="${qty}" min="1" oninput="calculateReceiptTotal()">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control item-price" name="item_price[]" value="${price}" min="0" oninput="calculateReceiptTotal()">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger w-100" onclick="removeItemRow(this)"><i class="bi bi-trash"></i></button>
        </div>
    `;
    container.appendChild(row);
}

function removeItemRow(btn) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) {
        btn.closest('.item-row').remove();
        calculateReceiptTotal();
    } else {
        alert('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
    }
}

function calculateReceiptTotal() {
    let subtotal = 0;
    const qtys = document.querySelectorAll('.item-qty');
    const prices = document.querySelectorAll('.item-price');
    for (let i = 0; i < qtys.length; i++) {
        const q = parseFloat(qtys[i].value) || 0;
        const p = parseFloat(prices[i].value) || 0;
        subtotal += q * p;
    }
    const discount = parseFloat(document.getElementById('rec_discount').value) || 0;
    const taxRate = parseFloat(document.getElementById('rec_tax_rate').value) || 0;
    const afterDiscount = Math.max(0, subtotal - discount);
    const tax = afterDiscount * (taxRate / 100);
    const total = afterDiscount + tax;

    document.getElementById('rec_grand_total').textContent = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ฿';
}

// ===== ฟังก์ชันสำหรับแก้ไขใบเสร็จ =====
function openEditReceipt(r) {
    if (!r) return;
    document.getElementById('edit_receipt_id').value = r.id;
    document.getElementById('edit_modal_rec_no').textContent = '#' + r.receipt_number;
    document.getElementById('edit_customer_name').value = r.customer_name || '';
    document.getElementById('edit_customer_phone').value = r.customer_phone || '';
    document.getElementById('edit_vehicle_plate').value = r.vehicle_plate || '';
    document.getElementById('edit_payment_method').value = r.payment_method || 'cash';
    document.getElementById('edit_notes').value = r.notes || '';
    document.getElementById('edit_rec_discount').value = parseFloat(r.discount_amount || 0);

    // VAT
    const taxRateSelect = document.getElementById('edit_rec_tax_rate');
    if (parseFloat(r.tax_amount || 0) > 0) {
        taxRateSelect.value = '7';
    } else {
        taxRateSelect.value = '0';
    }

    // Items
    const container = document.getElementById('editReceiptItemsContainer');
    container.innerHTML = '';
    let items = [];
    if (typeof r.items_detail === 'string') {
        try { items = JSON.parse(r.items_detail); } catch (e) { items = []; }
    } else if (Array.isArray(r.items_detail)) {
        items = r.items_detail;
    }

    if (items && items.length > 0) {
        items.forEach(it => {
            addEditItemRowWithData(it.name || '', it.qty || 1, it.price || 0);
        });
    } else {
        // Fallback row with total amount
        addEditItemRowWithData('สินค้า/บริการ', 1, r.total_amount || 0);
    }

    calculateEditReceiptTotal();
    const modalEl = document.getElementById('editReceiptModal');
    if (modalEl) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}

function addEditItemRow() {
    addEditItemRowWithData('', 1, 0);
}

function addEditItemRowWithData(name, qty, price) {
    const container = document.getElementById('editReceiptItemsContainer');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 edit-item-row';
    row.innerHTML = `
        <div class="col-md-6">
            <input type="text" class="form-control" name="item_name[]" value="${name}" list="productList" required placeholder="ชื่อสินค้า/บริการ">
        </div>
        <div class="col-md-2">
            <input type="number" class="form-control edit-item-qty" name="item_qty[]" value="${qty}" min="1" placeholder="จำนวน" oninput="calculateEditReceiptTotal()">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control edit-item-price" name="item_price[]" value="${price}" min="0" placeholder="ราคา/หน่วย" oninput="calculateEditReceiptTotal()">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger w-100" onclick="removeEditItemRow(this)"><i class="bi bi-trash"></i></button>
        </div>
    `;
    container.appendChild(row);
}

function removeEditItemRow(btn) {
    const rows = document.querySelectorAll('.edit-item-row');
    if (rows.length > 1) {
        btn.closest('.edit-item-row').remove();
        calculateEditReceiptTotal();
    } else {
        alert('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
    }
}

function calculateEditReceiptTotal() {
    let subtotal = 0;
    const qtys = document.querySelectorAll('.edit-item-qty');
    const prices = document.querySelectorAll('.edit-item-price');
    for (let i = 0; i < qtys.length; i++) {
        const q = parseFloat(qtys[i].value) || 0;
        const p = parseFloat(prices[i].value) || 0;
        subtotal += q * p;
    }
    const discount = parseFloat(document.getElementById('edit_rec_discount').value) || 0;
    const taxRate = parseFloat(document.getElementById('edit_rec_tax_rate').value) || 0;
    const afterDiscount = Math.max(0, subtotal - discount);
    const tax = afterDiscount * (taxRate / 100);
    const total = afterDiscount + tax;

    document.getElementById('edit_rec_grand_total').textContent = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ฿';
}

// Auto open edit modal if ?edit=ID is present
window.addEventListener('DOMContentLoaded', () => {
    const editId = new URLSearchParams(window.location.search).get('edit');
    if (editId) {
        // Find receipt in table
        <?php foreach ($receipts as $r): ?>
        if (editId == '<?= $r['id'] ?>') {
            openEditReceipt(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>);
        }
        <?php endforeach; ?>
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

