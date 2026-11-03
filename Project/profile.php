<?php
require_once __DIR__ . '/config.php';
requireLogin();

$pageTitle = 'โปรไฟล์ของฉัน';
$db = getDB();
$userId = (int)$_SESSION['user_id'];

// ดึงข้อมูลสมาชิกปัจจุบัน
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    die("ไม่พบข้อมูลผู้ใช้");
}

// บันทึกการแก้ไขข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_profile') {
        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (empty($fullname) || empty($phone)) {
            setFlash('danger', 'กรุณากรอกชื่อ-นามสกุลและเบอร์โทรศัพท์');
        } else {
            // อัพโหลดรูปโปรไฟล์ (ถ้ามี)
            $profileImg = $user['profile_image'];
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = uploadFile($_FILES['profile_image'], 'profiles');
                if ($uploaded) {
                    $profileImg = $uploaded;
                    $_SESSION['profile_image'] = $profileImg;
                }
            }

            $stmtUp = $db->prepare("UPDATE users SET fullname = ?, phone = ?, email = ?, address = ?, profile_image = ? WHERE id = ?");
            $stmtUp->execute([$fullname, $phone, $email ?: null, $address ?: null, $profileImg, $userId]);

            $_SESSION['fullname'] = $fullname;
            setFlash('success', 'บันทึกข้อมูลโปรไฟล์เรียบร้อยแล้ว');
            header('Location: ' . SITE_URL . 'profile.php');
            exit;
        }
    }

    if ($action === 'change_password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (!password_verify($oldPass, $user['password'])) {
            setFlash('danger', 'รหัสผ่านเดิมไม่ถูกต้อง');
        } elseif (strlen($newPass) < 6) {
            setFlash('danger', 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
        } elseif ($newPass !== $confirmPass) {
            setFlash('danger', 'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน');
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmtUp = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmtUp->execute([$newHash, $userId]);
            setFlash('success', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
            header('Location: ' . SITE_URL . 'profile.php');
            exit;
        }
    }
}

// ดึงรถของฉัน
$stmtV = $db->prepare("SELECT mv.*, cb.name as brand_name FROM member_vehicles mv LEFT JOIN car_brands cb ON mv.brand_id = cb.id WHERE mv.user_id = ?");
$stmtV->execute([$userId]);
$myVehicles = $stmtV->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div>
        <h4 class="mb-1"><i class="bi bi-person-circle text-primary me-2"></i>โปรไฟล์สมาชิก</h4>
        <small class="text-muted">จัดการข้อมูลส่วนตัว เบอร์โทรศัพท์ ที่อยู่สำหรับจัดส่ง และความปลอดภัย</small>
    </div>
</div>

<div class="row g-4">
    <!-- คอลัมน์ซ้าย: บัตรโปรไฟล์ & ข้อมูลรถ -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3 mb-4 text-center p-4">
            <div class="position-relative d-inline-block mx-auto mb-3">
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="<?= SITE_URL . htmlspecialchars($user['profile_image']) ?>" class="rounded-circle border" style="width: 100px; height: 100px; object-fit: cover;">
                <?php else: ?>
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold mx-auto fs-1" style="width: 100px; height: 100px;">
                        <?= mb_substr($user['fullname'], 0, 1, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>
            <h5 class="fw-bold mb-1"><?= htmlspecialchars($user['fullname']) ?></h5>
            <div class="text-muted small mb-2">Username: <code><?= htmlspecialchars($user['username']) ?></code></div>
            <div>
                <?php if ($user['role'] === 'admin'): ?>
                    <span class="badge bg-danger rounded-pill px-3 py-1"><i class="bi bi-shield-lock me-1"></i>ผู้ดูแลระบบ</span>
                <?php elseif ($user['role'] === 'staff'): ?>
                    <span class="badge bg-info text-dark rounded-pill px-3 py-1"><i class="bi bi-person-gear me-1"></i>พนักงาน</span>
                <?php else: ?>
                    <span class="badge bg-success rounded-pill px-3 py-1"><i class="bi bi-person-check me-1"></i>สมาชิกลูกค้า</span>
                <?php endif; ?>
            </div>
            <div class="text-muted small mt-3">
                วันที่สมัครสมาชิก: <?= date('d/m/Y', strtotime($user['created_at'])) ?>
            </div>
        </div>

        <!-- กล่องข้อมูลรถของฉัน -->
        <div class="card border-0 shadow-sm rounded-3 p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-car-front-fill me-1"></i>รถที่ลงทะเบียน (<?= count($myVehicles) ?> คัน)</h6>
                <a href="<?= SITE_URL ?>vehicles.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> จัดการรถ</a>
            </div>
            <?php if (empty($myVehicles)): ?>
                <p class="text-muted small mb-0">ยังไม่มีรถที่ลงทะเบียน <a href="<?= SITE_URL ?>vehicles.php">เพิ่มข้อมูลรถตอนนี้</a></p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($myVehicles as $v): ?>
                    <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= htmlspecialchars($v['brand_name'] ?? '-') ?> <?= htmlspecialchars($v['model']) ?></strong>
                            <small class="text-muted d-block"><?= $v['vehicle_type'] === 'motorcycle' ? 'มอเตอร์ไซค์' : 'รถยนต์' ?></small>
                        </div>
                        <span class="badge bg-dark"><?= htmlspecialchars($v['license_plate']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- คอลัมน์ขวา: ฟอร์มแก้ไขข้อมูล & เปลี่ยนรหัสผ่าน -->
    <div class="col-lg-8">
        <!-- การ์ด 1: ข้อมูลส่วนตัว -->
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>แก้ไขข้อมูลส่วนตัว</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="fullname" value="<?= htmlspecialchars($user['fullname']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">อีเมล</label>
                            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เปลี่ยนรูปโปรไฟล์</label>
                            <input type="file" class="form-control" name="profile_image" accept="image/*">
                        </div>
                        <div class="col-12">
                            <label class="form-label">ที่อยู่จัดส่ง / ติดต่อ</label>
                            <textarea class="form-control" name="address" rows="3" placeholder="บ้านเลขที่, ถนน, ตำบล, อำเภอ, จังหวัด, รหัสไปรษณีย์"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                            <small class="text-muted">ที่อยู่นี้จะถูกใช้เป็นค่าเริ่มต้นเมื่อสั่งซื้อสินค้าเพื่อจัดส่ง</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>บันทึกข้อมูลส่วนตัว</button>
                </form>
            </div>
        </div>

        <!-- การ์ด 2: เปลี่ยนรหัสผ่าน -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold"><i class="bi bi-key-fill text-warning me-2"></i>เปลี่ยนรหัสผ่าน</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">รหัสผ่านปัจจุบัน <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="old_password" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">รหัสผ่านใหม่ <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="new_password" required minlength="6">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ยืนยันรหัสผ่านใหม่ <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="confirm_password" required minlength="6">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-shield-lock me-1"></i>อัพเดทรหัสผ่าน</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

