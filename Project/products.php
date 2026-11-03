<?php
require_once 'config.php';
requireStaff();

$db = getDB();

// ===== จัดการ POST requests =====

// เพิ่มสินค้า
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_product') {
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $brandId = !empty($_POST['battery_brand_id']) ? (int)$_POST['battery_brand_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $costPrice = (float)($_POST['cost_price'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $minQuantity = (int)($_POST['min_quantity'] ?? 5);
        $warrantyMonths = (int)($_POST['warranty_months'] ?? 12);
        $vehicleType = $_POST['vehicle_type'] ?? 'both';
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            setFlash('danger', 'กรุณากรอกชื่อสินค้า');
        } else {
            // ตรวจสอบ SKU ซ้ำ
            if (!empty($sku)) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
                $stmt->execute([$sku]);
                if ($stmt->fetchColumn() > 0) {
                    setFlash('danger', 'รหัสสินค้า (SKU) นี้มีอยู่แล้ว');
                    header('Location: products.php');
                    exit;
                }
            }
            
            // อัปโหลดรูปภาพ (รองรับทั้งเปิดกล้องถ่ายภาพ Base64 และอัปโหลดไฟล์)
            $imagePath = null;
            if (!empty($_POST['image_base64'])) {
                $imagePath = uploadBase64Image($_POST['image_base64'], 'products');
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $imagePath = uploadFile($_FILES['image'], 'products');
            }
            
            $stmt = $db->prepare("
                INSERT INTO products (category_id, battery_brand_id, name, sku, description, price, cost_price, quantity, min_quantity, image, warranty_months, vehicle_type, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$categoryId, $brandId, $name, $sku ?: null, $description, $price, $costPrice, $quantity, $minQuantity, $imagePath, $warrantyMonths, $vehicleType, $status]);
            
            setFlash('success', 'เพิ่มสินค้า "' . htmlspecialchars($name) . '" สำเร็จ');
        }
        header('Location: products.php');
        exit;
    }
    
    // แก้ไขสินค้า
    if ($action === 'edit_product') {
        $productId = (int)$_POST['product_id'];
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $brandId = !empty($_POST['battery_brand_id']) ? (int)$_POST['battery_brand_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $costPrice = (float)($_POST['cost_price'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $minQuantity = (int)($_POST['min_quantity'] ?? 5);
        $warrantyMonths = (int)($_POST['warranty_months'] ?? 12);
        $vehicleType = $_POST['vehicle_type'] ?? 'both';
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            setFlash('danger', 'กรุณากรอกชื่อสินค้า');
        } else {
            // ตรวจสอบ SKU ซ้ำ (ไม่นับตัวเอง)
            if (!empty($sku)) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND id != ?");
                $stmt->execute([$sku, $productId]);
                if ($stmt->fetchColumn() > 0) {
                    setFlash('danger', 'รหัสสินค้า (SKU) นี้มีอยู่แล้ว');
                    header('Location: products.php');
                    exit;
                }
            }
            
            // อัปโหลดรูปภาพใหม่ (รองรับทั้งเปิดกล้องถ่ายภาพ Base64, อัปโหลดไฟล์, หรือลบรูป)
            $imageUpdate = '';
            $imageParam = [];
            if (!empty($_POST['image_base64'])) {
                $newImg = uploadBase64Image($_POST['image_base64'], 'products');
                if ($newImg) {
                    $imageUpdate = ', image = ?';
                    $imageParam = [$newImg];
                }
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $imagePath = uploadFile($_FILES['image'], 'products');
                if ($imagePath) {
                    $imageUpdate = ', image = ?';
                    $imageParam = [$imagePath];
                }
            } elseif (!empty($_POST['remove_image'])) {
                $imageUpdate = ', image = NULL';
            }
            
            $stmt = $db->prepare("
                UPDATE products SET 
                    category_id = ?, battery_brand_id = ?, name = ?, sku = ?, description = ?,
                    price = ?, cost_price = ?, quantity = ?, min_quantity = ?,
                    warranty_months = ?, vehicle_type = ?, status = ? {$imageUpdate}
                WHERE id = ?
            ");
            $params = [$categoryId, $brandId, $name, $sku ?: null, $description, $price, $costPrice, $quantity, $minQuantity, $warrantyMonths, $vehicleType, $status];
            $params = array_merge($params, $imageParam, [$productId]);
            $stmt->execute($params);
            
            setFlash('success', 'แก้ไขสินค้า "' . htmlspecialchars($name) . '" สำเร็จ');
        }
        header('Location: products.php');
        exit;
    }
    
    // ลบสินค้า
    if ($action === 'delete_product') {
        $productId = (int)$_POST['product_id'];
        $stmt = $db->prepare("SELECT name FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        
        if ($product) {
            $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            setFlash('success', 'ลบสินค้า "' . htmlspecialchars($product['name']) . '" สำเร็จ');
        }
        header('Location: products.php');
        exit;
    }
    
    // เพิ่มความเข้ากันได้
    if ($action === 'add_compatibility') {
        $productId = (int)$_POST['product_id'];
        $carBrandId = (int)$_POST['car_brand_id'];
        $carModel = trim($_POST['car_model'] ?? '');
        $yearFrom = trim($_POST['year_from'] ?? '');
        $yearTo = trim($_POST['year_to'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        $stmt = $db->prepare("INSERT INTO product_compatibility (product_id, car_brand_id, car_model, year_from, year_to, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$productId, $carBrandId, $carModel ?: null, $yearFrom ?: null, $yearTo ?: null, $notes ?: null]);
        
        setFlash('success', 'เพิ่มข้อมูลความเข้ากันได้สำเร็จ');
        header('Location: products.php?edit=' . $productId . '#compatibility');
        exit;
    }
    
    // ลบความเข้ากันได้
    if ($action === 'delete_compatibility') {
        $compId = (int)$_POST['comp_id'];
        $productId = (int)$_POST['product_id'];
        
        $stmt = $db->prepare("DELETE FROM product_compatibility WHERE id = ?");
        $stmt->execute([$compId]);
        
        setFlash('success', 'ลบข้อมูลความเข้ากันได้สำเร็จ');
        header('Location: products.php?edit=' . $productId . '#compatibility');
        exit;
    }
    
    // เพิ่มสินค้าแนะนำ
    if ($action === 'add_recommended') {
        $productId = (int)$_POST['product_id'];
        $carBrandId = (int)$_POST['car_brand_id'];
        $carModel = trim($_POST['car_model'] ?? '');
        $priority = (int)($_POST['priority'] ?? 0);
        
        $stmt = $db->prepare("INSERT INTO recommended_products (product_id, car_brand_id, car_model, priority) VALUES (?, ?, ?, ?)");
        $stmt->execute([$productId, $carBrandId, $carModel ?: null, $priority]);
        
        setFlash('success', 'เพิ่มสินค้าแนะนำสำเร็จ');
        header('Location: products.php?edit=' . $productId . '#recommended');
        exit;
    }
    
    // ลบสินค้าแนะนำ
    if ($action === 'delete_recommended') {
        $recId = (int)$_POST['rec_id'];
        $productId = (int)$_POST['product_id'];
        
        $stmt = $db->prepare("DELETE FROM recommended_products WHERE id = ?");
        $stmt->execute([$recId]);
        
        setFlash('success', 'ลบสินค้าแนะนำสำเร็จ');
        header('Location: products.php?edit=' . $productId . '#recommended');
        exit;
    }
}

// ===== ดึงข้อมูลสำหรับแสดงผล =====

// ดึงหมวดหมู่
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// ดึงยี่ห้อแบตเตอรี่
$batteryBrands = $db->query("SELECT * FROM battery_brands ORDER BY name")->fetchAll();

// ดึงยี่ห้อรถ
$carBrands = $db->query("SELECT * FROM car_brands ORDER BY name")->fetchAll();

// ค้นหา/กรองสินค้า
$search = trim($_GET['search'] ?? '');
$filterCategory = $_GET['category'] ?? '';
$filterBrand = $_GET['brand'] ?? '';

$where = ["1=1"];
$params = [];

if ($search) {
    $where[] = "(p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterCategory) {
    $where[] = "p.category_id = ?";
    $params[] = (int)$filterCategory;
}
if ($filterBrand) {
    $where[] = "p.battery_brand_id = ?";
    $params[] = (int)$filterBrand;
}

$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT p.*, c.name AS category_name, bb.name AS brand_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
    WHERE {$whereClause}
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$products = $stmt->fetchAll();

// ถ้ากำลังแก้ไขสินค้า ดึงข้อมูลเพิ่มเติม
$editProduct = null;
$editCompatibility = [];
$editRecommended = [];
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$editId]);
    $editProduct = $stmt->fetch();
    
    if ($editProduct) {
        // ดึงข้อมูลความเข้ากันได้
        $stmt = $db->prepare("
            SELECT pc.*, cb.name AS car_brand_name 
            FROM product_compatibility pc 
            JOIN car_brands cb ON pc.car_brand_id = cb.id 
            WHERE pc.product_id = ? 
            ORDER BY cb.name, pc.car_model
        ");
        $stmt->execute([$editId]);
        $editCompatibility = $stmt->fetchAll();
        
        // ดึงข้อมูลสินค้าแนะนำ
        $stmt = $db->prepare("
            SELECT rp.*, cb.name AS car_brand_name 
            FROM recommended_products rp 
            JOIN car_brands cb ON rp.car_brand_id = cb.id 
            WHERE rp.product_id = ? 
            ORDER BY rp.priority, cb.name
        ");
        $stmt->execute([$editId]);
        $editRecommended = $stmt->fetchAll();
    }
}

$pageTitle = 'จัดการสินค้า';
require_once 'header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-box-seam"></i> จัดการสินค้า</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
        <i class="bi bi-plus-lg"></i> เพิ่มสินค้า
    </button>
</div>

<!-- ฟิลเตอร์ค้นหา -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="ค้นหาชื่อสินค้า / SKU..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="category" class="form-select">
                    <option value="">-- หมวดหมู่ทั้งหมด --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="brand" class="form-select">
                    <option value="">-- ยี่ห้อทั้งหมด --</option>
                    <?php foreach ($batteryBrands as $bb): ?>
                        <option value="<?= $bb['id'] ?>" <?= $filterBrand == $bb['id'] ? 'selected' : '' ?>><?= htmlspecialchars($bb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> กรอง</button>
            </div>
        </form>
    </div>
</div>

<!-- สินค้าใกล้หมด -->
<?php
$lowStockItems = array_filter($products, function($p) { return $p['quantity'] <= $p['min_quantity'] && $p['status'] === 'active'; });
if (count($lowStockItems) > 0):
?>
<div class="low-stock-alert mb-4">
    <h6 class="mb-2"><i class="bi bi-exclamation-triangle-fill text-danger"></i> สินค้าใกล้หมดสต๊อก (<?= count($lowStockItems) ?> รายการ)</h6>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($lowStockItems as $ls): ?>
            <span class="badge bg-danger"><?= htmlspecialchars($ls['name']) ?> (เหลือ <?= $ls['quantity'] ?>)</span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ตารางสินค้า -->
<div class="table-container">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th style="width:60px;">รูป</th>
                    <th>ชื่อสินค้า</th>
                    <th>SKU</th>
                    <th>ยี่ห้อ</th>
                    <th class="text-end">ราคา</th>
                    <th class="text-center">สต๊อก</th>
                    <th class="text-center">สถานะ</th>
                    <th class="text-center">ประกัน</th>
                    <th class="text-center" style="width:120px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i>ไม่พบสินค้า</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <?php 
                            $isLow = $p['quantity'] <= $p['min_quantity'] && $p['status'] === 'active';
                            $rowClass = $isLow ? 'table-danger' : '';
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td>
                                <?php if ($p['image']): ?>
                                    <img src="<?= SITE_URL . htmlspecialchars($p['image']) ?>" alt="" class="rounded" style="width:45px;height:45px;object-fit:cover;">
                                <?php else: ?>
                                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width:45px;height:45px;">
                                        <i class="bi bi-image text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($p['name']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($p['category_name'] ?? '-') ?></small>
                                <?php if ($p['vehicle_type'] === 'car'): ?>
                                    <span class="badge bg-info badge-status ms-1">รถยนต์</span>
                                <?php elseif ($p['vehicle_type'] === 'motorcycle'): ?>
                                    <span class="badge bg-warning badge-status ms-1">มอเตอร์ไซค์</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary badge-status ms-1">ทั้งหมด</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= htmlspecialchars($p['sku'] ?? '-') ?></code></td>
                            <td><?= htmlspecialchars($p['brand_name'] ?? '-') ?></td>
                            <td class="text-end fw-bold"><?= number_format($p['price'], 2) ?></td>
                            <td class="text-center">
                                <?php if ($isLow): ?>
                                    <span class="badge bg-danger badge-status">
                                        <i class="bi bi-exclamation-triangle-fill"></i> <?= $p['quantity'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success badge-status"><?= $p['quantity'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($p['status'] === 'active'): ?>
                                    <span class="badge bg-success badge-status">ใช้งาน</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary badge-status">ปิดใช้งาน</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= $p['warranty_months'] ?> เดือน</td>
                            <td class="text-center">
                                <a href="products.php?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบสินค้า: <?= htmlspecialchars(addslashes($p['name'])) ?> ?');">
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="p-3 border-top bg-light">
        <small class="text-muted">ทั้งหมด <?= count($products) ?> รายการ</small>
    </div>
</div>

<!-- ===== Modal เพิ่มสินค้า ===== -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_product">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-lg"></i> เพิ่มสินค้าใหม่</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">หมวดหมู่</label>
                            <select name="category_id" class="form-select">
                                <option value="">-- เลือกหมวดหมู่ --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ยี่ห้อแบตเตอรี่</label>
                            <select name="battery_brand_id" class="form-select">
                                <option value="">-- เลือกยี่ห้อ --</option>
                                <?php foreach ($batteryBrands as $bb): ?>
                                    <option value="<?= $bb['id'] ?>"><?= htmlspecialchars($bb['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">ชื่อสินค้า <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">รหัสสินค้า (SKU)</label>
                            <input type="text" name="sku" class="form-control" placeholder="เช่น BAT-3K-NS60L">
                        </div>
                        <div class="col-12">
                            <label class="form-label">รายละเอียด</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ราคาขาย (บาท) <span class="text-danger">*</span></label>
                            <input type="number" name="price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ราคาทุน (บาท)</label>
                            <input type="number" name="cost_price" class="form-control" step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">จำนวน</label>
                            <input type="number" name="quantity" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">จำนวนขั้นต่ำ</label>
                            <input type="number" name="min_quantity" class="form-control" min="0" value="5">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ประกัน (เดือน)</label>
                            <input type="number" name="warranty_months" class="form-control" min="0" value="12">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ประเภทรถ</label>
                            <select name="vehicle_type" class="form-select">
                                <option value="both">ทั้งหมด</option>
                                <option value="car">รถยนต์</option>
                                <option value="motorcycle">มอเตอร์ไซค์</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">สถานะ</label>
                            <select name="status" class="form-select">
                                <option value="active">ใช้งาน</option>
                                <option value="inactive">ปิดใช้งาน</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="bi bi-camera"></i> รูปภาพสินค้า</label>
                            
                            <!-- Hidden inputs for file & camera base64 -->
                            <input type="hidden" name="image_base64" id="addProdBase64">
                            <input type="file" name="image" id="addProdFileInput" class="d-none" accept="image/*" onchange="previewImage(this, 'addPreview', 'addProdBase64')">
                            <input type="file" id="addProdNativeInput" class="d-none" accept="image/*" capture="environment" onchange="previewImage(this, 'addPreview', 'addProdBase64')">
                            
                            <!-- Action Buttons for all platforms -->
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <button type="button" class="btn btn-outline-primary" onclick="triggerCamera('addProdBase64', 'addPreview', 'addProdNativeInput')">
                                    <i class="bi bi-camera-fill me-1"></i> เปิดกล้องถ่ายภาพ (Webcam / มือถือ)
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('addProdFileInput').click()">
                                    <i class="bi bi-folder2-open me-1"></i> เลือกไฟล์รูปจากเครื่อง
                                </button>
                            </div>

                            <!-- Preview container -->
                            <div id="addPreviewWrap" class="photo-preview-wrap p-2 border rounded bg-light d-none" style="max-width: 340px;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-success fw-bold"><i class="bi bi-check2-circle"></i> ภาพที่เลือก/ถ่ายแล้ว:</small>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="clearCapturedPhoto('addProdBase64', 'addPreview', 'addProdFileInput')">
                                        <i class="bi bi-x-circle"></i> ลบรูป
                                    </button>
                                </div>
                                <img id="addPreview" src="" class="img-fluid rounded border shadow-sm w-100" style="max-height: 220px; object-fit: contain;">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== ส่วนแก้ไขสินค้า ===== -->
<?php if ($editProduct): ?>
<div class="card border-0 shadow-sm mt-4" id="editSection">
    <div class="card-header bg-warning text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-pencil-square"></i> แก้ไขสินค้า: <?= htmlspecialchars($editProduct['name']) ?></h5>
        <a href="products.php" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i> ปิด</a>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_product">
            <input type="hidden" name="product_id" value="<?= $editProduct['id'] ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">หมวดหมู่</label>
                    <select name="category_id" class="form-select">
                        <option value="">-- เลือกหมวดหมู่ --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $editProduct['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ยี่ห้อแบตเตอรี่</label>
                    <select name="battery_brand_id" class="form-select">
                        <option value="">-- เลือกยี่ห้อ --</option>
                        <?php foreach ($batteryBrands as $bb): ?>
                            <option value="<?= $bb['id'] ?>" <?= $editProduct['battery_brand_id'] == $bb['id'] ? 'selected' : '' ?>><?= htmlspecialchars($bb['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">ชื่อสินค้า <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editProduct['name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">รหัสสินค้า (SKU)</label>
                    <input type="text" name="sku" class="form-control" value="<?= htmlspecialchars($editProduct['sku'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">รายละเอียด</label>
                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ราคาขาย (บาท)</label>
                    <input type="number" name="price" class="form-control" step="0.01" min="0" value="<?= $editProduct['price'] ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ราคาทุน (บาท)</label>
                    <input type="number" name="cost_price" class="form-control" step="0.01" min="0" value="<?= $editProduct['cost_price'] ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">จำนวน</label>
                    <input type="number" name="quantity" class="form-control" min="0" value="<?= $editProduct['quantity'] ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">จำนวนขั้นต่ำ</label>
                    <input type="number" name="min_quantity" class="form-control" min="0" value="<?= $editProduct['min_quantity'] ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">ประกัน (เดือน)</label>
                    <input type="number" name="warranty_months" class="form-control" min="0" value="<?= $editProduct['warranty_months'] ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">ประเภทรถ</label>
                    <select name="vehicle_type" class="form-select">
                        <option value="both" <?= $editProduct['vehicle_type'] === 'both' ? 'selected' : '' ?>>ทั้งหมด</option>
                        <option value="car" <?= $editProduct['vehicle_type'] === 'car' ? 'selected' : '' ?>>รถยนต์</option>
                        <option value="motorcycle" <?= $editProduct['vehicle_type'] === 'motorcycle' ? 'selected' : '' ?>>มอเตอร์ไซค์</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">สถานะ</label>
                    <select name="status" class="form-select">
                        <option value="active" <?= $editProduct['status'] === 'active' ? 'selected' : '' ?>>ใช้งาน</option>
                        <option value="inactive" <?= $editProduct['status'] === 'inactive' ? 'selected' : '' ?>>ปิดใช้งาน</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="bi bi-camera"></i> รูปภาพสินค้า</label>
                    
                    <!-- Hidden inputs for file & camera base64 -->
                    <input type="hidden" name="image_base64" id="editProdBase64">
                    <input type="file" name="image" id="editProdFileInput" class="d-none" accept="image/*" onchange="previewImage(this, 'editPreview', 'editProdBase64')">
                    <input type="file" id="editProdNativeInput" class="d-none" accept="image/*" capture="environment" onchange="previewImage(this, 'editPreview', 'editProdBase64')">
                    
                    <!-- Action Buttons for all platforms -->
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button type="button" class="btn btn-outline-primary" onclick="triggerCamera('editProdBase64', 'editPreview', 'editProdNativeInput')">
                            <i class="bi bi-camera-fill me-1"></i> เปิดกล้องถ่ายภาพ (Webcam / มือถือ)
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('editProdFileInput').click()">
                            <i class="bi bi-folder2-open me-1"></i> เลือกไฟล์รูปใหม่
                        </button>
                    </div>

                    <!-- Preview container for new photo -->
                    <div id="editPreviewWrap" class="photo-preview-wrap p-2 border rounded bg-light mb-2 d-none" style="max-width: 320px;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-success fw-bold"><i class="bi bi-check2-circle"></i> ภาพใหม่ที่เลือก/ถ่ายแล้ว:</small>
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="clearCapturedPhoto('editProdBase64', 'editPreview', 'editProdFileInput')">
                                <i class="bi bi-x-circle"></i> ยกเลิกภาพใหม่
                            </button>
                        </div>
                        <img id="editPreview" src="" class="img-fluid rounded border shadow-sm w-100" style="max-height: 200px; object-fit: contain;">
                    </div>

                    <?php if ($editProduct['image']): ?>
                        <div class="p-2 border rounded bg-white" style="max-width: 320px;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="text-muted"><i class="bi bi-image"></i> รูปปัจจุบันในระบบ:</small>
                                <label class="form-check-label text-danger small" style="cursor:pointer;">
                                    <input type="checkbox" name="remove_image" value="1" class="form-check-input me-1"> ลบรูปนี้
                                </label>
                            </div>
                            <img src="<?= SITE_URL . htmlspecialchars($editProduct['image']) ?>" class="img-fluid rounded border" style="max-height:160px; object-fit: contain;">
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-warning"><i class="bi bi-save"></i> บันทึกการแก้ไข</button>
                <a href="products.php" class="btn btn-secondary">ยกเลิก</a>
            </div>
        </form>
    </div>
</div>

<!-- ===== ส่วนความเข้ากันได้ ===== -->
<div class="card border-0 shadow-sm mt-4" id="compatibility">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-car-front"></i> ความเข้ากันได้กับรถยนต์</h5>
    </div>
    <div class="card-body">
        <!-- ฟอร์มเพิ่ม -->
        <form method="POST" class="row g-2 mb-3 p-3 bg-light rounded">
            <input type="hidden" name="action" value="add_compatibility">
            <input type="hidden" name="product_id" value="<?= $editProduct['id'] ?>">
            <div class="col-md-3">
                <select name="car_brand_id" class="form-select" required>
                    <option value="">-- ยี่ห้อรถ --</option>
                    <?php foreach ($carBrands as $cb): ?>
                        <option value="<?= $cb['id'] ?>"><?= htmlspecialchars($cb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <input type="text" name="car_model" class="form-control" placeholder="รุ่นรถ (เช่น Vios)">
            </div>
            <div class="col-md-2">
                <input type="text" name="year_from" class="form-control" placeholder="ปีเริ่มต้น" maxlength="4">
            </div>
            <div class="col-md-2">
                <input type="text" name="year_to" class="form-control" placeholder="ปีสิ้นสุด" maxlength="4">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-info text-white w-100"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
            </div>
        </form>
        
        <!-- ตารางแสดงผล -->
        <?php if (empty($editCompatibility)): ?>
            <p class="text-muted text-center py-3"><i class="bi bi-info-circle"></i> ยังไม่มีข้อมูลความเข้ากันได้</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>ยี่ห้อรถ</th>
                            <th>รุ่น</th>
                            <th>ปี</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($editCompatibility as $comp): ?>
                            <tr>
                                <td><?= htmlspecialchars($comp['car_brand_name']) ?></td>
                                <td><?= htmlspecialchars($comp['car_model'] ?? '-') ?></td>
                                <td><?= ($comp['year_from'] || $comp['year_to']) ? htmlspecialchars(($comp['year_from'] ?? '?') . ' - ' . ($comp['year_to'] ?? 'ปัจจุบัน')) : '-' ?></td>
                                <td>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบ?');">
                                        <input type="hidden" name="action" value="delete_compatibility">
                                        <input type="hidden" name="comp_id" value="<?= $comp['id'] ?>">
                                        <input type="hidden" name="product_id" value="<?= $editProduct['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== ส่วนสินค้าแนะนำ ===== -->
<div class="card border-0 shadow-sm mt-4 mb-4" id="recommended">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-star"></i> สินค้าแนะนำ</h5>
    </div>
    <div class="card-body">
        <!-- ฟอร์มเพิ่ม -->
        <form method="POST" class="row g-2 mb-3 p-3 bg-light rounded">
            <input type="hidden" name="action" value="add_recommended">
            <input type="hidden" name="product_id" value="<?= $editProduct['id'] ?>">
            <div class="col-md-4">
                <select name="car_brand_id" class="form-select" required>
                    <option value="">-- ยี่ห้อรถ --</option>
                    <?php foreach ($carBrands as $cb): ?>
                        <option value="<?= $cb['id'] ?>"><?= htmlspecialchars($cb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <input type="text" name="car_model" class="form-control" placeholder="รุ่นรถ (เช่น Civic)">
            </div>
            <div class="col-md-2">
                <input type="number" name="priority" class="form-control" placeholder="ลำดับ" min="0" value="0">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
            </div>
        </form>
        
        <!-- ตารางแสดงผล -->
        <?php if (empty($editRecommended)): ?>
            <p class="text-muted text-center py-3"><i class="bi bi-info-circle"></i> ยังไม่มีข้อมูลสินค้าแนะนำ</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>ยี่ห้อรถ</th>
                            <th>รุ่น</th>
                            <th class="text-center">ลำดับ</th>
                            <th class="text-center">สถานะ</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($editRecommended as $rec): ?>
                            <tr>
                                <td><?= htmlspecialchars($rec['car_brand_name']) ?></td>
                                <td><?= htmlspecialchars($rec['car_model'] ?? '-') ?></td>
                                <td class="text-center"><?= $rec['priority'] ?></td>
                                <td class="text-center">
                                    <?= $rec['is_active'] ? '<span class="badge bg-success">เปิด</span>' : '<span class="badge bg-secondary">ปิด</span>' ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบ?');">
                                        <input type="hidden" name="action" value="delete_recommended">
                                        <input type="hidden" name="rec_id" value="<?= $rec['id'] ?>">
                                        <input type="hidden" name="product_id" value="<?= $editProduct['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Scroll to edit section
window.addEventListener('load', function() {
    const editSection = document.getElementById('editSection');
    if (editSection) {
        editSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    // If hash is present, scroll to that section
    if (window.location.hash) {
        const target = document.querySelector(window.location.hash);
        if (target) {
            setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
        }
    }
});
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
