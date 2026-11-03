<?php
require_once __DIR__ . '/config.php';
requireStaff();
$pageTitle = 'จัดการสมาชิก';

$db = getDB();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. เพิ่มสมาชิกใหม่
    if ($action === 'add_member') {
        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['admin', 'staff', 'customer']) ? $_POST['role'] : 'customer';

        if (empty($fullname) || empty($phone) || empty($username) || empty($password)) {
            setFlash('danger', 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
        } else {
            // เช็ค username ซ้ำ
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                setFlash('danger', 'ชื่อผู้ใช้ "' . htmlspecialchars($username) . '" มีอยู่ในระบบแล้ว');
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password, fullname, phone, email, address, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashedPassword, $fullname, $phone, $email ?: null, $address ?: null, $role]);
                $newUserId = $db->lastInsertId();

                // ถ้าระบุข้อมูลรถมาด้วย
                if (!empty($_POST['vehicle_brand_id']) && !empty($_POST['vehicle_model']) && !empty($_POST['license_plate'])) {
                    $vType = in_array($_POST['vehicle_type'] ?? '', ['car', 'motorcycle']) ? $_POST['vehicle_type'] : 'car';
                    $stmtV = $db->prepare("INSERT INTO member_vehicles (user_id, vehicle_type, brand_id, model, license_plate, year, color) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmtV->execute([
                        $newUserId,
                        $vType,
                        (int)$_POST['vehicle_brand_id'],
                        trim($_POST['vehicle_model']),
                        trim($_POST['license_plate']),
                        trim($_POST['vehicle_year'] ?? ''),
                        trim($_POST['vehicle_color'] ?? '')
                    ]);
                }

                setFlash('success', 'เพิ่มสมาชิก "' . htmlspecialchars($fullname) . '" เรียบร้อยแล้ว');
            }
        }
        header('Location: ' . SITE_URL . 'members.php');
        exit;
    }

    // 2. แก้ไขข้อมูลสมาชิก
    if ($action === 'edit_member') {
        $userId = (int)$_POST['user_id'];
        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['admin', 'staff', 'customer']) ? $_POST['role'] : 'customer';
        $newPassword = $_POST['new_password'] ?? '';

        if (empty($fullname) || empty($phone)) {
            setFlash('danger', 'กรุณากรอกชื่อและเบอร์โทรศัพท์');
        } else {
            if (!empty($newPassword)) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET fullname = ?, phone = ?, email = ?, address = ?, role = ?, password = ? WHERE id = ?");
                $stmt->execute([$fullname, $phone, $email ?: null, $address ?: null, $role, $hash, $userId]);
            } else {
                $stmt = $db->prepare("UPDATE users SET fullname = ?, phone = ?, email = ?, address = ?, role = ? WHERE id = ?");
                $stmt->execute([$fullname, $phone, $email ?: null, $address ?: null, $role, $userId]);
            }
            setFlash('success', 'บันทึกการแก้ไขข้อมูลสมาชิกสำเร็จ');
        }
        header('Location: ' . SITE_URL . 'members.php');
        exit;
    }

    // 3. ลบสมาชิก
    if ($action === 'delete_member') {
        requireAdmin(); // เฉพาะ admin ลบได้
        $userId = (int)$_POST['user_id'];
        if ($userId === (int)$_SESSION['user_id']) {
            setFlash('danger', 'ไม่สามารถลบบัญชีของตัวเองได้');
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            setFlash('success', 'ลบสมาชิกเรียบร้อยแล้ว');
        }
        header('Location: ' . SITE_URL . 'members.php');
        exit;
    }
}

// ค้นหาและกรอง
$search = trim($_GET['search'] ?? '');
$filterRole = $_GET['role'] ?? '';

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(u.fullname LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterRole !== '') {
    $where[] = "u.role = ?";
    $params[] = $filterRole;
}

$whereClause = implode(' AND ', $where);

// ดึงรายชื่อสมาชิกพร้อมจำนวนรถ
$sql = "
    SELECT u.*, COUNT(mv.id) as vehicle_count 
    FROM users u 
    LEFT JOIN member_vehicles mv ON u.id = mv.user_id 
    WHERE {$whereClause} 
    GROUP BY u.id 
    ORDER BY u.created_at DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// ยี่ห้อรถสำหรับ dropdown modal เพิ่มสมาชิก
$carBrands = $db->query("SELECT * FROM car_brands ORDER BY type, name")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div>
        <h4 class="mb-1"><i class="bi bi-people-fill text-primary me-2"></i>ระบบจัดการสมาชิก</h4>
        <small class="text-muted">เพิ่ม ลบ แก้ไข บันทึกข้อมูลสมาชิก และดูรายละเอียดรถของสมาชิก</small>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
        <i class="bi bi-person-plus-fill me-1"></i> เพิ่มสมาชิกใหม่
    </button>
</div>

<!-- สถิติสมาชิก -->
<div class="row g-3 mb-4">
    <?php
    $totalCust = $db->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
    $totalStaff = $db->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetchColumn();
    $totalAdmin = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $totalVehicles = $db->query("SELECT COUNT(*) FROM member_vehicles")->fetchColumn();
    ?>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary text-white">
                <i class="bi bi-people"></i>
            </div>
            <div>
                <small class="text-muted">ลูกค้าทั้งหมด</small>
                <h4 class="mb-0 fw-bold"><?= number_format($totalCust) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card d-flex align-items-center gap-3">
            <div class="stat-icon bg-info text-white">
                <i class="bi bi-person-badge"></i>
            </div>
            <div>
                <small class="text-muted">พนักงาน / แอดมิน</small>
                <h4 class="mb-0 fw-bold"><?= number_format($totalStaff + $totalAdmin) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card d-flex align-items-center gap-3">
            <div class="stat-icon bg-warning text-white">
                <i class="bi bi-car-front-fill"></i>
            </div>
            <div>
                <small class="text-muted">รถที่ลงทะเบียน</small>
                <h4 class="mb-0 fw-bold"><?= number_format($totalVehicles) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card d-flex align-items-center gap-3">
            <div class="stat-icon bg-success text-white">
                <i class="bi bi-shield-check"></i>
            </div>
            <div>
                <small class="text-muted">สมาชิกล่าสุด</small>
                <h4 class="mb-0 fw-bold"><?= count($members) ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- ช่องค้นหา & กรอง -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-md-6 col-lg-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control border-start-0" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาตามชื่อ, เบอร์โทร, อีเมล, ชื่อผู้ใช้...">
                </div>
            </div>
            <div class="col-md-3 col-lg-3">
                <select name="role" class="form-select">
                    <option value="">ทุกระดับสิทธิ์</option>
                    <option value="customer" <?= $filterRole === 'customer' ? 'selected' : '' ?>>ลูกค้า (Customer)</option>
                    <option value="staff" <?= $filterRole === 'staff' ? 'selected' : '' ?>>พนักงาน (Staff)</option>
                    <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>ผู้ดูแลระบบ (Admin)</option>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <button type="submit" class="btn btn-secondary w-100">
                    <i class="bi bi-funnel me-1"></i> กรองข้อมูล
                </button>
            </div>
            <?php if ($search || $filterRole): ?>
            <div class="col-auto">
                <a href="<?= SITE_URL ?>members.php" class="btn btn-link text-decoration-none text-muted">ล้างตัวกรอง</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ตารางรายชื่อสมาชิก -->
<div class="table-container shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>สมาชิก</th>
                    <th>ชื่อผู้ใช้</th>
                    <th>เบอร์โทรศัพท์ / อีเมล</th>
                    <th>ระดับสิทธิ์</th>
                    <th>จำนวนรถ</th>
                    <th>วันที่สมัคร</th>
                    <th class="text-center" style="width: 140px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                <tr>
                    <td colspan="8" class="text-center py-4 text-muted">
                        <i class="bi bi-person-x fs-1 d-block mb-2"></i>
                        ไม่พบข้อมูลสมาชิกตามเงื่อนไข
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($members as $idx => $m): ?>
                <tr>
                    <td class="text-muted"><?= $idx + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-primary fw-bold" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                <?= mb_substr($m['fullname'], 0, 1, 'UTF-8') ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($m['fullname']) ?></div>
                                <?php if (!empty($m['address'])): ?>
                                <small class="text-muted d-block text-truncate" style="max-width: 200px;">
                                    <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($m['address']) ?>
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><code><?= htmlspecialchars($m['username']) ?></code></td>
                    <td>
                        <div><i class="bi bi-telephone text-primary me-1"></i><?= htmlspecialchars($m['phone']) ?></div>
                        <?php if ($m['email']): ?>
                        <small class="text-muted"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($m['email']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($m['role'] === 'admin'): ?>
                            <span class="badge bg-danger rounded-pill"><i class="bi bi-shield-lock me-1"></i>ผู้ดูแลระบบ</span>
                        <?php elseif ($m['role'] === 'staff'): ?>
                            <span class="badge bg-info text-dark rounded-pill"><i class="bi bi-person-gear me-1"></i>พนักงาน</span>
                        <?php else: ?>
                            <span class="badge bg-success rounded-pill"><i class="bi bi-person-check me-1"></i>ลูกค้า</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= SITE_URL ?>vehicles.php?user_id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill py-0 px-2" title="ดูข้อมูลรถ">
                            <i class="bi bi-car-front me-1"></i><?= $m['vehicle_count'] ?> คัน
                        </a>
                    </td>
                    <td><small class="text-muted"><?= date('d/m/Y', strtotime($m['created_at'])) ?></small></td>
                    <td>
                        <div class="d-flex justify-content-center gap-1">
                            <!-- ปุ่มดูรถ / รายละเอียด -->
                            <button type="button" class="btn btn-sm btn-outline-info" title="ดูรายละเอียดและรถ" onclick='viewMemberDetails(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                <i class="bi bi-eye"></i>
                            </button>

                            <!-- ปุ่มแก้ไข -->
                            <button type="button" class="btn btn-sm btn-outline-warning" title="แก้ไขข้อมูล" onclick='editMember(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>

                            <?php if (isAdmin() && $m['id'] != $_SESSION['user_id']): ?>
                            <!-- ปุ่มลบ -->
                            <form method="POST" action="" onsubmit="return confirm('ยืนยันลบสมาชิก <?= htmlspecialchars($m['fullname']) ?> หรือไม่?');" class="d-inline">
                                <input type="hidden" name="action" value="delete_member">
                                <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบสมาชิก">
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

<!-- Modal: เพิ่มสมาชิกใหม่ -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_member">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>เพิ่มสมาชิกใหม่</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="fw-bold text-primary mb-3"><i class="bi bi-person-vcard me-2"></i>ข้อมูลส่วนตัว</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="fullname" required placeholder="เช่น นายสมคิด สว่างใจ">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" required placeholder="เช่น 081-234-5678">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">อีเมล</label>
                            <input type="email" class="form-control" name="email" placeholder="example@email.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ระดับสิทธิ์</label>
                            <select name="role" class="form-select">
                                <option value="customer" selected>ลูกค้า (Customer)</option>
                                <option value="staff">พนักงาน (Staff)</option>
                                <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">ที่อยู่จัดส่ง / ติดต่อ</label>
                            <textarea class="form-control" name="address" rows="2" placeholder="ที่อยู่สำหรับจัดส่งแบตเตอรี่ฉุกเฉินหรือออกใบเสร็จ"></textarea>
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary mb-3"><i class="bi bi-key me-2"></i>ข้อมูลเข้าสู่ระบบ</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">ชื่อผู้ใช้ (Username) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" required placeholder="ภาษาอังกฤษหรือตัวเลข">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">รหัสผ่าน <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="password" required placeholder="อย่างน้อย 6 ตัวอักษร">
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary mb-3"><i class="bi bi-car-front-fill me-2"></i>ข้อมูลรถคันแรก (ไม่บังคับ)</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">ประเภทรถ</label>
                            <select name="vehicle_type" class="form-select">
                                <option value="car" selected>รถยนต์</option>
                                <option value="motorcycle">รถมอเตอร์ไซค์</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ยี่ห้อรถ</label>
                            <select name="vehicle_brand_id" class="form-select">
                                <option value="">-- เลือกยี่ห้อรถ --</option>
                                <?php foreach ($carBrands as $cb): ?>
                                <option value="<?= $cb['id'] ?>"><?= htmlspecialchars($cb['name']) ?> (<?= $cb['type'] === 'motorcycle' ? 'มอเตอร์ไซค์' : 'รถยนต์' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">รุ่นรถ</label>
                            <input type="text" class="form-control" name="vehicle_model" placeholder="เช่น Vios, City, D-Max, Wave">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">เลขทะเบียนรถ</label>
                            <input type="text" class="form-control" name="license_plate" placeholder="เช่น 1กข 1234 กทม">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ปี ค.ศ./พ.ศ.</label>
                            <input type="text" class="form-control" name="vehicle_year" placeholder="เช่น 2021">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">สีรถ</label>
                            <input type="text" class="form-control" name="vehicle_color" placeholder="เช่น ขาว, ดำ">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>บันทึกสมาชิก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขสมาชิก -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_member">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขข้อมูลสมาชิก</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_fullname" name="fullname" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_phone" name="phone" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">อีเมล</label>
                            <input type="email" class="form-control" id="edit_email" name="email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ระดับสิทธิ์</label>
                            <select name="role" id="edit_role" class="form-select">
                                <option value="customer">ลูกค้า (Customer)</option>
                                <option value="staff">พนักงาน (Staff)</option>
                                <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">ที่อยู่</label>
                            <textarea class="form-control" id="edit_address" name="address" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="p-3 bg-light rounded-3 border">
                                <label class="form-label fw-bold text-dark"><i class="bi bi-key-fill me-1"></i>เปลี่ยนรหัสผ่านใหม่ (เว้นว่างไว้หากไม่ต้องการเปลี่ยน)</label>
                                <input type="password" class="form-control" name="new_password" placeholder="กรอกรหัสผ่านใหม่ที่ต้องการ">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i>บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: ดูรายละเอียดสมาชิกและรถ -->
<div class="modal fade" id="viewMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-person-lines-fill me-2"></i>ข้อมูลสมาชิกและประวัติ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewMemberContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
function editMember(m) {
    document.getElementById('edit_user_id').value = m.id;
    document.getElementById('edit_fullname').value = m.fullname;
    document.getElementById('edit_phone').value = m.phone;
    document.getElementById('edit_email').value = m.email || '';
    document.getElementById('edit_address').value = m.address || '';
    document.getElementById('edit_role').value = m.role;
    new bootstrap.Modal(document.getElementById('editMemberModal')).show();
}

function viewMemberDetails(m) {
    const modalEl = document.getElementById('viewMemberModal');
    const content = document.getElementById('viewMemberContent');
    new bootstrap.Modal(modalEl).show();

    // Fetch vehicles and orders for this user via ajax
    fetch('<?= SITE_URL ?>api/get_member_info.php?user_id=' + m.id)
        .then(res => res.json())
        .then(data => {
            let vehiclesHtml = '';
            if (data.vehicles && data.vehicles.length > 0) {
                vehiclesHtml = '<div class="row g-2">';
                data.vehicles.forEach(v => {
                    vehiclesHtml += `
                        <div class="col-md-6">
                            <div class="p-3 border rounded-3 bg-light">
                                <div class="fw-bold text-primary"><i class="bi bi-car-front-fill me-1"></i>${v.brand_name} ${v.model}</div>
                                <div>ทะเบียน: <span class="badge bg-dark">${v.license_plate}</span></div>
                                <small class="text-muted">ประเภท: ${v.vehicle_type === 'motorcycle' ? 'มอเตอร์ไซค์' : 'รถยนต์'} | สี: ${v.color || '-'} | ปี: ${v.year || '-'}</small>
                            </div>
                        </div>
                    `;
                });
                vehiclesHtml += '</div>';
            } else {
                vehiclesHtml = '<div class="text-muted small">ยังไม่มีข้อมูลรถที่ลงทะเบียน</div>';
            }

            content.innerHTML = `
                <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold fs-3" style="width: 60px; height: 60px;">
                        ${m.fullname.charAt(0)}
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold">${m.fullname}</h5>
                        <div class="text-muted">Username: <code>${m.username}</code> | สิทธิ์: <span class="badge bg-secondary">${m.role}</span></div>
                        <div class="small text-muted"><i class="bi bi-telephone me-1"></i>${m.phone} | <i class="bi bi-envelope me-1"></i>${m.email || 'ไม่มีอีเมล'}</div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="fw-bold text-muted small text-uppercase">ที่อยู่จัดส่ง / ติดต่อ</label>
                    <p class="mb-0 text-dark">${m.address || 'ไม่ได้ระบุที่อยู่'}</p>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-car-front-fill me-1"></i>รถที่ลงทะเบียน (${data.vehicles ? data.vehicles.length : 0} คัน)</h6>
                    <a href="<?= SITE_URL ?>vehicles.php?user_id=${m.id}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus-lg me-1"></i>จัดการรถของสมาชิกคนนี้
                    </a>
                </div>
                ${vehiclesHtml}
            `;
        })
        .catch(err => {
            content.innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการโหลดข้อมูล</div>';
        });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

