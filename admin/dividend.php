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
    if ($current_role !== 'admin') { // Still requires admin to view this page
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
    $members_dividends = []; // Reset and key by numeric member_id
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
            ORDER BY m.member_code ASC -- Order here instead of usort
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
             $member_id_int = (int)$row['member_id'];
             $members_dividends[$member_id_int] = [ // Use numeric ID as key
                'id' => $member_id_int,
                'code' => $row['member_code'],
                'member_name' => $row['full_name'],
                'shares' => (int)$row['shares'],
                'type' => 'member', // Hardcoded as member
                'type_th' => 'สมาชิก', // Hardcoded as member
                'payments' => [], // period_id => amount
                'total_received' => 0.0
            ];
        }

    } catch (Throwable $e) {
        error_log("Error fetching members: " . $e->getMessage());
        // Allow script to continue, but $members_dividends might be empty
    }


    // 3) การจ่ายปันผล - ดึงเฉพาะ payment ของ member
    $payments_stmt = $pdo->prepare("
        SELECT dp.member_id, dp.period_id,
               dp.dividend_amount, dp.payment_status
        FROM dividend_payments dp
        WHERE dp.member_type = 'member' -- Filter only member payments
    ");
    $payments_stmt->execute(); // Execute the prepared statement

    foreach ($payments_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $member_id_int = (int)$payment['member_id'];
        // Check if this member exists in our filtered list
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
    // Attempt to read from settings table first
    $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='site_name' LIMIT 1");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $site_name = $r['setting_value'] ?: $site_name;
    } else {
         // Fallback to app_settings if settings table fails or doesn't have site_name
         $st_app = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
         $st_app->execute();
         if ($r_app = $st_app->fetch(PDO::FETCH_ASSOC)) {
            $sys = json_decode($r_app['json_value'], true) ?: [];
            if (!empty($sys['site_name'])) $site_name = $sys['site_name'];
         }
    }
} catch (Throwable $e) {
    error_log("Error fetching site name: " . $e->getMessage());
}

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
$total_members = 0; // Will count only from members table now
$total_shares = 0; // Will sum only from members table now

try {
    // Get paid/pending from periods table (remains the same)
    $stats_periods = $pdo->query("
        SELECT
            (SELECT COALESCE(SUM(total_dividend_amount), 0)
             FROM dividend_periods WHERE status = 'paid') as total_paid,
            (SELECT COALESCE(SUM(total_dividend_amount), 0)
             FROM dividend_periods WHERE status = 'approved') as total_pending
    ")->fetch(PDO::FETCH_ASSOC);

    $total_dividend_paid = (float)($stats_periods['total_paid'] ?? 0);
    $pending_dividend = (float)($stats_periods['total_pending'] ?? 0);

    // Get member count and total shares ONLY from members table
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
    <title>ปันผล | <?= htmlspecialchars($site_name) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
    <style>
        /* CSS styles remain the same */
        .status-paid {
            background: #d1f4dd; color: #0f5132; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500;
        }
        .status-approved {
            background: #fff3cd; color: #664d03; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500;
        }
        .status-pending {
            background: #f8d7da; color: #842029; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500;
        }
        .dividend-card {
            border: 1px solid #e9ecef; border-radius: 12px; transition: all 0.3s; background: #fff;
        }
        .dividend-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,.08); transform: translateY(-2px);
        }
        .dividend-amount { font-size: 1.3rem; font-weight: 700; color: #198754; }
        .dividend-rate { font-size: 1.1rem; font-weight: 600; color: #0d6efd; }
        .member-row:hover { background-color: rgba(13, 110, 253, 0.05); }
        .member-type-badge { font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; }
        .type-member { background: #e7f1ff; color: #004085; }
        /* .type-manager, .type-committee styles can be removed if not used */

        .calc-result {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 12px; text-align: center;
        }
        .calc-input {
            background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 8px; padding: 0.75rem; transition: all 0.3s;
        }
        .calc-input:focus {
            background: white; border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13,110,253,.1);
        }
        .stats-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;
        }
        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
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
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="panel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="mb-0">
                                        <i class="bi bi-lightning-fill me-1"></i> การจัดการ
                                    </h6>
                                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCreateDividend">
                                        <i class="fa-solid fa-plus me-1"></i> สร้างปันผลปีใหม่
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                     <div class="row g-4">
                        <?php if (empty($dividend_periods)): ?>
                            <div class="col-12"><div class="alert alert-info text-center">...</div></div>
                        <?php else: ?>
                            <?php foreach($dividend_periods as $period): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="dividend-card p-4">
                                   <div class="d-flex justify-content-between align-items-start mb-3">...</div>
                                    <div class="mb-3">...</div>
                                    <div class="d-flex flex-column gap-1 small">...</div>
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
                                    <?php foreach($members_dividends as $member_id => $member): // Use numeric key ?>
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
                         <h5 class="mb-4">... เครื่องคำนวณปันผล ...</h5>
                        <div class="row g-4">...</div>
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
                                             <td colspan="5" class="text-center text-muted py-4">...</td>
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
    © <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการปันผล
</footer>

<div class="modal fade" id="modalCreateDividend" tabindex="-1">
     <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="dividend_create_period.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
             <div class="modal-header">...</div>
             <div class="modal-body">
                 <div class="col-sm-6">
                    <label class="form-label">จำนวนหุ้นรวม (เฉพาะสมาชิก)</label> <input type="number" class="form-control bg-light"
                           value="<?= $total_shares ?>" readonly>
                    <small class="text-muted">รวมเฉพาะสมาชิกในระบบ</small>
                </div>
                 ... (rest of the fields) ...
            </div>
            <div class="modal-footer">...</div>
        </form>
    </div>
</div>
<div class="modal fade" id="modalMemberHistory" tabindex="-1">...</div>
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">...</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const $ = (s, p=document) => p.querySelector(s);
const $$ = (s, p=document) => [...p.querySelectorAll(s)];

// ** UPDATED: membersData now only contains members **
const membersData = Object.values(<?= json_encode($members_dividends, JSON_UNESCAPED_UNICODE) ?>);


// Toast Helper (Remains the same)
const toast = (msg, success = true) => { ... };
// Show Toast from URL (Remains the same)
// ...

// ========== FILTER MEMBERS ==========
const memberSearch = $('#memberSearch');
// ** REMOVED filterType variable **
// const filterType = $('#filterType');
const minShares = $('#minShares');

function normalize(s) { return (s || '').toString().toLowerCase().trim(); }

function applyMemberFilter() {
    const keyword = normalize(memberSearch?.value || '');
    // ** REMOVED type variable **
    // const type = filterType?.value || '';
    const minS = parseInt(minShares?.value || '0', 10);

    $$('#membersTable tbody tr').forEach(tr => {
        // Use member-key or code/name for searching
        const searchText = normalize(`${tr.dataset.memberKey} ${tr.dataset.memberName}`);
        // ** REMOVED memberType variable and check **
        // const memberType = tr.dataset.memberType;
        const shares = parseInt(tr.dataset.shares || '0', 10);

        const matchKeyword = !keyword || searchText.includes(keyword);
        // ** REMOVED matchType check **
        // const matchType = !type || memberType === type;
        const matchShares = isNaN(minS) || shares >= minS;

        // ** UPDATED condition **
        tr.style.display = (matchKeyword && matchShares) ? '' : 'none';
    });
}

memberSearch?.addEventListener('input', applyMemberFilter);
// ** REMOVED filterType listener **
// filterType?.addEventListener('change', applyMemberFilter);
minShares?.addEventListener('input', applyMemberFilter);

// ========== CALCULATOR ==========
// Calculation logic remains the same, but uses the updated $total_shares from PHP for default
function calculateDividend() {
    const profit = parseFloat($('#calcProfit')?.value || '0');
    // Use the potentially updated $total_shares from PHP for the default
    const shares = parseFloat($('#calcShares')?.value || '<?= $total_shares ?>'); // Use PHP value here
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
        preview.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">...</td></tr>`;
        return;
    }

    const showAll = $('#showAllMembers')?.checked;
    // membersData already contains only members
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
        html += `<tr class="table-light"><td colspan="5" class="text-center text-muted small">...</td></tr>`;
    }

    preview.innerHTML = html;
}

// ========== MODAL DATE RANGE & CALC (Remain the same) ==========
function updateDateRange() { /* ... same code ... */ }
function calculateDateRange() { /* ... same code ... */ }
function updateModalCalc() { /* ... same code ... */ }


// ========== MEMBER HISTORY (Remains the same, endpoint needs correct data) ==========
async function viewMemberHistory(memberKey) {
    const [memberType, memberId] = memberKey.split('_');
    if (!memberId || memberType !== 'member') { // Ensure it's 'member'
        toast('ข้อมูลสมาชิกไม่ถูกต้อง', false);
        return;
    }
    // membersData already filtered
    const member = membersData.find(m => m.id == memberId); // Find by ID

    if (!member) {
        toast('ไม่พบข้อมูลสมาชิก', false);
        return;
    }

    $('#historyMemberId').textContent = member.code;
    $('#historyMemberName').textContent = member.member_name;
    $('#historyMemberType').textContent = member.type_th; // Should always be 'สมาชิก'

    const historyTable = $('#memberHistoryTable');
    historyTable.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';

    new bootstrap.Modal('#modalMemberHistory').show();

    try {
        // Assuming the endpoint handles filtering by member_type correctly if needed
        const response = await fetch(
            `dividend_member_history.php?member_id=${memberId}&member_type=member` // Explicitly request 'member'
        );
        // ... (rest of the history display logic remains the same) ...
         if (!response.ok) throw new Error('ไม่สามารถดึงข้อมูลได้');
        const data = await response.json();

        if (data.ok && data.history.length > 0) {
            // Display summary
            const summary = data.summary;
            let summaryHtml = `...`; // Summary HTML same as before
            const modalBody = historyTable.closest('.modal-body');
            let summaryDiv = modalBody.querySelector('.history-summary');
            if (!summaryDiv) { /* create div */ }
            summaryDiv.innerHTML = summaryHtml;

            // Display history
            let html = '';
            data.history.forEach(item => { /* history row HTML same as before */ });
            historyTable.innerHTML = html;
        } else {
             if(summaryDiv) summaryDiv.innerHTML = '';
             historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">... ยังไม่มีประวัติ ...</td></tr>`;
        }
    } catch (error) {
        console.error('History fetch error:', error);
        if(summaryDiv) summaryDiv.innerHTML = '';
        historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-3">... เกิดข้อผิดพลาด ...</td></tr>`;
    }
}

// ========== DIVIDEND ACTIONS (view/process payout - remain the same, backend handles logic) ==========
function viewDividendDetails(year) { /* ... same code ... */ }
async function processPayout(year, csrfToken) { /* ... same code ... */ }

// ========== EXPORT MEMBERS ==========
function exportMembers() {
    // ** UPDATED Headers - Removed Type **
    const headers = ['รหัส', 'ชื่อ', 'หุ้น', <?php
        // Generate headers for each period dynamically
        echo implode(',', array_map(fn($p) => "'ปี ".$p['year']."'", $dividend_periods));
    ?>, 'รวมรับปันผล'];

    const rows = [headers];
    $$('#membersTable tbody tr').forEach(tr => {
        if (tr.style.display === 'none') return; // Skip filtered rows
        const cells = tr.querySelectorAll('td');
        // Code[0], Name[1], Shares[2], N periods, Total[N+3], Actions[N+4]
        const periodCount = <?= count($dividend_periods) ?>;
        if (cells.length >= 4 + periodCount) { // Adjusted index check
            const rowData = [
                cells[0].textContent.trim(), // Code
                cells[1].textContent.trim(), // Name
                tr.dataset.shares || '0',    // Shares
            ];
            // Add payment data for each period (starts at index 3 now)
            for (let i = 0; i < periodCount; i++) {
                 rowData.push(cells[3 + i].textContent.replace(/[฿,]/g, '').trim());
            }
            // Add total received (index 3 + periodCount)
            rowData.push(cells[3 + periodCount].textContent.replace(/[฿,]/g, '').trim());
            rows.push(rowData);
        }
    });

    // CSV generation remains the same
    const csv = rows.map(r => r.map(v => `"${(v||'').toString().replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `dividend_members_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}


// ========== INITIALIZE (Remains the same) ==========
document.addEventListener('DOMContentLoaded', () => {
    if ($('#calcShares')) { $('#calcShares').value = <?= $total_shares ?>; }
    // ... (rest of initialization) ...
    applyMemberFilter(); // Apply filter on load
});
</script>
</body>
</html>