<?php
require_once __DIR__ . '/config.php';
requireLogin();

$receiptId = (int)($_GET['id'] ?? 0);
if (!$receiptId) {
    die("ไม่พบรหัสใบเสร็จ");
}

$db = getDB();
$stmt = $db->prepare("
    SELECT r.*, u.fullname as staff_name 
    FROM receipts r 
    LEFT JOIN users u ON r.created_by = u.id 
    WHERE r.id = ?
");
$stmt->execute([$receiptId]);
$receipt = $stmt->fetch();

if (!$receipt) {
    die("ไม่พบข้อมูลใบเสร็จรับเงินในระบบ");
}

// ตรวจสอบสิทธิ์ (ลูกค้าดูได้เฉพาะของตัวเอง, staff ดูได้ทั้งหมด)
if (!isStaff() && $receipt['customer_id'] != $_SESSION['user_id']) {
    die("คุณไม่มีสิทธิ์เข้าถึงเอกสารนี้");
}

// แปลง JSON รายการสินค้า
$items = [];
if (!empty($receipt['items_detail'])) {
    $items = json_decode($receipt['items_detail'], true) ?: [];
}

// ฟังก์ชันแปลงตัวเลขเป็นตัวหนังสือไทย (BahtText)
function bahtText($number) {
    $number = number_format($number, 2, '.', '');
    list($baht, $satang) = explode('.', $number);
    
    $thaiNum = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
    $thaiUnit = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'];
    
    if ($baht == '0') {
        $text = 'ศูนย์บาท';
    } else {
        $text = '';
        $len = strlen($baht);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int)$baht[$i];
            $unit = $len - $i - 1;
            if ($digit != 0) {
                if ($unit == 1 && $digit == 1) {
                    $text .= 'สิบ';
                } elseif ($unit == 1 && $digit == 2) {
                    $text .= 'ยี่สิบ';
                } elseif ($unit == 0 && $digit == 1 && $len > 1 && $baht[$i-1] != '0') {
                    $text .= 'เอ็ด';
                } else {
                    $text .= $thaiNum[$digit] . $thaiUnit[$unit];
                }
            }
        }
        $text .= 'บาท';
    }
    
    if ($satang == '00') {
        $text .= 'ถ้วน';
    } else {
        $len = strlen($satang);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int)$satang[$i];
            $unit = $len - $i - 1;
            if ($digit != 0) {
                if ($unit == 1 && $digit == 1) {
                    $text .= 'สิบ';
                } elseif ($unit == 1 && $digit == 2) {
                    $text .= 'ยี่สิบ';
                } elseif ($unit == 0 && $digit == 1 && $satang[0] != '0') {
                    $text .= 'เอ็ด';
                } else {
                    $text .= $thaiNum[$digit] . ($unit == 1 ? 'สิบ' : '');
                }
            }
        }
        $text .= 'สตางค์';
    }
    return $text;
}

$paymentLabels = [
    'cash' => 'เงินสด (Cash)',
    'transfer' => 'โอนเงินเข้าบัญชีธนาคาร (Bank Transfer)',
    'credit_card' => 'บัตรเครดิต/เดบิต (Credit/Debit Card)'
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบเสร็จรับเงิน_<?= htmlspecialchars($receipt['receipt_number']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Sarabun', sans-serif;
        }
        body {
            background: #f4f6f9;
            margin: 0;
            padding: 20px;
            color: #222;
        }
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 35px 40px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-radius: 8px;
        }
        .action-bar {
            max-width: 800px;
            margin: 0 auto 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-print {
            background: #1a5276;
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .btn-print:hover { background: #2980b9; }
        .btn-back {
            background: #6c757d;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        /* Header Style */
        .shop-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #1a5276;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .shop-logo-title h2 {
            margin: 0;
            color: #1a5276;
            font-size: 24px;
            font-weight: 700;
        }
        .shop-info {
            font-size: 13px;
            color: #555;
            line-height: 1.5;
            margin-top: 5px;
        }
        .doc-title {
            text-align: right;
        }
        .doc-title h3 {
            margin: 0;
            color: #1a5276;
            font-size: 20px;
            font-weight: 700;
        }
        .doc-meta {
            margin-top: 6px;
            font-size: 13px;
            color: #444;
        }

        /* Customer Box */
        .customer-section {
            display: flex;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 18px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .customer-col {
            line-height: 1.6;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            background: #1a5276;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            padding: 9px 12px;
            text-align: left;
        }
        .items-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        .items-table tbody tr:nth-child(even) {
            background: #fcfdfe;
        }

        /* Totals */
        .totals-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
        }
        .baht-text-box {
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            padding: 10px 15px;
            border-radius: 6px;
            font-size: 13.5px;
            font-weight: 500;
            color: #334155;
            max-width: 420px;
        }
        .totals-table {
            width: 320px;
            font-size: 14px;
        }
        .totals-table td {
            padding: 4px 8px;
        }
        .totals-table tr.grand-total td {
            border-top: 2px solid #1a5276;
            border-bottom: 2px solid #1a5276;
            font-weight: 700;
            font-size: 16px;
            color: #1a5276;
            padding: 8px;
        }

        /* Warranty & Terms */
        .terms-box {
            border: 1px solid #e2e8f0;
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 10px 15px;
            border-radius: 4px;
            font-size: 12.5px;
            color: #78350f;
            line-height: 1.5;
            margin-bottom: 35px;
        }

        /* Signatures */
        .signature-section {
            display: flex;
            justify-content: space-around;
            text-align: center;
            margin-top: 25px;
        }
        .signature-box {
            width: 220px;
        }
        .signature-line {
            border-bottom: 1px dotted #888;
            height: 45px;
            margin-bottom: 8px;
        }
        .signature-title {
            font-size: 13px;
            color: #555;
        }

        /* Print Media Style */
        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .action-bar { display: none; }
            .receipt-container {
                box-shadow: none;
                padding: 0;
                width: 100%;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

    <div class="action-bar d-print-none">
        <a href="<?= SITE_URL ?>receipts.php" class="btn-back"><i class="bi bi-arrow-left"></i> กลับหน้ารายการใบเสร็จ</a>
        <div style="display: flex; gap: 8px;">
            <?php if (isStaff()): ?>
            <a href="<?= SITE_URL ?>receipts.php?edit=<?= $receipt['id'] ?>" class="btn-print" style="background:#e67e22; border:none; text-decoration:none;"><i class="bi bi-pencil-square"></i> แก้ไขใบเสร็จนี้</a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn-print"><i class="bi bi-printer"></i> พิมพ์ใบเสร็จ (Print)</button>
        </div>
    </div>

    <div class="receipt-container">
        <!-- Header ร้านค้า -->
        <div class="shop-header">
            <div class="shop-logo-title">
                <h2><i class="bi bi-battery-charging"></i> <?= SITE_NAME ?></h2>
                <div class="shop-info">
                    <?= SHOP_ADDRESS ?><br>
                    โทร: <?= SHOP_PHONE ?> | Line: <?= SHOP_LINE ?><br>
                    เลขประจำตัวผู้เสียภาษี: <?= SHOP_TAX_ID ?>
                </div>
            </div>
            <div class="doc-title">
                <h3>ใบเสร็จรับเงิน</h3>
                <div style="font-size: 13px; color: #64748b;">RECEIPT / DELIVERY SLIP</div>
                <div class="doc-meta">
                    <strong>เลขที่:</strong> <?= htmlspecialchars($receipt['receipt_number']) ?><br>
                    <strong>วันที่:</strong> <?= date('d/m/Y', strtotime($receipt['created_at'])) ?>
                </div>
            </div>
        </div>

        <!-- ข้อมูลลูกค้าและยานพาหนะ -->
        <div class="customer-section">
            <div class="customer-col">
                <strong>ข้อมูลลูกค้า:</strong> <?= htmlspecialchars($receipt['customer_name'] ?: 'ลูกค้าทั่วไป') ?><br>
                <strong>เบอร์โทรศัพท์:</strong> <?= htmlspecialchars($receipt['customer_phone'] ?: '-') ?>
            </div>
            <div class="customer-col text-end">
                <strong>ทะเบียนรถ:</strong> <?= htmlspecialchars($receipt['vehicle_plate'] ?: 'ไม่ได้ระบุ') ?><br>
                <strong>วิธีชำระเงิน:</strong> <?= $paymentLabels[$receipt['payment_method']] ?? $receipt['payment_method'] ?>
            </div>
        </div>

        <!-- รายการสินค้า -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;">ลำดับ</th>
                    <th>รายการสินค้า / บริการ</th>
                    <th style="width: 80px; text-align: center;">จำนวน</th>
                    <th style="width: 110px; text-align: right;">ราคา/หน่วย</th>
                    <th style="width: 120px; text-align: right;">จำนวนเงิน (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr>
                    <td style="text-align: center;">1</td>
                    <td>แบตเตอรี่ / บริการหน้าร้าน (ตามบิล #<?= htmlspecialchars($receipt['receipt_number']) ?>)</td>
                    <td style="text-align: center;">1</td>
                    <td style="text-align: right;"><?= number_format($receipt['total_amount'], 2) ?></td>
                    <td style="text-align: right;"><?= number_format($receipt['total_amount'], 2) ?></td>
                </tr>
                <?php else: ?>
                <?php foreach ($items as $idx => $it): ?>
                <tr>
                    <td style="text-align: center;"><?= $idx + 1 ?></td>
                    <td><?= htmlspecialchars($it['name']) ?></td>
                    <td style="text-align: center;"><?= $it['qty'] ?></td>
                    <td style="text-align: right;"><?= number_format($it['price'], 2) ?></td>
                    <td style="text-align: right;"><?= number_format($it['total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ส่วนสรุปยอดเงินและตัวอักษรไทย -->
        <div class="totals-section">
            <div class="baht-text-box">
                <span style="color: #64748b; font-size: 12px; display: block;">จำนวนเงินตัวอักษร:</span>
                <strong><?= bahtText($receipt['net_amount']) ?></strong>
                <?php if ($receipt['notes']): ?>
                <div style="margin-top: 8px; font-size: 12px; color: #475569;">
                    <strong>หมายเหตุ:</strong> <?= htmlspecialchars($receipt['notes']) ?>
                </div>
                <?php endif; ?>
            </div>

            <table class="totals-table">
                <tr>
                    <td>รวมเงิน (Subtotal):</td>
                    <td style="text-align: right;"><?= number_format($receipt['total_amount'], 2) ?> ฿</td>
                </tr>
                <?php if ($receipt['discount_amount'] > 0): ?>
                <tr>
                    <td>ส่วนลด (Discount):</td>
                    <td style="text-align: right; color: #dc2626;">-<?= number_format($receipt['discount_amount'], 2) ?> ฿</td>
                </tr>
                <?php endif; ?>
                <?php if ($receipt['tax_amount'] > 0): ?>
                <tr>
                    <td>ภาษีมูลค่าเพิ่ม (VAT):</td>
                    <td style="text-align: right;"><?= number_format($receipt['tax_amount'], 2) ?> ฿</td>
                </tr>
                <?php endif; ?>
                <tr class="grand-total">
                    <td>ยอดรวมสุทธิ (Net Total):</td>
                    <td style="text-align: right;"><?= number_format($receipt['net_amount'], 2) ?> ฿</td>
                </tr>
            </table>
        </div>

        <!-- เงื่อนไขการรับประกันสินค้า -->
        <div class="terms-box">
            <strong><i class="bi bi-shield-check"></i> ข้อกำหนดและเงื่อนไขการรับประกันสินค้า:</strong><br>
            1. ทางร้านรับประกันแบตเตอรี่เฉพาะความเสียหายอันเกิดจากกระบวนการผลิตของผู้ผลิตเท่านั้น<br>
            2. การรับประกันไม่ครอบคลุมกรณีไดชาร์จผิดปกติ, อุบัติเหตุ, แบตเตอรี่แตกร้าวบวมจากการ Overcharge, หรือการดัดแปลงสภาพ<br>
            3. โปรดเก็บใบเสร็จรับเงินนี้ไว้เพื่อใช้เป็นหลักฐานยืนยันในการขอรับบริการเคลมหรือตรวจเช็คแบตเตอรี่ตลอดอายุการรับประกัน
        </div>

        <!-- ลายเซ็น -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-title">ผู้รับเงิน / ช่างผู้ให้บริการ</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">(<?= htmlspecialchars($receipt['staff_name'] ?? 'ผู้มีอำนาจลงนาม') ?>)</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-title">ผู้รับสินค้า / บริการ</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">(<?= htmlspecialchars($receipt['customer_name'] ?: 'ลูกค้า') ?>)</div>
            </div>
        </div>
    </div>

</body>
</html>

