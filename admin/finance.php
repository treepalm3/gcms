<?php
// admin/finance.php — จัดการการเงินและบัญชี (Modern UI/UX Version)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) { header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit(); }

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ'); }

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
  $current_name = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
  $current_role = $_SESSION['role'] ?? '';
  if ($current_role !== 'admin') { header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit(); }
} catch (Throwable $e) { header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit(); }
 
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* ===== Helpers ===== */
if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  }
}
if (!function_exists('column_exists')) {
  function column_exists(PDO $pdo, string $table, string $col): bool {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:col');
    $st->execute([':db'=>$db, ':tb'=>$table, ':col'=>$col]);
    return (int)$st->fetchColumn() > 0;
  }
}
function nf($n, $d=2){ return number_format((float)$n, $d, '.', ','); }
function ymd($s){ $t=strtotime($s); return $t? date('Y-m-d',$t) : null; }

/* ===== ค่าพื้นฐาน ===== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  $st = $pdo->query("SELECT site_name FROM settings WHERE id=1");
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) $site_name = $r['site_name'] ?: $site_name;
} catch (Throwable $e) {}
 
$stationId = 1;
try {
  if (table_exists($pdo,'settings')) {
    $sid = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1")->fetchColumn();
    if ($sid !== false) $stationId = (int)$sid;
  }
} catch (Throwable $e) {}
 
/* ===== กำหนดช่วงวันที่ "ส่วนกลางของทั้งหน้า" ===== */
$quick = $_GET['gp_quick'] ?? '';
$in_from = ymd($_GET['gp_from'] ?? '');
$in_to   = ymd($_GET['gp_to']   ?? '');

$today = new DateTime('today');
$from = null; $to = null;

if ($in_from && $in_to) {
    $from = new DateTime($in_from);
    $to = new DateTime($in_to);
} else {
    switch ($quick) {
      case 'today':      $from=$today; $to=clone $today; break;
      case 'yesterday':  $from=(clone $today)->modify('-1 day'); $to=(clone $today)->modify('-1 day'); break;
      case '30d':        $from=(clone $today)->modify('-29 day'); $to=$today; break;
      case 'this_month': $from=new DateTime(date('Y-m-01')); $to=$today; break;
      case 'last_month': $from=new DateTime(date('Y-m-01', strtotime('first day of last month'))); $to=new DateTime(date('Y-m-t', strtotime('last day of last month'))); break;
      case 'this_year':  $from=new DateTime(date('Y-01-01')); $to=$today; break;
      case 'all':        $from=null; $to=null; break;
      default:           $from=(clone $today)->modify('-6 day'); $to=$today; $quick = '7d';
    }
}
if ($from && $to && $to < $from) { $tmp=$from; $from=$to; $to=$tmp; }
if ($from && $to) {
  $diffDays = (int)$from->diff($to)->format('%a');
  if ($diffDays > 366) { $from=(clone $to)->modify('-366 day'); }
}
$rangeFromStr = $from ? $from->format('Y-m-d') : null;
$rangeToStr   = $to   ? $to->format('Y-m-d')   : null;
 
/* ===== ธงโหมด ===== */
$has_ft  = table_exists($pdo,'financial_transactions');
$has_gpv = table_exists($pdo,'v_sales_gross_profit');
if ($has_gpv) {
  try {
    $test = $pdo->query("SELECT COUNT(*) FROM v_sales_gross_profit LIMIT 1")->fetchColumn();
    if ($test === false) $has_gpv = false;
  } catch (Throwable $e) {
    $has_gpv = false;
  }
}
$ft_has_station   = $has_ft && column_exists($pdo,'financial_transactions','station_id');
$has_sales_station= column_exists($pdo,'sales','station_id');
$has_fr_station   = column_exists($pdo,'fuel_receives','station_id');

/* ===== ดึงธุรกรรมและสรุป ===== */
$transactions = [];
$categories   = ['income'=>[],'expense'=>[]];
$total_income = 0.0; $total_expense = 0.0; $net_profit = 0.0; $total_transactions = 0;

try {
  if ($has_ft) {
    $w = 'WHERE 1=1'; $p=[];
    if ($ft_has_station) { $w .= " AND ft.station_id = :sid"; $p[':sid'] = $stationId; }
    if ($rangeFromStr) { $w.=" AND DATE(ft.transaction_date) >= :f"; $p[':f']=$rangeFromStr; }
    if ($rangeToStr)   { $w.=" AND DATE(ft.transaction_date) <= :t"; $p[':t']=$rangeToStr; }

    $stmt = $pdo->prepare("
      SELECT COALESCE(ft.transaction_code, CONCAT('FT-', ft.id)) AS id,
             ft.transaction_date AS date, ft.type, ft.category, ft.description,
             ft.amount, ft.reference_id AS reference, COALESCE(u.full_name,'-') AS created_by
      FROM financial_transactions ft
      LEFT JOIN users u ON u.id = ft.user_id
      $w
      ORDER BY ft.transaction_date DESC, ft.id DESC
      LIMIT 500
    ");
    $stmt->execute($p);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $catSql = "SELECT type, GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR '||') cats
               FROM financial_transactions ".($ft_has_station?" WHERE station_id=:sid ":"")."
               GROUP BY type";
    $catStmt = $pdo->prepare($catSql);
    if ($ft_has_station) $catStmt->execute([':sid'=>$stationId]); else $catStmt->execute();
    $cats = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $categories['income']  = isset($cats['income'])  ? explode('||',$cats['income'])  : [];
    $categories['expense'] = isset($cats['expense']) ? explode('||',$cats['expense']) : [];

    $sumSql = "SELECT COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) ti,
                      COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) te,
                      COUNT(*) cnt
               FROM financial_transactions ft $w";
    $sum = $pdo->prepare($sumSql);
    $sum->execute($p);
    $s = $sum->fetch(PDO::FETCH_ASSOC);
    $total_income = (float)$s['ti'];
    $total_expense = (float)$s['te'];
    $total_transactions = (int)$s['cnt'];
    $net_profit = $total_income - $total_expense;
  }
} catch (Throwable $e) {
  error_log("Finance error: " . $e->getMessage());
}

$canEdit = ($current_role === 'admin');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>การเงินและบัญชี | <?= htmlspecialchars($site_name) ?></title>
  
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  
  <style>
    :root {
      --primary: #667eea;
      --primary-dark: #5568d3;
      --success: #10b981;
      --danger: #ef4444;
      --warning: #f59e0b;
      --info: #3b82f6;
      --dark: #1f2937;
      --light-bg: #f8fafc;
      --border: #e5e7eb;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
      --shadow-md: 0 4px 6px rgba(0,0,0,0.07);
      --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
    }
    
    * { font-family: 'Prompt', sans-serif; }
    body { background: var(--light-bg); color: var(--dark); }
    
    /* Navbar */
    .navbar-custom {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      box-shadow: var(--shadow-md);
      padding: 1rem 0;
    }
    .navbar-brand { font-weight: 700; font-size: 1.25rem; }
    
    /* Stats Cards */
    .stat-card {
      border: none;
      border-radius: 16px;
      padding: 1.5rem;
      box-shadow: var(--shadow-sm);
      transition: all 0.3s ease;
      height: 100%;
      position: relative;
      overflow: hidden;
    }
    .stat-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
    }
    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 100px;
      height: 100px;
      border-radius: 50%;
      opacity: 0.1;
      transform: translate(30%, -30%);
    }
    .stat-card.income { border-left: 4px solid var(--success); }
    .stat-card.income::before { background: var(--success); }
    .stat-card.expense { border-left: 4px solid var(--danger); }
    .stat-card.expense::before { background: var(--danger); }
    .stat-card.profit { border-left: 4px solid var(--primary); }
    .stat-card.profit::before { background: var(--primary); }
    .stat-card.count { border-left: 4px solid var(--info); }
    .stat-card.count::before { background: var(--info); }
    
    .stat-icon {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.75rem;
      margin-bottom: 1rem;
    }
    .stat-card.income .stat-icon { background: rgba(16, 185, 129, 0.1); color: var(--success); }
    .stat-card.expense .stat-icon { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
    .stat-card.profit .stat-icon { background: rgba(102, 126, 234, 0.1); color: var(--primary); }
    .stat-card.count .stat-icon { background: rgba(59, 130, 246, 0.1); color: var(--info); }
    
    .stat-label {
      font-size: 0.875rem;
      color: #6b7280;
      font-weight: 500;
      margin-bottom: 0.5rem;
    }
    .stat-value {
      font-size: 1.75rem;
      font-weight: 700;
      margin: 0;
    }
    
    /* Filter Section */
    .filter-section {
      background: white;
      border-radius: 16px;
      padding: 1.5rem;
      box-shadow: var(--shadow-sm);
      margin-bottom: 1.5rem;
    }
    .filter-section .section-title {
      font-size: 1rem;
      font-weight: 600;
      color: var(--dark);
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    /* Quick Filter Buttons */
    .quick-filter-group {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
    }
    .quick-filter-btn {
      padding: 0.5rem 1rem;
      border: 2px solid var(--border);
      border-radius: 8px;
      background: white;
      color: var(--dark);
      font-weight: 500;
      font-size: 0.875rem;
      transition: all 0.2s;
      cursor: pointer;
    }
    .quick-filter-btn:hover {
      border-color: var(--primary);
      color: var(--primary);
      background: rgba(102, 126, 234, 0.05);
    }
    .quick-filter-btn.active {
      background: var(--primary);
      border-color: var(--primary);
      color: white;
    }
    
    /* Custom Inputs */
    .form-control, .form-select {
      border-radius: 8px;
      border: 2px solid var(--border);
      padding: 0.625rem 1rem;
      transition: all 0.2s;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    /* Buttons */
    .btn {
      border-radius: 8px;
      padding: 0.625rem 1.25rem;
      font-weight: 500;
      transition: all 0.2s;
    }
    .btn-primary {
      background: var(--primary);
      border-color: var(--primary);
    }
    .btn-primary:hover {
      background: var(--primary-dark);
      border-color: var(--primary-dark);
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }
    .btn-success { background: var(--success); border-color: var(--success); }
    .btn-danger { background: var(--danger); border-color: var(--danger); }
    .btn-outline-primary {
      color: var(--primary);
      border-color: var(--primary);
    }
    .btn-outline-primary:hover {
      background: var(--primary);
      border-color: var(--primary);
    }
    
    /* Table */
    .table-container {
      background: white;
      border-radius: 16px;
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }
    .table-header {
      padding: 1.5rem;
      border-bottom: 1px solid var(--border);
    }
    .table-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--dark);
      margin: 0;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    
    .table {
      margin: 0;
    }
    .table thead th {
      background: var(--light-bg);
      color: var(--dark);
      font-weight: 600;
      font-size: 0.875rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border: none;
      padding: 1rem;
    }
    .table tbody tr {
      transition: background 0.2s;
      border-bottom: 1px solid var(--border);
    }
    .table tbody tr:hover {
      background: rgba(102, 126, 234, 0.02);
    }
    .table tbody td {
      padding: 1rem;
      vertical-align: middle;
    }
    
    /* Badges */
    .badge {
      padding: 0.375rem 0.75rem;
      border-radius: 6px;
      font-weight: 500;
      font-size: 0.813rem;
    }
    .badge-income {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
    }
    .badge-expense {
      background: rgba(239, 68, 68, 0.1);
      color: var(--danger);
    }
    
    /* Action Buttons */
    .btn-action {
      width: 32px;
      height: 32px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 6px;
      border: none;
      transition: all 0.2s;
    }
    .btn-action:hover {
      transform: scale(1.1);
    }
    .btn-action.btn-edit {
      background: rgba(59, 130, 246, 0.1);
      color: var(--info);
    }
    .btn-action.btn-delete {
      background: rgba(239, 68, 68, 0.1);
      color: var(--danger);
    }
    .btn-action.btn-view {
      background: rgba(102, 126, 234, 0.1);
      color: var(--primary);
    }
    
    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      color: #9ca3af;
    }
    .empty-state i {
      font-size: 4rem;
      margin-bottom: 1rem;
      opacity: 0.3;
    }
    .empty-state h5 {
      color: #6b7280;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .stat-card { margin-bottom: 1rem; }
      .filter-section { padding: 1rem; }
      .table-container { border-radius: 12px; }
      .table-header { padding: 1rem; }
      .table thead th, .table tbody td { padding: 0.75rem 0.5rem; font-size: 0.875rem; }
      .quick-filter-btn { padding: 0.375rem 0.75rem; font-size: 0.813rem; }
    }
    
    /* Loading Overlay */
    .loading-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }
    .loading-overlay.active { display: flex; }
    .loading-spinner {
      width: 50px;
      height: 50px;
      border: 4px solid rgba(255,255,255,0.3);
      border-top-color: white;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  </style>
</head>
<body>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="loading-spinner"></div>
</div>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-custom mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">
      <i class="bi bi-currency-exchange me-2"></i><?= htmlspecialchars($site_name) ?>
    </a>
    <div class="d-flex align-items-center text-white">
      <i class="bi bi-person-circle me-2"></i>
      <span class="d-none d-md-inline"><?= htmlspecialchars($current_name) ?></span>
    </div>
  </div>
</nav>

<div class="container-fluid px-3 px-md-4">
  
  <!-- Stats Cards -->
  <div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-lg-3">
      <div class="stat-card income">
        <div class="stat-icon">
          <i class="bi bi-arrow-down-circle-fill"></i>
        </div>
        <div class="stat-label">รายรับทั้งหมด</div>
        <h3 class="stat-value text-success">฿<?= nf($total_income) ?></h3>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
      <div class="stat-card expense">
        <div class="stat-icon">
          <i class="bi bi-arrow-up-circle-fill"></i>
        </div>
        <div class="stat-label">รายจ่ายทั้งหมด</div>
        <h3 class="stat-value text-danger">฿<?= nf($total_expense) ?></h3>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
      <div class="stat-card profit">
        <div class="stat-icon">
          <i class="bi bi-wallet2"></i>
        </div>
        <div class="stat-label">กำไรสุทธิ</div>
        <h3 class="stat-value" style="color: var(--primary)">฿<?= nf($net_profit) ?></h3>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
      <div class="stat-card count">
        <div class="stat-icon">
          <i class="bi bi-receipt"></i>
        </div>
        <div class="stat-label">จำนวนรายการ</div>
        <h3 class="stat-value text-info"><?= number_format($total_transactions) ?></h3>
      </div>
    </div>
  </div>

  <!-- Filter Section -->
  <div class="filter-section">
    <div class="section-title">
      <i class="bi bi-funnel"></i>
      <span>ตัวกรองข้อมูล</span>
    </div>
    
    <!-- Quick Filters -->
    <div class="mb-3">
      <div class="quick-filter-group">
        <button class="quick-filter-btn <?= $quick==='today'?'active':'' ?>" onclick="location.href='?gp_quick=today'">
          <i class="bi bi-calendar-day me-1"></i>วันนี้
        </button>
        <button class="quick-filter-btn <?= $quick==='yesterday'?'active':'' ?>" onclick="location.href='?gp_quick=yesterday'">
          เมื่อวาน
        </button>
        <button class="quick-filter-btn <?= $quick==='7d'?'active':'' ?>" onclick="location.href='?gp_quick=7d'">
          7 วัน
        </button>
        <button class="quick-filter-btn <?= $quick==='30d'?'active':'' ?>" onclick="location.href='?gp_quick=30d'">
          30 วัน
        </button>
        <button class="quick-filter-btn <?= $quick==='this_month'?'active':'' ?>" onclick="location.href='?gp_quick=this_month'">
          <i class="bi bi-calendar-month me-1"></i>เดือนนี้
        </button>
        <button class="quick-filter-btn <?= $quick==='this_year'?'active':'' ?>" onclick="location.href='?gp_quick=this_year'">
          <i class="bi bi-calendar me-1"></i>ปีนี้
        </button>
        <button class="quick-filter-btn <?= $quick==='all'?'active':'' ?>" onclick="location.href='?gp_quick=all'">
          ทั้งหมด
        </button>
      </div>
    </div>
    
    <!-- Custom Date Range -->
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label small text-muted mb-1">วันที่เริ่มต้น</label>
        <input type="date" class="form-control" name="gp_from" value="<?= $rangeFromStr ?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small text-muted mb-1">วันที่สิ้นสุด</label>
        <input type="date" class="form-control" name="gp_to" value="<?= $rangeToStr ?>">
      </div>
      <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-primary w-100">
          <i class="bi bi-search me-2"></i>ค้นหา
        </button>
      </div>
    </form>
  </div>

  <!-- Actions Bar -->
  <div class="d-flex flex-wrap gap-2 mb-3 align-items-center justify-content-between">
    <div class="d-flex flex-wrap gap-2">
      <?php if ($canEdit): ?>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddTransaction">
        <i class="bi bi-plus-circle me-2"></i>เพิ่มรายการ
      </button>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-success" id="btnExportCSV">
        <i class="bi bi-file-earmark-excel me-2"></i>Export CSV
      </button>
      <button class="btn btn-outline-primary" id="btnPrint">
        <i class="bi bi-printer me-2"></i>พิมพ์
      </button>
    </div>
  </div>

  <!-- Table Filters -->
  <div class="filter-section">
    <div class="row g-2">
      <div class="col-12 col-md-3">
        <input type="text" class="form-control" id="txnSearch" placeholder="🔍 ค้นหา...">
      </div>
      <div class="col-12 col-md-3">
        <select class="form-select" id="filterType">
          <option value="">ทุกประเภท</option>
          <option value="income">รายรับ</option>
          <option value="expense">รายจ่าย</option>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <select class="form-select" id="filterCategory">
          <option value="">ทุกหมวดหมู่</option>
          <?php foreach (array_unique(array_merge($categories['income'], $categories['expense'])) as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <button class="btn btn-outline-primary w-100" id="btnTxnShowAll">
          <i class="bi bi-arrow-counterclockwise me-2"></i>ล้างตัวกรอง
        </button>
      </div>
    </div>
  </div>

  <!-- Transactions Table -->
  <div class="table-container">
    <div class="table-header">
      <h4 class="table-title">
        <i class="bi bi-list-ul"></i>
        รายการธุรกรรมทั้งหมด
      </h4>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="txnTable">
        <thead>
          <tr>
            <th style="width: 120px;">วันที่</th>
            <th style="width: 120px;">รหัส</th>
            <th style="width: 100px;">ประเภท</th>
            <th>หมวดหมู่</th>
            <th>รายละเอียด</th>
            <th class="text-end" style="width: 150px;">จำนวนเงิน</th>
            <th style="width: 120px;">อ้างอิง</th>
            <?php if ($canEdit): ?>
            <th class="text-center" style="width: 120px;">จัดการ</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($transactions)): ?>
          <tr>
            <td colspan="<?= $canEdit ? 8 : 7 ?>" class="text-center">
              <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h5>ไม่มีรายการธุรกรรม</h5>
                <p class="mb-0">ลองเปลี่ยนช่วงเวลาหรือเพิ่มรายการใหม่</p>
              </div>
            </td>
          </tr>
          <?php else: ?>
            <?php foreach ($transactions as $txn): 
              $dt = new DateTime($txn['date']);
              $dateDisplay = $dt->format('d/m/Y');
              $timeDisplay = $dt->format('H:i');
            ?>
            <tr data-id="<?= htmlspecialchars($txn['id']) ?>"
                data-date="<?= htmlspecialchars($txn['date']) ?>"
                data-type="<?= htmlspecialchars($txn['type']) ?>"
                data-category="<?= htmlspecialchars($txn['category']) ?>"
                data-description="<?= htmlspecialchars($txn['description']) ?>"
                data-amount="<?= htmlspecialchars($txn['amount']) ?>"
                data-reference="<?= htmlspecialchars($txn['reference']) ?>"
                data-created-by="<?= htmlspecialchars($txn['created_by']) ?>">
              <td>
                <div><?= $dateDisplay ?></div>
                <small class="text-muted"><?= $timeDisplay ?></small>
              </td>
              <td>
                <span class="badge bg-secondary"><?= htmlspecialchars($txn['id']) ?></span>
              </td>
              <td>
                <?php if ($txn['type'] === 'income'): ?>
                  <span class="badge badge-income">
                    <i class="bi bi-arrow-down-circle me-1"></i>รายรับ
                  </span>
                <?php else: ?>
                  <span class="badge badge-expense">
                    <i class="bi bi-arrow-up-circle me-1"></i>รายจ่าย
                  </span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($txn['category']) ?></td>
              <td><?= htmlspecialchars($txn['description']) ?></td>
              <td class="text-end">
                <strong class="<?= $txn['type']==='income'?'text-success':'text-danger' ?>">
                  ฿<?= nf($txn['amount']) ?>
                </strong>
              </td>
              <td>
                <?php if ($txn['reference']): ?>
                  <span class="badge bg-light text-dark"><?= htmlspecialchars($txn['reference']) ?></span>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <?php if ($canEdit): ?>
              <td class="text-center">
                <button class="btn-action btn-edit btnEdit" title="แก้ไข">
                  <i class="bi bi-pencil"></i>
                </button>
                <button class="btn-action btn-delete btnDel" title="ลบ">
                  <i class="bi bi-trash"></i>
                </button>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
  'use strict';
  
  const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
  
  // ===== ฟิลเตอร์ =====
  const q = document.getElementById('txnSearch');
  const fType = document.getElementById('filterType');
  const fCat  = document.getElementById('filterCategory');
  const tbody = document.querySelector('#txnTable tbody');
  
  function normalize(s){ return (s||'').toString().toLowerCase(); }
  
  function applyFilters(){
    const text = q ? normalize(q.value) : '';
    const type = fType ? fType.value : '';
    const cat  = fCat ? fCat.value : '';
    
    tbody.querySelectorAll('tr').forEach(tr=>{
      const d = tr.dataset;
      if (!d.id) return; // Skip empty state row
      
      let ok = true;
      if (type && d.type !== type) ok = false;
      if (cat  && d.category !== cat) ok = false;
      if (text) {
        const blob = (d.id+' '+(d.category||'')+' '+(d.description||'')+' '+(d.reference||'')+' '+(d.createdBy||'')).toLowerCase();
        if (!blob.includes(text)) ok = false;
      }
      tr.style.display = ok? '' : 'none';
    });
  }
  
  [q, fType, fCat].forEach(el => el && el.addEventListener('input', applyFilters));
  
  document.getElementById('btnTxnShowAll')?.addEventListener('click', ()=>{
    if (q) q.value = '';
    if (fType) fType.value = '';
    if (fCat) fCat.value = '';
    applyFilters();
  });
  
  // ===== Export CSV =====
  function exportCSV(){
    const rows = [['วันที่','รหัส','ประเภท','หมวดหมู่','รายละเอียด','จำนวนเงิน','อ้างอิง','ผู้บันทึก']];
    tbody.querySelectorAll('tr').forEach(tr=>{
      if (tr.style.display==='none' || !tr.dataset.id) return;
      const d = tr.dataset;
      const date = new Date(d.date);
      const dd = String(date.getDate()).padStart(2,'0')+'/'+String(date.getMonth()+1).padStart(2,'0')+'/'+date.getFullYear();
      const amt = parseFloat(d.amount||'0');
      rows.push([
        dd, 
        d.id, 
        d.type==='income'?'รายรับ':'รายจ่าย', 
        d.category||'', 
        d.description||'', 
        amt.toFixed(2), 
        d.reference||'', 
        d.createdBy||''
      ]);
    });
    const csv = rows.map(r=>r.map(x=>`"${String(x).replace(/"/g,'""')}"`).join(',')).join('\r\n');
    const blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'finance_export.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  }
  
  document.getElementById('btnExportCSV')?.addEventListener('click', exportCSV);
  document.getElementById('btnPrint')?.addEventListener('click', ()=>window.print());
  
  // ===== Edit & Delete Handlers =====
  if (canEdit) {
    document.querySelectorAll('#txnTable .btnEdit').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const tr = btn.closest('tr');
        const d = tr.dataset;
        alert('แก้ไขรายการ: ' + d.id + '\n(เชื่อมต่อกับโมดัลของคุณได้)');
      });
    });
    
    document.querySelectorAll('#txnTable .btnDel').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const tr = btn.closest('tr');
        const d = tr.dataset;
        if (confirm('ต้องการลบรายการ ' + d.id + ' ใช่หรือไม่?')) {
          alert('ลบรายการ: ' + d.id + '\n(เชื่อมต่อกับ API ลบของคุณได้)');
        }
      });
    });
  }
  
  // ===== Initial Filter =====
  if (tbody) applyFilters();
  
})();
</script>

</body>
</html>
