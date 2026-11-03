<?php
if (!isset($pageTitle)) $pageTitle = SITE_NAME;
$notifCount = isLoggedIn() ? getUnreadNotificationCount($_SESSION['user_id']) : 0;
$lowStock = isStaff() ? checkLowStock() : [];
$lowStockCount = count($lowStock);
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= SITE_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a5276;
            --primary-light: #2980b9;
            --secondary: #e67e22;
            --success: #27ae60;
            --danger: #e74c3c;
            --dark: #2c3e50;
            --light-bg: #f8f9fa;
            --sidebar-width: 260px;
        }
        * { font-family: 'Noto Sans Thai', sans-serif; }
        body { background: var(--light-bg); }
        
        /* Sidebar */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, var(--primary) 0%, var(--dark) 100%);
            color: #fff; z-index: 1000;
            transition: transform 0.3s ease;
            overflow-y: auto;
        }
        .sidebar-brand {
            padding: 1.5rem 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand h5 { margin: 0; font-weight: 700; font-size: 1.1rem; }
        .sidebar-brand small { opacity: 0.7; font-size: 0.75rem; }
        .sidebar-nav { padding: 0.5rem 0; }
        .sidebar-nav .nav-section {
            padding: 0.75rem 1rem 0.25rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.5;
            font-weight: 600;
        }
        .sidebar-nav .nav-link {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.6rem 1rem; color: rgba(255,255,255,0.8);
            text-decoration: none; font-size: 0.9rem;
            transition: all 0.2s; border-left: 3px solid transparent;
        }
        .sidebar-nav .nav-link:hover,
        .sidebar-nav .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-left-color: var(--secondary);
        }
        .sidebar-nav .nav-link i { font-size: 1.1rem; width: 24px; text-align: center; }
        
        /* Main content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }
        
        /* Top navbar */
        .top-navbar {
            background: #fff;
            padding: 0.75rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: sticky; top: 0; z-index: 999;
        }
        
        .content-area { padding: 1.5rem; }
        
        /* Cards */
        .stat-card {
            background: #fff; border-radius: 12px;
            padding: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .stat-icon {
            width: 48px; height: 48px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
        }
        
        /* Notification badge */
        .notif-badge {
            position: absolute; top: -5px; right: -5px;
            background: var(--danger); color: #fff;
            border-radius: 50%; min-width: 20px; height: 20px;
            font-size: 0.7rem; display: flex; align-items: center;
            justify-content: center; font-weight: 700;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(231,76,60,0.4); }
            70% { box-shadow: 0 0 0 10px rgba(231,76,60,0); }
            100% { box-shadow: 0 0 0 0 rgba(231,76,60,0); }
        }
        
        /* Notification popup */
        .notif-popup {
            position: fixed; top: 20px; right: 20px; z-index: 9999;
            max-width: 380px; width: 100%;
        }
        .notif-popup .alert {
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Table */
        .table-container {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .table-container .table { margin: 0; }
        .table-container .table th {
            background: var(--primary); color: #fff;
            font-weight: 500; font-size: 0.85rem;
            padding: 0.75rem;
        }
        .table-container .table td { 
            padding: 0.75rem; vertical-align: middle;
            font-size: 0.85rem;
        }
        
        /* Responsive */
        .sidebar-toggle { display: none; }
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .sidebar-overlay {
                position: fixed; top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999; display: none;
            }
            .sidebar-overlay.show { display: block; }
        }
        
        /* Button styles */
        .btn-primary { background: var(--primary); border-color: var(--primary); }
        .btn-primary:hover { background: var(--primary-light); border-color: var(--primary-light); }
        .btn-warning { background: var(--secondary); border-color: var(--secondary); color: #fff; }
        .btn-warning:hover { color: #fff; opacity: 0.9; }
        
        /* Status badges */
        .badge-status { padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; }
        
        /* Form controls */
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 0.2rem rgba(41,128,185,0.25);
        }
        
        /* Page header */
        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;
        }
        .page-header h4 { margin: 0; font-weight: 700; color: var(--dark); }

        /* Low stock alert */
        .low-stock-alert {
            background: linear-gradient(135deg, #fff5f5, #fed7d7);
            border: 1px solid #feb2b2;
            border-radius: 10px;
            padding: 1rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h5><i class="bi bi-battery-charging"></i> <?= SITE_NAME ?></h5>
            <small>ระบบจัดการร้านแบตเตอรี่</small>
        </div>
        <div class="sidebar-nav">
            <div class="nav-section">เมนูหลัก</div>
            <a href="<?= SITE_URL ?>index.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
                <i class="bi bi-house-door"></i> หน้าหลัก
            </a>
            
            <?php if (isLoggedIn()): ?>
            <div class="nav-section">ระบบสมาชิก</div>
            <?php if (isStaff()): ?>
            <a href="<?= SITE_URL ?>members.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'members.php' ? 'active' : '' ?>">
                <i class="bi bi-people"></i> จัดการสมาชิก
            </a>
            <?php endif; ?>
            <a href="<?= SITE_URL ?>vehicles.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'vehicles.php' ? 'active' : '' ?>">
                <i class="bi bi-car-front"></i> ข้อมูลรถ
            </a>
            
            <div class="nav-section">สินค้าและสต๊อก</div>
            <?php if (isStaff()): ?>
            <a href="<?= SITE_URL ?>products.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : '' ?>">
                <i class="bi bi-box-seam"></i> จัดการสินค้า
                <?php if ($lowStockCount > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-auto"><?= $lowStockCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= SITE_URL ?>stock.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'stock.php' ? 'active' : '' ?>">
                <i class="bi bi-clipboard-data"></i> สต๊อกสินค้า
            </a>
            <a href="<?= SITE_URL ?>recommendations.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'recommendations.php' ? 'active' : '' ?>">
                <i class="bi bi-star"></i> จัดการสินค้าแนะนำ
            </a>
            <?php endif; ?>
            
            <div class="nav-section">การสั่งซื้อ</div>
            <a href="<?= SITE_URL ?>orders.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : '' ?>">
                <i class="bi bi-cart3"></i> สั่งซื้อสินค้า
            </a>
            <?php if (isStaff()): ?>
            <a href="<?= SITE_URL ?>manage_orders.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'manage_orders.php' ? 'active' : '' ?>">
                <i class="bi bi-list-check"></i> จัดการคำสั่งซื้อ
            </a>
            <?php endif; ?>
            
            <div class="nav-section">ประกันและเคลม</div>
            <a href="<?= SITE_URL ?>warranty.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'warranty.php' ? 'active' : '' ?>">
                <i class="bi bi-shield-check"></i> ข้อมูลประกัน
            </a>
            <a href="<?= SITE_URL ?>claims.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'claims.php' ? 'active' : '' ?>">
                <i class="bi bi-exclamation-triangle"></i> เคลมสินค้า
            </a>
            
            <?php if (isStaff()): ?>
            <div class="nav-section">บัญชีและการเงิน</div>
            <a href="<?= SITE_URL ?>accounting.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'accounting.php' ? 'active' : '' ?>">
                <i class="bi bi-calculator"></i> รายรับ-รายจ่าย
            </a>
            <a href="<?= SITE_URL ?>receipts.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'receipts.php' ? 'active' : '' ?>">
                <i class="bi bi-receipt"></i> ใบเสร็จ
            </a>
            <?php endif; ?>
            
            <div class="nav-section">การแจ้งเตือน</div>
            <a href="<?= SITE_URL ?>notifications.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : '' ?>">
                <i class="bi bi-bell"></i> การแจ้งเตือน
                <span class="badge bg-danger rounded-pill ms-auto notif-badge <?= $notifCount > 0 ? '' : 'd-none' ?>"><?= $notifCount ?></span>
            </a>
            
            <?php if (isAdmin()): ?>
            <div class="nav-section">ตั้งค่า</div>
            <a href="<?= SITE_URL ?>settings.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i> ตั้งค่าระบบ
            </a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-sm btn-outline-secondary sidebar-toggle" onclick="toggleSidebar()">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <h6 class="mb-0 d-none d-md-block" style="color: var(--dark);"><?= htmlspecialchars($pageTitle) ?></h6>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php if (isLoggedIn()): ?>
                <!-- Notifications Dropdown -->
                <?php
                $topNotifs = [];
                if (isLoggedIn()) {
                    $stmtTN = getDB()->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) ORDER BY created_at DESC LIMIT 5");
                    $stmtTN->execute([$_SESSION['user_id']]);
                    $topNotifs = $stmtTN->fetchAll();
                }
                ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="การแจ้งเตือน">
                        <i class="bi bi-bell"></i>
                        <span class="notif-badge <?= $notifCount > 0 ? '' : 'd-none' ?>"><?= $notifCount ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow p-0" style="min-width: 300px; max-width: 350px;">
                        <div class="p-2 border-bottom d-flex justify-content-between align-items-center bg-light">
                            <span class="fw-bold small"><i class="bi bi-bell me-1"></i>การแจ้งเตือน (<?= $notifCount ?>)</span>
                            <?php if ($notifCount > 0): ?>
                            <a href="<?= SITE_URL ?>notifications.php?action=read_all" class="badge bg-primary text-decoration-none" style="font-size: 0.72rem;">
                                <i class="bi bi-check2-all me-1"></i>อ่านทั้งหมด
                            </a>
                            <?php endif; ?>
                        </div>
                        <div style="max-height: 280px; overflow-y: auto;">
                            <?php if (empty($topNotifs)): ?>
                            <div class="p-3 text-center text-muted small">ไม่มีการแจ้งเตือน</div>
                            <?php else: ?>
                            <?php foreach ($topNotifs as $tn): ?>
                            <a href="<?= SITE_URL ?>notifications.php?read_id=<?= $tn['id'] ?>&redirect=<?= urlencode(!empty($tn['link']) ? SITE_URL . ltrim($tn['link'], '/') : SITE_URL . 'notifications.php') ?>" class="dropdown-item py-2 border-bottom <?= !$tn['is_read'] ? 'bg-light fw-bold' : '' ?>" style="white-space: normal;">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-circle-fill text-<?= !$tn['is_read'] ? 'primary' : 'muted' ?>" style="font-size: 0.5rem; margin-top: 6px;"></i>
                                    <div class="flex-grow-1">
                                        <div class="small"><?= htmlspecialchars($tn['title']) ?></div>
                                        <div class="small text-muted" style="font-size: 0.75rem; line-height: 1.3;"><?= htmlspecialchars(mb_strimwidth($tn['message'], 0, 70, '...')) ?></div>
                                        <div class="text-muted" style="font-size: 0.7rem;"><?= date('d/m/Y H:i', strtotime($tn['created_at'])) ?> น.</div>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="p-2 text-center border-top bg-light">
                            <a href="<?= SITE_URL ?>notifications.php" class="text-primary small fw-bold text-decoration-none">
                                ดูการแจ้งเตือนทั้งหมด <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <!-- User dropdown -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-sm-inline"><?= htmlspecialchars($_SESSION['fullname'] ?? '') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= SITE_URL ?>logout.php"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
                    </ul>
                </div>
                <?php else: ?>
                <a href="<?= SITE_URL ?>login.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i> เข้าสู่ระบบ
                </a>
                <a href="<?= SITE_URL ?>register.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-person-plus"></i> สมัครสมาชิก
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Content Area -->
        <div class="content-area">
            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
                <?= $flash['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

