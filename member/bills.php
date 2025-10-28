<?php
// member/bills.php — ประวัติการซื้อของสมาชิก (แก้ไข: รองรับกรรมการ/ผู้บริหารที่เป็นสมาชิก)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ====== CSRF ======
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ====== บังคับล็อกอิน + บทบาท ======
try {
  if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php'); exit();
  }
  $current_name = $_SESSION['full_name'] ?: 'สมาชิกสหกรณ์';
  $current_role = $_SESSION['role'] ?? 'guest';
  
  // [แก้ไข] อนุญาตทุกบทบาทที่มีสถานะเป็นสมาชิก
  $allowed_roles = ['member', 'manager', 'committee', 'admin']; 
  if(!in_array($current_role, $allowed_roles)){
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
} catch(Throwable $e){
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}

// ====== เชื่อมฐานข้อมูล ======
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องได้ $pdo (PDO)

$db_ok = true; $db_err = null;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('ไม่พบตัวแปร $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET NAMES utf8mb4");
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}
} catch (Throwable $e) { $db_ok = false; $db_err = $e->getMessage(); }

// ====== ข้อมูลไซต์ / สถานี ======
$site_name = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
$station_id = 1;

if ($db_ok) {
  try {
    // station_id + ชื่อสถานี (comment)
    $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $station_id = (int)$r['setting_value'];
      if (!empty($r['comment'])) $site_name = $r['comment'];
    }
    // system_settings ใน app_settings
    $sys = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings'")->fetchColumn();
    if ($sys) {
      $sysj = json_decode($sys, true);
      if (!empty($sysj['site_name'])) $site_name = $sysj['site_name'];
      if (!empty($sysj['site_subtitle'])) $site_subtitle = $sysj['site_subtitle'];
    }
  } catch (Throwable $e) {}
}

// ====== ผู้ใช้ปัจจุบัน ======
$current_user_id = (int)$_SESSION['user_id'];
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ====== ข้อมูลสมาชิก (users + members) ======
$member = null;
$member_id = null;
$member_phone = null;
$member_house = null;

if ($db_ok) {
  try {
    // [แก้ไข] ลบเงื่อนไข u.role='member' เพื่อให้ดึงข้อมูลได้ทุกบทบาทที่เป็นสมาชิก
    $sql = "
      SELECT u.id AS user_id, u.full_name, u.phone,
             m.id AS member_id, m.house_number, m.member_code
      FROM users u
      JOIN members m ON m.user_id = u.id
      WHERE u.id = :uid AND u.is_active=1
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':uid'=>$current_user_id]);
    $member = $st->fetch(PDO::FETCH_ASSOC);

    if ($member) {
      $current_name = $member['full_name'] ?: $current_name;
      $member_id    = (int)$member['member_id'];
      $member_phone = $member['phone'] ?: '';
      $member_house = $member['house_number'] ?: '';
    }
  } catch (Throwable $e) { $db_err = $e->getMessage(); }
}

// ====== ดึงบิลของสมาชิก ======
// ... (ส่วนที่เหลือของโค้ดดึงบิลใช้ member_phone และ member_house ที่ดึงมาแล้ว)
$rows = [];
if ($db_ok && $member) {
  try {
    // ทางหลัก: match ด้วยเบอร์โทร หรือเลขบ้าน (ที่ POS ต้องบันทึกลง sales)
    $q = $pdo->prepare("
      SELECT
        s.id,
        s.sale_code,
        s.sale_date,
        s.payment_method,
        s.total_amount,
        s.net_amount,
        s.discount_amount,
        s.discount_pct,
        GROUP_CONCAT(DISTINCT si.fuel_type ORDER BY si.id SEPARATOR ', ') AS fuels,
        SUM(si.liters) AS liters,
        CASE
          WHEN COUNT(DISTINCT si.fuel_type)=1 AND COUNT(DISTINCT si.price_per_liter)=1
            THEN MAX(si.price_per_liter)
          ELSE NULL
        END AS price_per_liter
      FROM sales s
      LEFT JOIN sales_items si ON si.sale_id = s.id
      WHERE s.station_id = :st
        AND (
              (s.customer_phone IS NOT NULL AND s.customer_phone <> '' AND s.customer_phone = :phone)
           OR (s.household_no  IS NOT NULL AND s.household_no  <> '' AND s.household_no  = :house)
        )
      GROUP BY s.id, s.sale_code, s.sale_date, s.payment_method, s.total_amount, s.net_amount, s.discount_amount, s.discount_pct
      ORDER BY s.sale_date DESC
      LIMIT 200
    ");
    $q->execute([
      ':st'    => $station_id,
      ':phone' => (string)$member_phone,
      ':house' => (string)$member_house,
    ]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ทางสำรอง: ใช้ scores.activity ที่มี sale_code ผูกกับ sales
    if (empty($rows) && $member_id) {
      $q2 = $pdo->prepare("
        SELECT
          s.id,
          s.sale_code,
          s.sale_date,
          s.payment_method,
          s.total_amount,
          s.net_amount,
          s.discount_amount,
          s.discount_pct,
          GROUP_CONCAT(DISTINCT si.fuel_type ORDER BY si.id SEPARATOR ', ') AS fuels,
          SUM(si.liters) AS liters,
          CASE
            WHEN COUNT(DISTINCT si.fuel_type)=1 AND COUNT(DISTINCT si.price_per_liter)=1
              THEN MAX(si.price_per_liter)
            ELSE NULL
          END AS price_per_liter
        FROM scores sc
        JOIN sales s
          ON sc.activity LIKE CONCAT('%', s.sale_code)
        LEFT JOIN sales_items si ON si.sale_id = s.id
        WHERE sc.member_id = :mid AND s.station_id = :st
        GROUP BY s.id, s.sale_code, s.sale_date, s.payment_method, s.total_amount, s.net_amount, s.discount_amount, s.discount_pct
        ORDER BY s.sale_date DESC
        LIMIT 200
      ");
      $q2->execute([':mid'=>$member_id, ':st'=>$station_id]);
      $rows = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $e) {
    $db_err = $e->getMessage();
  }
}

// ====== เตรียมข้อมูลสำหรับหน้า ======
$pay_th = ['cash'=>'เงินสด','qr'=>'พร้อมเพย์','transfer'=>'โอนเงิน','card'=>'บัตรเครดิต'];

$recent_bills = [];
$fuel_set = [];  // เก็บชนิดน้ำมันทุกชนิด (เป็นรายชนิด)
$pay_set  = [];  // เก็บ label วิธีชำระ

foreach ($rows as $r) {
  $dt = new DateTime($r['sale_date']);
  $fuels = array_map('trim', array_filter(explode(',', $r['fuels'] ?? ''))); // list รายชนิด
  foreach($fuels as $fx){ if($fx!=='') $fuel_set[$fx] = true; }

  $pay_label = $pay_th[$r['payment_method']] ?? $r['payment_method'];
  if ($pay_label) $pay_set[$pay_label] = true;

  $recent_bills[] = [
    'date'  => $dt->format('Y-m-d'),
    'time'  => $dt->format('H:i'),
    'bill'  => $r['sale_code'],
    'fuels' => implode(', ', $fuels) ?: '-',
    'fuel_keys' => implode('|', $fuels), // สำหรับกรองแบบหลายค่า
    'liters'=> (float)($r['liters'] ?? 0),
    'price' => isset($r['price_per_liter']) && $r['price_per_liter'] !== null ? (float)$r['price_per_liter'] : null,
    'total' => (float)($r['net_amount'] ?: $r['total_amount'] ?: 0),
    'pay'   => $pay_label,
  ];
}

// สำหรับ dropdown filter
$fuel_types = array_keys($fuel_set);
sort($fuel_types);
$pay_methods = array_keys($pay_set);
sort($pay_methods);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ประวัติการซื้อ | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    .panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
    .ok-badge{background:rgba(32,163,158,.12)!important;color:var(--teal)!important;border:1px solid var(--border)}
    .form-select, .form-control { min-width: 150px; }

    @media print {
      body > .navbar,
      body > .container-fluid > .row > .sidebar,
      body > .footer,
      main > .main-header,
      .panel > .d-flex.justify-content-between {
        display: none !important;
      }
      body > .container-fluid,
      body > .container-fluid > .row,
      main.col-lg-10 {
        display: block !important;
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        flex: none !important;
        max-width: 100% !important;
      }
      .panel { box-shadow: none; border: none; }
      /* ซ่อนคอลัมน์พิมพ์ ตอนสั่งพิมพ์ทั้งหน้า */
      #tblBills th:last-child, #tblBills td:last-child { display: none; }
    }
  </style>
</head>
<body>

  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="member_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
      </div>
      <div class="d-flex align-items-center gap-3 ms-auto">
        <div class="nav-identity text-end d-none d-sm-block">
          <div class="nav-name"><?= htmlspecialchars($current_name) ?></div>
          <div class="nav-sub"><?= htmlspecialchars($current_role_th) ?></div>
        </div>
        <a href="profile.php" class="avatar-circle text-decoration-none"><?= htmlspecialchars($avatar_text) ?></a>
      </div>
    </div>
  </nav>

  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Member</span></h3></div>
      <nav class="sidebar-menu">
        <a href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
        <a class="active" href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
        <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Member</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
          <a class="active" href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
          <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>

      <main class="col-lg-10 p-4">
        <div class="main-header">
          <h2><i class="fa-solid fa-receipt me-2"></i>ประวัติการซื้อ</h2>
        </div>

        <?php if (!$db_ok): ?>
          <div class="alert alert-danger">เชื่อมต่อฐานข้อมูลไม่สำเร็จ: <?= htmlspecialchars($db_err) ?></div>
        <?php endif; ?>

        <div class="panel">
          <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <div class="d-flex flex-wrap gap-2">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" id="billSearch" class="form-control" placeholder="ค้นหา: บิล/ชนิด/วิธีจ่าย">
              </div>
              <select id="filterFuel" class="form-select">
                <option value="">ทุกชนิดน้ำมัน</option>
                <?php foreach($fuel_types as $fuel): ?>
                <option value="<?= htmlspecialchars($fuel) ?>"><?= htmlspecialchars($fuel) ?></option>
                <?php endforeach; ?>
              </select>
              <select id="filterPay" class="form-select">
                <option value="">ทุกวิธีชำระ</option>
                <?php foreach($pay_methods as $pay): ?>
                <option value="<?= htmlspecialchars($pay) ?>"><?= htmlspecialchars($pay) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-light" id="btnResetFilters"><i class="fa-solid fa-rotate-left me-1"></i>ล้างค่า</button>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-secondary" id="btnPrintBills"><i class="fa-solid fa-print me-1"></i> พิมพ์</button>
              <button class="btn btn-outline-primary" id="btnExportCSV"><i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV</button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tblBills">
              <thead><tr>
                <th>วันที่</th><th>เวลา</th><th>บิล</th><th>ชนิด</th><th class="text-end">ลิตร</th><th class="text-end">ราคา/ลิตร</th><th class="text-end">สุทธิ</th><th>ชำระ</th><th class="text-end">พิมพ์</th>
              </tr></thead>
              <tbody>
                <?php if (empty($recent_bills)): ?>
                  <tr><td colspan="9" class="text-center text-muted">ยังไม่มีประวัติการซื้อ</td></tr>
                <?php else: ?>
                  <?php foreach($recent_bills as $r): ?>
                    <tr data-fuel="<?= htmlspecialchars($r['fuel_keys']) ?>" 
                        data-pay="<?= htmlspecialchars($r['pay']) ?>"
                        data-receipt-type="sale"
                        data-receipt-code="<?= htmlspecialchars($r['bill']) ?>"
                        data-receipt-url="sales_receipt.php?code=<?= urlencode($r['bill']) ?>">
                      <td><?= htmlspecialchars($r['date']) ?></td>
                      <td><?= htmlspecialchars($r['time']) ?></td>
                      <td><b><?= htmlspecialchars($r['bill']) ?></b></td>
                      <td><?= htmlspecialchars($r['fuels']) ?></td>
                      <td class="text-end"><?= number_format($r['liters'],2) ?></td>
                      <td class="text-end"><?= $r['price']!==null ? number_format($r['price'],2) : '-' ?></td>
                      <td class="text-end"><b><?= number_format($r['total'],2) ?></b></td>
                      <td><span class="badge ok-badge"><?= htmlspecialchars($r['pay']) ?></span></td>
                      <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary btnReceipt" title="ดู/พิมพ์ใบเสร็จ"><i class="fa-solid fa-print"></i></button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            <div id="noResults" class="text-center text-muted p-3" style="display:none;">ไม่พบรายการที่ตรงกับเงื่อนไข</div>
          </div>

        </div>
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — ศูนย์สมาชิก</footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const billSearch = document.getElementById('billSearch');
      const filterFuel = document.getElementById('filterFuel');
      const filterPay = document.getElementById('filterPay');
      const btnReset = document.getElementById('btnResetFilters');
      const tableBody = document.querySelector('#tblBills tbody');
      const noResults = document.getElementById('noResults');
      const btnPrint = document.getElementById('btnPrintBills');
      const btnExport = document.getElementById('btnExportCSV');
// --- [!! ใหม่ !!] ตรรกะการเปิดลิงก์ใบเสร็จ (เหมือน committee/finance.php) ---
const receiptRoutes = {
    sale: code => `sales_receipt.php?code=${encodeURIComponent(code)}`
    // หน้านี้ต้องการแค่ 'sale'
  };

  // ใช้ tableBody listener ที่มีอยู่แล้ว หรือจะใช้ document.body ก็ได้
  tableBody.addEventListener('click', function(e) {
      const receiptBtn = e.target.closest('.btnReceipt'); // ค้นหาปุ่มใหม่
      if (!receiptBtn) return; // ถ้าไม่ได้คลิกปุ่มพิมพ์ ก็ไม่ต้องทำอะไร

      const tr = receiptBtn.closest('tr');
      if (!tr) return;

      const type = tr.dataset.receiptType;
      const code = tr.dataset.receiptCode;
      let url = (tr.dataset.receiptUrl || '').trim();

      // ตรรกะสำรอง (เผื่อ data-receipt-url ไม่ได้ตั้งค่า)
      if (!url && type && code && receiptRoutes[type]) {
         url = receiptRoutes[type](code);
      }

      if (url && url !== '') {
          window.open(url, '_blank'); // เปิดในแท็บใหม่
      } else {
          console.warn('Could not determine receipt URL for this row', tr);
          alert('ไม่พบ URL ใบเสร็จสำหรับรายการนี้');
      }
  });
  // --- [!! สิ้นสุดส่วนใหม่ !!] ---
      function visibleRows() {
        return Array.from(document.querySelectorAll('#tblBills tbody tr'))
          .filter(row => row.querySelectorAll('td').length > 1); // ตัดแถว "ยังไม่มีประวัติ" ออก
      }

      function applyFilters() {
        const searchTerm = (billSearch.value || '').toLowerCase().trim();
        const fuelType = filterFuel.value;
        const payMethod = filterPay.value;
        let visibleCount = 0;

        visibleRows().forEach(row => {
          const rowText = row.textContent.toLowerCase();
          const dataFuel = (row.dataset.fuel || ''); // อาจเป็น "ดีเซล|แก๊สโซฮอล์ 95"
          const dataPay = row.dataset.pay || '';

          const matchSearch = !searchTerm || rowText.includes(searchTerm);
          const matchFuel = !fuelType || dataFuel.split('|').includes(fuelType);
          const matchPay = !payMethod || dataPay === payMethod;

          if (matchSearch && matchFuel && matchPay) {
            row.style.display = '';
            visibleCount++;
          } else {
            row.style.display = 'none';
          }
        });

        noResults.style.display = visibleCount === 0 ? '' : 'none';
      }

      function resetFilters() {
        billSearch.value = '';
        filterFuel.value = '';
        filterPay.value = '';
        applyFilters();
      }

      // --- Export to CSV ---
      function exportToCSV() {
        const headers = ['วันที่', 'เวลา', 'บิล', 'ชนิด', 'ลิตร', 'ราคา/ลิตร', 'สุทธิ', 'ชำระ'];
        const rows = [headers];

        visibleRows().forEach(row => {
          if (row.style.display === 'none') return; // เฉพาะแถวที่มองเห็น

          const cells = row.querySelectorAll('td');
          // ตัดคอลัมน์ปุ่มพิมพ์ (คอลัมน์สุดท้าย)
          const rowData = Array.from(cells).slice(0, -1).map(cell => {
            let text = cell.textContent.trim();
            if (cell.classList.contains('text-end')) {
              text = text.replace(/,/g, ''); // ลบ comma ตัวเลข
            }
            return text;
          });
          rows.push(rowData);
        });

        if (rows.length <= 1) {
          alert('ไม่มีข้อมูลให้ส่งออก');
          return;
        }

        let csvContent = rows.map(e => e.map(item => `"${(item || '').replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([`\uFEFF${csvContent}`], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        const today = new Date().toISOString().slice(0, 10);
        link.setAttribute('href', url);
        link.setAttribute('download', `bills-history-${today}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
      }

      billSearch.addEventListener('input', applyFilters);
      filterFuel.addEventListener('change', applyFilters);
      filterPay.addEventListener('change', applyFilters);
      btnReset.addEventListener('click', resetFilters);
      btnExport.addEventListener('click', exportToCSV);
      btnPrint.addEventListener('click', () => window.print());
    });
  </script>
</body>
</html>