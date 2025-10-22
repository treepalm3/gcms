<?php
// admin/dividend.php — จัดการปันผลสหกรณ์ (รวมทุกประเภทผู้ถือหุ้น)
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
    } catch (Throwable $e) {
        error_log("Error fetching members: " . $e->getMessage());
    }
    
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
            LEFT JOIN users u ON mg.user_id = u.id
        ");
        $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        error_log("Error fetching managers: " . $e->getMessage());
    }
    
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
    } catch (Throwable $e) {
        error_log("Error fetching committees: " . $e->getMessage());
    }
    
    // เรียงตามรหัส
    usort($all_members, function($a, $b) {
        return strcmp($a['member_code'] ?? '', $b['member_code'] ?? '');
    });

    // สร้าง array สำหรับแสดงผล
    $members_dividends = [];
    foreach ($all_members as $row) {
        // สร้าง key ที่ไม่ซ้ำกัน โดยใช้ ประเภท_ID
        $key = $row['member_type'] . '_' . $row['member_id']; 
        $members_dividends[$key] = [
            'id' => $row['member_id'],
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

    // 3) การจ่ายปันผล (ดึงจากตาราง dividend_payments ที่มี member_type)
    $payments_stmt = $pdo->query("
        SELECT dp.member_id, dp.member_type, dp.period_id, 
               dp.dividend_amount, dp.payment_status
        FROM dividend_payments dp
    ");

    foreach ($payments_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        // ใช้ key เดียวกัน (ประเภท_ID) เพื่อหา array ที่ถูกต้อง
        $key = $payment['member_type'] . '_' . $payment['member_id']; 
        if (!isset($members_dividends[$key])) continue; // ถ้าไม่พบ ให้ข้าม

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

// ดึงข้อมูลระบบ
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
    // (โค้ดดึงชื่อเว็บ... เหมือนเดิม)
    $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='site_name' LIMIT 1");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $site_name = $r['setting_value'] ?: $site_name;
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

// คำนวณสถิติ (ใช้ข้อมูลที่ดึงมารวมกัน)
$total_dividend_paid = 0;
$pending_dividend = 0;
$total_members = count($members_dividends); // นับจำนวนผู้ถือหุ้นทั้งหมด (รวมทุกประเภท)
$total_shares = array_sum(array_column($members_dividends, 'shares')); // รวมหุ้นทั้งหมด (รวมทุกประเภท)

try {
    // ดึงยอดจ่ายแล้ว/ค้างจ่าย จากตาราง periods (เหมือนเดิม)
    $stats = $pdo->query("
        SELECT 
            (SELECT COALESCE(SUM(total_dividend_amount), 0) 
             FROM dividend_periods WHERE status = 'paid') as total_paid,
            (SELECT COALESCE(SUM(total_dividend_amount), 0) 
             FROM dividend_periods WHERE status = 'approved') as total_pending
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
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
    <style>
        /* (CSS Styles ... เหมือนเดิม) */
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
        .type-manager { background: #fff3cd; color: #664d03; }
        .type-committee { background: #f8d7da; color: #721c24; }
        .calc-result { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 12px; text-align: center; }
        .calc-input { background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 8px; padding: 0.75rem; transition: all 0.3s; }
        .calc-input:focus { background: white; border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13,110,253,.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        @media (max-width: 992px) { .stats-grid { grid-template-columns: 1fr; } }
         .panel { background:#fff; border:1px solid #e9ecef; border-radius:12px; padding:16px; margin-bottom: 1rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">...</nav>
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">...</div>
<div class="container-fluid">
    <div class="row">
        <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">...</aside>

        <main class="col-lg-10 p-4">
            <div class="main-header">
                <h2><i class="fa-solid fa-gift"></i> ปันผล (รวมทุกประเภท)</h2> </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($debug_info['managers']) && is_array($debug_info['managers'])): ?>
                <div class="alert alert-warning">
                    <strong>Manager Debug:</strong><br>
                    <pre><?= htmlspecialchars(implode("\n", $debug_info['managers'])) ?></pre>
                </div>
            <?php endif; ?>
            

            <div class="stats-grid">
                <div class="stat-card">
                    <h5><i class="fa-solid fa-gift"></i> ปันผลจ่ายแล้ว</h5>
                    <h3 class="text-success">฿<?= number_format($total_dividend_paid, 2) ?></h3>
                    <p class="mb-0 text-muted">รวมทุกปีที่จ่ายแล้ว</p>
                </div>
                <div class="stat-card">
                    <h5><i class="bi bi-people-fill"></i> จำนวนผู้ถือหุ้น</h5>
                    <h3 class="text-info"><?= number_format($total_members) ?> คน</h3>
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
                        <i class="bi bi-people-fill me-2"></i>ผู้ถือหุ้น
                    </button>
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
                             <div class="col-12"><div class="alert alert-info text-center">...ยังไม่มีงวดปันผล...</div></div>
                        <?php else: ?>
                            <?php foreach($dividend_periods as $period): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="dividend-card p-4">
                                   <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="mb-1">ปี <?= htmlspecialchars($period['year']) ?></h5>
                                            <small class="text-muted"><?= htmlspecialchars($period['period_name']) ?></small>
                                            <?php if (!empty($period['start_date']) && !empty($period['end_date'])): ?>
                                            <br><small class="text-muted"><i class="bi bi-calendar-range"></i>
                                                <?php
                                                    $start = new DateTime($period['start_date']);
                                                    $end = new DateTime($period['end_date']);
                                                    echo $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y');
                                                ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="status-<?= htmlspecialchars($period['status']) ?>">
                                            <?= ['paid'=>'จ่ายแล้ว','approved'=>'อนุมัติแล้ว','pending'=>'รออนุมัติ'][$period['status']] ?? 'ไม่ทราบ' ?>
                                        </span>
                                    </div>
                                    <div class="mb-3">
                                        <div class="row text-center">
                                            <div class="col-6"><small class="text-muted">อัตราปันผล</small><br><span class="dividend-rate"><?= number_format($period['dividend_rate'], 1) ?>%</span></div>
                                            <div class="col-6"><small class="text-muted">ยอดรวม</small><br><span class="dividend-amount">฿<?= number_format($period['total_dividend_amount'], 0) ?></span></div>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column gap-1 small">
                                        <div class="d-flex justify-content-between"><span class="text-muted">กำไรสุทธิ:</span><span>฿<?= number_format($period['total_profit'], 0) ?></span></div>
                                        <div class="d-flex justify-content-between"><span class="text-muted">จำนวนหุ้น:</span><span><?= number_format($period['total_shares_at_time']) ?> หุ้น</span></div>
                                        <?php if($period['payment_date']): ?>
                                        <div class="d-flex justify-content-between"><span class="text-muted">วันที่จ่าย:</span><span><?= date('d/m/Y', strtotime($period['payment_date'])) ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-3 pt-3 border-top">
                                        <div class="d-grid gap-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="viewDividendDetails(<?= (int)$period['year'] ?>)"><i class="bi bi-eye me-1"></i> รายละเอียด</button>
                                            <?php if($period['status'] === 'approved'): ?>
                                            <button class="btn btn-sm btn-success" onclick="processPayout(<?= (int)$period['year'] ?>, '<?= $_SESSION['csrf_token'] ?>')"><i class="fa-solid fa-money-check-dollar me-1"></i> จ่ายปันผล</button>
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
                                <input type="search" id="memberSearch" class="form-control" placeholder="ค้นหา...">
                            </div>
                            <select id="filterType" class="form-select" style="max-width:150px;">
                                <option value="">ทุกประเภท</option>
                                <option value="member">สมาชิก</option>
                                <option value="manager">ผู้บริหาร</option>
                                <option value="committee">กรรมการ</option>
                            </select>
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
                                        <th class="text-center">ประเภท</th>
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
                <div class="tab-pane fade" id="calculator-panel">
                     <div class="panel">
                        <h5 class="mb-4">... เครื่องคำนวณปันผล ...</h5>
                        <div class="row g-4">
                            <div class="col-md-5">
                                 <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                         <div class="mb-3">
                                            <label class="form-label">... กำไรสุทธิ ...</label>
                                            <input type="number" id="calcProfit" ...>
                                        </div>
                                         <div class="mb-3">
                                            <label class="form-label">จำนวนหุ้นรวม</label>
                                            <input type="number" id="calcShares" class="form-control calc-input" 
                                                   value="<?= $total_shares ?>" ...> <small class="text-muted">จำนวนหุ้นทั้งหมดในระบบ (รวมทุกประเภท)</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">... อัตราปันผล ...</label>
                                            <input type="number" id="calcRate" ...>
                                        </div>
                                        ...
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-7">
                                </div>
                        </div>

                        <div class="mt-4">
                            <h6 class="mb-3">ตัวอย่างการจ่ายตามผู้ถือหุ้น</h6>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>รหัส</th>
                                            <th>ชื่อ</th>
                                            <th class="text-center">ประเภท</th> <th class="text-center">หุ้น</th>
                                            <th class="text-end">ปันผลที่จะได้รับ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dividendPreview">
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">... กรอกข้อมูลเพื่อดูตัวอย่างการคำนวณ
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
    © <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการปันผล
</footer>

<div class="modal fade" id="modalCreateDividend" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="dividend_create_period.php">
             <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
             <div class="modal-header">...</div>
             <div class="modal-body">
                <div class="row g-3">
                    ...
                    <div class="col-sm-6">
                        <label class="form-label">จำนวนหุ้นรวม</label>
                        <input type="number" class="form-control bg-light" 
                               value="<?= $total_shares ?>" readonly> <small class="text-muted">รวมทุกประเภทในระบบ</small>
                    </div>
                    ...
                    <div class="alert alert-info mt-3 mb-0">
                         ...
                         <strong>หมายเหตุ:</strong>
                         <ul class="mb-0 mt-2">
                            ...
                            <li>ระบบจะสร้างรายการจ่ายให้ผู้ถือหุ้นทุกคน (สมาชิก, ผู้บริหาร, กรรมการ)</li>
                            ...
                         </ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">...</div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalMemberHistory" tabindex="-1">
     <div class="modal-dialog modal-lg">
        <div class="modal-content">
             <div class="modal-header">...</div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-sm-4"><strong>รหัส:</strong> <span id="historyMemberId">-</span></div>
                    <div class="col-sm-4"><strong>ชื่อ:</strong> <span id="historyMemberName">-</span></div>
                    <div class="col-sm-4"><strong>ประเภท:</strong> <span id="historyMemberType">-</span></div> </div>
                <div class="history-summary"></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
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
'use strict';

const $ = (s, p=document) => p.querySelector(s);
const $$ = (s, p=document) => [...p.querySelectorAll(s)];

// ข้อมูลสมาชิก (กลับไปใช้ object ที่มี key เป็น string)
const membersData = <?= json_encode($members_dividends, JSON_UNESCAPED_UNICODE) ?>; 
// *** แก้ไข: ไม่ใช้ array_values แล้ว ***

// Toast Helper (เหมือนเดิม)
const toast = (msg, success = true) => { /* ... */ };
// แสดง Toast จาก URL (เหมือนเดิม)
// ...

// ========== FILTER MEMBERS ==========
const memberSearch = $('#memberSearch');
const filterType = $('#filterType'); // ** นำกลับมา **
const minShares = $('#minShares');

function normalize(s) { return (s || '').toString().toLowerCase().trim(); }

function applyMemberFilter() {
    const keyword = normalize(memberSearch?.value || '');
    const type = filterType?.value || ''; // ** นำกลับมา **
    const minS = parseInt(minShares?.value || '0', 10);

    $$('#membersTable tbody tr').forEach(tr => {
        const searchText = normalize(`${tr.dataset.memberName} ${tr.dataset.memberKey}`);
        const memberType = tr.dataset.memberType; // ** นำกลับมา **
        const shares = parseInt(tr.dataset.shares || '0', 10);
        
        const matchKeyword = !keyword || searchText.includes(keyword);
        const matchType = !type || memberType === type; // ** นำกลับมา **
        const matchShares = isNaN(minS) || shares >= minS;
        
        // ** อัปเดตเงื่อนไข **
        tr.style.display = (matchKeyword && matchType && matchShares) ? '' : 'none';
    });
}

memberSearch?.addEventListener('input', applyMemberFilter);
filterType?.addEventListener('change', applyMemberFilter); // ** นำกลับมา **
minShares?.addEventListener('input', applyMemberFilter);

// ========== CALCULATOR ==========
// (เหมือนเดิม - ใช้ $total_shares ที่รวมแล้วจาก PHP)
function calculateDividend() {
     const profit = parseFloat($('#calcProfit')?.value || '0');
    const shares = parseFloat($('#calcShares')?.value || '<?= $total_shares ?>');
    const rate = parseFloat($('#calcRate')?.value || '0');
    // ... (การคำนวณเหมือนเดิม) ...
    updateDividendPreview(dividendPerShare); // เรียกใช้ตัวอัปเดตที่แก้ไขแล้ว
}

function updateDividendPreview(dividendPerShare) {
    const preview = $('#dividendPreview');
    if (!preview) return;
    
    if (dividendPerShare <= 0) {
        preview.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted py-4"> <i class="bi bi-calculator fs-1 d-block mb-2 opacity-25"></i>
                    กรอกข้อมูลเพื่อดูตัวอย่างการคำนวณ
                </td>
            </tr>
        `;
        return;
    }

    const showAll = $('#showAllMembers')?.checked;
    // ** เปลี่ยนกลับไปใช้ Object.values **
    const membersArray = Object.values(membersData); 
    const displayMembers = showAll ? membersArray : membersArray.slice(0, 10);
    
    let html = '';
    displayMembers.forEach((member, index) => {
        const amount = member.shares * dividendPerShare;
        html += `
            <tr>
                <td>${index + 1}</td>
                <td><strong>${member.code}</strong></td>
                <td>${member.member_name}</td>
                <td class="text-center"> <span class="member-type-badge type-${member.type}">
                        ${member.type_th}
                    </span>
                </td>
                <td class="text-center">
                    <span class="badge bg-primary">${member.shares.toLocaleString('th-TH')} หุ้น</span>
                </td>
                <td class="text-end">
                    <strong class="text-success">฿${amount.toLocaleString('th-TH', {minimumFractionDigits: 2})}</strong>
                </td>
            </tr>
        `;
    });
    
    if (!showAll && membersArray.length > 10) {
        html += `
            <tr class="table-light">
                <td colspan="6" class="text-center text-muted small"> <i class="bi bi-three-dots me-1"></i>
                    และอีก ${membersArray.length - 10} คน (เปิดสวิตช์ "แสดงทั้งหมด" เพื่อดูเพิ่ม)
                </td>
            </tr>
        `;
    }
    
    preview.innerHTML = html;
}

// ========== MODAL DATE RANGE & CALC (เหมือนเดิม) ==========
function updateDateRange() { /* ... same code ... */ }
function calculateDateRange() { /* ... same code ... */ }
function updateModalCalc() { /* ... same code ... */ }

// ========== MEMBER HISTORY (เหมือนเดิม) ==========
async function viewMemberHistory(memberKey) { // รับ 'member_1' หรือ 'manager_12'
    
    // ** แก้ไข: ใช้ membersData (ที่เป็น object) โดยตรง **
    const member = membersData[memberKey]; 
    if (!member) {
        toast('ไม่พบข้อมูลสมาชิก', false);
        return;
    }
    
    $('#historyMemberId').textContent = member.code;
    $('#historyMemberName').textContent = member.member_name;
    $('#historyMemberType').textContent = member.type_th; // ** นำกลับมา **

    const historyTable = $('#memberHistoryTable');
    historyTable.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';
    
    const summaryDiv = historyTable.closest('.modal-body').querySelector('.history-summary');
    if(summaryDiv) summaryDiv.innerHTML = '';

    new bootstrap.Modal('#modalMemberHistory').show();

    try {
        // ** แยก memberType และ memberId จาก key **
        const [memberType, memberId] = memberKey.split('_');
        
        const response = await fetch(
            `dividend_member_history.php?member_id=${memberId}&member_type=${memberType}` // ส่ง type ที่ถูกต้อง
        );
        
        if (!response.ok) {
            throw new Error('ไม่สามารถดึงข้อมูลได้');
        }
        
        const data = await response.json();

        // (โค้ดแสดงผล history และ summary ... เหมือนเดิม)
        if (data.ok && data.history.length > 0) {
            const summary = data.summary;
            let summaryHtml = `... (โค้ดสรุปยอด ...`;
            if(summaryDiv) summaryDiv.innerHTML = summaryHtml;

            let html = '';
            data.history.forEach(item => {
                html += `... (โค้ดสร้างแถวประวัติ) ...`;
            });
            historyTable.innerHTML = html;
        } else {
             if(summaryDiv) summaryDiv.innerHTML = '';
            historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">...ยังไม่มีประวัติ...</td></tr>`;
        }
    } catch (error) {
        console.error('History fetch error:', error);
        if(summaryDiv) summaryDiv.innerHTML = '';
        historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-3">...เกิดข้อผิดพลาด...</td></tr>`;
    }
}
// ========== DIVIDEND ACTIONS (เหมือนเดิม) ==========
function viewDividendDetails(year) { /* ... */ }
async function processPayout(year, csrfToken) { /* ... */ }

// ========== EXPORT (กลับมาเพิ่ม "ประเภท") ==========
function exportMembers() {
    const headers = ['รหัส', 'ชื่อ', 'ประเภท', 'หุ้น', <?php
        // Generate headers for each period dynamically
        echo implode(',', array_map(fn($p) => "'ปี ".$p['year']."'", $dividend_periods));
    ?>, 'รวมรับปันผล'];
    
    const rows = [headers];
    $$('#membersTable tbody tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        const cells = tr.querySelectorAll('td');
        const periodCount = <?= count($dividend_periods) ?>;
        
        // Code[0], Name[1], Type[2], Shares[3], [4...4+N-1]:Periods, [4+N]:Total, [5+N]:Actions
        if (cells.length >= 5 + periodCount) { // Adjusted index check
            const rowData = [
                cells[0].textContent.trim(), // Code
                cells[1].textContent.trim(), // Name
                cells[2].textContent.trim(), // Type (นำกลับมา)
                tr.dataset.shares || '0',    // Shares
            ];
            // Add period data (starts at index 4 now)
            for (let i = 0; i < periodCount; i++) {
                 rowData.push(cells[4 + i].textContent.replace(/[฿,]/g, '').trim());
            }
            // Add total (index 4 + periodCount)
            rowData.push(cells[4 + periodCount].textContent.replace(/[฿,]/g, '').trim());
            rows.push(rowData);
        }
    });

    const csv = rows.map(r => r.map(v => `"${(v||'').toString().replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `dividend_members_all_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

// ========== INITIALIZE (เหมือนเดิม) ==========
document.addEventListener('DOMContentLoaded', () => {
    if ($('#calcShares')) { $('#calcShares').value = <?= $total_shares ?>; }
    // ... (Event Listeners ...)
    applyMemberFilter(); // Apply filter on load
    calculateDividend(); // Initial run for calculator
});
</script>
</body>
</html>