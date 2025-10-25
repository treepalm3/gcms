<?php
// committee/dividend.php — [ปรับปรุง] หน้าปันผลสำหรับกรรมการ (อ่านข้อมูล + รายงาน/ส่งออก)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== Guard & DB =====
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit();
}
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== Role check =====
try {
  $current_name = $_SESSION['full_name'] ?? 'กรรมการ';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'committee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}
// CSRF token not strictly needed for read-only, but kept for consistency
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

// ===== Helpers =====
if (!function_exists('nf')) {
    function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
}
if (!function_exists('d')) {
    function d($s, $fmt = 'd/m/Y') {
        if (empty($s) || $s === '0000-00-00' || $s === '0000-00-00 00:00:00') return '-'; // [ปรับปรุง] Handle zero dates
        $t = strtotime($s); 
        return $t ? date($fmt, $t) : '-';
    }
}
if (!function_exists('table_exists')) { // [เพิ่ม] Check function exists before declaring
    function table_exists(PDO $pdo, string $table): bool {
      try {
        $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb");
        $st->execute([':db'=>$db, ':tb'=>$table]);
        return (int)$st->fetchColumn() > 0;
      } catch (Throwable $e) { return false; }
    }
}

// ===== Site settings =====
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$stationId = 1; // Default station ID
try {
  $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) { 
      $stationId = (int)$r['setting_value'];
      $site_name = $r['comment'] ?: $site_name; 
  }
} catch (Throwable $e) {} // Ignore errors, use defaults

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',  'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ===== Initialize data variables =====
$dividend_periods = [];
$rebate_periods = [];
$members_dividends = []; // Use combined key 'type_id' e.g., 'member_123'
$error_message = null;
$total_dividend_paid = 0.0;
$pending_dividend = 0.0;
$total_rebate_paid = 0.0;
$pending_rebate = 0.0;
$total_members = 0;
$total_shares = 0;

// ===== Main Data Fetching Block =====
try {
    // 1) งวดปันผล (Dividend Periods)
    $dividend_periods = $pdo->query("
        SELECT id, `year`, start_date, end_date, period_name, total_profit, dividend_rate,
               total_shares_at_time, total_dividend_amount, status, payment_date, created_at, approved_by
        FROM dividend_periods
        ORDER BY `year` DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2) งวดเฉลี่ยคืน (Rebate Periods)
    if (table_exists($pdo, 'rebate_periods')) {
        $rebate_periods = $pdo->query("
            SELECT id, `year`, start_date, end_date, period_name, total_purchase_amount, total_rebate_budget, 
                   rebate_type, rebate_value, rebate_per_baht, status, payment_date
            FROM rebate_periods
            ORDER BY `year` DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rebate_periods = []; // Ensure it's an array if table doesn't exist
    }

    // 3) รวมสมาชิกทุกประเภท (Members, Managers, Committees) using UNION ALL
    $all_members_stmt = $pdo->query("
        (SELECT 
            m.id, u.id as user_id, m.member_code, u.full_name, m.shares, 'member' AS member_type, m.house_number 
        FROM members m JOIN users u ON m.user_id = u.id WHERE m.is_active = 1)
        UNION ALL
        (SELECT 
            mg.id, u.id as user_id, CONCAT('MGR-', mg.id) AS member_code, u.full_name, mg.shares, 'manager' AS member_type, mg.house_number 
        FROM managers mg JOIN users u ON mg.user_id = u.id)
        UNION ALL
        (SELECT 
            c.id, u.id as user_id, c.committee_code AS member_code, u.full_name, c.shares, 'committee' AS member_type, c.house_number 
        FROM committees c JOIN users u ON c.user_id = u.id)
    ");
    
    // Process members into the $members_dividends array
    while ($row = $all_members_stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['member_type'] . '_' . $row['id']; // e.g., 'member_1', 'manager_12'
        $members_dividends[$key] = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'code' => $row['member_code'],
            'member_name' => $row['full_name'],
            'shares' => (int)$row['shares'],
            'type' => $row['member_type'],
            'type_th' => $role_th_map[$row['member_type']] ?? 'อื่นๆ', // Use existing map
            'payments' => [], // period_id => amount
            'rebates' => [],  // period_id => amount
            'total_received' => 0.0,
            'total_rebate_received' => 0.0
        ];
    }
    $total_members = count($members_dividends);
    $total_shares = array_sum(array_column($members_dividends, 'shares'));

    // 4) จ่ายปันผลรายสมาชิก/งวด (Dividend Payments)
    if (table_exists($pdo, 'dividend_payments')) {
        $payments_stmt = $pdo->query("
            SELECT dp.member_id, dp.member_type, dp.period_id, dp.dividend_amount, dp.payment_status
            FROM dividend_payments dp
        ");
        foreach ($payments_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $key = $payment['member_type'] . '_' . $payment['member_id'];
            if (!isset($members_dividends[$key])) continue;
            $pid = (int)$payment['period_id'];
            $amt = (float)$payment['dividend_amount'];
            $members_dividends[$key]['payments'][$pid] = $amt;
            if (($payment['payment_status'] ?? 'pending') === 'paid') {
                $members_dividends[$key]['total_received'] += $amt;
            }
        }
    }
    
    // 5) จ่ายเฉลี่ยคืนรายสมาชิก/งวด (Rebate Payments)
    if (table_exists($pdo, 'rebate_payments')) {
        $rebate_payments_stmt = $pdo->query("
            SELECT rp.member_id, rp.member_type, rp.period_id, rp.rebate_amount, rp.payment_status
            FROM rebate_payments rp
        ");
        foreach ($rebate_payments_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $key = $payment['member_type'] . '_' . $payment['member_id'];
            if (!isset($members_dividends[$key])) continue;
            $pid = (int)$payment['period_id'];
            $amt = (float)$payment['rebate_amount'];
            $members_dividends[$key]['rebates'][$pid] = $amt;
            if (($payment['payment_status'] ?? 'pending') === 'paid') {
                $members_dividends[$key]['total_rebate_received'] += $amt;
            }
        }
    }

    // 6) Calculate Stats (Paid/Pending Dividends & Rebates)
    if (table_exists($pdo, 'dividend_periods')) {
        $stats = $pdo->query("
            SELECT 
              COALESCE(SUM(CASE WHEN status = 'paid' THEN total_dividend_amount ELSE 0 END), 0) AS total_paid,
              COALESCE(SUM(CASE WHEN status = 'approved' THEN total_dividend_amount ELSE 0 END), 0) AS total_pending
            FROM dividend_periods
        ")->fetch(PDO::FETCH_ASSOC);
        $total_dividend_paid = (float)($stats['total_paid'] ?? 0);
        $pending_dividend    = (float)($stats['total_pending'] ?? 0);
    }
  
    if (table_exists($pdo, 'rebate_periods')) {
        $rebate_stats = $pdo->query("
            SELECT
              COALESCE(SUM(CASE WHEN status = 'paid' THEN total_rebate_budget ELSE 0 END), 0) as total_paid,
              COALESCE(SUM(CASE WHEN status = 'approved' THEN total_rebate_budget ELSE 0 END), 0) as total_pending
            FROM rebate_periods
        ")->fetch(PDO::FETCH_ASSOC);
        $total_rebate_paid = (float)($rebate_stats['total_paid'] ?? 0);
        $pending_rebate = (float)($rebate_stats['total_pending'] ?? 0);
    }

} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    // Reset data arrays on major failure
    $dividend_periods = [];
    $rebate_periods = [];
    $members_dividends = [];
    $total_members = 0;
    $total_shares = 0;
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ปันผล | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    /* [ปรับปรุง] ใช้ CSS variables จาก admin_dashboard.css มากขึ้น */
    .stat-card h5 { font-size: 1rem; color: var(--bs-secondary-color); margin-bottom: 0.5rem; }
    .stat-card h3 { font-weight: 700; }
    
    .status-paid, .status-approved, .status-pending { padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; display: inline-block; }
    .status-paid { background-color: var(--bs-success-bg-subtle); color: var(--bs-success-text-emphasis); }
    .status-approved { background-color: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
    .status-pending { background-color: var(--bs-secondary-bg-subtle); color: var(--bs-secondary-text-emphasis); } /* [แก้ไข] Pending เป็น secondary */
    
    .dividend-card{ border:1px solid var(--bs-border-color-translucent); border-radius: var(--bs-card-border-radius); transition:.25s; background:#fff; box-shadow: var(--bs-box-shadow-sm); }
    .dividend-card:hover{ box-shadow: var(--bs-box-shadow); transform: translateY(-2px); } /* [แก้ไข] ใช้ shadow ปกติ */
    .dividend-amount{ font-size:1.5rem; font-weight:700; color: var(--bs-success); }
    .dividend-rate{ font-size:1.2rem; font-weight:600; color: var(--bs-primary); }
    .rebate-amount { font-size: 1.5rem; font-weight: 700; color: var(--bs-info-text-emphasis); }
    .rebate-rate { font-size: 1.2rem; font-weight: 600; color: var(--bs-info); }

    .member-row:hover{ background-color: rgba(var(--bs-primary-rgb), .05); } /* [แก้ไข] ใช้ rgba */
    .member-type-badge { font-size:.75rem; padding:.2rem .5rem; border-radius:12px; font-weight: 500;}
    .type-member { background-color: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); }
    .type-manager { background-color: var(--bs-info-bg-subtle); color: var(--bs-info-text-emphasis); }
    .type-committee { background-color: var(--bs-secondary-bg-subtle); color: var(--bs-secondary-text-emphasis); }

    /* [ลบ] .panel (ใช้ .stat-card) */
    .nav-tabs .nav-link { font-weight: 600; }
    .nav-tabs .nav-link.active { color: var(--bs-primary); border-bottom-color: var(--bs-primary); }
    .table th { white-space: nowrap; } /* Prevent header wrapping */
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
      <a class="navbar-brand" href="committee_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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
    <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
    <nav class="sidebar-menu">
      <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
      <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
    </nav>
    <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
      <div class="main-header mb-4">
        <h2><i class="fa-solid fa-gift me-2"></i> ปันผลและเฉลี่ยคืน</h2>
      </div>

      <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>เกิดข้อผิดพลาด:</strong> <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="stats-grid mb-4">
        <div class="stat-card text-center">
          <h5><i class="fa-solid fa-gift text-success"></i> ปันผลจ่ายแล้ว (หุ้น)</h5>
          <h3 class="text-success">฿<?= nf($total_dividend_paid, 2) ?></h3>
          <small class="text-warning">รอจ่าย: ฿<?= nf($pending_dividend, 2) ?></small>
        </div>
        <div class="stat-card text-center">
          <h5><i class="bi bi-arrow-repeat text-info"></i> เฉลี่ยคืนจ่ายแล้ว (ซื้อ)</h5>
          <h3 class="text-info">฿<?= nf($total_rebate_paid, 2) ?></h3>
          <small class="text-warning">รอจ่าย: ฿<?= nf($pending_rebate, 2) ?></small>
        </div>
        <div class="stat-card text-center">
          <h5><i class="bi bi-people-fill text-primary"></i> สมาชิกทั้งหมด</h5>
          <h3 class="text-primary"><?= nf($total_members, 0) ?> คน</h3>
          <small class="text-muted">หุ้นรวม <?= nf($total_shares, 0) ?> หุ้น</small>
        </div>
      </div>

      <ul class="nav nav-tabs mb-3" id="dividendTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#periods-panel" type="button" role="tab">
            <i class="fa-solid fa-calendar-days me-2"></i>งวดปันผล (หุ้น)
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rebate-panel" type="button" role="tab">
            <i class="bi bi-arrow-repeat me-2"></i>งวดเฉลี่ยคืน (ซื้อ)
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#members-panel" type="button" role="tab">
            <i class="bi bi-people-fill me-2"></i>สมาชิกและหุ้น
          </button>
        </li>
      </ul>

      <div class="tab-content pt-3" id="dividendTabContent">
        <div class="tab-pane fade show active" id="periods-panel" role="tabpanel">
          <div class="stat-card mb-3"> 
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                  <h5 class="mb-0 card-title"><i class="fa-solid fa-gift me-2 text-success"></i>งวดปันผลตามหุ้น</h5>
                  <button class="btn btn-sm btn-outline-secondary" onclick="exportDividends('dividend')">
                      <i class="bi bi-download me-1"></i> ส่งออกข้อมูล
                  </button>
              </div>
              <div>
                <div class="row g-3">
                  <?php if (empty($dividend_periods)): ?>
                      <div class="col-12"><div class="alert alert-light text-center border mb-0">ยังไม่มีงวดปันผล</div></div>
                  <?php else: ?>
                      <?php foreach($dividend_periods as $period): 
                          $status_map = ['paid'=>'จ่ายแล้ว','approved'=>'อนุมัติ','pending'=>'รออนุมัติ'];
                          $status_class_map = ['paid'=>'paid','approved'=>'approved','pending'=>'pending']; // Match CSS classes
                      ?>
                      <div class="col-md-6 col-lg-4">
                        <div class="dividend-card h-100 p-3">
                          <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                              <h6 class="card-title mb-1"><?= htmlspecialchars($period['year']) ?>: <?= htmlspecialchars($period['period_name']) ?></h6>
                              <small class="text-muted"><?= d($period['start_date']) ?> - <?= d($period['end_date']) ?></small>
                            </div>
                            <span class="status-<?= htmlspecialchars($status_class_map[$period['status']] ?? 'pending') ?>">
                              <?= htmlspecialchars($status_map[$period['status']] ?? '?') ?>
                            </span>
                          </div>
                          <div class="row text-center my-3">
                            <div class="col-6 border-end">
                              <small class="text-muted">อัตรา</small><br>
                              <span class="dividend-rate"><?= nf($period['dividend_rate'] ?? 0, 1) ?>%</span>
                            </div>
                            <div class="col-6">
                              <small class="text-muted">ยอดรวม</small><br>
                              <span class="dividend-amount">฿<?= nf($period['total_dividend_amount'] ?? 0, 0) ?></span>
                            </div>
                          </div>
                          <div class="d-flex flex-column gap-1 small text-muted border-top pt-2">
                            <div class="d-flex justify-content-between"><span>กำไรสุทธิ:</span> <span>฿<?= nf($period['total_profit'] ?? 0, 0) ?></span></div>
                            <div class="d-flex justify-content-between"><span>จำนวนหุ้น:</span> <span><?= nf($period['total_shares_at_time'] ?? 0, 0) ?> หุ้น</span></div>
                          </div>
                        </div>
                      </div>
                      <?php endforeach; ?>
                  <?php endif; ?>
                </div>
            </div>
          </div>
        </div>
        
        <div class="tab-pane fade" id="rebate-panel" role="tabpanel">
           <div class="stat-card mb-3">
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                  <h5 class="mb-0 card-title"><i class="bi bi-arrow-repeat me-2 text-info"></i>งวดเฉลี่ยคืนตามยอดซื้อ</h5>
                  <button class="btn btn-sm btn-outline-secondary" onclick="exportDividends('rebate')">
                      <i class="bi bi-download me-1"></i> ส่งออกข้อมูล
                  </button>
              </div>
              <div>
                <div class="row g-3">
                  <?php if (empty($rebate_periods)): ?>
                      <div class="col-12"><div class="alert alert-light text-center border mb-0">ยังไม่มีงวดเฉลี่ยคืน</div></div>
                  <?php else: ?>
                      <?php foreach($rebate_periods as $period): 
                          $status_map = ['paid'=>'จ่ายแล้ว','approved'=>'อนุมัติ','pending'=>'รออนุมัติ'];
                          $status_class_map = ['paid'=>'paid','approved'=>'approved','pending'=>'pending']; 
                      ?>
                      <div class="col-md-6 col-lg-4">
                        <div class="dividend-card h-100 p-3">
                          <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                              <h6 class="card-title mb-1"><?= htmlspecialchars($period['year']) ?>: <?= htmlspecialchars($period['period_name']) ?></h6>
                              <small class="text-muted"><?= d($period['start_date']) ?> - <?= d($period['end_date']) ?></small>
                            </div>
                            <span class="status-<?= htmlspecialchars($status_class_map[$period['status']] ?? 'pending') ?>">
                              <?= htmlspecialchars($status_map[$period['status']] ?? '?') ?>
                            </span>
                          </div>
                          <div class="row text-center my-3">
                            <div class="col-6 border-end">
                              <small class="text-muted">อัตรา/งบ</small><br>
                              <span class="rebate-rate">
                                <?php
                                  $rate_text = '-';
                                  if (($period['rebate_type'] ?? '') === 'fixed') { $rate_text = 'คงที่'; }
                                  elseif (!empty($period['rebate_per_baht']) && (float)$period['rebate_per_baht'] > 0) { $rate_text = '฿' . nf($period['rebate_per_baht'], 4) . '/บาท'; }
                                  elseif (!empty($period['rebate_value']) && (float)$period['rebate_value'] > 0) { $rate_text = nf($period['rebate_value'], 1) . '%'; }
                                  echo $rate_text;
                                ?>
                              </span>
                            </div>
                            <div class="col-6">
                              <small class="text-muted">ยอดรวม</small><br>
                              <span class="rebate-amount">฿<?= nf($period['total_rebate_budget'] ?? 0, 0) ?></span>
                            </div>
                          </div>
                          <div class="d-flex flex-column gap-1 small text-muted border-top pt-2">
                            <div class="d-flex justify-content-between"><span>ยอดซื้อรวม (ฐาน):</span> <span>฿<?= nf($period['total_purchase_amount'] ?? 0, 0) ?></span></div>
                            <?php if(!empty($period['payment_date'])): ?>
                            <div class="d-flex justify-content-between"><span>วันที่จ่าย:</span> <span><?= d($period['payment_date']) ?></span></div>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                      <?php endforeach; ?>
                  <?php endif; ?>
                </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="members-panel" role="tabpanel">
          <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <div class="d-flex flex-wrap gap-2">
              <div class="input-group" style="max-width:280px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" id="memberSearch" class="form-control" placeholder="ค้นหา รหัส/ชื่อ สมาชิก...">
              </div>
              <input type="number" id="minShares" class="form-control" placeholder="หุ้นขั้นต่ำ" min="0" style="max-width: 150px;">
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-primary" onclick="exportMembers()"><i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV</button>
            </div>
          </div>

          <div class="stat-card">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0" id="membersTable">
                <thead>
                  <tr>
                    <th>รหัส</th>
                    <th>ชื่อ</th>
                    <th class="text-center">ประเภท</th>
                    <th class="text-center">หุ้น</th>
                    <th class="text-end">รวมปันผล (หุ้น)</th>
                    <th class="text-end">รวมเฉลี่ยคืน (ซื้อ)</th>
                    <th class="text-end"></th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($members_dividends)): ?>
                    <tr><td colspan="7" class="text-center text-muted p-4">ไม่พบข้อมูลสมาชิก</td></tr>
                <?php else: ?>
                    <?php foreach($members_dividends as $key => $member): ?>
                      <tr class="member-row"
                          data-member-key="<?= htmlspecialchars($key) ?>"
                          data-member-name="<?= htmlspecialchars($member['member_name']) ?>"
                          data-shares="<?= (int)$member['shares'] ?>">
                        <td><b><?= htmlspecialchars($member['code']) ?></b></td>
                        <td><?= htmlspecialchars($member['member_name']) ?></td>
                        <td class="text-center">
                            <span class="member-type-badge type-<?= htmlspecialchars($member['type']) ?>"><?= htmlspecialchars($member['type_th']) ?></span>
                        </td>
                        <td class="text-center">
                          <span class="badge bg-secondary rounded-pill"><?= nf((int)$member['shares'], 0) ?> หุ้น</span>
                        </td>
                        <td class="text-end"><strong class="text-success">฿<?= nf($member['total_received'] ?? 0, 2) ?></strong></td>
                        <td class="text-end"><strong class="text-info">฿<?= nf($member['total_rebate_received'] ?? 0, 2) ?></strong></td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-outline-info py-0 px-1" title="ดูประวัติ" 
                                  onclick="viewMemberHistory('<?= htmlspecialchars($key) ?>', <?= (int)$member['id'] ?>, '<?= htmlspecialchars($member['type']) ?>')">
                            <i class="bi bi-clock-history"></i>
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        </div></main>
  </div>
</div>

<footer class="footer"> 
    <span class="text-muted">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>
</footer>

<div class="modal fade" id="modalMemberHistory" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"> <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>ประวัติการรับปันผล/เฉลี่ยคืน</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row mb-3 small">
          <div class="col-sm-4"><strong>รหัส:</strong> <span id="historyMemberCode">-</span></div>
          <div class="col-sm-5"><strong>ชื่อ:</strong> <span id="historyMemberName">-</span></div>
          <div class="col-sm-3"><strong>ประเภท:</strong> <span id="historyMemberType">-</span></div>
        </div>
        <div class="history-summary mb-3"></div>
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead class="table-light sticky-top"> <tr>
                <th>ปี</th>
                <th>ประเภท</th>
                <th class="text-end">รายละเอียด</th>
                <th class="text-end">ยอดเงิน</th>
                <th class="text-center">สถานะ</th>
              </tr>
            </thead>
            <tbody id="memberHistoryTable"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div>
    </div>
  </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
  <div id="liveToast" class="toast align-items-center text-bg-dark border-0" role="status" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">ดำเนินการสำเร็จ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="ปิด"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $ = (s, p=document)=>p.querySelector(s);
const $$ = (s, p=document)=>[...p.querySelectorAll(s)];

// [แก้ไข] ใช้ key จาก PHP ('type_id')
const membersData = <?= json_encode($members_dividends, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>; 
const dividendPeriodsData = <?= json_encode($dividend_periods, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const rebatePeriodsData = <?= json_encode($rebate_periods, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;

// [เพิ่ม] ฟังก์ชัน nf() เวอร์ชัน JavaScript
function nf(number, decimals = 2) {
    const num = parseFloat(number) || 0;
    return num.toLocaleString('th-TH', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// [เพิ่ม] Toast function
const toast = (msg, success=true)=>{
  const t = $('#liveToast');
  if (!t) return; // Add check if toast element exists
  const body = t.querySelector('.toast-body');
  t.className = `toast align-items-center border-0 ${success ? 'text-bg-success' : 'text-bg-danger'}`;
  if (body) body.textContent = msg || 'ดำเนินการสำเร็จ';
  bootstrap.Toast.getOrCreateInstance(t, { delay: 2000 }).show();
};

// ฟิลเตอร์สมาชิก
const memberSearch = $('#memberSearch');
const minShares = $('#minShares');
const membersTableBody = $('#membersTable tbody'); // [เพิ่ม] Cache tbody

function normalize(s){ return (s||'').toString().toLowerCase().trim(); }
function applyMemberFilter(){
  if (!membersTableBody) return; // [เพิ่ม] Check if table exists
  const k = normalize(memberSearch?.value);
  const minS = parseInt(minShares?.value || '0', 10);
  
  membersTableBody.querySelectorAll('tr.member-row').forEach(tr=>{ // [ปรับปรุง] Selector
    const searchText = normalize(`${tr.dataset.memberKey} ${tr.dataset.memberName}`);
    const shares = parseInt(tr.dataset.shares || '0', 10);
    const okK = !k || searchText.includes(k);
    const okS = isNaN(minS) || minS <= 0 ? true : shares >= minS; // Allow empty/0 min shares
    tr.style.display = (okK && okS) ? '' : 'none';
  });
}
memberSearch?.addEventListener('input', applyMemberFilter);
minShares?.addEventListener('input', applyMemberFilter);

// ประวัติสมาชิก
async function viewMemberHistory(memberKey, memberId, memberType) {
  // [แก้ไข] ใช้ membersData ที่เป็น object keyed by 'type_id'
  const member = membersData[memberKey]; 
  if (!member) { 
      toast('ไม่พบข้อมูลสมาชิก', false);
      return; 
  }

  $('#historyMemberCode').textContent = member.code;
  $('#historyMemberName').textContent = member.member_name;
  $('#historyMemberType').textContent = member.type_th;
  
  const historyTable = $('#memberHistoryTable');
  const summaryDiv = $('#modalMemberHistory .history-summary'); // [ปรับปรุง] Selector
  historyTable.innerHTML = '<tr><td colspan="5" class="text-center p-3"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';
  if(summaryDiv) summaryDiv.innerHTML = '';

  const modalInstance = bootstrap.Modal.getOrCreateInstance('#modalMemberHistory'); // [ปรับปรุง] Get instance
  modalInstance.show();

  try {
    // [หมายเหตุ] ใช้ fetch ไปยัง endpoint เดิม
    const response = await fetch(`dividend_member_history.php?member_id=${memberId}&member_type=${memberType}`);
    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
    const data = await response.json();

    if (data.ok && data.history.length > 0) {
        const summary = data.summary;
        if(summaryDiv) {
             summaryDiv.innerHTML = `
                <div class="alert alert-light border small mb-3">
                    <div class="row text-center g-2">
                        <div class="col-6 col-md-3"><small class="text-muted d-block">ปันผล(รับแล้ว)</small><strong class="text-success">${summary.total_received_formatted}</strong></div>
                        <div class="col-6 col-md-3"><small class="text-muted d-block">ปันผล(ค้างรับ)</small><strong class="text-warning">${summary.total_pending_formatted}</strong></div>
                        <div class="col-6 col-md-3"><small class="text-muted d-block">เฉลี่ยคืน(รับแล้ว)</small><strong class="text-info">${summary.total_rebate_received_formatted || '฿0.00'}</strong></div>
                        <div class="col-6 col-md-3"><small class="text-muted d-block">เฉลี่ยคืน(ค้างรับ)</small><strong class="text-warning">${summary.total_rebate_pending_formatted || '฿0.00'}</strong></div>
                    </div>
                </div>`;
        }

        let html = '';
        data.history.forEach(item => {
            const statusClass = item.payment_status === 'paid' ? 'status-paid' : (item.payment_status === 'approved' ? 'status-approved' : 'status-pending');
            const statusText = item.payment_status === 'paid' ? 'จ่ายแล้ว' : (item.payment_status === 'approved' ? 'อนุมัติ' : 'รอจ่าย');
            const isDividend = item.type === 'ปันผล (หุ้น)';

            html += `
                <tr>
                    <td><strong>ปี ${item.year}</strong></td>
                    <td>${item.type}</td>
                    <td class="text-end small">${item.details}</td>
                    <td class="text-end"><strong class="${isDividend ? 'text-success' : 'text-info'}">${item.amount_formatted}</strong></td>
                    <td class="text-center">
                        <span class="${statusClass}">${statusText}</span>
                        ${item.payment_date_formatted && item.payment_date_formatted !== '-' ? `<br><small class="text-muted">${item.payment_date_formatted}</small>` : ''}
                    </td>
                </tr>`;
        });
        historyTable.innerHTML = html;
        
    } else if (data.ok) {
        if(summaryDiv) summaryDiv.innerHTML = '';
        historyTable.innerHTML = '<tr><td colspan="5" class="text-center text-muted p-4"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>ยังไม่มีประวัติการรับปันผล/เฉลี่ยคืน</td></tr>';
    } else {
        throw new Error(data.error || 'ไม่สามารถโหลดข้อมูลประวัติได้ (รูปแบบไม่ถูกต้อง)');
    }
  } catch (error) {
    console.error("Fetch history error:", error); // [เพิ่ม] Log error
    if(summaryDiv) summaryDiv.innerHTML = '';
    historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger p-3"><i class="bi bi-exclamation-triangle me-1"></i>เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
  }
}

// รายงาน/ส่งออก (ฟังก์ชัน exportDividends ยังไม่ได้ implement)
function exportDividends(type) { 
    toast(`กำลังเตรียมส่งออกข้อมูล ${type}... (ยังไม่รองรับ)`, false); 
    // TODO: Implement actual export logic (e.g., fetch full data via API or generate CSV from existing data)
}

function exportMembers() {
  const headers = ['รหัส', 'ชื่อ', 'ประเภท', 'หุ้น', 'รวมปันผล(หุ้น)', 'รวมเฉลี่ยคืน(ซื้อ)'];
  const rows = [headers];
  let visibleRowCount = 0;
  
  // [ปรับปรุง] วนลูปเฉพาะ row ที่แสดงผลอยู่
  $$('#membersTable tbody tr.member-row').forEach(tr=>{
    if(tr.style.display==='none') return;
    visibleRowCount++;
    const tds = tr.querySelectorAll('td');
    if(tds.length >= 7){
      rows.push([
        tds[0].textContent.trim(), // รหัส
        tds[1].textContent.trim(), // ชื่อ
        tds[2].textContent.trim(), // ประเภท
        tr.dataset.shares,         // หุ้น (จาก data attribute)
        tds[4].textContent.replace(/[฿,]/g,'').trim(), // รวมปันผล
        tds[5].textContent.replace(/[฿,]/g,'').trim()  // รวมเฉลี่ยคืน
      ]);
    }
  });
  
  if (visibleRowCount === 0) { // [แก้ไข] เช็คจาก visibleRowCount
      toast('ไม่มีข้อมูลสมาชิกให้ส่งออก (ตามตัวกรองปัจจุบัน)', false); 
      return; 
  }
  
  try {
      const csv = rows.map(r=>r.map(v=>`"${String(v??'').replaceAll('"','""')}"`).join(',')).join('\n');
      const blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8;'});
      const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'committee_members_export.csv'; a.click(); URL.revokeObjectURL(a.href);
      toast('ส่งออกข้อมูลสมาชิกสำเร็จ');
  } catch (e) {
      console.error("Export error:", e);
      toast('เกิดข้อผิดพลาดในการส่งออก', false);
  }
}

// Activate tab based on URL hash
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash || '#periods-panel';
    const tabTrigger = $(`button[data-bs-target="${hash}"]`);
    if (tabTrigger) {
        try { // [เพิ่ม] try-catch for safety
            bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
        } catch(e) { console.error("Error showing tab:", e); }
    }
     $$('button[data-bs-toggle="tab"]').forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', event => {
            if (event.target.dataset.bsTarget) { // [เพิ่ม] Check dataset exists
                history.pushState(null, null, event.target.dataset.bsTarget);
            }
        })
    });
    // [เพิ่ม] Apply initial filter if needed
    applyMemberFilter(); 
});
</script>
</body>
</html>