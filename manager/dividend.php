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

$dividend_periods = [];
$members_dividends = [];
$error_message = null;

try {
    // 1) งวดปันผล (รายปี) - รวม start_date, end_date
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
    
    // 2.2) ผู้บริหาร (ไม่มี manager_code → สร้างจาก id)
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
    } catch (Throwable $e) {
        error_log("Error fetching managers: " . $e->getMessage());
    }
    
    // 2.3) กรรมการ (มี committee_code)
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
        return strcmp($a['member_code'], $b['member_code']);
    });

    // สร้าง array สำหรับแสดงผล
    foreach ($all_members as $row) {
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

    // 3) การจ่ายปันผล
    $payments_stmt = $pdo->query("
        SELECT dp.member_id, dp.member_type, dp.period_id, 
               dp.dividend_amount, dp.payment_status
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

} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
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

// คำนวณสถิติ
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

    <!-- Content -->
    <main class="col-lg-10 p-4">
            <div class="main-header">
                <h2><i class="fa-solid fa-gift"></i> ปันผล</h2>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <!-- Summary Cards -->
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

            <!-- Tabs -->
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
                <!-- งวดปันผลรายปี -->
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

                    <!-- Dividend Cards -->
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
                                            <small class="text-muted">
                                                <?= htmlspecialchars($period['period_name']) ?>
                                            </small>
                                            <?php if (!empty($period['start_date']) && !empty($period['end_date'])): ?>
                                            <br>
                                            <small class="text-muted">
                                                <i class="bi bi-calendar-range"></i>
                                                <?php
                                                    $start = new DateTime($period['start_date']);
                                                    $end = new DateTime($period['end_date']);
                                                    echo $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y');
                                                ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="status-<?= htmlspecialchars($period['status']) ?>">
                                            <?= [
                                                'paid' => 'จ่ายแล้ว',
                                                'approved' => 'อนุมัติแล้ว',
                                                'pending' => 'รออนุมัติ'
                                            ][$period['status']] ?? 'ไม่ทราบสถานะ' ?>
                                        </span>
                                    </div>

                                    <div class="mb-3">
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <small class="text-muted">อัตราปันผล</small><br>
                                                <span class="dividend-rate">
                                                    <?= number_format($period['dividend_rate'], 1) ?>%
                                                </span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">ยอดรวม</small><br>
                                                <span class="dividend-amount">
                                                    ฿<?= number_format($period['total_dividend_amount'], 0) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-column gap-1 small">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">กำไรสุทธิ:</span>
                                            <span>฿<?= number_format($period['total_profit'], 0) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">จำนวนหุ้น:</span>
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

                <!-- ผู้ถือหุ้น -->
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
<!-- คำนวณปันผล -->
<div class="tab-pane fade" id="calculator-panel">
                <div class="panel">
                    <h5 class="mb-4">
                        <i class="bi bi-calculator me-2"></i>เครื่องคำนวณปันผล
                    </h5>
                    
                    <div class="row g-4">
                        <!-- Input Section -->
                        <div class="col-md-5">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <h6 class="card-title mb-3">
                                        <i class="bi bi-pencil-square me-2"></i>ข้อมูลพื้นฐาน
                                    </h6>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-currency-dollar me-1"></i>กำไรสุทธิ (บาท)
                                        </label>
                                        <input type="number" id="calcProfit" class="form-control calc-input" 
                                               placeholder="เช่น 1,000,000" step="0.01" oninput="calculateDividend()">
                                        <small class="text-muted">กำไรสุทธิรวมของปีที่ต้องการคำนวณ</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-diagram-3 me-1"></i>จำนวนหุ้นรวม
                                        </label>
                                        <input type="number" id="calcShares" class="form-control calc-input" 
                                               value="<?= $total_shares ?>" step="1" oninput="calculateDividend()">
                                        <small class="text-muted">จำนวนหุ้นทั้งหมดในระบบ</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">
                                            <i class="bi bi-percent me-1"></i>อัตราปันผล (%)
                                        </label>
                                        <input type="number" id="calcRate" class="form-control calc-input" 
                                               placeholder="เช่น 15" step="0.1" min="0" max="100" oninput="calculateDividend()">
                                        <small class="text-muted">เปอร์เซ็นต์ของกำไรที่จะจ่ายเป็นปันผล</small>
                                    </div>

                                    <div class="alert alert-info mb-0">
                                        <small>
                                            <i class="bi bi-info-circle me-1"></i>
                                            <strong>หมายเหตุ:</strong> ตามกฎหมายสหกรณ์ ควรสำรองกำไร 10% เป็นทุนสำรอง 
                                            และ 5% เป็นกองทุนสวัสดิการ
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Result Section -->
                        <div class="col-md-7">
                            <div class="calc-result mb-4">
                                <div class="row text-center">
                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <i class="bi bi-cash-stack fs-3 mb-2"></i>
                                        <div class="small opacity-75">ยอดปันผลรวม</div>
                                        <h3 class="mb-0" id="totalDividend">฿0.00</h3>
                                    </div>
                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <i class="bi bi-coin fs-3 mb-2"></i>
                                        <div class="small opacity-75">ปันผลต่อหุ้น</div>
                                        <h3 class="mb-0" id="dividendPerShare">฿0.00</h3>
                                    </div>
                                    <div class="col-md-4">
                                        <i class="bi bi-pie-chart fs-3 mb-2"></i>
                                        <div class="small opacity-75">% ของกำไร</div>
                                        <h3 class="mb-0" id="profitPercentage">0%</h3>
                                    </div>
                                </div>
                            </div>

                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <h6 class="card-title mb-3">
                                        <i class="bi bi-pie-chart-fill me-2"></i>การจัดสรรกำไร
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 bg-warning bg-opacity-10 rounded">
                                                <small class="text-muted d-block">ทุนสำรอง (10%)</small>
                                                <strong id="reserveFund">฿0.00</strong>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 bg-info bg-opacity-10 rounded">
                                                <small class="text-muted d-block">กองทุนสวัสดิการ (5%)</small>
                                                <strong id="welfareFund">฿0.00</strong>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-3 bg-success bg-opacity-10 rounded">
                                                <small class="text-muted d-block">คงเหลือหลังหักทุนสำรอง (85%)</small>
                                                <strong class="text-success" id="netAvailable">฿0.00</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Table -->
                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">
                                <i class="bi bi-table me-2"></i>การจ่ายตามผู้ถือหุ้น
                            </h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="showAllMembers" onchange="calculateDividend()">
                                <label class="form-check-label" for="showAllMembers">แสดงทั้งหมด</label>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>รหัส</th>
                                        <th>ชื่อ</th>
                                        <th class="text-center">ประเภท</th>
                                        <th class="text-center">หุ้น</th>
                                        <th class="text-end">ปันผลที่จะได้รับ</th>
                                    </tr>
                                </thead>
                                <tbody id="dividendPreview">
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
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
    © <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการปันผล
</footer>
<!-- Modal: Create Dividend -->
<div class="modal fade" id="modalCreateDividend" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="/../admin/dividend_create_period.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">
                <i class="fa-solid fa-plus me-2"></i>สร้างงวดปันผลรายปี
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        
        <div class="modal-body">
            <div class="row g-3">
                <!-- ปี -->
                <div class="col-sm-6">
                    <label class="form-label">
                        <i class="bi bi-calendar me-1"></i>ปี <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="year" id="dividendYear" class="form-control" 
                           value="<?= date('Y') ?>" required min="2020" max="2050"
                           onchange="updateDateRange()">
                    <small class="text-muted">ปีที่ต้องการสร้างงวดปันผล</small>
                </div>

                <!-- ชื่องวด -->
                <div class="col-sm-6">
                    <label class="form-label">
                        <i class="bi bi-card-text me-1"></i>ชื่องวด
                    </label>
                    <input type="text" name="period_name" id="periodName" class="form-control" 
                           placeholder="เช่น ปันผลประจำปี 2025" 
                           value="ปันผลประจำปี <?= date('Y') ?>">
                    <small class="text-muted">เว้นว่างเพื่อให้ระบบสร้างอัตโนมัติ</small>
                </div>

                <!-- วันที่เริ่มต้น -->
                <div class="col-sm-6">
                    <label class="form-label">
                        <i class="bi bi-calendar-event me-1"></i>วันที่เริ่มต้น <span class="text-danger">*</span>
                    </label>
                    <input type="date" name="start_date" id="startDate" class="form-control" 
                           value="<?= date('Y') ?>-01-01" required>
                    <small class="text-muted">เริ่มนับกำไรตั้งแต่วันที่</small>
                </div>

                <!-- วันที่สิ้นสุด -->
                <div class="col-sm-6">
                    <label class="form-label">
                        <i class="bi bi-calendar-check me-1"></i>วันที่สิ้นสุด <span class="text-danger">*</span>
                    </label>
                    <input type="date" name="end_date" id="endDate" class="form-control" 
                           value="<?= date('Y') ?>-12-31" required>
                    <small class="text-muted">นับกำไรถึงวันที่</small>
                </div>

                <!-- แสดงช่วงเวลา -->
                <div class="col-12">
                    <div class="alert alert-light border mb-0">
                        <small class="text-muted">
                            <i class="bi bi-calendar-range me-1"></i>
                            <strong>ช่วงเวลา:</strong> 
                            <span id="dateRangeDisplay">1 ม.ค. <?= date('Y') ?> - 31 ธ.ค. <?= date('Y') ?></span>
                            (<span id="daysCount">365</span> วัน)
                        </small>
                    </div>
                </div>

                <!-- กำไรสุทธิ -->
                <div class="col-sm-6">
                    <label class="form-label">
                        <i class="bi bi-currency-dollar me-1"></i>กำไรสุทธิ (บาท) <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="total_profit" id="modalProfit" class="form-control" 
                           step="0.01" min="0.01" required placeholder="0.00" 
                           oninput="updateModalCalc()">
                    <small class="text-muted">กำไรสุทธิในช่วงเวลาที่เลือก</small>
                </div>

                <!-- อัตราปันผล -->
                <div class="col-sm-6">
                    <label class="form-label">
                        <i class="bi bi-percent me-1"></i>อัตราปันผล (%) <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="dividend_rate" id="modalRate" class="form-control" 
                           step="0.1" min="0.1" max="100" required placeholder="เช่น 15"
                           oninput="updateModalCalc()">
                    <small class="text-muted">% ของกำไรที่จะจ่ายเป็นปันผล</small>
                </div>

                <!-- จำนวนหุ้นรวม -->
                <div class="col-sm-6">
                    <label class="form-label">
                        <i class="bi bi-diagram-3 me-1"></i>จำนวนหุ้นรวม
                    </label>
                    <input type="number" class="form-control bg-light" 
                           value="<?= $total_shares ?>" readonly>
                    <small class="text-muted">รวมทุกประเภทในระบบ</small>
                </div>

                <!-- ยอดปันผลรวม -->
                <div class="col-sm-6">
                    <label class="form-label">
                        <i class="bi bi-cash-stack me-1"></i>ยอดปันผลรวม (บาท)
                    </label>
                    <input type="text" id="modalTotal" class="form-control bg-light" 
                           value="0.00" readonly>
                    <small class="text-muted">คำนวณอัตโนมัติ</small>
                </div>

                <!-- หมายเหตุ -->
                <div class="col-12">
                    <label class="form-label">
                        <i class="bi bi-chat-left-text me-1"></i>หมายเหตุ
                    </label>
                    <textarea name="notes" class="form-control" rows="3" 
                              placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                </div>
            </div>

            <!-- Alert Info -->
            <div class="alert alert-info mt-3 mb-0">
                <div class="d-flex">
                    <i class="bi bi-info-circle fs-5 me-2"></i>
                    <div>
                        <strong>หมายเหตุ:</strong>
                        <ul class="mb-0 mt-2">
                            <li>แต่ละปีสามารถมีได้เพียง 1 งวดปันผล</li>
                            <li>หลังสร้างงวด สถานะจะเป็น <span class="badge bg-warning">รออนุมัติ</span></li>
                            <li>ระบบจะสร้างรายการจ่ายให้ผู้ถือหุ้นทุกคนอัตโนมัติ</li>
                            <li>ช่วงวันที่จะบันทึกไว้เพื่ออ้างอิง</li>
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
<!-- Modal: Member History -->
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
                        <strong>รหัส:</strong> <span id="historyMemberId">-</span>
                    </div>
                    <div class="col-sm-4">
                        <strong>ชื่อ:</strong> <span id="historyMemberName">-</span>
                    </div>
                    <div class="col-sm-4">
                        <strong>ประเภท:</strong> <span id="historyMemberType">-</span>
                    </div>
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
                        <tbody id="memberHistoryTable">
                            <tr>
                                <td colspan="5" class="text-center text-muted">กำลังโหลด...</td>
                            </tr>
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
<!-- Toast -->
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

const $ = (s, p=document) => p.querySelector(s);
const $$ = (s, p=document) => [...p.querySelectorAll(s)];

// ข้อมูลสมาชิก
const membersData = <?= json_encode(array_values($members_dividends), JSON_UNESCAPED_UNICODE) ?>;

// Toast Helper
const toast = (msg, success = true) => {
    const t = $('#liveToast');
    t.className = `toast align-items-center border-0 ${success ? 'text-bg-success' : 'text-bg-danger'}`;
    $('.toast-body', t).textContent = msg || 'บันทึกเรียบร้อย';
    bootstrap.Toast.getOrCreateInstance(t, { delay: 3000 }).show();
};

// แสดง Toast จาก URL
const urlParams = new URLSearchParams(window.location.search);
const okMsg = urlParams.get('ok');
const errMsg = urlParams.get('err');
if (okMsg) {
    toast(okMsg, true);
    window.history.replaceState({}, document.title, window.location.pathname);
}
if (errMsg) {
    toast(errMsg, false);
    window.history.replaceState({}, document.title, window.location.pathname);
}

// ========== FILTER MEMBERS ==========
const memberSearch = $('#memberSearch');
const filterType = $('#filterType');
const minShares = $('#minShares');

function normalize(s) { 
    return (s || '').toString().toLowerCase().trim(); 
}

function applyMemberFilter() {
    const keyword = normalize(memberSearch?.value || '');
    const type = filterType?.value || '';
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
filterType?.addEventListener('change', applyMemberFilter);
minShares?.addEventListener('input', applyMemberFilter);

// ========== CALCULATOR ==========
function calculateDividend() {
    const profit = parseFloat($('#calcProfit')?.value || '0');
    const shares = parseFloat($('#calcShares')?.value || '0');
    const rate = parseFloat($('#calcRate')?.value || '0');

    // คำนวณปันผล
    const totalDividend = profit * (rate / 100);
    const dividendPerShare = shares > 0 ? totalDividend / shares : 0;
    const profitPercentage = profit > 0 ? (totalDividend / profit) * 100 : 0;

    // คำนวณทุนสำรอง
    const reserveFund = profit * 0.10;
    const welfareFund = profit * 0.05;
    const netAvailable = profit * 0.85;

    // แสดงผล
    $('#totalDividend').textContent = '฿' + totalDividend.toLocaleString('th-TH', {minimumFractionDigits: 2});
    $('#dividendPerShare').textContent = '฿' + dividendPerShare.toLocaleString('th-TH', {minimumFractionDigits: 2});
    $('#profitPercentage').textContent = profitPercentage.toFixed(1) + '%';
    
    $('#reserveFund').textContent = '฿' + reserveFund.toLocaleString('th-TH', {minimumFractionDigits: 2});
    $('#welfareFund').textContent = '฿' + welfareFund.toLocaleString('th-TH', {minimumFractionDigits: 2});
    $('#netAvailable').textContent = '฿' + netAvailable.toLocaleString('th-TH', {minimumFractionDigits: 2});

    // แสดงตัวอย่าง
    updateDividendPreview(dividendPerShare);
}

function updateDividendPreview(dividendPerShare) {
    const preview = $('#dividendPreview');
    if (!preview) return;
    
    if (dividendPerShare <= 0) {
        preview.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
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
                    <span class="member-type-badge type-${member.type}">
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
    
    if (!showAll && membersData.length > 10) {
        html += `
            <tr class="table-light">
                <td colspan="6" class="text-center text-muted small">
                    <i class="bi bi-three-dots me-1"></i>
                    และอีก ${membersData.length - 10} คน (เปิดสวิตช์ "แสดงทั้งหมด" เพื่อดูเพิ่ม)
                </td>
            </tr>
        `;
    }
    
    preview.innerHTML = html;
}

// ========== MODAL DATE RANGE ==========
function updateDateRange() {
    const year = $('#dividendYear')?.value || <?= date('Y') ?>;
    const startDate = $('#startDate');
    const endDate = $('#endDate');
    const periodName = $('#periodName');
    
    if (startDate && endDate) {
        startDate.value = `${year}-01-01`;
        endDate.value = `${year}-12-31`;
        
        // อัปเดตชื่องวด
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
    
    // คำนวณจำนวนวัน
    const diffTime = Math.abs(endDate - startDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
    
    // แสดงผล
    const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.','ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const startStr = `${startDate.getDate()} ${thaiMonths[startDate.getMonth()]} ${startDate.getFullYear()}`;
    const endStr = `${endDate.getDate()} ${thaiMonths[endDate.getMonth()]} ${endDate.getFullYear()}`;

    display.textContent = `${startStr} - ${endStr}`;
    daysCount.textContent = diffDays.toLocaleString('th-TH');

    // Validation: วันที่สิ้นสุดต้องมากกว่าวันที่เริ่มต้น
    if (endDate < startDate) {
        endInput.setCustomValidity('วันที่สิ้นสุดต้องมากกว่าวันที่เริ่มต้น');
        display.textContent = 'ช่วงวันที่ไม่ถูกต้อง';
        display.classList.add('text-danger');
    } else {
        endInput.setCustomValidity('');
        display.classList.remove('text-danger');
    }
}
// ========== MODAL CALCULATOR ==========
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
if (!memberId || !memberType) {
    toast('ข้อมูลสมาชิกไม่ถูกต้อง', false);
    return;
}

const member = membersData.find(m => `${m.type}_${m.id}` === memberKey);
if (!member) {
    toast('ไม่พบข้อมูลสมาชิก', false);
    return;
}

$('#historyMemberId').textContent = member.code;
$('#historyMemberName').textContent = member.member_name;
$('#historyMemberType').textContent = member.type_th;

const historyTable = $('#memberHistoryTable');
historyTable.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...</td></tr>';

new bootstrap.Modal('#modalMemberHistory').show();

try {
    const response = await fetch(
        `../admin/dividend_member_history.php?member_id=${memberId}&member_type=${memberType}`
    );
    
    if (!response.ok) {
        throw new Error('ไม่สามารถดึงข้อมูลได้');
    }
    
    const data = await response.json();

    if (data.ok && data.history.length > 0) {
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
        
        const modalBody = historyTable.closest('.modal-body');
        let summaryDiv = modalBody.querySelector('.history-summary');
        if (!summaryDiv) {
            summaryDiv = document.createElement('div');
            summaryDiv.className = 'history-summary';
            historyTable.parentElement.insertBefore(summaryDiv, historyTable.parentElement.firstChild);
        }
        summaryDiv.innerHTML = summaryHtml;

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
// ========== DIVIDEND ACTIONS ==========
function viewDividendDetails(year) {
    window.location.href = `dividend_detail.php?year=${year}`;
}

async function processPayout(year, csrfToken) {
    if (!confirm(`ยืนยันการจ่ายปันผลปี ${year}?\n\nการดำเนินการนี้ไม่สามารถย้อนกลับได้`)) {
        return;
    }

    try {
        const response = await fetch('/../admin/dividend_payout.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ 
                year: year, 
                csrf_token: csrfToken 
            })
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
const headers = ['รหัส', 'ชื่อ', 'ประเภท', 'หุ้น', 'รวมรับปันผล'];
const rows = [headers];$$('#membersTable tbody tr').forEach(tr => {
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
// Set default values
if ($('#calcShares')) {
$('#calcShares').value = <?= $total_shares ?>;
}
// เพิ่ม Event Listeners สำหรับ Date Range
const startDate = $('#startDate');
const endDate = $('#endDate');

if (startDate) {
    startDate.addEventListener('change', calculateDateRange);
}

if (endDate) {
    endDate.addEventListener('change', calculateDateRange);
}

// คำนวณครั้งแรก
calculateDateRange();
});
</script>
</body>
</html>