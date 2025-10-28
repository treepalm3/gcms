<?php
// admin/dividend_detail.php — แสดงรายละเอียดงวดปันผล (แก้ไขการ Join เพื่อลดความซ้ำซ้อนและแก้ Deprecated Error)
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== Helpers =====
if (!function_exists('nf')) {
    function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
}
if (!function_exists('d')) {
    function d($s, $fmt = 'd/m/Y') {
        if (empty($s)) {
            return '-';
        }
        $t = strtotime($s);
        return $t ? date($fmt, $t) : '-';
    }
}
// ===== [สิ้นสุด Helpers] =====

$period_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$period_details = null;
$payments = [];
$error_message = null;

if ($period_id <= 0) {
    $error_message = "ไม่ได้ระบุ ID ของงวดปันผลที่ต้องการดู";
} else {
    try {
        // 1) ดึงข้อมูลงวดปันผล
        $stmt_period = $pdo->prepare("
            SELECT id, `year`, start_date, end_date, period_name, total_profit, dividend_rate,
                   total_shares_at_time, total_dividend_amount, dividend_per_share, status, payment_date,
                   created_at, approved_by
            FROM dividend_periods
            WHERE id = :id
            LIMIT 1
        ");
        $stmt_period->execute([':id' => $period_id]);
        $period_details = $stmt_period->fetch(PDO::FETCH_ASSOC);

        if (!$period_details) {
            $error_message = "ไม่พบข้อมูลงวดปันผล ID #" . $period_id;
        } else {
            // 2) ดึงรายการจ่ายปันผลสำหรับงวดนี้ (รวมข้อมูลสมาชิก)
            // ใช้ COALESCE ในการ Join users ครั้งเดียว เพื่อป้องกันการนับซ้ำ
            $stmt_payments = $pdo->prepare("
                SELECT
                    dp.id as payment_id,
                    dp.member_id,
                    dp.member_type,
                    dp.shares_at_time,
                    dp.dividend_amount,
                    dp.payment_status,
                    dp.paid_at,
                    u.full_name AS member_name,
                    -- ใช้ COALESCE เพื่อหา user_id ที่ถูกต้องจากทุกตารางโปรไฟล์
                    COALESCE(m.member_code, c.committee_code, CONCAT('MGR-', LPAD(mg.id, 3, '0')), CONCAT('UNK-', dp.member_id)) AS member_code,
                    COALESCE(m.user_id, mg.user_id, c.user_id) AS primary_user_id
                FROM dividend_payments dp
                -- Join กับตารางโปรไฟล์ทั้งหมดเพื่อหา user_id
                LEFT JOIN members m ON dp.member_type = 'member' AND dp.member_id = m.id
                LEFT JOIN managers mg ON dp.member_type = 'manager' AND dp.member_id = mg.id
                LEFT JOIN committees c ON dp.member_type = 'committee' AND dp.member_id = c.id
                -- Join users ครั้งเดียวด้วย user_id ที่ได้จากตารางโปรไฟล์
                LEFT JOIN users u ON COALESCE(m.user_id, mg.user_id, c.user_id) = u.id
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>รายละเอียดปันผล <?= $period_details ? 'ปี ' . $period_details['year'] : '' ?> | <?= htmlspecialchars($site_name) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .status-paid, .status-approved, .status-pending { padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; display: inline-block; }
        .status-paid { background-color: var(--bs-success-bg-subtle); color: var(--bs-success-text-emphasis); }
        .status-approved { background-color: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
        .status-pending { background-color: var(--bs-danger-bg-subtle); color: var(--bs-danger-text-emphasis); }
        .member-type-badge { font-size:.75rem; padding:.2rem .5rem; border-radius:12px; font-weight: 500;}
        .type-member { background-color: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); }
        .type-manager { background-color: var(--bs-info-bg-subtle); color: var(--bs-info-text-emphasis); }
        .type-committee { background-color: var(--bs-secondary-bg-subtle); color: var(--bs-secondary-text-emphasis); }
        .table-hover tbody tr:hover { background-color: var(--bs-light-bg-subtle); }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"><span class="navbar-toggler-icon"></span></button>
            <a class="navbar-brand" href="admin_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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

<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body sidebar">
        <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
        <nav class="sidebar-menu">
            <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
            <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
            <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
            <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
            <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
            <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
            <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
            <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
            <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่าระบบ</a>
        </nav>
        <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
            <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
            <nav class="sidebar-menu flex-grow-1">
                <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
                <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
                <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
                <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
                <a href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
                <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
                <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
                <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
                <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
            </nav>
            <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
        </aside>

        <main class="col-lg-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="fa-solid fa-gift me-2"></i>
                    รายละเอียดปันผล <?= $period_details ? 'ปี ' . htmlspecialchars($period_details['year']) : '' ?>
                </h2>
                <a href="dividend.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>กลับไปหน้ารวม
                </a>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php elseif ($period_details): ?>
                
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><?= htmlspecialchars($period_details['period_name']) ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid mb-3">
                            <div class="card card-body shadow-sm text-center">
                                <div class="fs-4 text-primary mb-2"><i class="bi bi-calendar-check"></i></div>
                                <h6 class="text-muted mb-1">สถานะ</h6>
                                <span class="status-<?= htmlspecialchars($period_details['status']) ?>">
                                    <?= ['paid' => 'จ่ายแล้ว', 'approved' => 'อนุมัติ', 'pending' => 'รออนุมัติ'][$period_details['status']] ?? '?' ?>
                                </span>
                            </div>
                            <div class="card card-body shadow-sm text-center">
                                <div class="fs-4 text-success mb-2"><i class="bi bi-cash-stack"></i></div>
                                <h6 class="text-muted mb-1">ยอดปันผลรวม</h6>
                                <h4 class="mb-0 text-success">฿<?= nf($period_details['total_dividend_amount'], 0) ?></h4>
                            </div>
                            <div class="card card-body shadow-sm text-center">
                                <div class="fs-4 text-info mb-2"><i class="bi bi-coin"></i></div>
                                <h6 class="text-muted mb-1">ปันผลต่อหุ้น</h6>
                                <h4 class="mb-0 text-info">฿<?= nf($period_details['dividend_per_share'] ?? 0, 4) ?></h4>
                            </div>
                            <div class="card card-body shadow-sm text-center">
                                <div class="fs-4 text-secondary mb-2"><i class="bi bi-diagram-3"></i></div>
                                <h6 class="text-muted mb-1">จำนวนหุ้น</h6>
                                <h4 class="mb-0"><?= number_format($period_details['total_shares_at_time']) ?></h4>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row small g-2">
                            <div class="col-sm-6"><strong>กำไรสุทธิ (ฐาน):</strong> ฿<?= nf($period_details['total_profit'], 2) ?></div>
                            <div class="col-sm-6"><strong>อัตราปันผล:</strong> <?= nf($period_details['dividend_rate'], 1) ?>%</div>
                            <div class="col-sm-6"><strong>ช่วงเวลา:</strong> <?= d($period_details['start_date']) ?> - <?= d($period_details['end_date']) ?></div>
                            <div class="col-sm-6"><strong>วันที่จ่าย:</strong> <?= d($period_details['payment_date']) ?></div>
                            <div class="col-sm-6"><strong>สร้างเมื่อ:</strong> <?= d($period_details['created_at']) ?></div>
                            <div class="col-sm-6"><strong>ผู้อนุมัติ:</strong> <?= htmlspecialchars($period_details['approved_by'] ?: '-') ?></div>
                        </div>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($period_details['status'] === 'pending'): ?>
                                <button class="btn btn-warning" onclick="approvePeriod(<?= (int)$period_details['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                    <i class="bi bi-check-circle me-1"></i> อนุมัติงวด
                                </button>
                            <?php elseif ($period_details['status'] === 'approved'): ?>
                                <button class="btn btn-success" onclick="processPayout(<?= (int)$period_details['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                    <i class="fa-solid fa-money-check-dollar me-1"></i> ยืนยันการจ่าย
                                </button>
                                <button class="btn btn-outline-secondary" onclick="unapprovePeriod(<?= (int)$period_details['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> ยกเลิกอนุมัติ
                                </button>
                            <?php elseif ($period_details['status'] === 'paid'): ?>
                                <span class="text-success fw-bold p-2"><i class="bi bi-check-all me-1"></i> จ่ายปันผลเรียบร้อยแล้ว</span>
                            <?php endif; ?>
                            
                            <button class="btn btn-outline-danger ms-auto" onclick="deletePeriod(<?= (int)$period_details['id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                <i class="bi bi-trash me-1"></i> ลบงวดปันผลนี้
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                     <div class="card-header bg-light">
                         <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>รายการจ่ายปันผล (<?= count($payments) ?> รายการ)</h5>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="input-group input-group-sm" style="max-width:280px;">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="search" id="paymentSearch" class="form-control" placeholder="ค้นหา รหัส/ชื่อ">
                                </div>
                                <select id="filterStatus" class="form-select form-select-sm" style="max-width:150px;">
                                    <option value="">ทุกสถานะ</option>
                                    <option value="paid">จ่ายแล้ว</option>
                                    <option value="pending">รอจ่าย</option>
                                </select>
                                 <button class="btn btn-sm btn-outline-secondary" onclick="exportPayments(<?= $period_details['year'] ?>)">
                                    <i class="bi bi-download me-1"></i> ส่งออก
                                </button>
                            </div>
                        </div>
                     </div>
                     <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="paymentsTable">
                                <thead>
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
                                            $type_class = 'type-' . htmlspecialchars($p['member_type'] ?? 'unknown');
                                            $type_text = [
                                                'member' => 'สมาชิก',
                                                'manager' => 'ผู้บริหาร',
                                                'committee' => 'กรรมการ',
                                                'unknown' => 'ไม่ทราบ'
                                            ][$p['member_type'] ?? 'unknown'] ?? 'ไม่ทราบ';
                                        ?>
                                        <tr data-status="<?= htmlspecialchars($p['payment_status'] ?? 'pending') ?>"
                                            data-name="<?= htmlspecialchars($p['member_name'] ?? 'ไม่ระบุ') ?>"
                                            data-code="<?= htmlspecialchars($p['member_code'] ?? 'UNK-'.($p['member_id'] ?? '')) ?>">
                                            <td><?= $index + 1 ?></td>
                                            <td><strong><?= htmlspecialchars($p['member_code'] ?? 'UNK-'.($p['member_id'] ?? '')) ?></strong></td>
                                            <td><?= htmlspecialchars($p['member_name'] ?? 'ไม่ระบุ') ?></td>
                                            <td class="text-center">
                                                <span class="member-type-badge <?= $type_class ?>"><?= $type_text ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary rounded-pill"><?= number_format($p['shares_at_time'] ?? 0) ?></span>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-success">฿<?= nf($p['dividend_amount'] ?? 0, 2) ?></strong>
                                            </td>
                                            <td class="text-center">
                                                <span class="<?= $status_class ?>"><?= $status_text ?></span>
                                                <?php if ($p['paid_at']): ?>
                                                    <br><small class="text-muted"><?= d($p['paid_at'], 'd/m/y H:i') ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($p['payment_status'] === 'pending' && $period_details['status'] !== 'paid'): ?>
                                                <button class="btn btn-sm btn-outline-success py-0 px-1" title="ทำเครื่องหมายว่าจ่ายแล้ว"
                                                    onclick="markAsPaid(<?= (int)$p['payment_id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                                <?php elseif ($p['payment_status'] === 'paid' && $period_details['status'] !== 'paid'): ?>
                                                <button class="btn btn-sm btn-outline-warning py-0 px-1" title="ยกเลิกการจ่าย"
                                                    onclick="markAsPending(<?= (int)$p['payment_id'] ?>, '<?= $_SESSION['csrf_token'] ?>')">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                                <?php endif; ?>
                                                 <button class="btn btn-sm btn-outline-info py-0 px-1" title="ดูประวัติสมาชิก"
                                                    onclick="viewMemberHistory('<?= htmlspecialchars($p['member_type'] ?? 'unknown').'_'.(int)$p['member_id'] ?>')">
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
            <?php endif; ?>
        </main>
    </div>
</div>

<footer class="footer mt-auto py-3 bg-light">
    <div class="container text-center">
        <span class="text-muted">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>
    </div>
</footer>

<div class="modal fade" id="modalMemberHistory" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>ประวัติการรับปันผล/เฉลี่ยคืน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div>
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
const $ = (s, p=document) => p.querySelector(s);
const $$ = (s, p=document) => [...p.querySelectorAll(s)];

const membersDataLite = <?= json_encode(array_map(function($p){
    // ใช้เทคนิค ?? เพื่อป้องกัน Deprecated
    $memberType = $p['member_type'] ?? 'unknown';
    return [
        'key' => $memberType.'_'.($p['member_id'] ?? ''),
        'code' => $p['member_code'] ?? 'UNK-'.($p['member_id'] ?? ''),
        'name' => $p['member_name'] ?? 'ไม่ระบุ',
        'type_th' => [
            'member' => 'สมาชิก',
            'manager' => 'ผู้บริหาร',
            'committee' => 'กรรมการ'
        ][$memberType] ?? 'ไม่ทราบ'
    ];
}, $payments), JSON_UNESCAPED_UNICODE) ?>;


const toast = (msg, success = true) => {
    const t = $('#liveToast');
    if (!t) return;
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
            cells[1].textContent.trim(),
            cells[2].textContent.trim(),
            cells[3].textContent.trim(),
            cells[4].textContent.replace(/[^\d]/g,''),
            cells[5].textContent.replace(/[฿,]/g, '').trim(),
            statusSpan ? statusSpan.textContent.trim() : '',
            dateSmall ? dateSmall.textContent.trim() : ''
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
// [สำคัญ] ไฟล์นี้จะเรียกไปที่ 'dividend_action.php' (ไฟล์เดียว)
async function sendAction(action, data) {
    const actionFile = 'dividend_action.php'; // <--- !!! ต้องสร้างไฟล์นี้ !!!

    try {
        const response = await fetch(actionFile, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action: action, ...data })
        });
        const result = await response.json();
        if (result.ok) {
            toast(result.message, true);
            if (action === 'delete_period') {
                setTimeout(() => window.location.href = 'dividend.php', 1500);
            } else {
                setTimeout(() => window.location.reload(), 1500);
            }
        } else {
            throw new Error(result.error || 'การดำเนินการล้มเหลว');
        }
    } catch (error) {
        console.error("Action Error:", error);
        toast('เกิดข้อผิดพลาด: ' + error.message, false);
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

function processPayout(periodId, csrfToken) { // [แก้ไข] ใช้ periodId
     const periodYear = <?= (int)($period_details['year'] ?? 0) ?>;
     if (!confirm(`ยืนยันการจ่ายปันผลปี ${periodYear} ทั้งหมด?\n\nการดำเนินการนี้จะเปลี่ยนสถานะรายการที่รอจ่ายทั้งหมดเป็น "จ่ายแล้ว"`)) return;
    sendAction('process_payout', { period_id: periodId, csrf_token: csrfToken }); // [แก้ไข] ส่ง period_id
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


// ========== MEMBER HISTORY MODAL (ต้องมี dividend_member_history.php) ==========
async function viewMemberHistory(memberKey) {
    const [memberType, memberId] = memberKey.split('_');
    if (!memberId || !memberType) return toast('ข้อมูลสมาชิกไม่ถูกต้อง', false);

    const member = membersDataLite.find(m => m.key === memberKey);
    if (!member) {
        // [แก้ไข] ถ้าหาไม่เจอใน $membersDataLite (เพราะอาจจะมาจากตารางอื่น)
        // ให้ลองใช้ข้อมูลจากแถวที่กด
        const tr = $(`tr[data-member-key="${memberKey}"]`);
        if(tr) {
            $('#historyMemberCode').textContent = tr.querySelector('td:nth-child(2)').textContent.trim();
            $('#historyMemberName').textContent = tr.querySelector('td:nth-child(3)').textContent.trim();
            $('#historyMemberType').textContent = tr.querySelector('td:nth-child(4)').textContent.trim();
        } else {
             return toast('ไม่พบข้อมูลสมาชิก', false);
        }
    } else {
        $('#historyMemberCode').textContent = member.code;
        $('#historyMemberName').textContent = member.name;
        $('#historyMemberType').textContent = member.type_th;
    }


    const historyTable = $('#memberHistoryTable');
    const summaryDiv = historyTable.closest('.modal-body').querySelector('.history-summary');
    historyTable.innerHTML = '<tr><td colspan="5" class="text-center p-3"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';
    if(summaryDiv) summaryDiv.innerHTML = '';

    new bootstrap.Modal('#modalMemberHistory').show();

    try {
        const response = await fetch(`dividend_member_history.php?member_id=${memberId}&member_type=${memberType}`);
        if (!response.ok) throw new Error(`ไม่สามารถดึงข้อมูลได้ (HTTP ${response.status})`);
        const data = await response.json();

        if (data.ok && data.history.length > 0) {
            const summary = data.summary;
            if(summaryDiv) {
                 summaryDiv.innerHTML = `
                    <div class="alert alert-light border small mb-3">
                        <div class="row text-center">
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
                        <td>${item.type || (isDividend ? 'ปันผล' : 'เฉลี่ยคืน')}</td>
                        <td class="text-end small">${item.details || (isDividend ? `${(item.shares_at_time || 0).toLocaleString('th-TH')} หุ้น @ ${parseFloat(item.dividend_rate || 0).toFixed(1)}%` : 'N/A')}</td>
                        <td class="text-end"><strong class="${isDividend ? 'text-success' : 'text-info'}">${item.amount_formatted || item.dividend_amount_formatted || '฿0.00'}</strong></td>
                        <td class="text-center">
                            <span class="${statusClass}">${statusText}</span>
                            ${item.payment_date_formatted !== '-' ? `<br><small class="text-muted">${item.payment_date_formatted}</small>` : ''}
                        </td>
                    </tr>`;
            });
            historyTable.innerHTML = html;
        } else if (data.ok) {
             if(summaryDiv) summaryDiv.innerHTML = '';
            historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-muted p-4"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>ยังไม่มีประวัติการรับปันผล/เฉลี่ยคืน</td></tr>`;
        } else {
            throw new Error(data.error || 'ไม่สามารถโหลดข้อมูลประวัติได้');
        }
    } catch (error) {
        console.error('History fetch error:', error);
         if(summaryDiv) summaryDiv.innerHTML = '';
        historyTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger p-3"><i class="bi bi-exclamation-triangle me-1"></i>เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
    }
}

// Initialize filters
document.addEventListener('DOMContentLoaded', () => {
    applyPaymentFilter();
});
</script>
</body>
</html>