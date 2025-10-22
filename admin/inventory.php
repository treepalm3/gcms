<?php
// inventory.php - Enhanced Fuel Management System (รับน้ำมันเข้าคลัง -> กระจายลงถังอัตโนมัติ)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
    exit();
}

// Database connection
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
  $current_name = $_SESSION['full_name'] ?: 'ผู้ดูแลระบบ';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'admin') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
  exit();
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ================= helper ================= */
function table_exists(PDO $pdo, string $table): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :tb');
  $stmt->execute([':db'=>$db, ':tb'=>$table]);
  return (int)$stmt->fetchColumn() > 0;
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :db AND table_name = :tb AND column_name = :col');
  $stmt->execute([':db'=>$db, ':tb'=>$table, ':col'=>$col]);
  return (int)$stmt->fetchColumn() > 0;
}
function get_setting(PDO $pdo, string $name, $default=null){
  try{
    if (!table_exists($pdo,'settings')) return $default;
    if (column_exists($pdo,'settings','setting_name') && column_exists($pdo,'settings','setting_value')){
      $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name = :n LIMIT 1');
      $st->execute([':n'=>$name]);
      $v = $st->fetchColumn();
      return $v !== false ? $v : $default;
    }
  }catch(Throwable $e){}
  return $default;
}

/* ================= defaults & data ================= */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$station_id = 1;
$fuels = [];
$pumps = [];
$suppliers = [];
$alerts = [];
$recent_receives = [];

try {
  // (แพตช์ #1) Settings: เช็กตารางก่อน query
  if (table_exists($pdo,'settings')) {
    $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $station_id = (int)($row['setting_value'] ?? 1);
      if (!empty($row['comment'])) { $site_name = $row['comment']; }
    }
  }

  // Fuels (สรุปรวมจากถัง)
  if (table_exists($pdo,'fuel_prices') && table_exists($pdo,'fuel_tanks')) {
    $sql = "
      SELECT
        fp.fuel_id,
        fp.fuel_name,
        fp.price,
        fp.display_order,
        COALESCE(SUM(ft.current_volume_l),0)        AS current_stock,
        COALESCE(SUM(ft.capacity_l),0)              AS capacity,
        COALESCE(SUM(ft.min_threshold_l),0)         AS min_threshold,
        COALESCE(SUM(ft.max_threshold_l),0)         AS max_threshold,
        (SELECT MAX(fm.occurred_at)
           FROM fuel_moves fm
           JOIN fuel_tanks t2 ON t2.id = fm.tank_id
          WHERE fm.type IN ('receive','transfer_in','adjust_plus')
            AND t2.fuel_id = fp.fuel_id)            AS last_refill_date,
        (SELECT fm2.liters
           FROM fuel_moves fm2
           JOIN fuel_tanks t3 ON t3.id = fm2.tank_id
          WHERE fm2.type IN ('receive','transfer_in','adjust_plus')
            AND t3.fuel_id = fp.fuel_id
          ORDER BY fm2.occurred_at DESC, fm2.id DESC
          LIMIT 1)                                  AS last_refill_amount
      FROM fuel_prices fp
      LEFT JOIN fuel_tanks ft ON ft.fuel_id = fp.fuel_id AND ft.is_active = 1
      GROUP BY fp.fuel_id, fp.fuel_name, fp.price, fp.display_order
      ORDER BY fp.display_order ASC, fp.fuel_id ASC
    ";
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $capacity = (float)$row['capacity'];
      $current  = (float)$row['current_stock'];
      $pct = $capacity > 0 ? max(0,min(100, ($current / $capacity) * 100)) : 0;
      $fuels[$row['fuel_id']] = [
        'name' => $row['fuel_name'],
        'price' => (float)$row['price'],
        'current_stock' => $current,
        'capacity' => $capacity,
        'min_threshold' => (float)$row['min_threshold'],
        'max_threshold' => (float)$row['max_threshold'],
        'last_refill_date' => $row['last_refill_date'],
        'last_refill_amount' => isset($row['last_refill_amount']) ? (float)$row['last_refill_amount'] : null,
        'stock_percentage' => $pct
      ];
    }
  }

  // Pumps
  if (table_exists($pdo,'pumps')){
    $stmt = $pdo->query('SELECT pump_id, pump_name, fuel_id, status, last_maintenance, COALESCE(total_sales_today,0) AS total_sales_today, pump_location FROM pumps ORDER BY pump_id ASC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $pumps[] = $row; }
  }

  // Suppliers
  if (table_exists($pdo,'suppliers')){
    $stmt = $pdo->query('SELECT supplier_id, supplier_name, contact_person, phone, email, fuel_types, last_delivery_date, COALESCE(rating,0) AS rating FROM suppliers ORDER BY supplier_name ASC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $suppliers[] = $row; }
  }

  // (แพตช์ #2) ถ้ายังไม่มีข้อมูลน้ำมันเลย → แจ้งเตือนแบบ info
  if (empty($fuels)) {
    $alerts[] = [
      'type'=>'info',
      'icon'=>'fa-info-circle',
      'message'=>'ยังไม่มีข้อมูลน้ำมัน กรุณาเพิ่มชนิดน้ำมันในตาราง fuel_prices และกำหนดถังใน fuel_tanks'
    ];
  }

  // Alerts (low stock)
  foreach ($fuels as $fuel_id => $fuel) {
    if ($fuel['current_stock'] <= $fuel['min_threshold']) {
      $alerts[] = ['type'=>'danger','icon'=>'fa-triangle-exclamation', 'message'=>"สต็อก{$fuel['name']}เหลือน้อย: ".number_format($fuel['current_stock'],0).' ลิตร'];
    } elseif ($fuel['current_stock'] <= ($fuel['min_threshold'] * 1.5)) {
      $alerts[] = ['type'=>'warning','icon'=>'fa-exclamation-triangle', 'message'=>"สต็อก{$fuel['name']}ใกล้หมด: ".number_format($fuel['current_stock'],0).' ลิตร'];
    }
  }

  // Alerts (maintenance due)
  if (table_exists($pdo,'pumps')){
    $stmt = $pdo->query("SELECT pump_name, last_maintenance FROM pumps WHERE last_maintenance < DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR last_maintenance IS NULL");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $alerts[] = ['type'=>'info','icon'=>'fa-wrench','message'=>"ปั๊ม {$row['pump_name']} ต้องการการบำรุงรักษา"];
    }
  }

  // Recent receives/transfers-in
  if (table_exists($pdo,'fuel_receives') && table_exists($pdo,'fuel_prices') && table_exists($pdo,'fuel_moves') && table_exists($pdo,'fuel_tanks')) {
    $stmt = $pdo->query("
      SELECT * FROM (
        SELECT fr.received_date AS occurred_at,
               'receive'        AS kind,
               fr.amount        AS amount,
               fr.cost          AS cost,
               fp.fuel_name     AS fuel_name,
               s.supplier_name  AS supplier_name,
               fr.notes         AS notes
        FROM fuel_receives fr
        JOIN fuel_prices fp ON fp.fuel_id = fr.fuel_id
        LEFT JOIN suppliers s ON s.supplier_id = fr.supplier_id

        UNION ALL

        SELECT fm.occurred_at AS occurred_at,
               'transfer_in'   AS kind,
               fm.liters       AS amount,
               NULL            AS cost,
               fp2.fuel_name   AS fuel_name,
               NULL            AS supplier_name,
               fm.ref_note     AS notes
        FROM fuel_moves fm
        JOIN fuel_tanks t ON t.id = fm.tank_id
        JOIN fuel_prices fp2 ON fp2.fuel_id = t.fuel_id
        WHERE fm.type='transfer_in'
      ) x
      ORDER BY occurred_at DESC
      LIMIT 5
    ");
    $recent_receives = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

} catch (Throwable $e) {
  $alerts[] = [ 'type' => 'danger', 'icon' => 'fa-server', 'message' => 'ไม่สามารถโหลดข้อมูลจากฐานข้อมูลได้: ' . $e->getMessage() ];
  error_log('Inventory page DB error: '.$e->getMessage());
  $pumps = [];
  $suppliers = [];
  $recent_receives = [];
}

// Totals for quick stats
$total_capacity = array_sum(array_map(fn($f)=> (float)($f['capacity'] ?? 0), $fuels));
$total_stock    = array_sum(array_map(fn($f)=> (float)($f['current_stock'] ?? 0), $fuels));
$available_cap  = max(0, $total_capacity - $total_stock);
$utilization    = $total_capacity > 0 ? ($total_stock / $total_capacity) * 100 : 0;

$role_th_map = [ 'admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ' ];
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
  .stock-card { border-left: 4px solid #6c757d; transition: all .3s ease; }
  .stock-card.low-stock { border-left-color: #dc3545; }
  .stock-card.medium-stock { border-left-color: #ffc107; }
  .stock-card.high-stock { border-left-color: #28a745; }
  .stock-progress { height: 8px; background-color: #e9ecef; border-radius: 4px; overflow: hidden; }
  .stock-progress-bar { height: 100%; transition: width .3s ease; }
  .stock-progress-bar.low { background: linear-gradient(90deg, #dc3545, #fd7e14); }
  .stock-progress-bar.medium { background: linear-gradient(90deg, #ffc107, #fd7e14); }
  .stock-progress-bar.high { background: linear-gradient(90deg, #28a745, #20c997); }
  .pump-status { padding: 4px 8px; border-radius: 12px; font-size: .8rem; font-weight: 500; }
  .pump-status.active { background: #d1edff; color: #0d6efd; }
  .pump-status.maintenance { background: #fff3cd; color: #664d03; }
  .pump-status.offline { background: #f8d7da; color: #721c24; }
  .alert-banner { border-radius: 8px; border: none; padding: 12px 16px; margin-bottom: 8px; }
  .fuel-price-card{border:1px solid #e9ecef; border-radius:12px; transition:.25s; background:#fff}
  .fuel-price-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.06); transform:translateY(-1px)}
  .fuel-price-actions{display:flex; gap:.5rem; margin-top:.75rem; flex-wrap:wrap}
  .badge-price{font-weight:600}
  .nav-tabs .nav-link { font-weight: 600; }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"><span class="navbar-toggler-icon"></span></button>
      <a class="navbar-brand fw-800" href="admin_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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

<!-- Offcanvas Sidebar -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
    <nav class="sidebar-menu">
      <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="inventory.php" class="active"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
      <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
      <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่าระบบ</a>
    </nav>
    <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php" class="active"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <!-- Main -->
    <main class="col-lg-10 p-4">
      <div class="main-header d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-fuel-pump-fill"></i> จัดการน้ำมัน</h2>
        <div class="d-flex gap-2"></div>
      </div>

      <!-- Alerts -->
      <div class="alerts-section mb-4" <?= empty($alerts) ? 'style="display:none"' : '' ?>>
        <?php foreach ($alerts as $alert): ?>
          <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> alert-banner">
            <i class="fa-solid <?= htmlspecialchars($alert['icon']) ?> me-2"></i><?= htmlspecialchars($alert['message']) ?>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Quick Stats -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="stat-card">
            <h6><i class="fa-solid fa-droplet me-2"></i>สต็อกรวม (ลิตร)</h6>
            <h3><?= number_format($total_stock, 0) ?></h3>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <h6><i class="fa-solid fa-inbox me-2"></i>พื้นที่ว่าง (ลิตร)</h6>
            <h3><?= number_format($available_cap, 0) ?></h3>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <h6><i class="fa-solid fa-percent me-2"></i>อัตราการใช้งาน</h6>
            <h3><?= number_format($utilization, 1) ?>%</h3>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <ul class="nav nav-tabs mb-3" id="inventoryTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stock-price-panel" type="button" role="tab"><i class="fa-solid fa-oil-can me-2"></i>แท็งก์น้ำมัน</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#price-panel" type="button" role="tab">
            <i class="fa-solid fa-tags me-2"></i>จัดการราคา
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <!-- (แพตช์ #3) เปลี่ยน target → #receive-panel -->
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#receive-panel" type="button" role="tab"><i class="fa-solid fa-gas-pump me-2"></i>รับน้ำมัน</button>
        </li>
      </ul>

      <div class="tab-content" id="inventoryTabContent">
        <!-- Tanks -->
        <div class="tab-pane fade show active" id="stock-price-panel" role="tabpanel">
          <div class="panel">
            <div class="panel-head"><h5><i class="fa-solid fa-oil-can me-2"></i>สถานะแท็งก์น้ำมัน</h5></div>
            <div class="row g-3">
              <?php foreach ($fuels as $id => $fuel):
                $pct = (float)$fuel['stock_percentage'];
                $stock_class = $pct <= 25 ? 'low' : ($pct <= 50 ? 'medium' : 'high');
                $stock_card_class = $pct <= 25 ? 'low-stock' : ($pct <= 50 ? 'medium-stock' : 'high-stock');
              ?>
              <div class="col-md-6 col-lg-4">
                <div class="card stock-card <?= $stock_card_class ?>" data-fuel="<?= htmlspecialchars((string)$id) ?>">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                      <h6 class="card-title mb-0"><?= htmlspecialchars($fuel['name']) ?></h6>
                      <span class="badge bg-primary badge-price" id="badge-price-<?= htmlspecialchars((string)$id) ?>">฿<?= number_format((float)$fuel['price'], 2, '.', ',') ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                      <span class="text-muted">สต็อกปัจจุบัน</span>
                      <strong class="stock-amount"><?= number_format((float)$fuel['current_stock'], 0) ?> ลิตร</strong>
                    </div>
                    <div class="stock-progress mb-2">
                      <div class="stock-progress-bar <?= $stock_class ?>" style="width: <?= $pct ?>%"></div>
                    </div>
                    <div class="row text-center">
                      <div class="col">
                        <small class="text-muted">ความจุ</small><br>
                        <small><strong><?= number_format((float)$fuel['capacity'], 0) ?></strong></small>
                      </div>
                      <div class="col">
                        <small class="text-muted">เปอร์เซ็นต์</small><br>
                        <small><strong><?= number_format($pct, 1) ?>%</strong></small>
                      </div>
                    </div>
                    <?php if (!empty($fuel['last_refill_date'])): ?>
                    <div class="mt-2 pt-2 border-top">
                      <small class="text-muted">
                        เติมล่าสุด: <?= date('d/m/Y', strtotime($fuel['last_refill_date'])) ?>
                        <?php if (!empty($fuel['last_refill_amount'])): ?>
                          (<?= number_format((float)$fuel['last_refill_amount'], 0) ?> ลิตร)
                        <?php endif; ?>
                      </small>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="d-flex gap-2 mt-3">
              <!-- ปุ่มเติม/รับน้ำมัน ถูกนำออกตามคำขอ -->
              <a class="btn btn-warning" href="report.php?type=fuel">
                <i class="fa-solid fa-chart-line me-1"></i> รายงานสต็อก
              </a>
            </div>
          </div>
        </div>

        <!-- Price Panel -->
        <div class="tab-pane fade" id="price-panel" role="tabpanel">
          <div class="panel">
            <div class="panel-head"><h5><i class="fa-solid fa-tags me-2"></i>จัดการราคาน้ำมัน</h5></div>
            <div class="alert alert-warning mb-3">
              <i class="fa-solid fa-triangle-exclamation me-1"></i>
              การเปลี่ยนแปลงราคาจะมีผลกับการขายครั้งถัดไป กรุณาตรวจสอบความถูกต้องก่อนบันทึก
            </div>

            <div class="row g-3">
              <?php foreach ($fuels as $id => $data): ?>
                <div class="col-md-6 col-lg-4">
                  <div class="fuel-price-card p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <label for="price_<?= htmlspecialchars((string)$id) ?>" class="form-label fw-bold m-0">
                        <i class="bi bi-fuel-pump-fill me-2"></i><?= htmlspecialchars($data['name']) ?>
                      </label>
                      <span class="text-muted small">ปัจจุบัน:
                        <strong id="current-price-<?= htmlspecialchars((string)$id) ?>">฿<?= number_format((float)$data['price'], 2, '.', ',') ?></strong>
                      </span>
                    </div>

                    <form class="fuel-price-form" data-fuel-id="<?= htmlspecialchars((string)$id) ?>" onsubmit="return false;">
                      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                      <input type="hidden" name="fuel_id" value="<?= htmlspecialchars((string)$id) ?>">
                      <div class="input-group">
                        <input type="number"
                               id="price_<?= htmlspecialchars((string)$id) ?>"
                               name="price"
                               class="form-control"
                               value="<?= htmlspecialchars(number_format((float)$data['price'], 2, '.', '')) ?>"
                               step="0.01" min="0" inputmode="decimal" required>
                        <span class="input-group-text">บาท/ลิตร</span>
                      </div>

                      <div class="fuel-price-actions">
                        <button type="button" class="btn btn-success btn-sm btn-save" data-fuel-id="<?= htmlspecialchars((string)$id) ?>">
                          <i class="fa-solid fa-floppy-disk me-1"></i> บันทึก
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm btn-reset"
                                data-default="<?= htmlspecialchars(number_format((float)$data['price'], 2, '.', '')) ?>"
                                data-input="#price_<?= htmlspecialchars((string)$id) ?>">
                          <i class="fa-solid fa-rotate-left me-1"></i> คืนค่าเดิม
                        </button>
                        <span class="ms-auto d-flex align-items-center gap-2" aria-live="polite">
                          <span class="save-status small text-muted"></span>
                          <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </span>
                      </div>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Receive (รับน้ำมันเข้าคลัง) -->
        <!-- (แพตช์ #3) เปลี่ยน id เป็น receive-panel -->
        <div class="tab-pane fade" id="receive-panel" role="tabpanel">
          <div id="receive" class="panel mb-4">
            <div class="panel-head"><h5>📥 รับน้ำมันเข้าคลัง </h5></div>
            <div class="settings-section">
              <form id="receiveForm" action="refill.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">ประเภทน้ำมัน</label>
                    <select name="fuel_id" class="form-select" required>
                      <option value="">เลือก</option>
                      <?php foreach($fuels as $fid => $f){ echo '<option value="'.htmlspecialchars((string)$fid).'">'.htmlspecialchars($f['name']).'</option>'; } ?>
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
                    <label class="form-label">ราคาต้นทุน</label>
                    <div class="input-group">
                      <input type="number" name="cost" class="form-control" step="0.01" min="0">
                      <span class="input-group-text">บาท/ลิตร</span>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">ซัพพลายเออร์</label>
                    <select name="supplier_id" class="form-select">
                      <option value="">ไม่ระบุ</option>
                      <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int)$supplier['supplier_id'] ?>"><?= htmlspecialchars($supplier['supplier_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12">
                    <label class="form-label">หมายเหตุ</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="เช่น ใบกำกับ/เที่ยวรถ/รอบจัดส่ง"></textarea>
                  </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                  <button type="submit" class="btn btn-success">💾 บันทึกการรับ</button>
                  <button type="reset" class="btn btn-secondary">🔄 ล้างฟอร์ม</button>
                </div>
              </form>
            </div>

            <div class="settings-section mt-4">
              <h6 class="mb-2">การรับ/โอนล่าสุด</h6>
              <div class="table-responsive">
                <table class="table table-sm table-hover">
                  <thead>
                    <tr>
                      <th>วันที่</th>
                      <th>ประเภท</th>
                      <th class="text-end">จำนวน</th>
                      <th class="text-end">ต้นทุน</th>
                      <th>ผู้จัดส่ง</th>
                      <th>หมายเหตุ</th>
                    </tr>
                  </thead>
                  <tbody id="recentReceiveTable">
                  <?php if (empty($recent_receives)): ?>
                    <tr><td colspan="6" class="text-center text-muted">ยังไม่มีข้อมูล</td></tr>
                  <?php else: foreach ($recent_receives as $rc): ?>
                    <tr>
                      <td><?= $rc['occurred_at'] ? htmlspecialchars(date('Y-m-d H:i:s', strtotime($rc['occurred_at']))) : '-' ?></td>
                      <td><?= htmlspecialchars($rc['fuel_name'] ?? '-') ?><?php if(($rc['kind'] ?? '')==='transfer_in') echo ' (โอนเข้าถัง)'; ?></td>
                      <td class="text-end"><?= number_format((float)($rc['amount'] ?? 0),2) ?></td>
                      <td class="text-end"><?= isset($rc['cost']) ? number_format((float)$rc['cost'],2) : '-' ?></td>
                      <td><?= htmlspecialchars($rc['supplier_name'] ?? '-') ?></td>
                      <td><?= htmlspecialchars($rc['notes'] ?? '-') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<footer class="footer">© <?= date('Y'); ?> <?= htmlspecialchars($site_name) ?></footer>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="toastBox" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">บันทึกสำเร็จ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const toastEl = document.getElementById('toastBox');
  const toastMsg = document.getElementById('toastMsg');
  let bsToast = toastEl ? new bootstrap.Toast(toastEl, { delay: 2200 }) : null;

  function showToast(message, ok=true){
    if (!toastEl) return alert(message);
    toastEl.classList.toggle('text-bg-success', ok);
    toastEl.classList.toggle('text-bg-danger', !ok);
    toastMsg.textContent = message;
    bsToast.show();
  }

  // messages from query string
  const urlParams = new URLSearchParams(window.location.search);
  const okMsg = urlParams.get('ok');
  const errMsg = urlParams.get('err');
  if (okMsg) { showToast(okMsg, true); window.history.replaceState({}, document.title, window.location.pathname); }
  if (errMsg) { showToast(errMsg, false); window.history.replaceState({}, document.title, window.location.pathname); }

  // reset price input to default
  document.querySelectorAll('.btn-reset').forEach(btn => {
    btn.addEventListener('click', () => {
      const selector = btn.getAttribute('data-input');
      const defVal   = btn.getAttribute('data-default');
      const input    = document.querySelector(selector);
      if (input) input.value = defVal;
    });
  });

  // save fuel price
  document.querySelectorAll('.btn-save').forEach(btn => {
    btn.addEventListener('click', async () => {
      const fuelId = btn.getAttribute('data-fuel-id');
      const form   = btn.closest('.fuel-price-form');
      if (!form) return;

      const input  = form.querySelector('input[name="price"]');
      const price  = parseFloat(input.value || '0');
      if (isNaN(price) || price < 0) { showToast('กรุณากรอกราคาให้ถูกต้อง', false); return; }

      const spinner = form.querySelector('.spinner-border');
      const status  = form.querySelector('.save-status');
      spinner.classList.remove('d-none');
      btn.disabled = true;

      try{
        const formData = new URLSearchParams();
        formData.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
        formData.append('fuel_id', fuelId);
        formData.append('price', price.toFixed(2));

        const res = await fetch('update_price.php', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData.toString()
        });
        const data = await res.json();

        if (data && data.ok){
          const badge = document.getElementById('badge-price-' + fuelId);
          if (badge) badge.textContent = '฿' + Number(data.new_price).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2});
          const cur   = document.getElementById('current-price-' + fuelId);
          if (cur)   cur.textContent   = '฿' + Number(data.new_price).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2});
          if (status) { status.textContent = 'บันทึกเมื่อ ' + new Date().toLocaleTimeString('th-TH'); }
          showToast('บันทึกราคา ' + (data.fuel_name || fuelId) + ' สำเร็จ');
        } else {
          showToast(data && data.error ? data.error : 'บันทึกล้มเหลว', false);
        }
      }catch(err){
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', false);
      }finally{
        spinner.classList.add('d-none');
        btn.disabled = false;
      }
    });
  });
});
</script>

</body>
</html>
