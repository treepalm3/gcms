<?php
// committee/committee_dashboard.php — unified, DB-safe, index-friendly
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ========== ตรวจการล็อกอินและสิทธิ์ ========== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
  exit();
}

/* ========== เชื่อมฐานข้อมูล ========== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}
try { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Throwable $e) {}

/* ========== ตรวจสิทธิ์บทบาท ========== */
try {
  $current_name = $_SESSION['full_name'] ?? 'กรรมการ';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'committee') {
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

/* ========== Helpers ========== */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = :db AND table_name = :tb
    ");
    $stmt->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = :db AND table_name = :tb AND column_name = :c
    ");
    $stmt->execute([':db'=>$db, ':tb'=>$table, ':c'=>$col]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
/** เลือกคอลัมน์แรกที่มีจริงจากรายการ $candidates */
function pick_col(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) { if (column_exists($pdo, $table, $c)) return $c; }
  return null;
}
function nf($n, $d=0){ return number_format((float)$n, $d); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ========== โหลดชื่อระบบจาก app_settings ========== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
try {
  // พยายามใช้ JSON_EXTRACT ก่อน
  $st = $pdo->query("
    SELECT
      JSON_UNQUOTE(JSON_EXTRACT(json_value,'$.site_name'))     AS site_name,
      JSON_UNQUOTE(JSON_EXTRACT(json_value,'$.site_subtitle')) AS site_subtitle
    FROM app_settings WHERE `key`='system_settings' LIMIT 1
  ");
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $site_name     = $r['site_name']     ?: $site_name;
    $site_subtitle = $r['site_subtitle'] ?: $site_subtitle;
  } else {
    // fallback
    $st = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $j = json_decode($r['json_value'] ?? '{}', true) ?: [];
      $site_name     = $j['site_name']     ?? $site_name;
      $site_subtitle = $j['site_subtitle'] ?? $site_subtitle;
    }
  }
} catch(Throwable $e){
  try {
    $st = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $j = json_decode($r['json_value'] ?? '{}', true) ?: [];
      $site_name     = $j['site_name']     ?? $site_name;
      $site_subtitle = $j['site_subtitle'] ?? $site_subtitle;
    }
  } catch (Throwable $e2) {}
}

/* ========== ข้อมูลส่วนหัว ========== */
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',  'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

/* ========== ตัวแปรสถานี (ถ้ามี) ========== */
$stationId = $_SESSION['station_id'] ?? 1;
$hasStationInSales = column_exists($pdo, 'sales', 'station_id');
$stationFilterSales = $hasStationInSales ? " AND s.station_id = :sid " : "";

/* ========== สถิติสมาชิก ========== */
$members_total = 0;
$members_new_month = 0;
if (table_exists($pdo, 'members')) {
  try {
    // กรองตาม station_id หากมีคอลัมน์
    $hasStationInMembers = column_exists($pdo,'members','station_id');
    $stationFilterMembers = $hasStationInMembers ? " WHERE station_id = :sid " : "";
    $st_total = $pdo->prepare("SELECT COUNT(*) FROM members" . $stationFilterMembers);
    $params = $hasStationInMembers ? [':sid'=>$stationId] : [];
    $st_total->execute($params);
    $members_total = (int)$st_total->fetchColumn();

    $joinCol = pick_col($pdo, 'members', ['joined_date','created_at','created_date']);
    if ($joinCol) {
      $sql_month = "
        SELECT COUNT(*) FROM members
        WHERE {$joinCol} >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND {$joinCol} <  DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
      ";
      if ($hasStationInMembers) { $sql_month .= " AND station_id = :sid"; }
      $st_month = $pdo->prepare($sql_month);
      $st_month->execute($params);
      $members_new_month = (int)$st_month->fetchColumn();
    }
  } catch(Throwable $e){}
}

/* ========== ตรวจตารางขาย/ไอเทมขาย และเลือกคอลัมน์ ========== */
$has_sales = table_exists($pdo,'sales');
$has_items = table_exists($pdo,'sales_items');

$saleDateCol = $has_sales ? pick_col($pdo, 'sales', ['sale_date','created_at','trans_date','date']) : null;
$saleIdCol   = $has_sales ? pick_col($pdo, 'sales', ['id','sale_id']) : null;
$itemJoinCol = $has_items ? pick_col($pdo, 'sales_items', ['sale_id','sales_id']) : null;
$itemAmount  = $has_items ? pick_col($pdo, 'sales_items', ['line_amount','amount','total_amount']) : null;
$itemLiters  = $has_items ? pick_col($pdo, 'sales_items', ['liters','quantity_liter','qty_liter','volume']) : null;
$itemFuel    = $has_items ? pick_col($pdo, 'sales_items', ['fuel_type','product','grade']) : null;

// fallback ถ้าไม่มี sales_items
$salesAmount = (!$has_items && $has_sales) ? pick_col($pdo, 'sales', ['total_amount','amount','grand_total']) : null;
$salesLiters = (!$has_items && $has_sales) ? pick_col($pdo, 'sales', ['total_liters','liters']) : null;

/* ========== ค่ากล่องสถิติ (คำนวณแบบ dynamic + index-friendly) ========== */
$today_revenue = $today_liters = $week_revenue = $week_liters = $week_orders = $growth_pct = null;

try {
  if ($has_sales && $saleDateCol) {
    $params = $hasStationInSales ? [':sid'=>$stationId] : [];

    // Today
    if ($has_items && $itemJoinCol && $saleIdCol && ($itemAmount || $itemLiters)) {
      $selAmt = $itemAmount ? "COALESCE(SUM(si.{$itemAmount}),0)" : "0";
      $selLit = $itemLiters ? "COALESCE(SUM(si.{$itemLiters}),0)" : "0";
      $sql = "
        SELECT {$selAmt} AS revenue_today, {$selLit} AS liters_today
        FROM sales s
        JOIN sales_items si ON si.{$itemJoinCol} = s.{$saleIdCol}
        WHERE s.{$saleDateCol} >= CURDATE()
          AND s.{$saleDateCol} <  CURDATE() + INTERVAL 1 DAY
        {$stationFilterSales}
      ";
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      $today_revenue = (float)($r['revenue_today'] ?? 0);
      $today_liters  = (float)($r['liters_today'] ?? 0);
    } elseif ($salesAmount || $salesLiters) {
      $selAmt = $salesAmount ? "COALESCE(SUM(s.{$salesAmount}),0)" : "0";
      $selLit = $salesLiters ? "COALESCE(SUM(s.{$salesLiters}),0)" : "0";
      $sql = "
        SELECT {$selAmt} AS revenue_today, {$selLit} AS liters_today
        FROM sales s
        WHERE s.{$saleDateCol} >= CURDATE()
          AND s.{$saleDateCol} <  CURDATE() + INTERVAL 1 DAY
        {$stationFilterSales}
      ";
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      $today_revenue = (float)($r['revenue_today'] ?? 0);
      $today_liters  = (float)($r['liters_today'] ?? 0);
    }

    // 7 วันล่าสุด
    if ($has_items && $itemJoinCol && $saleIdCol && ($itemAmount || $itemLiters)) {
      $selAmt = $itemAmount ? "COALESCE(SUM(si.{$itemAmount}),0)" : "0";
      $selLit = $itemLiters ? "COALESCE(SUM(si.{$itemLiters}),0)" : "0";
      $sql = "
        SELECT {$selAmt} AS rev7, {$selLit} AS lit7, COUNT(DISTINCT s.{$saleIdCol}) AS cnt7
        FROM sales s
        JOIN sales_items si ON si.{$itemJoinCol} = s.{$saleIdCol}
        WHERE s.{$saleDateCol} >= CURDATE() - INTERVAL 7 DAY
        {$stationFilterSales}
      ";
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      $week_revenue = (float)($r['rev7'] ?? 0);
      $week_liters  = (float)($r['lit7'] ?? 0);
      $week_orders  = (int)  ($r['cnt7'] ?? 0);

      $sqlPrev = "
        SELECT {$selAmt} AS rev_prev7
        FROM sales s
        JOIN sales_items si ON si.{$itemJoinCol} = s.{$saleIdCol}
        WHERE s.{$saleDateCol} >= CURDATE() - INTERVAL 14 DAY
          AND s.{$saleDateCol} <  CURDATE() - INTERVAL 7 DAY
        {$stationFilterSales}
      ";
      $st = $pdo->prepare($sqlPrev);
      $st->execute($params);
      $rev_prev7 = (float)$st->fetchColumn();
      $growth_pct = $rev_prev7 > 0 ? (($week_revenue - $rev_prev7) / $rev_prev7 * 100.0) : null;

    } else {
      $selAmt = $salesAmount ? "COALESCE(SUM(s.{$salesAmount}),0)" : "0";
      $selLit = $salesLiters ? "COALESCE(SUM(s.{$salesLiters}),0)" : "0";
      $sql = "
        SELECT {$selAmt} AS rev7, {$selLit} AS lit7, COUNT(*) AS cnt7
        FROM sales s
        WHERE s.{$saleDateCol} >= CURDATE() - INTERVAL 7 DAY
        {$stationFilterSales}
      ";
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      $week_revenue = (float)($r['rev7'] ?? 0);
      $week_liters  = (float)($r['lit7'] ?? 0);
      $week_orders  = (int)  ($r['cnt7'] ?? 0);

      $sqlPrev = "
        SELECT {$selAmt} AS rev_prev7
        FROM sales s
        WHERE s.{$saleDateCol} >= CURDATE() - INTERVAL 14 DAY
          AND s.{$saleDateCol} <  CURDATE() - INTERVAL 7 DAY
        {$stationFilterSales}
      ";
      $st = $pdo->prepare($sqlPrev);
      $st->execute($params);
      $rev_prev7 = (float)$st->fetchColumn();
      $growth_pct = $rev_prev7 > 0 ? (($week_revenue - $rev_prev7) / $rev_prev7 * 100.0) : null;
    }
  }
} catch (Throwable $e) {}

/* ========== ข้อมูลกราฟ ========== */
$bar_labels = []; $bar_values = [];
$pie_labels = []; $pie_values = [];

try {
  if ($has_sales && $saleDateCol) {
    $params = $hasStationInSales ? [':sid'=>$stationId] : [];

    // Bar: ลิตรต่อเดือน 6 เดือน
    if ($has_items && $itemLiters && $itemJoinCol && $saleIdCol) {
      $sql = "
        SELECT DATE_FORMAT(s.{$saleDateCol}, '%Y-%m') ym, COALESCE(SUM(si.{$itemLiters}),0) liters
        FROM sales s JOIN sales_items si ON si.{$itemJoinCol} = s.{$saleIdCol}
        WHERE s.{$saleDateCol} >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
        {$stationFilterSales}
        GROUP BY ym ORDER BY ym
      ";
    } elseif ($salesLiters) {
      $sql = "
        SELECT DATE_FORMAT(s.{$saleDateCol}, '%Y-%m') ym, COALESCE(SUM(s.{$salesLiters}),0) liters
        FROM sales s
        WHERE s.{$saleDateCol} >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
        {$stationFilterSales}
        GROUP BY ym ORDER BY ym
      ";
    } else {
      $sql = null;
    }
    if ($sql) {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $bar_labels[] = $r['ym'];
        $bar_values[] = (float)$r['liters'];
      }
    }

    // Pie: แบ่งตามชนิดเชื้อเพลิง (30 วัน)
    if ($has_items && $itemLiters && $itemFuel && $itemJoinCol && $saleIdCol) {
      $sql = "
        SELECT si.{$itemFuel} AS label, COALESCE(SUM(si.{$itemLiters}),0) liters
        FROM sales s JOIN sales_items si ON si.{$itemJoinCol} = s.{$saleIdCol}
        WHERE s.{$saleDateCol} >= CURDATE() - INTERVAL 30 DAY
        {$stationFilterSales}
        GROUP BY label ORDER BY liters DESC
      ";
      $st = $pdo->prepare($sql);
      $st->execute($params);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $pie_labels[] = $r['label'] ?? 'อื่น ๆ';
        $pie_values[] = (float)$r['liters'];
      }
    }
  } else {
    // Fallback: สมาชิกใหม่รายเดือน + โพสต์
    if (table_exists($pdo,'members')) {
      $joinCol = pick_col($pdo, 'members', ['joined_date','created_at','created_date']);
      if ($joinCol) {
        $hasStationInMembers = column_exists($pdo,'members','station_id');
        $sql = "
          SELECT DATE_FORMAT({$joinCol}, '%Y-%m') ym, COUNT(*) cnt
          FROM members
          WHERE {$joinCol} IS NOT NULL
            AND {$joinCol} >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
        ";
        $params = [];
        if ($hasStationInMembers) { $sql .= " AND station_id = :sid"; $params[':sid'] = $stationId; }
        $sql .= " GROUP BY ym ORDER BY ym";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $bar_labels[] = $r['ym'];
          $bar_values[] = (int)$r['cnt'];
        }
      }
    }
    if (table_exists($pdo,'posts')) {
      $typeCol   = pick_col($pdo,'posts',['type','category','post_type']);
      $statusCol = pick_col($pdo,'posts',['status','state','is_published']);
      if ($typeCol) {
        $where = $statusCol ? "WHERE ({$statusCol}='published' OR {$statusCol}=1)" : "";
        $sql = "
          SELECT {$typeCol} AS t, COUNT(*) cnt
          FROM posts
          {$where}
          GROUP BY {$typeCol}
          ORDER BY cnt DESC
        ";
        $st = $pdo->query($sql);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $pie_labels[] = $r['t'] ?? 'อื่น ๆ';
          $pie_values[] = (int)$r['cnt'];
        }
      }
    }
  }
} catch (Throwable $e) {}

/* ========== กำไรขั้นต้น 7 วัน (ถ้ามี view) ========== */
$gp7 = null;
if (table_exists($pdo,'v_sales_gross_profit')) {
  try {
    $sql = "
      SELECT COALESCE(SUM(gross_profit),0)
      FROM v_sales_gross_profit
      WHERE sale_date >= CURDATE() - INTERVAL 7 DAY
    ";
    // หมายเหตุ: view นี้ไม่มี station_id ถ้าต้องการกรอง ให้สร้าง view เพิ่มเติม
    $gp7 = (float)$pdo->query($sql)->fetchColumn();
  } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($site_name) ?> - แดชบอร์ดกรรมการ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
</head>

<body>
  <!-- Navbar -->
  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"
                aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="#"><?= h($site_name) ?></a>
      </div>
      <div class="d-flex align-items-center gap-3 ms-auto">
        <div class="nav-identity text-end d-none d-sm-block">
          <div class="nav-name"><?= h($current_name) ?></div>
          <div class="nav-sub"><?= h($current_role_th) ?></div>
        </div>
        <a href="profile.php" class="avatar-circle text-decoration-none"><?= h($avatar_text) ?></a>
      </div>
    </div>
  </nav>

  <!-- Offcanvas Sidebar -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= h($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu">
        <a href="committee_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <div class="col-md-3 col-lg-2 d-none d-md-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="committee_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
          <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
          <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
          <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </div>

      <!-- Content -->
      <main class="col-md-9 col-lg-10 p-4 fade-in">
        <div class="main-header">
          <h2><i class="fa-solid fa-border-all"></i> ภาพรวม</h2>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
          <div class="stat-card">
            <h5><i class="bi bi-currency-dollar"></i> รายได้วันนี้</h5>
            <h3 class="text-success"><?= ($today_revenue !== null) ? '฿'.nf($today_revenue,2) : '—' ?></h3>
            <p class="<?= ($today_revenue !== null) ? 'text-success' : 'text-muted' ?> mb-0">
              <?= ($today_revenue !== null) ? 'จากยอดขาย' : 'ยังไม่พบสคีมาที่ใช้คำนวณรายได้' ?>
            </p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-fuel-pump-fill"></i> ยอดขายน้ำมัน (วันนี้)</h5>
            <h3 class="text-primary"><?= ($today_liters !== null) ? nf($today_liters,2).' ลิตร' : '—' ?></h3>
            <p class="<?= ($today_liters !== null) ? 'text-success' : 'text-muted' ?> mb-0">
              <?= ($today_liters !== null) ? 'แบบ real-time' : 'เปิดใช้เมื่อเจอคอลัมน์ liters' ?>
            </p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-people-fill"></i> สมาชิกทั้งหมด</h5>
            <h3 class="text-info"><?= nf($members_total) ?> คน</h3>
            <p class="text-muted mb-0">สมาชิกใหม่เดือนนี้: <?= nf($members_new_month) ?> คน</p>
          </div>
          <?php if ($gp7 !== null): ?>
          <div class="stat-card">
            <h5><i class="bi bi-graph-up-arrow"></i> กำไรขั้นต้น (7 วัน)</h5>
            <h3 class="text-warning"><?= '฿'.nf($gp7,2) ?></h3>
            <p class="text-muted mb-0"></p>
          </div>
          <?php endif; ?>
        </div>

        <!-- Weekly Summary -->
        <div class="row mt-5">
          <div class="col-12">
            <div class="stat-card">
              <h5><i class="bi bi-graph-up"></i> สรุปประจำสัปดาห์</h5>
              <div class="row text-center">
                <div class="col-md-3">
                  <h6 class="text-muted">รายได้รวม (7 วัน)</h6>
                  <h4 class="text-success"><?= ($week_revenue !== null) ? '฿'.nf($week_revenue,2) : '—' ?></h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">น้ำมันขาย (7 วัน)</h6>
                  <h4 class="text-primary"><?= ($week_liters !== null) ? nf($week_liters,2).' ลิตร' : '—' ?></h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">บิลเฉลี่ย/วัน</h6>
                  <h4 class="text-info"><?= ($week_orders !== null && $week_orders>=0) ? nf($week_orders/7.0,0).' บิล' : '—' ?></h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">% เติบโต</h6>
                  <h4 class="text-warning">
                    <?= ($growth_pct !== null) ? ((($growth_pct>=0?'+':'')) . nf($growth_pct,1) . '%') : '—' ?>
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
              <h5 class="mb-2"><i class="bi bi-bar-chart"></i> <?= ($has_sales && $saleDateCol) ? 'กราฟขายน้ำมัน (6 เดือน)' : 'สมาชิกใหม่รายเดือน (6 เดือน)' ?></h5>
              <div class="chart-wrap"><canvas id="barChart"></canvas></div>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="stat-card h-100">
              <h5 class="mb-2"><i class="bi bi-pie-chart"></i> <?= ($has_sales && $saleDateCol) ? 'สัดส่วนลิตรตามชนิดเชื้อเพลิง (30 วัน)' : 'สัดส่วนโพสต์ตามประเภท' ?></h5>
              <div class="chart-wrap"><canvas id="pieChart"></canvas></div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= h($site_name) ?> - <?= h($site_subtitle) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
        scales: { y: { beginAtZero: true } }
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
        plugins: { legend: { position: 'bottom' } }
      }
    });
  </script>
</body>
</html>
