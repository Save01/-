<?php
require_once __DIR__ . '/config.php';
requireLogin();

$pageTitle = 'ข้อมูลรถของฉัน';
$db = getDB();

$currentUserId = (int)$_SESSION['user_id'];
$isStaffUser = isStaff();

// ถ้าเป็น Staff และมีการส่ง user_id มา ให้ดู/จัดการรถของลูกค้ารายนั้น
$targetUserId = $currentUserId;
$targetUser = null;

if ($isStaffUser && isset($_GET['user_id'])) {
    $targetUserId = (int)$_GET['user_id'];
    $stmtU = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmtU->execute([$targetUserId]);
    $targetUser = $stmtU->fetch();
    if ($targetUser) {
        $pageTitle = 'ข้อมูลรถของ ' . $targetUser['fullname'];
    } else {
        $targetUserId = $currentUserId;
    }
}

// ===== จัดการ POST requests =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. เพิ่มข้อมูลรถ
    if ($action === 'add_vehicle') {
        $ownerId = $isStaffUser && isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : $targetUserId;
        $vehicleType = in_array($_POST['vehicle_type'] ?? '', ['car', 'motorcycle']) ? $_POST['vehicle_type'] : 'car';
        $brandId = (int)($_POST['brand_id'] ?? 0);
        $model = trim($_POST['model'] ?? '');
        $plate = trim($_POST['license_plate'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $color = trim($_POST['color'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($brandId <= 0 || empty($model) || empty($plate)) {
            setFlash('danger', 'กรุณาระบุยี่ห้อ รุ่น และเลขทะเบียนรถให้ครบถ้วน');
        } else {
            $stmt = $db->prepare("
                INSERT INTO member_vehicles (user_id, vehicle_type, brand_id, model, license_plate, year, color, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ownerId, $vehicleType, $brandId, $model, $plate, $year ?: null, $color ?: null, $notes ?: null]);
            setFlash('success', 'เพิ่มข้อมูลรถทะเบียน ' . htmlspecialchars($plate) . ' เรียบร้อยแล้ว');
        }
        $redirectUrl = SITE_URL . 'vehicles.php' . ($isStaffUser && $ownerId !== $currentUserId ? '?user_id=' . $ownerId : '');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 2. แก้ไขข้อมูลรถ
    if ($action === 'edit_vehicle') {
        $vehicleId = (int)$_POST['vehicle_id'];
        $vehicleType = in_array($_POST['vehicle_type'] ?? '', ['car', 'motorcycle']) ? $_POST['vehicle_type'] : 'car';
        $brandId = (int)($_POST['brand_id'] ?? 0);
        $model = trim($_POST['model'] ?? '');
        $plate = trim($_POST['license_plate'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $color = trim($_POST['color'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // ตรวจสอบสิทธิ์ความเป็นเจ้าของรถ หรือเป็น Staff
        $checkStmt = $db->prepare("SELECT user_id FROM member_vehicles WHERE id = ?");
        $checkStmt->execute([$vehicleId]);
        $veh = $checkStmt->fetch();

        if (!$veh || (!$isStaffUser && $veh['user_id'] != $currentUserId)) {
            setFlash('danger', 'ไม่มีสิทธิ์แก้ไขข้อมูลรถคันนี้');
        } else {
            $stmt = $db->prepare("
                UPDATE member_vehicles SET vehicle_type = ?, brand_id = ?, model = ?, license_plate = ?, year = ?, color = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$vehicleType, $brandId, $model, $plate, $year ?: null, $color ?: null, $notes ?: null, $vehicleId]);
            setFlash('success', 'บันทึกการแก้ไขข้อมูลรถสำเร็จ');
        }
        $redirectUrl = SITE_URL . 'vehicles.php' . ($isStaffUser && $targetUser ? '?user_id=' . $targetUserId : '');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 3. ลบข้อมูลรถ
    if ($action === 'delete_vehicle') {
        $vehicleId = (int)$_POST['vehicle_id'];

        $checkStmt = $db->prepare("SELECT user_id, license_plate FROM member_vehicles WHERE id = ?");
        $checkStmt->execute([$vehicleId]);
        $veh = $checkStmt->fetch();

        if (!$veh || (!$isStaffUser && $veh['user_id'] != $currentUserId)) {
            setFlash('danger', 'ไม่มีสิทธิ์ลบข้อมูลรถคันนี้');
        } else {
            $stmt = $db->prepare("DELETE FROM member_vehicles WHERE id = ?");
            $stmt->execute([$vehicleId]);
            setFlash('success', 'ลบข้อมูลรถทะเบียน ' . htmlspecialchars($veh['license_plate']) . ' เรียบร้อยแล้ว');
        }
        $redirectUrl = SITE_URL . 'vehicles.php' . ($isStaffUser && $targetUser ? '?user_id=' . $targetUserId : '');
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// ดึงรายการรถ
$stmtList = $db->prepare("
    SELECT mv.*, cb.name as brand_name, u.fullname as owner_name 
    FROM member_vehicles mv 
    LEFT JOIN car_brands cb ON mv.brand_id = cb.id 
    LEFT JOIN users u ON mv.user_id = u.id 
    WHERE mv.user_id = ? 
    ORDER BY mv.id DESC
");
$stmtList->execute([$targetUserId]);
$vehicles = $stmtList->fetchAll();

// ดึงยี่ห้อรถสำหรับ dropdown
$carBrands = $db->query("SELECT * FROM car_brands ORDER BY type, name")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div>
        <h4 class="mb-1"><i class="bi bi-car-front-fill text-primary me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
        <small class="text-muted">
            <?php if ($targetUser): ?>
                จัดการข้อมูลรถของสมาชิก: <strong><?= htmlspecialchars($targetUser['fullname']) ?></strong> (สามารถเพิ่มได้มากกว่า 1 คัน)
            <?php else: ?>
                บันทึกและจัดการข้อมูลรถของคุณ เพื่อรับคำแนะนำแบตเตอรี่ที่ตรงรุ่นและเช็คประกันได้สะดวกรวดเร็ว
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <?php if ($targetUser): ?>
        <a href="<?= SITE_URL ?>members.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> กลับหน้ารายชื่อสมาชิก
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
            <i class="bi bi-plus-circle-fill me-1"></i> เพิ่มข้อมูลรถใหม่
        </button>
    </div>
</div>

<!-- Alert แนะนำการเพิ่มรถ -->
<div class="alert alert-info border-0 shadow-sm d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-info-circle-fill fs-3 text-info"></i>
        <div>
            <strong>สมาชิกร้านพรชัยแบตเตอรี่สามารถลงทะเบียนรถได้มากกว่า 1 คัน</strong>
            <div class="small text-muted">รองรับทั้งรถยนต์และรถมอเตอร์ไซค์ ระบบจะแนะนำขนาดและรุ่นแบตเตอรี่ที่เหมาะสมกับรถแต่ละคันให้อัตโนมัติ</div>
        </div>
    </div>
    <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill">
        มีรถทั้งหมด <?= count($vehicles) ?> คัน
    </span>
</div>

<!-- การ์ดรายการรถ -->
<?php if (empty($vehicles)): ?>
<div class="card border-0 shadow-sm p-5 text-center my-4">
    <div class="mb-3 text-muted">
        <i class="bi bi-car-front" style="font-size: 4rem;"></i>
    </div>
    <h5>ยังไม่มีข้อมูลรถที่ลงทะเบียน</h5>
    <p class="text-muted">คลิกปุ่มด้านล่างเพื่อเพิ่มข้อมูลรถคันแรกของคุณ</p>
    <div class="mt-2">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
            <i class="bi bi-plus-lg me-1"></i> เพิ่มข้อมูลรถทันที
        </button>
    </div>
</div>
<?php else: ?>
<div class="row g-4 mb-4">
    <?php foreach ($vehicles as $v): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100 rounded-3 overflow-hidden">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge <?= $v['vehicle_type'] === 'motorcycle' ? 'bg-warning text-dark' : 'bg-primary' ?> p-2 rounded-3">
                        <i class="bi <?= $v['vehicle_type'] === 'motorcycle' ? 'bi-bicycle' : 'bi-car-front' ?> fs-5"></i>
                    </span>
                    <div>
                        <h5 class="mb-0 fw-bold"><?= htmlspecialchars($v['brand_name'] ?? 'ไม่ระบุ') ?> <?= htmlspecialchars($v['model']) ?></h5>
                        <small class="text-muted"><?= $v['vehicle_type'] === 'motorcycle' ? 'รถมอเตอร์ไซค์' : 'รถยนต์' ?></small>
                    </div>
                </div>
                <div class="text-end">
                    <span class="badge bg-dark fs-6 px-3 py-2" style="letter-spacing: 1px;">
                        <?= htmlspecialchars($v['license_plate']) ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="p-2 bg-light rounded text-muted small">
                            <span class="d-block text-secondary">สีรถ</span>
                            <strong class="text-dark"><?= htmlspecialchars($v['color'] ?: 'ไม่ได้ระบุ') ?></strong>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 bg-light rounded text-muted small">
                            <span class="d-block text-secondary">ปีจดทะเบียน / ปีรถ</span>
                            <strong class="text-dark"><?= htmlspecialchars($v['year'] ?: 'ไม่ได้ระบุ') ?></strong>
                        </div>
                    </div>
                    <?php if (!empty($v['notes'])): ?>
                    <div class="col-12">
                        <div class="p-2 bg-light rounded text-muted small">
                            <span class="d-block text-secondary">บันทึกเพิ่มเติม</span>
                            <span class="text-dark"><?= htmlspecialchars($v['notes']) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="d-flex gap-2 flex-wrap pt-2 border-top">
                    <!-- ปุ่มสั่งซื้อแบตเตอรี่สำหรับคันนี้ -->
                    <a href="<?= SITE_URL ?>orders.php?vehicle_id=<?= $v['id'] ?>" class="btn btn-sm btn-success flex-grow-1">
                        <i class="bi bi-cart-plus me-1"></i> แบตเตอรี่แนะนำสำหรับคันนี้
                    </a>

                    <!-- ปุ่มแก้ไข -->
                    <button type="button" class="btn btn-sm btn-outline-warning" onclick='editVehicle(<?= json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                        <i class="bi bi-pencil me-1"></i> แก้ไข
                    </button>

                    <!-- ปุ่มลบ -->
                    <form method="POST" action="" onsubmit="return confirm('ยืนยันการลบข้อมูลรถทะเบียน <?= htmlspecialchars($v['license_plate']) ?> หรือไม่?');" class="d-inline">
                        <input type="hidden" name="action" value="delete_vehicle">
                        <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal: เพิ่มข้อมูลรถ -->
<div class="modal fade" id="addVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_vehicle">
                <?php if ($targetUser): ?>
                <input type="hidden" name="owner_id" value="<?= $targetUserId ?>">
                <?php endif; ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-car-front me-2"></i>เพิ่มข้อมูลรถคันใหม่</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ประเภทรถ <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vehicle_type" id="add_type_car" value="car" checked onchange="filterBrandDropdown('car', 'add_brand_id')">
                                <label class="form-check-label" for="add_type_car">
                                    <i class="bi bi-car-front me-1"></i> รถยนต์
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vehicle_type" id="add_type_motorcycle" value="motorcycle" onchange="filterBrandDropdown('motorcycle', 'add_brand_id')">
                                <label class="form-check-label" for="add_type_motorcycle">
                                    <i class="bi bi-bicycle me-1"></i> รถมอเตอร์ไซค์
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ยี่ห้อรถ <span class="text-danger">*</span></label>
                        <select name="brand_id" id="add_brand_id" class="form-select" required>
                            <option value="">-- เลือกยี่ห้อรถ --</option>
                            <?php foreach ($carBrands as $cb): ?>
                            <option value="<?= $cb['id'] ?>" data-type="<?= $cb['type'] ?>"><?= htmlspecialchars($cb['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รุ่นรถ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="model" required placeholder="เช่น Vios, City, Fortuner, D-Max, Wave 110i, PCX">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เลขทะเบียนรถ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="license_plate" required placeholder="เช่น 1กข 1234 กทม หรือ 5กก 9999 สมุทรปราการ">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">ปี ค.ศ. / พ.ศ.</label>
                            <input type="text" class="form-control" name="year" placeholder="เช่น 2021">
                        </div>
                        <div class="col-6">
                            <label class="form-label">สีรถ</label>
                            <input type="text" class="form-control" name="color" placeholder="เช่น ขาว, ดำ, เทา">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุเพิ่มเติม</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="เช่น แบตเตอรี่ลูกเดิมขั้วจม, มีเครื่องเสียง, แบตกี่แอมป์"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>บันทึกข้อมูลรถ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขข้อมูลรถ -->
<div class="modal fade" id="editVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_vehicle">
                <input type="hidden" name="vehicle_id" id="edit_veh_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขข้อมูลรถ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ประเภทรถ <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vehicle_type" id="edit_type_car" value="car">
                                <label class="form-check-label" for="edit_type_car"><i class="bi bi-car-front me-1"></i> รถยนต์</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vehicle_type" id="edit_type_motorcycle" value="motorcycle">
                                <label class="form-check-label" for="edit_type_motorcycle"><i class="bi bi-bicycle me-1"></i> รถมอเตอร์ไซค์</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ยี่ห้อรถ <span class="text-danger">*</span></label>
                        <select name="brand_id" id="edit_brand_id" class="form-select" required>
                            <option value="">-- เลือกยี่ห้อรถ --</option>
                            <?php foreach ($carBrands as $cb): ?>
                            <option value="<?= $cb['id'] ?>"><?= htmlspecialchars($cb['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รุ่นรถ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="model" id="edit_model" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เลขทะเบียนรถ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="license_plate" id="edit_plate" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">ปี ค.ศ. / พ.ศ.</label>
                            <input type="text" class="form-control" name="year" id="edit_year">
                        </div>
                        <div class="col-6">
                            <label class="form-label">สีรถ</label>
                            <input type="text" class="form-control" name="color" id="edit_color">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุเพิ่มเติม</label>
                        <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
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
function editVehicle(v) {
    document.getElementById('edit_veh_id').value = v.id;
    if (v.vehicle_type === 'motorcycle') {
        document.getElementById('edit_type_motorcycle').checked = true;
    } else {
        document.getElementById('edit_type_car').checked = true;
    }
    document.getElementById('edit_brand_id').value = v.brand_id;
    document.getElementById('edit_model').value = v.model;
    document.getElementById('edit_plate').value = v.license_plate;
    document.getElementById('edit_year').value = v.year || '';
    document.getElementById('edit_color').value = v.color || '';
    document.getElementById('edit_notes').value = v.notes || '';
    new bootstrap.Modal(document.getElementById('editVehicleModal')).show();
}

function filterBrandDropdown(type, selectId) {
    const select = document.getElementById(selectId);
    const options = select.querySelectorAll('option');
    options.forEach(opt => {
        if (!opt.value) return;
        const brandType = opt.getAttribute('data-type');
        if (brandType === 'both' || brandType === type) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

