<?php
// manager/dividend.php — จัดการปันผลสหกรณ์ 
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit();
}

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
    $current_name = $_SESSION['full_name'] ?? 'ผู้บริหาร';
    $current_role = $_SESSION['role'] ?? 'guest';
    if ($current_role !== 'manager') {
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

// ===== Helpers =====
if (!function_exists('nf')) {
    function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
}
if (!function_exists('d')) {
    function d($s, $fmt = 'd/m/Y') {
        if (empty($s)) return '-';
        $t = strtotime($s); 
        return $t ? date($fmt, $t) : '-';
    }
}

// ===== เตรียมข้อมูล =====
$dividend_periods = [];
$rebate_periods = [];
$members_dividends = [];
$error_message = null;

try {
    // 1) งวดปันผล (รายปี)
    $dividend_periods = $pdo->query("
        SELECT id, `year`, start_date, end_date, period_name, total_profit, dividend_rate,
               total_shares_at_time, total_dividend_amount, status, payment_date,
               created_at, approved_by
        FROM dividend_periods
        ORDER BY `year` DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 1.1) งวดเฉลี่ยคืน
    try {
        $rebate_periods = $pdo->query("
            SELECT id, `year`, period_name, start_date, end_date, total_purchase_amount, total_rebate_budget, 
                   rebate_type, rebate_value, rebate_per_baht, status, payment_date
            FROM rebate_periods
            ORDER BY `year` DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Rebate table not found: " . $e->getMessage());
        $rebate_periods = [];
    }


    // 2) รวมสมาชิกทุกประเภท (แหล่งข้อมูลเดียว: members, เพื่อป้องกันการนับซ้ำซ้อน)
    $all_members = [];
    try {
        // ดึงข้อมูลสมาชิกทั้งหมดจากตาราง 'members' เท่านั้น และ JOIN เพื่อดึง role ที่แท้จริงจาก users
        $stmt = $pdo->query("
            SELECT 
                m.id AS member_id, 
                m.member_code, 
                u.full_name, 
                m.shares, 
                u.role AS member_type, -- ใช้ role จาก users เพื่อระบุประเภท (member/manager/committee)
                m.user_id 
            FROM members m 
            JOIN users u ON m.user_id = u.id 
            WHERE m.is_active = 1
        ");
        $all_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /*
        // [ยกเลิก] โค้ดเดิมที่ดึงจากหลายตาราง (ซึ่งทำให้เกิดการนับซ้ำซ้อน)
        // $stmt = $pdo->query("SELECT mg.id AS member_id, CONCAT('MGR-', LPAD(mg.id, 3, '0')) AS member_code, u.full_name, mg.shares, 'manager' AS member_type FROM managers mg JOIN users u ON mg.user_id = u.id");
        // $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
        // $stmt = $pdo->query("SELECT c.id AS member_id, c.committee_code AS member_code, u.full_name, COALESCE(c.shares, 0) AS shares, 'committee' AS member_type FROM committees c JOIN users u ON c.user_id = u.id");
        // $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
        */

    } catch (Throwable $e) { error_log("Error fetching members: " . $e->getMessage()); }

    usort($all_members, function($a, $b) { return strcmp($a['member_code'] ?? '', $b['member_code'] ?? ''); });

    // สร้าง array สำหรับแสดงผล
    $members_dividends = [];
    foreach ($all_members as $row) {
        $key = $row['member_type'] . '_' . $row['member_id'];
        $members_dividends[$key] = [
            'id' => (int)$row['member_id'],
            'code' => $row['member_code'],
            'member_name' => $row['full_name'],
            'shares' => (int)$row['shares'],
            'type' => $row['member_type'],
            'type_th' => ['member' => 'สมาชิก', 'manager' => 'ผู้บริหาร', 'committee' => 'กรรมการ', 'admin' => 'ผู้ดูแลระบบ'][$row['member_type']] ?? 'อื่นๆ',
            'payments' => [],
            'rebates' => [],
            'total_received' => 0.0,
            'total_rebate_received' => 0.0
        ];
    }

    // 3) การจ่ายปันผล
    // Note: ตาราง dividend_payments อาจจะยังใช้ member_type และ member_id เก่าอยู่ ต้องดูว่ามีการปรับใช้ user_id หรือไม่
    $payments_stmt = $pdo->query("SELECT dp.member_id, dp.member_type, dp.period_id, dp.dividend_amount, dp.payment_status FROM dividend_payments dp");
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
    
    // 3.1) การจ่ายเฉลี่ยคืน
    try {
        $rebate_payments_stmt = $pdo->query("SELECT rp.member_id, rp.member_type, rp.period_id, rp.rebate_amount, rp.payment_status FROM rebate_payments rp");
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
    } catch(Throwable $e) { /* ไม่ทำอะไรถ้าตารางยังไม่มี */ }


} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    error_log("Dividend page error: " . $e->getMessage());
}

// ===== ดึงข้อมูลระบบ =====
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
    $st = $pdo->query("SELECT comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $site_name = $r['comment'] ?: $site_name;
    }
} catch (Throwable $e) {}

$role_th_map = ['admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ===== คำนวณสถิติรวม =====
$total_dividend_paid = 0;
$pending_dividend = 0;
$total_rebate_paid = 0;
$pending_rebate = 0;
$total_members = count($members_dividends);
$total_shares = array_sum(array_column($members_dividends, 'shares'));

try {
    // ยอดปันผล
    $stats = $pdo->query("
        SELECT
            (SELECT COALESCE(SUM(total_dividend_amount), 0) FROM dividend_periods WHERE status = 'paid') as total_paid,
            (SELECT COALESCE(SUM(total_dividend_amount), 0) FROM dividend_periods WHERE status = 'approved') as total_pending
    ")->fetch(PDO::FETCH_ASSOC);
    $total_dividend_paid = (float)($stats['total_paid'] ?? 0);
    $pending_dividend = (float)($stats['total_pending'] ?? 0);
    
    // ยอดเฉลี่ยคืน
    try {
        $rebate_stats = $pdo->query("
            SELECT
                (SELECT COALESCE(SUM(total_rebate_budget), 0) FROM rebate_periods WHERE status = 'paid') as total_paid,
                (SELECT COALESCE(SUM(total_rebate_budget), 0) FROM rebate_periods WHERE status = 'approved') as total_pending
        ")->fetch(PDO::FETCH_ASSOC);
        $total_rebate_paid = (float)($rebate_stats['total_paid'] ?? 0);
        $pending_rebate = (float)($rebate_stats['total_pending'] ?? 0);
    } catch (Throwable $e) { /* ตารางยังไม่มี */ }

} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ปันผล | สหกรณ์ปั๊มน้ำมัน</title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
        .status-paid { 
            background: #d1f4dd; 
            color: #0f5132; 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-size: 0.85rem; 
            font-weight: 500; 
        }
        .status-approved { 
            background: #fff3cd; 
            color: #664d03; 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-size: 0.85rem; 
            font-weight: 500; 
        }
        .status-pending { 
            background: #f8d7da; 
            color: #842029; 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-size: 0.85rem; 
            font-weight: 500; 
        }
        .dividend-card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            transition: all 0.3s;
            background: #fff;
        }
        .dividend-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,.08);
            transform: translateY(-2px);
        }
        .dividend-amount {
            font-size: 1.3rem;
            font-weight: 700;
            color: #198754;
        }
        .dividend-rate {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0d6efd;
        }
        .member-row:hover {
            background-color: rgba(13, 110, 253, 0.05);
        }
        .member-type-badge {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
        }
        .type-member { background: #e7f1ff; color: #004085; }
        .type-manager { background: #fff3cd; color: #664d03; }
        .type-committee { background: #f8d7da; color: #721c24; }
        
        .calc-result {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            text-align: center;
        }
        .calc-input {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s;
        }
        .calc-input:focus {
            background: white;
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13,110,253,.1);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
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
      <a class="navbar-brand" href="manager_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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
    <div class="side-brand mb-2"><h3><span>Manager</span></h3></div>
    <nav class="sidebar-menu">
      <a href="manager_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
      <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
    </nav>
    <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Manager</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="manager_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
            <div class="main-header">
                <h2 class="mb-0"><i class="fa-solid fa-gift me-2"></i> จัดการปันผลและเฉลี่ยคืน</h2>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card text-center">
                    <div class="fs-4 text-success mb-2"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                    <h6>ปันผล (หุ้น) จ่ายแล้ว</h6>
                    <h3 class="mb-0 text-success">฿<?= number_format($total_dividend_paid, 2) ?></h3>
                    <small class="text-warning">รอจ่าย: ฿<?= number_format($pending_dividend, 2) ?></small>
                </div>
                <div class="stat-card text-center">
                    <div class="fs-4 text-primary mb-2"><i class="bi bi-people-fill"></i></div>
                    <h6>ผู้ถือหุ้นปัจจุบัน</h6>
                    <h3 class="mb-0 text-primary"><?= number_format($total_members) ?> คน</h3>
                    <small class="text-muted">หุ้นรวม <?= number_format($total_shares) ?></small>
                </div>
                <div class="stat-card text-center">
                    <div class="fs-4 text-info mb-2"><i class="bi bi-arrow-repeat"></i></div>
                    <h6>เฉลี่ยคืน (ซื้อ) จ่ายแล้ว</h6>
                    <h3 class="mb-0 text-info">฿<?= number_format($total_rebate_paid, 2) ?></h3>
                    <small class="text-warning">รอจ่าย: ฿<?= number_format($pending_rebate, 2) ?></small>
                </div>
            </div>

            <ul class="nav nav-tabs mb-3" id="dividendTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="periods-tab" data-bs-toggle="tab" data-bs-target="#periods-panel" type="button" role="tab" aria-controls="periods-panel" aria-selected="true">
                        <i class="fa-solid fa-calendar-days me-2"></i>งวดปันผล (ตามหุ้น)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="rebate-tab" data-bs-toggle="tab" data-bs-target="#rebate-panel" type="button" role="tab" aria-controls="rebate-panel" aria-selected="false">
                        <i class="bi bi-arrow-repeat me-2"></i>งวดเฉลี่ยคืน (ตามยอดซื้อ)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="members-tab" data-bs-toggle="tab" data-bs-target="#members-panel" type="button" role="tab" aria-controls="members-panel" aria-selected="false">
                        <i class="bi bi-people-fill me-2"></i>ผู้ถือหุ้น
                    </button>
                </li>
            </ul>

            <div class="tab-content pt-3">
                <div class="tab-pane fade show active" id="periods-panel" role="tabpanel" aria-labelledby="periods-tab">
                    <div class="panel mb-4">
                       <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fa-solid fa-calendar-days me-2"></i>งวดปันผลตามหุ้น</h5>
                             <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCreateDividend">
                                <i class="fa-solid fa-plus me-1"></i> สร้างงวดปันผล
                            </button>
                       </div>
                       <div class="row g-3">
                           <?php if (empty($dividend_periods)): ?>
                               <div class="col-12"><div class="alert alert-info text-center mb-0">ยังไม่มีงวดปันผลตามหุ้น</div></div>
                           <?php else: ?>
                               <?php foreach($dividend_periods as $period): ?>
                               <div class="col-md-6 col-lg-4">
                                   <div class="stat-card h-100 d-flex flex-column">
                                       <div class="d-flex flex-column flex-grow-1">
                                           <div class="d-flex justify-content-between align-items-start mb-2">
                                               <div>
                                                   <h5 class="card-title mb-0">ปี <?= htmlspecialchars($period['year']) ?></h5>
                                                   <small class="text-muted d-block mb-1"><?= htmlspecialchars($period['period_name']) ?></small>
                                               </div>
                                               <span class="badge rounded-pill <?=
                                                   $period['status'] === 'paid' ? 'text-bg-success' :
                                                   ($period['status'] === 'approved' ? 'text-bg-warning' : 'text-bg-danger')
                                               ?>"><?= ['paid' => 'จ่ายแล้ว', 'approved' => 'อนุมัติ', 'pending' => 'รออนุมัติ'][$period['status']] ?? '?' ?></span>
                                           </div>
                                           <div class="row text-center my-3 flex-grow-1 align-items-center">
                                               <div class="col-6 border-end">
                                                   <small class="text-muted">อัตรา</small><br>
                                                   <span class="h5 fw-bold text-primary"><?= number_format($period['dividend_rate'], 1) ?>%</span>
                                               </div>
                                               <div class="col-6">
                                                   <small class="text-muted">ยอดรวม</small><br>
                                                   <span class="h5 fw-bold text-success">฿<?= number_format($period['total_dividend_amount'], 0) ?></span>
                                               </div>
                                           </div>
                                           <div class="d-flex flex-column gap-1 small text-muted border-top pt-2 mb-3">
                                               <div class="d-flex justify-content-between"><span>กำไรสุทธิ:</span> <span>฿<?= number_format($period['total_profit'], 0) ?></span></div>
                                               <div class="d-flex justify-content-between"><span>จำนวนหุ้น:</span> <span><?= number_format($period['total_shares_at_time']) ?> หุ้น</span></div>
                                               <?php if($period['payment_date']): ?>
                                               <div class="d-flex justify-content-between"><span>วันที่จ่าย:</span> <span><?= d($period['payment_date']) ?></span></div>
                                               <?php endif; ?>
                                           </div>
                                       </div>
                                       <div class="mt-auto d-grid gap-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="viewDividendDetails(<?= (int)$period['id'] ?>)">
                                                <i class="bi bi-eye me-1"></i> ดูรายละเอียด
                                            </button>
                                            <?php if($period['status'] === 'approved'): ?>
                                            <button class="btn btn-sm btn-success" onclick="processPayout(<?= (int)$period['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                <i class="fa-solid fa-money-check-dollar me-1"></i> ยืนยันการจ่าย
                                            </button>
                                            <?php elseif ($period['status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-outline-warning" onclick="approveDividend(<?= (int)$period['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                <i class="bi bi-check2-circle me-1"></i> อนุมัติงวดนี้
                                            </button>
                                            <?php endif; ?>
                                       </div>
                                   </div>
                               </div>
                               <?php endforeach; ?>
                           <?php endif; ?>
                       </div>
                   </div>
                </div>

                <div class="tab-pane fade" id="rebate-panel" role="tabpanel" aria-labelledby="rebate-tab">
                    <div class="panel mb-4">
                       <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>งวดเฉลี่ยคืนตามยอดซื้อ</h5>
                             <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalCreateRebate">
                                <i class="fa-solid fa-plus me-1"></i> สร้างงวดเฉลี่ยคืน
                            </button>
                       </div>
                       <div class="row g-3">
                           <?php if (empty($rebate_periods)): ?>
                               <div class="col-12"><div class="alert alert-info text-center mb-0">ยังไม่มีงวดเฉลี่ยคืน</div></div>
                           <?php else: ?>
                               <?php foreach($rebate_periods as $period): ?>
                               <div class="col-md-6 col-lg-4">
                                   <div class="stat-card h-100 d-flex flex-column">
                                       <div class="d-flex flex-column flex-grow-1">
                                           <div class="d-flex justify-content-between align-items-start mb-2">
                                               <div>
                                                   <h5 class="card-title mb-0">ปี <?= htmlspecialchars($period['year']) ?></h5>
                                                   <small class="text-muted d-block mb-1"><?= htmlspecialchars($period['period_name']) ?></small>
                                               </div>
                                               <span class="badge rounded-pill <?=
                                                   $period['status'] === 'paid' ? 'text-bg-success' :
                                                   ($period['status'] === 'approved' ? 'text-bg-warning' : 'text-bg-danger')
                                               ?>"><?= ['paid' => 'จ่ายแล้ว', 'approved' => 'อนุมัติ', 'pending' => 'รออนุมัติ'][$period['status']] ?? '?' ?></span>
                                           </div>
                                           <div class="row text-center my-3 flex-grow-1 align-items-center">
                                               <div class="col-6 border-end">
                                                   <small class="text-muted">อัตรา/งบ</small><br>
                                                   <span class="h5 fw-bold text-info">
                                                      <?php
                                                        if (($period['rebate_type'] ?? '') === 'fixed') { echo 'คงที่'; }
                                                        elseif (isset($period['rebate_per_baht']) && (float)$period['rebate_per_baht'] > 0) { echo '฿' . nf($period['rebate_per_baht'], 4) . '/บาท'; }
                                                        elseif (isset($period['rebate_value']) && (float)$period['rebate_value'] > 0) { echo nf($period['rebate_value'], 1) . '%'; }
                                                        else { echo '-'; }
                                                      ?>
                                                   </span>
                                               </div>
                                               <div class="col-6">
                                                   <small class="text-muted">ยอดรวม</small><br>
                                                   <span class="h5 fw-bold text-info">฿<?= number_format($period['total_rebate_budget'], 0) ?></span>
                                               </div>
                                           </div>
                                           <div class="d-flex flex-column gap-1 small text-muted border-top pt-2 mb-3">
                                               <div class="d-flex justify-content-between"><span>ยอดซื้อรวม:</span> <span>฿<?= number_format($period['total_purchase_amount'] ?? 0, 0) ?></span></div>
                                               <?php if($period['payment_date']): ?>
                                               <div class="d-flex justify-content-between"><span>วันที่จ่าย:</span> <span><?= d($period['payment_date']) ?></span></div>
                                               <?php endif; ?>
                                           </div>
                                       </div>
                                       <div class="mt-auto d-grid gap-2">
                                            <button class="btn btn-sm btn-outline-info" onclick="viewRebateDetails(<?= (int)$period['id'] ?>)">
                                                <i class="bi bi-eye me-1"></i> ดูรายละเอียด
                                            </button>
                                            <?php if($period['status'] === 'approved'): ?>
                                            <button class="btn btn-sm btn-success" onclick="processRebatePayout(<?= (int)$period['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                <i class="fa-solid fa-money-check-dollar me-1"></i> ยืนยันการจ่าย
                                            </button>
                                            <?php elseif ($period['status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-outline-warning" onclick="approveRebate(<?= (int)$period['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                <i class="bi bi-check2-circle me-1"></i> อนุมัติงวดนี้
                                            </button>
                                            <?php endif; ?>
                                       </div>
                                   </div>
                               </div>
                               <?php endforeach; ?>
                           <?php endif; ?>
                       </div>
                   </div>
                </div>

                <div class="tab-pane fade" id="members-panel" role="tabpanel" aria-labelledby="members-tab">
                    <div class="panel">
                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>ผู้ถือหุ้น (<?= $total_members ?> คน)</h5>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="input-group input-group-sm" style="max-width:200px;"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" id="memberSearch" class="form-control" placeholder="ค้นหาชื่อ/รหัส..."></div>
                                <select id="filterMemberType" class="form-select form-select-sm" style="max-width:130px;"><option value="">ทุกประเภท</option><option value="member">สมาชิก</option><option value="manager">ผู้บริหาร</option><option value="committee">กรรมการ</option></select>
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

            </div> </main>
    </div>
</div>

<footer class="footer ">
        <span class="text">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>  
</footer>

<div class="modal fade" id="modalCreateDividend" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="dividend_create_period.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fa-solid fa-plus me-2"></i>สร้างงวดปันผล (ตามหุ้น)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-calendar me-1"></i>ปี <span class="text-danger">*</span></label><input type="number" name="year" id="dividendYear" class="form-control" value="<?= date('Y') ?>" required min="2020" max="2050" onchange="updateDateRange('dividend')"></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-card-text me-1"></i>ชื่องวด</label><input type="text" name="period_name" id="dividendPeriodName" class="form-control" placeholder="เช่น ปันผลประจำปี <?= date('Y') ?>" value="ปันผลประจำปี <?= date('Y') ?>"></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-calendar-event me-1"></i>วันที่เริ่มต้น <span class="text-danger">*</span></label><input type="date" name="start_date" id="dividendStartDate" class="form-control" value="<?= date('Y') ?>-01-01" required></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-calendar-check me-1"></i>วันที่สิ้นสุด <span class="text-danger">*</span></label><input type="date" name="end_date" id="dividendEndDate" class="form-control" value="<?= date('Y') ?>-12-31" required></div>
                    <div class="col-12"><div class="alert alert-light border mb-0 py-2"><small class="text-muted d-flex justify-content-between"><span><i class="bi bi-calendar-range me-1"></i> <strong>ช่วงเวลา:</strong> <span id="dividendDateRangeDisplay">1 ม.ค. <?= date('Y') ?> - 31 ธ.ค. <?= date('Y') ?></span></span> <span>(<span id="dividendDaysCount">365</span> วัน)</span></small></div></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-currency-dollar me-1"></i>กำไรสุทธิ (บาท) <span class="text-danger">*</span></label><input type="number" name="total_profit" id="modalProfit" class="form-control" step="0.01" min="0.01" required placeholder="0.00" oninput="updateModalCalc('dividend')"></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-percent me-1"></i>อัตราปันผล (หุ้น) (%) <span class="text-danger">*</span></label><input type="number" name="dividend_rate" id="modalRate" class="form-control" step="0.1" min="0.1" max="100" required placeholder="เช่น 15" oninput="updateModalCalc('dividend')"></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-diagram-3 me-1"></i>จำนวนหุ้นรวม (ณ สิ้นงวด)</label><input type="number" name="total_shares_at_time" class="form-control bg-light" value="<?= $total_shares ?>" required readonly></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-cash-stack me-1"></i>ยอดปันผลรวม (บาท)</label><input type="text" id="modalTotal" class="form-control bg-light" value="0.00" readonly><input type="hidden" name="total_dividend_amount" id="modalTotalHidden" value="0"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>ยกเลิก</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save2 me-1"></i>สร้างงวดปันผล</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalCreateRebate" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="rebate_create_period.php"> <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="modal-header bg-info text-dark">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>สร้างงวดเฉลี่ยคืน (ตามยอดซื้อ)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-calendar me-1"></i>ปี <span class="text-danger">*</span></label><input type="number" name="year" id="rebateYear" class="form-control" value="<?= date('Y') ?>" required min="2020" max="2050" onchange="updateDateRange('rebate')"></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-card-text me-1"></i>ชื่องวด</label><input type="text" name="period_name" id="rebatePeriodName" class="form-control" placeholder="เช่น เฉลี่ยคืนประจำปี <?= date('Y') ?>" value="เฉลี่ยคืนประจำปี <?= date('Y') ?>"></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-calendar-event me-1"></i>วันที่เริ่ม (ยอดซื้อ) <span class="text-danger">*</span></label><input type="date" name="start_date" id="rebateStartDate" class="form-control" value="<?= date('Y') ?>-01-01" required></div>
                    <div class="col-sm-6"><label class="form-label fw-bold"><i class="bi bi-calendar-check me-1"></i>วันที่สิ้นสุด (ยอดซื้อ) <span class="text-danger">*</span></label><input type="date" name="end_date" id="rebateEndDate" class="form-control" value="<?= date('Y') ?>-12-31" required></div>
                    <div class="col-12"><div class="alert alert-light border mb-0 py-2"><small class="text-muted d-flex justify-content-between"><span><i class="bi bi-calendar-range me-1"></i> <strong>ช่วงเวลา:</strong> <span id="rebateDateRangeDisplay">1 ม.ค. <?= date('Y') ?> - 31 ธ.ค. <?= date('Y') ?></span></span> <span>(<span id="rebateDaysCount">365</span> วัน)</span></small></div></div>

                    <div class="col-12"><hr class="my-2"><h6 class="text-info"><i class="bi bi-calculator me-2"></i>กำหนดงบประมาณเฉลี่ยคืน</h6></div>
                    <div class="col-sm-6">
                        <label class="form-label fw-bold"><i class="bi bi-currency-dollar me-1"></i>กำไรสุทธิ (บาท) <span class="text-danger">*</span></label>
                        <input type="number" name="total_profit" id="rebateModalProfit" class="form-control" placeholder="0.00" step="0.01" required oninput="updateModalCalc('rebate')">
                        <div class="form-text">กำไรสุทธิทั้งหมดที่จะใช้เป็นฐานคำนวณ</div>
                    </div>
                     <div class="col-sm-6">
                        <label class="form-label fw-bold"><i class="bi bi-percent me-1"></i>อัตราเฉลี่ยคืน (%) <span class="text-danger">*</span></label>
                        <input type="number" name="rebate_rate_percent" id="modalRebateRatePercent" class="form-control" placeholder="เช่น 5" step="0.1" required oninput="updateModalCalc('rebate')">
                        <div class="form-text">เปอร์เซ็นต์จากกำไรสุทธิ</div>
                    </div>
                    
                    <input type="hidden" name="rebate_base" value="profit">
                    <input type="hidden" name="rebate_mode" value="weighted">
                    <input type="hidden" name="rebate_type" value="rate">
                    <input type="hidden" name="rebate_value" id="modalRebateValueHidden" value="0"> 
                    <div class="col-12">
                        <label class="form-label fw-bold"><i class="bi bi-cash-stack me-1"></i>งบประมาณเฉลี่ยคืนรวม (บาท)</label>
                        <input type="text" id="modalRebateTotal" class="form-control bg-light" value="0.00" readonly>
                        <input type="hidden" name="total_rebate_budget" id="modalRebateTotalHidden" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>ยกเลิก</button>
                <button type="submit" class="btn btn-info text-dark"><i class="bi bi-save2 me-1"></i>สร้างงวดเฉลี่ยคืน</button>
            </div>
        </form>
    </div>
</div>


<div class="modal fade" id="modalMemberHistory" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>ประวัติการรับปันผล/เฉลี่ยคืน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3 small">
                    <div class="col-sm-4"><strong>รหัส:</strong> <span id="historyMemberId">-</span></div>
                    <div class="col-sm-5"><strong>ชื่อ:</strong> <span id="historyMemberName">-</span></div>
                    <div class="col-sm-3"><strong>ประเภท:</strong> <span id="historyMemberType">-</span></div>
                </div>
                <div class="history-summary mb-3"></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ปี</th>
                                <th>ประเภท</th>
                                <th class="text-end">รายละเอียด</th>
                                <th class="text-end">ยอดเงิน</th>
                                <th class="text-center">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody id="memberHistoryTable">
                            <tr><td colspan="5" class="text-center text-muted">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
    <div id="liveToast" class="toast border-0 align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const $  = (s, p=document) => p.querySelector(s);
const $$ = (s, p=document) => [...p.querySelectorAll(s)];
function nf(number, decimals = 2) {
    const num = parseFloat(number) || 0;
    return num.toLocaleString('th-TH', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

const membersData = <?= json_encode(array_values($members_dividends), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const dividendPeriodsData = <?= json_encode($dividend_periods, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const rebatePeriodsData = <?= json_encode($rebate_periods, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

const toast = (msg, success = true) => {
    const t = $('#liveToast');
    if (!t) return;
    t.className = `toast align-items-center border-0 ${success ? 'text-bg-success' : 'text-bg-danger'}`;
    $('.toast-body', t).textContent = msg || (success ? 'ดำเนินการสำเร็จ' : 'เกิดข้อผิดพลาด');
    bootstrap.Toast.getOrCreateInstance(t, { delay: 3000 }).show();
};

const urlParams = new URLSearchParams(window.location.search);
const okMsg = urlParams.get('ok');
const errMsg = urlParams.get('err');
if (okMsg) {
    toast(okMsg, true);
    window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
}
if (errMsg) {
    toast(errMsg, false);
    window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
}

// ===== FILTER MEMBERS (คงเดิม) =====
const memberSearch = $('#memberSearch');
const filterMemberType = $('#filterMemberType');
const minShares = $('#minShares');
function normalize(s){ return (s || '').toString().toLowerCase().trim(); }
function applyMemberFilter(){
    const keyword = normalize(memberSearch?.value || '');
    const type = filterMemberType?.value || '';
    const minS = parseInt(minShares?.value || '0', 10);
    $$('#membersTable tbody tr').forEach(tr => {
        const searchText = normalize(`${tr.dataset.memberName} ${tr.dataset.memberKey}`);
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


// ===== MODAL DATE RANGE (คงเดิม) =====
function updateDateRange(prefix) { // prefix = 'dividend' หรือ 'rebate'
    const yearSelect = $(`#${prefix}Year`);
    const startDateInput = $(`#${prefix}StartDate`);
    const endDateInput = $(`#${prefix}EndDate`);
    const periodNameInput = $(`#${prefix}PeriodName`);
    if (!yearSelect || !startDateInput || !endDateInput) return;
    const year = yearSelect.value || <?= (int)date('Y') ?>;
    startDateInput.value = `${year}-01-01`;
    endDateInput.value = `${year}-12-31`;
    if (periodNameInput && (periodNameInput.value === '' || periodNameInput.value.includes('ประจำปี'))) {
        const defaultName = (prefix === 'dividend') ? 'ปันผลประจำปี' : 'เฉลี่ยคืนประจำปี';
        periodNameInput.value = `${defaultName} ${year}`;
    }
    calculateDateRange(prefix);
}
function calculateDateRange(prefix) {
    const startInput = $(`#${prefix}StartDate`);
    const endInput = $(`#${prefix}EndDate`);
    const display = $(`#${prefix}DateRangeDisplay`);
    const daysCount = $(`#${prefix}DaysCount`);
    if (!startInput || !endInput || !display || !daysCount) return;
    try {
        const startDate = new Date(startInput.value + 'T00:00:00');
        const endDate = new Date(endInput.value + 'T00:00:00');
        if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
             display.textContent = 'รูปแบบวันที่ไม่ถูกต้อง'; daysCount.textContent = 'N/A';
             display.classList.add('text-danger'); endInput.setCustomValidity('รูปแบบวันที่ไม่ถูกต้อง');
             return;
        }
        if (endDate < startDate) {
             display.textContent = 'ช่วงวันที่ไม่ถูกต้อง'; daysCount.textContent = '0';
             display.classList.add('text-danger'); endInput.setCustomValidity('วันที่สิ้นสุดต้องไม่น้อยกว่าวันเริ่มต้น');
             return;
        }
        const diffTime = endDate.getTime() - startDate.getTime();
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.','ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        const startStr = `${startDate.getDate()} ${thaiMonths[startDate.getMonth()]} ${startDate.getFullYear() + 543}`;
        const endStr = `${endDate.getDate()} ${thaiMonths[endDate.getMonth()]} ${endDate.getFullYear() + 543}`;
        display.textContent = `${startStr} - ${endStr}`;
        daysCount.textContent = diffDays.toLocaleString('th-TH');
        endInput.setCustomValidity('');
        display.classList.remove('text-danger');
    } catch (e) {
        display.textContent = 'Error'; daysCount.textContent = 'N/A';
        display.classList.add('text-danger'); endInput.setCustomValidity('Error');
    }
}

// ===== MODAL CALC (แก้ไขเพื่อให้ง่ายขึ้น) =====
function updateModalCalc(prefix) {
    if (prefix === 'dividend') {
        const profitInput = $('#modalProfit');
        const rateInput = $('#modalRate');
        const totalDisplay = $('#modalTotal');
        const totalHidden = $('#modalTotalHidden');
        if (!profitInput || !rateInput || !totalDisplay || !totalHidden) return;
        const profit = parseFloat(profitInput.value || '0');
        const rate = parseFloat(rateInput.value || '0');
        const totalDividend = profit * (rate / 100);
        totalDisplay.value = '฿' + totalDividend.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        totalHidden.value = totalDividend.toFixed(2);
    } else if (prefix === 'rebate') {
        const profitInput = $('#rebateModalProfit');
        const rateInput = $('#modalRebateRatePercent'); 
        const totalDisplay = $('#modalRebateTotal');
        const totalHidden = $('#modalRebateTotalHidden');
        const valueHidden = $('#modalRebateValueHidden');

        if (!profitInput || !rateInput || !totalDisplay || !totalHidden || !valueHidden) return;

        const profit = parseFloat(profitInput.value || '0');
        const rate = parseFloat(rateInput.value || '0'); 
        
        const rebateBudget = profit * (rate / 100);

        totalDisplay.value = '฿' + rebateBudget.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        totalHidden.value = rebateBudget.toFixed(2);
        valueHidden.value = rate.toFixed(4);
    }
}

// ===== MEMBER HISTORY (คงเดิม) =====
async function viewMemberHistory(memberKey) {
  const [memberType, memberId] = memberKey.split('_');
  if (!memberId || !memberType) { toast('ข้อมูลสมาชิกไม่ถูกต้อง', false); return; }
  const member = membersData.find(m => `${m.type}_${m.id}` === memberKey);
  if (!member) { toast('ไม่พบข้อมูลสมาชิก', false); return; }

  $('#historyMemberId').textContent = member.code;
  $('#historyMemberName').textContent = member.member_name;
  $('#historyMemberType').textContent = member.type_th;

  const historyTable = $('#memberHistoryTable');
  const summaryDiv = $('.history-summary');
  historyTable.innerHTML = '<tr><td colspan="5" class="text-center p-3"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';
  if (summaryDiv) summaryDiv.innerHTML = '';

  const historyModal = new bootstrap.Modal('#modalMemberHistory');
  historyModal.show();

  try {
      const response = await fetch(`dividend_member_history.php?member_id=${member.id}&member_type=${member.type}`);
      if (!response.ok) throw new Error(`ไม่สามารถดึงข้อมูลได้ (HTTP ${response.status})`);
      const data = await response.json();

      if (data.ok && data.history.length > 0) {
          const summary = data.summary;
          const summaryHtml = `
              <div class="alert alert-light border small mb-3">
                  <div class="row text-center">
                      <div class="col-6 col-md-3"><small class="text-muted d-block">ปันผล(รับแล้ว)</small><strong class="text-success">${summary.total_received_formatted}</strong></div>
                      <div class="col-6 col-md-3"><small class="text-muted d-block">ปันผล(ค้างรับ)</small><strong class="text-warning">${summary.total_pending_formatted}</strong></div>
                      <div class="col-6 col-md-3"><small class="text-muted d-block">เฉลี่ยคืน(รับแล้ว)</small><strong class="text-info">${summary.total_rebate_received_formatted || '฿0.00'}</strong></div>
                      <div class="col-6 col-md-3"><small class="text-muted d-block">เฉลี่ยคืน(ค้างรับ)</small><strong class="text-warning">${summary.total_rebate_pending_formatted || '฿0.00'}</strong></div>
                  </div>
              </div>`;
         if (summaryDiv) summaryDiv.innerHTML = summaryHtml;

          let html = '';
          data.history.forEach(item => {
              const statusClass = item.payment_status === 'paid' ? 'status-paid' : (item.payment_status === 'approved' ? 'status-approved' : 'status-pending');
              const statusText = item.payment_status === 'paid' ? 'จ่ายแล้ว' : (item.payment_status === 'approved' ? 'อนุมัติ' : 'รอจ่าย');
              const isDividend = item.type === 'ปันผล (หุ้น)';

              html += `
                  <tr>
                      <td><strong>ปี ${item.year}</strong></td>
                      <td>${item.type}</td>
                      <td class="text-end small">
                          ${isDividend ?
                              `${(item.shares_at_time || 0).toLocaleString('th-TH')} หุ้น @ ${parseFloat(item.dividend_rate || 0).toFixed(1)}%` :
                              `ซื้อ ${nf(item.purchase_amount_at_time || 0)} @ ${nf(item.rebate_per_baht || 0, 4)}/บาท`
                          }
                      </td>
                      <td class="text-end"><strong class="${isDividend ? 'text-success' : 'text-info'}">${item.amount_formatted || '฿0.00'}</strong></td>
                      <td class="text-center">
                          <span class="${statusClass}">${statusText}</span>
                          ${item.payment_date_formatted !== '-' ? `<br><small class="text-muted">${item.payment_date_formatted}</small>` : ''}
                      </td>
                  </tr>`;
          });
          historyTable.innerHTML = html;
      } else if (data.ok) {
         if (summaryDiv) summaryDiv.innerHTML = '';
          historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-muted p-4"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>ยังไม่มีประวัติการรับปันผล/เฉลี่ยคืน</td></tr>`;
      } else {
           throw new Error(data.error || 'ไม่สามารถโหลดข้อมูลประวัติได้');
      }
  } catch (error) {
      console.error('History fetch error:', error);
      if (summaryDiv) summaryDiv.innerHTML = '';
      historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger p-3"><i class="bi bi-exclamation-triangle me-1"></i>เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
  }
}

// ===== DIVIDEND ACTIONS (คงเดิม) =====
function viewDividendDetails(periodId) {
    window.location.href = `dividend_detail.php?id=${periodId}`;
}
async function processPayout(periodId, csrfToken) {
    const period = dividendPeriodsData.find(p => p.id === periodId);
    if (!confirm(`ยืนยันการจ่ายปันผลปี ${period?.year || periodId}?\n\nสถานะจะเปลี่ยนเป็น "จ่ายแล้ว"`)) { return; }
    try {
        const response = await fetch('dividend_payout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ period_id: periodId, csrf_token: csrfToken })
        });
        const data = await response.json();
        if (data.ok) {
            toast(data.message || 'บันทึกการจ่ายปันผลสำเร็จ', true);
            setTimeout(() => window.location.reload(), 2000);
        } else { throw new Error(data.error || 'การดำเนินการล้มเหลว'); }
    } catch (error) { toast('เกิดข้อผิดพลาด: ' + error.message, false); }
}
async function approveDividend(periodId, csrfToken) {
    const period = dividendPeriodsData.find(p => p.id === periodId);
     if (!confirm(`ยืนยันการอนุมัติงวดปันผลปี ${period?.year || periodId}?\n\nสถานะจะเปลี่ยนเป็น "อนุมัติแล้ว"`)) { return; }
     try {
        const response = await fetch('dividend_approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ period_id: periodId, csrf_token: csrfToken })
        });
        const data = await response.json();
        if (data.ok) {
            toast(data.message || 'อนุมัติงวดปันผลสำเร็จ', true);
            setTimeout(() => window.location.reload(), 2000);
        } else { throw new Error(data.error || 'การอนุมัติล้มเหลว'); }
    } catch (error) { toast('เกิดข้อผิดพลาด: ' + error.message, false); }
}

// ===== REBATE ACTIONS (คงเดิม) =====
function viewRebateDetails(periodId) {
    window.location.href = `rebate_detail.php?id=${periodId}`;
}
async function approveRebate(periodId, csrfToken) {
    const period = rebatePeriodsData.find(p => p.id === periodId);
    if (!confirm(`ยืนยันการอนุมัติงวดเฉลี่ยคืนปี ${period?.year || periodId}?`)) { return; }
    try {
        const response = await fetch('rebate_approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ period_id: periodId, csrf_token: csrfToken })
        });
        const data = await response.json();
        if (data.ok) {
            toast(data.message || 'อนุมัติงวดเฉลี่ยคืนสำเร็จ', true);
            setTimeout(() => window.location.reload(), 2000);
        } else { throw new Error(data.error || 'การอนุมัติล้มเหลว'); }
    } catch (error) { toast('เกิดข้อผิดพลาด: ' + error.message, false); }
}
async function processRebatePayout(periodId, csrfToken) {
    const period = rebatePeriodsData.find(p => p.id === periodId);
    if (!confirm(`ยืนยันการจ่ายเงินเฉลี่ยคืนปี ${period?.year || periodId}?`)) { return; }
    try {
        const response = await fetch('rebate_payout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ period_id: periodId, csrf_token: csrfToken })
        });
        const data = await response.json();
        if (data.ok) {
            toast(data.message || 'บันทึกการจ่ายเฉลี่ยคืนสำเร็จ', true);
            setTimeout(() => window.location.reload(), 2000);
        } else { throw new Error(data.error || 'การดำเนินการล้มเหลว'); }
    } catch (error) { toast('เกิดข้อผิดพลาด: ' + error.message, false); }
}

// ===== EXPORT (คงเดิม) =====
function exportMembers() {
  const headers = ['รหัส', 'ชื่อ', 'ประเภท', 'หุ้น', 'รวมรับปันผล', 'รวมรับเฉลี่ยคืน'];
  const rows = [headers];
  $$('#membersTable tbody tr').forEach(tr => {
      if (tr.style.display === 'none') return;
      const cells = tr.querySelectorAll('td');
      if (cells.length >= 7) {
          rows.push([
              cells[0].textContent.trim(), // Code
              cells[1].textContent.trim(), // Name
              cells[2].textContent.trim(), // Type
              tr.dataset.shares,           // Shares
              cells[4].textContent.replace(/[฿,]/g, '').trim(), // Total Dividend
              cells[5].textContent.replace(/[฿,]/g, '').trim()  // Total Rebate
          ]);
      }
  });
  if (rows.length <= 1) { toast('ไม่มีข้อมูลที่จะส่งออก', false); return; }
  const csv = rows.map(r => r.map(v => `"${(v === null || v === undefined ? '' : v).toString().replace(/"/g,'""')}"`).join(',')).join('\n');
  const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `dividend_members_${new Date().toISOString().slice(0,10)}.csv`;
  link.click();
  URL.revokeObjectURL(link.href);
}

// ===== INITIALIZE =====
document.addEventListener('DOMContentLoaded', () => {
    // Listeners for Dividend Modal
    $('#dividendStartDate')?.addEventListener('change', () => calculateDateRange('dividend'));
    $('#dividendEndDate')?.addEventListener('change', () => calculateDateRange('dividend'));
    calculateDateRange('dividend');
    $('#modalProfit')?.addEventListener('input', () => updateModalCalc('dividend'));
    $('#modalRate')?.addEventListener('input', () => updateModalCalc('dividend'));
    
    // Listeners for Rebate Modal
    $('#rebateStartDate')?.addEventListener('change', () => calculateDateRange('rebate'));
    $('#rebateEndDate')?.addEventListener('change', () => calculateDateRange('rebate'));
    calculateDateRange('rebate');
    $('#rebateModalProfit')?.addEventListener('input', () => updateModalCalc('rebate'));
    $('#modalRebateRatePercent')?.addEventListener('input', () => updateModalCalc('rebate'));

    // Activate tab based on URL hash
    const hash = window.location.hash || '#periods-panel';
    const tabTrigger = $(`button[data-bs-target="${hash}"]`);
    if (tabTrigger) {
        bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
    }
     $$('button[data-bs-toggle="tab"]').forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', event => {
            history.pushState(null, null, event.target.dataset.bsTarget);
        })
    });
});
</script>
</body>
</html>