<?php
// admin_dashboard.php — Dashboard สำหรับผู้ดูแลระบบ (อัปเดตให้เข้ากับสคีมาจริง)
// โหมดข้อมูล:
//   A) มี sales + sales_items  -> ได้ทั้งรายได้และลิตรตามชนิดเชื้อเพลิง
//   B) ไม่มี sales_items แต่มี sales + fuel_moves -> รายได้จาก sales, ลิตรจาก fuel_moves (sale_out)
//   C) ไม่มี sales -> แสดงข้อมูลสมาชิก/โพสต์แทน
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
  exit();
}

/* ===== เชื่อมฐานข้อมูล ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}

// ตรวจสิทธิ์
try {
  $current_name = $_SESSION['full_name'] ?: 'ผู้ดูแลระบบ';
  $current_role = $_SESSION['role'] ?? '';
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

/* ===== Helper ===== */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb");
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:col");
    $st->execute([':db'=>$db, ':tb'=>$table, ':col'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function nf($n,$d=0){ return number_format((float)$n,$d); }

/* ===== โหลด system settings (app_settings → settings) ===== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
try {
  if (table_exists($pdo,'app_settings') && column_exists($pdo,'app_settings','json_value')) {
    $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
    $st->execute();
    if ($json = $st->fetchColumn()) {
      $sys = json_decode($json,true);
      if (is_array($sys)) {
        if (!empty($sys['site_name']))     $site_name = $sys['site_name'];
        if (!empty($sys['site_subtitle'])) $site_subtitle = $sys['site_subtitle'];
        if (!empty($sys['timezone']))      @date_default_timezone_set($sys['timezone']);
      }
    }
  }
  // fallback: settings (id, setting_name, setting_value, comment)
  if (table_exists($pdo,'settings')) {
    $st = $pdo->prepare("SELECT comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($c = $st->fetchColumn()) { $site_name = $c ?: $site_name; }
  }
} catch (Throwable $e) {}

/* ===== สถิติสมาชิก ===== */
$members_total = 0;
$members_new_month = 0;
try {
  if (table_exists($pdo,'members')) {
    $members_total = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
    $members_new_month = (int)$pdo->query("
      SELECT COUNT(*) FROM members
      WHERE joined_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        AND joined_date <  DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
    ")->fetchColumn();
  }
} catch (Throwable $e) {}

$role_th_map = ['admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

/* ===== ตรวจสิทธิ์ข้อมูลที่มี ===== */
$has_sales       = table_exists($pdo,'sales');
$has_sales_items = table_exists($pdo,'sales_items');
$has_moves       = table_exists($pdo,'fuel_moves');

/* ===== วันนี้ ===== */
$today_revenue = null;   // บาท
$today_liters  = null;   // ลิตร

try {
  if ($has_sales && $has_sales_items) {
    // ยอด/ลิตร วันนี้ จาก sales_items
    $st = $pdo->query("
      SELECT COALESCE(SUM(si.line_amount),0) AS rev, COALESCE(SUM(si.liters),0) AS lit
      FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      WHERE DATE(s.sale_date) = CURDATE()
    ");
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $today_revenue = (float)$r['rev'];
    $today_liters  = (float)$r['lit'];

  } elseif ($has_sales) {
    // มีแต่ sales → รายได้วันนี้จาก sales
    $today_revenue = (float)$pdo->query("
      SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date)=CURDATE()
    ")->fetchColumn();
    // ลิตรวันนี้ fallback จาก fuel_moves (ถ้ามี)
    if ($has_moves) {
      $today_liters = (float)$pdo->query("
        SELECT COALESCE(SUM(liters),0) FROM fuel_moves
        WHERE type='sale_out' AND DATE(occurred_at)=CURDATE()
      ")->fetchColumn();
    }
  } elseif ($has_moves) {
    // ไม่มี sales แต่มี fuel_moves (ระบบ POS ยังไม่เชื่อม) → แสดงลิตรได้อย่างน้อย
    $today_liters = (float)$pdo->query("
      SELECT COALESCE(SUM(liters),0) FROM fuel_moves
      WHERE type='sale_out' AND DATE(occurred_at)=CURDATE()
    ")->fetchColumn();
  }
} catch (Throwable $e) {}

/* ===== 7 วันล่าสุด / Growth ===== */
$week_revenue = null;   // บาท
$week_liters  = null;   // ลิตร
$week_orders  = null;   // จำนวนบิล
$growth_pct   = null;   // % เติบโต (รายได้)

try {
  if ($has_sales && $has_sales_items) {
    $r = $pdo->query("
      SELECT COALESCE(SUM(si.line_amount),0) AS rev7,
             COALESCE(SUM(si.liters),0)      AS lit7,
             COUNT(DISTINCT s.id)            AS cnt7
      FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ")->fetch(PDO::FETCH_ASSOC);
    $week_revenue = (float)$r['rev7'];
    $week_liters  = (float)$r['lit7'];
    $week_orders  = (int)$r['cnt7'];

    $rev_prev7 = (float)$pdo->query("
      SELECT COALESCE(SUM(si.line_amount),0) FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      WHERE s.sale_date <  DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    ")->fetchColumn();
    $growth_pct = $rev_prev7 > 0 ? (($week_revenue - $rev_prev7) / $rev_prev7 * 100.0) : null;

  } elseif ($has_sales) {
    $r = $pdo->query("
      SELECT COALESCE(SUM(total_amount),0) AS rev7, COUNT(*) AS cnt7
      FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ")->fetch(PDO::FETCH_ASSOC);
    $week_revenue = (float)$r['rev7'];
    $week_orders  = (int)$r['cnt7'];

    if ($has_moves) {
      $week_liters = (float)$pdo->query("
        SELECT COALESCE(SUM(liters),0) FROM fuel_moves
        WHERE type='sale_out' AND occurred_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      ")->fetchColumn();
    }

    $rev_prev7 = (float)$pdo->query("
      SELECT COALESCE(SUM(total_amount),0)
      FROM sales
      WHERE sale_date <  DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    ")->fetchColumn();
    $growth_pct = $rev_prev7 > 0 ? (($week_revenue - $rev_prev7) / $rev_prev7 * 100.0) : null;

  } elseif ($has_moves) {
    $week_liters = (float)$pdo->query("
      SELECT COALESCE(SUM(liters),0) FROM fuel_moves
      WHERE type='sale_out' AND occurred_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ")->fetchColumn();
  }
} catch (Throwable $e) {}

/* ===== ข้อมูลกราฟ ===== */
$bar_labels = []; $bar_values = [];
$pie_labels = []; $pie_values = [];

try {
  if ($has_sales && $has_sales_items) {
    // แท่ง: ลิตร/เดือน 6 เดือน
    $q = $pdo->query("
      SELECT DATE_FORMAT(s.sale_date, '%Y-%m') ym, COALESCE(SUM(si.liters),0) liters
      FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      WHERE s.sale_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
      GROUP BY ym ORDER BY ym
    ");
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $bar_labels[]=$r['ym']; $bar_values[]=(float)$r['liters']; }

    // โดนัท: ลิตร 30 วัน แยกชนิด
    $q = $pdo->query("
      SELECT si.fuel_type AS name, COALESCE(SUM(si.liters),0) liters
      FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      GROUP BY si.fuel_type ORDER BY liters DESC
    ");
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $pie_labels[]=$r['name']; $pie_values[]=(float)$r['liters']; }

  } elseif ($has_moves && $has_sales) {
    // ไม่มี sales_items → ใช้ liters จาก fuel_moves และ revenue จาก sales
    // แท่ง: ลิตร/เดือน 6 เดือนจาก fuel_moves
    $q = $pdo->query("
      SELECT DATE_FORMAT(fm.occurred_at, '%Y-%m') ym, COALESCE(SUM(fm.liters),0) liters
      FROM fuel_moves fm
      WHERE fm.type='sale_out'
        AND fm.occurred_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
      GROUP BY ym ORDER BY ym
    ");
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $bar_labels[]=$r['ym']; $bar_values[]=(float)$r['liters']; }

    // โดนัท: ลิตร 30 วัน แยกชนิด จาก fuel_moves→tanks→fuel_prices
    $q = $pdo->query("
      SELECT fp.fuel_name AS name, COALESCE(SUM(fm.liters),0) liters
      FROM fuel_moves fm
      JOIN fuel_tanks t ON t.id = fm.tank_id
      JOIN fuel_prices fp ON fp.fuel_id = t.fuel_id
      WHERE fm.type='sale_out' AND fm.occurred_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      GROUP BY fp.fuel_name
      ORDER BY liters DESC
    ");
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $pie_labels[]=$r['name']; $pie_values[]=(float)$r['liters']; }

  } elseif ($has_sales) {
    // มีแต่ sales → แท่ง: รายได้/เดือน, โดนัท: สัดส่วนโพสต์ (ถ้ามี)
    $q = $pdo->query("
      SELECT DATE_FORMAT(sale_date, '%Y-%m') ym, COALESCE(SUM(total_amount),0) rev
      FROM sales
      WHERE sale_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
      GROUP BY ym ORDER BY ym
    ");
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $bar_labels[]=$r['ym']; $bar_values[]=(float)$r['rev']; }

    if (table_exists($pdo,'posts')) {
      $q = $pdo->query("SELECT type, COUNT(*) cnt FROM posts WHERE status='published' GROUP BY type ORDER BY cnt DESC");
      while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $pie_labels[]=$r['type']; $pie_values[]=(int)$r['cnt']; }
    }

  } else {
    // ไม่มียอดขาย → ใช้สมาชิก/โพสต์
    if (table_exists($pdo,'members')) {
      $q = $pdo->query("
        SELECT DATE_FORMAT(joined_date, '%Y-%m') ym, COUNT(*) cnt
        FROM members
        WHERE joined_date IS NOT NULL
          AND joined_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
        GROUP BY ym ORDER BY ym
      ");
      while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $bar_labels[]=$r['ym']; $bar_values[]=(int)$r['cnt']; }
    }
    if (table_exists($pdo,'posts')) {
      $q = $pdo->query("SELECT type, COUNT(*) cnt FROM posts WHERE status='published' GROUP BY type ORDER BY cnt DESC");
      while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $pie_labels[]=$r['type']; $pie_values[]=(int)$r['cnt']; }
    }
  }
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($site_name) ?> - แดชบอร์ด</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
</head>

<body>
  <!-- Navbar -->
  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="#"><?= htmlspecialchars($site_name) ?></a>
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
  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu">
        <a href="admin_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <div class="col-md-3 col-lg-2 d-none d-md-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="admin_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
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
      </div>

      <!-- Content -->
      <main class="col-md-9 col-lg-10 p-4 fade-in">
        <div class="main-header"><h2><i class="fa-solid fa-border-all"></i> ภาพรวม</h2></div>

        <!-- Stats -->
        <div class="stats-grid">
          <div class="stat-card">
            <h5><i class="bi bi-currency-dollar"></i> รายได้วันนี้</h5>
            <h3 class="text-success"><?= $has_sales ? '฿'.nf($today_revenue ?? 0,2) : '—' ?></h3>
            <p class="<?= $has_sales ? 'text-success' : 'text-muted' ?> mb-0">
              <?= $has_sales ? 'จากตารางยอดขาย' : 'ยังไม่พบตารางยอดขาย (sales)' ?>
            </p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-fuel-pump-fill"></i> ยอดขายน้ำมัน (วันนี้)</h5>
            <h3 class="text-primary">
              <?= $today_liters !== null ? nf($today_liters,2).' ลิตร' : '—' ?>
            </h3>
            <p class="text-muted mb-0">
              <?= ($has_sales_items ? 'จาก sales_items' : ($has_moves ? 'คำนวณจาก fuel_moves (sale_out)' : 'รอเชื่อมข้อมูลการขาย')) ?>
            </p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-people-fill"></i> สมาชิกทั้งหมด</h5>
            <h3 class="text-info"><?= nf($members_total) ?> คน</h3>
            <p class="text-muted mb-0">สมาชิกใหม่เดือนนี้: <?= nf($members_new_month) ?> คน</p>
          </div>
        </div>

        <!-- Weekly Summary -->
        <div class="row mt-5">
          <div class="col-12">
            <div class="stat-card">
              <h5><i class="bi bi-graph-up"></i> สรุปประจำสัปดาห์</h5>
              <div class="row text-center">
                <div class="col-md-3">
                  <h6 class="text-muted">รายได้รวม (7 วัน)</h6>
                  <h4 class="text-success"><?= $has_sales ? '฿'.nf($week_revenue ?? 0,2) : '—' ?></h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">น้ำมันขาย (7 วัน)</h6>
                  <h4 class="text-primary"><?= $week_liters !== null ? nf($week_liters,2).' ลิตร' : '—' ?></h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">บิลเฉลี่ย/วัน</h6>
                  <h4 class="text-info"><?= $has_sales && $week_orders!==null ? nf(($week_orders/7.0),0).' บิล' : '—' ?></h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">% เติบโต</h6>
                  <h4 class="text-warning">
                    <?= ($has_sales && $growth_pct!==null) ? (($growth_pct>=0?'+':'').nf($growth_pct,1).'%') : '—' ?>
                  </h4>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts -->
        <div class="row g-4 mt-4 row-charts">
          <div class="col-12 col-lg-6">
            <div class="stat-card h-100">
              <h5 class="mb-2">
                <i class="bi bi-bar-chart"></i>
                <?php
                  if ($has_sales_items) echo 'กราฟขายน้ำมัน (ลิตร/เดือน — 6 เดือน)';
                  elseif ($has_moves && $has_sales) echo 'ลิตรขายต่อเดือน (6 เดือน — จาก fuel_moves)';
                  elseif ($has_sales) echo 'รายได้ต่อเดือน (6 เดือน)';
                  else echo 'สมาชิกใหม่รายเดือน (6 เดือน)';
                ?>
              </h5>
              <div class="chart-wrap"><canvas id="barChart"></canvas></div>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="stat-card h-100">
              <h5 class="mb-2">
                <i class="bi bi-pie-chart"></i>
                <?php
                  if ($has_sales_items) echo 'สัดส่วนลิตรตามชนิดเชื้อเพลิง (30 วัน)';
                  elseif ($has_moves && $has_sales) echo 'สัดส่วนลิตรตามชนิด (30 วัน — fuel_moves)';
                  else echo 'สัดส่วนโพสต์ตามประเภท';
                ?>
              </h5>
              <div class="chart-wrap"><canvas id="pieChart"></canvas></div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> - <?= htmlspecialchars($site_subtitle) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    const barLabels = <?= json_encode($bar_labels, JSON_UNESCAPED_UNICODE) ?>;
    const barValues = <?= json_encode($bar_values, JSON_UNESCAPED_UNICODE) ?>;
    const pieLabels = <?= json_encode($pie_labels, JSON_UNESCAPED_UNICODE) ?>;
    const pieValues = <?= json_encode($pie_values, JSON_UNESCAPED_UNICODE) ?>;

    // Bar
    new Chart(document.getElementById('barChart').getContext('2d'), {
      type: 'bar',
      data: {
        labels: barLabels,
        datasets: [{
          label: 'จำนวน',
          data: barValues,
          backgroundColor: '#20A39E',
          borderColor: '#36535E',
          borderWidth: 2,
          borderRadius: 6,
          maxBarThickness: 34
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#36535E' } } },
        scales: {
          x: { ticks: { color: '#68727A' }, grid: { color: '#E9E1D3' } },
          y: { ticks: { color: '#68727A' }, grid: { color: '#E9E1D3' }, beginAtZero: true }
        }
      }
    });

    // Pie/Doughnut
    new Chart(document.getElementById('pieChart').getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: pieLabels,
        datasets: [{
          data: pieValues,
          backgroundColor: ['#CCA43B','#20A39E','#B66D0D','#513F32','#212845','#A1C181','#E56B6F','#6D597A'],
          borderColor: '#ffffff',
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { color: '#36535E' } } }
      }
    });

    // นาฬิกาเล็ก ๆ (ถ้าจะใช้ในอนาคต)
    function updateTime() {
      const now = new Date();
      const opts = { dateStyle: 'medium', timeStyle: 'short' };
      const nowStr = now.toLocaleString('th-TH', opts);
      const el1 = document.getElementById('nowText');
      const el2 = document.getElementById('nowTextMobile');
      if (el1) el1.textContent = nowStr;
      if (el2) el2.textContent = nowStr;
    }
    setInterval(updateTime, 1000);
    updateTime();
  </script>
</body>
</html>
