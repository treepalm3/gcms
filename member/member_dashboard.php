<?php
// member/member_dashboard.php — ศูนย์สมาชิก (เชื่อม DB จริง)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ====== CSRF ======
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ====== บังคับล็อกอิน + บทบาท ======
try {
  if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php'); exit();
  }
  $current_name = $_SESSION['full_name'] ?: 'สมาชิกสหกรณ์';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'member') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}

// ====== เชื่อมฐานข้อมูล ======
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องได้ $pdo (PDO)

$db_ok = true; $db_err = null;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('ไม่พบตัวแปร $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET NAMES utf8mb4");
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}
} catch (Throwable $e) { $db_ok = false; $db_err = $e->getMessage(); }

// ====== ข้อมูลไซต์ / สถานี ======
$site_name = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
$station_id = 1;

function get_setting(PDO $pdo, string $name, $default = null) {
  try {
    $stmt = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name=:n LIMIT 1");
    $stmt->execute([':n'=>$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['setting_value'] : $default;
  } catch (Throwable $e) { return $default; }
}

if ($db_ok) {
  try {
    // station_id + ชื่อสถานี (comment)
    $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $station_id = (int)$r['setting_value'];
      if (!empty($r['comment'])) $site_name = $r['comment'];
    }
    // ชื่อไซต์จาก app_settings.system_settings
    $sys = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings'")->fetchColumn();
    if ($sys) {
      $sysj = json_decode($sys, true);
      if (!empty($sysj['site_name'])) $site_name = $sysj['site_name'];
      if (!empty($sysj['site_subtitle'])) $site_subtitle = $sysj['site_subtitle'];
    }
  } catch (Throwable $e) {}
}

// ====== ผู้ใช้ปัจจุบัน ======
$current_user_id = (int)$_SESSION['user_id'];
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน', 'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ====== ดึงข้อมูลสมาชิกจาก DB ======
$member = null;
$member_points = 0;
$member_tier = 'Bronze';
$member_phone = '-';
$member_house = '-';
$member_joined = '-';
$member_code = '-';
$member_address = null;
$member_balance = 0.00; // ไม่มีตาราง wallet ในสคีมานี้ — แสดง 0.00 ไปก่อน

$member_id = null; // members.id

if ($db_ok) {
  try {
    $sql = "
      SELECT
        u.id       AS user_id,
        u.full_name,
        u.phone,
        m.id       AS member_id,
        m.member_code,
        m.points,
        m.tier,
        m.house_number,
        m.joined_date,
        m.address
      FROM users u
      JOIN members m ON m.user_id = u.id
      WHERE u.id = :uid AND u.role='member' AND u.is_active=1
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':uid'=>$current_user_id]);
    $member = $st->fetch(PDO::FETCH_ASSOC);

    if ($member) {
      $current_name   = $member['full_name'] ?: $current_name;
      $member_id      = (int)$member['member_id'];
      $member_points  = (int)$member['points'];
      $member_tier    = (string)$member['tier'];
      $member_phone   = $member['phone'] ?: '-';
      $member_house   = $member['house_number'] ?: '-';
      $member_joined  = $member['joined_date'] ? date('Y-m-d', strtotime($member['joined_date'])) : '-';
      $member_code    = $member['member_code'] ?: '-';
      $member_address = $member['address'] ?: null;
    }
  } catch (Throwable $e) { $db_err = $e->getMessage(); }
}

// ฟังก์ชันเป้าหมายแต้มของระดับถัดไป (ตัวอย่างกำหนดเอง)
function next_level_points(string $tier): int {
  // สามารถปรับเกณฑ์ได้ตามจริง
  return match ($tier) {
    'Bronze'   => 1000,
    'Silver'   => 5000,
    'Gold'     => 15000,
    'Platinum' => 30000,
    default    => 1000,
  };
}

$next_lvl = next_level_points($member_tier);
$pct      = $next_lvl > 0 ? min(100, round($member_points / $next_lvl * 100)) : 0;
$remain   = max(0, $next_lvl - $member_points);

// ====== ประวัติคะแนน (ล่าสุด 10 รายการ) ======
$point_history = [];
if ($db_ok && $member_id) {
  try {
    $stH = $pdo->prepare("
      SELECT score_date, score, activity
      FROM scores
      WHERE member_id = :mid
      ORDER BY score_date DESC
      LIMIT 10
    ");
    $stH->execute([':mid'=>$member_id]);
    $point_history = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}
}

// ====== บิลล่าสุดของสมาชิก ======
// เงื่อนไขหลัก: matching โดยเบอร์โทร หรือบ้านเลขที่
$recent_bills = [];
if ($db_ok && $member) {
  try {
    $stB = $pdo->prepare("
      SELECT
        s.sale_code,
        s.sale_date,
        s.payment_method,
        s.total_amount,
        s.net_amount,
        s.discount_amount,
        s.discount_pct,
        GROUP_CONCAT(DISTINCT si.fuel_type ORDER BY si.id SEPARATOR ', ') AS fuels,
        SUM(si.liters) AS liters
      FROM sales s
      LEFT JOIN sales_items si ON si.sale_id = s.id
      WHERE s.station_id = :st
        AND (
              (s.customer_phone IS NOT NULL AND s.customer_phone <> '' AND s.customer_phone = :phone)
           OR (s.household_no  IS NOT NULL AND s.household_no  <> '' AND s.household_no  = :house)
        )
      GROUP BY s.id, s.sale_code, s.sale_date, s.payment_method, s.total_amount, s.net_amount, s.discount_amount, s.discount_pct
      ORDER BY s.sale_date DESC
      LIMIT 10
    ");
    $stB->execute([
      ':st'    => $station_id,
      ':phone' => (string)$member_phone,
      ':house' => (string)$member_house,
    ]);
    $recent_bills = $stB->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ถ้าไม่เจอ (บางระบบยังไม่บันทึก phone/house ลง sales) — ใช้สำรองจาก scores.activity ที่มี sale_code
    if (empty($recent_bills) && $member_id) {
      $stB2 = $pdo->prepare("
        SELECT
          s.sale_code,
          s.sale_date,
          s.payment_method,
          s.total_amount,
          s.net_amount,
          s.discount_amount,
          s.discount_pct,
          GROUP_CONCAT(DISTINCT si.fuel_type ORDER BY si.id SEPARATOR ', ') AS fuels,
          SUM(si.liters) AS liters
        FROM scores sc
        JOIN sales s ON sc.activity LIKE CONCAT('%', s.sale_code)
        LEFT JOIN sales_items si ON si.sale_id = s.id
        WHERE sc.member_id = :mid AND s.station_id = :st
        GROUP BY s.id, s.sale_code, s.sale_date, s.payment_method, s.total_amount, s.net_amount, s.discount_amount, s.discount_pct
        ORDER BY s.sale_date DESC
        LIMIT 10
      ");
      $stB2->execute([':mid'=>$member_id, ':st'=>$station_id]);
      $recent_bills = $stB2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $e) {}
}

// ====== ปันผลของสมาชิก ======
$div_summary = [
  'last_year'  => null,
  'last_amount'=> 0.00,
  'accumulate' => 0.00,
  'rate'       => null,
];
if ($db_ok && $member_id) {
  try {
    // สะสมทั้งหมด (จ่ายแล้ว)
    $stSum = $pdo->prepare("
      SELECT SUM(dp.dividend_amount) AS total_paid
      FROM dividend_payments dp
      WHERE dp.member_id = :mid AND dp.payment_status = 'paid'
    ");
    $stSum->execute([':mid'=>$member_id]);
    $div_summary['accumulate'] = (float)($stSum->fetchColumn() ?: 0);

    // รายการล่าสุด + อัตราจากรอบ
    $stLast = $pdo->prepare("
      SELECT dp.dividend_amount, d.period_name, d.year, d.dividend_rate
      FROM dividend_payments dp
      JOIN dividend_periods d ON d.id = dp.period_id
      WHERE dp.member_id = :mid AND dp.payment_status = 'paid'
      ORDER BY d.payment_date DESC, dp.id DESC
      LIMIT 1
    ");
    $stLast->execute([':mid'=>$member_id]);
    if ($row = $stLast->fetch(PDO::FETCH_ASSOC)) {
      $div_summary['last_year']  = (int)$row['year'];
      $div_summary['last_amount']= (float)$row['dividend_amount'];
      $div_summary['rate']       = (float)$row['dividend_rate'];
    }
  } catch (Throwable $e) {}
}

// ====== รางวัล (ตัวอย่างนิยามในหน้า) ======
$rewards = [
  ['title'=>'ส่วนลด 50 บาท','need'=>3000,'desc'=>'แลกส่วนลดเติมน้ำมัน 50 บาท (ใช้ได้กับทุกชนิด)'],
  ['title'=>'ล้างรถฟรี 1 ครั้ง','need'=>5000,'desc'=>'ใช้บริการล้างรถมาตรฐานที่สถานีบริการ'],
  ['title'=>'คูปองน้ำดื่ม 6 ขวด','need'=>1500,'desc'=>'รับคูปองแลกน้ำดื่มแพ็คเล็ก 6 ขวด'],
];
$redeemable = 0; foreach($rewards as $rw){ if($member_points >= (int)$rw['need']) $redeemable++; }

// ====== ช่วยจัดรูปแบบแสดงบิล ======
$pay_th = ['cash'=>'เงินสด','qr'=>'QR Code','transfer'=>'โอนเงิน','card'=>'บัตรเครดิต'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>แดชบอร์ดสมาชิก | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    .panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
    .panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
    .panel-head h5{margin:0;color:var(--steel);font-weight:700}
    .member-card{border-radius:16px;padding:1rem;color:#1b1b1b;background:linear-gradient(135deg,var(--gold),var(--amber));box-shadow:0 12px 30px rgba(182,109,13,.24);position:relative;overflow:hidden}
    .member-card .badge-tier{background:rgba(255,255,255,.25);backdrop-filter:blur(2px);border:1px solid rgba(255,255,255,.4);border-radius:999px;padding:.2rem .6rem;font-weight:800}
    .member-card .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .small-muted{color:var(--steel);font-size:.92rem}
  </style>
</head>
<body>

  <!-- App Bar -->
  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="#"><?= htmlspecialchars($site_name) ?></a>
      </div>
      <div class="d-flex align-items-center gap-3 ms-auto">
        <div class="nav-identity text-end d-none d-sm-block">
          <div class="nav-name"><?= htmlspecialchars($current_name) ?></div>
          <div class="nav-sub"><?= htmlspecialchars($current_role_th) ?></div>
        </div>
        <a href="profile.php" class="avatar-circle text-decoration-none"><?= htmlspecialchars(mb_substr($current_name,0,1,'UTF-8')) ?></a>
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
      <div class="side-brand mb-2"><h3><span>Member</span></h3></div>
      <nav class="sidebar-menu">
        <a class="active" href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
        <a href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
        <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Member</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a class="active" href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
          <a href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
          <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>

      <!-- Content -->
      <main class="col-lg-10 p-4">
        <?php if (!$db_ok): ?>
          <div class="alert alert-danger">เชื่อมต่อฐานข้อมูลไม่สำเร็จ: <?= htmlspecialchars($db_err) ?></div>
        <?php elseif (!$member): ?>
          <div class="alert alert-warning">ไม่พบข้อมูลสมาชิกของคุณในระบบ</div>
        <?php endif; ?>

        <div class="row g-4">
          <!-- Member Card -->
          <div class="col-12">
            <div class="member-card" id="printCard">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="badge-tier mb-2"><i class="fa-solid fa-crown me-1"></i><?= htmlspecialchars($member_tier) ?> MEMBER</div>
                  <h4 class="mb-1 fw-800"><?= htmlspecialchars($current_name) ?></h4>
                  <div class="mono small">ID: <?= htmlspecialchars($member_code) ?></div>
                </div>
              </div>
              <hr class="my-3" style="border-top:1px dashed rgba(0,0,0,.25)">
              <div class="row g-2 small">
                <div class="col-sm-4">เบอร์: <b><?= htmlspecialchars($member_phone) ?></b></div>
                <div class="col-sm-4">หุ้น/ครัวเรือน: <b><?= htmlspecialchars($member_house) ?></b></div>
                <div class="col-sm-4">สมัครเมื่อ: <b><?= htmlspecialchars($member_joined) ?></b></div>
              </div>
              <?php if (!empty($member_address)): ?>
                <div class="small mt-2">ที่อยู่: <?= htmlspecialchars($member_address) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="row g-4 mt-4">
          <!-- Points -->
          <div class="col-12 col-xl-6" id="points">
            <div class="panel h-100">
              <div class="panel-head">
                <h5 class="mb-0"><i class="fa-solid fa-star me-2"></i>คะแนนสะสม</h5>
                <a href="points.php" class="btn btn-outline-primary btn-sm">ดูรายละเอียด</a>
              </div>

              <div class="d-flex justify-content-between align-items-baseline mb-1">
                <h4 class="mb-0 text-primary"><?= number_format($member_points) ?> <span class="small fw-normal">แต้ม</span></h4>
                <div class="small-muted">เป้าหมาย: <?= number_format($next_lvl) ?></div>
              </div>
              <div class="small-muted mb-2">
                ขาดอีก <b><?= number_format($remain) ?></b> แต้มเพื่อเลื่อนขั้น
              </div>
              <div class="progress mb-2">
                <div class="progress-bar" role="progressbar" style="width: <?= (int)$pct ?>%" aria-valuenow="<?= (int)$pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
              </div>

              <div class="row text-center g-3 mt-2">
                <div class="col-4">
                  <?php
                    // แต้มเดือนนี้ (คำนวณจาก scores เดือนปัจจุบัน)
                    $month_points = 0;
                    if ($db_ok && $member_id) {
                      try {
                        $smp = $pdo->prepare("
                          SELECT COALESCE(SUM(score),0) FROM scores
                          WHERE member_id=:mid AND DATE_FORMAT(score_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
                        ");
                        $smp->execute([':mid'=>$member_id]);
                        $month_points = (int)$smp->fetchColumn();
                      } catch (Throwable $e) {}
                    }
                  ?>
                  <h5 class="mb-0 text-primary"><?= number_format($month_points) ?></h5>
                  <div class="small-muted">แต้มเดือนนี้</div>
                </div>
                <div class="col-4">
                  <?php
                    $pct_goal = $next_lvl>0 ? round(min(100, $member_points/$next_lvl*100)) : 0;
                  ?>
                  <h5 class="mb-0 text-success"><?= $pct_goal ?>%</h5>
                  <div class="small-muted">สำเร็จเป้า</div>
                </div>
                <div class="col-4">
                  <h5 class="mb-0 text-warning"><?= (int)$redeemable ?></h5>
                  <div class="small-muted">สิทธิ์พร้อมแลก</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Dividend -->
          <div class="col-12 col-xl-6">
            <div class="panel h-100">
              <div class="panel-head">
                <h5 class="mb-0"><i class="fa-solid fa-gift me-2"></i>ปันผล</h5>
                <a href="dividend.php" class="btn btn-outline-primary btn-sm">ดูประวัติทั้งหมด</a>
              </div>

              <div class="small-muted">ปันผลปีล่าสุด<?= $div_summary['last_year'] ? ' ('.(int)$div_summary['last_year'].')' : '' ?></div>
              <h4 class="text-primary mb-2">฿<?= number_format((float)$div_summary['last_amount'], 2) ?></h4>
              <div class="small-muted">
                อัตรา: <b><?= $div_summary['rate'] !== null ? number_format((float)$div_summary['rate'], 2).'%' : '-' ?></b> |
                สะสมทั้งหมด: <b>฿<?= number_format((float)$div_summary['accumulate'], 2) ?></b>
              </div>
              <hr>
              <div class="small-muted">ยอดเงินคงเหลือในระบบ (สำหรับเติมน้ำมัน)</div>
              <h4 class="text-success mb-0">฿<?= number_format((float)$member_balance, 2) ?></h4>
            </div>
          </div>
        </div>

        <!-- Recent activity -->
        <div class="row g-4 mt-4" id="bills">
          <div class="col-12">
            <div class="panel">
              <div class="panel-head">
                <h5 class="mb-0"><i class="fa-solid fa-receipt me-2"></i>กิจกรรมล่าสุด</h5>
                <div class="d-flex gap-2">
                  <div class="input-group" style="max-width:260px">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="search" id="billSearch" class="form-control" placeholder="ค้นหา: กิจกรรม">
                  </div>
                  <a href="bills.php" class="btn btn-outline-secondary btn-sm">ดูทั้งหมด</a>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tblBills">
                  <thead>
                    <tr>
                      <th scope="col">วันที่</th>
                      <th scope="col">เวลา</th>
                      <th scope="col">กิจกรรม</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($recent_bills)): ?>
                      <tr><td colspan="3" class="text-center text-muted">ยังไม่มีกิจกรรมล่าสุด</td></tr>
                    <?php else:
                      foreach($recent_bills as $r):
                        $dt   = new DateTime($r['sale_date']);
                        $date = $dt->format('Y-m-d');
                        $time = $dt->format('H:i');
                        $payL = $pay_th[$r['payment_method']] ?? $r['payment_method'];
                        $activity = sprintf(
                          'เติม %s %s ลิตร / ฿%s (%s) — บิล %s',
                          $r['fuels'] ?: '-',
                          number_format((float)$r['liters'],2),
                          number_format((float)$r['net_amount'] ?: (float)$r['total_amount'],2),
                          $payL,
                          $r['sale_code']
                        );
                    ?>
                      <tr>
                        <td><?= htmlspecialchars($date) ?></td>
                        <td><?= htmlspecialchars($time) ?></td>
                        <td><?= htmlspecialchars($activity) ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>

            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — ศูนย์สมาชิก</footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ค้นหาในกิจกรรม
    const q = document.getElementById('billSearch');
    q?.addEventListener('input', () => {
      const k = (q.value || '').toLowerCase().trim();
      document.querySelectorAll('#tblBills tbody tr').forEach(tr=>{
        tr.style.display = !k || tr.textContent.toLowerCase().includes(k) ? '' : 'none';
      });
    });
  </script>
</body>
</html>
