<?php
// employee/employee_dashboard.php — แดชบอร์ด "พนักงานปั๊ม"
session_start();
date_default_timezone_set('Asia/Bangkok');

// บังคับล็อกอินก่อนใช้งาน
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php');
    exit();
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- การเชื่อมต่อฐานข้อมูล ---
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) {
    $dbFile = __DIR__ . '/config/db.php';
}
require_once $dbFile; // expect $pdo (PDO)

// ============ helpers ============
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :tb');
    $stmt->execute([':db' => $db, ':tb' => $table]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :db AND table_name = :tb AND column_name = :col');
    $stmt->execute([':db' => $db, ':tb' => $table, ':col' => $col]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function nf($n, $d=0) { return number_format((float)$n, $d, '.', ','); }

// ============ ค่าพื้นฐาน/ผู้ใช้ ============
$site_name     = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';

// ดึงชื่อไซต์จาก app_settings (JSON) หากมี
try {
  if (table_exists($pdo, 'app_settings')) {
    $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
    $st->execute();
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $sys = json_decode($r['json_value'] ?? '', true) ?: [];
      $site_name     = $sys['site_name']     ?? $site_name;
      $site_subtitle = $sys['site_subtitle'] ?? $site_subtitle;
      if (!empty($sys['timezone'])) @date_default_timezone_set($sys['timezone']);
    }
  }
} catch (Throwable $e) {}

$current_user_id = $_SESSION['user_id'];
$current_name = 'พนักงาน';
$current_role = 'employee';

try {
  $current_name = $_SESSION['full_name'] ?: 'พนักงาน';
  $current_role = $_SESSION['role'];
  if ($current_role !== 'employee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
  exit();
}

$role_th_map = [
  'admin' => 'ผู้ดูแลระบบ', 'manager' => 'ผู้บริหาร',
  'employee' => 'พนักงาน', 'member' => 'สมาชิกสหกรณ์',
  'committee' => 'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// วิธีชำระเงิน -> badge map
$payment_map = [
  'cash'     => ['label' => 'เงินสด',      'class' => 'badge bg-success-subtle text-success'],
  'qr'       => ['label' => 'QR Code',     'class' => 'badge bg-info-subtle text-info'],
  'transfer' => ['label' => 'โอนเงิน',     'class' => 'badge bg-primary-subtle text-primary'],
  'card'     => ['label' => 'บัตรเครดิต',  'class' => 'badge bg-warning-subtle text-warning'],
];

// ============ เตรียมตัวแปรแสดงผล ============
$todaySalesAmount = 0.0;
$todayLiters = 0.0;
$todayBills = 0;
$avgPerBill = 0.0;
$recent = [];

$hourly_chart_data = ['labels' => [], 'data' => []];
$fuel_mix_chart_data = ['labels' => [], 'data' => []];
$fuel_inventory = [];
$system_status = [];

try {
  // ---------- ตรวจสคีมาที่อาจต่าง ----------
  $hasUserIdOnSales          = column_exists($pdo, 'sales', 'user_id');           // บางสคีมาไม่มี
  $hasLineAmount             = column_exists($pdo, 'sales_items', 'line_amount'); // มีใน dump ที่ให้มา
  $hasNetAmountOnSale        = column_exists($pdo, 'sales', 'net_amount');        // มีใน dump ที่ให้มา
  $hasPaymentMethodOnSales   = column_exists($pdo, 'sales', 'payment_method');    // มีใน dump ที่ให้มา

  // ---------- ระบุสถานี ----------
  $station_id = 1;
  try {
    if (table_exists($pdo, 'settings')) {
      $stSid = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
      $stSid->execute();
      $station_id = (int)($stSid->fetchColumn() ?: 1);
    }
  } catch (Throwable $e) {}

  // ---------- สร้าง WHERE แบบใช้ดัชนี + ตามสถานี ----------
  $where  = "s.sale_date >= CURDATE() AND s.sale_date < (CURDATE() + INTERVAL 1 DAY) AND s.station_id = :sid";
  $params = [':sid' => $station_id];
  if ($hasUserIdOnSales) {
    $where .= " AND s.user_id = :uid";
    $params[':uid'] = $current_user_id;
  }

  // ==========================================================
  // 1) สรุปยอดขายวันนี้ (ใช้ยอดสุทธิจริง)
  // ==========================================================
  if (table_exists($pdo, 'sales') && table_exists($pdo, 'sales_items')) {
    $amtExpr = $hasLineAmount ? "si.line_amount" : "(si.liters * si.price_per_liter)";
    $selNet  = $hasNetAmountOnSale ? "s.net_amount" : $amtExpr;

    $sql_summary = "
      SELECT
          SUM($selNet)         AS total_sales,
          SUM(si.liters)       AS total_liters,
          COUNT(DISTINCT s.id) AS total_bills
      FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      WHERE $where
    ";
    $stmt_summary = $pdo->prepare($sql_summary);
    $stmt_summary->execute($params);
    $summary = $stmt_summary->fetch(PDO::FETCH_ASSOC) ?: [];

    $todaySalesAmount = (float)($summary['total_sales'] ?? 0);
    $todayLiters      = (float)($summary['total_liters'] ?? 0);
    $todayBills       = (int)($summary['total_bills'] ?? 0);
    $avgPerBill       = $todayBills > 0 ? $todaySalesAmount / $todayBills : 0.0;
  }

  // ==========================================================
  // 2) รายการล่าสุด 6 บิล (รวมข้อมูลสมาชิกและแต้ม)
  // ==========================================================
  if (table_exists($pdo, 'sales') && table_exists($pdo, 'sales_items')) {
    $amtExpr = $hasLineAmount ? "si.line_amount" : "(si.liters * si.price_per_liter)";
    $selNet  = $hasNetAmountOnSale ? "s.net_amount" : $amtExpr;
    $selPay  = $hasPaymentMethodOnSales ? "s.payment_method" : "NULL";
    
    // ตรวจสอบว่ามีคอลัมน์ customer_phone และ household_no หรือไม่
    $hasCustomerPhone = column_exists($pdo, 'sales', 'customer_phone');
    $hasHouseholdNo = column_exists($pdo, 'sales', 'household_no');
    
    $selPhone = $hasCustomerPhone ? "s.customer_phone" : "NULL";
    $selHouse = $hasHouseholdNo ? "s.household_no" : "NULL";

    $sql_recent = "
      SELECT
          s.id AS sale_id,
          s.sale_date,
          s.sale_code,
          s.total_amount,
          $selNet AS net_amount,
          $selPay AS payment_method,
          $selPhone AS customer_phone,
          $selHouse AS household_no,
          si.liters,
          si.price_per_liter,
          COALESCE(fp.fuel_name, si.fuel_type) AS fuel_name,
          u.full_name AS member_name,
          m.id AS member_id,
          (
              SELECT COALESCE(SUM(sc.score), 0)
              FROM scores sc
              WHERE sc.member_id = m.id
                AND sc.activity LIKE CONCAT('%', s.sale_code, '%')
          ) AS points_earned
      FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      LEFT JOIN fuel_prices fp ON fp.fuel_name = si.fuel_type
      LEFT JOIN users u ON (
          ($selPhone IS NOT NULL AND $selPhone <> '' 
          AND REPLACE(REPLACE(REPLACE(REPLACE(u.phone, '-', ''), ' ', ''), '(', ''), ')', '') = REPLACE(REPLACE(REPLACE(REPLACE($selPhone, '-', ''), ' ', ''), '(', ''), ')', ''))
          OR ($selHouse IS NOT NULL AND $selHouse <> '' AND EXISTS (
              SELECT 1 FROM members m2 WHERE m2.user_id = u.id AND m2.house_number = $selHouse
          ))
      )
      LEFT JOIN members m ON m.user_id = u.id AND m.is_active = 1
      WHERE $where
      ORDER BY s.sale_date DESC, s.id DESC
      LIMIT 6
    ";
    $stmt_recent = $pdo->prepare($sql_recent);
    $stmt_recent->execute($params);
    $rows = $stmt_recent->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
      $total_amount     = (float)($row['total_amount'] ?? 0);
      $net_amount       = (float)($row['net_amount'] ?? 0);
      $discount_amount  = max(0, round($total_amount - $net_amount, 2));
      $discount_percent = $total_amount > 0 ? round(($discount_amount / $total_amount) * 100, 2) : 0.0;
      
      // ดึงข้อมูลสมาชิกและแต้ม
      $customer_phone = $row['customer_phone'] ?? '';
      $household_no   = $row['household_no'] ?? '';
      $member_name    = $row['member_name'] ?? '';
      $points_earned  = (int)($row['points_earned'] ?? 0);

      $recent[] = [
        'time'       => date('H:i', strtotime($row['sale_date'])),
        'fuel'       => $row['fuel_name'] ?? '-',
        'fuel_id'    => null,
        'liters'     => (float)($row['liters'] ?? 0),
        'net'        => $net_amount,
        'pay'        => $row['payment_method'] ?? '-',
        'phone'      => $customer_phone,
        'house_no'   => $household_no,
        'member_name'=> $member_name,
        'points'     => $points_earned,
        'receipt_no' => $row['sale_code'] ?? 'N/A',
        'receipt'    => false,
        'sale_data_json' => json_encode([
          'site_name'        => $site_name,
          'receipt_no'       => $row['sale_code'] ?? 'N/A',
          'datetime'         => $row['sale_date'],
          'fuel_name'        => $row['fuel_name'] ?? '-',
          'price_per_liter'  => (float)($row['price_per_liter'] ?? 0),
          'liters'           => (float)($row['liters'] ?? 0),
          'total_amount'     => $total_amount,
          'discount_percent' => $discount_percent,
          'discount_amount'  => $discount_amount,
          'net_amount'       => $net_amount,
          'payment_method'   => $row['payment_method'] ?? null,
          'customer_phone'   => '',
          'household_no'     => '',
          'points_earned'    => 0,
          'employee_name'    => $current_name,
        ], JSON_UNESCAPED_UNICODE),
      ];
    }
  }

  // ==========================================================
  // 3) กราฟรายชั่วโมง (ลิตร)
  // ==========================================================
  if (table_exists($pdo, 'sales') && table_exists($pdo, 'sales_items')) {
    $sql_hourly = "
      SELECT HOUR(s.sale_date) AS hh, SUM(si.liters) AS total_liters
      FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      WHERE $where
      GROUP BY HOUR(s.sale_date)
      ORDER BY hh ASC
    ";
    $stmt_hourly = $pdo->prepare($sql_hourly);
    $stmt_hourly->execute($params);
    $hourly = $stmt_hourly->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $hours_labels = range(6, 22); // 06:00 - 22:00
    $hourly_liters = [];
    foreach ($hours_labels as $h) { $hourly_liters[] = (float)($hourly[$h] ?? 0); }

    $hourly_chart_data = [
      'labels' => array_map(fn($h)=>str_pad($h,2,'0',STR_PAD_LEFT).":00", $hours_labels),
      'data'   => $hourly_liters
    ];
  }

  // ==========================================================
  // 4) โดนัทสัดส่วนชนิดน้ำมัน
  // ==========================================================
  if (table_exists($pdo, 'sales') && table_exists($pdo, 'sales_items')) {
    $sql_mix = "
      SELECT COALESCE(fp.fuel_name, si.fuel_type) AS name, SUM(si.liters) AS total_liters
      FROM sales s
      JOIN sales_items si ON si.sale_id = s.id
      LEFT JOIN fuel_prices fp ON fp.fuel_name = si.fuel_type
      WHERE $where
      GROUP BY name
      ORDER BY total_liters DESC
    ";
    $stmt_mix = $pdo->prepare($sql_mix);
    $stmt_mix->execute($params);
    $mix = $stmt_mix->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $fuel_mix_chart_data = [
      'labels' => array_column($mix, 'name'),
      'data'   => array_map('floatval', array_column($mix, 'total_liters'))
    ];
  }

  // ==========================================================
  // 5) สถานะสินค้าคงคลัง (ถังจริง -> fallback fuel_stock)
  // ==========================================================
  if (table_exists($pdo, 'fuel_prices')) {
    if (table_exists($pdo, 'fuel_tanks')) {
      $sql_inv = "
        SELECT
          fp.fuel_id,
          fp.fuel_name,
          COALESCE(SUM(ft.current_volume_l),0) AS current_stock,
          COALESCE(SUM(ft.capacity_l),0)       AS capacity,
          COALESCE(MIN(ft.min_threshold_l),0)  AS min_threshold,
          COALESCE(MAX(ft.max_threshold_l),0)  AS max_threshold,
          (
            SELECT MAX(fm.occurred_at)
            FROM fuel_moves fm
            JOIN fuel_tanks t2 ON t2.id = fm.tank_id
            WHERE fm.type IN ('receive','transfer_in','adjust_plus')
              AND t2.fuel_id = fp.fuel_id
          ) AS last_refill_date
        FROM fuel_prices fp
        LEFT JOIN fuel_tanks ft
          ON ft.fuel_id = fp.fuel_id AND ft.is_active = 1
        GROUP BY fp.fuel_id, fp.fuel_name
        ORDER BY fp.fuel_id
      ";
      $rows = $pdo->query($sql_inv)->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $fuel_inventory[] = [
          'fuel_id'          => (int)$r['fuel_id'],
          'fuel_name'        => $r['fuel_name'],
          'current_stock'    => (float)$r['current_stock'],
          'capacity'         => (float)$r['capacity'],
          'min_threshold'    => (float)$r['min_threshold'],
          'max_threshold'    => (float)$r['max_threshold'],
          'last_refill_date' => $r['last_refill_date']
        ];
      }
    }
    // Fallback: fuel_stock
    if (empty($fuel_inventory) && table_exists($pdo, 'fuel_stock')) {
      $sql_inv2 = "
        SELECT
          fs.fuel_id,
          fp.fuel_name,
          fs.current_stock,
          fs.capacity,
          fs.min_threshold,
          fs.max_threshold,
          fs.last_refill_date
        FROM fuel_stock fs
        JOIN fuel_prices fp ON fp.fuel_id = fs.fuel_id
        ORDER BY fp.fuel_id
      ";
      $rows2 = $pdo->query($sql_inv2)->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows2 as $r) {
        $fuel_inventory[] = [
          'fuel_id'          => (int)$r['fuel_id'],
          'fuel_name'        => $r['fuel_name'],
          'current_stock'    => (float)$r['current_stock'],
          'capacity'         => (float)$r['capacity'],
          'min_threshold'    => (float)$r['min_threshold'],
          'max_threshold'    => (float)$r['max_threshold'],
          'last_refill_date' => $r['last_refill_date']
        ];
      }
    }
  }

  // ---------- ตัวชี้วัดระบบ ----------
  $low_stock_count = 0;
  foreach ($fuel_inventory as $it) {
    if ($it['capacity'] > 0 && ($it['current_stock'] <= $it['min_threshold'])) $low_stock_count++;
  }

  $db_name    = null;
  $db_version = null;
  $last_login = null;
  try {
    $db_name    = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $db_version = $pdo->query("SELECT VERSION()")->fetchColumn();
  } catch (Throwable $e) {}

  if (table_exists($pdo, 'users') && column_exists($pdo, 'users', 'last_login_at')) {
    $stmtLL = $pdo->prepare("SELECT last_login_at FROM users WHERE id = :uid");
    $stmtLL->execute([':uid' => $current_user_id]);
    $last_login = $stmtLL->fetchColumn() ?: null;
  }

  $active_pumps = 0;
  if (table_exists($pdo,'pumps') && column_exists($pdo,'pumps','status')) {
    $active_pumps = (int)$pdo->query("SELECT COUNT(*) FROM pumps WHERE status='active'")->fetchColumn();
  }

  $system_status = [
    'db_connected'    => ($pdo instanceof PDO),
    'db_name'         => $db_name,
    'db_version'      => $db_version,
    'php_version'     => PHP_VERSION,
    'timezone'        => date_default_timezone_get(),
    'server_time'     => date('Y-m-d H:i:s'),
    'user_last_login' => $last_login,
    'active_pumps'    => $active_pumps,
    'low_stock_fuels' => $low_stock_count
  ];

} catch (Throwable $e) {
  error_log("Dashboard Error: " . $e->getMessage());
  // Fallback data (ป้องกันหน้าแตก)
  $recent = [[
    'time' => date('H:i'),
    'fuel' => 'Error',
    'fuel_id' => 'NA',
    'liters' => 0, 'net' => 0, 'pay' => '-',
    'phone' => '-', 'house_no' => $e->getMessage(), 'points' => 0,
    'receipt_no' => 'N/A', 'receipt' => false, 'sale_data_json' => '{}'
  ]];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>แดชบอร์ดพนักงาน | <?= htmlspecialchars($site_name) ?></title>

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

  <!-- Global theme -->
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    .panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
    .panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
    .panel-head h5{margin:0;color:var(--steel);font-weight:700}

    .stat-card{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
    .stat-card h3{font-weight:600}
    .stat-card .small-muted{font-size:.9rem;color:var(--steel)}
    .stat-card .text-success{color:var(--success)}
    .stat-card .text-info{color:var(--info)}
    .stat-card .text-primary{color:var(--primary)}
    .stat-card .text-warning{color:var(--warning)}

    .receipt-btn{padding:.25rem .5rem;font-size:.75rem;border-radius:4px}
    .member-info{font-size:.85rem;color:#6c757d}
    .house-badge{background:#e7f3ff;color:#0066cc;padding:2px 6px;border-radius:3px;font-size:.75rem}
    .points-badge{background:#fff3cd;color:#856404;padding:2px 6px;border-radius:3px;font-size:.75rem}

    .member-info {
      font-size: 0.85rem;
    }
    .member-info .fw-semibold {
      font-weight: 600;
      margin-bottom: 2px;
    }
    .table td {
      vertical-align: middle;
    }
  </style>
</head>
<body>
  <!-- App Bar -->
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

  <!-- Offcanvas Sidebar -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Employee</span></h3></div>
      <nav class="sidebar-menu">
        <a class="active" href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
        <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
        <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
        <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>

  <!-- Main -->
  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Employee</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a class="active" href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
          <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
          <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
          <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i> ออกจากระบบ</a>
      </aside>

      <!-- Content -->
      <main class="col-lg-10 p-4">
        <div class="main-header d-flex align-items-center justify-content-between">
          <h2 class="mb-0"><i class="fa-solid fa-chart-simple me-2"></i>แดชบอร์ดภาพรวม</h2>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
          <div class="stat-card">
            <h5><i class="bi bi-currency-dollar"></i> ยอดขายของวันนี้</h5>
            <h3 class="text-success">฿<?= number_format($todaySalesAmount,2) ?></h3>
            <p class="text-success mb-0">เฉลี่ย/บิล ฿<?= number_format($avgPerBill,2) ?></p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-fuel-pump-fill"></i> น้ำมันที่ขาย (ลิตร)</h5>
            <h3 class="text-primary"><?= number_format($todayLiters,2) ?> ลิตร</h3>
            <p class="mb-0 text-muted">รวมทุกชนิดน้ำมัน</p>
          </div>
          <div class="stat-card">
            <h5><i class="fa-solid fa-receipt"></i> จำนวนบิลวันนี้</h5>
            <h3 class="text-info"><?= number_format($todayBills) ?> บิล</h3>
            <p class="mb-0 text-muted">อัปเดตเรียลไทม์</p>
          </div>
        </div>

        <!-- Inventory Status -->
        <div class="stat-card mt-4">
          <h5 class="mb-3"><i class="fa-solid fa-oil-can me-2"></i>สถานะสินค้าคงคลัง</h5>
          <div class="row g-3">
            <?php if (empty($fuel_inventory)): ?>
              <div class="col-12"><p class="text-muted text-center">ไม่สามารถโหลดข้อมูลสต็อกได้</p></div>
            <?php else: foreach ($fuel_inventory as $fuel):
              $percentage = $fuel['capacity'] > 0 ? ($fuel['current_stock'] / $fuel['capacity']) * 100 : 0;
              $stock_status_class = 'bg-success';
              if ($percentage < 20) { $stock_status_class = 'bg-danger'; }
              elseif ($percentage < 50) { $stock_status_class = 'bg-warning'; }
            ?>
              <div class="col-md-6 col-lg-4">
                <h6 class="mb-1"><?= htmlspecialchars($fuel['fuel_name']) ?></h6>
                <div class="progress" style="height:20px" role="progressbar" aria-label="Fuel level"
                     aria-valuenow="<?= (float)$percentage ?>" aria-valuemin="0" aria-valuemax="100">
                  <div class="progress-bar <?= $stock_status_class ?>" style="width: <?= (float)$percentage ?>%;">
                    <?= number_format($percentage, 1) ?>%
                  </div>
                </div>
                <div class="d-flex justify-content-between small mt-1">
                  <span><?= number_format($fuel['current_stock'], 0) ?> L</span>
                  <span class="text-muted"><?= number_format($fuel['capacity'], 0) ?> L</span>
                </div>
                <?php if(!empty($fuel['last_refill_date'])): ?>
                  <div class="small text-muted mt-1">เติมล่าสุด: <?= htmlspecialchars(date('d/m/Y H:i', strtotime($fuel['last_refill_date']))) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <!-- Charts -->
        <div class="row g-4 mt-1 row-charts">
          <div class="col-12 col-xl-7">
            <div class="stat-card h-100">
              <h5><i class="fa-solid fa-chart-line"></i> ยอดขายรายชั่วโมง (ลิตร)</h5>
              <div class="chart-wrap" style="height:280px"><canvas id="hourChart"></canvas></div>
            </div>
          </div>
          <div class="col-12 col-xl-5">
            <div class="stat-card h-100">
              <h5><i class="bi bi-pie-chart"></i> สัดส่วนการขายน้ำมัน</h5>
              <div class="chart-wrap" style="height:280px"><canvas id="mixChart"></canvas></div>
            </div>
          </div>
        </div>

        <!-- Recent table -->
        <div class="stat-card mt-4">
          <h5 class="mb-2"><i class="fa-solid fa-clock-rotate-left"></i> รายการล่าสุด</h5>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>เวลา</th>
                  <th>ชนิด</th>
                  <th class="text-end">ลิตร</th>
                  <th class="text-end">สุทธิ (฿)</th>
                  <th>ชำระ</th>
                  <th>ข้อมูลสมาชิก</th>
                  <th>แต้ม</th>
                  <th>ใบเสร็จ</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($recent as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['time']) ?></td>
                  <td><?= htmlspecialchars($r['fuel']) ?></td>
                  <td class="text-end"><?= number_format($r['liters'],2) ?></td>
                  <td class="text-end"><?= number_format($r['net'],2) ?></td>
                  <td>
                    <?php
                      $payKey   = is_string($r['pay']) ? strtolower($r['pay']) : '';
                      $pay_info = $payment_map[$payKey] ?? ['label' => 'ไม่ระบุ', 'class' => 'badge bg-secondary-subtle text-secondary'];
                    ?>
                    <span class="<?= $pay_info['class'] ?>"><?= htmlspecialchars($pay_info['label']) ?></span>
                  </td>
                  <td>
                    <?php if(!empty($r['member_name']) || !empty($r['phone']) || !empty($r['house_no'])): ?>
                      <div class="small">
                        <?php if(!empty($r['member_name'])): ?>
                          <div class="fw-semibold text-primary"><i class="bi bi-person-check-fill"></i> <?= htmlspecialchars($r['member_name']) ?></div>
                        <?php endif; ?>
                        <?php if(!empty($r['phone'])): ?>
                          <div class="text-muted"><i class="bi bi-telephone"></i> <?= htmlspecialchars($r['phone']) ?></div>
                        <?php endif; ?>
                        <?php if(!empty($r['house_no'])): ?>
                          <div><span class="badge bg-info-subtle text-info"><i class="bi bi-house"></i> <?= htmlspecialchars($r['house_no']) ?></span></div>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <span class="text-muted small">-</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if($r['points'] > 0): ?>
                      <span class="badge bg-warning text-dark">
                        <i class="bi bi-star-fill"></i> +<?= (int)$r['points'] ?>
                      </span>
                    <?php else: ?>
                      <span class="text-muted small">-</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button class="btn btn-outline-secondary receipt-btn" onclick="printReceipt('<?= htmlspecialchars($r['sale_data_json'], ENT_QUOTES) ?>')">
                      <i class="bi bi-printer"></i> พิมพ์
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($recent)): ?>
                  <tr><td colspan="8" class="text-center text-muted">ยังไม่มีรายการวันนี้</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php if (($_GET['diag'] ?? '') === '1'): ?>
        <!-- DB self-test -->
        <div class="stat-card mt-4">
          <h5 class="mb-2"><i class="bi bi-bug"></i> DB self-test</h5>
          <ul class="mb-2">
            <?php
              $checks = [
                ['sales',                  table_exists($pdo,'sales')],
                ['sales_items',            table_exists($pdo,'sales_items')],
                ['fuel_prices',            table_exists($pdo,'fuel_prices')],
                ['fuel_tanks',             table_exists($pdo,'fuel_tanks')],
                ['fuel_stock (fallback)',  table_exists($pdo,'fuel_stock')],
                ['users.last_login_at',    column_exists($pdo,'users','last_login_at')],
                ['pumps.status',           column_exists($pdo,'pumps','status')],
              ];
              foreach ($checks as [$label, $ok]) {
                echo '<li>'.htmlspecialchars($label).' : '.($ok?'<span class="text-success">OK</span>':'<span class="text-danger">MISSING</span>').'</li>';
              }
              try {
                $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM sales s WHERE s.station_id=:sid AND s.sale_date>=CURDATE() AND s.sale_date<(CURDATE()+INTERVAL 1 DAY)");
                $stmtCnt->execute([':sid'=>$station_id]);
                $cntSaleToday = (int)$stmtCnt->fetchColumn();
                echo '<li>rows in sales today (station '.$station_id.') : <b>'.$cntSaleToday.'</b></li>';
              } catch(Throwable $e) {
                echo '<li class="text-danger">sample query error: '.htmlspecialchars($e->getMessage()).'</li>';
              }
            ?>
          </ul>
          <small class="text-muted">เปิด/ปิดโดยใส่พารามิเตอร์ <code>?diag=1</code> หลัง URL</small>
        </div>
        <?php endif; ?>

      </main>
    </div>
  </div>

  <!-- Receipt Print Modal (basic) -->
  <div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="receiptModalLabel"><i class="bi bi-receipt"></i> พิมพ์ใบเสร็จ</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="receiptContent">
            <div class="text-center mb-3">
              <h6><?= htmlspecialchars($site_name) ?></h6>
              <p class="small text-muted">ใบเสร็จรับเงิน</p>
            </div>
            <hr>
            <div id="receiptDetails"></div>
            <hr>
            <div class="text-center">
              <p class="small">ขอบคุณที่ใช้บริการ</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
          <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> พิมพ์
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — แดชบอร์ดพนักงาน</footer>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // พิมพ์ใบเสร็จ
    function printReceipt(saleDataJson) {
      let sale;
      try { sale = JSON.parse(saleDataJson); } catch(e) { return; }

      const {
        site_name, receipt_no, datetime, fuel_name, price_per_liter, liters,
        total_amount, discount_percent, discount_amount, net_amount,
        payment_method, employee_name, customer_phone, household_no, points_earned
      } = sale;

      const saleDate = new Date(datetime).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
      const payMap   = { cash:'เงินสด', qr:'QR Code', transfer:'โอนเงิน', card:'บัตรเครดิต' };
      const payLabel = payMap[(payment_method || '').toLowerCase()] || 'ไม่ระบุ';

      const receiptHTML = `
        <html><head><title>ใบเสร็จ ${receipt_no}</title>
        <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap" rel="stylesheet">
        <style>
          body { font-family:'Sarabun',sans-serif; width:300px; margin:0 auto; padding:10px; color:#000; font-size:14px; }
          h3,h4,p{ margin:0; text-align:center; }
          h3{ font-size:1.1rem } h4{ font-weight:normal; font-size:.9rem }
          hr{ border:none; border-top:1px dashed #000; margin:6px 0 }
          .row{ display:flex; justify-content:space-between; margin-bottom:2px; }
          .total{ font-weight:700; font-size:1.05rem }
        </style></head><body>
          <h3>${site_name}</h3><h4>ใบเสร็จรับเงิน</h4><hr>
          <div class="row"><span>เลขที่:</span><span>${receipt_no}</span></div>
          <div class="row"><span>วันที่:</span><span>${saleDate}</span></div><hr>
          ${customer_phone ? `<div class="row"><span>เบอร์โทร:</span><span>${customer_phone}</span></div>`:''}
          ${household_no ? `<div class="row"><span>บ้านเลขที่:</span><span>${household_no}</span></div>`:''}
          <div class="row"><span>${parseFloat(liters).toFixed(3)} L. @ ${parseFloat(price_per_liter).toFixed(2)}</span><span>${parseFloat(total_amount).toFixed(2)}</span></div><hr>
          ${parseFloat(discount_amount)>0?`<div class="row"><span>ส่วนลด (${parseFloat(discount_percent)}%):</span><span>-${parseFloat(discount_amount).toFixed(2)}</span></div>`:''}
          <div class="row total"><span>รวมทั้งสิ้น</span><span>${parseFloat(net_amount).toFixed(2)} บาท</span></div><hr>
          ${parseInt(points_earned)>0?`<div class="row"><span>แต้มที่ได้รับ</span><span>${parseInt(points_earned)} แต้ม</span></div><hr>`:''}
          <div class="row"><span>ชำระโดย:</span><span>${payLabel}</span></div>
          <div class="row"><span>พนักงาน:</span><span>${employee_name || ''}</span></div>
          <p style="margin-top:10px;">** ขอบคุณที่ใช้บริการ **</p>
        </body></html>`;

      const w = window.open('', '_blank');
      w.document.write(receiptHTML); w.document.close(); w.focus();
      setTimeout(()=>{ w.print(); w.close(); }, 250);
    }

    // --- Chart Data from PHP ---
    const hourlyChartData = <?= json_encode($hourly_chart_data); ?>;
    const fuelMixChartData = <?= json_encode($fuel_mix_chart_data); ?>;

    // Hourly liters line chart
    const hourCtx = document.getElementById('hourChart').getContext('2d');
    new Chart(hourCtx, {
      type: 'line',
      data: {
        labels: hourlyChartData.labels,
        datasets: [{
          label: 'ลิตร (รวม)',
          data: hourlyChartData.data,
          borderColor: '#20A39E',
          backgroundColor: 'rgba(32, 163, 158, 0.1)',
          fill: true,
          tension: 0.3,
          borderWidth: 2
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

    // Fuel mix donut
    const mixCtx = document.getElementById('mixChart').getContext('2d');
    const fuelColors = {
      'ดีเซล': '#CCA43B', 'แก๊สโซฮอล์ 95': '#20A39E', 'แก๊สโซฮอล์ 91': '#B66D0D',
      'E20': '#513F32', 'เบนซิน': '#212845', 'default': '#6c757d'
    };
    const backgroundColors = (fuelMixChartData.labels || []).map(label => fuelColors[label] || fuelColors['default']);

    new Chart(mixCtx, {
      type: 'doughnut',
      data: {
        labels: fuelMixChartData.labels,
        datasets: [{
          data: fuelMixChartData.data,
          backgroundColor: backgroundColors,
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
  </script>
</body>
</html>
