<?php
// receipt.php — แสดงใบเสร็จ/เอกสารเดี่ยว (เปิดจากปุ่มในตารางการเงิน)
// session_start(); // [ลบออก] - เซสชันควรถูกเริ่มโดยไฟล์ที่ include หน้านี้เข้ามาแล้ว
date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอินและสิทธิ์ (ปรับตามระบบจริงได้) =====
if (!isset($_SESSION['user_id'])) {
    // กำหนดค่าเริ่มต้น หรือ redirect ถ้ายังไม่ล็อกอิน
    // อาจจะไม่จำเป็นต้องทำอะไร ถ้าไฟล์นี้ถูก include โดยไฟล์ที่เช็ค login แล้ว
    // header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit();
}
// อาจจะมีการตรวจสอบ role เพิ่มเติม ถ้าจำเป็น

// ===== เชื่อมต่อฐานข้อมูล =====
// สมมติว่า $pdo ถูก require มาแล้วจากไฟล์ที่ include เข้ามา
// ถ้าไฟล์นี้อาจถูกเรียกโดยตรง ต้อง uncomment ส่วนนี้
/*
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ'); }
*/
if (!isset($pdo) || !($pdo instanceof PDO)) { die('ไม่พบการเชื่อมต่อฐานข้อมูล ($pdo)'); }


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
$stationId = 1; // ค่า default
// พยายามดึงจาก $tx ที่อาจถูกส่งมา หรือ query ใหม่
if (isset($tx) && isset($tx['site_name'])) {
    $site_name = $tx['site_name'];
    // อาจจะต้องดึง stationId จาก $tx ถ้ามี
} else {
    try {
      if (table_exists($pdo,'settings')) {
        $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) $stationId = (int)$row['setting_value'];
        $sn = $pdo->query("SELECT site_name FROM settings WHERE id=1")->fetchColumn();
        if ($sn) $site_name = $sn;
      }
      // ดึงข้อมูลไซต์เพิ่มเติมจาก app_settings ถ้ายังไม่มี
       if (empty($site_address) && table_exists($pdo, 'app_settings')) {
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
    } catch (Throwable $e) { /* ใช้ค่า default */ }
}
// กำหนดค่า default ถ้ายังไม่มีค่าจาก settings
$site_address = $site_address ?? ($data['site_address'] ?? '-');
$site_phone = $site_phone ?? ($data['site_phone'] ?? '-');
$site_tax_id = $site_tax_id ?? ($data['site_tax_id'] ?? '-');


// ===== รับพารามิเตอร์ =====
// $forceType อาจถูกเซ็ตโดยไฟล์ที่ include เข้ามา
$t = strtolower($_GET['t'] ?? ($forceType ?? ''));
$code = trim((string)($_GET['code'] ?? ''));   // สำหรับ sale / lot / transaction (code)
$id   = trim((string)($_GET['id'] ?? ''));     // สำหรับ receive (id) / transaction (id)

// ถ้ายังไม่ทราบประเภท ลองเดาจากชื่อไฟล์ (คงเดิม)
if (!$t) {
  $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
  if (stripos($script,'sales_receipt')!==false) $t='sale';
  elseif (stripos($script,'receive')!==false)  $t='receive';
  elseif (stripos($script,'lot')!==false)      $t='lot';
  elseif (stripos($script,'txn')!==false || stripos($script,'receipt')!==false) $t='transaction'; // จับ receipt.php ด้วย
}

// ===== ตัวแปรข้อมูล =====
// $data อาจถูกส่งมาจากไฟล์ที่ include (เช่น txn_receipt.php ส่ง $tx มา)
if (!isset($data)) $data = null; // ถ้ายังไม่มี ให้เป็น null
$items = [];   // รายการย่อย (เฉพาะ sale)
$title = 'ใบเสร็จ / เอกสาร';
$notFoundMsg = '';
$errorMessage = ''; // สำหรับเก็บข้อผิดพลาดทางเทคนิค

// ===== Query ข้อมูล (ถ้า $data ยังไม่มีค่า) =====
if ($data === null && !empty($t)) {
    try {
        switch ($t) {
            case 'sale':
                if ($code === '') { $notFoundMsg = 'ไม่พบเลขที่บิลขาย (code)'; break; }
                $sql = "SELECT s.*, (SELECT u.full_name FROM fuel_moves fm JOIN users u ON u.id=fm.user_id WHERE fm.type='sale_out' AND fm.sale_id = s.id ORDER BY fm.id ASC LIMIT 1) AS cashier_name FROM sales s WHERE s.sale_code = :code AND s.station_id = :sid LIMIT 1";
                $st = $pdo->prepare($sql); $st->execute([':code' => $code, ':sid' => $stationId]);
                $data = $st->fetch(PDO::FETCH_ASSOC);
                if (!$data) { $notFoundMsg = 'ไม่พบบิลขายเลขที่ ' . htmlspecialchars($code); break; }
                $sti = $pdo->prepare("SELECT fuel_type, liters, price_per_liter, (liters*price_per_liter) AS line_amount FROM sales_items WHERE sale_id = :sid ORDER BY id");
                $sti->execute([':sid' => $data['id']]);
                $items = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $title = 'ใบเสร็จรับเงิน (ขายน้ำมัน)';
                break;

            case 'receive':
                 if ($id === '' || !ctype_digit($id)) { $notFoundMsg = 'พารามิเตอร์ id ไม่ถูกต้อง'; break; }
                $sql = "SELECT fr.*, fp.fuel_name, s2.supplier_name, u.full_name AS created_by_name FROM fuel_receives fr LEFT JOIN fuel_prices fp ON fp.fuel_id = fr.fuel_id AND fp.station_id = :sid LEFT JOIN suppliers s2 ON s2.supplier_id = fr.supplier_id LEFT JOIN users u ON u.id = fr.created_by WHERE fr.id = :id LIMIT 1";
                $st = $pdo->prepare($sql); $st->execute([':sid' => $stationId, ':id' => $id]);
                $data = $st->fetch(PDO::FETCH_ASSOC);
                if (!$data) { $notFoundMsg = 'ไม่พบใบรับเข้าคลัง #' . htmlspecialchars($id); break; }
                $title = 'ใบรับเข้าคลัง (Fuel Receive)';
                break;

            case 'lot':
                if ($code === '') { $notFoundMsg = 'ไม่พบ Lot Code (code)'; break; }
                $sql = "SELECT l.*, t.code AS tank_code, t.name AS tank_name, fp.fuel_name, u.full_name AS created_by_name FROM fuel_lots l JOIN fuel_tanks t ON t.id = l.tank_id LEFT JOIN fuel_prices fp ON fp.fuel_id = l.fuel_id LEFT JOIN users u ON u.id = l.created_by WHERE l.lot_code = :code LIMIT 1";
                $st = $pdo->prepare($sql); $st->execute([':code' => $code]);
                $data = $st->fetch(PDO::FETCH_ASSOC);
                if (!$data) { $notFoundMsg = 'ไม่พบ LOT ' . htmlspecialchars($code); break; }
                $title = 'ใบรับเข้าถัง (LOT)';
                break;

            case 'transaction':
                if (!table_exists($pdo,'financial_transactions')) { $notFoundMsg='ระบบยังไม่มีตาราง financial_transactions'; break; }

                $lookupValue = $id ?: $code; // ใช้ id ก่อน ถ้าไม่มีค่อยใช้ code
                if (empty($lookupValue)) { $notFoundMsg = 'ไม่พบรหัสหรือ ID รายการ'; break; }

                $sql = "
                  SELECT ft.*,
                         COALESCE(ft.transaction_code, CONCAT('FT-', ft.id)) AS display_code,
                         u.full_name AS created_by_name
                  FROM financial_transactions ft
                  LEFT JOIN users u ON u.id = ft.user_id
                  WHERE ";
                $params = [];

                if (ctype_digit($lookupValue)) { // ถ้าดูเหมือน ID
                    $sql .= " ft.id = :lookup_id ";
                    $params[':lookup_id'] = (int)$lookupValue;
                    $sql .= " OR ft.transaction_code = :lookup_code_alt ";
                    $params[':lookup_code_alt'] = 'FT-' . $lookupValue;
                } else { // ถ้าไม่ใช่ตัวเลขล้วน ถือว่าเป็น code
                    $sql .= " ft.transaction_code = :lookup_code ";
                    $params[':lookup_code'] = $lookupValue;
                    if (preg_match('/^FT-(\d+)$/', $lookupValue, $matches)) {
                        $sql .= " OR ft.id = :lookup_id_alt ";
                        $params[':lookup_id_alt'] = (int)$matches[1];
                    }
                }
                $sql .= " LIMIT 1";

                $st = $pdo->prepare($sql);
                if (!$st->execute($params)) {
                    $errorInfo = $st->errorInfo();
                    throw new Exception("Execute failed: " . ($errorInfo[2] ?? 'Unknown error'));
                }
                $data = $st->fetch(PDO::FETCH_ASSOC);
                if (!$data) { $notFoundMsg = 'ไม่พบรายการการเงิน ' . htmlspecialchars($lookupValue); break; }
                $title = 'ใบรายการการเงิน (Financial Txn)';
                break;

            default:
              if(!empty($t)) { // แสดง error เฉพาะเมื่อมีการระบุ t แต่ไม่รู้จัก
                $notFoundMsg = 'ไม่รู้จักประเภทเอกสาร (t=' . htmlspecialchars($t) . ')';
              } else {
                $notFoundMsg = 'ไม่ได้ระบุประเภทเอกสารที่ต้องการแสดง';
              }
        }

        // ดึงข้อมูลไซต์เพิ่มเติม ถ้ายังไม่มี
        if ($data && !isset($site_address) && table_exists($pdo, 'app_settings')) {
           try {
               $settings_stmt = $pdo->query("SELECT
                   JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.address')) as address,
                   JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.contact_phone')) as phone,
                   JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.tax_id')) as tax_id
                   FROM app_settings WHERE `key` = 'system_settings' LIMIT 1");
               $site_info = $settings_stmt->fetch(PDO::FETCH_ASSOC);
               if ($site_info) {
                   $site_address = $site_info['address'] ?? '-';
                   $site_phone = $site_info['phone'] ?? '-';
                   $site_tax_id = $site_info['tax_id'] ?? '-';
               }
           } catch (Throwable $e) { /* ใช้ค่า default */ }
        }

    } catch (PDOException $e) { // ดักจับ Error จาก PDO
        error_log("Receipt Fetch PDO Error: " . $e->getMessage());
        $errorMessage = 'เกิดข้อผิดพลาดในการดึงข้อมูล (PDO)';
    } catch (Throwable $e) { // ดักจับ Error ทั่วไป
        error_log("Receipt Fetch General Error: " . $e->getMessage());
        $errorMessage = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
} elseif (empty($t) && $data === null) {
    // กรณีที่ไม่มีการระบุประเภท และไม่มีข้อมูล $tx ส่งมา
    $notFoundMsg = 'ไม่ได้ระบุประเภทเอกสารหรือข้อมูล';
}

// กำหนดชื่อไซต์ที่จะแสดงผล
$display_site_name = $data['site_name'] ?? $site_name;

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?> | <?= htmlspecialchars($display_site_name) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"> <style>
    body { background:#f7f7f7; font-family: 'Prompt', sans-serif; } /* เพิ่ม Font */
    .receipt { max-width: 80mm; margin:24px auto; background:#fff; border:1px solid #dee2e6; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); font-size: 10pt; line-height: 1.5; color: #000;} /* ปรับขนาดและความละเอียด */
    .receipt-header, .receipt-body, .receipt-footer { padding: 10px; }
    .receipt-header { text-align: center; border-bottom: 1px dashed #ccc; }
    .receipt-body { border-bottom: 1px dashed #ccc; padding-bottom: 5px; margin-bottom: 5px;}
    .receipt-footer { text-align: center; font-size: 9pt; color: #555; padding-top: 5px;}
    .brand { font-weight:600; font-size:11pt; margin-bottom: 2px;}
    .address-info { font-size: 9pt; line-height: 1.3; margin-bottom: 5px; }
    .muted { color:#6c757d; font-size: 9pt; }
    .table-tight th, .table-tight td { padding: 2px 4px; font-size: 10pt; vertical-align: top;}
    .text-end { text-align: right;}
    .fw-bold { font-weight: bold; }
    .totals th { border-top: 1px dashed #ccc; padding-top: 5px !important;}
    .totals th, .totals td { font-weight: bold; }
    .subtle { font-size: 9pt; color: #444;}
    hr.dashed { border-top: 1px dashed #ccc; margin: 8px 0;}

    @media print {
      .no-print { display:none !important; }
      body { background:#fff; margin: 0; padding: 0;}
      .receipt { border:none; box-shadow: none; margin:0; max-width: 100%; width: 100%; font-size: 10pt; /* อาจจะต้องปรับขนาด Font สำหรับเครื่องพิมพ์ */ }
      @page { margin: 5mm; size: 80mm auto; /* ตั้งค่าหน้ากระดาษสลิป */ }
    }
  </style>
</head>
<body>
  <div class="receipt">
    <div class="receipt-header">
        <div class="brand"><?= htmlspecialchars($display_site_name) ?></div>
        <div class="address-info">
            <?= htmlspecialchars($site_address ?? '-') ?><br>
            โทร: <?= htmlspecialchars($site_phone ?? '-') ?> เลขผู้เสียภาษี: <?= htmlspecialchars($site_tax_id ?? '-') ?>
        </div>
        <div class="muted">พิมพ์เมื่อ <?= dt('now','d/m/Y H:i') ?></div>
    </div>

    <div class="receipt-body">
      <?php if ($errorMessage): ?>
        <div class="alert alert-danger mb-0 small"><i class="bi bi-exclamation-octagon-fill"></i> เกิดข้อผิดพลาดทางเทคนิค: <?= htmlspecialchars($errorMessage) ?></div>
      <?php elseif ($notFoundMsg): ?>
        <div class="alert alert-warning mb-0 small"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($notFoundMsg) ?></div>
      <?php elseif ($data): ?>

        <?php if ($t==='sale'): ?>
          <h5 class="text-center fw-bold mb-1" style="font-size: 11pt;">ใบเสร็จรับเงิน/ใบกำกับภาษีอย่างย่อ</h5>
          <div class="d-flex justify-content-between subtle mb-1">
             <span>เลขที่: <?= htmlspecialchars($data['sale_code']) ?></span>
             <span>วันที่: <?= dt($data['sale_date']) ?></span>
          </div>
           <div class="subtle mb-2">แคชเชียร์: <?= htmlspecialchars($data['cashier_name'] ?? ($_SESSION['full_name'] ?? '-')) ?></div>
          <table class="table table-borderless table-tight mb-1">
            <thead>
                <tr style="border-bottom: 1px dashed #ccc;">
                    <th>รายการ</th>
                    <th class="text-end">จำนวน</th>
                    <th class="text-end">ราคา</th>
                    <th class="text-end">รวม</th>
                </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><?= htmlspecialchars($it['fuel_type']) ?></td>
                  <td class="text-end"><?= nf($it['liters'],2) ?></td>
                  <td class="text-end"><?= nf($it['price_per_liter'],2) ?></td>
                  <td class="text-end"><?= nf($it['line_amount'],2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="totals">
               <tr>
                 <td colspan="3" class="text-end fw-bold">รวมทั้งสิ้น</td>
                 <td class="text-end fw-bold"><?= nf($data['total_amount'],2) ?></td>
               </tr>
                <tr>
                 <td colspan="3" class="text-end">ชำระโดย:</td>
                 <td class="text-end"><?= htmlspecialchars(ucfirst($data['payment_method'] ?? 'เงินสด')) ?></td>
               </tr>
            </tfoot>
          </table>

        <?php elseif ($t==='receive'): ?>
          <h5 class="text-center fw-bold mb-2">เอกสารรับเข้าคลัง</h5>
          <div class="d-flex justify-content-between subtle mb-1">
              <span>เลขที่: #<?= htmlspecialchars($data['id']) ?></span>
              <span>วันที่: <?= dt($data['received_date']) ?></span>
          </div>
          <div class="subtle mb-2">ผู้บันทึก: <?= htmlspecialchars($data['created_by_name'] ?? '-') ?></div>
          <hr class="dashed">
          <table class="table table-borderless table-tight mb-1">
            <tbody>
              <tr><td class="fw-bold" style="width: 35%;">ซัพพลายเออร์:</td><td><?= htmlspecialchars($data['supplier_name'] ?? '-') ?></td></tr>
              <tr><td class="fw-bold">เชื้อเพลิง:</td><td><?= htmlspecialchars($data['fuel_name'] ?? '-') ?></td></tr>
              <tr><td class="fw-bold">จำนวน (ลิตร):</td><td class="text-end"><?= nf($data['amount'] ?? 0, 2) ?></td></tr>
              <tr><td class="fw-bold">ราคา/ลิตร:</td><td class="text-end"><?= nf($data['cost'] ?? 0, 2) ?></td></tr>
              <tr style="border-top: 1px dashed #ccc;"><td class="fw-bold">รวมเป็นเงิน:</td><td class="text-end fw-bold"><?= nf(($data['amount']??0) * ($data['cost']??0), 2) ?></td></tr>
               <tr><td class="fw-bold">หมายเหตุ:</td><td><?= nl2br(htmlspecialchars($data['notes'] ?? '-')) ?></td></tr>
            </tbody>
          </table>

        <?php elseif ($t==='lot'): ?>
           <h5 class="text-center fw-bold mb-2">เอกสารรับเข้าถัง (LOT)</h5>
           <div class="d-flex justify-content-between subtle mb-1">
              <span>LOT Code: <?= htmlspecialchars($data['lot_code']) ?></span>
              <span>วันที่: <?= dt($data['received_at']) ?></span>
           </div>
           <div class="subtle mb-2">ผู้บันทึก: <?= htmlspecialchars($data['created_by_name'] ?? '-') ?></div>
           <hr class="dashed">
           <table class="table table-borderless table-tight mb-1">
               <tbody>
                  <tr><td class="fw-bold" style="width: 40%;">ถัง:</td><td><?= htmlspecialchars(($data['tank_code'] ?? '').' '.($data['tank_name'] ?? '')) ?></td></tr>
                  <tr><td class="fw-bold">เชื้อเพลิง:</td><td><?= htmlspecialchars($data['fuel_name'] ?? '-') ?></td></tr>
                  <tr><td class="fw-bold">Observed (ลิตร):</td><td class="text-end"><?= nf($data['observed_liters'] ?? 0, 2) ?></td></tr>
                  <tr><td class="fw-bold">Corrected (ลิตร):</td><td class="text-end"><?= nf($data['corrected_liters'] ?? 0, 2) ?></td></tr>
                  <tr><td class="fw-bold">Initial Liters:</td><td class="text-end"><?= nf($data['initial_liters'] ?? 0, 2) ?></td></tr>
                  <tr><td class="fw-bold">Unit Cost:</td><td class="text-end"><?= nf($data['unit_cost'] ?? 0, 4) ?></td></tr>
                  <tr><td class="fw-bold">Tax/Liter:</td><td class="text-end"><?= nf($data['tax_per_liter'] ?? 0, 4) ?></td></tr>
                  <tr><td class="fw-bold">Other Costs:</td><td class="text-end"><?= nf($data['other_costs'] ?? 0, 2) ?></td></tr>
                  <tr style="border-top: 1px dashed #ccc;"><td class="fw-bold">Initial Total Cost:</td><td class="text-end fw-bold"><?= nf($data['initial_total_cost'] ?? 0, 2) ?></td></tr>
                  <tr><td class="fw-bold">หมายเหตุ:</td><td><?= nl2br(htmlspecialchars($data['notes'] ?? '-')) ?></td></tr>
               </tbody>
           </table>

        <?php elseif ($t==='transaction'): ?>
          <h5 class="text-center fw-bold mb-2">รายการการเงิน</h5>
           <div class="d-flex justify-content-between subtle mb-1">
              <span>รหัส: <?= htmlspecialchars($data['display_code'] ?? ($data['transaction_code'] ?? ($data['id'] ?? '—'))) ?></span>
              <span>วันที่: <?= dt($data['transaction_date'] ?? '') ?></span>
           </div>
           <div class="subtle mb-2">ผู้บันทึก: <?= htmlspecialchars($data['created_by_name'] ?? '-') ?></div>
           <hr class="dashed">
            <table class="table table-borderless table-tight mb-1">
                 <tbody>
                    <tr><td class="fw-bold" style="width: 30%;">ประเภท:</td><td><?= htmlspecialchars(ucfirst($data['type'] ?? '-')) ?></td></tr>
                    <tr><td class="fw-bold">หมวดหมู่:</td><td><?= htmlspecialchars($data['category'] ?? '-') ?></td></tr>
                    <tr><td class="fw-bold">รายละเอียด:</td><td><?= htmlspecialchars($data['description'] ?? '-') ?></td></tr>
                    <tr><td class="fw-bold">อ้างอิง:</td><td><?= htmlspecialchars($data['reference_id'] ?? '-') ?></td></tr>
                    <tr style="border-top: 1px dashed #ccc;"><td class="fw-bold">จำนวนเงิน:</td><td class="text-end fw-bold <?= ($data['type']??'')==='income' ? 'text-success' : 'text-danger' ?>"><?= nf($data['amount'] ?? 0,2) ?></td></tr>
                 </tbody>
            </table>
        <?php endif; ?>

      <?php endif; // end if ($data) ?>
    </div>

    <div class="receipt-footer no-print">
      <a href="javascript:window.print()" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i> พิมพ์เอกสารนี้</a>
      <a href="javascript:history.back()" class="btn btn-sm btn-secondary">ย้อนกลับ</a>
    </div>
  </div>

</body>
</html>