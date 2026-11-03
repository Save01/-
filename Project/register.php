<?php
require_once 'config.php';

// ถ้า login อยู่แล้ว ให้ไปหน้าหลัก
if (isLoggedIn()) {
    header('Location: ' . SITE_URL . 'index.php');
    exit;
}

$db = getDB();
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $fullname = trim($_POST['fullname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $old = $_POST;

    // Validate
    if (empty($fullname)) $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    if (empty($phone)) $errors[] = 'กรุณากรอกเบอร์โทร';
    if (empty($username)) $errors[] = 'กรุณากรอกชื่อผู้ใช้';
    if (mb_strlen($username) < 4) $errors[] = 'ชื่อผู้ใช้ต้องมีอย่างน้อย 4 ตัวอักษร';
    if (empty($password)) $errors[] = 'กรุณากรอกรหัสผ่าน';
    if (mb_strlen($password) < 6) $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    if ($password !== $confirm_password) $errors[] = 'รหัสผ่านไม่ตรงกัน';

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    // Check duplicate username
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) $errors[] = 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว';

    // Validate vehicles
    $vehicleTypes = $_POST['vehicle_type'] ?? [];
    $vehicleBrands = $_POST['vehicle_brand'] ?? [];
    $vehicleModels = $_POST['vehicle_model'] ?? [];
    $vehiclePlates = $_POST['vehicle_plate'] ?? [];

    if (empty($vehicleTypes) || empty($vehicleTypes[0])) {
        $errors[] = 'กรุณากรอกข้อมูลรถอย่างน้อย 1 คัน';
    } else {
        for ($i = 0; $i < count($vehicleTypes); $i++) {
            if (empty($vehicleBrands[$i]) || empty($vehicleModels[$i]) || empty($vehiclePlates[$i])) {
                $errors[] = 'กรุณากรอกข้อมูลรถให้ครบถ้วน (คันที่ ' . ($i + 1) . ')';
                break;
            }
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password, fullname, phone, email, address, role) VALUES (?, ?, ?, ?, ?, ?, 'customer')");
            $stmt->execute([$username, $hashedPassword, $fullname, $phone, $email ?: null, $address ?: null]);
            $userId = $db->lastInsertId();

            // Insert vehicles
            for ($i = 0; $i < count($vehicleTypes); $i++) {
                if (!empty($vehicleTypes[$i]) && !empty($vehicleBrands[$i]) && !empty($vehicleModels[$i]) && !empty($vehiclePlates[$i])) {
                    $stmt = $db->prepare("INSERT INTO member_vehicles (user_id, vehicle_type, brand_id, model, license_plate) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $vehicleTypes[$i], $vehicleBrands[$i], trim($vehicleModels[$i]), trim($vehiclePlates[$i])]);
                }
            }

            $db->commit();

            setFlash('success', '<i class="bi bi-check-circle me-1"></i> สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ');
            header('Location: ' . SITE_URL . 'login.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
        }
    }
}

// Fetch car brands for initial load
$brandsStmt = $db->query("SELECT * FROM car_brands ORDER BY name");
$allBrands = $brandsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก | <?= SITE_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a5276;
            --primary-light: #2980b9;
            --secondary: #e67e22;
            --dark: #2c3e50;
        }
        * { font-family: 'Noto Sans Thai', sans-serif; }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #1a5276 0%, #2c3e50 50%, #1a5276 100%);
            padding: 2rem 1rem;
        }
        .register-container {
            max-width: 700px;
            margin: 0 auto;
        }
        .register-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .register-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--dark) 100%);
            color: #fff;
            padding: 1.5rem 2rem;
            text-align: center;
        }
        .register-header .brand-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        .register-header h4 { margin: 0; font-weight: 700; }
        .register-header p { margin: 0.25rem 0 0; opacity: 0.8; font-size: 0.85rem; }
        .register-body { padding: 2rem; }
        .section-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 0.2rem rgba(41,128,185,0.15);
        }
        .btn-register {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
        }
        .btn-register:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(26,82,118,0.4);
            color: #fff;
        }
        .vehicle-row {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
            position: relative;
        }
        .vehicle-row .vehicle-number {
            position: absolute;
            top: -10px;
            left: 10px;
            background: var(--primary);
            color: #fff;
            border-radius: 20px;
            padding: 0 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .btn-remove-vehicle {
            position: absolute;
            top: -10px;
            right: 10px;
        }
        .register-footer {
            text-align: center;
            padding: 0 2rem 2rem;
        }
        .register-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        .register-footer a:hover { color: var(--secondary); }
        .decorative-circles {
            position: fixed; width: 100%; height: 100%; top: 0; left: 0;
            pointer-events: none; overflow: hidden; z-index: 0;
        }
        .decorative-circles div {
            position: absolute; border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.05);
        }
        .circle-1 { width: 300px; height: 300px; top: -100px; right: -100px; }
        .circle-2 { width: 200px; height: 200px; bottom: -50px; left: -50px; }
        .register-container { position: relative; z-index: 1; }
    </style>
</head>
<body>
    <div class="decorative-circles">
        <div class="circle-1"></div>
        <div class="circle-2"></div>
    </div>

    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="brand-icon">
                    <i class="bi bi-battery-charging"></i>
                </div>
                <h4>สมัครสมาชิก</h4>
                <p><?= SITE_NAME ?></p>
            </div>

            <div class="register-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>พบข้อผิดพลาด:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="registerForm">
                    <!-- ข้อมูลส่วนตัว -->
                    <div class="section-title">
                        <i class="bi bi-person-circle"></i> ข้อมูลส่วนตัว
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="fullname" value="<?= htmlspecialchars($old['fullname'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" name="phone" value="<?= htmlspecialchars($old['phone'] ?? '') ?>" required
                                   pattern="[0-9]{9,10}" title="เบอร์โทร 9-10 หลัก">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">อีเมล</label>
                            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($old['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ที่อยู่</label>
                            <textarea class="form-control" name="address" rows="1"><?= htmlspecialchars($old['address'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- ข้อมูลบัญชี -->
                    <div class="section-title">
                        <i class="bi bi-shield-lock"></i> ข้อมูลบัญชี
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label class="form-label">ชื่อผู้ใช้ (Username) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($old['username'] ?? '') ?>" required
                                   minlength="4" pattern="[a-zA-Z0-9_]+" title="ตัวอักษรภาษาอังกฤษ ตัวเลข หรือ _ เท่านั้น">
                            <div class="form-text">ตัวอักษรภาษาอังกฤษ ตัวเลข หรือ _ อย่างน้อย 4 ตัวอักษร</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">รหัสผ่าน <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="password" id="password" required minlength="6">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('password','pwdIcon1')">
                                    <i class="bi bi-eye" id="pwdIcon1"></i>
                                </button>
                            </div>
                            <div class="form-text">อย่างน้อย 6 ตัวอักษร</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ยืนยันรหัสผ่าน <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('confirm_password','pwdIcon2')">
                                    <i class="bi bi-eye" id="pwdIcon2"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ข้อมูลรถ -->
                    <div class="section-title">
                        <i class="bi bi-car-front"></i> ข้อมูลรถ
                        <button type="button" class="btn btn-sm btn-outline-primary ms-auto" onclick="addVehicle()">
                            <i class="bi bi-plus-circle"></i> เพิ่มรถ
                        </button>
                    </div>

                    <div id="vehicleContainer">
                        <div class="vehicle-row" id="vehicle-0">
                            <span class="vehicle-number">รถคันที่ 1</span>
                            <div class="row g-2 mt-1">
                                <div class="col-md-6">
                                    <label class="form-label">ประเภทรถ <span class="text-danger">*</span></label>
                                    <select class="form-select vehicle-type" name="vehicle_type[]" required onchange="filterBrands(this)">
                                        <option value="">-- เลือกประเภท --</option>
                                        <option value="car">รถยนต์</option>
                                        <option value="motorcycle">มอเตอร์ไซค์</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">ยี่ห้อรถ <span class="text-danger">*</span></label>
                                    <select class="form-select vehicle-brand" name="vehicle_brand[]" required>
                                        <option value="">-- เลือกยี่ห้อ --</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">รุ่นรถ <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="vehicle_model[]" required placeholder="เช่น Civic, PCX">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">เลขทะเบียนรถ <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="vehicle_plate[]" required placeholder="เช่น กข-1234">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-register">
                            <i class="bi bi-person-plus me-2"></i>สมัครสมาชิก
                        </button>
                    </div>
                </form>
            </div>

            <div class="register-footer">
                <p class="text-muted mb-0">
                    มีบัญชีอยู่แล้ว? <a href="login.php"><i class="bi bi-box-arrow-in-right me-1"></i>เข้าสู่ระบบ</a>
                </p>
            </div>
        </div>

        <p class="text-center text-white-50 mt-3" style="font-size: 0.8rem;">
            &copy; <?= date('Y') ?> <?= SITE_NAME ?> - สงวนลิขสิทธิ์
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let vehicleCount = 1;

        function togglePwd(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }

        function filterBrands(selectEl) {
            const type = selectEl.value;
            const row = selectEl.closest('.vehicle-row');
            const brandSelect = row.querySelector('.vehicle-brand');

            brandSelect.innerHTML = '<option value="">-- กำลังโหลด... --</option>';

            if (!type) {
                brandSelect.innerHTML = '<option value="">-- เลือกยี่ห้อ --</option>';
                return;
            }

            fetch('<?= SITE_URL ?>api/get_brands.php?type=' + encodeURIComponent(type))
                .then(r => r.json())
                .then(data => {
                    let html = '<option value="">-- เลือกยี่ห้อ --</option>';
                    data.forEach(b => {
                        html += `<option value="${b.id}">${b.name}</option>`;
                    });
                    brandSelect.innerHTML = html;
                })
                .catch(() => {
                    brandSelect.innerHTML = '<option value="">-- ไม่สามารถโหลดข้อมูลได้ --</option>';
                });
        }

        function addVehicle() {
            vehicleCount++;
            const container = document.getElementById('vehicleContainer');
            const div = document.createElement('div');
            div.className = 'vehicle-row';
            div.id = 'vehicle-' + (vehicleCount - 1);
            div.innerHTML = `
                <span class="vehicle-number">รถคันที่ ${vehicleCount}</span>
                <button type="button" class="btn btn-sm btn-danger btn-remove-vehicle rounded-pill" onclick="removeVehicle(this)">
                    <i class="bi bi-x-lg"></i>
                </button>
                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">ประเภทรถ <span class="text-danger">*</span></label>
                        <select class="form-select vehicle-type" name="vehicle_type[]" required onchange="filterBrands(this)">
                            <option value="">-- เลือกประเภท --</option>
                            <option value="car">รถยนต์</option>
                            <option value="motorcycle">มอเตอร์ไซค์</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ยี่ห้อรถ <span class="text-danger">*</span></label>
                        <select class="form-select vehicle-brand" name="vehicle_brand[]" required>
                            <option value="">-- เลือกยี่ห้อ --</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">รุ่นรถ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vehicle_model[]" required placeholder="เช่น Civic, PCX">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">เลขทะเบียนรถ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vehicle_plate[]" required placeholder="เช่น กข-1234">
                    </div>
                </div>
            `;
            container.appendChild(div);
        }

        function removeVehicle(btn) {
            const row = btn.closest('.vehicle-row');
            row.remove();
            // Re-number
            vehicleCount--;
            const rows = document.querySelectorAll('.vehicle-row');
            rows.forEach((r, i) => {
                r.querySelector('.vehicle-number').textContent = 'รถคันที่ ' + (i + 1);
            });
        }

        // Password match validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const pwd = document.getElementById('password').value;
            const cpwd = document.getElementById('confirm_password').value;
            if (pwd !== cpwd) {
                e.preventDefault();
                alert('รหัสผ่านไม่ตรงกัน');
            }
        });
    </script>
</body>
</html>
