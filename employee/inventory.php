<?php
// employee/inventory.php - ระบบจัดการน้ำมันสำหรับพนักงาน
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอินและสิทธิ์ =====
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
  exit();
}

// Database connection
$dbFile = __DIR__ . '/../config/db.php';
require_once $dbFile; // ต้องกำหนดตัวแปร $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ');
}

// ===== ดึงข้อมูลผู้ใช้และสิทธิ์ =====
try {
  $current_name = $_SESSION['full_name'] ?? 'พนักงาน';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'employee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
  exit();
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ================= ดึงข้อมูลจากฐานข้อมูล ================= */
$site_name   = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$station_id  = 1;
$fuels       = [];
$suppliers   = [];
$alerts      = [];
$recent_receives = [];

try {
  // --- ดึง station_id และชื่อสถานีจาก settings ---
  $st_settings = $pdo->query("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
  if ($row = $st_settings->fetch(PDO::FETCH_ASSOC)) {
    $station_id = (int)($row['setting_value'] ?? 1);
    if (!empty($row['comment'])) { $site_name = $row['comment']; }
  }

  // --- Fuels สรุปจากถัง: ผูกถังกับสถานีเดียวกันเสมอ ---
  $sql_fuels = "
    SELECT
      fp.fuel_id,
      fp.fuel_name,
      fp.price,
      COALESCE(SUM(ft.current_volume_l), 0) AS current_stock,
      COALESCE(SUM(ft.capacity_l), 0)       AS capacity,
      COALESCE(SUM(ft.min_threshold_l), 0)  AS min_threshold
    FROM fuel_prices fp
    LEFT JOIN fuel_tanks ft
           ON ft.fuel_id = fp.fuel_id
          AND ft.station_id = fp.station_id
          AND ft.is_active = 1
    WHERE fp.station_id = :station_id
    GROUP BY fp.fuel_id, fp.fuel_name, fp.price
    ORDER BY fp.display_order ASC, fp.fuel_id ASC
  ";
  $stmt_fuels = $pdo->prepare($sql_fuels);
  $stmt_fuels->execute([':station_id' => $station_id]);

  while ($row = $stmt_fuels->fetch(PDO::FETCH_ASSOC)) {
    $capacity = (float)$row['capacity'];
    $current  = (float)$row['current_stock'];
    $pct = $capacity > 0 ? max(0, min(100, ($current / $capacity) * 100)) : 0;
    $fuels[(int)$row['fuel_id']] = [
      'name'             => $row['fuel_name'],
      'price'            => (float)$row['price'],
      'current_stock'    => $current,
      'capacity'         => $capacity,
      'min_threshold'    => (float)$row['min_threshold'],
      'stock_percentage' => $pct
    ];
  }

  // --- Suppliers (กรองตามสถานี) ---
  $stmt_suppliers = $pdo->prepare("
    SELECT supplier_id, supplier_name
      FROM suppliers
     WHERE station_id = :station_id
     ORDER BY supplier_name ASC
  ");
  $stmt_suppliers->execute([':station_id' => $station_id]);
  $suppliers = $stmt_suppliers->fetchAll(PDO::FETCH_ASSOC);

  // --- Alerts (low stock) ---
  foreach ($fuels as $fuel_id => $fuel) {
    if ($fuel['current_stock'] <= $fuel['min_threshold']) {
      $alerts[] = ['type'=>'danger','message'=>"สต็อก {$fuel['name']} เหลือน้อย: ".number_format($fuel['current_stock'],0).' ลิตร'];
    } elseif ($fuel['current_stock'] <= ($fuel['min_threshold'] * 1.5)) {
      $alerts[] = ['type'=>'warning','message'=>"สต็อก {$fuel['name']} ใกล้หมด: ".number_format($fuel['current_stock'],0).' ลิตร'];
    }
  }

// --- Recent receives (กรองตามสถานีผ่าน fuel_prices และ suppliers) ---
  $stmt_receives = $pdo->prepare("
    SELECT
      fr.received_date,
      fr.amount,
      fr.cost,
      fp.fuel_name,
      s.supplier_name
    FROM fuel_receives fr
    JOIN fuel_prices  fp
      ON fp.fuel_id    = fr.fuel_id
    AND fp.station_id = :sid_fp
    LEFT JOIN suppliers s
      ON s.supplier_id = fr.supplier_id
    AND s.station_id  = :sid_sup
    ORDER BY fr.received_date DESC
    LIMIT 5
  ");
  $stmt_receives->execute([
    ':sid_fp'  => $station_id,
    ':sid_sup' => $station_id,
  ]);
  $recent_receives = $stmt_receives->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $alerts[] = [ 'type' => 'danger', 'message' => 'ไม่สามารถโหลดข้อมูลจากฐานข้อมูลได้: ' . $e->getMessage() ];
}

// รวมยอด
$total_capacity = array_sum(array_column($fuels, 'capacity'));
$total_stock    = array_sum(array_column($fuels, 'current_stock'));

// แสดงชื่อบทบาท
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน',
  'member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>จัดการน้ำมัน | <?= htmlspecialchars($site_name) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
<link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
<style>
  .stock-card { border-left: 4px solid #6c757d; }
  .stock-card.low-stock { border-left-color: #dc3545; }
  .stock-card.medium-stock { border-left-color: #ffc107; }
  .stock-card.high-stock { border-left-color: #198754; }
  .stock-progress { height: 8px; }
  .stock-progress-bar.low { background-color: #dc3545; }
  .stock-progress-bar.medium { background-color: #ffc107; }
  .stock-progress-bar.high { background-color: #198754; }
</style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
      <a class="navbar-brand" href="profile.php"><?= htmlspecialchars($site_name) ?></a>
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

<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Employee</span></h3></div>
    <nav class="sidebar-menu">
      <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
      <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
      <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
      <a class="active" href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
      <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
      <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
    </nav>
    <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Employee</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
        <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
        <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
        <a class="active" href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
        <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
      <div class="main-header d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-fuel-pump-fill"></i> จัดการน้ำมัน</h2>
      </div>

      <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>">
          <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($alert['message']) ?>
        </div>
      <?php endforeach; ?>

      <div class="stats-grid">
        <div class="stat-card">
          <h6><i class="fa-solid fa-droplet me-2"></i>สต็อกรวม</h6>
          <h3><?= number_format($total_stock, 0) ?> ลิตร</h3>
        </div>
        <div class="stat-card">
          <h6><i class="fa-solid fa-inbox me-2"></i>ความจุรวม</h6>
          <h3><?= number_format($total_capacity, 0) ?> ลิตร</h3>
        </div>
      </div>

      <ul class="nav nav-tabs mb-3 mt-4" id="inventoryTab" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stock-panel"><i class="fa-solid fa-oil-can me-2"></i>สถานะสต็อก</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#receive-panel"><i class="fa-solid fa-truck-fast me-2"></i>รับน้ำมัน</button></li>
      </ul>

      <div class="tab-content" id="inventoryTabContent">
        <!-- แท็บสถานะสต็อก -->
        <div class="tab-pane fade show active" id="stock-panel" role="tabpanel">
          <div class="panel">
            <div class="row g-3">
              <?php foreach ($fuels as $id => $fuel):
                $pct = $fuel['stock_percentage'];
                $stock_class = $pct <= 25 ? 'low' : ($pct <= 50 ? 'medium' : 'high');
              ?>
              <div class="col-md-6 col-lg-4">
                <div class="card stock-card <?= $stock_class ?>-stock">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                      <h6 class="card-title mb-0"><?= htmlspecialchars($fuel['name']) ?></h6>
                      <span class="badge bg-primary">฿<?= number_format($fuel['price'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                      <span class="text-muted">คงเหลือ</span>
                      <strong><?= number_format($fuel['current_stock'], 0) ?> ลิตร</strong>
                    </div>
                    <div class="progress stock-progress mb-2">
                      <div class="progress-bar stock-progress-bar <?= $stock_class ?>" style="width: <?= $pct ?>%"></div>
                    </div>
                    <div class="row text-center">
                      <div class="col">
                        <small class="text-muted">ความจุ</small><br>
                        <small><strong><?= number_format($fuel['capacity'], 0) ?></strong></small>
                      </div>
                      <div class="col">
                        <small class="text-muted">เปอร์เซ็นต์</small><br>
                        <small><strong><?= number_format($pct, 1) ?>%</strong></small>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- แท็บรับน้ำมัน -->
        <div class="tab-pane fade" id="receive-panel" role="tabpanel">
          <div class="panel mb-4">
            <h5 class="mb-3"><i class="fa-solid fa-truck-fast me-2"></i>บันทึกการรับน้ำมันเข้าคลัง</h5>
            <form action="refill.php" method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">ประเภทน้ำมัน</label>
                  <select name="fuel_id" class="form-select" required>
                    <option value="">เลือก...</option>
                    <?php foreach ($fuels as $fid => $f): ?>
                      <option value="<?= (int)$fid ?>"><?= htmlspecialchars($f['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label">จำนวน (ลิตร)</label>
                  <div class="input-group">
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                    <span class="input-group-text">ลิตร</span>
                  </div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">ราคาต้นทุน (บาท/ลิตร)</label>
                  <div class="input-group">
                    <input type="number" name="cost" class="form-control" step="0.01" min="0">
                    <span class="input-group-text">บาท</span>
                  </div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">ซัพพลายเออร์</label>
                  <select name="supplier_id" class="form-select">
                    <option value="">ไม่ระบุ</option>
                    <?php foreach ($suppliers as $s): ?>
                      <option value="<?= (int)$s['supplier_id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-12">
                  <label class="form-label">หมายเหตุ</label>
                  <textarea name="notes" class="form-control" rows="2" placeholder="เช่น เลขที่ใบส่งของ"></textarea>
                </div>
              </div>

              <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-success">
                  <i class="fa-solid fa-save me-1"></i> บันทึกการรับ
                </button>
                <button type="reset" class="btn btn-outline-secondary">ล้างฟอร์ม</button>
              </div>
            </form>
          </div>

          <div class="panel">
            <h5 class="mb-3">ประวัติการรับล่าสุด</h5>
            <div class="table-responsive">
              <table class="table table-sm table-hover">
                <thead>
                  <tr>
                    <th>วันที่</th>
                    <th>ประเภท</th>
                    <th class="text-end">จำนวน (ลิตร)</th>
                    <th>ผู้จัดส่ง</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($recent_receives)): ?>
                    <tr><td colspan="4" class="text-center text-muted">ยังไม่มีข้อมูล</td></tr>
                  <?php else: foreach ($recent_receives as $rc): ?>
                    <tr>
                      <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($rc['received_date']))) ?></td>
                      <td><?= htmlspecialchars($rc['fuel_name']) ?></td>
                      <td class="text-end"><?= number_format((float)$rc['amount'], 2) ?></td>
                      <td><?= htmlspecialchars($rc['supplier_name'] ?? '-') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div><!-- /receive-panel -->
      </div><!-- /tab-content -->
    </main>
  </div>
</div>

<footer class="footer">© <?= date('Y'); ?> <?= htmlspecialchars($site_name) ?></footer>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="toastBox" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const toastEl = document.getElementById('toastBox');
  const toastMsg = document.getElementById('toastMsg');
  const bsToast = new bootstrap.Toast(toastEl, { delay: 3000 });

  function showToast(message, isSuccess = true) {
    toastEl.className = `toast align-items-center text-white border-0 ${isSuccess ? 'bg-success' : 'bg-danger'}`;
    toastMsg.textContent = message;
    bsToast.show();
  }

  const urlParams = new URLSearchParams(window.location.search);
  const okMsg  = urlParams.get('ok');
  const errMsg = urlParams.get('err');
  if (okMsg)  { showToast(okMsg, true);  window.history.replaceState({}, document.title, window.location.pathname); }
  if (errMsg) { showToast(errMsg, false); window.history.replaceState({}, document.title, window.location.pathname); }
});
</script>

</body>
</html>
