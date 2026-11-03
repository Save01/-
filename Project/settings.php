<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$pageTitle = 'ตั้งค่าระบบ';
$db = getDB();

// ===== จัดการ POST requests =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. เพิ่มยี่ห้อรถ
    if ($action === 'add_car_brand') {
        $name = trim($_POST['name'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['car', 'motorcycle', 'both']) ? $_POST['type'] : 'car';
        if (!empty($name)) {
            $stmt = $db->prepare("INSERT INTO car_brands (name, type) VALUES (?, ?)");
            $stmt->execute([$name, $type]);
            setFlash('success', 'เพิ่มยี่ห้อรถ "' . htmlspecialchars($name) . '" สำเร็จ');
        }
        header('Location: ' . SITE_URL . 'settings.php?tab=car_brands');
        exit;
    }

    // 2. ลบยี่ห้อรถ
    if ($action === 'delete_car_brand') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM car_brands WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('success', 'ลบยี่ห้อรถเรียบร้อยแล้ว');
        header('Location: ' . SITE_URL . 'settings.php?tab=car_brands');
        exit;
    }

    // 3. เพิ่มยี่ห้อแบตเตอรี่
    if ($action === 'add_battery_brand') {
        $name = trim($_POST['name'] ?? '');
        if (!empty($name)) {
            $stmt = $db->prepare("INSERT INTO battery_brands (name) VALUES (?)");
            $stmt->execute([$name]);
            setFlash('success', 'เพิ่มยี่ห้อแบตเตอรี่ "' . htmlspecialchars($name) . '" สำเร็จ');
        }
        header('Location: ' . SITE_URL . 'settings.php?tab=battery_brands');
        exit;
    }

    // 4. ลบยี่ห้อแบตเตอรี่
    if ($action === 'delete_battery_brand') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM battery_brands WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('success', 'ลบยี่ห้อแบตเตอรี่เรียบร้อยแล้ว');
        header('Location: ' . SITE_URL . 'settings.php?tab=battery_brands');
        exit;
    }

    // 5. เพิ่มหมวดหมู่
    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!empty($name)) {
            $stmt = $db->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $desc ?: null]);
            setFlash('success', 'เพิ่มหมวดหมู่สินค้าสำเร็จ');
        }
        header('Location: ' . SITE_URL . 'settings.php?tab=categories');
        exit;
    }

    // 6. ลบหมวดหมู่
    if ($action === 'delete_category') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('success', 'ลบหมวดหมู่สินค้าเรียบร้อยแล้ว');
        header('Location: ' . SITE_URL . 'settings.php?tab=categories');
        exit;
    }
}

$tab = $_GET['tab'] ?? 'car_brands';

$carBrands = $db->query("SELECT * FROM car_brands ORDER BY type, name")->fetchAll();
$batteryBrands = $db->query("SELECT * FROM battery_brands ORDER BY name")->fetchAll();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div>
        <h4 class="mb-1"><i class="bi bi-gear-fill text-primary me-2"></i>ตั้งค่าระบบ</h4>
        <small class="text-muted">จัดการข้อมูลหลัก: ยี่ห้อรถ ยี่ห้อแบตเตอรี่ และหมวดหมู่สินค้า</small>
    </div>
</div>

<ul class="nav nav-pills mb-4 gap-2" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'car_brands' ? 'active' : '' ?>" href="<?= SITE_URL ?>settings.php?tab=car_brands">
            <i class="bi bi-car-front me-1"></i> ยี่ห้อรถ (<?= count($carBrands) ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'battery_brands' ? 'active' : '' ?>" href="<?= SITE_URL ?>settings.php?tab=battery_brands">
            <i class="bi bi-battery-charging me-1"></i> ยี่ห้อแบตเตอรี่ (<?= count($batteryBrands) ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'categories' ? 'active' : '' ?>" href="<?= SITE_URL ?>settings.php?tab=categories">
            <i class="bi bi-tag me-1"></i> หมวดหมู่สินค้า (<?= count($categories) ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'shop_info' ? 'active' : '' ?>" href="<?= SITE_URL ?>settings.php?tab=shop_info">
            <i class="bi bi-shop me-1"></i> ข้อมูลร้านค้า
        </a>
    </li>
</ul>

<?php if ($tab === 'car_brands'): ?>
<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3 p-3">
            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-plus-circle me-1"></i>เพิ่มยี่ห้อรถใหม่</h6>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_car_brand">
                <div class="mb-3">
                    <label class="form-label">ชื่อยี่ห้อรถ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required placeholder="เช่น BYD, Tesla, GPX">
                </div>
                <div class="mb-3">
                    <label class="form-label">ประเภท</label>
                    <select name="type" class="form-select">
                        <option value="car">รถยนต์</option>
                        <option value="motorcycle">รถมอเตอร์ไซค์</option>
                        <option value="both">ทั้งสองประเภท</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>บันทึกยี่ห้อรถ</button>
            </form>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-container shadow-sm">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ยี่ห้อรถ</th>
                        <th>ประเภท</th>
                        <th class="text-end">ลบ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($carBrands as $i => $cb): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($cb['name']) ?></td>
                        <td>
                            <?php if ($cb['type'] === 'motorcycle'): ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-bicycle me-1"></i>มอเตอร์ไซค์</span>
                            <?php elseif ($cb['type'] === 'both'): ?>
                                <span class="badge bg-info text-dark">ทั้งสองประเภท</span>
                            <?php else: ?>
                                <span class="badge bg-primary"><i class="bi bi-car-front me-1"></i>รถยนต์</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <form method="POST" action="" onsubmit="return confirm('ยืนยันลบยี่ห้อนี้?');" class="d-inline">
                                <input type="hidden" name="action" value="delete_car_brand">
                                <input type="hidden" name="id" value="<?= $cb['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'battery_brands'): ?>
<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3 p-3">
            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-plus-circle me-1"></i>เพิ่มยี่ห้อแบตเตอรี่ใหม่</h6>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_battery_brand">
                <div class="mb-3">
                    <label class="form-label">ชื่อยี่ห้อแบตเตอรี่ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required placeholder="เช่น Amaron, Varta, GS, 3K">
                </div>
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>บันทึกยี่ห้อแบตเตอรี่</button>
            </form>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-container shadow-sm">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ชื่อยี่ห้อแบตเตอรี่</th>
                        <th class="text-end">ลบ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batteryBrands as $i => $bb): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-bold text-primary"><?= htmlspecialchars($bb['name']) ?></td>
                        <td class="text-end">
                            <form method="POST" action="" onsubmit="return confirm('ยืนยันลบยี่ห้อนี้?');" class="d-inline">
                                <input type="hidden" name="action" value="delete_battery_brand">
                                <input type="hidden" name="id" value="<?= $bb['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'categories'): ?>
<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3 p-3">
            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-plus-circle me-1"></i>เพิ่มหมวดหมู่สินค้าใหม่</h6>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_category">
                <div class="mb-3">
                    <label class="form-label">ชื่อหมวดหมู่ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required placeholder="เช่น แบตเตอรี่รถบรรทุก">
                </div>
                <div class="mb-3">
                    <label class="form-label">คำอธิบาย</label>
                    <textarea class="form-control" name="description" rows="2"></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>บันทึกหมวดหมู่</button>
            </form>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-container shadow-sm">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ชื่อหมวดหมู่</th>
                        <th>คำอธิบาย</th>
                        <th class="text-end">ลบ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $i => $cat): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($cat['name']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($cat['description'] ?? '-') ?></td>
                        <td class="text-end">
                            <form method="POST" action="" onsubmit="return confirm('ยืนยันลบหมวดหมู่นี้?');" class="d-inline">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'shop_info'): ?>
<div class="card border-0 shadow-sm rounded-3 p-4" style="max-width: 650px;">
    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-shop me-2"></i>ข้อมูลร้านพรชัยแบตเตอรี่ บางพลี</h5>
    <div class="list-group list-group-flush mb-4">
        <div class="list-group-item d-flex justify-content-between px-0">
            <strong class="text-muted">ชื่อร้านค้า:</strong>
            <span class="fw-bold text-dark"><?= SITE_NAME ?></span>
        </div>
        <div class="list-group-item d-flex justify-content-between px-0">
            <strong class="text-muted">ที่อยู่หน้าร้าน:</strong>
            <span class="text-end"><?= SHOP_ADDRESS ?></span>
        </div>
        <div class="list-group-item d-flex justify-content-between px-0">
            <strong class="text-muted">เบอร์โทรศัพท์:</strong>
            <span><?= SHOP_PHONE ?></span>
        </div>
        <div class="list-group-item d-flex justify-content-between px-0">
            <strong class="text-muted">Line ID:</strong>
            <span><?= SHOP_LINE ?></span>
        </div>
        <div class="list-group-item d-flex justify-content-between px-0">
            <strong class="text-muted">เลขประจำตัวผู้เสียภาษี:</strong>
            <span><?= SHOP_TAX_ID ?></span>
        </div>
    </div>
    <div class="alert alert-light border small text-muted">
        <i class="bi bi-info-circle me-1"></i> ข้อมูลข้างต้นถูกนำไปแสดงผลบนหัวเอกสารใบเสร็จรับเงิน รายงานสต๊อก และข้อมูลติดต่อลูกค้า
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

