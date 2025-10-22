<?php
// admin/report.php — ระบบรายงานและสถิติสหกรณ์ (เวอร์ชันรวมแก้ไข)

// --- Session & CSRF ---
session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- DB connect ---
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}

// --- Defaults / Current user (กัน Notice) ---
$current_name = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
$current_role = $_SESSION['role'] ?? 'guest';

// --- Site settings (cooperative schema) ---
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
$station_id = 1;

try {
  // system settings (JSON) ใน app_settings.key='system_settings'
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $sys = json_decode($r['json_value'], true) ?: [];
    if (!empty($sys['site_name']))     $site_name = $sys['site_name'];
    if (!empty($sys['site_subtitle'])) $site_subtitle = $sys['site_subtitle'];
  }

  // station_id จาก settings
  $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) $station_id = (int)$r['setting_value'];
} catch (Throwable $e) {}

// --- AuthZ guard ---
try {
  $current_name = $_SESSION['full_name'] ?? $current_name;
  $current_role = $_SESSION['role'] ?? $current_role;
  if ($current_role !== 'admin') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
  exit();
}

// ===== Helpers: ตรวจตาราง/คอลัมน์ และเลือกคอลัมน์ที่มีจริง =====
function table_exists(PDO $pdo, string $table): bool {
  // escape สำหรับ LIKE
  $like = str_replace(['\\','_','%'], ['\\\\','\\_','\\%'], $table);
  $quoted = $pdo->quote($like);
  // ห้ามใช้ prepare กับ SHOW
  $sql = "SHOW FULL TABLES LIKE $quoted";
  $stmt = $pdo->query($sql);
  return (bool)($stmt ? $stmt->fetchColumn() : false);
}

function column_exists(PDO $pdo, string $table, string $col): bool {
  // escape ชื่อ table สำหรับ backtick และ pattern LIKE
  $tableEsc = str_replace('`','``',$table);
  $like = str_replace(['\\','_','%'], ['\\\\','\\_','\\%'], $col);
  $quoted = $pdo->quote($like);
  $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE $quoted";
  $stmt = $pdo->query($sql);
  return (bool)($stmt ? $stmt->fetchColumn() : false);
}

function first_col(PDO $pdo, string $table, array $cands): ?string {
  foreach ($cands as $c) { if (column_exists($pdo,$table,$c)) return $c; }
  return null;
}

// --- Case/Null safe helpers ---
function row_get(array $row, array $keys) {
  foreach ($keys as $k) if (array_key_exists($k, $row)) return $row[$k];
  return null;
}
function lcstr($v){ return is_string($v) ? strtolower($v) : ''; }

// ==== หา column วันที่แบบยืดหยุ่น ====
function detect_date_col(PDO $pdo, string $table): ?string {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $stmt = $pdo->prepare("
    SELECT column_name, data_type
    FROM information_schema.columns
    WHERE table_schema=:db AND table_name=:tb
    ORDER BY ordinal_position
  ");
  $stmt->execute([':db'=>$db, ':tb'=>$table]);
  $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$cols) return null;

  $preferred = [
    'transaction_date','txn_date','trans_date','posting_date','posted_date',
    'doc_date','document_date','entry_date','recorded_at','transacted_at',
    'created_at','createdon','created','date','datetime','timestamp'
  ];

  // สร้างแผนที่ชื่อคอลัมน์ -> (name,type) แบบปลอดภัย
  $byName = [];
  foreach ($cols as $c) {
    $name = row_get($c, ['column_name','COLUMN_NAME','Column_name']);
    $type = lcstr(row_get($c, ['data_type','DATA_TYPE']));
    if (!$name) continue;
    $byName[lcstr($name)] = ['name'=>$name,'type'=>$type];
  }

  // เลือกตามชื่อที่คาดไว้ก่อน
  foreach ($preferred as $p) {
    if (isset($byName[$p])) return $byName[$p]['name'];
  }

  // เลือกคอลัมน์แรกที่ชนิดเป็นวันเวลา
  foreach ($byName as $info) {
    if (in_array($info['type'], ['date','datetime','timestamp'], true)) {
      return $info['name'];
    }
  }
  return null;
}

// คืน ['col'=>ชื่อคอลัมน์, 'expr'=>นิพจน์วันที่ใน SQL] หรือ null
function detect_finance_date_expr(PDO $pdo, string $table): ?array {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $stmt = $pdo->prepare("
    SELECT column_name, data_type
    FROM information_schema.columns
    WHERE table_schema=:db AND table_name=:tb
  ");
  $stmt->execute([':db'=>$db, ':tb'=>$table]);
  $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$cols) return null;

  $candNames = [
    'transaction_date','txn_date','trans_date','posting_date','posted_date',
    'doc_date','document_date','entry_date','recorded_at','transacted_at',
    'created_at','createdon','created','date'
  ];

  // map ชื่อคอลัมน์ (lower) -> data_type (lower)
  $by = [];
  $names = []; // เก็บชื่อจริงไว้คืนค่า
  foreach ($cols as $c) {
    $name = row_get($c, ['column_name','COLUMN_NAME','Column_name']);
    $type = lcstr(row_get($c, ['data_type','DATA_TYPE']));
    if (!$name) continue;
    $by[lcstr($name)] = $type;
    $names[lcstr($name)] = $name;
  }

  // 1) ถ้ามีคอลัมน์ที่เป็นชนิดวันเวลาจริง ใช้ทันที
  foreach ($candNames as $n) {
    $ln = lcstr($n);
    if (isset($by[$ln]) && in_array($by[$ln], ['date','datetime','timestamp'], true)) {
      $real = $names[$ln];
      return ['col'=>$real, 'expr'=>'`'.$real.'`'];
    }
  }
  foreach ($by as $ln=>$type) {
    if (in_array($type, ['date','datetime','timestamp'], true)) {
      $real = $names[$ln];
      return ['col'=>$real, 'expr'=>'`'.$real.'`'];
    }
  }

  // 2) ถ้าชื่อสื่อความเป็นวันที่แต่เป็นข้อความ: ลอง STR_TO_DATE
  $parseFormats = ["'%Y-%m-%d %H:%i:%s'","'%Y-%m-%d'","'%d/%m/%Y'","'%Y/%m/%d'","'%d-%m-%Y'"];
  foreach ($candNames as $n) {
    $ln = lcstr($n);
    if (!isset($names[$ln])) continue;
    $real = $names[$ln];
    $coalesce = "COALESCE(" . implode(", ", array_map(fn($f)=>"STR_TO_DATE(`$real`, $f)", $parseFormats)) . ")";
    $sqlChk = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE $coalesce IS NOT NULL LIMIT 1");
    $sqlChk->execute();
    if ((int)$sqlChk->fetchColumn() > 0) {
      return ['col'=>$real, 'expr'=>$coalesce];
    }
  }

  // 3) คอลัมน์ที่ชื่อมีคำว่า date/time ใด ๆ แล้ว parse ได้
  foreach ($names as $ln=>$real) {
    if (str_contains($ln,'date') || str_contains($ln,'time')) {
      $coalesce = "COALESCE(" . implode(", ", array_map(fn($f)=>"STR_TO_DATE(`$real`, $f)", $parseFormats)) . ")";
      $sqlChk = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE $coalesce IS NOT NULL LIMIT 1");
      $sqlChk->execute();
      if ((int)$sqlChk->fetchColumn() > 0) {
        return ['col'=>$real, 'expr'=>$coalesce];
      }
    }
  }
  return null;
}

// ==== Init result containers ====
$daily_sales = [];
$fuel_stock = [];
$monthly_finance = [];
$fuel_sales_distribution = [];
$member_stats = [
  'total_members' => 0, 'new_this_month' => 0, 'total_shares' => 0,
  'active_pumps' => 0, 'maintenance_due' => 0
];
$error_message = null;

// DEBUG flag
$DEBUG = false;

try {
  /* ==== Mapping อัตโนมัติ ==== */
// sales / sales_items
$salesIdCol    = first_col($pdo,'sales',['id','sale_id']);
$salesDateCol  = first_col($pdo,'sales',['sale_date','sold_at','created_at','updated_at']);
$siSaleIdCol   = first_col($pdo,'sales_items',['sale_id','sales_id']);
$siLitersCol   = first_col($pdo,'sales_items',['liters','qty_liter','quantity_liters','quantity']);
$siAmountCol   = first_col($pdo,'sales_items',['line_amount','amount','subtotal']);
$siPriceCol    = first_col($pdo,'sales_items',['price_per_liter','unit_price','price']);

// สำคัญ: เลือก fuel_id ก่อน (เลข) ค่อย fallback เป็น fuel_type (ข้อความ)
$siFuelFkCol   = first_col($pdo,'sales_items',['fuel_id','product_id','fuel_type']);

if (!$salesIdCol || !$salesDateCol || !$siSaleIdCol) {
  throw new RuntimeException('ไม่พบคอลัมน์หลักของตาราง sales/sales_items');
}

// fuels/fuel_prices
$fuelPricesTable = table_exists($pdo,'fuel_prices') ? 'fuel_prices' : null;
$fpIdCol   = $fuelPricesTable ? first_col($pdo,$fuelPricesTable,['fuel_id','id']) : null;
$fpNameCol = $fuelPricesTable ? first_col($pdo,$fuelPricesTable,['fuel_name','name','title','label']) : null;
$fpOrderCol= ($fuelPricesTable && column_exists($pdo,$fuelPricesTable,'display_order')) ? 'display_order' : null;

// ถ้า si ใช้ fuel_id → join ด้วยเลข; ถ้าเป็น fuel_type → ใช้ label จาก si ตรง ๆ
// ถ้า si ใช้ fuel_id → join ด้วยเลข; ถ้าเป็น fuel_type → ใช้ label จาก si ตรง ๆ
$useJoinWithFuel = $fuelPricesTable && $fpIdCol && in_array($siFuelFkCol, [$fpIdCol,'fuel_id','product_id'], true);

// ตรวจว่าข้อมูล fuel_id มีจริงไหม (ไม่ใช่แค่มีคอลัมน์)
if ($useJoinWithFuel) {
  $probe_sql = "
    SELECT COUNT(*)
    FROM sales_items si
    JOIN sales s ON si.$siSaleIdCol = s.$salesIdCol
    WHERE s.station_id = {$station_id}
      AND s.$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND si.$siFuelFkCol IS NOT NULL
  ";
  $hasFk = (int)$pdo->query($probe_sql)->fetchColumn();
  if ($hasFk === 0) {
    $useJoinWithFuel = false;
  }
}

$fpJoin    = $useJoinWithFuel ? "JOIN $fuelPricesTable fp ON fp.$fpIdCol = si.$siFuelFkCol AND fp.station_id = s.station_id" : "";
$fpNameSel = $useJoinWithFuel
  ? ($fpNameCol ? "fp.$fpNameCol" : "si.$siFuelFkCol")
  : (($siFuelFkCol === 'fuel_type') ? "si.fuel_type" : "si.$siFuelFkCol");

// นิพจน์จำนวนเงิน/ลิตร
$amountExpr = $siAmountCol ? "SUM(si.$siAmountCol)" : (($siLitersCol && $siPriceCol) ? "SUM(si.$siLitersCol * si.$siPriceCol)" : "SUM(0)");
$litersExpr = $siLitersCol ? "SUM(si.$siLitersCol)" : "SUM(0)";

/* 1) ยอดขายรายวัน 7 วันล่าสุด */
$sql = "
  SELECT DATE(s.$salesDateCol) AS `date`,
         COALESCE($amountExpr,0) AS revenue,
         COALESCE($litersExpr,0) AS liters,
         COUNT(DISTINCT s.$salesIdCol) AS transactions
  FROM sales s
  JOIN sales_items si ON s.$salesIdCol = si.$siSaleIdCol
  WHERE s.station_id = {$station_id}
    AND s.$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY DATE(s.$salesDateCol)
  ORDER BY `date` ASC
";
$daily_sales = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* 2) ค่าเฉลี่ยการใช้น้ำมัน/วัน (30 วัน) */
$avg_sql = "
  SELECT $fpNameSel AS fuel_name, COALESCE($litersExpr / 30, 0) AS avg_daily_usage
  FROM sales_items si
  JOIN sales s ON si.$siSaleIdCol = s.$salesIdCol
  $fpJoin
  WHERE s.station_id = {$station_id}
    AND s.$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY $fpNameSel
";
$avg_map = [];
foreach ($pdo->query($avg_sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $avg_map[$r['fuel_name']] = (float)$r['avg_daily_usage'];
}

/* 3) สต็อกน้ำมัน: ใช้ v_fuel_stock_live ถ้ามี (ดึงจากถังจริง) */
$fuelStockTable = table_exists($pdo,'v_fuel_stock_live')
  ? 'v_fuel_stock_live'
  : (table_exists($pdo,'fuel_stock') ? 'fuel_stock' : null);

$fsFuelIdCol   = $fuelStockTable ? first_col($pdo,$fuelStockTable,['fuel_id','product_id','id']) : null;
$fsCurrentCol  = $fuelStockTable ? first_col($pdo,$fuelStockTable,['current_stock','current_qty','qty','quantity']) : null;
$fsCapacityCol = $fuelStockTable ? first_col($pdo,$fuelStockTable,['capacity','max_capacity','max_qty']) : null;

if ($fuelStockTable && $fsCurrentCol && $fsCapacityCol) {
  $stock_sql = "
    SELECT ".
      (($fuelPricesTable && $fpNameCol)
        ? "fp.$fpNameCol AS fuel_type"
        : "fs.$fsFuelIdCol AS fuel_type").",
      fs.$fsCurrentCol AS current_stock,
      fs.$fsCapacityCol AS capacity
    FROM $fuelStockTable fs
    ".(($fuelPricesTable && $fpIdCol && $fsFuelIdCol)
        ? "JOIN $fuelPricesTable fp ON fp.$fpIdCol = fs.$fsFuelIdCol AND fp.station_id = fs.station_id"
        : "")."
    WHERE fs.station_id = {$station_id}
    ".($fpOrderCol ? "ORDER BY fp.$fpOrderCol" : "")."
  ";
  $fuel_stock = [];
  foreach ($pdo->query($stock_sql)->fetchAll(PDO::FETCH_ASSOC) as $st) {
    $name = (string)$st['fuel_type'];
    $fuel_stock[] = [
      'fuel_type'      => $name,
      'current_stock'  => (float)$st['current_stock'],
      'capacity'       => (float)$st['capacity'],
      'avg_daily_usage'=> (float)($avg_map[$name] ?? 0.0)
    ];
  }
}

/* 4) การเงินรายเดือน (6 เดือน) – ใช้ financial_transactions จริง */
$monthly_finance = [];
if (table_exists($pdo,'financial_transactions')) {
  $mf_sql = "
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS `month`,
           COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END),0) AS income,
           COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense,
           COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE -amount END),0) AS profit
    FROM financial_transactions
    WHERE station_id = {$station_id}
      AND transaction_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY `month`
    ORDER BY `month` ASC
  ";
  $monthly_finance = $pdo->query($mf_sql)->fetchAll(PDO::FETCH_ASSOC);
}

/* 5) สถิติสมาชิก + ปั๊ม */
$member_sql = "
  SELECT
    COUNT(*) AS total_members,
    SUM(CASE WHEN joined_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS new_this_month,
    COALESCE(SUM(shares),0) AS total_shares
  FROM members
  WHERE station_id = {$station_id}
";
$mdata = $pdo->query($member_sql)->fetch(PDO::FETCH_ASSOC) ?: [];
$member_stats['total_members'] = (int)($mdata['total_members'] ?? 0);
$member_stats['new_this_month']= (int)($mdata['new_this_month'] ?? 0);
$member_stats['total_shares']  = (int)($mdata['total_shares'] ?? 0);

if (table_exists($pdo,'pumps') && column_exists($pdo,'pumps','status')) {
  $pumps_sql = "
    SELECT
      SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_pumps,
      SUM(CASE WHEN (last_maintenance < DATE_SUB(NOW(), INTERVAL 30 DAY) OR last_maintenance IS NULL) THEN 1 ELSE 0 END) AS maintenance_due
    FROM pumps
    WHERE station_id = {$station_id}
  ";
  $pdata = $pdo->query($pumps_sql)->fetch(PDO::FETCH_ASSOC) ?: [];
  $member_stats['active_pumps']   = (int)($pdata['active_pumps'] ?? 0);
  $member_stats['maintenance_due']= (int)($pdata['maintenance_due'] ?? 0);
}

/* 6) สัดส่วนยอดขายตามชนิดน้ำมัน (30 วัน) */
$dist_sql = "
  SELECT $fpNameSel AS fuel_name, COALESCE($litersExpr,0) AS total_liters
  FROM sales_items si
  JOIN sales s ON si.$siSaleIdCol = s.$salesIdCol
  $fpJoin
  WHERE s.station_id = {$station_id}
    AND s.$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY $fpNameSel
  HAVING total_liters > 0
  ORDER BY total_liters DESC
";
$fuel_sales_distribution = $pdo->query($dist_sql)->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูลรายงาน: " . $e->getMessage();
  // fallback ให้หน้าแสดงผลได้
  $daily_sales = [['date' => date('Y-m-d'), 'revenue' => 0, 'liters' => 0, 'transactions' => 0]];
  $fuel_stock = [];
  $monthly_finance = [];
  $fuel_sales_distribution = [];
  $member_stats = ['total_members'=>0,'new_this_month'=>0,'total_shares'=>0,'active_pumps'=>0,'maintenance_due'=>0];
}

// (debug) ใส่คอมเมนต์เช็ค DB
try {
  $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
  if ($DEBUG) echo "<!-- DB OK: {$dbName} | sales7=".count($daily_sales)." | fuels=".count($fuel_stock)." -->";
} catch (Throwable $e) {
  if ($DEBUG) echo "<!-- DB FAIL: ".$e->getMessage()." -->";
}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// คำนวณสถิติสรุป
$total_revenue_7days = array_sum(array_column($daily_sales, 'revenue'));
$total_liters_7days  = array_sum(array_column($daily_sales, 'liters'));
$avg_daily_revenue   = count($daily_sales) > 0 ? $total_revenue_7days / count($daily_sales) : 0;
$current_month_profit = !empty($monthly_finance) ? (end($monthly_finance)['profit'] ?? 0) : 0;
// เทียบกับเดือนก่อน
$prev_month_profit = 0;
if (count($monthly_finance) >= 2) {
  $last2 = array_slice($monthly_finance, -2);
  $prev_month_profit = (float)$last2[0]['profit'];
}
$delta_pct = ($prev_month_profit > 0) ? (($current_month_profit - $prev_month_profit) / $prev_month_profit * 100) : 0;

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>รายงานและสถิติ | สหกรณ์ปั๊มน้ำมัน</title>

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

  <!-- Global theme -->
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .report-card { border: 1px solid #e9ecef; border-radius: 12px; transition: .25s; background: #fff; cursor: pointer; }
    .report-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,.08); transform: translateY(-2px); border-color: #0d6efd; }
    .report-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; margin-bottom: 15px; }
    .chart-container { position: relative; height: 300px; width: 100%; }
    .kpi-value { font-size: 2rem; font-weight: 700; margin: 10px 0; }
    .kpi-trend { font-size: 0.9rem; font-weight: 500; }
    .trend-up { color: #198754; } .trend-down { color: #dc3545; } .trend-neutral { color: #6c757d; }
    .filter-section { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
    .quick-stats { background: linear-gradient(135deg, #CCA43B 80%, #856404 100%); color: white; border-radius: 12px; padding: 25px; margin-bottom: 25px; }
    .nav-tabs .nav-link { color: var(--steel); font-weight: 600; }
    .nav-tabs .nav-link.active { color: var(--navy); border-color: var(--border) var(--border) #fff; border-bottom-width: 2px; }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button"
              data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"
              aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
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

<!-- Offcanvas Sidebar (มือถือ/แท็บเล็ต) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
    <nav class="sidebar-menu">
      <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
      <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a class="active" href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
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
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a class="active" href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <!-- Content -->
    <main class="col-lg-10 p-4">
      <div class="main-header">
        <h2><i class="fa-solid fa-chart-line"></i> รายงานและสถิติ</h2>
      </div>

      <?php if ($error_message): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
      <?php endif; ?>

      <!-- Quick Stats -->
      <div class="quick-stats">
        <div class="row text-center">
          <div class="col-md-3">
            <h6>รายได้ 7 วันล่าสุด</h6>
            <div class="kpi-value">฿<?= number_format($total_revenue_7days, 0) ?></div>
            <div class="kpi-trend trend-up">
              <i class="bi bi-arrow-up"></i> เฉลี่ย ฿<?= number_format($avg_daily_revenue, 0) ?>/วัน
            </div>
          </div>
          <div class="col-md-3">
            <h6>น้ำมันขาย 7 วันล่าสุด</h6>
            <div class="kpi-value"><?= number_format($total_liters_7days) ?> ลิตร</div>
            <div class="kpi-trend trend-up">
              <i class="bi bi-arrow-up"></i> เฉลี่ย <?= number_format($total_liters_7days/7, 0) ?> ลิตร/วัน
            </div>
          </div>
          <div class="col-md-3">
            <h6>กำไรเดือนนี้</h6>
            <div class="kpi-value">฿<?= number_format($current_month_profit) ?></div>
            <?php
              $trend_cls = $delta_pct > 0 ? 'trend-up' : ($delta_pct < 0 ? 'trend-down' : 'trend-neutral');
              $trend_icon= $delta_pct > 0 ? 'bi-arrow-up' : ($delta_pct < 0 ? 'bi-arrow-down' : 'bi-dash');
            ?>
            <div class="kpi-trend <?= $trend_cls ?>">
              <i class="bi <?= $trend_icon ?>"></i> <?= ($delta_pct>=0?'+':'') . number_format($delta_pct,1) ?>%
            </div>
          </div>
          <div class="col-md-3">
            <h6>สมาชิกทั้งหมด</h6>
            <div class="kpi-value"><?= number_format($member_stats['total_members']) ?> คน</div>
            <div class="kpi-trend trend-up">
              <i class="bi bi-arrow-up"></i> เพิ่ม <?= number_format($member_stats['new_this_month']) ?> คนเดือนนี้
            </div>
          </div>
        </div>
      </div>

      <!-- Filter Section -->
      <div class="filter-section">
        <div class="row g-3 align-items-end">
          <div class="col-md-3">
            <label class="form-label"><i class="bi bi-calendar3 me-1"></i>วันที่เริ่มต้น</label>
            <input type="date" id="startDate" class="form-control" value="<?= date('Y-m-01') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label"><i class="bi bi-calendar3 me-1"></i>วันที่สิ้นสุด</label>
            <input type="date" id="endDate" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label"><i class="bi bi-funnel me-1"></i>ประเภทรายงาน</label>
            <select id="reportType" class="form-select">
              <option value="all">ทั้งหมด</option>
              <option value="sales">ยอดขาย</option>
              <option value="inventory">คลัง/สต็อก</option>
              <option value="finance">การเงิน</option>
              <option value="members">สมาชิก</option>
            </select>
          </div>
          <div class="col-md-3">
            <button class="btn btn-primary w-100" onclick="generateReport()">
              <i class="bi bi-search me-1"></i> สร้างรายงาน
            </button>
          </div>
        </div>
      </div>

      <!-- Tab Navigation -->
      <ul class="nav nav-tabs mb-3" id="reportTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview-panel" type="button" role="tab">
            <i class="fa-solid fa-chart-pie me-2"></i>ภาพรวม
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales-panel" type="button" role="tab">
            <i class="fa-solid fa-chart-line me-2"></i>ยอดขาย
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory-panel" type="button" role="tab">
            <i class="bi bi-fuel-pump-fill me-2"></i>คลัง/สต็อก
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="financial-tab" data-bs-toggle="tab" data-bs-target="#financial-panel" type="button" role="tab">
            <i class="bi bi-calculator me-2"></i>การเงิน
          </button>
        </li>
      </ul>

      <!-- Tab Content -->
      <div class="tab-content" id="reportTabContent">
        <!-- Overview Panel -->
        <div class="tab-pane fade show active" id="overview-panel" role="tabpanel">
          <div class="row g-4">
            <div class="col-md-6">
              <div class="panel">
                <h6 class="mb-3"><i class="bi bi-graph-up me-1"></i> ยอดขายรายวัน (7 วันล่าสุด)</h6>
                <div class="chart-container">
                  <canvas id="dailySalesChart"></canvas>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="panel">
                <h6 class="mb-3"><i class="bi bi-pie-chart me-1"></i> สัดส่วนยอดขายตามประเภทน้ำมัน</h6>
                <div class="chart-container">
                  <canvas id="fuelTypeChart"></canvas>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="panel">
                <h6 class="mb-3"><i class="bi bi-bar-chart me-1"></i> รายได้-ค่าใช้จ่าย (6 เดือนล่าสุด)</h6>
                <div class="chart-container">
                  <canvas id="monthlyFinanceChart"></canvas>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="panel">
                <h6 class="mb-3"><i class="bi bi-speedometer2 me-1"></i> สถานะสต็อกน้ำมัน</h6>
                <div class="chart-container">
                  <canvas id="stockStatusChart"></canvas>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Sales Panel -->
        <div class="tab-pane fade" id="sales-panel" role="tabpanel">
          <div class="row g-4">
            <div class="col-12">
              <div class="panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6><i class="fa-solid fa-chart-line me-1"></i> รายงานยอดขายรายละเอียด</h6>
                  <div class="btn-group">
                    <button class="btn btn-sm btn-outline-primary" onclick="exportSalesReport('csv')">
                      <i class="bi bi-filetype-csv me-1"></i> CSV
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="exportSalesReport('pdf')">
                      <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                    </button>
                    <button class="btn btn-sm btn-outline-info" onclick="window.print()">
                      <i class="bi bi-printer me-1"></i> พิมพ์
                    </button>
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>วันที่</th>
                        <th class="text-end">รายได้ (บาท)</th>
                        <th class="text-end">ปริมาณ (ลิตร)</th>
                        <th class="text-end">จำนวนธุรกรรม</th>
                        <th class="text-end">รายได้เฉลี่ย/ธุรกรรม</th>
                        <th class="text-center">แนวโน้ม</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach(array_reverse($daily_sales) as $sale):
                        $avg_per_transaction = ($sale['transactions'] > 0) ? ($sale['revenue'] / $sale['transactions']) : 0;
                      ?>
                      <tr>
                        <td><?= date('d/m/Y', strtotime($sale['date'])) ?></td>
                        <td class="text-end">฿<?= number_format($sale['revenue'], 2) ?></td>
                        <td class="text-end"><?= number_format($sale['liters']) ?></td>
                        <td class="text-end"><?= number_format($sale['transactions']) ?></td>
                        <td class="text-end">฿<?= number_format($avg_per_transaction, 2) ?></td>
                        <td class="text-center">
                          <span class="badge bg-success">
                            <i class="bi bi-arrow-up"></i> +2.3%
                          </span>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Inventory Panel -->
        <div class="tab-pane fade" id="inventory-panel" role="tabpanel">
          <div class="row g-4">
            <div class="col-12">
              <div class="panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6><i class="bi bi-fuel-pump-fill me-1"></i> รายงานสถานะสต็อก</h6>
                  <button class="btn btn-sm btn-outline-primary" onclick="exportInventoryReport()">
                    <i class="bi bi-download me-1"></i> ส่งออกรายงาน
                  </button>
                </div>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>ประเภทน้ำมัน</th>
                        <th class="text-end">สต็อกปัจจุบัน</th>
                        <th class="text-end">ความจุรวม</th>
                        <th class="text-center">เปอร์เซ็นต์</th>
                        <th class="text-end">การใช้เฉลี่ย/วัน</th>
                        <th class="text-end">วันที่จะหมด (ประมาณ)</th>
                        <th class="text-center">สถานะ</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach($fuel_stock as $stock):
                        $rawPct = ($stock['capacity'] > 0) ? ($stock['current_stock'] / $stock['capacity']) * 100 : 0;
                        $percentage = max(0, min(100, $rawPct));
                        $days_remaining = $stock['avg_daily_usage'] > 0 ? $stock['current_stock'] / $stock['avg_daily_usage'] : 0;
                        $status_class = $percentage <= 25 ? 'danger' : ($percentage <= 50 ? 'warning' : 'success');
                        $status_text = $percentage <= 25 ? 'ต่ำ' : ($percentage <= 50 ? 'ปานกลาง' : 'ปกติ');
                      ?>
                      <tr>
                        <td><strong><?= htmlspecialchars($stock['fuel_type']) ?></strong></td>
                        <td class="text-end"><?= number_format($stock['current_stock']) ?> ลิตร</td>
                        <td class="text-end"><?= number_format($stock['capacity']) ?> ลิตร</td>
                        <td class="text-center">
                          <div class="progress" style="width: 80px; height: 20px;">
                            <div class="progress-bar bg-<?= $status_class ?>" style="width: <?= number_format($percentage,2) ?>%"></div>
                          </div>
                          <small><?= number_format($percentage, 1) ?>%</small>
                        </td>
                        <td class="text-end"><?= number_format($stock['avg_daily_usage']) ?> ลิตร</td>
                        <td class="text-end"><?= number_format($days_remaining, 0) ?> วัน</td>
                        <td class="text-center"><span class="badge bg-<?= $status_class ?>"><?= $status_text ?></span></td>
                      </tr>
                      <?php endforeach; ?>
                      <?php if (empty($fuel_stock)): ?>
                        <tr><td colspan="7" class="text-center text-muted">ไม่มีข้อมูลสต็อก</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Financial Panel -->
        <div class="tab-pane fade" id="financial-panel" role="tabpanel">
          <div class="row g-4">
            <div class="col-md-8">
              <div class="panel">
                <h6 class="mb-3"><i class="bi bi-graph-up me-1"></i> รายได้และค่าใช้จ่าย (6 เดือนล่าสุด)</h6>
                <div class="chart-container">
                  <canvas id="financialTrendChart"></canvas>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="panel">
                <h6 class="mb-3"><i class="bi bi-calculator me-1"></i> สรุปการเงิน</h6>
                <?php 
                $total_income = array_sum(array_column($monthly_finance, 'income'));
                $total_expense = array_sum(array_column($monthly_finance, 'expense'));
                $total_profit = array_sum(array_column($monthly_finance, 'profit'));
                $profit_margin = $total_income > 0 ? ($total_profit / $total_income) * 100 : 0;
                ?>
                <div class="row g-3">
                  <div class="col-12">
                    <div class="border rounded p-3 text-center">
                      <h6 class="text-success">รายได้รวม (6 เดือน)</h6>
                      <h4 class="text-success mb-0">฿<?= number_format($total_income) ?></h4>
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="border rounded p-3 text-center">
                      <h6 class="text-danger">ค่าใช้จ่ายรวม (6 เดือน)</h6>
                      <h4 class="text-danger mb-0">฿<?= number_format($total_expense) ?></h4>
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="border rounded p-3 text-center">
                      <h6 class="text-primary">กำไรสุทธิ (6 เดือน)</h6>
                      <h4 class="text-primary mb-0">฿<?= number_format($total_profit) ?></h4>
                      <small class="text-muted">อัตรากำไร <?= number_format($profit_margin, 1) ?>%</small>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Export Actions -->
      <div class="row mt-4">
        <div class="col-12">
          <div class="panel">
            <h6 class="mb-3"><i class="bi bi-download me-1"></i> ส่งออกรายงาน</h6>
            <div class="row g-3">
              <div class="col-md-3">
                <button class="btn btn-success w-100" onclick="exportAllReports('excel')">
                  <i class="bi bi-filetype-xlsx me-1"></i> ส่งออก Excel
                </button>
              </div>
              <div class="col-md-3">
                <button class="btn btn-danger w-100" onclick="exportAllReports('pdf')">
                  <i class="bi bi-file-earmark-pdf me-1"></i> ส่งออก PDF
                </button>
              </div>
              <div class="col-md-3">
                <button class="btn btn-primary w-100" onclick="emailReport()">
                  <i class="bi bi-envelope me-1"></i> ส่งทางอีเมล
                </button>
              </div>
              <div class="col-md-3">
                <button class="btn btn-info w-100" onclick="scheduleReport()">
                  <i class="bi bi-clock me-1"></i> ตั้งเวลาส่งอัตโนมัติ
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Footer -->
<footer class="footer">© <?= date('Y') ?> สหกรณ์ปั๊มน้ำมัน — รายงานและสถิติ</footer>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
  <div id="liveToast" class="toast align-items-center text-bg-dark border-0" role="status" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">กำลังสร้างรายงาน...</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="ปิด"></button>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const $ = (s, p=document)=>p.querySelector(s);

// Data from PHP
const dailySalesData = <?= json_encode($daily_sales, JSON_UNESCAPED_UNICODE) ?>;
const monthlyFinanceData = <?= json_encode($monthly_finance, JSON_UNESCAPED_UNICODE) ?>;
const fuelStockData = <?= json_encode($fuel_stock, JSON_UNESCAPED_UNICODE) ?>;
const fuelSalesDistributionData = <?= json_encode($fuel_sales_distribution, JSON_UNESCAPED_UNICODE) ?>;

// Toast helper
const toast = (msg, success=true)=>{
  const t = $('#liveToast');
  t.className = `toast align-items-center border-0 ${success ? 'text-bg-success' : 'text-bg-danger'}`;
  t.querySelector('.toast-body').textContent = msg || 'กำลังสร้างรายงาน...';
  bootstrap.Toast.getOrCreateInstance(t, { delay: 2000 }).show();
};

// Chart Colors
const colors = {
  primary: '#0d6efd',
  success: '#198754',
  warning: '#ffc107',
  danger:  '#dc3545',
  info:    '#0dcaf0',
  purple:  '#6f42c1',
  teal:    '#20c997',
  orange:  '#fd7e14'
};
const palette = [colors.primary, colors.success, colors.warning, colors.info, colors.purple, colors.teal, colors.orange, colors.danger];

// Helpers
function humanMoney(v){
  const n = Number(v||0);
  if (n >= 1e9) return (n/1e9).toFixed(1)+'B';
  if (n >= 1e6) return (n/1e6).toFixed(1)+'M';
  if (n >= 1e3) return (n/1e3).toFixed(1)+'K';
  return n.toLocaleString('th-TH');
}
function monthLabel(ym){
  if (!ym || typeof ym !== 'string' || ym.indexOf('-')<0) return '';
  const [year, month] = ym.split('-');
  if (!year || !month) return '';
  return `${month}/${String(year).slice(-2)}`;
}

// Daily Sales Chart
const dailySalesChart = new Chart($('#dailySalesChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: dailySalesData.map(d => new Date(d.date).toLocaleDateString('th-TH', {day: 'numeric', month: 'short'})),
    datasets: [{
      label: 'รายได้ (บาท)',
      data: dailySalesData.map(d => d.revenue),
      backgroundColor: colors.success,
      borderColor: colors.success,
      borderWidth: 1,
      borderRadius: 4
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { callback: (v)=>'฿'+humanMoney(v) } } }
  }
});

// Fuel Type Distribution Chart
const fuelTypeChart = new Chart($('#fuelTypeChart').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: fuelSalesDistributionData.map(d => d.fuel_name),
    datasets: [{
      data: fuelSalesDistributionData.map(d => d.total_liters),
      backgroundColor: fuelSalesDistributionData.map((_,i)=> palette[i % palette.length]),
      borderWidth: 2,
      borderColor: '#fff'
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '60%',
    plugins: { legend: { position: 'bottom' } }
  }
});

// Monthly Finance Chart (bar)
const monthlyFinanceChart = new Chart($('#monthlyFinanceChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: monthlyFinanceData.map(d => monthLabel(d.month || '')),
    datasets: [
      { label: 'รายได้',    data: monthlyFinanceData.map(d => d.income),  backgroundColor: colors.success, borderRadius: 4 },
      { label: 'ค่าใช้จ่าย', data: monthlyFinanceData.map(d => d.expense), backgroundColor: colors.danger,  borderRadius: 4 }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: { y: { beginAtZero: true, ticks: { callback: (v)=>'฿'+humanMoney(v) } } }
  }
});

// Stock Status Chart
const stockStatusChart = new Chart($('#stockStatusChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: fuelStockData.map(d => d.fuel_type),
    datasets: [{
      label: 'เปอร์เซ็นต์ความเต็ม',
      data: fuelStockData.map(d => {
        const pct = d.capacity>0 ? (d.current_stock / d.capacity) * 100 : 0;
        return Math.max(0, Math.min(100, pct));
      }),
      backgroundColor: fuelStockData.map(d => {
        const pct = d.capacity>0 ? (d.current_stock / d.capacity) * 100 : 0;
        return pct <= 25 ? colors.danger : pct <= 50 ? colors.warning : colors.success;
      }),
      borderRadius: 4
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y',
    plugins: { legend: { display: false } },
    scales: {
      x: { beginAtZero: true, max: 100, ticks: { callback: (v)=>v + '%' } }
    }
  }
});

// Financial Trend Chart (line)
const financialTrendChart = new Chart($('#financialTrendChart').getContext('2d'), {
  type: 'line',
  data: {
    labels: monthlyFinanceData.map(d => monthLabel(d.month || '')),
    datasets: [
      { label:'รายได้',   data: monthlyFinanceData.map(d => d.income),  borderColor: colors.success, backgroundColor: colors.success + '20', tension: .4, fill: false },
      { label:'ค่าใช้จ่าย', data: monthlyFinanceData.map(d => d.expense), borderColor: colors.danger,  backgroundColor: colors.danger  + '20', tension: .4, fill: false },
      { label:'กำไร',     data: monthlyFinanceData.map(d => d.profit),  borderColor: colors.primary, backgroundColor: colors.primary + '20', tension: .4, fill: true }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: { y: { beginAtZero: true, ticks: { callback: (v)=>'฿'+humanMoney(v) } } }
  }
});

// === Minimal handlers (ไม่ให้ปุ่ม error และแสดง Toast) ===
function generateReport() {
  const s = document.getElementById('startDate')?.value;
  const e = document.getElementById('endDate')?.value;
  const t = document.getElementById('reportType')?.value || 'all';
  toast(`กำลังสร้างรายงาน: ${t} (${s||'-'} ถึง ${e||'-'})`, true);
  // TODO: POST ไป endpoint เพื่อ query ช่วงใหม่ แล้วอัปเดตกำหนดกราฟ
}
function exportSalesReport(type) { toast(`ส่งออกยอดขายแบบ ${String(type||'').toUpperCase()}`, true); }
function exportInventoryReport() { toast('ส่งออกสต็อกเป็น CSV/XLSX', true); }
function exportAllReports(fmt)   { toast(`ส่งออกทั้งหน้าตาเป็น ${String(fmt||'').toUpperCase()}`, true); }
function emailReport()           { toast('ส่งรายงานทางอีเมล (โปรดตั้งค่า SMTP ในระบบ)', true); }
function scheduleReport()        { toast('ตั้งเวลาส่งรายงานอัตโนมัติ (ใช้ CRON + endpoint)', true); }
</script>
</body>
</html>
