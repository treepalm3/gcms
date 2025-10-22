<?php
// dividend.php — จัดการปันผลสหกรณ์ (เฉพาะสมาชิก)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
    exit();
}

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$dividend_periods = [];
$members_dividends = []; // Keyed by member_id (numeric)
$error_message = null;

try {
    // 1) งวดปันผล (รายปี) - เหมือนเดิม
    $dividend_periods = $pdo->query("
        SELECT id, `year`, start_date, end_date, period_name, total_profit, dividend_rate,
               total_shares_at_time, total_dividend_amount, status, payment_date,
               created_at, approved_by
        FROM dividend_periods
        ORDER BY `year` DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2) ดึงข้อมูลเฉพาะสมาชิกทั่วไป (members) เท่านั้น
    $members_dividends = []; // Reset และ key ด้วย member_id ที่เป็นตัวเลข
    try {
        $stmt = $pdo->query("
            SELECT
                m.id AS member_id,
                m.member_code,
                u.full_name,
                m.shares
            FROM members m
            JOIN users u ON m.user_id = u.id
            WHERE m.is_active = 1
            ORDER BY m.member_code ASC -- เรียงตามรหัสสมาชิกเลย
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
             $member_id_int = (int)$row['member_id'];
             $members_dividends[$member_id_int] = [ // ใช้ ID ตัวเลขเป็น key
                'id' => $member_id_int,
                'code' => $row['member_code'],
                'member_name' => $row['full_name'],
                'shares' => (int)$row['shares'],
                'type' => 'member', // กำหนดค่าตายตัวเป็น member
                'type_th' => 'สมาชิก', // กำหนดค่าตายตัวเป็น สมาชิก
                'payments' => [], // period_id => amount
                'total_received' => 0.0
            ];
        }

    } catch (Throwable $e) {
        error_log("Error fetching members: " . $e->getMessage());
        $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูลสมาชิก";
    }
    // ** ลบส่วนดึงข้อมูล managers และ committees ออก **

    // 3) การจ่ายปันผล (เฉพาะสมาชิก)
    $payments_stmt = $pdo->query("
        SELECT dp.member_id, dp.period_id,
               dp.dividend_amount, dp.payment_status
        FROM dividend_payments dp
        WHERE dp.member_type = 'member' -- กรองเฉพาะรายการจ่ายของสมาชิก
    ");

    foreach ($payments_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $member_id_int = (int)$payment['member_id'];
        // ตรวจสอบว่าสมาชิกคนนี้มีอยู่ใน list ที่เรากรองมาหรือไม่
        if (!isset($members_dividends[$member_id_int])) continue;

        $pid = (int)$payment['period_id'];
        $amt = (float)$payment['dividend_amount'];

        $members_dividends[$member_id_int]['payments'][$pid] = $amt;
        if (($payment['payment_status'] ?? 'pending') === 'paid') {
            $members_dividends[$member_id_int]['total_received'] += $amt;
        }
    }

} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    error_log("Dividend page error: " . $e->getMessage());
}

// ดึงข้อมูลระบบ (เหมือนเดิม)
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

// คำนวณสถิติ - ** UPDATED to use members only **
$total_dividend_paid = 0;
$pending_dividend = 0;
$total_members = 0; // จะนับเฉพาะจากตาราง members
$total_shares = 0; // จะรวมเฉพาะจากตาราง members

try {
    // ยอดจ่ายแล้ว/ค้างจ่าย ดึงจากตาราง periods (เหมือนเดิม)
    $stats_periods = $pdo->query("
        SELECT
            (SELECT COALESCE(SUM(total_dividend_amount), 0)
             FROM dividend_periods WHERE status = 'paid') as total_paid,
            (SELECT COALESCE(SUM(total_dividend_amount), 0)
             FROM dividend_periods WHERE status = 'approved') as total_pending
    ")->fetch(PDO::FETCH_ASSOC);

    $total_dividend_paid = (float)($stats_periods['total_paid'] ?? 0);
    $pending_dividend = (float)($stats_periods['total_pending'] ?? 0);

    // จำนวนสมาชิกและหุ้นรวม ดึงจากตาราง members เท่านั้น
    $stats_members = $pdo->query("
        SELECT COUNT(*) as member_count, COALESCE(SUM(shares), 0) as share_sum
        FROM members
        WHERE is_active = 1
    ")->fetch(PDO::FETCH_ASSOC);

    $total_members = (int)($stats_members['member_count'] ?? 0);
    $total_shares = (int)($stats_members['share_sum'] ?? 0);

} catch (Throwable $e) {
     error_log("Error fetching stats: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ปันผล (เฉพาะสมาชิก) | <?= htmlspecialchars($site_name) ?></title> <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
    <style>
        /* CSS styles remain the same */
        .status-paid { background: #d1f4dd; color: #0f5132; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .status-approved { background: #fff3cd; color: #664d03; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .status-pending { background: #f8d7da; color: #842029; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .dividend-card { border: 1px solid #e9ecef; border-radius: 12px; transition: all 0.3s; background: #fff; }
        .dividend-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.08); transform: translateY(-2px); }
        .dividend-amount { font-size: 1.3rem; font-weight: 700; color: #198754; }
        .dividend-rate { font-size: 1.1rem; font-weight: 600; color: #0d6efd; }
        .member-row:hover { background-color: rgba(13, 110, 253, 0.05); }
        .member-type-badge { font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; }
        .type-member { background: #e7f1ff; color: #004085; }
        /* .type-manager, .type-committee can be removed */

        .calc-result { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 12px; text-align: center; }
        .calc-input { background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 8px; padding: 0.75rem; transition: all 0.3s; }
        .calc-input:focus { background: white; border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13,110,253,.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        @media (max-width: 992px) { .stats-grid { grid-template-columns: 1fr; } }
        .panel { background:#fff; border:1px solid #e9ecef; border-radius:12px; padding:16px; margin-bottom: 1rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <button class="navbar-toggler d-lg-none" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar">
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
            <div class="main-header">
                 <h2><i class="fa-solid fa-gift"></i> ปันผล (เฉพาะสมาชิก)</h2> </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <h5><i class="fa-solid fa-gift"></i> ปันผลจ่ายแล้ว</h5>
                    <h3 class="text-success">฿<?= number_format($total_dividend_paid, 2) ?></h3>
                    <p class="mb-0 text-muted">รวมทุกปีที่จ่ายแล้ว</p>
                </div>
                <div class="stat-card">
                    <h5><i class="bi bi-people-fill"></i> จำนวนสมาชิก</h5> <h3 class="text-info"><?= number_format($total_members) ?> คน</h3>
                    <p class="mb-0 text-muted">หุ้นรวม <?= number_format($total_shares) ?> หุ้น</p>
                </div>
                <div class="stat-card">
                    <h5><i class="bi bi-clock-history"></i> ปันผลค้างจ่าย</h5>
                    <h3 class="text-warning">฿<?= number_format($pending_dividend, 2) ?></h3>
                    <p class="mb-0 text-muted">ปีที่อนุมัติแล้วแต่ยังไม่จ่าย</p>
                </div>
            </div>

            <ul class="nav nav-tabs mb-3" id="dividendTab" role="tablist">
                 <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#periods-panel">
                        <i class="fa-solid fa-calendar-days me-2"></i>งวดปันผลรายปี
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#members-panel">
                         <i class="bi bi-people-fill me-2"></i>สมาชิกและหุ้น </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#calculator-panel">
                        <i class="bi bi-calculator me-2"></i>คำนวณปันผล
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="periods-panel">
                    <div class="row mb-4"><div class="col-12"><div class="panel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="bi bi-lightning-fill me-1"></i> การจัดการ</h6>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCreateDividend">
                                <i class="fa-solid fa-plus me-1"></i> สร้างปันผลปีใหม่
                            </button>
                        </div>
                    </div></div></div>
                    <div class="row g-4">
                        <?php if (empty($dividend_periods)): ?>
                            <div class="col-12">
                                <div class="alert alert-info text-center">
                                    <i class="bi bi-info-circle fs-3 d-block mb-2"></i>
                                    <h5>ยังไม่มีงวดปันผล</h5>
                                    <p class="mb-0">คลิกปุ่ม "สร้างปันผลปีใหม่" เพื่อเริ่มต้น</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach($dividend_periods as $period): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="dividend-card p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="mb-1">ปี <?= htmlspecialchars($period['year']) ?></h5>
                                            <small class="text-muted"><?= htmlspecialchars($period['period_name']) ?></small>
                                            <?php if (!empty($period['start_date']) && !empty($period['end_date'])): ?>
                                            <br>
                                            <small class="text-muted"><i class="bi bi-calendar-range"></i>
                                                <?php
                                                    $start = new DateTime($period['start_date']);
                                                    $end = new DateTime($period['end_date']);
                                                    echo $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y');
                                                ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="status-<?= htmlspecialchars($period['status']) ?>">
                                            <?= ['paid' => 'จ่ายแล้ว', 'approved' => 'อนุมัติแล้ว', 'pending' => 'รออนุมัติ'][$period['status']] ?? 'ไม่ทราบสถานะ' ?>
                                        </span>
                                    </div>
                                    <div class="mb-3">
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <small class="text-muted">อัตราปันผล</small><br>
                                                <span class="dividend-rate"><?= number_format($period['dividend_rate'], 1) ?>%</span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">ยอดรวม</small><br>
                                                <span class="dividend-amount">฿<?= number_format($period['total_dividend_amount'], 0) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column gap-1 small">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">กำไรสุทธิ:</span>
                                            <span>฿<?= number_format($period['total_profit'], 0) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">จำนวนหุ้น (ที่คำนวณ):</span>
                                            <span><?= number_format($period['total_shares_at_time']) ?> หุ้น</span>
                                        </div>
                                        <?php if($period['payment_date']): ?>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">วันที่จ่าย:</span>
                                            <span><?= date('d/m/Y', strtotime($period['payment_date'])) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-3 pt-3 border-top">
                                        <div class="d-grid gap-2">
                                            <button class="btn btn-sm btn-outline-primary"
                                                    onclick="viewDividendDetails(<?= (int)$period['year'] ?>)">
                                                <i class="bi bi-eye me-1"></i> รายละเอียด
                                            </button>
                                            <?php if($period['status'] === 'approved'): ?>
                                            <button class="btn btn-sm btn-success"
                                                    onclick="processPayout(<?= (int)$period['year'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                <i class="fa-solid fa-money-check-dollar me-1"></i> จ่ายปันผล
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

                <div class="tab-pane fade" id="members-panel">
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                        <div class="d-flex flex-wrap gap-2">
                            <div class="input-group" style="max-width:280px;">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="search" id="memberSearch" class="form-control" placeholder="ค้นหาสมาชิก...">
                            </div>
                            <input type="number" id="minShares" class="form-control"
                                   placeholder="หุ้นขั้นต่ำ" min="0" style="max-width:120px;">
                        </div>
                        <button class="btn btn-outline-primary" onclick="exportMembers()">
                            <i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV
                        </button>
                    </div>

                    <div class="panel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="membersTable">
                                <thead>
                                    <tr>
                                        <th>รหัส</th>
                                        <th>ชื่อ</th>
                                        <th class="text-center">หุ้น</th>
                                        <?php foreach ($dividend_periods as $period): ?>
                                        <th class="text-end d-none d-lg-table-cell">
                                            <?= htmlspecialchars($period['year']) ?>
                                        </th>
                                        <?php endforeach; ?>
                                        <th class="text-end">รวมรับ</th>
                                        <th class="text-end">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                     <?php // Use numeric key $member_id
                                     foreach($members_dividends as $member_id => $member): ?>
                                    <tr class="member-row"
                                        data-member-key="member_<?= $member_id ?>" data-member-name="<?= htmlspecialchars($member['member_name']) ?>"
                                        data-member-type="member" data-shares="<?= (int)$member['shares'] ?>">
                                        <td><strong><?= htmlspecialchars($member['code']) ?></strong></td>
                                        <td><?= htmlspecialchars($member['member_name']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-primary">
                                                <?= number_format($member['shares']) ?> หุ้น
                                            </span>
                                        </td>
                                        <?php foreach ($dividend_periods as $period): ?>
                                        <td class="text-end d-none d-lg-table-cell">
                                            ฿<?= number_format($member['payments'][$period['id']] ?? 0, 2) ?>
                                        </td>
                                        <?php endforeach; ?>
                                        <td class="text-end">
                                            <strong class="text-success">
                                                ฿<?= number_format($member['total_received'], 2) ?>
                                            </strong>
                                        </td>
                                        <td class="text-end">
                                             <button class="btn btn-sm btn-outline-info"
                                                     onclick="viewMemberHistory('member_<?= $member_id ?>')"> <i class="bi bi-clock-history"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="calculator-panel">
                     <div class="panel">
                         <h5 class="mb-4">
                            <i class="bi bi-calculator me-2"></i>เครื่องคำนวณปันผล
                        </h5>

                        <div class="row g-4">
                            <div class="col-md-5">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3">...</h6>
                                        <div class="mb-3">
                                            <label class="form-label">... กำไรสุทธิ ...</label>
                                            <input type="number" id="calcProfit" class="form-control calc-input" ...>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">จำนวนหุ้นรวม (สมาชิก)</label> <input type="number" id="calcShares" class="form-control calc-input"
                                                   value="<?= $total_shares ?>" step="1" oninput="calculateDividend()">
                                            <small class="text-muted">จำนวนหุ้นของสมาชิกทั้งหมดในระบบ</small> </div>
                                        <div class="mb-3">
                                            <label class="form-label">... อัตราปันผล ...</label>
                                            <input type="number" id="calcRate" class="form-control calc-input" ...>
                                        </div>
                                        <div class="alert alert-info mb-0">...</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-7">...</div>
                        </div>

                        <div class="mt-4">
                            <h6 class="mb-3">ตัวอย่างการจ่ายตามสมาชิก</h6> <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>รหัส</th>
                                            <th>ชื่อ</th>
                                            <th class="text-center">หุ้น</th>
                                            <th class="text-end">ปันผลที่จะได้รับ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dividendPreview">
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">
                                                <i class="bi bi-calculator fs-1 d-block mb-2 opacity-25"></i>
                                                กรอกข้อมูลเพื่อดูตัวอย่างการคำนวณ
                                            </td>
                                        </tr>
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
<footer class="footer">
    © <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการปันผล (สมาชิก)
</footer>

<div class="modal fade" id="modalCreateDividend" tabindex="-1">
     <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="dividend_create_period.php">
             <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
             <div class="modal-header bg-primary text-white">...</div>
             <div class="modal-body">
                <div class="row g-3">
                     <div class="col-sm-6">
                        <label class="form-label">
                            <i class="bi bi-diagram-3 me-1"></i>จำนวนหุ้นรวม (เฉพาะสมาชิก)
                        </label>
                        <input type="number" class="form-control bg-light"
                               value="<?= $total_shares ?>" readonly>
                        <small class="text-muted">รวมหุ้นสมาชิกทั้งหมดในระบบ</small>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label">
                            <i class="bi bi-cash-stack me-1"></i>ยอดปันผลรวม (บาท)
                        </label>
                        <input type="text" id="modalTotal" class="form-control bg-light"
                               value="0.00" readonly>
                        <small class="text-muted">คำนวณอัตโนมัติ</small>
                    </div>
                     </div>

                <div class="alert alert-info mt-3 mb-0">
                    <div class="d-flex">
                        <i class="bi bi-info-circle fs-5 me-2"></i>
                        <div>
                            <strong>หมายเหตุ:</strong>
                            <ul class="mb-0 mt-2">
                                <li>แต่ละปีสามารถมีได้เพียง 1 งวดปันผล</li>
                                <li>หลังสร้างงวด สถานะจะเป็น <span class="badge bg-warning">รออนุมัติ</span></li>
                                <li>ระบบจะสร้างรายการจ่ายให้สมาชิกทุกคนอัตโนมัติ</li>
                                <li>ปันผล<strong>เฉพาะสมาชิกเท่านั้น</strong> (ไม่รวมผู้บริหารและกรรมการ)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>ยกเลิก
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save2 me-1"></i>สร้างงวดปันผล
                </button>
            </div>
        </form>
    </div>
</div>
<div class="modal fade" id="modalMemberHistory" tabindex="-1">
     <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">... ประวัติการรับปันผล ...</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-sm-4"><strong>รหัส:</strong> <span id="historyMemberId">-</span></div>
                    <div class="col-sm-8"><strong>ชื่อ:</strong> <span id="historyMemberName">-</span></div>
                     </div>
                <div class="history-summary"></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ปี</th>
                                <th class="text-center">หุ้น</th>
                                <th class="text-end">อัตรา (%)</th>
                                <th class="text-end">ปันผลที่ได้รับ</th>
                                <th class="text-center">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody id="memberHistoryTable">...</tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">...</div>
        </div>
    </div>
</div>
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">...</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
'useSTRICT';

const $ = (s, p=document) => p.querySelector(s);
const $$ = (s, p=document) => [...p.querySelectorAll(s)];

// ** UPDATED: membersData is now a simple array **
const membersData = <?= json_encode(array_values($members_dividends), JSON_UNESCAPED_UNICODE) ?>;

// Toast Helper (Remains the same)
const toast = (msg, success = true) => { /* ... */ };
// Show Toast from URL (Remains the same)
// ...

// ========== FILTER MEMBERS ==========
const memberSearch = $('#memberSearch');
// ** REMOVED filterType variable **
const minShares = $('#minShares');

function normalize(s) { return (s || '').toString().toLowerCase().trim(); }

function applyMemberFilter() {
    const keyword = normalize(memberSearch?.value || '');
    // ** REMOVED type variable **
    const minS = parseInt(minShares?.value || '0', 10);

    $$('#membersTable tbody tr').forEach(tr => {
        const searchText = normalize(`${tr.dataset.memberName} ${tr.dataset.memberKey}`);
        // ** REMOVED memberType variable and check **
        const shares = parseInt(tr.dataset.shares || '0', 10);

        const matchKeyword = !keyword || searchText.includes(keyword);
        const matchShares = isNaN(minS) || shares >= minS;

        // ** UPDATED condition **
        tr.style.display = (matchKeyword && matchShares) ? '' : 'none';
    });
}

memberSearch?.addEventListener('input', applyMemberFilter);
// ** REMOVED filterType listener **
minShares?.addEventListener('input', applyMemberFilter);

// ========== CALCULATOR ==========
function calculateDividend() {
    const profit = parseFloat($('#calcProfit')?.value || '0');
    const shares = parseFloat($('#calcShares')?.value || '<?= $total_shares ?>');
    const rate = parseFloat($('#calcRate')?.value || '0');

    // ... (rest of calculation is the same) ...
    const totalDividend = profit * (rate / 100);
    const dividendPerShare = shares > 0 ? totalDividend / shares : 0;
    const profitPercentage = profit > 0 ? (totalDividend / profit) * 100 : 0;
    const reserveFund = profit * 0.10;
    const welfareFund = profit * 0.05;
    const netAvailable = profit * 0.85;

    $('#totalDividend').textContent = '฿' + totalDividend.toLocaleString('th-TH', {minimumFractionDigits: 2});
    $('#dividendPerShare').textContent = '฿' + dividendPerShare.toLocaleString('th-TH', {minimumFractionDigits: 2});
    $('#profitPercentage').textContent = profitPercentage.toFixed(1) + '%';
    $('#reserveFund').textContent = '฿' + reserveFund.toLocaleString('th-TH', {minimumFractionDigits: 2});
    $('#welfareFund').textContent = '฿' + welfareFund.toLocaleString('th-TH', {minimumFractionDigits: 2});
    $('#netAvailable').textContent = '฿' + netAvailable.toLocaleString('th-TH', {minimumFractionDigits: 2});

    updateDividendPreview(dividendPerShare);
}

function updateDividendPreview(dividendPerShare) {
    const preview = $('#dividendPreview');
    if (!preview) return;

    if (dividendPerShare <= 0) {
        // ** Updated Colspan **
        preview.innerHTML = `
            <tr>
                <td colspan="5" class="text-center text-muted py-4">
                    <i class="bi bi-calculator fs-1 d-block mb-2 opacity-25"></i>
                    กรอกข้อมูลเพื่อดูตัวอย่างการคำนวณ
                </td>
            </tr>
        `;
        return;
    }

    const showAll = $('#showAllMembers')?.checked;
    const displayMembers = showAll ? membersData : membersData.slice(0, 10);

    let html = '';
    displayMembers.forEach((member, index) => {
        const amount = member.shares * dividendPerShare;
        html += `
            <tr>
                <td>${index + 1}</td>
                <td><strong>${member.code}</strong></td>
                <td>${member.member_name}</td>
                <td class="text-center">
                    <span class="badge bg-primary">${member.shares.toLocaleString('th-TH')} หุ้น</span>
                </td>
                <td class="text-end">
                    <strong class="text-success">฿${amount.toLocaleString('th-TH', {minimumFractionDigits: 2})}</strong>
                </td>
            </tr>
        `;
    });

    if (!showAll && membersData.length > 10) {
        // ** Updated Colspan **
        html += `
            <tr class="table-light">
                <td colspan="5" class="text-center text-muted small">
                    <i class="bi bi-three-dots me-1"></i>
                    และอีก ${membersData.length - 10} คน (เปิดสวิตช์ "แสดงทั้งหมด" เพื่อดูเพิ่ม)
                </td>
            </tr>
        `;
    }

    preview.innerHTML = html;
}

// ========== MODAL DATE RANGE & CALC (Remain the same) ==========
function updateDateRange() {
     const year = $('#dividendYear')?.value || <?= date('Y') ?>;
    const startDate = $('#startDate');
    const endDate = $('#endDate');
    const periodName = $('#periodName');
    
    if (startDate && endDate) {
        startDate.value = `${year}-01-01`;
        endDate.value = `${year}-12-31`;
        if (periodName && periodName.value.includes('ปันผลประจำปี')) {
            periodName.value = `ปันผลประจำปี ${year}`;
        }
        calculateDateRange();
    }
}
function calculateDateRange() {
    const startInput = $('#startDate');
    const endInput = $('#endDate');
    const display = $('#dateRangeDisplay');
    const daysCount = $('#daysCount');
    
    if (!startInput || !endInput || !display || !daysCount) return;
    const startDate = new Date(startInput.value);
    const endDate = new Date(endInput.value);
    if (isNaN(startDate) || isNaN(endDate)) return;
    
    const diffTime = Math.abs(endDate - startDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
    
    const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.','ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const startStr = `${startDate.getDate()} ${thaiMonths[startDate.getMonth()]} ${startDate.getFullYear()}`;
    const endStr = `${endDate.getDate()} ${thaiMonths[endDate.getMonth()]} ${endDate.getFullYear()}`;

    display.textContent = `${startStr} - ${endStr}`;
    daysCount.textContent = diffDays.toLocaleString('th-TH');

    if (endDate < startDate) {
        endInput.setCustomValidity('วันที่สิ้นสุดต้องมากกว่าวันที่เริ่มต้น');
        display.textContent = 'ช่วงวันที่ไม่ถูกต้อง';
        display.classList.add('text-danger');
    } else {
        endInput.setCustomValidity('');
        display.classList.remove('text-danger');
    }
}
function updateModalCalc() {
    const profit = parseFloat($('#modalProfit')?.value || '0');
    const rate = parseFloat($('#modalRate')?.value || '0');
    const totalDividend = profit * (rate / 100);
    const modalTotal = $('#modalTotal');
    if (modalTotal) {
        modalTotal.value = '฿' + totalDividend.toLocaleString('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
}

// ========== MEMBER HISTORY ==========
async function viewMemberHistory(memberKey) {
    const [memberType, memberId] = memberKey.split('_');
    
    if (!memberId || memberType !== 'member') { // Ensure it's 'member'
        toast('ข้อมูลสมาชิกไม่ถูกต้อง', false);
        return;
    }
    // Find member in the filtered membersData (array)
    const member = membersData.find(m => m.id == memberId); // Find by ID

    if (!member) {
        toast('ไม่พบข้อมูลสมาชิก', false);
        return;
    }

    $('#historyMemberId').textContent = member.code;
    $('#historyMemberName').textContent = member.member_name;
    // ** REMOVED Type from modal header **
    // $('#historyMemberType').textContent = member.type_th; 

    const historyTable = $('#memberHistoryTable');
    historyTable.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';
    
    const summaryDiv = historyTable.closest('.modal-body').querySelector('.history-summary');
    if(summaryDiv) summaryDiv.innerHTML = ''; // Clear summary

    new bootstrap.Modal('#modalMemberHistory').show();

    try {
        const response = await fetch(
            `dividend_member_history.php?member_id=${memberId}&member_type=member` // Explicitly request 'member'
        );
        
        if (!response.ok) {
            throw new Error('ไม่สามารถดึงข้อมูลได้');
        }
        
        const data = await response.json();

        if (data.ok && data.history.length > 0) {
            // ... (rest of summary and history display logic is the same) ...
             // แสดงสรุป
            const summary = data.summary;
            let summaryHtml = `
                <div class="alert alert-info mb-3">
                    <div class="row text-center">
                        <div class="col-4">
                            <small class="text-muted d-block">รับแล้ว</small>
                            <strong class="text-success">${summary.total_received_formatted}</strong>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">ค้างรับ</small>
                            <strong class="text-warning">${summary.total_pending_formatted}</strong>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">จำนวนครั้ง</small>
                            <strong>${summary.payment_count} ครั้ง</strong>
                        </div>
                    </div>
                </div>
            `;
            if(summaryDiv) summaryDiv.innerHTML = summaryHtml;

            // แสดงประวัติ
            let html = '';
            data.history.forEach(item => {
                const statusClass = item.payment_status === 'paid' ? 'status-paid' : 
                                   item.payment_status === 'approved' ? 'status-approved' : 'status-pending';
                const statusText = item.payment_status === 'paid' ? 'จ่ายแล้ว' : 
                                  item.payment_status === 'approved' ? 'อนุมัติแล้ว' : 'รออนุมัติ';
                
                html += `
                    <tr>
                        <td>
                            <strong>ปี ${item.year}</strong><br>
                            <small class="text-muted">${item.period_name || '-'}</small>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary">${item.shares_at_time.toLocaleString('th-TH')}</span>
                        </td>
                        <td class="text-end">${parseFloat(item.dividend_rate).toFixed(1)}%</td>
                        <td class="text-end">
                            <strong class="text-success">${item.dividend_amount_formatted}</strong>
                        </td>
                        <td class="text-center">
                            <span class="${statusClass}">${statusText}</span>
                            ${item.payment_date_formatted !== '-' ? 
                                `<br><small class="text-muted">${item.payment_date_formatted}</small>` : ''}
                        </td>
                    </tr>
                `;
            });
            historyTable.innerHTML = html;
        } else {
             if(summaryDiv) summaryDiv.innerHTML = '';
            historyTable.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>
                        ยังไม่มีประวัติการรับปันผล
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        console.error('History fetch error:', error);
         if(summaryDiv) summaryDiv.innerHTML = '';
        historyTable.innerHTML = `
            <tr>
                <td colspan="5" class="text-center text-danger py-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    เกิดข้อผิดพลาด: ${error.message}
                </td>
            </tr>
        `;
    }
}
// ========== DIVIDEND ACTIONS (view/process payout - remain the same) ==========
function viewDividendDetails(year) {
    window.location.href = `dividend_detail.php?year=${year}`;
}
async function processPayout(year, csrfToken) {
    if (!confirm(`ยืนยันการจ่ายปันผลปี ${year}?\n\nการดำเนินการนี้ไม่สามารถย้อนกลับได้`)) {
        return;
    }
    try {
        const response = await fetch('dividend_action.php', { // Changed to relative path
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'process_payout', year: year, csrf_token: csrfToken })
        });
        const data = await response.json();
        if (data.ok) {
            toast(data.message, true);
            setTimeout(() => window.location.reload(), 2000);
        } else {
            toast(data.error, false);
        }
    } catch (error) {
        toast('เกิดข้อผิดพลาดในการเชื่อมต่อ', false);
    }
}

// ========== EXPORT ==========
function exportMembers() {
    // ** UPDATED Headers - Removed Type **
    const headers = ['รหัส', 'ชื่อ', 'หุ้น', 'รวมรับปันผล'];
    
    // Add dynamic period headers
    <?php
        $period_headers = [];
        foreach ($dividend_periods as $period) {
            $period_headers[] = "ปี " . $period['year'];
        }
        echo "const periodHeaders = " . json_encode($period_headers) . ";\n";
    ?>
    headers.splice(3, 0, ...periodHeaders); // Insert period headers before 'Total'
    
    const rows = [headers];

    $$('#membersTable tbody tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        const cells = tr.querySelectorAll('td');
        const periodCount = <?= count($dividend_periods) ?>;
        
        // Code[0], Name[1], Shares[2], [3...3+N-1]:Periods, [3+N]:Total, [4+N]:Actions
        if (cells.length >= (4 + periodCount)) { 
            const rowData = [
                cells[0].textContent.trim(), // Code
                cells[1].textContent.trim(), // Name
                tr.dataset.shares || '0',    // Shares
            ];
            // Add period data
            for (let i = 0; i < periodCount; i++) {
                 rowData.push(cells[3 + i].textContent.replace(/[฿,]/g, '').trim());
            }
            // Add total
            rowData.push(cells[3 + periodCount].textContent.replace(/[฿,]/g, '').trim());
            rows.push(rowData);
        }
    });

    const csv = rows.map(r => r.map(v => `"${(v||'').toString().replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `dividend_members_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

// ========== INITIALIZE ==========
document.addEventListener('DOMContentLoaded', () => {
    if ($('#calcShares')) { $('#calcShares').value = <?= $total_shares ?>; }
    
    const startDate = $('#startDate');
    const endDate = $('#endDate');
    const dividendYear = $('#dividendYear');

    startDate?.addEventListener('change', calculateDateRange);
    endDate?.addEventListener('change', calculateDateRange);
    dividendYear?.addEventListener('change', updateDateRange);
    
    calculateDateRange(); // Initial run for modal
    applyMemberFilter(); // Initial run for table filter
    calculateDividend(); // Initial run for calculator
});
</script>
</body>
</html>