<?php
// manager/dividend_detail.php — แสดงรายละเอียดงวดปันผล
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

$period_year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$period_details = null;
$payments = [];
$error_message = null;

if ($period_year <= 0) {
    $error_message = "ไม่ได้ระบุปีที่ต้องการดู";
} else {
    try {
        // 1) ดึงข้อมูลงวดปันผล
        $stmt_period = $pdo->prepare("
            SELECT id, `year`, start_date, end_date, period_name, total_profit, dividend_rate,
                   total_shares_at_time, total_dividend_amount, dividend_per_share, status, payment_date,
                   created_at, approved_by
            FROM dividend_periods
            WHERE `year` = :year
            LIMIT 1
        ");
        $stmt_period->execute([':year' => $period_year]);
        $period_details = $stmt_period->fetch(PDO::FETCH_ASSOC);

        if (!$period_details) {
            $error_message = "ไม่พบข้อมูลงวดปันผลสำหรับปี " . $period_year;
        } else {
            // 2) ดึงรายการจ่ายปันผลสำหรับงวดนี้ (รวมข้อมูลสมาชิก)
            $period_id = $period_details['id'];
            $stmt_payments = $pdo->prepare("
                SELECT
                    dp.id as payment_id,
                    dp.member_id,
                    dp.member_type,
                    dp.shares_at_time,
                    dp.dividend_amount,
                    dp.payment_status,
                    dp.paid_at,
                    COALESCE(u.full_name, CONCAT('Unknown ', dp.member_type, ' #', dp.member_id)) AS member_name,
                    CASE dp.member_type
                        WHEN 'member' THEN m.member_code
                        WHEN 'manager' THEN CONCAT('MGR-', LPAD(mg.id, 3, '0'))
                        WHEN 'committee' THEN c.committee_code
                        ELSE CONCAT('UNK-', dp.member_id)
                    END AS member_code
                FROM dividend_payments dp
                LEFT JOIN members m ON dp.member_type = 'member' AND dp.member_id = m.id
                LEFT JOIN managers mg ON dp.member_type = 'manager' AND dp.member_id = mg.id
                LEFT JOIN committees c ON dp.member_type = 'committee' AND dp.member_id = c.id
                LEFT JOIN users u ON (dp.member_type = 'member' AND m.user_id = u.id)
                                  OR (dp.member_type = 'manager' AND mg.user_id = u.id)
                                  OR (dp.member_type = 'committee' AND c.user_id = u.id)
                WHERE dp.period_id = :period_id
                ORDER BY member_code ASC
            ");
            $stmt_payments->execute([':period_id' => $period_id]);
            $payments = $stmt_payments->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (Throwable $e) {
        $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    }
}

// ดึงข้อมูลระบบ
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
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

// Function format number
function nf($n, $d = 2) { return number_format((float)$n, $d); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>รายละเอียดปันผล ปี <?= $period_year ?: '??' ?> | <?= htmlspecialchars($site_name) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

    <style>
        .status-paid { background: #d1f4dd; color: #0f5132; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .status-approved { background: #fff3cd; color: #664d03; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .status-pending { background: #f8d7da; color: #842029; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .summary-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: .5rem; padding: 1rem; }
        .member-type-badge { font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; }
        .type-member { background: #e7f1ff; color: #004085; }
        .type-manager { background: #fff3cd; color: #664d03; }
        .type-committee { background: #f8d7da; color: #721c24; }
        .panel { background:#fff; border:1px solid #e9ecef; border-radius:12px; padding:16px; margin-bottom: 1.5rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand" href="manager_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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
      <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
      <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
    </nav>
    <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
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
        <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

        <main class="col-lg-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="fa-solid fa-gift me-2"></i>รายละเอียดปันผล ปี <?= htmlspecialchars($period_year) ?>
                </h2>
                <a href="dividend.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>กลับไปหน้ารวม
                </a>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php elseif ($period_details): ?>
                <div class="panel">
                    <div class="row g-3">
                        <div class="col-md-6 col-lg-3">
                            <div class="summary-box text-center h-100">
                                <i class="bi bi-calendar-check fs-3 mb-2 text-primary"></i>
                                <div class="text-muted small">สถานะ</div>
                                <span class="status-<?= htmlspecialchars($period_details['status']) ?>">
                                    <?= [
                                        'paid' => 'จ่ายแล้ว',
                                        'approved' => 'อนุมัติแล้ว',
                                        'pending' => 'รออนุมัติ'
                                    ][$period_details['status']] ?? 'ไม่ทราบ' ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="summary-box text-center h-100">
                                <i class="bi bi-cash-stack fs-3 mb-2 text-success"></i>
                                <div class="text-muted small">ยอดปันผลรวม</div>
                                <h4 class="mb-0 text-success">฿<?= nf($period_details['total_dividend_amount'], 0) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="summary-box text-center h-100">
                                <i class="bi bi-coin fs-3 mb-2 text-info"></i>
                                <div class="text-muted small">ปันผลต่อหุ้น</div>
                                <h4 class="mb-0 text-info">฿<?= nf($period_details['dividend_per_share'] ?? 0, 4) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="summary-box text-center h-100">
                                <i class="bi bi-diagram-3 fs-3 mb-2 text-secondary"></i>
                                <div class="text-muted small">จำนวนหุ้น</div>
                                <h4 class="mb-0"><?= number_format($period_details['total_shares_at_time']) ?></h4>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="row small g-2">
                        <div class="col-sm-6"><strong>ชื่องวด:</strong> <?= htmlspecialchars($period_details['period_name']) ?></div>
                        <div class="col-sm-6"><strong>กำไรสุทธิ:</strong> ฿<?= nf($period_details['total_profit'], 2) ?></div>
                        <div class="col-sm-6"><strong>อัตราปันผล:</strong> <?= nf($period_details['dividend_rate'], 1) ?>%</div>
                        <div class="col-sm-6">
                            <strong>ช่วงเวลา:</strong>
                            <?php if (!empty($period_details['start_date']) && !empty($period_details['end_date'])): ?>
                                <?php
                                $start = new DateTime($period_details['start_date']);
                                $end = new DateTime($period_details['end_date']);
                                echo $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y');
                                ?>
                            <?php else: echo '-'; endif; ?>
                        </div>
                        <div class="col-sm-6">
                            <strong>วันที่จ่าย:</strong>
                            <?= $period_details['payment_date'] ? date('d/m/Y', strtotime($period_details['payment_date'])) : '-' ?>
                        </div>
                         <div class="col-sm-6">
                            <strong>ผู้อนุมัติ:</strong> <?= htmlspecialchars($period_details['approved_by'] ?: '-') ?>
                        </div>
                    </div>
                     <div class="mt-3 pt-3 border-top">
                        <?php if ($period_details['status'] === 'pending'): ?>
                            <button class="btn btn-warning me-2" onclick="approvePeriod(<?= (int)$period_details['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                <i class="bi bi-check-circle me-1"></i> อนุมัติงวด
                            </button>
                        <?php elseif ($period_details['status'] === 'approved'): ?>
                            <button class="btn btn-success me-2" onclick="processPayout(<?= (int)$period_details['year'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                <i class="fa-solid fa-money-check-dollar me-1"></i> จ่ายปันผล
                            </button>
                            <button class="btn btn-outline-secondary me-2" onclick="unapprovePeriod(<?= (int)$period_details['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> ยกเลิกการอนุมัติ
                            </button>
                        <?php elseif ($period_details['status'] === 'paid'): ?>
                            <span class="text-muted fst-italic">
                                <i class="bi bi-check-all me-1"></i> จ่ายปันผลเรียบร้อยแล้ว
                            </span>
                        <?php endif; ?>
                        <button class="btn btn-outline-danger" onclick="deletePeriod(<?= (int)$period_details['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                            <i class="bi bi-trash me-1"></i> ลบงวดปันผล
                        </button>
                    </div>
                </div>

                <div class="panel">
                     <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>รายการจ่ายปันผล (<?= count($payments) ?> รายการ)</h5>
                        <div class="d-flex flex-wrap gap-2">
                            <div class="input-group" style="max-width:280px;">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="search" id="paymentSearch" class="form-control" placeholder="ค้นหา รหัส/ชื่อ">
                            </div>
                            <select id="filterStatus" class="form-select" style="max-width:150px;">
                                <option value="">ทุกสถานะ</option>
                                <option value="paid">จ่ายแล้ว</option>
                                <option value="pending">รอจ่าย</option>
                            </select>
                             <button class="btn btn-outline-primary" onclick="exportPayments(<?= $period_year ?>)">
                                <i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="paymentsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>รหัส</th>
                                    <th>ชื่อผู้รับ</th>
                                    <th class="text-center">ประเภท</th>
                                    <th class="text-center">หุ้น</th>
                                    <th class="text-end">ยอดปันผล</th>
                                    <th class="text-center">สถานะ</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-3">ยังไม่มีรายการจ่ายสำหรับงวดนี้</td></tr>
                                <?php else: ?>
                                    <?php foreach($payments as $index => $p):
                                        $status_class = $p['payment_status'] === 'paid' ? 'status-paid' : 'status-pending';
                                        $status_text = $p['payment_status'] === 'paid' ? 'จ่ายแล้ว' : 'รอจ่าย';
                                        $type_class = 'type-' . htmlspecialchars($p['member_type']);
                                        $type_text = [
                                            'member' => 'สมาชิก',
                                            'manager' => 'ผู้บริหาร',
                                            'committee' => 'กรรมการ'
                                        ][$p['member_type']] ?? 'ไม่ทราบ';
                                    ?>
                                    <tr data-status="<?= htmlspecialchars($p['payment_status']) ?>"
                                        data-name="<?= htmlspecialchars($p['member_name']) ?>"
                                        data-code="<?= htmlspecialchars($p['member_code']) ?>">
                                        <td><?= $index + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($p['member_code']) ?></strong></td>
                                        <td><?= htmlspecialchars($p['member_name']) ?></td>
                                        <td class="text-center">
                                            <span class="member-type-badge <?= $type_class ?>"><?= $type_text ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary"><?= number_format($p['shares_at_time']) ?></span>
                                        </td>
                                        <td class="text-end">
                                            <strong class="text-success">฿<?= nf($p['dividend_amount'], 2) ?></strong>
                                        </td>
                                        <td class="text-center">
                                            <span class="<?= $status_class ?>"><?= $status_text ?></span>
                                            <?php if ($p['paid_at']): ?>
                                                <br><small class="text-muted"><?= date('d/m/y H:i', strtotime($p['paid_at'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($p['payment_status'] === 'pending' && $period_details['status'] !== 'paid'): // อนุญาตให้แก้สถานะถ้ายังไมจ่ายทั้งงวด ?>
                                            <button class="btn btn-sm btn-outline-success" title="ทำเครื่องหมายว่าจ่ายแล้ว"
                                                onclick="markAsPaid(<?= (int)$p['payment_id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <?php elseif ($p['payment_status'] === 'paid' && $period_details['status'] !== 'paid'): ?>
                                            <button class="btn btn-sm btn-outline-warning" title="ยกเลิกการจ่าย"
                                                onclick="markAsPending(<?= (int)$p['payment_id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                            <?php endif; ?>
                                             <button class="btn btn-sm btn-outline-info" title="ดูประวัติสมาชิก"
                                                onclick="viewMemberHistory('<?= htmlspecialchars($p['member_type'].'_'.$p['member_id']) ?>')">
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
            <?php endif; ?>
        </main>
    </div>
</div>

<footer class="footer">
    © <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — รายละเอียดปันผล
</footer>

<div class="modal fade" id="modalMemberHistory" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-clock-history me-2"></i>ประวัติการรับปันผล
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-sm-4">
                        <strong>รหัส:</strong> <span id="historyMemberCode">-</span>
                    </div>
                    <div class="col-sm-4">
                        <strong>ชื่อ:</strong> <span id="historyMemberName">-</span>
                    </div>
                    <div class="col-sm-4">
                        <strong>ประเภท:</strong> <span id="historyMemberType">-</span>
                    </div>
                </div>
                <div class="history-summary mb-3"></div>
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
    <div id="liveToast" class="toast border-0" role="status">
        <div class="d-flex">
            <div class="toast-body">บันทึกเรียบร้อย</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';
// ข้อมูลสมาชิกที่แสดงบนหน้านี้ (สำหรับ modal ประวัติ)
const membersDataLite = <?= json_encode(array_map(function($p){
    return [
        'key' => $p['member_type'].'_'.$p['member_id'],
        'code' => $p['member_code'],
        'name' => $p['member_name'],
        'type_th' => [
            'member' => 'สมาชิก',
            'manager' => 'ผู้บริหาร',
            'committee' => 'กรรมการ'
        ][$p['member_type']] ?? 'ไม่ทราบ'
    ];
}, $payments), JSON_UNESCAPED_UNICODE) ?>;

const $ = (s, p=document) => p.querySelector(s);
const $$ = (s, p=document) => [...p.querySelectorAll(s)];

// Toast Helper
const toast = (msg, success = true) => {
    const t = $('#liveToast');
    t.className = `toast align-items-center border-0 ${success ? 'text-bg-success' : 'text-bg-danger'}`;
    $('.toast-body', t).textContent = msg || 'ดำเนินการเรียบร้อย';
    bootstrap.Toast.getOrCreateInstance(t, { delay: 3000 }).show();
};

// Filter Payments Table
const paymentSearch = $('#paymentSearch');
const filterStatus = $('#filterStatus');

function applyPaymentFilter() {
    const keyword = (paymentSearch?.value || '').toLowerCase().trim();
    const status = filterStatus?.value || '';

    $$('#paymentsTable tbody tr').forEach(tr => {
        const name = (tr.dataset.name || '').toLowerCase();
        const code = (tr.dataset.code || '').toLowerCase();
        const rowStatus = tr.dataset.status;

        const matchKeyword = !keyword || name.includes(keyword) || code.includes(keyword);
        const matchStatus = !status || rowStatus === status;

        tr.style.display = (matchKeyword && matchStatus) ? '' : 'none';
    });
}
paymentSearch?.addEventListener('input', applyPaymentFilter);
filterStatus?.addEventListener('change', applyPaymentFilter);

// Export Payments CSV
function exportPayments(year) {
    const headers = ['#', 'รหัส', 'ชื่อ', 'ประเภท', 'หุ้น', 'ยอดปันผล', 'สถานะ', 'วันที่จ่าย'];
    const rows = [headers];
    let counter = 1;

    $$('#paymentsTable tbody tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        const cells = tr.querySelectorAll('td');
        if (cells.length < 8) return;

        const statusSpan = cells[6].querySelector('span');
        const dateSmall = cells[6].querySelector('small');

        rows.push([
            counter++,
            cells[1].textContent.trim(), // รหัส
            cells[2].textContent.trim(), // ชื่อ
            cells[3].textContent.trim(), // ประเภท
            cells[4].textContent.replace(/[^\d]/g,''), // หุ้น (เอาเฉพาะตัวเลข)
            cells[5].textContent.replace(/[฿,]/g, '').trim(), // ยอดปันผล
            statusSpan ? statusSpan.textContent.trim() : '', // สถานะ
            dateSmall ? dateSmall.textContent.trim() : '' // วันที่จ่าย
        ]);
    });

    const csv = rows.map(r => r.map(v => `"${(v||'').toString().replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `dividend_payments_${year}_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

// ========== ACTIONS (AJAX) ==========
async function sendAction(action, data) {
    try {
        const response = await fetch('../admin/dividend_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, ...data })
        });
        const result = await response.json();
        if (result.ok) {
            toast(result.message, true);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            toast(result.error, false);
        }
    } catch (error) {
        toast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + error.message, false);
    }
}

function approvePeriod(periodId, csrfToken) {
    if (!confirm('ยืนยันการอนุมัติงวดปันผลนี้?')) return;
    sendAction('approve_period', { period_id: periodId, csrf_token: csrfToken });
}

function unapprovePeriod(periodId, csrfToken) {
    if (!confirm('ยืนยันยกเลิกการอนุมัติงวดปันผลนี้? (สถานะจะกลับเป็น Pending)')) return;
     sendAction('unapprove_period', { period_id: periodId, csrf_token: csrfToken });
}

function processPayout(year, csrfToken) {
     if (!confirm(`ยืนยันการจ่ายปันผลปี ${year} ทั้งหมด?\n\nการดำเนินการนี้จะเปลี่ยนสถานะรายการที่รอจ่ายทั้งหมดเป็น "จ่ายแล้ว" และไม่สามารถย้อนกลับได้`)) return;
    sendAction('process_payout', { year: year, csrf_token: csrfToken });
}

function markAsPaid(paymentId, csrfToken) {
    if (!confirm('ยืนยันว่าจ่ายปันผลรายการนี้แล้ว?')) return;
    sendAction('mark_paid', { payment_id: paymentId, csrf_token: csrfToken });
}

function markAsPending(paymentId, csrfToken) {
     if (!confirm('ยกเลิกสถานะการจ่ายของรายการนี้? (จะกลับเป็น รอจ่าย)')) return;
    sendAction('mark_pending', { payment_id: paymentId, csrf_token: csrfToken });
}
function deletePeriod(periodId, csrfToken) {
    if (!confirm('คำเตือน!\nการลบงวดปันผลจะลบข้อมูลการจ่ายทั้งหมดที่เกี่ยวข้องกับงวดนี้ด้วย\n\nคุณแน่ใจหรือไม่ว่าต้องการลบงวดปันผลนี้? การกระทำนี้ไม่สามารถย้อนกลับได้')) return;
    sendAction('delete_period', { period_id: periodId, csrf_token: csrfToken });
}


// ========== MEMBER HISTORY MODAL (เหมือนหน้า dividend.php) ==========
async function viewMemberHistory(memberKey) {
    const [memberType, memberId] = memberKey.split('_');
    if (!memberId || !memberType) return toast('ข้อมูลสมาชิกไม่ถูกต้อง', false);

    const member = membersDataLite.find(m => m.key === memberKey);
    if (!member) return toast('ไม่พบข้อมูลสมาชิก', false);

    $('#historyMemberCode').textContent = member.code;
    $('#historyMemberName').textContent = member.name;
    $('#historyMemberType').textContent = member.type_th;

    const historyTable = $('#memberHistoryTable');
    historyTable.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';
    // Clear previous summary
    const summaryDiv = historyTable.closest('.modal-body').querySelector('.history-summary');
    if(summaryDiv) summaryDiv.innerHTML = '';


    new bootstrap.Modal('#modalMemberHistory').show();

    try {
        const response = await fetch(`../admin/dividend_member_history.php?member_id=${memberId}&member_type=${memberType}`);
        if (!response.ok) throw new Error('ไม่สามารถดึงข้อมูลได้');
        const data = await response.json();

        if (data.ok && data.history.length > 0) {
             // Display summary
            const summary = data.summary;
            if(summaryDiv) {
                 summaryDiv.innerHTML = `
                    <div class="alert alert-light border mb-3">
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
                    </div>`;
            }

            // Display history
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
             if(summaryDiv) summaryDiv.innerHTML = ''; // Clear summary if no history
            historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>ยังไม่มีประวัติการรับปันผล</td></tr>`;
        }
    } catch (error) {
        console.error('History fetch error:', error);
         if(summaryDiv) summaryDiv.innerHTML = ''; // Clear summary on error
        historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-3"><i class="bi bi-exclamation-triangle me-1"></i>เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
    }
}


// Initialize filters
document.addEventListener('DOMContentLoaded', () => {
    applyPaymentFilter();
});

</script>
</body>
</html>