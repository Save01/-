<?php
require_once 'config.php';
requireLogin();
$pageTitle = 'เคลมสินค้า';

$db = getDB();
$userId = $_SESSION['user_id'];
$isStaffUser = isStaff();

$statusLabels = [
    'pending' => ['รอดำเนินการ', 'bg-warning text-dark', 'bi-clock'],
    'processing' => ['กำลังดำเนินการ', 'bg-info text-white', 'bi-gear'],
    'approved' => ['อนุมัติ', 'bg-success', 'bi-check-circle'],
    'rejected' => ['ปฏิเสธ', 'bg-danger', 'bi-x-circle'],
    'completed' => ['เสร็จสิ้น', 'bg-success', 'bi-check2-all'],
];

$claimTypeLabels = [
    'replace' => 'เปลี่ยนใหม่',
    'send_company' => 'ส่งบริษัท',
];

$statusFlow = [
    'pending' => ['processing', 'rejected'],
    'processing' => ['approved', 'rejected'],
    'approved' => ['completed'],
    'rejected' => [],
    'completed' => [],
];

// ===== ลูกค้า: ส่งเคลม =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_claim' && !$isStaffUser) {
    $warrantyId = (int)$_POST['warranty_id'];
    $claimType = $_POST['claim_type'];
    $problemDescription = trim($_POST['problem_description']);
    
    // ตรวจสอบว่า warranty เป็นของลูกค้าและ active
    $stmtCheck = $db->prepare("SELECT w.*, p.name AS product_name FROM warranties w JOIN products p ON w.product_id = p.id WHERE w.id = ? AND w.customer_id = ? AND w.status = 'active' AND w.warranty_end_date >= CURDATE()");
    $stmtCheck->execute([$warrantyId, $userId]);
    $warranty = $stmtCheck->fetch();
    
    if (!$warranty) {
        setFlash('danger', 'ไม่พบข้อมูลประกันหรือประกันหมดอายุแล้ว');
        header('Location: claims.php');
        exit;
    }
    
    if (!in_array($claimType, ['replace', 'send_company'])) {
        setFlash('danger', 'ประเภทเคลมไม่ถูกต้อง');
        header('Location: claims.php');
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // สร้างเคลม
        $stmtClaim = $db->prepare("
            INSERT INTO claims (warranty_id, customer_id, claim_type, problem_description, claim_date, status)
            VALUES (?, ?, ?, ?, CURDATE(), 'pending')
        ");
        $stmtClaim->execute([$warrantyId, $userId, $claimType, $problemDescription]);
        $claimId = $db->lastInsertId();
        
        // อัพโหลดรูปภาพ
        if (isset($_FILES['claim_images'])) {
            $files = $_FILES['claim_images'];
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $fileData = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i],
                    ];
                    $imagePath = uploadFile($fileData, 'claims');
                    if ($imagePath) {
                        $stmtImg = $db->prepare("INSERT INTO claim_images (claim_id, image_path) VALUES (?, ?)");
                        $stmtImg->execute([$claimId, $imagePath]);
                    }
                }
            }
        }
        
        // แจ้งเตือน admin/staff
        $stmtStaff = $db->query("SELECT id FROM users WHERE role IN ('admin', 'staff')");
        $staffUsers = $stmtStaff->fetchAll();
        foreach ($staffUsers as $staff) {
            createNotification(
                $staff['id'],
                'เคลมสินค้าใหม่',
                'ลูกค้า ' . htmlspecialchars($_SESSION['fullname']) . ' แจ้งเคลมสินค้า: ' . $warranty['product_name'],
                'claim',
                'claims.php?view=' . $claimId
            );
        }
        
        $db->commit();
        setFlash('success', 'ส่งเรื่องเคลมสำเร็จ กรุณารอการตรวจสอบจากทางร้าน');
        header('Location: claims.php');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        header('Location: claims.php');
        exit;
    }
}

// ===== Staff: อัพเดทสถานะเคลม =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_claim_status' && $isStaffUser) {
    $claimId = (int)$_POST['claim_id'];
    $newStatus = $_POST['new_status'];
    $resolution = trim($_POST['resolution'] ?? '');
    
    $stmtCheck = $db->prepare("SELECT c.*, w.customer_id FROM claims c JOIN warranties w ON c.warranty_id = w.id WHERE c.id = ?");
    $stmtCheck->execute([$claimId]);
    $claim = $stmtCheck->fetch();
    
    if ($claim && in_array($newStatus, $statusFlow[$claim['status']] ?? [])) {
        $stmtUpdate = $db->prepare("UPDATE claims SET status = ?, resolution = CASE WHEN ? != '' THEN ? ELSE resolution END WHERE id = ?");
        $stmtUpdate->execute([$newStatus, $resolution, $resolution, $claimId]);
        
        // ถ้าอนุมัติ ให้อัพเดท warranty เป็น claimed
        if ($newStatus === 'approved') {
            $stmtW = $db->prepare("UPDATE warranties SET status = 'claimed' WHERE id = ?");
            $stmtW->execute([$claim['warranty_id']]);
        }
        
        // แจ้งเตือนลูกค้า
        $statusText = $statusLabels[$newStatus][0] ?? $newStatus;
        createNotification(
            $claim['customer_id'],
            'อัพเดทเคลมสินค้า',
            'การเคลมของคุณได้ถูกอัพเดทเป็น: ' . $statusText . ($resolution ? ' - ' . $resolution : ''),
            'claim',
            'claims.php'
        );
        
        setFlash('success', 'อัพเดทสถานะเคลมสำเร็จ');
    } else {
        setFlash('danger', 'ไม่สามารถอัพเดทสถานะได้');
    }
    header('Location: claims.php' . (isset($_POST['from_view']) ? '?view=' . $claimId : ''));
    exit;
}

// ===== ดูรายละเอียดเคลม =====
if (isset($_GET['view']) && $isStaffUser) {
    $claimId = (int)$_GET['view'];
    $stmtClaim = $db->prepare("
        SELECT c.*, w.serial_number, w.purchase_date, w.warranty_end_date, w.warranty_months,
            p.name AS product_name, bb.name AS battery_brand, p.image AS product_image,
            u.fullname AS customer_name, u.phone AS customer_phone, u.email AS customer_email,
            mv.license_plate, mv.model AS vehicle_model, cb.name AS vehicle_brand
        FROM claims c
        JOIN warranties w ON c.warranty_id = w.id
        JOIN products p ON w.product_id = p.id
        LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
        JOIN users u ON c.customer_id = u.id
        LEFT JOIN member_vehicles mv ON w.vehicle_id = mv.id
        LEFT JOIN car_brands cb ON mv.brand_id = cb.id
        WHERE c.id = ?
    ");
    $stmtClaim->execute([$claimId]);
    $claim = $stmtClaim->fetch();
    
    if (!$claim) {
        setFlash('danger', 'ไม่พบข้อมูลเคลม');
        header('Location: claims.php');
        exit;
    }
    
    // ดึงรูปภาพ
    $stmtImages = $db->prepare("SELECT * FROM claim_images WHERE claim_id = ?");
    $stmtImages->execute([$claimId]);
    $claimImages = $stmtImages->fetchAll();
    
    $st = $statusLabels[$claim['status']] ?? ['ไม่ทราบ', 'bg-secondary', 'bi-question'];
    $nextStatuses = $statusFlow[$claim['status']] ?? [];
    
    require_once 'header.php';
    ?>
    
    <div class="page-header">
        <h4><i class="bi bi-exclamation-triangle"></i> รายละเอียดเคลม #<?= $claim['id'] ?></h4>
        <a href="claims.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กลับ</a>
    </div>
    
    <div class="row g-4">
        <div class="col-lg-8">
            <!-- สถานะ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h6 class="mb-1">สถานะเคลม</h6>
                            <span class="badge <?= $st[1] ?> badge-status fs-6">
                                <i class="bi <?= $st[2] ?>"></i> <?= $st[0] ?>
                            </span>
                        </div>
                        <?php if (!empty($nextStatuses)): ?>
                        <div>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                                <i class="bi bi-arrow-right-circle"></i> อัพเดทสถานะ
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- รายละเอียดปัญหา -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-chat-text"></i> รายละเอียดปัญหา</h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <span class="badge bg-primary"><?= $claimTypeLabels[$claim['claim_type']] ?? $claim['claim_type'] ?></span>
                        <small class="text-muted ms-2">วันที่แจ้ง: <?= date('d/m/Y', strtotime($claim['claim_date'])) ?></small>
                    </div>
                    <p><?= nl2br(htmlspecialchars($claim['problem_description'])) ?></p>
                    
                    <?php if ($claim['resolution']): ?>
                    <div class="alert alert-info mt-3">
                        <strong><i class="bi bi-info-circle"></i> หมายเหตุจากร้าน:</strong><br>
                        <?= nl2br(htmlspecialchars($claim['resolution'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- รูปภาพ -->
            <?php if (!empty($claimImages)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-images"></i> รูปภาพประกอบ (<?= count($claimImages) ?> รูป)</h6>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <?php foreach ($claimImages as $img): ?>
                        <div class="col-4 col-md-3">
                            <a href="<?= SITE_URL . htmlspecialchars($img['image_path']) ?>" target="_blank">
                                <img src="<?= SITE_URL . htmlspecialchars($img['image_path']) ?>" class="img-fluid rounded" style="height: 150px; width: 100%; object-fit: cover;">
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="col-lg-4">
            <!-- ข้อมูลสินค้า -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-box-seam"></i> ข้อมูลสินค้า</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= htmlspecialchars($claim['product_name']) ?></strong></p>
                    <p class="mb-1"><small class="text-muted"><?= htmlspecialchars($claim['battery_brand'] ?? '') ?></small></p>
                    <p class="mb-1"><i class="bi bi-upc"></i> S/N: <?= htmlspecialchars($claim['serial_number'] ?: '-') ?></p>
                    <p class="mb-1"><i class="bi bi-calendar"></i> วันที่ซื้อ: <?= date('d/m/Y', strtotime($claim['purchase_date'])) ?></p>
                    <p class="mb-0"><i class="bi bi-shield-check"></i> หมดประกัน: <?= date('d/m/Y', strtotime($claim['warranty_end_date'])) ?></p>
                </div>
            </div>
            
            <!-- ข้อมูลลูกค้า -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-person"></i> ข้อมูลลูกค้า</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= htmlspecialchars($claim['customer_name']) ?></strong></p>
                    <p class="mb-1"><i class="bi bi-telephone"></i> <?= htmlspecialchars($claim['customer_phone']) ?></p>
                    <?php if ($claim['customer_email']): ?>
                    <p class="mb-1"><i class="bi bi-envelope"></i> <?= htmlspecialchars($claim['customer_email']) ?></p>
                    <?php endif; ?>
                    <?php if ($claim['license_plate']): ?>
                    <p class="mb-0"><i class="bi bi-car-front"></i> <?= htmlspecialchars($claim['vehicle_brand'] . ' ' . $claim['vehicle_model']) ?> (<?= htmlspecialchars($claim['license_plate']) ?>)</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- เวลา -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-clock"></i> เวลา</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><small class="text-muted">สร้างเมื่อ:</small><br><?= date('d/m/Y H:i:s', strtotime($claim['created_at'])) ?></p>
                    <p class="mb-0"><small class="text-muted">อัพเดทล่าสุด:</small><br><?= date('d/m/Y H:i:s', strtotime($claim['updated_at'])) ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!empty($nextStatuses)): ?>
    <!-- Modal อัพเดทสถานะ -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_claim_status">
                    <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>">
                    <input type="hidden" name="from_view" value="1">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-arrow-right-circle"></i> อัพเดทสถานะเคลม</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">เปลี่ยนสถานะเป็น <span class="text-danger">*</span></label>
                            <select name="new_status" class="form-select" required>
                                <?php foreach ($nextStatuses as $ns): ?>
                                <?php $nsl = $statusLabels[$ns] ?? ['', '']; ?>
                                <option value="<?= $ns ?>"><?= $nsl[0] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุ / ผลการตรวจสอบ</label>
                            <textarea name="resolution" class="form-control" rows="3" placeholder="ระบุหมายเหตุหรือผลการตรวจสอบ..."><?= htmlspecialchars($claim['resolution'] ?? '') ?></textarea>
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
    <?php endif; ?>
    
    <?php
    // End detail view
} else {
    // ===== รายการเคลม =====
    
    // Filters (staff only)
    $filterStatus = $_GET['filter_status'] ?? '';
    $filterSearch = trim($_GET['filter_search'] ?? '');
    
    if ($isStaffUser) {
        $where = [];
        $params = [];
        if ($filterStatus) {
            $where[] = "c.status = ?";
            $params[] = $filterStatus;
        }
        if ($filterSearch) {
            $where[] = "(u.fullname LIKE ? OR p.name LIKE ?)";
            $params[] = "%{$filterSearch}%";
            $params[] = "%{$filterSearch}%";
        }
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $stmtClaims = $db->prepare("
            SELECT c.*, w.serial_number, p.name AS product_name, bb.name AS battery_brand,
                u.fullname AS customer_name, u.phone AS customer_phone,
                (SELECT COUNT(*) FROM claim_images ci WHERE ci.claim_id = c.id) AS image_count
            FROM claims c
            JOIN warranties w ON c.warranty_id = w.id
            JOIN products p ON w.product_id = p.id
            LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
            JOIN users u ON c.customer_id = u.id
            {$whereClause}
            ORDER BY c.created_at DESC
        ");
        $stmtClaims->execute($params);
    } else {
        $stmtClaims = $db->prepare("
            SELECT c.*, w.serial_number, p.name AS product_name, bb.name AS battery_brand,
                u.fullname AS customer_name,
                (SELECT COUNT(*) FROM claim_images ci WHERE ci.claim_id = c.id) AS image_count
            FROM claims c
            JOIN warranties w ON c.warranty_id = w.id
            JOIN products p ON w.product_id = p.id
            LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
            JOIN users u ON c.customer_id = u.id
            WHERE c.customer_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmtClaims->execute([$userId]);
    }
    $claims = $stmtClaims->fetchAll();
    
    // ดึงประกันที่ active สำหรับลูกค้า
    if (!$isStaffUser) {
        $stmtActiveWarranties = $db->prepare("
            SELECT w.*, p.name AS product_name, bb.name AS battery_brand,
                mv.license_plate, mv.model AS vehicle_model, cb.name AS vehicle_brand
            FROM warranties w
            JOIN products p ON w.product_id = p.id
            LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
            LEFT JOIN member_vehicles mv ON w.vehicle_id = mv.id
            LEFT JOIN car_brands cb ON mv.brand_id = cb.id
            WHERE w.customer_id = ? AND w.status = 'active' AND w.warranty_end_date >= CURDATE()
            ORDER BY w.warranty_end_date ASC
        ");
        $stmtActiveWarranties->execute([$userId]);
        $activeWarranties = $stmtActiveWarranties->fetchAll();
    }
    
    require_once 'header.php';
    ?>
    
    <div class="page-header">
        <h4><i class="bi bi-exclamation-triangle"></i> เคลมสินค้า</h4>
        <?php if (!$isStaffUser): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#submitClaimModal" <?= empty($activeWarranties) ? 'disabled' : '' ?>>
            <i class="bi bi-plus-circle"></i> แจ้งเคลม
        </button>
        <?php endif; ?>
    </div>
    
    <?php if ($isStaffUser): ?>
    <!-- ตัวกรอง -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">สถานะ</label>
                    <select name="filter_status" class="form-select">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($statusLabels as $key => $val): ?>
                        <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= $val[0] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">ค้นหา</label>
                    <input type="text" name="filter_search" class="form-control" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="ชื่อลูกค้า, สินค้า...">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> ค้นหา</button>
                    <a href="claims.php" class="btn btn-outline-secondary">ล้าง</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- รายการเคลม -->
    <?php if (empty($claims)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-check-circle display-1 text-success"></i>
            <h5 class="mt-3">ไม่มีรายการเคลม</h5>
            <p class="text-muted"><?= $isStaffUser ? 'ยังไม่มีการแจ้งเคลมสินค้า' : 'คุณยังไม่มีรายการเคลม' ?></p>
        </div>
    </div>
    <?php else: ?>
    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($isStaffUser): ?><th>ลูกค้า</th><?php endif; ?>
                        <th>สินค้า</th>
                        <th>ประเภท</th>
                        <th>ปัญหา</th>
                        <th>รูปภาพ</th>
                        <th class="text-center">สถานะ</th>
                        <th>วันที่</th>
                        <?php if ($isStaffUser): ?><th class="text-center">จัดการ</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($claims as $c): ?>
                    <?php $cst = $statusLabels[$c['status']] ?? ['ไม่ทราบ', 'bg-secondary', '']; ?>
                    <tr>
                        <td><?= $c['id'] ?></td>
                        <?php if ($isStaffUser): ?>
                        <td>
                            <?= htmlspecialchars($c['customer_name']) ?><br>
                            <small class="text-muted"><?= htmlspecialchars($c['customer_phone'] ?? '') ?></small>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?= htmlspecialchars($c['product_name']) ?><br>
                            <small class="text-muted">S/N: <?= htmlspecialchars($c['serial_number'] ?: '-') ?></small>
                        </td>
                        <td><span class="badge bg-primary"><?= $claimTypeLabels[$c['claim_type']] ?? $c['claim_type'] ?></span></td>
                        <td><small><?= mb_strlen($c['problem_description']) > 50 ? htmlspecialchars(mb_substr($c['problem_description'], 0, 50)) . '...' : htmlspecialchars($c['problem_description']) ?></small></td>
                        <td>
                            <?php if ($c['image_count'] > 0): ?>
                            <span class="badge bg-secondary"><i class="bi bi-images"></i> <?= $c['image_count'] ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $cst[1] ?> badge-status">
                                <i class="bi <?= $cst[2] ?>"></i> <?= $cst[0] ?>
                            </span>
                        </td>
                        <td><small><?= date('d/m/Y', strtotime($c['claim_date'])) ?></small></td>
                        <?php if ($isStaffUser): ?>
                        <td class="text-center">
                            <a href="claims.php?view=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i> ดู
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!$isStaffUser && !empty($activeWarranties)): ?>
    <!-- Modal แจ้งเคลม -->
    <div class="modal fade" id="submitClaimModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit_claim">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> แจ้งเคลมสินค้า</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">เลือกประกัน <span class="text-danger">*</span></label>
                                <select name="warranty_id" class="form-select" required>
                                    <option value="">-- เลือกประกันที่ต้องการเคลม --</option>
                                    <?php foreach ($activeWarranties as $aw): ?>
                                    <option value="<?= $aw['id'] ?>">
                                        <?= htmlspecialchars($aw['product_name']) ?> (<?= htmlspecialchars($aw['battery_brand'] ?? '') ?>) 
                                        - S/N: <?= htmlspecialchars($aw['serial_number'] ?: '-') ?>
                                        <?php if ($aw['license_plate']): ?>
                                        | <?= htmlspecialchars($aw['vehicle_brand'] . ' ' . $aw['vehicle_model']) ?> (<?= htmlspecialchars($aw['license_plate']) ?>)
                                        <?php endif; ?>
                                        - หมดประกัน <?= date('d/m/Y', strtotime($aw['warranty_end_date'])) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">ประเภทเคลม <span class="text-danger">*</span></label>
                                <select name="claim_type" class="form-select" required>
                                    <option value="">-- เลือกประเภท --</option>
                                    <option value="replace">เปลี่ยนใหม่</option>
                                    <option value="send_company">ส่งบริษัท</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">รายละเอียดปัญหา <span class="text-danger">*</span></label>
                                <textarea name="problem_description" class="form-control" rows="4" placeholder="อธิบายอาการ/ปัญหาที่พบ..." required></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label"><i class="bi bi-camera"></i> รูปภาพประกอบ</label>
                                <input type="file" class="form-control" name="claim_images[]" multiple accept="image/*" capture="environment" onchange="previewClaimImages(this)">
                                <small class="text-muted">สามารถถ่ายรูปหรือเลือกรูปได้หลายรูป</small>
                                <div id="claimImagePreviews" class="d-flex gap-2 mt-2 flex-wrap"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> ส่งเรื่องเคลม</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function previewClaimImages(input) {
        const container = document.getElementById('claimImagePreviews');
        container.innerHTML = '';
        if (input.files) {
            Array.from(input.files).forEach(file => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'rounded';
                    img.style.cssText = 'width: 80px; height: 80px; object-fit: cover;';
                    container.appendChild(img);
                };
                reader.readAsDataURL(file);
            });
        }
    }

    // Auto-select warranty and open modal if warranty_id in query
    window.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const wId = urlParams.get('warranty_id');
        if (wId) {
            const select = document.querySelector('select[name="warranty_id"]');
            if (select) {
                select.value = wId;
                const modalEl = document.getElementById('submitClaimModal');
                if (modalEl) {
                    new bootstrap.Modal(modalEl).show();
                }
            }
        }
    });
    </script>
    <?php endif; ?>
    
    <?php
}
?>

<?php require_once 'footer.php'; ?>
