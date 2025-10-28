<?php
// employee/receipt.php — Template แสดงใบเสร็จ/เอกสารเดี่ยว
// ไฟล์นี้ถูกออกแบบมาให้ถูก include/require โดยไฟล์อื่น
// (เช่น sales_receipt.php, lot_view.php)
// session_start(); // เซสชันควรถูกเริ่มโดยไฟล์ที่ include หน้านี้เข้ามาแล้ว

date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอินและสิทธิ์ =====
if (!isset($_SESSION['user_id'])) {
    // โดยทั่วไป ไฟล์ที่ include หน้านี้ควรจะเช็ค login แล้ว
    // แต่ถ้าไม่, อาจจะ redirect หรือแสดงข้อผิดพลาด
    header('HTTP/1.1 403 Forbidden');
    die('โปรดเข้าสู่ระบบก่อน (Template Error)');
}

// ===== เชื่อมต่อฐานข้อมูล =====
// $pdo ควรถูกส่งต่อมาจากไฟล์ที่ include เข้ามา
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('ไม่พบการเชื่อมต่อฐานข้อมูล ($pdo)');
}


// ===== Helpers =====
if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
      try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
        $st->execute([':db'=>$db, ':tb'=>$table]);
        return (int)$st->fetchColumn() > 0;
      } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('nf')) {
    function nf($n,$d=2){ return number_format((float)$n,$d,'.',','); }
}
if (!function_exists('dt')) {
    function dt($s,$fmt='d/m/Y H:i'){ $t=strtotime($s); return $t? date($fmt,$t):'-'; }
}
if (!function_exists('d')) {
    function d($s,$fmt='d/m/Y'){ $t=strtotime($s); return $t? date($fmt,$t):'-'; }
}

// ===== ค่าพื้นฐานระบบ (ดึงใหม่ หรือใช้ค่าที่ส่งมา) =====
$site_name = 'สหกรณ์ปั๊มน้ำมัน'; // ค่า default
$stationId = $_SESSION['station_id'] ?? 1; // ใช้ station_id ของ employee ถ้ามี
$site_address = null;
$site_phone = null;
$site_tax_id = null;

// พยายามดึงจาก $tx ที่อาจถูกส่งมา หรือ query ใหม่
if (isset($tx) && isset($tx['site_name'])) {
    $site_name = $tx['site_name'];
} else {
    try {
      // ดึงข้อมูลไซต์จาก app_settings
       if (table_exists($pdo, 'app_settings')) {
           $settings_stmt = $pdo->query("SELECT
               JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.site_name')) as site_name,
               JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.address')) as address,
               JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.contact_phone')) as phone,
               JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.tax_id')) as tax_id
               FROM app_settings WHERE `key` = 'system_settings' LIMIT 1");
           $site_info = $settings_stmt->fetch(PDO::FETCH_ASSOC);
           if ($site_info) {
               $site_name = $site_info['site_name'] ?: $site_name;
               $site_address = $site_info['address'] ?? '-';
               $site_phone = $site_info['phone'] ?? '-';
               $site_tax_id = $site_info['tax_id'] ?? '-';
           }
       }
       // ถ้ายังไม่มี ให้ลอง settings (เผื่อเป็นระบบเก่า)
       if (empty($site_address) && table_exists($pdo,'settings')) {
         $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
         if ($row) $stationId = (int)$row['setting_value'];
         $sn = $pdo->query("SELECT comment FROM settings WHERE setting_name='station_id' LIMIT 1")->fetchColumn();
         if ($sn) $site_name = $sn;
       }
    } catch (Throwable $e) { /* ใช้ค่า default */ }
}
// กำหนดค่า default ถ้ายังไม่มีค่าจาก settings
$site_address = $site_address ?? ($data['site_address'] ?? '-');
$site_phone = $site_phone ?? ($data['site_phone'] ?? '-');
$site_tax_id = $site_tax_id ?? ($data['site_tax_id'] ?? '-');


// ===== รับพารามิเตอร์ =====
// $forceType อาจถูกเซ็ตโดยไฟล์ที่ include เข้ามา (เช่น 'sale')
$t = strtolower($_GET['t'] ?? ($forceType ?? ''));
$code = trim((string)($_GET['code'] ?? ''));   // สำหรับ sale / lot / transaction (code)
$id   = trim((string)($_GET['id'] ?? ''));     // สำหรับ receive (id) / transaction (id)

// ถ้ายังไม่ทราบประเภท ลองเดา
if (!$t) {
  $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
  if (stripos($script,'sales_receipt')!==false) $t='sale';
  elseif (stripos($script,'receive')!==false)  $t='receive';
  elseif (stripos($script,'lot')!==false)      $t='lot';
  elseif (stripos($script,'txn')!==false || stripos($script,'receipt')!==false) $t='transaction';
}

// ===== ตัวแปรข้อมูล =====
// $data อาจถูกส่งมาจากไฟล์ที่ include
if (!isset($data)) $data = null;
$items = [];
$title = 'ใบเสร็จ / เอกสาร';
$notFoundMsg = '';
$errorMessage = '';

// ===== Query ข้อมูล (ถ้า $data ยังไม่มีค่า) =====
if ($data === null && !empty($t)) {
    try {
        // (ใช้ stationId ของ employee ที่ login อยู่)
        $currentStationId = (int)($_SESSION['station_id'] ?? $stationId);

        switch ($t) {
            case 'sale':
                if ($code === '') { $notFoundMsg = 'ไม่พบเลขที่บิลขาย (code)'; break; }
                // ดึงชื่อพนักงานจาก 3 แหล่ง (created_by, employee_user_id, หรือ user_id จาก fuel_moves)
                $sql = "
                    SELECT s.*,
                           COALESCE(u_cr.full_name, u_emp.full_name, u_fm.full_name, 'ไม่ระบุ') AS cashier_name
                    FROM sales s
                    LEFT JOIN users u_cr ON u_cr.id = s.created_by
                    LEFT JOIN users u_emp ON u_emp.id = s.employee_user_id
                    LEFT JOIN (
                        SELECT fm.sale_id, fm.user_id
                        FROM fuel_moves fm
                        WHERE fm.sale_id = (SELECT s_inner.id FROM sales s_inner WHERE s_inner.sale_code = :code LIMIT 1)
                          AND fm.is_sale_out = 1
                        ORDER BY fm.id ASC LIMIT 1
                    ) fm_join ON fm_join.sale_id = s.id
                    LEFT JOIN users u_fm ON u_fm.id = fm_join.user_id
                    WHERE s.sale_code = :code AND s.station_id = :sid
                    LIMIT 1
                ";
                $st = $pdo->prepare($sql);
                $st->execute([':code' => $code, ':sid' => $currentStationId]);
                $data = $st->fetch(PDO::FETCH_ASSOC);

                if (!$data) { $notFoundMsg = 'ไม่พบบิลขายเลขที่ ' . htmlspecialchars($code); break; }

                $sti = $pdo->prepare("
                    SELECT fuel_type, liters, price_per_liter, (liters*price_per_liter) AS line_amount
                    FROM sales_items
                    WHERE sale_id = :sid ORDER BY id
                ");
                $sti->execute([':sid' => $data['id']]);
                $items = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $title = 'ใบเสร็จรับเงิน (ขายน้ำมัน)';
                break;

            // (เคส 'receive', 'lot', 'transaction' อาจจะไม่จำเป็นสำหรับ Employee)
            // (แต่ใส่ไว้เผื่อก็ได้)

            case 'transaction':
                if (!table_exists($pdo,'financial_transactions')) { $notFoundMsg='ระบบยังไม่มีตาราง financial_transactions'; break; }

                $lookupValue = $id ?: $code;
                if (empty($lookupValue)) { $notFoundMsg = 'ไม่พบรหัสหรือ ID รายการ'; break; }

                $sql = "
                  SELECT ft.*,
                         COALESCE(ft.transaction_code, CONCAT('FT-', ft.id)) AS display_code,
                         u.full_name AS created_by_name
                  FROM financial_transactions ft
                  LEFT JOIN users u ON u.id = ft.user_id
                  WHERE (ft.id = :id OR ft.transaction_code = :code)
                  LIMIT 1
                ";
                
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':id' => ctype_digit($lookupValue) ? (int)$lookupValue : 0,
                    ':code' => $lookupValue
                ]);
                
                $data = $st->fetch(PDO::FETCH_ASSOC);
                if (!$data) { $notFoundMsg = 'ไม่พบรายการการเงิน ' . htmlspecialchars($lookupValue); break; }
                $title = 'ใบรายการการเงิน';
                break;

            default:
              if(!empty($t)) {
                $notFoundMsg = 'ไม่รู้จักประเภทเอกสาร (t=' . htmlspecialchars($t) . ')';
              } else {
                $notFoundMsg = 'ไม่ได้ระบุประเภทเอกสารที่ต้องการแสดง';
              }
        }

    } catch (Throwable $e) {
        error_log("Employee Receipt Fetch Error: " . $e->getMessage());
        $errorMessage = 'เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage();
    }
} elseif (empty($t) && $data === null) {
    $notFoundMsg = 'ไม่ได้ระบุประเภทเอกสารหรือข้อมูล';
}

// กำหนดชื่อไซต์ที่จะแสดงผล
$display_site_name = $data['site_name'] ?? $site_name;

$pay_map = [
    'cash'     => 'เงินสด',
    'qr'       => 'QR Code',
    'transfer' => 'โอนเงิน',
    'card'     => 'บัตรเครดิต'
];

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?> | <?= htmlspecialchars($display_site_name) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap" rel="stylesheet">
  <style>
    body {
        font-family: 'Sarabun', 'monospace', sans-serif;
        width: 300px; /* 80mm */
        margin: 0 auto;
        padding: 10px;
        color: #000;
        font-size: 14px;
        line-height: 1.6;
        background: #fff;
    }
    .header { text-align: center; margin-bottom: 8px; }
    .header h3, .header p { margin: 0; padding: 0; }
    .header h3 { font-size: 1.1rem; font-weight: 700; }
    .header p { font-size: 0.9rem; }
    hr.dashed { border: none; border-top: 1px dashed #000; margin: 8px 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 2px 0; }
    
    .meta-info td:last-child { text-align: right; }
    
    .items-table thead th { text-align: left; border-bottom: 1px solid #000; font-size: 0.9rem; }
    .items-table tbody td { font-size: 0.9rem; }
    .items-table .item-line td { padding-top: 5px; }
    .items-table .item-detail td { font-size: 0.85rem; padding-top: 0; padding-bottom: 5px; color: #333; }
    .items-table .col-qty, .items-table .col-price, .items-table .col-total { text-align: right; width: 60px; }
    .items-table .col-item { width: auto; }

    .summary-table td:last-child { text-align: right; }
    .summary-table .total { font-weight: 700; font-size: 1.1rem; }
    .footer { text-align: center; margin-top: 10px; font-size: 0.9rem; }
    .no-print { display: none; } /* ซ่อนปุ่มเวลาพิมพ์ */

    @media print {
      body { width: 100%; margin: 0; padding: 0; }
      @page { margin: 2mm; size: 80mm auto; }
      .no-print { display:none; }
    }
  </style>
</head>
<body onload="window.print();">

  <div class="receipt">
    <div class="receipt-header">
        <div class="brand"><?= htmlspecialchars($display_site_name) ?></div>
        <div class="address-info" style="font-size: 0.9rem; line-height: 1.3;">
            <?= htmlspecialchars($site_address ?? '-') ?><br>
            โทร: <?= htmlspecialchars($site_phone ?? '-') ?> เลขผู้เสียภาษี: <?= htmlspecialchars($site_tax_id ?? '-') ?>
        </div>
        <p style="font-size: 0.8rem; color: #555;">พิมพ์เมื่อ <?= dt('now','d/m/Y H:i') ?></p>
    </div>

    <div class="receipt-body">
      <?php if ($errorMessage): ?>
        <div style="color: red; border: 1px solid red; padding: 10px;"><b>เกิดข้อผิดพลาด:</b><br><?= htmlspecialchars($errorMessage) ?></div>
      <?php elseif ($notFoundMsg): ?>
        <div style="color: #856404; border: 1px solid #ffeeba; padding: 10px;"><?= htmlspecialchars($notFoundMsg) ?></div>
      <?php elseif ($data): ?>

        <?php if ($t==='sale'): ?>
          <h5 style="text-align: center; font-weight: 700; margin: 5px 0; font-size: 1.1rem;">ใบเสร็จรับเงิน/ใบกำกับภาษีอย่างย่อ</h5>
          
          <table class="meta-info" style="font-size: 0.9rem;">
              <tr>
                  <td>เลขที่:</td>
                  <td><?= htmlspecialchars($data['sale_code']) ?></td>
              </tr>
              <tr>
                  <td>วันที่:</td>
                  <td><?= dt($data['sale_date']) ?></td>
              </tr>
              <tr>
                  <td>พนักงาน:</td>
                  <td><?= htmlspecialchars($data['cashier_name'] ?? ($_SESSION['full_name'] ?? '-')) ?></td>
              </tr>
          </table>
          
          <hr class="dashed">
          
          <table class="items-table">
            <thead>
                <tr>
                    <th class="col-item">รายการ</th>
                    <th class="col-price">ราคา</th>
                    <th class="col-total">รวม</th>
                </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr class="item-line">
                  <td><?= htmlspecialchars($it['fuel_type']) ?></td>
                  <td></td>
                  <td class="col-total"><?= nf($it['line_amount'],2) ?></td>
                </tr>
                <tr class="item-detail">
                  <td>&nbsp;&nbsp;&nbsp;<?= nf($it['liters'],2) ?> ลิตร @ <?= nf($it['price_per_liter'],2) ?></td>
                  <td></td>
                  <td></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <hr class="dashed">
          
          <table class="summary-table">
            <tr>
                <td>ยอดรวม</td>
                <td><?= nf($data['total_amount'],2) ?></td>
            </tr>
            <tr>
                <td>ส่วนลด</td>
                <td><?= nf($data['discount_amount'] ?? 0, 2) ?></td>
            </tr>
            <tr class="total">
                <td>ยอดสุทธิ</td>
                <td><?= nf($data['net_amount'],2) ?> บาท</td>
            </tr>
          </table>

          <hr class="dashed">

          <table class="meta-info" style="font-size: 0.9rem;">
            <tr>
                <td>ชำระโดย:</td>
                <td><?= htmlspecialchars($pay_map[strtolower($data['payment_method'] ?? '')] ?? 'เงินสด') ?></td>
            </tr>
          </table>

        <?php elseif ($t==='transaction'): ?>
           <h5 style="text-align: center; font-weight: 700; margin: 5px 0; font-size: 1.1rem;">รายการการเงิน</h5>
           <table class="meta-info" style="font-size: 0.9rem;">
               <tr>
                  <td>รหัส:</td>
                  <td><?= htmlspecialchars($data['display_code'] ?? ($data['transaction_code'] ?? ($data['id'] ?? '—'))) ?></td>
               </tr>
               <tr>
                  <td>วันที่:</td>
                  <td><?= dt($data['transaction_date'] ?? '') ?></td>
               </tr>
               <tr>
                  <td>ผู้บันทึก:</td>
                  <td><?= htmlspecialchars($data['created_by_name'] ?? '-') ?></td>
               </tr>
           </table>
           <hr class="dashed">
           <table class="summary-table" style="font-size: 1rem;">
                 <tbody>
                    <tr><td style="width: 30%;">ประเภท:</td><td><?= htmlspecialchars(ucfirst($data['type'] ?? '-')) ?></td></tr>
                    <tr><td>หมวดหมู่:</td><td><?= htmlspecialchars($data['category'] ?? '-') ?></td></tr>
                    <tr><td>รายละเอียด:</td><td><?= htmlspecialchars($data['description'] ?? '-') ?></td></tr>
                    <tr><td>อ้างอิง:</td><td><?= htmlspecialchars($data['reference_id'] ?? '-') ?></td></tr>
                    <tr class="total" style="border-top: 1px dashed #ccc; padding-top: 5px;">
                        <td>จำนวนเงิน:</td>
                        <td style="text-align: right; color: <?= ($data['type']??'')==='income' ? '#198754' : '#dc3545' ?>;">
                            <?= nf($data['amount'] ?? 0,2) ?>
                        </td>
                    </tr>
                 </tbody>
            </table>
            
        <?php endif; // (จบ if $t === 'sale' / 'transaction') ?>

      <?php endif; // (จบ if $data) ?>
    </div>

    <div class="receipt-footer">
      <p>** ขอบคุณที่ใช้บริการ **</p>
    </div>
  </div>

  <div style="text-align: center; margin-top: 20px;" class="no-print">
      <a href="javascript:window.print()" style="padding: 10px 15px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 5px;">พิมพ์เอกสารนี้</a>
      <a href="javascript:history.back()" style="padding: 10px 15px; background-color: #6c757d; color: white; text-decoration: none; border-radius: 5px;">ย้อนกลับ</a>
  </div>

</body>
</html>