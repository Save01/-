<?php
require_once 'config.php';
requireLogin();
$pageTitle = 'ข้อมูลประกัน';

$db = getDB();
$userId = $_SESSION['user_id'];
$isStaffUser = isStaff();

// ===== Staff: เพิ่มประกัน =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_warranty' && $isStaffUser) {
    $productId = (int)$_POST['product_id'];
    $customerId = (int)$_POST['customer_id'];
    $vehicleId = !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
    $serialNumber = trim($_POST['serial_number'] ?? '');
    $purchaseDate = $_POST['purchase_date'];
    $warrantyMonths = (int)$_POST['warranty_months'];
    $notes = trim($_POST['notes'] ?? '');
    
    $warrantyEndDate = date('Y-m-d', strtotime($purchaseDate . " +{$warrantyMonths} months"));
    
    $stmt = $db->prepare("
        INSERT INTO warranties (product_id, customer_id, vehicle_id, serial_number, purchase_date, warranty_end_date, warranty_months, notes, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ");
    $stmt->execute([$productId, $customerId, $vehicleId, $serialNumber, $purchaseDate, $warrantyEndDate, $warrantyMonths, $notes]);
    
    // แจ้งเตือนลูกค้า
    $stmtProd = $db->prepare("SELECT name FROM products WHERE id = ?");
    $stmtProd->execute([$productId]);
    $prodName = $stmtProd->fetchColumn();
    
    createNotification(
        $customerId,
        'ลงทะเบียนประกันสำเร็จ',
        'สินค้า ' . $prodName . ' ได้รับการลงทะเบียนประกัน ' . $warrantyMonths . ' เดือน หมดประกัน ' . date('d/m/Y', strtotime($warrantyEndDate)),
        'warranty_expire',
        'warranty.php'
    );
    
    setFlash('success', 'เพิ่มข้อมูลประกันสำเร็จ');
    header('Location: warranty.php');
    exit;
}

// ===== Staff: แก้ไขประกัน =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_warranty' && $isStaffUser) {
    $warrantyId = (int)$_POST['warranty_id'];
    $serialNumber = trim($_POST['serial_number'] ?? '');
    $purchaseDate = $_POST['purchase_date'];
    $warrantyMonths = (int)$_POST['warranty_months'];
    $notes = trim($_POST['notes'] ?? '');
    $status = $_POST['status'];
    
    $warrantyEndDate = date('Y-m-d', strtotime($purchaseDate . " +{$warrantyMonths} months"));
    
    $stmt = $db->prepare("
        UPDATE warranties SET serial_number = ?, purchase_date = ?, warranty_end_date = ?, warranty_months = ?, notes = ?, status = ?
        WHERE id = ?
    ");
    $stmt->execute([$serialNumber, $purchaseDate, $warrantyEndDate, $warrantyMonths, $notes, $status, $warrantyId]);
    
    setFlash('success', 'แก้ไขข้อมูลประกันสำเร็จ');
    header('Location: warranty.php');
    exit;
}

// ===== Staff: ลบประกัน =====
if (isset($_GET['delete']) && $isStaffUser) {
    $deleteId = (int)$_GET['delete'];
    $stmtDel = $db->prepare("DELETE FROM warranties WHERE id = ?");
    $stmtDel->execute([$deleteId]);
    setFlash('success', 'ลบข้อมูลประกันสำเร็จ');
    header('Location: warranty.php');
    exit;
}

// ===== AJAX: ดึงรถของลูกค้า =====
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_vehicles' && isset($_GET['customer_id']) && $isStaffUser) {
    header('Content-Type: application/json; charset=utf-8');
    $custId = (int)$_GET['customer_id'];
    $stmtV = $db->prepare("SELECT mv.id, mv.model, mv.license_plate, cb.name AS brand_name FROM member_vehicles mv JOIN car_brands cb ON mv.brand_id = cb.id WHERE mv.user_id = ?");
    $stmtV->execute([$custId]);
    echo json_encode($stmtV->fetchAll());
    exit;
}

// ===== ดึงข้อมูลประกัน =====
$searchTerm = trim($_GET['search'] ?? '');

if ($isStaffUser) {
    $where = [];
    $params = [];
    if ($searchTerm) {
        $where[] = "(u.fullname LIKE ? OR w.serial_number LIKE ? OR p.name LIKE ?)";
        $params[] = "%{$searchTerm}%";
        $params[] = "%{$searchTerm}%";
        $params[] = "%{$searchTerm}%";
    }
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $stmtWarranties = $db->prepare("
        SELECT w.*, p.name AS product_name, bb.name AS battery_brand, p.image AS product_image,
            u.fullname AS customer_name, u.phone AS customer_phone,
            mv.license_plate, mv.model AS vehicle_model, cb.name AS vehicle_brand,
            DATEDIFF(w.warranty_end_date, CURDATE()) AS days_remaining
        FROM warranties w
        JOIN products p ON w.product_id = p.id
        LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
        JOIN users u ON w.customer_id = u.id
        LEFT JOIN member_vehicles mv ON w.vehicle_id = mv.id
        LEFT JOIN car_brands cb ON mv.brand_id = cb.id
        {$whereClause}
        ORDER BY w.warranty_end_date ASC
    ");
    $stmtWarranties->execute($params);
} else {
    $where = ["w.customer_id = ?"];
    $params = [$userId];
    if ($searchTerm) {
        $where[] = "(w.serial_number LIKE ? OR p.name LIKE ? OR mv.license_plate LIKE ?)";
        $params[] = "%{$searchTerm}%";
        $params[] = "%{$searchTerm}%";
        $params[] = "%{$searchTerm}%";
    }
    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $stmtWarranties = $db->prepare("
        SELECT w.*, p.name AS product_name, bb.name AS battery_brand, p.image AS product_image,
            u.fullname AS customer_name, u.phone AS customer_phone,
            mv.license_plate, mv.model AS vehicle_model, cb.name AS vehicle_brand,
            DATEDIFF(w.warranty_end_date, CURDATE()) AS days_remaining
        FROM warranties w
        JOIN products p ON w.product_id = p.id
        LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
        JOIN users u ON w.customer_id = u.id
        LEFT JOIN member_vehicles mv ON w.vehicle_id = mv.id
        LEFT JOIN car_brands cb ON mv.brand_id = cb.id
        {$whereClause}
        ORDER BY w.warranty_end_date ASC
    ");
    $stmtWarranties->execute($params);
}
$warranties = $stmtWarranties->fetchAll();

// สำหรับ Staff: ดึงรายการลูกค้าและสินค้า
if ($isStaffUser) {
    $customers = $db->query("SELECT id, fullname, phone FROM users WHERE role = 'customer' ORDER BY fullname")->fetchAll();
    $products = $db->query("SELECT p.id, p.name, p.warranty_months, bb.name AS brand_name FROM products p LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id WHERE p.status = 'active' ORDER BY p.name")->fetchAll();
}

require_once 'header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-shield-check"></i> ข้อมูลประกัน</h4>
    <?php if ($isStaffUser): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWarrantyModal">
        <i class="bi bi-plus-circle"></i> เพิ่มประกัน
    </button>
    <?php endif; ?>
</div>

<!-- ค้นหา -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-9">
                <label class="form-label fw-bold"><i class="bi bi-search me-1"></i>ค้นหาและตรวจสอบระยะประกันสินค้า</label>
                <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="<?= $isStaffUser ? 'ค้นหาด้วยชื่อลูกค้า, หมายเลขซีเรียล, ทะเบียนรถ, หรือชื่อสินค้า...' : 'ค้นหาด้วยหมายเลขซีเรียล (SN), ทะเบียนรถ, หรือชื่อสินค้า...' ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> ค้นหา</button>
            </div>
        </form>
    </div>
</div>

<!-- รายการประกัน -->
<?php if (empty($warranties)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-shield display-1 text-muted"></i>
        <h5 class="mt-3">ไม่พบข้อมูลประกัน</h5>
        <p class="text-muted"><?= $isStaffUser ? 'กรุณาเพิ่มข้อมูลประกันสินค้า' : 'คุณยังไม่มีประกันสินค้า' ?></p>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($warranties as $w): ?>
    <?php
        $daysRemaining = (int)$w['days_remaining'];
        if ($daysRemaining < 0) {
            $colorClass = 'border-danger';
            $bgClass = 'bg-danger bg-opacity-10';
            $badgeClass = 'bg-danger';
            $statusText = 'หมดประกัน';
            $icon = 'bi-shield-x';
        } elseif ($daysRemaining <= 30) {
            $colorClass = 'border-warning';
            $bgClass = 'bg-warning bg-opacity-10';
            $badgeClass = 'bg-warning text-dark';
            $statusText = 'ใกล้หมดประกัน';
            $icon = 'bi-shield-exclamation';
        } else {
            $colorClass = 'border-success';
            $bgClass = 'bg-success bg-opacity-10';
            $badgeClass = 'bg-success';
            $statusText = 'ยังอยู่ในประกัน';
            $icon = 'bi-shield-check';
        }
        if ($w['status'] === 'claimed') {
            $badgeClass = 'bg-info';
            $statusText = 'เคลมแล้ว';
            $icon = 'bi-arrow-repeat';
        }
    ?>
    <div class="col-lg-6">
        <div class="card border-2 <?= $colorClass ?> h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="mb-1"><?= htmlspecialchars($w['product_name']) ?></h6>
                        <small class="text-muted"><?= htmlspecialchars($w['battery_brand'] ?? '') ?></small>
                    </div>
                    <span class="badge <?= $badgeClass ?> badge-status">
                        <i class="bi <?= $icon ?>"></i> <?= $statusText ?>
                    </span>
                </div>
                
                <div class="<?= $bgClass ?> rounded p-2 mb-2">
                    <div class="row g-2 small">
                        <div class="col-6">
                            <strong>ซีเรียล:</strong> <?= htmlspecialchars($w['serial_number'] ?: '-') ?>
                        </div>
                        <div class="col-6">
                            <strong>ระยะประกัน:</strong> <?= $w['warranty_months'] ?> เดือน
                        </div>
                        <div class="col-6">
                            <strong>วันที่ซื้อ:</strong> <?= date('d/m/Y', strtotime($w['purchase_date'])) ?>
                        </div>
                        <div class="col-6">
                            <strong>หมดประกัน:</strong> <?= date('d/m/Y', strtotime($w['warranty_end_date'])) ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($daysRemaining >= 0 && $w['status'] !== 'claimed'): ?>
                <div class="mb-2">
                    <small class="text-muted">เหลืออีก <?= $daysRemaining ?> วัน</small>
                    <div class="progress" style="height: 6px;">
                        <?php
                        $totalDays = (strtotime($w['warranty_end_date']) - strtotime($w['purchase_date'])) / 86400;
                        $usedPercent = $totalDays > 0 ? max(0, min(100, (($totalDays - $daysRemaining) / $totalDays) * 100)) : 100;
                        $barColor = $daysRemaining <= 30 ? 'bg-warning' : 'bg-success';
                        ?>
                        <div class="progress-bar <?= $barColor ?>" style="width: <?= $usedPercent ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($isStaffUser): ?>
                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                    <small>
                        <i class="bi bi-person"></i> <?= htmlspecialchars($w['customer_name']) ?> 
                        <?php if ($w['license_plate']): ?>
                        | <i class="bi bi-car-front"></i> <?= htmlspecialchars($w['vehicle_brand'] . ' ' . $w['vehicle_model']) ?> (<?= htmlspecialchars($w['license_plate']) ?>)
                        <?php endif; ?>
                    </small>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="editWarranty(<?= htmlspecialchars(json_encode($w)) ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-outline-danger" onclick="confirmDelete('warranty.php?delete=<?= $w['id'] ?>', '<?= htmlspecialchars($w['product_name']) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                    <small class="text-muted">
                        <?php if ($w['license_plate']): ?>
                        <i class="bi bi-car-front"></i> <?= htmlspecialchars(($w['vehicle_brand'] ?? '') . ' ' . ($w['vehicle_model'] ?? '')) ?> (<?= htmlspecialchars($w['license_plate']) ?>)
                        <?php endif; ?>
                    </small>
                    <?php if ($w['status'] === 'active' && $daysRemaining >= 0): ?>
                    <a href="<?= SITE_URL ?>claims.php?warranty_id=<?= $w['id'] ?>" class="btn btn-sm btn-warning text-dark">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> ส่งเคลมสินค้า
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($isStaffUser): ?>
<!-- Modal เพิ่มประกัน -->
<div class="modal fade" id="addWarrantyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_warranty">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-shield-plus"></i> เพิ่มข้อมูลประกัน</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">สินค้า <span class="text-danger">*</span></label>
                            <select name="product_id" class="form-select" required id="addProductSelect" onchange="updateWarrantyMonths(this)">
                                <option value="">-- เลือกสินค้า --</option>
                                <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" data-months="<?= $p['warranty_months'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['brand_name'] ?? '') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ลูกค้า <span class="text-danger">*</span></label>
                            <select name="customer_id" class="form-select" required id="addCustomerSelect" onchange="loadVehicles(this.value, 'addVehicleSelect')">
                                <option value="">-- เลือกลูกค้า --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['fullname']) ?> (<?= htmlspecialchars($c['phone']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">รถ</label>
                            <select name="vehicle_id" class="form-select" id="addVehicleSelect">
                                <option value="">-- เลือกรถ --</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">หมายเลขซีเรียล</label>
                            <input type="text" name="serial_number" class="form-control" placeholder="S/N...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">วันที่ซื้อ <span class="text-danger">*</span></label>
                            <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ระยะประกัน (เดือน) <span class="text-danger">*</span></label>
                            <input type="number" name="warranty_months" class="form-control" value="12" min="1" max="120" required id="addWarrantyMonths">
                        </div>
                        <div class="col-12">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal แก้ไขประกัน -->
<div class="modal fade" id="editWarrantyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_warranty">
                <input type="hidden" name="warranty_id" id="editWarrantyId">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> แก้ไขข้อมูลประกัน</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">หมายเลขซีเรียล</label>
                            <input type="text" name="serial_number" class="form-control" id="editSerialNumber">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">สถานะ</label>
                            <select name="status" class="form-select" id="editStatus">
                                <option value="active">ใช้งาน</option>
                                <option value="expired">หมดประกัน</option>
                                <option value="claimed">เคลมแล้ว</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">วันที่ซื้อ <span class="text-danger">*</span></label>
                            <input type="date" name="purchase_date" class="form-control" id="editPurchaseDate" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ระยะประกัน (เดือน) <span class="text-danger">*</span></label>
                            <input type="number" name="warranty_months" class="form-control" id="editWarrantyMonths" min="1" max="120" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea name="notes" class="form-control" rows="2" id="editNotes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateWarrantyMonths(select) {
    const option = select.selectedOptions[0];
    const months = option?.dataset?.months;
    if (months) {
        document.getElementById('addWarrantyMonths').value = months;
    }
}

function loadVehicles(customerId, selectId) {
    const select = document.getElementById(selectId);
    select.innerHTML = '<option value="">-- กำลังโหลด... --</option>';
    
    if (!customerId) {
        select.innerHTML = '<option value="">-- เลือกรถ --</option>';
        return;
    }
    
    fetch('warranty.php?ajax=get_vehicles&customer_id=' + customerId)
        .then(r => r.json())
        .then(vehicles => {
            let html = '<option value="">-- เลือกรถ --</option>';
            vehicles.forEach(v => {
                html += `<option value="${v.id}">${v.brand_name} ${v.model} (${v.license_plate})</option>`;
            });
            select.innerHTML = html;
        })
        .catch(() => {
            select.innerHTML = '<option value="">-- ไม่พบข้อมูลรถ --</option>';
        });
}

function editWarranty(w) {
    document.getElementById('editWarrantyId').value = w.id;
    document.getElementById('editSerialNumber').value = w.serial_number || '';
    document.getElementById('editPurchaseDate').value = w.purchase_date;
    document.getElementById('editWarrantyMonths').value = w.warranty_months;
    document.getElementById('editNotes').value = w.notes || '';
    document.getElementById('editStatus').value = w.status;
    new bootstrap.Modal(document.getElementById('editWarrantyModal')).show();
}
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
