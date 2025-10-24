<?php
// dividend.php — จัดการปันผลสหกรณ์ (รวมปันผลตามหุ้น + เฉลี่ยคืนจากยอดซื้อรายปี)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอินและสิทธิ์ =====
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
    exit();
}

// ===== เชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์ =====
try {
    $current_name = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
    $current_role = $_SESSION['role'] ?? 'guest';
    if ($current_role !== 'admin') {
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

// ===== Helpers =====
if (!function_exists('nf')) {
    function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
}
if (!function_exists('d')) {
    function d($s, $fmt = 'd/m/Y') { $t = strtotime($s); return $t ? date($fmt, $t) : '-'; }
}

// ===== เตรียมข้อมูล =====
$dividend_periods = [];
$members_dividends = [];
$error_message = null;

try {
    // 1) งวดปันผล (รายปี)
    // [แก้ไข] ดึงคอลัมน์ใหม่ๆ ของ rebate มาด้วย (ถ้ามีในตาราง)
    $dividend_periods = $pdo->query("
        SELECT id, `year`, start_date, end_date, period_name, total_profit, dividend_rate,
               total_shares_at_time, total_dividend_amount, status, payment_date,
               created_at, approved_by
               /* , rebate_base, rebate_type, rebate_value, rebate_mode (เพิ่มคอลัมน์ของคุณที่นี่) */
        FROM dividend_periods
        ORDER BY `year` DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2) รวมสมาชิกทุกประเภท
    $all_members = [];

    // 2.1) สมาชิกทั่วไป
    try {
        $stmt = $pdo->query("
            SELECT
                m.id AS member_id,
                m.member_code,
                u.full_name,
                m.shares,
                'member' AS member_type
            FROM members m
            JOIN users u ON m.user_id = u.id
            WHERE m.is_active = 1
        ");
        $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { error_log("Error fetching members: " . $e->getMessage()); }

    // 2.2) ผู้บริหาร
    try {
        $stmt = $pdo->query("
            SELECT
                mg.id AS member_id,
                CONCAT('MGR-', LPAD(mg.id, 3, '0')) AS member_code,
                u.full_name,
                mg.shares,
                'manager' AS member_type
            FROM managers mg
            JOIN users u ON mg.user_id = u.id
        ");
        $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { error_log("Error fetching managers: " . $e->getMessage()); }

    // 2.3) กรรมการ
    try {
        $stmt = $pdo->query("
            SELECT
                c.id AS member_id,
                c.committee_code AS member_code,
                u.full_name,
                COALESCE(c.shares, 0) AS shares,
                'committee' AS member_type
            FROM committees c
            JOIN users u ON c.user_id = u.id
        ");
        $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { error_log("Error fetching committees: " . $e->getMessage()); }

    // เรียงตามรหัส
    usort($all_members, function($a, $b) {
        return strcmp($a['member_code'] ?? '', $b['member_code'] ?? '');
    });

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
            'type_th' => [
                'member' => 'สมาชิก',
                'manager' => 'ผู้บริหาร',
                'committee' => 'กรรมการ'
            ][$row['member_type']] ?? 'อื่นๆ',
            'payments' => [],
            'total_received' => 0.0
        ];
    }

    // 3) การจ่ายปันผล (เดิม)
    $payments_stmt = $pdo->query("
        SELECT dp.member_id, dp.member_type, dp.period_id,
               dp.dividend_amount, dp.payment_status
        FROM dividend_payments dp
        /* [หมายเหตุ] คุณต้อง JOIN ตารางจ่ายเฉลี่ยคืน (ถ้ามี) มาด้วย */
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

} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    error_log("Dividend page error: " . $e->getMessage());
}

// ===== ดึงข้อมูลระบบ =====
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
    $st = $pdo->query("SELECT site_name FROM settings WHERE id=1");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $site_name = $r['site_name'] ?: $site_name;
    }
} catch (Throwable $e) {}

$role_th_map = [
    'admin'=>'ผู้ดูแลระบบ',
    'manager'=>'ผู้บริหาร',
    'employee'=>'พนักงาน',
    'member'=>'สมาชิกสหกรณ์',
    'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ===== คำนวณสถิติรวม =====
$total_dividend_paid = 0;
$pending_dividend = 0;
$total_members = count($members_dividends);
$total_shares = array_sum(array_column($members_dividends, 'shares'));

try {
    $stats = $pdo->query("
        SELECT
            (SELECT COALESCE(SUM(total_dividend_amount), 0)
             FROM dividend_periods WHERE status = 'paid') as total_paid,
            (SELECT COALESCE(SUM(total_dividend_amount), 0)
             FROM dividend_periods WHERE status = 'approved') as total_pending
         /* [หมายเหตุ] ควรอัปเดต Query นี้ให้รวมยอดเฉลี่ยคืนด้วย */
    ")->fetch(PDO::FETCH_ASSOC);

    $total_dividend_paid = (float)($stats['total_paid'] ?? 0);
    $pending_dividend = (float)($stats['total_pending'] ?? 0);
} catch (Throwable $e) {}
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
        .status-paid, .status-approved, .status-pending {
            padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; display: inline-block;
        }
        .status-paid { background-color: var(--bs-success-bg-subtle); color: var(--bs-success-text-emphasis); }
        .status-approved { background-color: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
        .status-pending { background-color: var(--bs-danger-bg-subtle); color: var(--bs-danger-text-emphasis); }

        .dividend-card { transition: all .2s ease-in-out; }
        .dividend-card:hover { transform: translateY(-3px); box-shadow: var(--bs-box-shadow-sm); }
        .dividend-amount { font-size: 1.5rem; font-weight: 700; color: var(--bs-success); }
        .dividend-rate { font-size: 1.2rem; font-weight: 600; color: var(--bs-primary); }

        .member-type-badge { font-size:.75rem; padding:.2rem .5rem; border-radius:12px; font-weight: 500;}
        .type-member { background-color: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); }
        .type-manager { background-color: var(--bs-info-bg-subtle); color: var(--bs-info-text-emphasis); }
        .type-committee { background-color: var(--bs-secondary-bg-subtle); color: var(--bs-secondary-text-emphasis); }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .nav-tabs .nav-link { font-weight: 600; }
        .nav-tabs .nav-link.active { color: var(--bs-primary); border-bottom-color: var(--bs-primary); }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand" href="admin_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
        </div>
        <div class="d-flex align-items-center gap-3 ms-auto">
            <div class="nav-identity text-end d-none d-sm-block">
                <div class="nav-name"><?= htmlspecialchars($current_name) ?></div>
                <div class="nav-sub"><?= htmlspecialchars($current_role_th) ?></div>
            </div>
            <a href="profile.php" class="avatar-circle text-decoration-none">
                <?= htmlspecialchars($avatar_text) ?>
            </a>
        </div>
    </div>
</nav>

<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
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
            <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
            <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
            <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่าระบบ</a>
        </nav>
        <a class="logout mt-auto" href="/index/logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ
        </a>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
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
                <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
                <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
                <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
            </nav>
            <a class="logout" href="/index/logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ
            </a>
        </aside>

        <main class="col-lg-10 p-4">
            <div class="main-header mb-4">
                <h2 class="mb-0"><i class="fa-solid fa-gift me-2"></i> จัดการปันผล</h2>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="card card-body shadow-sm text-center">
                    <div class="fs-4 text-success mb-2"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                    <h6 class="text-muted mb-1">ปันผลจ่ายแล้ว (รวม)</h6>
                    <h3 class="mb-0 text-success">฿<?= number_format($total_dividend_paid, 2) ?></h3>
                </div>
                <div class="card card-body shadow-sm text-center">
                    <div class="fs-4 text-primary mb-2"><i class="bi bi-people-fill"></i></div>
                    <h6 class="text-muted mb-1">ผู้ถือหุ้นปัจจุบัน</h6>
                    <h3 class="mb-0 text-primary"><?= number_format($total_members) ?> คน</h3>
                    <small class="text-muted">หุ้นรวม <?= number_format($total_shares) ?></small>
                </div>
                <div class="card card-body shadow-sm text-center">
                    <div class="fs-4 text-warning mb-2"><i class="bi bi-hourglass-split"></i></div>
                    <h6 class="text-muted mb-1">ปันผลรอจ่าย (อนุมัติแล้ว)</h6>
                    <h3 class="mb-0 text-warning">฿<?= number_format($pending_dividend, 2) ?></h3>
                </div>
            </div>

            <ul class="nav nav-tabs mb-3" id="dividendTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="periods-tab" data-bs-toggle="tab" data-bs-target="#periods-panel" type="button" role="tab" aria-controls="periods-panel" aria-selected="true">
                        <i class="fa-solid fa-calendar-days me-2"></i>งวดปันผลรายปี
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
                    <div class="card shadow-sm mb-4">
                       <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fa-solid fa-calendar-days me-2"></i>งวดปันผลทั้งหมด</h5>
                             <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCreateDividend">
                                <i class="fa-solid fa-plus me-1"></i> สร้างงวดปันผล
                            </button>
                       </div>
                       <div class="card-body">
                           <div class="row g-3">
                               <?php if (empty($dividend_periods)): ?>
                                   <div class="col-12">
                                       <div class="alert alert-info text-center mb-0">
                                           <i class="bi bi-info-circle fs-3 d-block mb-2"></i>
                                           <h5>ยังไม่มีงวดปันผล</h5>
                                           <p class="mb-0">คลิกปุ่ม "สร้างงวดปันผล" เพื่อเริ่มต้น</p>
                                       </div>
                                   </div>
                               <?php else: ?>
                                   <?php foreach($dividend_periods as $period): ?>
                                   <div class="col-md-6 col-lg-4">
                                       <div class="card dividend-card h-100 shadow-sm">
                                          <div class="card-body d-flex flex-column">
                                               <div class="d-flex justify-content-between align-items-start mb-2">
                                                   <div>
                                                       <h5 class="card-title mb-0">ปี <?= htmlspecialchars($period['year']) ?></h5>
                                                       <small class="text-muted d-block mb-1"><?= htmlspecialchars($period['period_name']) ?></small>
                                                   </div>
                                                   <span class="status-<?= htmlspecialchars($period['status']) ?>">
                                                        <?= ['paid' => 'จ่ายแล้ว', 'approved' => 'อนุมัติ', 'pending' => 'รออนุมัติ'][$period['status']] ?? '?' ?>
                                                   </span>
                                               </div>

                                               <div class="row text-center my-3 flex-grow-1 align-items-center">
                                                   <div class="col-6 border-end">
                                                       <small class="text-muted">ปันผล (หุ้น)</small><br>
                                                       <span class="dividend-rate"><?= number_format($period['dividend_rate'], 1) ?>%</span>
                                                   </div>
                                                   <div class="col-6">
                                                       <small class="text-muted">ยอดรวม (หุ้น)</small><br>
                                                       <span class="dividend-amount">฿<?= number_format($period['total_dividend_amount'], 0) ?></span>
                                                   </div>
                                                    </div>

                                               <div class="d-flex flex-column gap-1 small text-muted border-top pt-2 mb-3">
                                                   <div class="d-flex justify-content-between"><span>กำไรสุทธิ:</span> <span>฿<?= number_format($period['total_profit'], 0) ?></span></div>
                                                   <div class="d-flex justify-content-between"><span>จำนวนหุ้น:</span> <span><?= number_format($period['total_shares_at_time']) ?> หุ้น</span></div>
                                                   <?php if($period['payment_date']): ?>
                                                   <div class="d-flex justify-content-between"><span>วันที่จ่าย:</span> <span><?= d($period['payment_date']) ?></span></div>
                                                   <?php endif; ?>
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
                                   </div>
                                   <?php endforeach; ?>
                               <?php endif; ?>
                           </div>
                       </div>
                   </div>
                </div>

                <div class="tab-pane fade" id="members-panel" role="tabpanel" aria-labelledby="members-tab">
                    <div class="card shadow-sm">
                        <div class="card-header bg-light">
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>ผู้ถือหุ้น (<?= $total_members ?> คน)</h5>
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
                                    </select>
                                    <input type="number" id="minShares" class="form-control form-control-sm" placeholder="หุ้นขั้นต่ำ" min="0" style="max-width:100px;">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="exportMembers()" title="ส่งออก CSV">
                                        <i class="bi bi-download"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0" id="membersTable">
                                    <thead>
                                        <tr>
                                            <th>รหัส</th>
                                            <th>ชื่อ</th>
                                            <th class="text-center">ประเภท</th>
                                            <th class="text-center">หุ้น</th>
                                            <?php foreach ($dividend_periods as $period): ?>
                                            <th class="text-end d-none d-lg-table-cell" title="<?= htmlspecialchars($period['period_name']) ?>">
                                                <?= htmlspecialchars($period['year']) ?>
                                            </th>
                                            <?php endforeach; ?>
                                            <th class="text-end">รวมรับ</th>
                                            <th class="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($members_dividends)): ?>
                                            <tr><td colspan="<?= 6 + count($dividend_periods) ?>" class="text-center text-muted p-4">ไม่มีข้อมูลผู้ถือหุ้น</td></tr>
                                        <?php endif; ?>
                                        <?php foreach($members_dividends as $key => $member): ?>
                                        <tr class="member-row"
                                            data-member-key="<?= htmlspecialchars($key) ?>"
                                            data-member-name="<?= htmlspecialchars($member['member_name']) ?>"
                                            data-member-type="<?= htmlspecialchars($member['type']) ?>"
                                            data-shares="<?= (int)$member['shares'] ?>">
                                            <td><strong><?= htmlspecialchars($member['code']) ?></strong></td>
                                            <td><?= htmlspecialchars($member['member_name']) ?></td>
                                            <td class="text-center">
                                                <span class="member-type-badge type-<?= htmlspecialchars($member['type']) ?>">
                                                    <?= htmlspecialchars($member['type_th']) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary rounded-pill">
                                                    <?= number_format($member['shares']) ?>
                                                </span>
                                            </td>
                                            <?php foreach ($dividend_periods as $period): ?>
                                            <td class="text-end d-none d-lg-table-cell small">
                                                <?= number_format($member['payments'][$period['id']] ?? 0, 2) ?>
                                            </td>
                                            <?php endforeach; ?>
                                            <td class="text-end">
                                                <strong class="text-success">
                                                    ฿<?= number_format($member['total_received'], 2) ?>
                                                </strong>
                                            </td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-outline-info py-0 px-1" title="ดูประวัติ"
                                                        onclick="viewMemberHistory('<?= htmlspecialchars($key) ?>')">
                                                    <i class="bi bi-clock-history"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                </div> </main>
    </div>
</div>

<footer class="footer mt-auto py-3 bg-light">
    <div class="container text-center">
        <span class="text-muted">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>
    </div>
</footer>

<div class="modal fade" id="modalCreateDividend" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="dividend_create_period.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa-solid fa-plus me-2"></i>สร้างงวดปันผลและเฉลี่ยคืน</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label"><i class="bi bi-calendar me-1"></i>ปี <span class="text-danger">*</span></label>
                        <input type="number" name="year" id="dividendYear" class="form-control" value="<?= date('Y') ?>" required min="2020" max="2050" onchange="updateDateRange()">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label"><i class="bi bi-card-text me-1"></i>ชื่องวด</label>
                        <input type="text" name="period_name" id="periodName" class="form-control" placeholder="เช่น ปันผลประจำปี <?= date('Y') ?>" value="ปันผลประจำปี <?= date('Y') ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label"><i class="bi bi-calendar-event me-1"></i>วันที่เริ่มต้น <span class="text-danger">*</span></label>
                        <input type="date" name="start_date" id="startDate" class="form-control" value="<?= date('Y') ?>-01-01" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label"><i class="bi bi-calendar-check me-1"></i>วันที่สิ้นสุด <span class="text-danger">*</span></label>
                        <input type="date" name="end_date" id="endDate" class="form-control" value="<?= date('Y') ?>-12-31" required>
                    </div>
                    <div class="col-12">
                         <div class="alert alert-light border mb-0 py-2">
                             <small class="text-muted d-flex justify-content-between">
                                 <span><i class="bi bi-calendar-range me-1"></i> <strong>ช่วงเวลา:</strong> <span id="dateRangeDisplay">1 ม.ค. <?= date('Y') ?> - 31 ธ.ค. <?= date('Y') ?></span></span>
                                 <span>(<span id="daysCount">365</span> วัน)</span>
                             </small>
                         </div>
                    </div>

                    <div class="col-12"><hr class="my-2"><h6 class="text-primary"><i class="bi bi-coin me-2"></i>1. ปันผลตามหุ้น</h6></div>
                    <div class="col-sm-6">
                        <label class="form-label"><i class="bi bi-currency-dollar me-1"></i>กำไรสุทธิ (บาท) <span class="text-danger">*</span></label>
                        <input type="number" name="total_profit" id="modalProfit" class="form-control" step="0.01" min="0.01" required placeholder="0.00" oninput="updateModalCalc()">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label"><i class="bi bi-percent me-1"></i>อัตราปันผล (หุ้น) (%) <span class="text-danger">*</span></label>
                        <input type="number" name="dividend_rate" id="modalRate" class="form-control" step="0.1" min="0.1" max="100" required placeholder="เช่น 15" oninput="updateModalCalc()">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label"><i class="bi bi-diagram-3 me-1"></i>จำนวนหุ้นรวม (ณ สิ้นงวด)</label>
                         <input type="number" name="total_shares_at_time" class="form-control bg-light" value="<?= $total_shares ?>" required readonly>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label"><i class="bi bi-cash-stack me-1"></i>ยอดปันผล (หุ้น) (บาท)</label>
                        <input type="text" id="modalTotal" name="total_dividend_amount_display" class="form-control bg-light" value="0.00" readonly>
                         <input type="hidden" name="total_dividend_amount" id="modalTotalHidden" value="0">
                    </div>

                    <div class="col-12"><hr class="my-2"><h6 class="text-info"><i class="bi bi-arrow-repeat me-2"></i>2. เฉลี่ยคืนตามยอดซื้อ</h6></div>
                     <div class="col-sm-6 col-md-4">
                        <label class="form-label">ฐานคำนวณ</label>
                        <select name="rebate_base" id="modalRebateBase" class="form-select" onchange="updateModalCalc()">
                            <option value="profit" selected>จากกำไรสุทธิ</option>
                            <option value="net">จากคงเหลือ (หลังหักสำรอง)</option>
                        </select>
                    </div>
                     <div class="col-sm-6 col-md-4">
                        <label class="form-label">รูปแบบเฉลี่ย</label>
                        <select name="rebate_mode" id="modalRebateMode" class="form-select">
                            <option value="weighted" selected>ถ่วงน้ำหนักตามยอดซื้อ</option>
                            <option value="equal">เท่ากัน (เฉพาะผู้ซื้อ)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                         <label class="form-label"><i class="bi bi-cash-stack me-1"></i>ยอดเฉลี่ยคืน (บาท)</label>
                         <input type="text" id="modalRebateTotal" class="form-control bg-light" value="0.00" readonly>
                         <input type="hidden" name="rebate_value" id="modalRebateValueHidden" value="0"> </div>
                    <div class="col-12">
                        <label class="form-label">กำหนดงบเฉลี่ยคืน</label>
                        <div class="input-group">
                           <input type="number" id="modalRebateRate" class="form-control" placeholder="%" step="0.1" oninput="updateModalRebateType('rate')">
                            <span class="input-group-text">%</span>
                            <span class="input-group-text">หรือ</span>
                            <input type="number" id="modalRebateFixed" class="form-control" placeholder="บาท" step="0.01" oninput="updateModalRebateType('fixed')">
                            <span class="input-group-text">บาท</span>
                        </div>
                        <input type="hidden" name="rebate_type" id="modalRebateTypeHidden" value="rate"> </div>

                    <div class="col-12">
                        <label class="form-label"><i class="bi bi-chat-left-text me-1"></i>หมายเหตุ (ถ้ามี)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="เช่น รายละเอียดการคำนวณ, มติที่ประชุม..."></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>ยกเลิก</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save2 me-1"></i>สร้างงวดปันผล</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalMemberHistory" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>ประวัติการรับปันผล</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3 small">
                    <div class="col-sm-4"><strong>รหัส:</strong> <span id="historyMemberId">-</span></div>
                    <div class="col-sm-5"><strong>ชื่อ:</strong> <span id="historyMemberName">-</span></div>
                    <div class="col-sm-3"><strong>ประเภท:</strong> <span id="historyMemberType">-</span></div>
                </div>
                <div class="history-summary mb-3"></div> <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ปี</th>
                                <th class="text-center">หุ้น (ณ เวลานั้น)</th>
                                <th class="text-end">อัตรา (%)</th>
                                <th class="text-end">ปันผลที่ได้รับ</th>
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
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const $  = (s, p=document) => p.querySelector(s);
const $$ = (s, p=document) => [...p.querySelectorAll(s)];

const membersData = <?= json_encode(array_values($members_dividends), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const dividendPeriodsData = <?= json_encode($dividend_periods, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;

const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
// [ลบ] Calculator-related variables
// const purchasesByMember = {};
// const codeToKey = {};
// membersData.forEach(m => { codeToKey[m.code] = `${m.type}_${m.id}`; });

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

// ===== [ลบ] CALCULATOR FUNCTIONS =====
// [ลบ] calculateDividend()
// [ลบ] updateDividendPreview()
// [ลบ] applyRebateYear()
// [ลบ] fetchPurchasesByRange()

// ===== MODAL DATE RANGE (คงเดิม) =====
function updateDateRange() {
    const yearSelect = $('#dividendYear');
    const startDateInput = $('#startDate');
    const endDateInput = $('#endDate');
    const periodNameInput = $('#periodName');
    if (!yearSelect || !startDateInput || !endDateInput) return;

    const year = yearSelect.value || <?= (int)date('Y') ?>;
    startDateInput.value = `${year}-01-01`;
    endDateInput.value = `${year}-12-31`;

    if (periodNameInput && (periodNameInput.value === '' || periodNameInput.value.startsWith('ปันผลประจำปี'))) {
        periodNameInput.value = `ปันผลประจำปี ${year}`;
    }
    calculateDateRange();
}

function calculateDateRange() {
    const startInput = $('#startDate');
    const endInput = $('#endDate');
    const display = $('#dateRangeDisplay');
    const daysCount = $('#daysCount');
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

// ===== [แก้ไข] MODAL CALC (สำหรับคำนวณยอดสรุปใน Modal) =====
function updateModalCalc() {
    // 1. ดึงค่า Input
    const profitInput = $('#modalProfit');
    const rateInput = $('#modalRate');
    const rebateBaseSelect = $('#modalRebateBase');
    const rebateTypeHidden = $('#modalRebateTypeHidden');
    const rebateRateInput = $('#modalRebateRate');
    const rebateFixedInput = $('#modalRebateFixed');

    // 2. ดึงค่า Output
    const totalDisplay = $('#modalTotal');
    const totalHidden = $('#modalTotalHidden');
    const rebateTotalDisplay = $('#modalRebateTotal');
    const rebateValueHidden = $('#modalRebateValueHidden');

    if (!profitInput || !rateInput || !totalDisplay || !totalHidden || !rebateBaseSelect || !rebateTotalDisplay) return;

    // 3. คำนวณปันผลตามหุ้น
    const profit = parseFloat(profitInput.value || '0');
    const rate = parseFloat(rateInput.value || '0');
    const totalDividend = profit * (rate / 100);

    totalDisplay.value = '฿' + totalDividend.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    totalHidden.value = totalDividend.toFixed(2);

    // 4. คำนวณงบเฉลี่ยคืน
    const reserveFund = profit * 0.10;
    const welfareFund = profit * 0.05;
    const netAvailable = profit - reserveFund - welfareFund; // 85%

    const rebateBase = rebateBaseSelect.value || 'profit';
    const rebateType = rebateTypeHidden.value || 'rate';
    const rebateRate = parseFloat(rebateRateInput.value || '0');
    const rebateFixed = parseFloat(rebateFixedInput.value || '0');

    const baseAmt = (rebateBase === 'net') ? Math.max(0, netAvailable) : profit;
    let rebateBudget = 0;
    let rebateValue = 0; // ค่าที่จะส่งไป PHP

    if (rebateType === 'fixed') {
        rebateBudget = Math.max(0, rebateFixed || 0);
        rebateValue = rebateBudget;
    } else { // rate
        rebateBudget = Math.max(0, baseAmt * (rebateRate / 100));
        rebateValue = rebateRate;
    }

    rebateTotalDisplay.value = '฿' + rebateBudget.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    rebateValueHidden.value = rebateValue.toFixed(4); // ส่งค่า % หรือ บาท
}

// [เพิ่ม] Function สลับประเภทการกรอกเฉลี่ยคืนใน Modal
function updateModalRebateType(selectedType) {
    const hiddenInput = $('#modalRebateTypeHidden');
    const rateInput = $('#modalRebateRate');
    const fixedInput = $('#modalRebateFixed');

    if (!hiddenInput || !rateInput || !fixedInput) return;

    hiddenInput.value = selectedType;

    if (selectedType === 'rate') {
        fixedInput.value = ''; // Clear the other input
        rateInput.disabled = false;
        fixedInput.disabled = true;
    } else { // fixed
        rateInput.value = ''; // Clear the other input
        fixedInput.disabled = false;
        rateInput.disabled = true;
    }
    // คำนวณใหม่ทุกครั้งที่เปลี่ยน
    updateModalCalc();
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
      const response = await fetch(`dividend_member_history.php?member_id=${memberId}&member_type=${memberType}`);
      if (!response.ok) throw new Error(`ไม่สามารถดึงข้อมูลได้ (HTTP ${response.status})`);
      const data = await response.json();

      if (data.ok && data.history.length > 0) {
          const summary = data.summary;
          const summaryHtml = `
              <div class="alert alert-light border small mb-3">
                  <div class="row text-center">
                      <div class="col-4"><small class="text-muted d-block">รับแล้ว</small><strong class="text-success">${summary.total_received_formatted}</strong></div>
                      <div class="col-4"><small class="text-muted d-block">ค้างรับ</small><strong class="text-warning">${summary.total_pending_formatted}</strong></div>
                      <div class="col-4"><small class="text-muted d-block">จำนวนงวด</small><strong>${summary.payment_count} ครั้ง</strong></div>
                  </div>
              </div>`;
         if (summaryDiv) summaryDiv.innerHTML = summaryHtml;

          let html = '';
          data.history.forEach(item => {
              const statusClass = item.payment_status === 'paid' ? 'status-paid' :
                                  item.payment_status === 'approved' ? 'status-approved' : 'status-pending';
              const statusText = item.payment_status === 'paid' ? 'จ่ายแล้ว' :
                                 item.payment_status === 'approved' ? 'อนุมัติ' : 'รอจ่าย';

              html += `
                  <tr>
                      <td><strong>ปี ${item.year}</strong><br><small class="text-muted">${item.period_name || '-'}</small></td>
                      <td class="text-center"><span class="badge bg-secondary rounded-pill">${item.shares_at_time.toLocaleString('th-TH')}</span></td>
                      <td class="text-end">${parseFloat(item.dividend_rate).toFixed(1)}%</td>
                      <td class="text-end"><strong class="text-success">${item.dividend_amount_formatted}</strong></td>
                      <td class="text-center">
                          <span class="${statusClass}">${statusText}</span>
                          ${item.payment_date_formatted !== '-' ? `<br><small class="text-muted">${item.payment_date_formatted}</small>` : ''}
                      </td>
                  </tr>`;
          });
          historyTable.innerHTML = html;
      } else {
         if (summaryDiv) summaryDiv.innerHTML = '';
          historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-muted p-4"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>ยังไม่มีประวัติการรับปันผล</td></tr>`;
      }
  } catch (error) {
      console.error('History fetch error:', error);
      if (summaryDiv) summaryDiv.innerHTML = '';
      historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger p-3"><i class="bi bi-exclamation-triangle me-1"></i>เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
  }
}

// ===== DIVIDEND ACTIONS (คงเดิม) =====
function viewDividendDetails(periodId) {
    // [แก้ไข] เปลี่ยนเป็นใช้ periodId แทน year
    // คุณอาจจะต้องสร้างหน้าใหม่ หรือปรับ report.php ให้รองรับ
    window.location.href = `report.php?type=dividend_period&period_id=${periodId}`; // Example
}

async function processPayout(periodId, csrfToken) {
    const period = dividendPeriodsData.find(p => p.id === periodId);
    if (!confirm(`ยืนยันการจ่ายปันผลปี ${period?.year || periodId}?\n\nสถานะจะเปลี่ยนเป็น "จ่ายแล้ว" และไม่สามารถย้อนกลับได้`)) {
        return;
    }
    try {
        const response = await fetch('dividend_payout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ period_id: periodId, csrf_token: csrfToken }) // [แก้ไข] ส่ง period_id
        });
        const data = await response.json();
        if (data.ok) {
            toast(data.message || 'บันทึกการจ่ายปันผลสำเร็จ', true);
            setTimeout(() => window.location.reload(), 2000);
        } else { throw new Error(data.error || 'การดำเนินการล้มเหลว'); }
    } catch (error) {
        console.error("Payout Error:", error);
        toast('เกิดข้อผิดพลาด: ' + error.message, false);
    }
}

// [เพิ่ม] Function Approve Dividend
async function approveDividend(periodId, csrfToken) {
    const period = dividendPeriodsData.find(p => p.id === periodId);
     if (!confirm(`ยืนยันการอนุมัติงวดปันผลปี ${period?.year || periodId}?\n\nสถานะจะเปลี่ยนเป็น "อนุมัติแล้ว" และจะสามารถกดจ่ายปันผลได้`)) {
        return;
    }
     try {
        const response = await fetch('dividend_approve.php', { // Endpoint นี้คุณต้องสร้างเอง
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ period_id: periodId, csrf_token: csrfToken })
        });
        const data = await response.json();
        if (data.ok) {
            toast(data.message || 'อนุมัติงวดปันผลสำเร็จ', true);
            setTimeout(() => window.location.reload(), 2000);
        } else { throw new Error(data.error || 'การอนุมัติล้มเหลว'); }
    } catch (error) {
        console.error("Approve Error:", error);
        toast('เกิดข้อผิดพลาด: ' + error.message, false);
    }
}


// ===== EXPORT (คงเดิม) =====
function exportMembers() {
  const headers = ['รหัส', 'ชื่อ', 'ประเภท', 'หุ้น', 'รวมรับปันผล'];
  const rows = [headers];
  $$('#membersTable tbody tr').forEach(tr => {
      if (tr.style.display === 'none') return;
      const cells = tr.querySelectorAll('td');
      if (cells.length >= 5) {
          rows.push([
              cells[0].textContent.trim(),
              cells[1].textContent.trim(),
              cells[2].textContent.trim(),
              tr.dataset.shares,
              cells[cells.length - 2].textContent.replace(/[฿,]/g, '').trim()
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
    // Modal Date Range listeners
    const startDateInput = $('#startDate');
    const endDateInput = $('#endDate');
    if (startDateInput) startDateInput.addEventListener('change', calculateDateRange);
    if (endDateInput)   endDateInput.addEventListener('change', calculateDateRange);
    calculateDateRange();

    // [เพิ่ม] Modal Rebate Type listeners
    const modalRebateRateInput = $('#modalRebateRate');
    const modalRebateFixedInput = $('#modalRebateFixed');
    if(modalRebateRateInput) modalRebateRateInput.addEventListener('input', () => updateModalRebateType('rate'));
    if(modalRebateFixedInput) modalRebateFixedInput.addEventListener('input', () => updateModalRebateType('fixed'));
    updateModalRebateType('rate'); // เริ่มต้นโดยเปิดใช้งาน Rate

    // [เพิ่ม] Modal Calc listeners
    $('#modalProfit')?.addEventListener('input', updateModalCalc);
    $('#modalRate')?.addEventListener('input', updateModalCalc);
    $('#modalRebateBase')?.addEventListener('change', updateModalCalc);

    // Activate tab based on URL hash
    const hash = window.location.hash;
    const tabTrigger = hash ? $(`button[data-bs-target="${hash}"]`) : $('#periods-tab');
    if (tabTrigger) {
        bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
    }
     // Add listener to update URL hash when tab changes
     $$('button[data-bs-toggle="tab"]').forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', event => {
            // ใช้ history.pushState เพื่อเปลี่ยน URL โดยไม่รีโหลดหน้า
            history.pushState(null, null, event.target.dataset.bsTarget);
        })
    });
});
</script>
</body>
</html>