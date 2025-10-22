<?php
// committee/dividend.php — หน้าปันผลสำหรับกรรมการ (อ่านข้อมูล + รายงาน/ส่งออก/คำนวณ)
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
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

// ===== Fetch data (เหมือน manager/admin) =====
$dividend_periods   = [];
$members_dividends  = []; // keyed by member_id
$error_message      = null;

try {
  // 1) งวดปันผล
  $dividend_periods = $pdo->query("
    SELECT id, period_code, `year`, period_name, total_profit, dividend_rate,
           total_shares_at_time, total_dividend_amount, status, payment_date, created_at, approved_by
    FROM dividend_periods
    ORDER BY `year` DESC, id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);

  // 2) สมาชิก + หุ้น
  $members_stmt = $pdo->query("
    SELECT m.id AS member_id, m.member_code, u.full_name, m.shares
    FROM members m
    JOIN users u ON m.user_id = u.id
    ORDER BY m.id
  ");
  foreach ($members_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $mid = (int)$row['member_id'];
    $members_dividends[$mid] = [
      'id'             => $mid,
      'code'           => $row['member_code'],
      'member_name'    => $row['full_name'],
      'shares'         => (int)$row['shares'],
      'payments'       => [], // period_id => amount
      'total_received' => 0.0,
    ];
  }

  // 3) จ่ายปันผลรายสมาชิก/งวด
  $payments_stmt = $pdo->query("
    SELECT dp.member_id, dp.period_id, dp.dividend_amount, dp.payment_status
    FROM dividend_payments dp
  ");
  foreach ($payments_stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
    $mid = (int)$payment['member_id'];
    if (!isset($members_dividends[$mid])) continue;
    $pid = (int)$payment['period_id'];
    $amt = (float)$payment['dividend_amount'];
    $members_dividends[$mid]['payments'][$pid] = $amt;
    if (($payment['payment_status'] ?? 'pending') === 'paid') {
      $members_dividends[$mid]['total_received'] += $amt;
    }
  }
} catch (Throwable $e) {
  $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
}

// ===== Site settings (app_settings->system_settings) =====
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $sys = json_decode($r['json_value'], true) ?: [];
    if (!empty($sys['site_name'])) $site_name = $sys['site_name'];
  }
} catch (Throwable $e) {}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',  'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ===== Stats =====
$total_dividend_paid = 0;
$pending_dividend    = 0;
$total_members       = 0;
$total_shares        = 0;
try {
  $stats = $pdo->query("
    SELECT 
      (SELECT COALESCE(SUM(total_dividend_amount), 0) FROM dividend_periods WHERE status = 'paid')      AS total_paid,
      (SELECT COALESCE(SUM(total_dividend_amount), 0) FROM dividend_periods WHERE status = 'approved')  AS total_pending,
      (SELECT COUNT(*) FROM members)                                                                    AS total_members,
      (SELECT COALESCE(SUM(shares), 0) FROM members)                                                    AS total_shares
  ")->fetch(PDO::FETCH_ASSOC);
  $total_dividend_paid = (float)($stats['total_paid'] ?? 0);
  $pending_dividend    = (float)($stats['total_pending'] ?? 0);
  $total_members       = (int)($stats['total_members'] ?? 0);
  $total_shares        = (int)($stats['total_shares'] ?? 0);
} catch (Throwable $e) { /* keep defaults */ }

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
    .status-paid { background:#d1edff; color:#0969da; padding:4px 8px; border-radius:12px; font-size:.8rem; font-weight:500; }
    .status-approved { background:#fff3cd; color:#664d03; padding:4px 8px; border-radius:12px; font-size:.8rem; font-weight:500; }
    .status-pending { background:#f8d7da; color:#721c24; padding:4px 8px; border-radius:12px; font-size:.8rem; font-weight:500; }
    .dividend-card{ border:1px solid #e9ecef; border-radius:12px; transition:.25s; background:#fff; }
    .dividend-card:hover{ box-shadow:0 8px 20px rgba(0,0,0,.06); transform: translateY(-1px); }
    .dividend-amount{ font-size:1.2rem; font-weight:700; color:#198754; }
    .dividend-rate{ font-size:1rem; font-weight:600; color:#0d6efd; }
    .member-row:hover{ background-color: rgba(13,110,253,.05); }
    .panel{ background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:20px }
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

<!-- Offcanvas Sidebar -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
    <nav class="sidebar-menu">
      <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
      <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
      <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
    </nav>
    <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
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

      <!-- Summary cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <h5><i class="fa-solid fa-gift"></i> ปันผลจ่ายแล้ว</h5>
          <h3 class="text-success">฿<?= number_format($total_dividend_paid, 2) ?></h3>
          <p class="mb-0 text-muted">รวมทุกงวดที่จ่ายแล้ว</p>
        </div>
        <div class="stat-card">
          <h5><i class="bi bi-people-fill"></i> สมาชิกทั้งหมด</h5>
          <h3 class="text-info"><?= number_format($total_members) ?> คน</h3>
          <p class="mb-0 text-muted">หุ้นรวม <?= number_format($total_shares) ?> หุ้น</p>
        </div>
        <div class="stat-card">
          <h5><i class="bi bi-clock-history"></i> ปันผลค้างจ่าย</h5>
          <h3 class="text-warning">฿<?= number_format($pending_dividend, 2) ?></h3>
          <p class="mb-0 text-muted">งวดที่อนุมัติแล้วแต่ยังไม่จ่าย</p>
        </div>
      </div>

      <!-- Tabs -->
      <ul class="nav nav-tabs mb-3" id="dividendTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#periods-panel" type="button" role="tab">
            <i class="fa-solid fa-calendar-days me-2"></i>งวดปันผล
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#members-panel" type="button" role="tab">
            <i class="bi bi-people-fill me-2"></i>สมาชิกและหุ้น
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#calculator-panel" type="button" role="tab">
            <i class="bi bi-calculator me-2"></i>คำนวณปันผล
          </button>
        </li>
      </ul>

      <div class="tab-content" id="dividendTabContent">
        <!-- Panel: งวดปันผล -->
        <div class="tab-pane fade show active" id="periods-panel" role="tabpanel">
          <!-- Quick Actions (เฉพาะดูรายงาน/ส่งออกสำหรับกรรมการ) -->
          <div class="row mb-4">
            <div class="col-12">
              <div class="panel">
                <h6 class="mb-3"><i class="bi bi-lightning-fill me-1"></i> การดำเนินการ</h6>
                <div class="row g-3">
                  <div class="col-md-4">
                    <button class="btn btn-info w-100" onclick="generateReport()">
                      <i class="bi bi-file-earmark-text me-1"></i> รายงานปันผล
                    </button>
                  </div>
                  <div class="col-md-4">
                    <button class="btn btn-warning w-100" onclick="exportDividends()">
                      <i class="bi bi-download me-1"></i> ส่งออกข้อมูล
                    </button>
                  </div>
                  <div class="col-md-4">
                    <button class="btn btn-outline-secondary w-100" disabled title="สำหรับผู้บริหาร/แอดมิน">
                      <i class="fa-solid fa-money-check-dollar me-1"></i> จ่ายปันผล (จำกัดสิทธิ์)
                    </button>
                  </div>
                </div>
                <div class="small text-muted mt-2">* การสร้างงวด/จ่ายปันผลทำได้โดยผู้บริหารหรือผู้ดูแลระบบ</div>
              </div>
            </div>
          </div>

          <!-- Cards: งวดปันผล -->
          <div class="row g-4">
            <?php foreach($dividend_periods as $period): ?>
            <div class="col-md-6 col-lg-4">
              <div class="dividend-card p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                  <div>
                    <h6 class="card-title mb-1"><?= htmlspecialchars($period['period_code']) ?></h6>
                    <small class="text-muted"><?= htmlspecialchars($period['year']) ?> - <?= htmlspecialchars($period['period_name']) ?></small>
                  </div>
                  <span class="status-<?= htmlspecialchars($period['status']) ?>">
                    <?= ['paid'=>'จ่ายแล้ว','approved'=>'อนุมัติแล้ว','pending'=>'รออนุมัติ'][$period['status']] ?? 'ไม่ทราบสถานะ' ?>
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

                <div class="d-flex flex-column gap-1 text-sm">
                  <div class="d-flex justify-content-between">
                    <span class="text-muted">กำไร:</span>
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
                  <div class="btn-group w-100">
                    <button class="btn btn-sm btn-outline-primary" onclick="viewDividendDetails('<?= htmlspecialchars($period['period_code']) ?>')">
                      <i class="bi bi-eye me-1"></i> รายละเอียด
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" disabled title="สำหรับผู้บริหาร/แอดมิน">
                      <i class="fa-solid fa-money-check-dollar me-1"></i> จ่าย (จำกัดสิทธิ์)
                    </button>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Panel: สมาชิก -->
        <div class="tab-pane fade" id="members-panel" role="tabpanel">
          <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <div class="d-flex flex-wrap gap-2">
              <div class="input-group" style="max-width:280px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" id="memberSearch" class="form-control" placeholder="ค้นหาสมาชิก">
              </div>
              <div class="input-group" style="max-width:200px;">
                <span class="input-group-text"><i class="bi bi-filter"></i></span>
                <input type="number" id="minShares" class="form-control" placeholder="หุ้นขั้นต่ำ" min="0">
              </div>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-primary" onclick="exportMembers()"><i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV</button>
              <button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> พิมพ์</button>
            </div>
          </div>

          <div class="panel">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0" id="membersTable">
                <thead>
                  <tr>
                    <th>รหัสสมาชิก</th>
                    <th>ชื่อสมาชิก</th>
                    <th class="text-center">จำนวนหุ้น</th>
                    <?php foreach ($dividend_periods as $period): ?>
                      <th class="text-end d-none d-md-table-cell"><?= htmlspecialchars($period['period_code']) ?></th>
                    <?php endforeach; ?>
                    <th class="text-end">รวมที่ได้รับ</th>
                    <th class="text-end">จัดการ</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach($members_dividends as $memberId => $member): ?>
                  <tr class="member-row"
                      data-member-id="<?= (int)$memberId ?>"
                      data-member-name="<?= htmlspecialchars($member['member_name']) ?>"
                      data-shares="<?= (int)$member['shares'] ?>">
                    <td><b><?= htmlspecialchars($member['code']) ?></b></td>
                    <td><?= htmlspecialchars($member['member_name']) ?></td>
                    <td class="text-center">
                      <span class="badge bg-warning-subtle"><?= number_format((int)$member['shares']) ?> หุ้น</span>
                    </td>
                    <?php foreach ($dividend_periods as $period): ?>
                      <td class="text-end d-none d-md-table-cell">
                        ฿<?= number_format($member['payments'][$period['id']] ?? 0, 2) ?>
                      </td>
                    <?php endforeach; ?>
                    <td class="text-end"><strong class="text-success">฿<?= number_format($member['total_received'], 2) ?></strong></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-info" onclick="viewMemberHistory(<?= (int)$memberId ?>)">
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

        <!-- Panel: เครื่องคำนวณ -->
        <div class="tab-pane fade" id="calculator-panel" role="tabpanel">
          <div class="panel">
            <h6 class="mb-4"><i class="bi bi-calculator me-2"></i>เครื่องคำนวณปันผล</h6>
            <div class="row g-4">
              <div class="col-md-6">
                <div class="border rounded p-4">
                  <h6 class="mb-3">ข้อมูลพื้นฐาน</h6>
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label">กำไรสุทธิ (บาท)</label>
                      <input type="number" id="calcProfit" class="form-control" placeholder="เช่น 1000000" step="0.01" oninput="calculateDividend()">
                    </div>
                    <div class="col-12">
                      <label class="form-label">จำนวนหุ้นรวม</label>
                      <input type="number" id="calcShares" class="form-control" placeholder="เช่น 2500" step="1" value="<?= $total_shares ?>" oninput="calculateDividend()">
                    </div>
                    <div class="col-12">
                      <label class="form-label">อัตราปันผล (%)</label>
                      <input type="number" id="calcRate" class="form-control" placeholder="เช่น 15" step="0.1" oninput="calculateDividend()">
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="border rounded p-4">
                  <h6 class="mb-3">ผลการคำนวณ</h6>
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label">ยอดปันผลรวม</label>
                      <div class="form-control-static"><h4 class="text-success mb-0" id="totalDividend">฿0.00</h4></div>
                    </div>
                    <div class="col-12">
                      <label class="form-label">ปันผลต่อหุ้น</label>
                      <div class="form-control-static"><h5 class="text-primary mb-0" id="dividendPerShare">฿0.00</h5></div>
                    </div>
                    <div class="col-12">
                      <label class="form-label">เปอร์เซ็นต์ของกำไร</label>
                      <div class="form-control-static"><span class="badge bg-info" id="profitPercentage">0%</span></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-4">
              <h6 class="mb-3">ตัวอย่างการจ่ายตามสมาชิก (5 อันดับแรก)</h6>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead>
                    <tr><th>สมาชิก</th><th class="text-center">หุ้น</th><th class="text-end">ปันผลที่จะได้รับ</th></tr>
                  </thead>
                  <tbody id="dividendPreview">
                    <tr><td colspan="3" class="text-center text-muted">กรอกข้อมูลเพื่อดูตัวอย่าง</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

          </div>
        </div>
      </div><!-- /.tab-content -->

    </main>
  </div>
</div>

<footer class="footer">© <?= date('Y') ?> สหกรณ์ปั๊มน้ำมัน — ปันผล (กรรมการ)</footer>

<!-- Modals -->
<div class="modal fade" id="modalMemberHistory" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>ประวัติการรับปันผล</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row mb-3">
          <div class="col-sm-6"><strong>รหัสสมาชิก:</strong> <span id="historyMemberId">-</span></div>
          <div class="col-sm-6"><strong>ชื่อสมาชิก:</strong> <span id="historyMemberName">-</span></div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>งวด</th>
                <th class="text-center">หุ้น</th>
                <th class="text-end">อัตรา (%)</th>
                <th class="text-end">ปันผลที่ได้รับ</th>
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

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $ = (s, p=document)=>p.querySelector(s);
const $$ = (s, p=document)=>[...p.querySelectorAll(s)];
const membersData = Object.values(<?= json_encode($members_dividends, JSON_UNESCAPED_UNICODE) ?>);

const toast = (msg, success=true)=>{
  const t = $('#liveToast');
  t.className = `toast align-items-center border-0 ${success ? 'text-bg-success' : 'text-bg-danger'}`;
  t.querySelector('.toast-body').textContent = msg || 'ดำเนินการสำเร็จ';
  bootstrap.Toast.getOrCreateInstance(t, { delay: 2000 }).show();
};

// ฟิลเตอร์สมาชิก
const memberSearch = $('#memberSearch');
const minShares = $('#minShares');
function normalize(s){ return (s||'').toString().toLowerCase().trim(); }
function applyMemberFilter(){
  const k = normalize(memberSearch?.value);
  const minS = parseInt(minShares?.value || '0', 10);
  $$('#membersTable tbody tr').forEach(tr=>{
    const searchText = normalize(`${tr.dataset.memberId} ${tr.dataset.memberName}`);
    const shares = parseInt(tr.dataset.shares || '0', 10);
    const okK = !k || searchText.includes(k);
    const okS = isNaN(minS) ? true : shares >= minS;
    tr.style.display = (okK && okS) ? '' : 'none';
  });
}
memberSearch?.addEventListener('input', applyMemberFilter);
minShares?.addEventListener('input', applyMemberFilter);

// เครื่องคำนวณปันผล
function calculateDividend() {
  const profit = parseFloat($('#calcProfit')?.value || '0');
  const shares = parseFloat($('#calcShares')?.value || '0');
  const rate   = parseFloat($('#calcRate')?.value || '0');
  const totalDividend    = profit * (rate/100);
  const dividendPerShare = shares > 0 ? totalDividend / shares : 0;
  const profitPercentage = profit > 0 ? (totalDividend / profit) * 100 : 0;

  $('#totalDividend').textContent    = '฿' + totalDividend.toLocaleString('th-TH', {minimumFractionDigits: 2});
  $('#dividendPerShare').textContent = '฿' + dividendPerShare.toLocaleString('th-TH', {minimumFractionDigits: 2});
  $('#profitPercentage').textContent = profitPercentage.toFixed(1) + '%';
  updateDividendPreview(dividendPerShare);
}
function updateDividendPreview(dividendPerShare) {
  const preview = $('#dividendPreview');
  if (dividendPerShare <= 0) {
    preview.innerHTML = '<tr><td colspan="3" class="text-center text-muted">กรอกข้อมูลเพื่อดูตัวอย่าง</td></tr>';
    return;
  }
  let html = '';
  membersData.slice(0, 5).forEach(member => {
    const amount = (member.shares||0) * dividendPerShare;
    html += `
      <tr>
        <td>${member.member_name}</td>
        <td class="text-center">${(member.shares||0).toLocaleString('th-TH')}</td>
        <td class="text-end">฿${amount.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
      </tr>`;
  });
  preview.innerHTML = html;
}

// ประวัติสมาชิก
async function viewMemberHistory(memberId) {
  const memberRow = $(`tr[data-member-id='${memberId}']`);
  if (!memberRow) return;

  $('#historyMemberId').textContent = memberRow.dataset.memberId;
  $('#historyMemberName').textContent = memberRow.dataset.memberName;
  const historyTable = $('#memberHistoryTable');
  historyTable.innerHTML = '<tr><td colspan="5" class="text-center">กำลังโหลด...</td></tr>';
  new bootstrap.Modal('#modalMemberHistory').show();

  try {
    const response = await fetch(`dividend_member_history.php?member_id=${memberId}`);
    const data = await response.json();

    if (data.ok && data.history.length > 0) {
      let html = '';
      data.history.forEach(item => {
        html += `
          <tr>
            <td>${item.period_code} (${item.year} ${item.period_name})</td>
            <td class="text-center">${parseInt(item.shares_at_time).toLocaleString('th-TH')}</td>
            <td class="text-end">${parseFloat(item.dividend_rate).toFixed(1)}%</td>
            <td class="text-end">฿${parseFloat(item.dividend_amount).toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
            <td class="text-center"><span class="status-${item.payment_status}">${item.payment_status === 'paid' ? 'จ่ายแล้ว' : 'ค้างจ่าย'}</span></td>
          </tr>`;
      });
      historyTable.innerHTML = html;
    } else {
      historyTable.innerHTML = '<tr><td colspan="5" class="text-center text-muted">ไม่พบประวัติการรับปันผล</td></tr>';
    }
  } catch (error) {
    historyTable.innerHTML = '<tr><td colspan="5" class="text-center text-danger">เกิดข้อผิดพลาดในการโหลดข้อมูล</td></tr>';
  }
}

// รายงาน/ส่งออก
function generateReport() { toast('กำลังสร้างรายงานปันผล...'); }
function exportDividends() { toast('กำลังส่งออกข้อมูลปันผล...'); }

function exportMembers() {
  const rows = [['MemberCode','MemberName','Shares',<?php
    $codes = array_map(fn($p)=>$p['period_code'], $dividend_periods);
    echo implode(',', array_map(fn($c)=>"'$c'", $codes));
  ?>,'TotalReceived']];
  $$('#membersTable tbody tr').forEach(tr=>{
    if(tr.style.display==='none') return;
    const tds = tr.querySelectorAll('td');
    if(tds.length >= 5){
      const arr = [];
      arr.push(tds[0].textContent.trim());               // MemberCode
      arr.push(tds[1].textContent.trim());               // MemberName
      arr.push(tr.dataset.shares||'0');                  // Shares (raw)
      const periodCols = <?= count($dividend_periods) ?>;
      for (let i=0;i<periodCols;i++){
        const cell = tds[3+i]?.textContent || '';
        arr.push(cell.replace(/[฿,\s]/g,''));
      }
      const totalCell = tds[3+periodCols]?.textContent || '0';
      arr.push(totalCell.replace(/[฿,\s]/g,''));
      rows.push(arr);
    }
  });
  const csv = rows.map(r=>r.map(v=>`"${String(v??'').replaceAll('"','""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'dividend_members.csv'; a.click(); URL.revokeObjectURL(a.href);
}
</script>
</body>
</html>
