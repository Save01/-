<?php
require_once __DIR__ . '/config.php';
requireStaff();

$pageTitle = 'จัดการสินค้าแนะนำ';
$db = getDB();

// ===== จัดการ POST requests =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. เพิ่มสินค้าแนะนำ
    if ($action === 'add_recommendation') {
        $carBrandId = (int)$_POST['car_brand_id'];
        $carModel = trim($_POST['car_model'] ?? '');
        $productId = (int)$_POST['product_id'];
        $priority = (int)($_POST['priority'] ?? 1);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($carBrandId <= 0 || $productId <= 0) {
            setFlash('danger', 'กรุณาเลือกยี่ห้อรถและสินค้าแบตเตอรี่');
        } else {
            $stmt = $db->prepare("
                INSERT INTO recommended_products (product_id, car_brand_id, car_model, priority, is_active)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$productId, $carBrandId, $carModel ?: null, $priority, $isActive]);
            setFlash('success', 'เพิ่มสินค้าแนะนำเรียบร้อยแล้ว');
        }
        header('Location: ' . SITE_URL . 'recommendations.php');
        exit;
    }

    // 2. แก้ไขสินค้าแนะนำ
    if ($action === 'edit_recommendation') {
        $recId = (int)$_POST['rec_id'];
        $carBrandId = (int)$_POST['car_brand_id'];
        $carModel = trim($_POST['car_model'] ?? '');
        $productId = (int)$_POST['product_id'];
        $priority = (int)($_POST['priority'] ?? 1);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $stmt = $db->prepare("
            UPDATE recommended_products SET product_id = ?, car_brand_id = ?, car_model = ?, priority = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([$productId, $carBrandId, $carModel ?: null, $priority, $isActive, $recId]);
        setFlash('success', 'แก้ไขสินค้าแนะนำเรียบร้อยแล้ว');
        header('Location: ' . SITE_URL . 'recommendations.php');
        exit;
    }

    // 3. ลบสินค้าแนะนำ
    if ($action === 'delete_recommendation') {
        $recId = (int)$_POST['rec_id'];
        $stmt = $db->prepare("DELETE FROM recommended_products WHERE id = ?");
        $stmt->execute([$recId]);
        setFlash('success', 'ลบสินค้าแนะนำเรียบร้อยแล้ว');
        header('Location: ' . SITE_URL . 'recommendations.php');
        exit;
    }
}

// ค้นหาและกรอง
$filterBrand = (int)($_GET['brand_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterBrand > 0) {
    $where[] = "rp.car_brand_id = ?";
    $params[] = $filterBrand;
}
if ($search !== '') {
    $where[] = "(cb.name LIKE ? OR rp.car_model LIKE ? OR p.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

// ดึงรายการแนะนำทั้งหมด
$sql = "
    SELECT rp.*, cb.name as brand_name, cb.type as brand_type, p.name as product_name, p.price as product_price, p.quantity as product_stock, bb.name as battery_brand_name 
    FROM recommended_products rp 
    JOIN car_brands cb ON rp.car_brand_id = cb.id 
    JOIN products p ON rp.product_id = p.id 
    LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id 
    WHERE {$whereClause} 
    ORDER BY cb.name ASC, rp.car_model ASC, rp.priority ASC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$recommendations = $stmt->fetchAll();

// ข้อมูลสำหรับ Dropdowns
$carBrands = $db->query("SELECT * FROM car_brands ORDER BY type, name")->fetchAll();
$products = $db->query("SELECT id, name, price, quantity FROM products WHERE status = 'active' ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div>
        <h4 class="mb-1"><i class="bi bi-star-fill text-warning me-2"></i>ระบบจัดการสินค้าแนะนำตามยี่ห้อ/รุ่นรถ</h4>
        <small class="text-muted">กำหนดแบตเตอรี่ที่เหมาะสมและแนะนำให้กับลูกค้ารายยี่ห้อและรุ่นรถ ระบบจะแสดงอัตโนมัติเมื่อลูกค้าสั่งซื้อ</small>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRecModal">
        <i class="bi bi-plus-circle-fill me-1"></i> เพิ่มการจับคู่สินค้าแนะนำ
    </button>
</div>

<!-- ช่องค้นหาและตัวกรอง -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหายี่ห้อรถ, รุ่นรถ, หรือชื่อแบตเตอรี่...">
            </div>
            <div class="col-md-4">
                <select name="brand_id" class="form-select">
                    <option value="">ทุกยี่ห้อรถ</option>
                    <?php foreach ($carBrands as $cb): ?>
                    <option value="<?= $cb['id'] ?>" <?= $filterBrand === (int)$cb['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-secondary w-100"><i class="bi bi-funnel me-1"></i> กรองข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<!-- ตารางรายการสินค้าแนะนำ -->
<div class="table-container shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>ยี่ห้อรถ</th>
                    <th>รุ่นรถที่รองรับ</th>
                    <th>แบตเตอรี่ที่แนะนำ</th>
                    <th>ราคา</th>
                    <th>สต๊อกคงเหลือ</th>
                    <th class="text-center">ลำดับความสำคัญ</th>
                    <th class="text-center">สถานะ</th>
                    <th class="text-center" style="width: 120px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recommendations)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">ไม่พบข้อมูลสินค้าแนะนำ</td></tr>
                <?php else: ?>
                <?php foreach ($recommendations as $r): ?>
                <tr>
                    <td>
                        <div class="fw-bold text-dark"><i class="bi bi-car-front-fill text-primary me-1"></i><?= htmlspecialchars($r['brand_name']) ?></div>
                        <small class="text-muted"><?= $r['brand_type'] === 'motorcycle' ? 'มอเตอร์ไซค์' : 'รถยนต์' ?></small>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border fs-6">
                            <?= htmlspecialchars($r['car_model'] ?: 'ทุกรุ่นของยี่ห้อนี้') ?>
                        </span>
                    </td>
                    <td>
                        <div class="fw-bold text-primary"><i class="bi bi-battery-charging me-1"></i><?= htmlspecialchars($r['product_name']) ?></div>
                        <small class="text-muted">แบรนด์: <?= htmlspecialchars($r['battery_brand_name'] ?? '-') ?></small>
                    </td>
                    <td class="fw-bold text-success"><?= number_format($r['product_price'], 2) ?> ฿</td>
                    <td>
                        <span class="badge <?= $r['product_stock'] <= 5 ? 'bg-danger' : 'bg-success' ?>">
                            <?= $r['product_stock'] ?> ลูก
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-secondary rounded-pill">อันดับ <?= $r['priority'] ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($r['is_active']): ?>
                            <span class="badge bg-success rounded-pill">เปิดใช้งาน</span>
                        <?php else: ?>
                            <span class="badge bg-secondary rounded-pill">ปิดใช้งาน</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-warning" onclick='editRec(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="" onsubmit="return confirm('ยืนยันลบคำแนะนำนี้?');" class="d-inline">
                                <input type="hidden" name="action" value="delete_recommendation">
                                <input type="hidden" name="rec_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: เพิ่มสินค้าแนะนำ -->
<div class="modal fade" id="addRecModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_recommendation">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-star-fill me-2"></i>เพิ่มการจับคู่สินค้าแนะนำ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ยี่ห้อรถ <span class="text-danger">*</span></label>
                        <select name="car_brand_id" class="form-select" required>
                            <option value="">-- เลือกยี่ห้อรถ --</option>
                            <?php foreach ($carBrands as $cb): ?>
                            <option value="<?= $cb['id'] ?>"><?= htmlspecialchars($cb['name']) ?> (<?= $cb['type'] === 'motorcycle' ? 'มอเตอร์ไซค์' : 'รถยนต์' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รุ่นรถที่ตรงกัน</label>
                        <input type="text" class="form-control" name="car_model" placeholder="เช่น Vios, City, D-Max, PCX (เว้นว่าง = แนะนำทุกรุ่นของยี่ห้อนี้)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">แบตเตอรี่ที่ต้องการแนะนำ <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- เลือกสินค้าแบตเตอรี่ --</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (ราคา: <?= number_format($p['price'], 2) ?> ฿ | เหลือ: <?= $p['quantity'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">ลำดับความสำคัญ (Priority)</label>
                            <input type="number" class="form-control" name="priority" value="1" min="1" max="99">
                            <small class="text-muted">1 = แสดงเป็นอันดับแรก</small>
                        </div>
                        <div class="col-6 d-flex align-items-center pt-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="add_is_active" value="1" checked>
                                <label class="form-check-label fw-bold" for="add_is_active">เปิดใช้งานแนะนำ</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>บันทึกคำแนะนำ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขสินค้าแนะนำ -->
<div class="modal fade" id="editRecModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_recommendation">
                <input type="hidden" name="rec_id" id="edit_rec_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขสินค้าแนะนำ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ยี่ห้อรถ <span class="text-danger">*</span></label>
                        <select name="car_brand_id" id="edit_car_brand_id" class="form-select" required>
                            <option value="">-- เลือกยี่ห้อรถ --</option>
                            <?php foreach ($carBrands as $cb): ?>
                            <option value="<?= $cb['id'] ?>"><?= htmlspecialchars($cb['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รุ่นรถที่ตรงกัน</label>
                        <input type="text" class="form-control" name="car_model" id="edit_car_model">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">แบตเตอรี่ที่ต้องการแนะนำ <span class="text-danger">*</span></label>
                        <select name="product_id" id="edit_product_id" class="form-select" required>
                            <option value="">-- เลือกสินค้าแบตเตอรี่ --</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (ราคา: <?= number_format($p['price'], 2) ?> ฿)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">ลำดับความสำคัญ (Priority)</label>
                            <input type="number" class="form-control" name="priority" id="edit_priority" min="1" max="99">
                        </div>
                        <div class="col-6 d-flex align-items-center pt-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label fw-bold" for="edit_is_active">เปิดใช้งานแนะนำ</label>
                            </div>
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

<script>
function editRec(r) {
    document.getElementById('edit_rec_id').value = r.id;
    document.getElementById('edit_car_brand_id').value = r.car_brand_id;
    document.getElementById('edit_car_model').value = r.car_model || '';
    document.getElementById('edit_product_id').value = r.product_id;
    document.getElementById('edit_priority').value = r.priority;
    document.getElementById('edit_is_active').checked = r.is_active == 1;
    new bootstrap.Modal(document.getElementById('editRecModal')).show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

