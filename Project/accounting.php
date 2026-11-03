<?php
require_once __DIR__ . '/config.php';
requireStaff();

$pageTitle = 'ระบบบัญชีรายรับ-รายจ่าย';
$db = getDB();

// ===== จัดการ POST requests =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. เพิ่มรายการรายรับ-รายจ่าย
    if ($action === 'add_transaction') {
        $type = in_array($_POST['type'] ?? '', ['income', 'expense']) ? $_POST['type'] : 'income';
        $category = trim($_POST['category'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $transactionDate = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d');
        $description = trim($_POST['description'] ?? '');

        if (empty($category) || $amount <= 0) {
            setFlash('danger', 'กรุณาระบุหมวดหมู่และจำนวนเงินที่มากกว่า 0');
        } else {
            $stmt = $db->prepare("
                INSERT INTO accounting (type, category, amount, description, transaction_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$type, $category, $amount, $description ?: null, $transactionDate, $_SESSION['user_id']]);
            setFlash('success', 'บันทึกรายการ' . ($type === 'income' ? 'รายรับ' : 'รายจ่าย') . ' จำนวน ' . number_format($amount, 2) . ' บาท เรียบร้อยแล้ว');
        }
        header('Location: ' . SITE_URL . 'accounting.php');
        exit;
    }

    // 2. แก้ไขรายการรายรับ-รายจ่าย
    if ($action === 'edit_transaction') {
        $id = (int)$_POST['transaction_id'];
        $type = in_array($_POST['type'] ?? '', ['income', 'expense']) ? $_POST['type'] : 'income';
        $category = trim($_POST['category'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $transactionDate = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d');
        $description = trim($_POST['description'] ?? '');

        if (empty($category) || $amount <= 0) {
            setFlash('danger', 'กรุณาระบุหมวดหมู่และจำนวนเงินที่ถูกต้อง');
        } else {
            $stmt = $db->prepare("
                UPDATE accounting SET type = ?, category = ?, amount = ?, description = ?, transaction_date = ?
                WHERE id = ?
            ");
            $stmt->execute([$type, $category, $amount, $description ?: null, $transactionDate, $id]);
            setFlash('success', 'บันทึกการแก้ไขรายการบัญชีสำเร็จ');
        }
        header('Location: ' . SITE_URL . 'accounting.php');
        exit;
    }

    // 3. ลบรายการ
    if ($action === 'delete_transaction') {
        $id = (int)$_POST['transaction_id'];
        $stmt = $db->prepare("DELETE FROM accounting WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('success', 'ลบรายการบัญชีเรียบร้อยแล้ว');
        header('Location: ' . SITE_URL . 'accounting.php');
        exit;
    }
}

// ค้นหาและตัวกรองช่วงเวลา
$filterType = $_GET['type'] ?? '';
$filterPeriod = $_GET['period'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterType === 'income' || $filterType === 'expense') {
    $where[] = "a.type = ?";
    $params[] = $filterType;
}

if ($filterPeriod === 'today') {
    $where[] = "a.transaction_date = CURDATE()";
} elseif ($filterPeriod === 'this_week') {
    $where[] = "YEARWEEK(a.transaction_date, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($filterPeriod === 'this_month') {
    $where[] = "MONTH(a.transaction_date) = MONTH(CURDATE()) AND YEAR(a.transaction_date) = YEAR(CURDATE())";
} elseif ($filterPeriod === 'this_year') {
    $where[] = "YEAR(a.transaction_date) = YEAR(CURDATE())";
} elseif ($filterPeriod === 'custom' && !empty($startDate) && !empty($endDate)) {
    $where[] = "a.transaction_date BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate;
}

if ($search !== '') {
    $where[] = "(a.category LIKE ? OR a.description LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

// คำนวณสรุป รายรับ-รายจ่าย (Calculations)
$calcSql = "
    SELECT 
        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as total_income,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
        COUNT(*) as total_transactions
    FROM accounting a 
    WHERE {$whereClause}
";
$stmtCalc = $db->prepare($calcSql);
$stmtCalc->execute($params);
$totals = $stmtCalc->fetch();

$totalIncome = (float)$totals['total_income'];
$totalExpense = (float)$totals['total_expense'];
$netProfit = $totalIncome - $totalExpense;

// ดึงรายการธุรกรรม
$sqlList = "
    SELECT a.*, u.fullname as creator_name 
    FROM accounting a 
    LEFT JOIN users u ON a.created_by = u.id 
    WHERE {$whereClause} 
    ORDER BY a.transaction_date DESC, a.id DESC
";
$stmtList = $db->prepare($sqlList);
$stmtList->execute($params);
$transactions = $stmtList->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="page-header d-print-none">
    <div>
        <h4 class="mb-1"><i class="bi bi-calculator-fill text-primary me-2"></i>ระบบบัญชี รายรับ-รายจ่าย</h4>
        <small class="text-muted">บันทึก ตรวจสอบ คำนวณ และสรุปยอดกำไร-ขาดทุนของร้านพรชัยแบตเตอรี่ บางพลี</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= SITE_URL ?>receipts.php" class="btn btn-outline-primary">
            <i class="bi bi-receipt me-1"></i> จัดการใบเสร็จ
        </a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> พิมพ์รายงาน
        </button>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTxModal">
            <i class="bi bi-plus-circle-fill me-1"></i> บันทึกรายรับ/รายจ่าย
        </button>
    </div>
</div>

<!-- การ์ดสรุปการคำนวณ รายรับ - รายจ่าย -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card border-start border-success border-4 d-flex align-items-center gap-3">
            <div class="stat-icon bg-success text-white">
                <i class="bi bi-arrow-down-left"></i>
            </div>
            <div>
                <small class="text-muted">รายรับรวม (Total Income)</small>
                <h4 class="mb-0 fw-bold text-success">+<?= number_format($totalIncome, 2) ?> ฿</h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card border-start border-danger border-4 d-flex align-items-center gap-3">
            <div class="stat-icon bg-danger text-white">
                <i class="bi bi-arrow-up-right"></i>
            </div>
            <div>
                <small class="text-muted">รายจ่ายรวม (Total Expense)</small>
                <h4 class="mb-0 fw-bold text-danger">-<?= number_format($totalExpense, 2) ?> ฿</h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card border-start border-<?= $netProfit >= 0 ? 'primary' : 'warning' ?> border-4 d-flex align-items-center gap-3">
            <div class="stat-icon bg-<?= $netProfit >= 0 ? 'primary' : 'warning' ?> text-white">
                <i class="bi bi-wallet2"></i>
            </div>
            <div>
                <small class="text-muted">กำไรสุทธิ / ยอดคงเหลือ (Net Profit)</small>
                <h4 class="mb-0 fw-bold text-<?= $netProfit >= 0 ? 'primary' : 'danger' ?>">
                    <?= ($netProfit >= 0 ? '+' : '') . number_format($netProfit, 2) ?> ฿
                </h4>
            </div>
        </div>
    </div>
</div>

<!-- ช่องค้นหาและตัวกรอง -->
<div class="card border-0 shadow-sm rounded-3 mb-4 d-print-none">
    <div class="card-body">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-md-3">
                <select name="period" class="form-select" id="periodSelect" onchange="toggleCustomDates()">
                    <option value="today" <?= $filterPeriod === 'today' ? 'selected' : '' ?>>วันนี้ (Today)</option>
                    <option value="this_week" <?= $filterPeriod === 'this_week' ? 'selected' : '' ?>>สัปดาห์นี้ (This Week)</option>
                    <option value="this_month" <?= $filterPeriod === 'this_month' ? 'selected' : '' ?>>เดือนนี้ (This Month)</option>
                    <option value="this_year" <?= $filterPeriod === 'this_year' ? 'selected' : '' ?>>ปีนี้ (This Year)</option>
                    <option value="all" <?= $filterPeriod === 'all' ? 'selected' : '' ?>>ทั้งหมด (All Time)</option>
                    <option value="custom" <?= $filterPeriod === 'custom' ? 'selected' : '' ?>>กำหนดช่วงวันที่เอง...</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select">
                    <option value="">ทุกประเภท</option>
                    <option value="income" <?= $filterType === 'income' ? 'selected' : '' ?>>เฉพาะรายรับ (+)</option>
                    <option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>เฉพาะรายจ่าย (-)</option>
                </select>
            </div>
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาหมวดหมู่, คำอธิบาย...">
            </div>
            <div class="col-md-2" id="customDateCol" style="display: <?= $filterPeriod === 'custom' ? 'block' : 'none' ?>;">
                <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div class="col-md-2" id="customDateEndCol" style="display: <?= $filterPeriod === 'custom' ? 'block' : 'none' ?>;">
                <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-secondary w-100"><i class="bi bi-funnel me-1"></i> กรอง</button>
            </div>
        </form>
    </div>
</div>

<!-- ตารางรายการบัญชี -->
<div class="table-container shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th>ประเภท</th>
                    <th>หมวดหมู่</th>
                    <th>รายละเอียด / หมายเหตุ</th>
                    <th class="text-end">จำนวนเงิน</th>
                    <th>ผู้บันทึก</th>
                    <th class="text-center d-print-none" style="width: 100px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        ไม่พบรายการบัญชีตามช่วงเวลาที่กำหนด
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td>
                        <strong><?= date('d/m/Y', strtotime($t['transaction_date'])) ?></strong>
                    </td>
                    <td>
                        <?php if ($t['type'] === 'income'): ?>
                            <span class="badge bg-success"><i class="bi bi-arrow-down-left me-1"></i>รายรับ</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="bi bi-arrow-up-right me-1"></i>รายจ่าย</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($t['category']) ?></strong>
                        <?php if (!empty($t['reference_type'])): ?>
                            <small class="badge bg-light text-muted border ms-1"><?= htmlspecialchars($t['reference_type']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-dark"><?= htmlspecialchars($t['description'] ?? '-') ?></small></td>
                    <td class="text-end fw-bold fs-6 text-<?= $t['type'] === 'income' ? 'success' : 'danger' ?>">
                        <?= $t['type'] === 'income' ? '+' : '-' ?><?= number_format($t['amount'], 2) ?> ฿
                    </td>
                    <td><small class="text-muted"><?= htmlspecialchars($t['creator_name'] ?? 'ระบบ') ?></small></td>
                    <td class="text-center d-print-none">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-warning" onclick='editTx(<?= json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="" onsubmit="return confirm('ยืนยันลบรายการนี้?');" class="d-inline">
                                <input type="hidden" name="action" value="delete_transaction">
                                <input type="hidden" name="transaction_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="4" class="text-end">รวมยอดสุทธิ (Net Total):</td>
                    <td class="text-end fs-5 text-<?= $netProfit >= 0 ? 'success' : 'danger' ?>">
                        <?= ($netProfit >= 0 ? '+' : '') . number_format($netProfit, 2) ?> ฿
                    </td>
                    <td colspan="2" class="d-print-none"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Modal: บันทึกรายรับ/รายจ่าย -->
<div class="modal fade" id="addTxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_transaction">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>บันทึกรายการรายรับ-รายจ่าย</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ประเภทรายการ <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" id="add_type_income" value="income" checked onchange="updateCategories('income')">
                                <label class="form-check-label text-success fw-bold" for="add_type_income">
                                    <i class="bi bi-arrow-down-left me-1"></i> รายรับ (Income)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" id="add_type_expense" value="expense" onchange="updateCategories('expense')">
                                <label class="form-check-label text-danger fw-bold" for="add_type_expense">
                                    <i class="bi bi-arrow-up-right me-1"></i> รายจ่าย (Expense)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมวดหมู่ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="category" id="add_category" list="categorySuggestions" required placeholder="เลือกหรือพิมพ์หมวดหมู่">
                        <datalist id="categorySuggestions">
                            <option value="ขายสินค้าหน้าร้าน">
                            <option value="ขายสินค้าออนไลน์/จัดส่ง">
                            <option value="บริการเปลี่ยนแบตนอกสถานที่">
                            <option value="เทิร์นแบตเตอรี่เก่า">
                            <option value="บริการตรวจเช็คระบบไฟ/ไดชาร์จ">
                        </datalist>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">จำนวนเงิน (บาท) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required placeholder="0.00">
                        </div>
                        <div class="col-6">
                            <label class="form-label">วันที่ทำรายการ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รายละเอียด / คำอธิบาย</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="ระบุรายละเอียดเพิ่มเติม เช่น เลขบิล, ชื่อลูกค้า, ชื่อช่าง"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>บันทึกรายการ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: แก้ไขรายการ -->
<div class="modal fade" id="editTxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_transaction">
                <input type="hidden" name="transaction_id" id="edit_tx_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขรายการรายรับ-รายจ่าย</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ประเภทรายการ <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" id="edit_type_income" value="income">
                                <label class="form-check-label text-success fw-bold" for="edit_type_income">รายรับ</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" id="edit_type_expense" value="expense">
                                <label class="form-check-label text-danger fw-bold" for="edit_type_expense">รายจ่าย</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมวดหมู่ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="category" id="edit_category" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">จำนวนเงิน (บาท) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="edit_amount" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">วันที่ทำรายการ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="transaction_date" id="edit_date" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รายละเอียด / คำอธิบาย</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
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
function editTx(t) {
    document.getElementById('edit_tx_id').value = t.id;
    if (t.type === 'income') {
        document.getElementById('edit_type_income').checked = true;
    } else {
        document.getElementById('edit_type_expense').checked = true;
    }
    document.getElementById('edit_category').value = t.category;
    document.getElementById('edit_amount').value = t.amount;
    document.getElementById('edit_date').value = t.transaction_date;
    document.getElementById('edit_description').value = t.description || '';
    new bootstrap.Modal(document.getElementById('editTxModal')).show();
}

function updateCategories(type) {
    const datalist = document.getElementById('categorySuggestions');
    datalist.innerHTML = '';
    const incomeCats = [
        'ขายสินค้าหน้าร้าน', 'ขายสินค้าออนไลน์/จัดส่ง', 'บริการเปลี่ยนแบตนอกสถานที่', 'เทิร์นแบตเตอรี่เก่า', 'บริการตรวจเช็คไดชาร์จ'
    ];
    const expenseCats = [
        'ซื้อสต๊อกแบตเตอรี่', 'ค่าน้ำมันรถช่างบริการ', 'ค่าเช่าร้าน/สถานที่', 'เงินเดือนพนักงาน/ช่าง', 'ค่าอุปกรณ์ซ่อมบำรุง', 'ค่าสาธารณูปโภค (น้ำ/ไฟ)'
    ];
    const list = type === 'income' ? incomeCats : expenseCats;
    list.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c;
        datalist.appendChild(opt);
    });
}

function toggleCustomDates() {
    const val = document.getElementById('periodSelect').value;
    const col1 = document.getElementById('customDateCol');
    const col2 = document.getElementById('customDateEndCol');
    if (val === 'custom') {
        col1.style.display = 'block';
        col2.style.display = 'block';
    } else {
        col1.style.display = 'none';
        col2.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

