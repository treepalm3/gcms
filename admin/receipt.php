<?php
// receipt.php — แสดงใบเสร็จ/เอกสารเดี่ยว (เปิดจากปุ่มในตารางการเงิน)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอินและสิทธิ์ (ปรับตามระบบจริงได้) =====
if (!isset($_SESSION['user_id'])) { header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit(); }
$current_role = $_SESSION['role'] ?? '';
if (!in_array($current_role, ['admin','manager'])) { // อนุญาต admin/manager; จะให้เฉพาะ admin ก็ได้
  header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
}

// ===== เชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ'); }

// ===== Helpers =====
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function nf($n,$d=2){ return number_format((float)$n,$d,'.',','); }
function dt($s,$fmt='d/m/Y H:i'){ $t=strtotime($s); return $t? date($fmt,$t):'-'; }
function d($s,$fmt='d/m/Y'){ $t=strtotime($s); return $t? date($fmt,$t):'-'; }

// ===== ค่าพื้นฐานระบบ =====
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  if (table_exists($pdo,'settings')) {
    $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $stationId = $row ? (int)$row['setting_value'] : 1;
    $sn = $pdo->query("SELECT site_name FROM settings WHERE id=1")->fetchColumn();
    if ($sn) $site_name = $sn;
  } else {
    $stationId = 1;
  }
} catch (Throwable $e) { $stationId = 1; }

// ===== รับพารามิเตอร์ =====
// รองรับ 2 แบบ: 1) ระบุ ?t=... ใน URL 2) ถูก include ผ่าน wrapper ที่เซ็ต $forceType ไว้
$t = strtolower($_GET['t'] ?? ($forceType ?? ''));
$code = trim((string)($_GET['code'] ?? ''));   // สำหรับ sale / lot / transaction
$id   = trim((string)($_GET['id'] ?? ''));     // สำหรับ receive (id)

// ถ้ายังไม่ทราบประเภท ลองเดาจากชื่อไฟล์ที่ถูกเรียก (กรณีเข้า wrapper โดยไม่เซ็ต $forceType)
if (!$t) {
  $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
  if (stripos($script,'sales_receipt')!==false) $t='sale';
  elseif (stripos($script,'receive')!==false)  $t='receive';
  elseif (stripos($script,'lot')!==false)      $t='lot';
  elseif (stripos($script,'txn')!==false)      $t='transaction';
}

// ===== ดึงข้อมูลตามประเภท =====
$data = null;  // ข้อมูลหัวบิล
$items = [];   // รายการย่อย (เฉพาะ sale)
$title = 'ใบเสร็จ';
$notFoundMsg = '';

try {
  switch ($t) {
    case 'sale':
      // ต้องมี sale_code ใน ?code=
      if ($code==='') { $notFoundMsg='ไม่พบเลขที่บิลขาย (code)'; break; }

      // ดึงหัวบิลขาย + ผู้บันทึกจาก fuel_moves (ถ้ามี)
      $sql = "
        SELECT s.*,
               (SELECT u.full_name
                  FROM fuel_moves fm
                  JOIN users u ON u.id=fm.user_id
                 WHERE fm.type='sale_out' AND fm.sale_id = s.id
                 ORDER BY fm.id ASC LIMIT 1) AS cashier_name
        FROM sales s
        WHERE s.sale_code = :code AND s.station_id = :sid
        LIMIT 1";
      $st = $pdo->prepare($sql); $st->execute([':code'=>$code, ':sid'=>$stationId]);
      $data = $st->fetch(PDO::FETCH_ASSOC);

      if (!$data) { $notFoundMsg='ไม่พบบิลขายเลขที่ '.$code; break; }

      // รายการย่อย
      $sti = $pdo->prepare("
        SELECT fuel_type, liters, price_per_liter, (liters*price_per_liter) AS line_amount
        FROM sales_items
        WHERE sale_id = :sid
        ORDER BY id
      "); $sti->execute([':sid'=>$data['id']]);
      $items = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $title = 'ใบเสร็จรับเงิน (ขายน้ำมัน)';
      break;

    case 'receive':
      // ต้องมี ?id= เลขใบรับเข้าคลัง
      if ($id==='' || !ctype_digit($id)) { $notFoundMsg='พารามิเตอร์ id ไม่ถูกต้อง'; break; }
      $st = $pdo->prepare("
        SELECT fr.*,
               fp.fuel_name,
               s2.supplier_name,
               u.full_name AS created_by_name
        FROM fuel_receives fr
        LEFT JOIN fuel_prices fp ON fp.fuel_id = fr.fuel_id AND fp.station_id = :sid
        LEFT JOIN suppliers s2 ON s2.supplier_id = fr.supplier_id
        LEFT JOIN users u ON u.id = fr.created_by
        WHERE fr.id = :id
        LIMIT 1
      "); $st->execute([':sid'=>$stationId, ':id'=>$id]);
      $data = $st->fetch(PDO::FETCH_ASSOC);
      if (!$data) { $notFoundMsg='ไม่พบใบรับเข้าคลัง #' . $id; break; }
      $title = 'ใบรับเข้าคลัง (Fuel Receive)';
      break;

    case 'lot':
      // ต้องมี lot_code ใน ?code=
      if ($code==='') { $notFoundMsg='ไม่พบ Lot Code (code)'; break; }
      $st = $pdo->prepare("
        SELECT l.*,
               t.code AS tank_code, t.name AS tank_name,
               fp.fuel_name,
               u.full_name AS created_by_name
        FROM fuel_lots l
        JOIN fuel_tanks t ON t.id = l.tank_id
        LEFT JOIN fuel_prices fp ON fp.fuel_id = l.fuel_id
        LEFT JOIN users u ON u.id = l.created_by
        WHERE l.lot_code = :code
        LIMIT 1
      "); $st->execute([':code'=>$code]);
      $data = $st->fetch(PDO::FETCH_ASSOC);
      if (!$data) { $notFoundMsg='ไม่พบ LOT ' . htmlspecialchars($code); break; }
      $title = 'ใบรับเข้าถัง (LOT)';
      break;

    case 'transaction':
      // ใช้กับตาราง financial_transactions (ถ้ามี)
      if (!table_exists($pdo,'financial_transactions')) { $notFoundMsg='ระบบยังไม่มีตาราง financial_transactions'; break; }
      if ($code==='') { $notFoundMsg='ไม่พบรหัสรายการ (code)'; break; }

      $st = $pdo->prepare("
        SELECT ft.*,
               COALESCE(ft.transaction_code, CONCAT('FT-', ft.id)) AS display_code,
               u.full_name AS created_by_name
        FROM financial_transactions ft
        LEFT JOIN users u ON u.id = ft.user_id
        WHERE ft.transaction_code = :code OR CONCAT('FT-', ft.id) = :code
        LIMIT 1
      "); $st->execute([':code'=>$code]);
      $data = $st->fetch(PDO::FETCH_ASSOC);
      if (!$data) { $notFoundMsg='ไม่พบรายการการเงิน ' . htmlspecialchars($code); break; }
      $title = 'ใบรายการการเงิน (Financial Txn)';
      break;

    default:
      $notFoundMsg = 'ไม่รู้จักประเภทเอกสาร (t='.$t.')';
  }
} catch (Throwable $e) {
  $notFoundMsg = 'เกิดข้อผิดพลาด: '.$e->getMessage();
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?> | <?= htmlspecialchars($site_name) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f7f7; }
    .receipt { max-width:900px; margin:24px auto; background:#fff; border:1px solid #e9ecef; border-radius:12px; }
    .receipt-header { padding:16px 20px; border-bottom:1px solid #e9ecef; }
    .receipt-body { padding:20px; }
    .receipt-footer { padding:12px 20px; border-top:1px solid #e9ecef; font-size:.9rem; color:#6c757d; }
    .brand { font-weight:700; font-size:1.25rem; }
    .muted { color:#6c757d; }
    .table-tight th, .table-tight td { padding:.55rem .6rem; }
    @media print {
      .no-print { display:none !important; }
      body { background:#fff; }
      .receipt { border:none; border-radius:0; margin:0; }
    }
  </style>
</head>
<body>
  <div class="receipt">
    <div class="receipt-header d-flex justify-content-between align-items-center">
      <div>
        <div class="brand"><?= htmlspecialchars($site_name) ?></div>
        <div class="muted"><?= htmlspecialchars(ucfirst($t)) ?> • พิมพ์เมื่อ <?= d('now','d/m/Y') ?> <?= date('H:i') ?></div>
      </div>
      <div class="no-print">
        <a href="javascript:window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> พิมพ์</a>
        <a href="javascript:history.back()" class="btn btn-primary">ย้อนกลับ</a>
      </div>
    </div>

    <div class="receipt-body">
      <?php if ($notFoundMsg): ?>
        <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($notFoundMsg) ?></div>
      <?php else: ?>

        <?php if ($t==='sale'): ?>
          <div class="row">
            <div class="col-md-6">
              <h5 class="mb-1">ใบเสร็จรับเงิน</h5>
              <div class="muted">เลขที่บิล: <b><?= htmlspecialchars($data['sale_code']) ?></b></div>
            </div>
            <div class="col-md-6 text-md-end">
              <div>วันที่ขาย: <b><?= dt($data['sale_date']) ?></b></div>
              <div>ชำระเงิน: <b><?= htmlspecialchars($data['payment_method'] ?? '-') ?></b></div>
              <div>ผู้บันทึก: <b><?= htmlspecialchars($data['cashier_name'] ?? '-') ?></b></div>
            </div>
          </div>
          <hr>
          <div class="table-responsive">
            <table class="table table-striped table-tight">
              <thead><tr>
                <th>#</th><th>ประเภทเชื้อเพลิง</th><th class="text-end">ลิตร</th><th class="text-end">ราคา/ลิตร</th><th class="text-end">จำนวนเงิน</th>
              </tr></thead>
              <tbody>
                <?php $i=1; foreach ($items as $it): ?>
                  <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($it['fuel_type']) ?></td>
                    <td class="text-end"><?= nf($it['liters'],2) ?></td>
                    <td class="text-end"><?= nf($it['price_per_liter'],2) ?></td>
                    <td class="text-end"><?= nf($it['line_amount'],2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="4" class="text-end">ยอดสุทธิ</th>
                  <th class="text-end">฿<?= nf($data['total_amount'],2) ?></th>
                </tr>
              </tfoot>
            </table>
          </div>

        <?php elseif ($t==='receive'): ?>
          <div class="row">
            <div class="col-md-7">
              <h5 class="mb-1">ใบรับเข้าคลัง</h5>
              <div>เลขที่รับเข้า: <b>#<?= htmlspecialchars($data['id']) ?></b></div>
              <div>ผู้ขาย/ซัพพลายเออร์: <b><?= htmlspecialchars($data['supplier_name'] ?? '-') ?></b></div>
            </div>
            <div class="col-md-5 text-md-end">
              <div>เชื้อเพลิง: <b><?= htmlspecialchars($data['fuel_name'] ?? '-') ?></b></div>
              <div>วันที่รับ: <b><?= dt($data['received_date']) ?></b></div>
              <div>ผู้บันทึก: <b><?= htmlspecialchars($data['created_by_name'] ?? '-') ?></b></div>
            </div>
          </div>
          <hr>
          <?php
            $amount = (float)($data['amount'] ?? 0);
            $cost   = (float)($data['cost'] ?? 0);
            $total  = $amount * $cost;
          ?>
          <div class="table-responsive">
            <table class="table table-tight">
              <tbody>
                <tr><th style="width:220px;">ปริมาณ (ลิตร)</th><td class="text-end"><?= nf($amount) ?></td></tr>
                <tr><th>ต้นทุน/ลิตร (บาท)</th><td class="text-end"><?= nf($cost,2) ?></td></tr>
                <tr><th>มูลค่ารวม</th><td class="text-end"><b>฿<?= nf($total,2) ?></b></td></tr>
                <tr><th>หมายเหตุ</th><td><?= htmlspecialchars($data['notes'] ?? '-') ?></td></tr>
              </tbody>
            </table>
          </div>

        <?php elseif ($t==='lot'): ?>
          <div class="row">
            <div class="col-md-7">
              <h5 class="mb-1">ใบรับเข้าถัง (LOT)</h5>
              <div>Lot Code: <b><?= htmlspecialchars($data['lot_code']) ?></b></div>
              <div>ถัง: <b><?= htmlspecialchars(($data['tank_code'] ?? '').' '.($data['tank_name'] ?? '')) ?></b></div>
            </div>
            <div class="col-md-5 text-md-end">
              <div>เชื้อเพลิง: <b><?= htmlspecialchars($data['fuel_name'] ?? '-') ?></b></div>
              <div>วันที่รับ: <b><?= dt($data['received_at']) ?></b></div>
              <div>ผู้บันทึก: <b><?= htmlspecialchars($data['created_by_name'] ?? '-') ?></b></div>
            </div>
          </div>
          <hr>
          <div class="table-responsive">
            <table class="table table-tight">
              <tbody>
                <tr><th style="width:260px;">Observed (ลิตร)</th><td class="text-end"><?= nf($data['observed_liters'] ?? 0) ?></td></tr>
                <tr><th>Corrected (ลิตร)</th><td class="text-end"><?= nf($data['corrected_liters'] ?? 0) ?></td></tr>
                <tr><th>Initial Liters</th><td class="text-end"><?= nf($data['initial_liters'] ?? 0) ?></td></tr>
                <tr><th>Unit Cost</th><td class="text-end"><?= nf($data['unit_cost'] ?? 0,4) ?></td></tr>
                <tr><th>Tax per Liter</th><td class="text-end"><?= nf($data['tax_per_liter'] ?? 0,4) ?></td></tr>
                <tr><th>Other Costs</th><td class="text-end"><?= nf($data['other_costs'] ?? 0,2) ?></td></tr>
                <tr class="table-active"><th>Initial Total Cost</th><td class="text-end"><b>฿<?= nf($data['initial_total_cost'] ?? 0,2) ?></b></td></tr>
                <tr><th>หมายเหตุ</th><td><?= htmlspecialchars($data['notes'] ?? '-') ?></td></tr>
              </tbody>
            </table>
          </div>

        <?php elseif ($t==='transaction'): ?>
          <h5 class="mb-1">รายการการเงิน</h5>
          <div class="row">
            <div class="col-md-6">
              <div>รหัส: <b><?= htmlspecialchars($data['display_code'] ?? '—') ?></b></div>
              <div>ประเภท: <b><?= htmlspecialchars($data['type'] ?? '-') ?></b></div>
              <div>หมวดหมู่: <b><?= htmlspecialchars($data['category'] ?? '-') ?></b></div>
            </div>
            <div class="col-md-6 text-md-end">
              <div>วันที่ทำรายการ: <b><?= dt($data['transaction_date'] ?? '') ?></b></div>
              <div>ผู้บันทึก: <b><?= htmlspecialchars($data['created_by_name'] ?? '-') ?></b></div>
            </div>
          </div>
          <hr>
          <div class="table-responsive">
            <table class="table table-tight">
              <tbody>
                <tr><th style="width:240px;">รายละเอียด</th><td><?= htmlspecialchars($data['description'] ?? '-') ?></td></tr>
                <tr><th>อ้างอิง</th><td><?= htmlspecialchars($data['reference_id'] ?? '-') ?></td></tr>
                <tr class="<?= ($data['type']??'')==='income' ? 'table-success' : 'table-danger' ?>">
                  <th>จำนวนเงิน</th><td class="text-end"><b>฿<?= nf($data['amount'] ?? 0,2) ?></b></td>
                </tr>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>

    <div class="receipt-footer">
      เอกสารนี้สร้างจากระบบ — ใช้สำหรับอ้างอิงภายใน หากต้องการรูปแบบทางการ (เช่น PDF หัวกระดาษองค์กร) แจ้งทีมไอทีได้
    </div>
  </div>

  <!-- icons (เฉพาะปุ่มพิมพ์) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</body>
</html>
