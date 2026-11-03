<?php
require_once 'config.php';

// ถ้า login อยู่แล้ว ให้ไปหน้าหลัก
if (isLoggedIn()) {
    header('Location: ' . SITE_URL . 'index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['profile_image'] = $user['profile_image'];

            header('Location: ' . SITE_URL . 'index.php');
            exit;
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ | <?= SITE_NAME ?></title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .login-container {
            width: 100%;
            max-width: 440px;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--dark) 100%);
            color: #fff;
            padding: 2rem;
            text-align: center;
        }
        .login-header .brand-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2.5rem;
        }
        .login-header h4 { margin: 0; font-weight: 700; }
        .login-header p { margin: 0.5rem 0 0; opacity: 0.8; font-size: 0.85rem; }
        .login-body { padding: 2rem; }
        .form-floating .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding-left: 2.75rem;
        }
        .form-floating .form-control:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 0.2rem rgba(41,128,185,0.15);
        }
        .form-floating label { padding-left: 2.75rem; }
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #adb5bd;
            z-index: 5;
            font-size: 1.1rem;
        }
        .btn-login {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(26,82,118,0.4);
        }
        .login-footer {
            text-align: center;
            padding: 0 2rem 2rem;
        }
        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        .login-footer a:hover { color: var(--secondary); }
        .toggle-password {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #adb5bd;
            cursor: pointer;
            z-index: 5;
        }
        .toggle-password:hover { color: var(--primary); }
        .decorative-circles {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 0;
        }
        .decorative-circles div {
            position: absolute;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.05);
        }
        .circle-1 { width: 300px; height: 300px; top: -100px; right: -100px; }
        .circle-2 { width: 200px; height: 200px; bottom: -50px; left: -50px; }
        .circle-3 { width: 150px; height: 150px; top: 40%; left: 10%; }
        .login-container { position: relative; z-index: 1; }
    </style>
</head>
<body>
    <div class="decorative-circles">
        <div class="circle-1"></div>
        <div class="circle-2"></div>
        <div class="circle-3"></div>
    </div>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="brand-icon">
                    <i class="bi bi-battery-charging"></i>
                </div>
                <h4><?= SITE_NAME ?></h4>
                <p>ระบบจัดการร้านแบตเตอรี่</p>
            </div>

            <div class="login-body">
                <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-1"></i>
                    <?= $flash['message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <div class="d-flex gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm flex-grow-1" onclick="fillLogin('admin', 'admin123')">
                        <i class="bi bi-shield-lock me-1"></i> แอดมิน (admin)
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm flex-grow-1" onclick="fillLogin('customer', 'customer123')">
                        <i class="bi bi-person me-1"></i> ลูกค้า (customer)
                    </button>
                </div>

                <form method="POST" action="">
                    <div class="position-relative mb-3">
                        <i class="bi bi-person input-icon"></i>
                        <div class="form-floating">
                            <input type="text" class="form-control" id="username" name="username"
                                   placeholder="ชื่อผู้ใช้" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                            <label for="username">ชื่อผู้ใช้</label>
                        </div>
                    </div>

                    <div class="position-relative mb-4">
                        <i class="bi bi-lock input-icon"></i>
                        <div class="form-floating">
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="รหัสผ่าน" required>
                            <label for="password">รหัสผ่าน</label>
                        </div>
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>

                    <button type="submit" class="btn btn-primary btn-login w-100 mb-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>เข้าสู่ระบบ
                    </button>
                </form>
            </div>

            <div class="login-footer">
                <p class="text-muted mb-0">
                    ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a>
                </p>
            </div>
        </div>

        <p class="text-center text-white-50 mt-3" style="font-size: 0.8rem;">
            &copy; <?= date('Y') ?> <?= SITE_NAME ?> - สงวนลิขสิทธิ์
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function fillLogin(u, p) {
            document.getElementById('username').value = u;
            document.getElementById('password').value = p;
        }

        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }
    </script>
</body>
</html>
