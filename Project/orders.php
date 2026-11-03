<?php
require_once 'config.php';
requireLogin();
$pageTitle = 'สั่งซื้อสินค้า';

$db = getDB();
$userId = $_SESSION['user_id'];

// ดึงรถของลูกค้า
$stmtVehicles = $db->prepare("SELECT mv.*, cb.name AS brand_name FROM member_vehicles mv JOIN car_brands cb ON mv.brand_id = cb.id WHERE mv.user_id = ?");
$stmtVehicles->execute([$userId]);
$vehicles = $stmtVehicles->fetchAll();

// ===== AJAX: ดึงสินค้าแนะนำตาม vehicle_id =====
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_products' && isset($_GET['vehicle_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $vehicleId = (int)$_GET['vehicle_id'];
    
    // ดึงข้อมูลรถ
    $stmtV = $db->prepare("SELECT mv.*, cb.name AS brand_name FROM member_vehicles mv JOIN car_brands cb ON mv.brand_id = cb.id WHERE mv.id = ? AND mv.user_id = ?");
    $stmtV->execute([$vehicleId, $userId]);
    $vehicle = $stmtV->fetch();
    
    if (!$vehicle) {
        echo json_encode(['error' => 'ไม่พบข้อมูลรถ']);
        exit;
    }
    
    // ดึงสินค้าแนะนำ
    $stmtRec = $db->prepare("
        SELECT DISTINCT p.*, bb.name AS brand_name,
            CASE WHEN rp.id IS NOT NULL THEN 1 ELSE 0 END AS is_recommended
        FROM products p
        LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
        LEFT JOIN recommended_products rp ON p.id = rp.product_id AND rp.car_brand_id = ? AND (rp.car_model = ? OR rp.car_model IS NULL) AND rp.is_active = 1
        LEFT JOIN product_compatibility pc ON p.id = pc.product_id AND pc.car_brand_id = ? AND (pc.car_model = ? OR pc.car_model IS NULL)
        WHERE p.status = 'active' AND p.quantity > 0
            AND (p.vehicle_type = ? OR p.vehicle_type = 'both')
            AND (rp.id IS NOT NULL OR pc.id IS NOT NULL)
        ORDER BY is_recommended DESC, p.name ASC
    ");
    $stmtRec->execute([$vehicle['brand_id'], $vehicle['model'], $vehicle['brand_id'], $vehicle['model'], $vehicle['vehicle_type']]);
    $products = $stmtRec->fetchAll();
    
    // ถ้าไม่พบการจับคู่เฉพาะรุ่น ให้ดึงแบตเตอรี่ทั้งหมดที่เหมาะกับประเภทรถ (car/motorcycle)
    if (empty($products)) {
        $stmtFallback = $db->prepare("
            SELECT DISTINCT p.*, bb.name AS brand_name, 0 AS is_recommended
            FROM products p
            LEFT JOIN battery_brands bb ON p.battery_brand_id = bb.id
            WHERE p.status = 'active' AND p.quantity > 0
                AND (p.vehicle_type = ? OR p.vehicle_type = 'both')
            ORDER BY p.name ASC
        ");
        $stmtFallback->execute([$vehicle['vehicle_type']]);
        $products = $stmtFallback->fetchAll();
    }
    
    echo json_encode(['products' => $products, 'vehicle' => $vehicle]);
    exit;
}

// ===== จัดการสร้างคำสั่งซื้อ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    $vehicleId = (int)$_POST['vehicle_id'];
    $productId = (int)$_POST['product_id'];
    $quantity = max(1, (int)$_POST['quantity']);
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $deliveryLat = !empty($_POST['delivery_lat']) ? (float)$_POST['delivery_lat'] : null;
    $deliveryLng = !empty($_POST['delivery_lng']) ? (float)$_POST['delivery_lng'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    // ตรวจสอบรถเป็นของลูกค้า
    $stmtCheckV = $db->prepare("SELECT id FROM member_vehicles WHERE id = ? AND user_id = ?");
    $stmtCheckV->execute([$vehicleId, $userId]);
    if (!$stmtCheckV->fetch()) {
        setFlash('danger', 'ไม่พบข้อมูลรถ');
        header('Location: orders.php');
        exit;
    }
    
    // ตรวจสอบสินค้า
    $stmtCheckP = $db->prepare("SELECT * FROM products WHERE id = ? AND status = 'active' AND quantity >= ?");
    $stmtCheckP->execute([$productId, $quantity]);
    $product = $stmtCheckP->fetch();
    if (!$product) {
        setFlash('danger', 'สินค้าไม่พร้อมจำหน่ายหรือสต๊อกไม่เพียงพอ');
        header('Location: orders.php');
        exit;
    }
    
    $totalAmount = $product['price'] * $quantity;
    $orderNumber = generateOrderNumber();
    
    // อัปโหลดรูปแบตเตอรี่เก่า: รองรับทั้งถ่ายจากกล้อง Base64 และไฟล์จากเครื่อง
    $oldBatteryImage = null;
    if (!empty($_POST['old_battery_base64'])) {
        $oldBatteryImage = uploadBase64Image($_POST['old_battery_base64'], 'orders');
    } else {
        $imageField = null;
        foreach (['old_battery_camera', 'old_battery_gallery', 'old_battery_image'] as $field) {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                $imageField = $field;
                break;
            }
        }
        if ($imageField !== null) {
            $uploadedImage = uploadFile($_FILES[$imageField], 'orders');
            if ($uploadedImage === false) {
                setFlash('danger', 'อัปโหลดรูปไม่สำเร็จ กรุณาใช้ไฟล์รูปภาพ (JPG, PNG, WEBP) และลองใหม่');
                header('Location: orders.php');
                exit;
            }
            $oldBatteryImage = $uploadedImage;
        }
    }
    
    try {
        $db->beginTransaction();
        
        // สร้างคำสั่งซื้อ
        $stmtOrder = $db->prepare("
            INSERT INTO orders (customer_id, vehicle_id, order_number, total_amount, delivery_address, delivery_lat, delivery_lng, old_battery_image, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmtOrder->execute([$userId, $vehicleId, $orderNumber, $totalAmount, $deliveryAddress, $deliveryLat, $deliveryLng, $oldBatteryImage, $notes]);
        $orderId = $db->lastInsertId();
        
        // สร้างรายการสินค้า
        $stmtItem = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmtItem->execute([$orderId, $productId, $quantity, $product['price']]);
        
        // แจ้งเตือน admin/staff
        $stmtStaff = $db->query("SELECT id FROM users WHERE role IN ('admin', 'staff')");
        $staffUsers = $stmtStaff->fetchAll();
        foreach ($staffUsers as $staff) {
            createNotification(
                $staff['id'],
                'คำสั่งซื้อใหม่ #' . $orderNumber,
                'ลูกค้า ' . htmlspecialchars($_SESSION['fullname']) . ' สั่งซื้อ ' . htmlspecialchars($product['name']) . ' จำนวน ' . $quantity . ' ชิ้น',
                'new_order',
                'manage_orders.php?view=' . $orderId
            );
        }
        
        $db->commit();
        setFlash('success', 'สร้างคำสั่งซื้อ #' . $orderNumber . ' สำเร็จ! กรุณารอการยืนยันจากทางร้าน');
        header('Location: orders.php');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        header('Location: orders.php');
        exit;
    }
}

// ===== ลูกค้ายกเลิกคำสั่งซื้อ (เฉพาะสถานะรอดำเนินการ) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $orderId = (int)$_POST['order_id'];
    $stmtChk = $db->prepare("SELECT * FROM orders WHERE id = ? AND customer_id = ? AND status = 'pending'");
    $stmtChk->execute([$orderId, $userId]);
    $ord = $stmtChk->fetch();
    if ($ord) {
        $stmtUpd = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
        $stmtUpd->execute([$orderId]);

        // คืนสต๊อกสินค้า
        $stmtItems = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $stmtItems->execute([$orderId]);
        foreach ($stmtItems->fetchAll() as $item) {
            $db->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
        }

        // แจ้งเตือนแอดมิน/ช่าง
        $admins = $db->query("SELECT id FROM users WHERE role IN ('admin', 'staff')")->fetchAll();
        foreach ($admins as $adm) {
            createNotification($adm['id'], 'ลูกค้ายกเลิกคำสั่งซื้อ #' . $ord['order_number'], 'ลูกค้ายกเลิกคำสั่งซื้อเรียบร้อยแล้ว', 'general', 'manage_orders.php?view=' . $orderId);
        }

        setFlash('success', 'ยกเลิกคำสั่งซื้อ #' . $ord['order_number'] . ' เรียบร้อยแล้ว');
    } else {
        setFlash('danger', 'ไม่สามารถยกเลิกคำสั่งซื้อนี้ได้ (อาจได้รับการยืนยันหรือดำเนินการแล้ว)');
    }
    header('Location: orders.php');
    exit;
}

// ===== ดึงประวัติคำสั่งซื้อ =====
$stmtHistory = $db->prepare("
    SELECT o.*, mv.license_plate, mv.model AS vehicle_model, cb.name AS vehicle_brand,
        (SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR ', ') 
         FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id) AS items_summary
    FROM orders o
    LEFT JOIN member_vehicles mv ON o.vehicle_id = mv.id
    LEFT JOIN car_brands cb ON mv.brand_id = cb.id
    WHERE o.customer_id = ?
    ORDER BY o.created_at DESC
");
$stmtHistory->execute([$userId]);
$orderHistory = $stmtHistory->fetchAll();

$statusLabels = [
    'pending' => ['รอดำเนินการ', 'bg-warning text-dark'],
    'confirmed' => ['ยืนยันแล้ว', 'bg-info text-white'],
    'processing' => ['กำลังจัดเตรียม', 'bg-primary'],
    'shipped' => ['จัดส่งแล้ว', 'bg-success'],
    'delivered' => ['ส่งมอบแล้ว', 'bg-success'],
    'cancelled' => ['ยกเลิก', 'bg-danger'],
];

require_once 'header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-cart3"></i> สั่งซื้อสินค้า</h4>
</div>

<?php if (empty($vehicles)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-car-front display-1 text-muted"></i>
        <h5 class="mt-3">คุณยังไม่มีข้อมูลรถ</h5>
        <p class="text-muted">กรุณาเพิ่มข้อมูลรถก่อนสั่งซื้อสินค้า</p>
        <a href="<?= SITE_URL ?>vehicles.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> เพิ่มข้อมูลรถ
        </a>
    </div>
</div>
<?php else: ?>

<!-- ขั้นตอนที่ 1: เลือกรถ -->
<div class="card border-0 shadow-sm mb-4" id="step1">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0"><i class="bi bi-1-circle"></i> เลือกรถของคุณ</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($vehicles as $v): ?>
            <div class="col-md-4 col-sm-6">
                <div class="card h-100 vehicle-card border-2" style="cursor:pointer;" onclick="selectVehicle(<?= $v['id'] ?>, this)">
                    <div class="card-body text-center">
                        <i class="bi bi-<?= $v['vehicle_type'] === 'car' ? 'car-front' : 'bicycle' ?> display-4 text-primary"></i>
                        <h6 class="mt-2 mb-1"><?= htmlspecialchars($v['brand_name'] . ' ' . $v['model']) ?></h6>
                        <span class="badge bg-secondary"><?= htmlspecialchars($v['license_plate']) ?></span>
                        <?php if ($v['year']): ?>
                        <small class="d-block text-muted mt-1">ปี <?= htmlspecialchars($v['year']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ขั้นตอนที่ 2: เลือกสินค้า -->
<div class="card border-0 shadow-sm mb-4 d-none" id="step2">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0"><i class="bi bi-2-circle"></i> เลือกสินค้าที่แนะนำ</h6>
    </div>
    <div class="card-body">
        <div id="productsLoading" class="text-center py-4 d-none">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2">กำลังค้นหาสินค้าที่เหมาะกับรถของคุณ...</p>
        </div>
        <div id="productsContainer"></div>
        <div id="noProducts" class="text-center py-4 d-none">
            <i class="bi bi-box-seam display-4 text-muted"></i>
            <p class="mt-2 text-muted">ไม่พบสินค้าที่แนะนำสำหรับรถรุ่นนี้ กรุณาติดต่อร้านค้า</p>
        </div>
    </div>
</div>

<!-- ขั้นตอนที่ 3: กรอกข้อมูลจัดส่ง -->
<div class="card border-0 shadow-sm mb-4 d-none" id="step3">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0"><i class="bi bi-3-circle"></i> ข้อมูลการจัดส่ง</h6>
    </div>
    <div class="card-body">
        <form id="orderForm" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_order">
            <input type="hidden" name="vehicle_id" id="selectedVehicleId">
            <input type="hidden" name="product_id" id="selectedProductId">
            
            <div class="row g-3">
                <!-- สรุปที่เลือก -->
                <div class="col-12">
                    <div class="alert alert-info" id="orderSummary">
                        <strong>สรุปการสั่งซื้อ:</strong>
                        <span id="summaryText"></span>
                    </div>
                </div>
                
                <!-- จำนวน -->
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-hash"></i> จำนวน</label>
                    <input type="number" class="form-control" name="quantity" id="orderQuantity" value="1" min="1" max="99" required onchange="updateTotal()">
                </div>
                
                <!-- ราคารวม -->
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-cash"></i> ราคารวม</label>
                    <input type="text" class="form-control" id="totalPrice" readonly>
                </div>
                
                <!-- ที่อยู่จัดส่ง -->
                <div class="col-12">
                    <label class="form-label"><i class="bi bi-geo-alt"></i> ที่อยู่จัดส่ง</label>
                    <textarea class="form-control" name="delivery_address" rows="3" placeholder="กรอกที่อยู่สำหรับจัดส่ง..." required></textarea>
                </div>
                
                <!-- ตำแหน่ง GPS -->
                <div class="col-12">
                    <label class="form-label"><i class="bi bi-pin-map"></i> ตำแหน่ง GPS</label>
                    <div class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="delivery_lat" id="deliveryLat">
                        <input type="hidden" name="delivery_lng" id="deliveryLng">
                        <button type="button" class="btn btn-outline-primary" id="gpsBtn" onclick="getLocation('deliveryLat','deliveryLng','gpsBtn')">
                            <i class="bi bi-geo-alt"></i> ระบุตำแหน่ง GPS
                        </button>
                        <small class="text-muted">เพื่อความสะดวกในการจัดส่ง</small>
                    </div>
                </div>
                
                <!-- รูปแบตเตอรี่เก่า: รองรับทั้งเปิดกล้องสดและเลือกรูป -->
                <div class="col-12">
                    <label class="form-label"><i class="bi bi-camera"></i> รูปแบตเตอรี่เก่า (ถ้ามี)</label>
                    <input type="hidden" name="old_battery_base64" id="oldBatteryBase64">
                    <div class="d-flex flex-column flex-sm-row gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="triggerCamera('oldBatteryBase64', 'oldBatteryPreview', 'oldBatteryCameraInput')">
                            <i class="bi bi-camera-fill"></i> ถ่ายรูปแบตเตอรี่ (เปิดกล้อง)
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('oldBatteryGalleryInput').click()">
                            <i class="bi bi-images"></i> เลือกรูปจากเครื่อง
                        </button>
                    </div>
                    <input type="file" class="form-control d-none" name="old_battery_camera" id="oldBatteryCameraInput" accept="image/*" capture="environment" onchange="previewOrderBatteryImage(this)">
                    <input type="file" class="form-control d-none" name="old_battery_gallery" id="oldBatteryGalleryInput" accept="image/*" onchange="previewOrderBatteryImage(this)">
                    <div class="photo-preview-wrap mt-2 d-none" style="max-width: 340px;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-success fw-bold"><i class="bi bi-check2-circle"></i> ภาพแบตเตอรี่เดิม:</small>
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="clearCapturedPhoto('oldBatteryBase64', 'oldBatteryPreview', 'oldBatteryGalleryInput')">ลบรูป</button>
                        </div>
                        <img id="oldBatteryPreview" class="rounded img-fluid border shadow-sm w-100" alt="ตัวอย่างรูปแบตเตอรี่เก่า" style="max-height: 240px; object-fit: contain;">
                    </div>
                    <div id="oldBatteryImageStatus" class="small text-muted mt-2" aria-live="polite">ถ่ายให้เห็นฉลาก ยี่ห้อ และขนาดแบตเตอรี่ชัดเจนก่อนส่งให้ร้านตรวจสอบ</div>
                </div>
                
                <!-- หมายเหตุ -->
                <div class="col-12">
                    <label class="form-label"><i class="bi bi-chat-dots"></i> หมายเหตุ</label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-success btn-lg w-100" id="submitOrderBtn">
                        <i class="bi bi-check-circle"></i> ยืนยันสั่งซื้อ
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- ประวัติคำสั่งซื้อ -->
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="bi bi-clock-history"></i> ประวัติคำสั่งซื้อของคุณ</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($orderHistory)): ?>
        <div class="text-center py-4">
            <i class="bi bi-inbox display-4 text-muted"></i>
            <p class="mt-2 text-muted">ยังไม่มีคำสั่งซื้อ</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>เลขที่</th>
                        <th>รถ</th>
                        <th>สินค้า</th>
                        <th>ยอดรวม</th>
                        <th>สถานะ</th>
                        <th>วันที่</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderHistory as $order): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                        <td>
                            <?= htmlspecialchars($order['vehicle_brand'] . ' ' . $order['vehicle_model']) ?><br>
                            <small class="text-muted"><?= htmlspecialchars($order['license_plate'] ?? '') ?></small>
                        </td>
                        <td><small><?= htmlspecialchars($order['items_summary'] ?? '-') ?></small></td>
                        <td><?= formatMoney($order['total_amount']) ?></td>
                        <td>
                            <?php $st = $statusLabels[$order['status']] ?? ['ไม่ทราบ', 'bg-secondary']; ?>
                            <span class="badge <?= $st[1] ?>"><?= $st[0] ?></span>
                        </td>
                        <td><small><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></small></td>
                        <td class="text-center">
                            <?php if ($order['status'] === 'pending'): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันยกเลิกคำสั่งซื้อนี้หรือไม่?');">
                                <input type="hidden" name="action" value="cancel_order">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-circle me-1"></i>ยกเลิก
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted small">-</span>
                            <?php endif; ?>
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
let selectedVehicleId = null;
let selectedProductPrice = 0;
let selectedProductName = '';

function selectVehicle(vehicleId, el) {
    // ยกเลิกเลือกเดิม
    document.querySelectorAll('.vehicle-card').forEach(c => c.classList.remove('border-primary', 'bg-light'));
    el.classList.add('border-primary', 'bg-light');
    
    selectedVehicleId = vehicleId;
    document.getElementById('selectedVehicleId').value = vehicleId;
    
    // แสดงขั้นตอนที่ 2
    document.getElementById('step2').classList.remove('d-none');
    document.getElementById('step3').classList.add('d-none');
    document.getElementById('productsLoading').classList.remove('d-none');
    document.getElementById('productsContainer').innerHTML = '';
    document.getElementById('noProducts').classList.add('d-none');
    
    // ดึงสินค้าแนะนำ
    fetch('orders.php?ajax=get_products&vehicle_id=' + vehicleId)
        .then(r => r.json())
        .then(data => {
            document.getElementById('productsLoading').classList.add('d-none');
            if (data.error) {
                document.getElementById('noProducts').classList.remove('d-none');
                return;
            }
            if (data.products.length === 0) {
                document.getElementById('noProducts').classList.remove('d-none');
                return;
            }
            
            let html = '<div class="row g-3">';
            data.products.forEach(p => {
                const recBadge = p.is_recommended == 1 ? '<span class="badge bg-warning text-dark"><i class="bi bi-star-fill"></i> แนะนำ</span>' : '';
                html += `
                <div class="col-md-4 col-sm-6">
                    <div class="card h-100 product-card border-2" style="cursor:pointer;" onclick="selectProduct(${p.id}, ${p.price}, '${p.name.replace(/'/g, "\\'")}', ${p.quantity}, this)">
                        <div class="card-body">
                            ${recBadge}
                            ${p.image ? `<img src="${p.image}" class="img-fluid rounded mb-2" alt="">` : '<div class="text-center py-3"><i class="bi bi-battery-charging display-4 text-muted"></i></div>'}
                            <h6 class="mb-1">${p.name}</h6>
                            <small class="text-muted">${p.brand_name || ''}</small>
                            <div class="mt-2">
                                <strong class="text-primary fs-5">${Number(p.price).toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท</strong>
                            </div>
                            <small class="text-muted">ประกัน ${p.warranty_months} เดือน | คงเหลือ ${p.quantity} ชิ้น</small>
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';
            document.getElementById('productsContainer').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('productsLoading').classList.add('d-none');
            document.getElementById('noProducts').classList.remove('d-none');
        });
    
    // Scroll
    document.getElementById('step2').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function selectProduct(productId, price, name, maxQty, el) {
    document.querySelectorAll('.product-card').forEach(c => c.classList.remove('border-primary', 'bg-light'));
    el.classList.add('border-primary', 'bg-light');
    
    selectedProductPrice = price;
    selectedProductName = name;
    document.getElementById('selectedProductId').value = productId;
    document.getElementById('orderQuantity').max = maxQty;
    document.getElementById('orderQuantity').value = 1;
    
    // แสดงขั้นตอนที่ 3
    document.getElementById('step3').classList.remove('d-none');
    document.getElementById('summaryText').textContent = name + ' - ราคา ' + Number(price).toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
    updateTotal();
    
    document.getElementById('step3').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function updateTotal() {
    const qty = parseInt(document.getElementById('orderQuantity').value) || 1;
    const total = selectedProductPrice * qty;
    document.getElementById('totalPrice').value = total.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
}

// ตรวจสอบก่อนส่งฟอร์ม
document.getElementById('orderForm')?.addEventListener('submit', function(e) {
    if (!document.getElementById('selectedProductId').value) {
        e.preventDefault();
        alert('กรุณาเลือกสินค้า');
        return;
    }
    document.getElementById('submitOrderBtn').disabled = true;
    document.getElementById('submitOrderBtn').innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังส่งคำสั่งซื้อ...';
});

// Auto-select vehicle from URL query if provided
window.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const vId = urlParams.get('vehicle_id');
    if (vId) {
        const card = document.querySelector(`.vehicle-card[onclick*="selectVehicle(${vId}"]`);
        if (card) {
            selectVehicle(parseInt(vId), card);
        }
    }
});

        // เลือกรูปได้ครั้งละหนึ่งภาพ: ถ้าเลือกกล้อง/คลังภาพใหม่ ให้ล้างไฟล์จากช่องอีกช่อง
        function previewOrderBatteryImage(input) {
            const cameraInput = document.getElementById('oldBatteryCameraInput');
            const galleryInput = document.getElementById('oldBatteryGalleryInput');
            const preview = document.getElementById('oldBatteryPreview');
            const status = document.getElementById('oldBatteryImageStatus');
            const base64Input = document.getElementById('oldBatteryBase64');
            if (base64Input) base64Input.value = '';
            if (!input.files || !input.files[0]) return;
            if (input.id === 'oldBatteryCameraInput') galleryInput.value = '';
            else cameraInput.value = '';
            const file = input.files[0];
            const reader = new FileReader();
            reader.onload = function (event) {
                preview.src = event.target.result;
                preview.style.display = 'block';
                const wrap = preview.closest('.photo-preview-wrap');
                if (wrap) wrap.classList.remove('d-none');
                status.textContent = 'เลือกรูปแล้ว: ' + file.name + ' — รูปจะถูกส่งพร้อมคำสั่งซื้อเมื่อกดยืนยัน';
            };
            reader.readAsDataURL(file);
        }
</script>

<?php require_once 'footer.php'; ?>
