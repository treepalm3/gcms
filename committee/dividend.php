<?php
// committee/dividend.php — [ปรับปรุง] หน้าปันผลสำหรับกรรมการ (อ่านข้อมูล + รายงาน/ส่งออก)
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== Guard & DB ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit();
}
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ===== Role check ===== */
try {
  $current_name = $_SESSION['full_name'] ?? 'กรรมการ';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'committee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}

// อ่านอย่างเดียว แต่คง CSRF ไว้สำหรับ endpoint ย่อยบางอย่าง
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* ===== Helpers ===== */
if (!function_exists('nf')) {
    function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
}
if (!function_exists('d')) {
    function d($s, $fmt = 'd/m/Y') {
        if (empty($s) || strpos($s, '0000-00-00') === 0) return '-';
        $t = strtotime($s);
        return $t ? date($fmt, $t) : '-';
    }
}
if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
      try {
        $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb");
        $st->execute([':db'=>$db, ':tb'=>$table]);
        return (int)$st->fetchColumn() > 0;
      } catch (Throwable $e) { return false; }
    }
}

/* ===== Site settings ===== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $site_name = $r['comment'] ?: $site_name;
  }
} catch (Throwable $e) {}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',  'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

/* ===== Initialize data variables ===== */
$dividend_periods = [];
$rebate_periods = [];
$members_dividends = []; // keyed by 'type_id' => [...]
$error_message = null;

$total_dividend_paid = 0.0;
$pending_dividend    = 0.0;
$total_rebate_paid   = 0.0;
$pending_rebate      = 0.0;
$total_members = 0;
$total_shares  = 0;

/* ===== Main Data Fetching Block ===== */
try {
    // 1) งวดปันผล (Dividend Periods)
    if (table_exists($pdo, 'dividend_periods')) {
        $dividend_periods = $pdo->query("
            SELECT id, `year`, start_date, end_date, period_name, total_profit, dividend_rate,
                   total_shares_at_time, total_dividend_amount, status, payment_date, created_at, approved_by
            FROM dividend_periods
            ORDER BY `year` DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error_message = ($error_message ? $error_message . ' | ' : '') . "ไม่พบตาราง dividend_periods";
    }

    // 2) งวดเฉลี่ยคืน (Rebate Periods)
    if (table_exists($pdo, 'rebate_periods')) {
        $rebate_periods = $pdo->query("
            SELECT id, `year`, start_date, end_date, period_name, total_purchase_amount, total_rebate_budget,
                   rebate_type, rebate_value, rebate_per_baht, status, payment_date
            FROM rebate_periods
            ORDER BY `year` DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } // ไม่มี error ถ้าตารางยังไม่มี

    // 3) รวมสมาชิกทั้งหมดจาก members + role จริงจาก users
    $all_members = [];
    try {
        $stmt = $pdo->query("
            SELECT 
                m.id AS member_id, 
                m.member_code, 
                u.full_name, 
                COALESCE(m.shares,0) AS shares, 
                u.role AS member_type,
                m.user_id 
            FROM members m 
            JOIN users u ON m.user_id = u.id 
            WHERE m.is_active = 1
        ");
        $all_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log("Error fetching members: " . $e->getMessage()); }

    usort($all_members, function($a, $b) { return strcmp($a['member_code'] ?? '', $b['member_code'] ?? ''); });

    // สร้าง array สำหรับแสดงผล (key = "{type}_{member_id}")
    foreach ($all_members as $row) {
        $type = $row['member_type'] ?: 'member';
        $key = $type . '_' . (int)$row['member_id'];
        $members_dividends[$key] = [
            'id' => (int)$row['member_id'],
            'code' => (string)$row['member_code'],
            'member_name' => (string)$row['full_name'],
            'shares' => (int)$row['shares'],
            'type' => $type,
            'type_th' => ['member' => 'สมาชิก', 'manager' => 'ผู้บริหาร', 'committee' => 'กรรมการ', 'admin' => 'ผู้ดูแลระบบ'][$type] ?? 'อื่นๆ',
            'payments' => [],
            'rebates' => [],
            'total_received' => 0.0,
            'total_rebate_received' => 0.0
        ];
    }

    // 4) จ่ายปันผลรายสมาชิก/งวด (Dividend Payments)
    if (table_exists($pdo, 'dividend_payments')) {
        $payments_stmt = $pdo->query("
            SELECT member_id, member_type, period_id, dividend_amount, payment_status
            FROM dividend_payments
        ");
        foreach ($payments_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $key = ($payment['member_type'] ?: 'member') . '_' . (int)$payment['member_id'];
            if (!isset($members_dividends[$key])) continue;
            $pid = (int)$payment['period_id'];
            $amt = (float)$payment['dividend_amount'];
            $members_dividends[$key]['payments'][$pid] = $amt;
            if (($payment['payment_status'] ?? 'pending') === 'paid') {
                $members_dividends[$key]['total_received'] += $amt;
            }
        }
    } else {
        $error_message = ($error_message ? $error_message . ' | ' : '') . "ไม่พบตาราง dividend_payments";
    }

    // 5) จ่ายเฉลี่ยคืนรายสมาชิก/งวด (Rebate Payments)
    if (table_exists($pdo, 'rebate_payments')) {
        $rebate_payments_stmt = $pdo->query("
            SELECT member_id, member_type, period_id, rebate_amount, payment_status
            FROM rebate_payments
        ");
        foreach ($rebate_payments_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $key = ($payment['member_type'] ?: 'member') . '_' . (int)$payment['member_id'];
            if (!isset($members_dividends[$key])) continue;
            $pid = (int)$payment['period_id'];
            $amt = (float)$payment['rebate_amount'];
            $members_dividends[$key]['rebates'][$pid] = $amt;
            if (($payment['payment_status'] ?? 'pending') === 'paid') {
                $members_dividends[$key]['total_rebate_received'] += $amt;
            }
        }
    } // ไม่ error ถ้าไม่มี

    // 6) Summary Stats
    if (table_exists($pdo, 'dividend_periods')) {
        $stats = $pdo->query("
            SELECT
              COALESCE(SUM(CASE WHEN status = 'paid'     THEN total_dividend_amount ELSE 0 END), 0) AS total_paid,
              COALESCE(SUM(CASE WHEN status = 'approved' THEN total_dividend_amount ELSE 0 END), 0) AS total_pending
            FROM dividend_periods
        ")->fetch(PDO::FETCH_ASSOC);
        $total_dividend_paid = (float)($stats['total_paid'] ?? 0);
        $pending_dividend    = (float)($stats['total_pending'] ?? 0);
    }

    if (table_exists($pdo, 'rebate_periods')) {
        $rebate_stats = $pdo->query("
            SELECT
              COALESCE(SUM(CASE WHEN status = 'paid'     THEN total_rebate_budget ELSE 0 END), 0) as total_paid,
              COALESCE(SUM(CASE WHEN status = 'approved' THEN total_rebate_budget ELSE 0 END), 0) as total_pending
            FROM rebate_periods
        ")->fetch(PDO::FETCH_ASSOC);
        $total_rebate_paid = (float)($rebate_stats['total_paid'] ?? 0);
        $pending_rebate    = (float)($rebate_stats['total_pending'] ?? 0);
    }

    // 7) Counters
    $total_members = count($members_dividends);
    $total_shares  = array_sum(array_map(fn($m)=>(int)$m['shares'], $members_dividends));

} catch (Throwable $e) {
    $error_message = ($error_message ? $error_message . ' | ' : '') . "เกิดข้อผิดพลาดในการดึงข้อมูลหลัก: " . $e->getMessage();
    // Reset on failure
    $dividend_periods = [];
    $rebate_periods = [];
    $members_dividends = [];
    $total_members = 0;
    $total_shares = 0;
    $total_dividend_paid = $pending_dividend = $total_rebate_paid = $pending_rebate = 0;
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
    .stat-card h5 { font-size: 1rem; color: var(--bs-secondary-color); margin-bottom: 0.5rem; }
    .stat-card h3 { font-weight: 700; }
    .status-paid,.status-approved,.status-pending{padding:.25rem .6rem;border-radius:20px;font-size:.8rem;font-weight:500;display:inline-block}
    .status-paid{background-color:var(--bs-success-bg-subtle);color:var(--bs-success-text-emphasis)}
    .status-approved{background-color:var(--bs-warning-bg-subtle);color:var(--bs-warning-text-emphasis)}
    .status-pending{background-color:var(--bs-secondary-bg-subtle);color:var(--bs-secondary-text-emphasis)}
    .dividend-card{ border:1px solid var(--bs-border-color-translucent); border-radius: var(--bs-card-border-radius); transition:.25s; background:#fff; box-shadow: var(--bs-box-shadow-sm); }
    .dividend-card:hover{ box-shadow: var(--bs-box-shadow); transform: translateY(-2px); }
    .dividend-amount{ font-size:1.5rem; font-weight:700; color: var(--bs-success); }
    .dividend-rate{ font-size:1.2rem; font-weight:600; color: var(--bs-primary); }
    .rebate-amount { font-size: 1.5rem; font-weight: 700; color: var(--bs-info-text-emphasis); }
    .rebate-rate { font-size: 1.2rem; font-weight: 600; color: var(--bs-info); }
    .member-row:hover{ background-color: rgba(var(--bs-primary-rgb), .05); }
    .table th { white-space: nowrap; }
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
    <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
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
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
      <div class="main-header mb-4">
        <h2><i class="fa-solid fa-gift me-2"></i> ปันผลและเฉลี่ยคืน</h2>
      </div>

      <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>เกิดข้อผิดพลาด:</strong> <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
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
        <!-- งวดปันผล -->
        <div class="tab-pane fade show active" id="periods-panel" role="tabpanel">
          <div class="stat-card mb-3">
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                  <h5 class="mb-0 card-title"><i class="fa-solid fa-gift me-2 text-success"></i>งวดปันผลตามหุ้น</h5>
                  <button class="btn btn-sm btn-outline-secondary" onclick="exportDividends('dividend')" <?= empty($dividend_periods) ? 'disabled' : '' ?>>
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
                          $status_class_map = ['paid'=>'paid','approved'=>'approved','pending'=>'pending']; ?>
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

        <!-- งวดเฉลี่ยคืน -->
        <div class="tab-pane fade" id="rebate-panel" role="tabpanel">
           <div class="stat-card mb-3">
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                  <h5 class="mb-0 card-title"><i class="bi bi-arrow-repeat me-2 text-info"></i>งวดเฉลี่ยคืนตามยอดซื้อ</h5>
                  <button class="btn btn-sm btn-outline-secondary" onclick="exportDividends('rebate')" <?= empty($rebate_periods) ? 'disabled' : '' ?>>
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
                          $rate_text = '-';
                          if (($period['rebate_type'] ?? '') === 'fixed') { $rate_text = 'คงที่'; }
                          elseif (!empty($period['rebate_per_baht']) && (float)$period['rebate_per_baht'] > 0) { $rate_text = '฿' . nf($period['rebate_per_baht'], 4) . '/บาท'; }
                          elseif (!empty($period['rebate_value']) && (float)$period['rebate_value'] > 0) { $rate_text = nf($period['rebate_value'], 1) . '%'; }
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
                              <span class="rebate-rate"><?= $rate_text ?></span>
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

        <!-- สมาชิก -->
        <div class="tab-pane fade" id="members-panel" role="tabpanel" aria-labelledby="members-tab">
          <div class="stat-card">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
              <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>ผู้ถือหุ้น (<?= nf($total_members,0) ?> คน)</h5>
              <div class="d-flex flex-wrap gap-2">
                <div class="input-group input-group-sm" style="max-width:200px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="search" id="memberSearch" class="form-control" placeholder="ค้นหาชื่อ/รหัส...">
                </div>
                <select id="filterMemberType" class="form-select form-select-sm" style="max-width:130px;">
                  <option value="">ทุกประเภท</option>
                  <option value="member">สมาชิก</option>
                  <option value="manager">ผู้บริหาร</option>
                  <option value="committee">กรรมการ</option>
                  <option value="admin">ผู้ดูแลระบบ</option>
                </select>
                <input type="number" id="minShares" class="form-control form-control-sm" placeholder="หุ้นขั้นต่ำ" min="0" style="max-width:100px;">
                <button class="btn btn-sm btn-outline-secondary" onclick="exportMembers()" title="ส่งออก CSV"><i class="bi bi-download"></i></button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table table-striped table-hover align-middle mb-0" id="membersTable">
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
                    <tr><td colspan="7" class="text-center text-muted p-4">ไม่มีข้อมูลผู้ถือหุ้น</td></tr>
                  <?php endif; ?>
                  <?php foreach($members_dividends as $key => $member): ?>
                  <tr class="member-row" data-member-key="<?= htmlspecialchars($key) ?>" data-member-name="<?= htmlspecialchars($member['member_name']) ?>" data-member-type="<?= htmlspecialchars($member['type']) ?>" data-shares="<?= (int)$member['shares'] ?>">
                    <td><strong><?= htmlspecialchars($member['code']) ?></strong></td>
                    <td><?= htmlspecialchars($member['member_name']) ?></td>
                    <td class="text-center"><span class="badge rounded-pill <?php
                        if ($member['type'] === 'member') echo 'text-bg-primary';
                        elseif ($member['type'] === 'manager') echo 'text-bg-info';
                        elseif ($member['type'] === 'committee') echo 'text-bg-secondary';
                        elseif ($member['type'] === 'admin') echo 'text-bg-danger';
                        else echo 'text-bg-light';
                    ?>"><?= htmlspecialchars($member['type_th']) ?></span></td>
                    <td class="text-center"><span class="badge bg-secondary rounded-pill"><?= number_format($member['shares']) ?></span></td>
                    <td class="text-end"><strong class="text-success">฿<?= number_format($member['total_received'], 2) ?></strong></td>
                    <td class="text-end"><strong class="text-info">฿<?= number_format($member['total_rebate_received'], 2) ?></strong></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-info py-0 px-1" title="ดูประวัติ" onclick="viewMemberHistory('<?= htmlspecialchars($key) ?>')"><i class="bi bi-clock-history"></i></button>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div><!-- /tab-content -->
    </main>
  </div>
</div>

<footer class="footer">
  <span class="text-muted">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>
</footer>

<!-- Modal: ประวัติสมาชิก -->
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

<!-- Toast -->
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

// JS Helper: Format number
function nf(number, decimals = 2) {
    const num = parseFloat(number) || 0;
    return num.toLocaleString('th-TH', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}
function formatDate(dateString) {
    if (!dateString || dateString.startsWith('0000-00-00')) return '-';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return '-';
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    } catch (e) { return '-'; }
}
function getStatusText(status) {
    const map = { paid: 'จ่ายแล้ว', approved: 'อนุมัติ', pending: 'รออนุมัติ' };
    return map[status] || status || '?';
}
function getRebateRateText(period) {
    if (!period) return '-';
    if (period.rebate_type === 'fixed') return 'คงที่';
    if (period.rebate_per_baht && parseFloat(period.rebate_per_baht) > 0) return `฿${nf(period.rebate_per_baht, 4)}/บาท`;
    if (period.rebate_value && parseFloat(period.rebate_value) > 0) return `${nf(period.rebate_value, 1)}%`;
    return '-';
}

// Toast
const toast = (msg, success=true)=>{
  const t = $('#liveToast');
  if (!t) return;
  const body = t.querySelector('.toast-body');
  t.className = `toast align-items-center border-0 ${success ? 'text-bg-success' : 'text-bg-danger'}`;
  if (body) body.textContent = msg || 'ดำเนินการสำเร็จ';
  try { bootstrap.Toast.getOrCreateInstance(t, { delay: 2200 }).show(); } catch(e) {}
};

// Data from PHP
const membersData         = <?= json_encode($members_dividends, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>; // keyed
const dividendPeriodsData = <?= json_encode($dividend_periods, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const rebatePeriodsData   = <?= json_encode($rebate_periods, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;

// --- Member Filtering ---
const memberSearch = $('#memberSearch');
const filterMemberType = $('#filterMemberType');
const minShares = $('#minShares');
function normalize(s){ return (s||'').toString().toLowerCase().trim(); }
function applyMemberFilter(){
  const keyword = normalize(memberSearch?.value || '');
  const type = filterMemberType?.value || '';
  const minS = parseInt(minShares?.value || '0', 10);

  $$('#membersTable tbody tr.member-row').forEach(tr=>{
    const searchText = normalize(`${tr.dataset.memberKey} ${tr.dataset.memberName}`);
    const memberType = tr.dataset.memberType;
    const shares = parseInt(tr.dataset.shares || '0', 10);
    const matchKeyword = !keyword || searchText.includes(keyword);
    const matchType = !type || memberType === type;
    const matchShares = isNaN(minS) || shares >= minS;
    tr.style.display = (matchKeyword && matchType && matchShares) ? '' : 'none';
  });
}
memberSearch?.addEventListener('input', applyMemberFilter);
filterMemberType?.addEventListener('change', applyMemberFilter);
minShares?.addEventListener('input', applyMemberFilter);

// --- Export Functions ---
function exportDividends(type) {
    let headers = [];
    let dataRows = [];
    let filename = '';

    try {
        if (type === 'dividend') {
            filename = 'committee_dividend_periods.csv';
            headers = ['ปี', 'ชื่องวด', 'วันที่เริ่ม', 'วันที่สิ้นสุด', 'กำไรสุทธิ', 'อัตรา (%)', 'หุ้น ณ เวลานั้น', 'ยอดปันผลรวม', 'สถานะ', 'วันที่จ่าย'];
            if (!dividendPeriodsData || dividendPeriodsData.length === 0) { toast('ไม่มีข้อมูลงวดปันผลให้ส่งออก', false); return; }
            dividendPeriodsData.forEach(p => {
                dataRows.push([
                    p.year,
                    p.period_name || '',
                    formatDate(p.start_date),
                    formatDate(p.end_date),
                    nf(p.total_profit, 2),
                    nf(p.dividend_rate, 1),
                    nf(p.total_shares_at_time, 0),
                    nf(p.total_dividend_amount, 2),
                    getStatusText(p.status),
                    formatDate(p.payment_date)
                ]);
            });
        } else if (type === 'rebate') {
            filename = 'committee_rebate_periods.csv';
            headers = ['ปี', 'ชื่องวด', 'วันที่เริ่ม', 'วันที่สิ้นสุด', 'ยอดซื้อรวม(ฐาน)', 'ประเภทเฉลี่ยคืน', 'อัตรา/งบ', 'งบประมาณรวม', 'สถานะ', 'วันที่จ่าย'];
            if (!rebatePeriodsData || rebatePeriodsData.length === 0) { toast('ไม่มีข้อมูลงวดเฉลี่ยคืนให้ส่งออก', false); return; }
            rebatePeriodsData.forEach(p => {
                dataRows.push([
                    p.year,
                    p.period_name || '',
                    formatDate(p.start_date),
                    formatDate(p.end_date),
                    nf(p.total_purchase_amount, 2),
                    p.rebate_type === 'fixed' ? 'คงที่' : (p.rebate_type === 'percentage' ? 'เปอร์เซ็นต์' : (p.rebate_type === 'per_unit' ? 'ต่อหน่วยซื้อ' : p.rebate_type)),
                    getRebateRateText(p),
                    nf(p.total_rebate_budget, 2),
                    getStatusText(p.status),
                    formatDate(p.payment_date)
                ]);
            });
        } else { toast('ประเภทการส่งออกไม่ถูกต้อง', false); return; }

        const rows = [headers, ...dataRows];
        const csv = rows.map(r=>r.map(v=>`"${String(v??'').replaceAll('"','""')}"`).join(',')).join('\n');
        const blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8;'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
        toast(`ส่งออกข้อมูล ${type === 'dividend' ? 'งวดปันผล' : 'งวดเฉลี่ยคืน'} สำเร็จ`);
    } catch (e) {
        console.error(`Export error (${type}):`, e);
        toast(`เกิดข้อผิดพลาดในการส่งออกข้อมูล ${type}`, false);
    }
}

function exportMembers() {
  const headers = ['รหัส', 'ชื่อ', 'ประเภท', 'หุ้น', 'รวมปันผล(หุ้น)', 'รวมเฉลี่ยคืน(ซื้อ)'];
  const rows = [headers];
  let visibleRowCount = 0;

  $$('#membersTable tbody tr.member-row').forEach(tr=>{
    if(tr.style.display==='none') return;
    visibleRowCount++;
    const tds = tr.querySelectorAll('td');
    if(tds.length >= 7){
      rows.push([
        tds[0].textContent.trim(),
        tds[1].textContent.trim(),
        tds[2].textContent.trim(),
        tr.dataset.shares,
        tds[4].textContent.replace(/[฿,]/g,'').trim(),
        tds[5].textContent.replace(/[฿,]/g,'').trim()
      ]);
    }
  });

  if (!visibleRowCount) { toast('ไม่มีข้อมูลสมาชิกให้ส่งออก (ตามตัวกรองปัจจุบัน)', false); return; }

  try {
      const csv = rows.map(r=>r.map(v=>`"${String(v??'').replaceAll('"','""')}"`).join(',')).join('\n');
      const blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8;'});
      const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'committee_members_export.csv';
      a.style.display = 'none'; document.body.appendChild(a);
      a.click();
      document.body.removeChild(a); URL.revokeObjectURL(a.href);
      toast('ส่งออกข้อมูลสมาชิกสำเร็จ');
  } catch (e) {
      console.error("Export members error:", e);
      toast('เกิดข้อผิดพลาดในการส่งออกสมาชิก', false);
  }
}

// --- View Member History Modal ---
async function viewMemberHistory(memberKey) {
  const member = membersData[memberKey];
  if (!member) { toast('ไม่พบข้อมูลสมาชิก', false); return; }

  $('#historyMemberCode').textContent = member.code || '-';
  $('#historyMemberName').textContent = member.member_name || '-';
  $('#historyMemberType').textContent = member.type_th || '-';

  const historyTable = $('#memberHistoryTable');
  const summaryDiv = $('#modalMemberHistory .history-summary');
  historyTable.innerHTML = '<tr><td colspan="5" class="text-center p-3"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';
  if(summaryDiv) summaryDiv.innerHTML = '';

  bootstrap.Modal.getOrCreateInstance('#modalMemberHistory').show();

  try {
    const response = await fetch(`dividend_member_history.php?member_id=${member.id}&member_type=${member.type}`);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();

    if (data.ok && data.history.length > 0) {
        const summary = data.summary || {};
        if(summaryDiv) {
             summaryDiv.innerHTML = `
                <div class="alert alert-light border small mb-3">
                    <div class="row text-center g-2">
                        <div class="col-6 col-md-3"><small class="text-muted d-block">ปันผล(รับแล้ว)</small><strong class="text-success">${summary.total_received_formatted||'฿0.00'}</strong></div>
                        <div class="col-6 col-md-3"><small class="text-muted d-block">ปันผล(ค้างรับ)</small><strong class="text-warning">${summary.total_pending_formatted||'฿0.00'}</strong></div>
                        <div class="col-6 col-md-3"><small class="text-muted d-block">เฉลี่ยคืน(รับแล้ว)</small><strong class="text-info">${summary.total_rebate_received_formatted||'฿0.00'}</strong></div>
                        <div class="col-6 col-md-3"><small class="text-muted d-block">เฉลี่ยคืน(ค้างรับ)</small><strong class="text-warning">${summary.total_rebate_pending_formatted||'฿0.00'}</strong></div>
                    </div>
                </div>`;
        }

        historyTable.innerHTML = data.history.map(item=>{
            const statusClass = item.payment_status === 'paid' ? 'status-paid' : (item.payment_status === 'approved' ? 'status-approved' : 'status-pending');
            const statusText = getStatusText(item.payment_status);
            const isDividend = item.type === 'ปันผล (หุ้น)';
            const details = isDividend
              ? `${(item.shares_at_time || 0).toLocaleString('th-TH')} หุ้น @ ${parseFloat(item.dividend_rate || 0).toFixed(1)}%`
              : `ซื้อ ${nf(item.purchase_amount_at_time || 0)} @ ${nf(item.rebate_per_baht || 0, 4)}/บาท`;
            return `
              <tr>
                  <td><strong>ปี ${item.year}</strong></td>
                  <td>${item.type}</td>
                  <td class="text-end small">${details}</td>
                  <td class="text-end"><strong class="${isDividend ? 'text-success' : 'text-info'}">${item.amount_formatted || '฿0.00'}</strong></td>
                  <td class="text-center">
                      <span class="${statusClass}">${statusText}</span>
                      ${item.payment_date_formatted && item.payment_date_formatted !== '-' ? `<br><small class="text-muted">${item.payment_date_formatted}</small>` : ''}
                  </td>
              </tr>`;
        }).join('');

    } else if (data.ok) {
        if(summaryDiv) summaryDiv.innerHTML = '';
        historyTable.innerHTML = '<tr><td colspan="5" class="text-center text-muted p-4"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>ยังไม่มีประวัติการรับปันผล/เฉลี่ยคืน</td></tr>';
    } else {
        throw new Error(data.error || 'ไม่สามารถโหลดข้อมูลประวัติได้ (รูปแบบไม่ถูกต้อง)');
    }
  } catch (error) {
    console.error("Fetch history error:", error);
    if(summaryDiv) summaryDiv.innerHTML = '';
    historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger p-3"><i class="bi bi-exclamation-triangle me-1"></i>เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
  }
}

// --- Tab Activation ---
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash || '#periods-panel';
    const tabTrigger = document.querySelector(`button[data-bs-target="${hash}"]`);
    if (tabTrigger) {
        try {
            bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
        } catch(e) { console.error("Error showing tab:", e); }
    }
     document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', event => {
            if (event.target.dataset.bsTarget) {
                history.pushState(null, null, event.target.dataset.bsTarget);
            }
        })
    });
    // Apply initial filter
    applyMemberFilter();
});
</script>
</body>
</html>
