<?php
// member/dividend.php — ปันผล + เฉลี่ยคืน (เชื่อม DB จริง)
session_start();
date_default_timezone_set('Asia/Bangkok');

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ====== ตรวจสิทธิ์ ====== */
try {
  if (!isset($_SESSION['user_id'])) { header('Location: /index/login.php'); exit(); }
  $current_name = $_SESSION['full_name'] ?: 'สมาชิกสหกรณ์';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'member') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
} catch(Throwable $e){
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}

/* ====== เชื่อมต่อฐานข้อมูล ====== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // expect $pdo

$db_ok = true; $db_err = null;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('ไม่พบตัวแปร $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET NAMES utf8mb4");
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}
} catch (Throwable $e) { $db_ok = false; $db_err = $e->getMessage(); }

/* ====== ค่าพื้นฐานไซต์/สถานี ====== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
$station_id = 1;

if ($db_ok) {
  try {
    $rowSt = $pdo->query("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($rowSt) {
      $station_id = (int)$rowSt['setting_value'];
      if (!empty($rowSt['comment'])) $site_name = $rowSt['comment'];
    }
    $sys = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings'")->fetchColumn();
    if ($sys) {
      $sysj = json_decode($sys, true);
      if (!empty($sysj['site_name'])) $site_name = $sysj['site_name'];
      if (!empty($sysj['site_subtitle'])) $site_subtitle = $sysj['site_subtitle'];
    }
  } catch (Throwable $e) { /* ignore */ }
}

/* ====== ผู้ใช้ปัจจุบัน ====== */
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

/* ====== หา member_id จาก users.id ====== */
$member_id = 0;
if ($db_ok) {
  try {
    // [แก้ไข] ขยายเงื่อนไข WHERE ให้รวม 'manager' และ 'committee'
    $st = $pdo->prepare("
      SELECT m.id AS member_id, u.full_name
      FROM users u
      JOIN members m ON m.user_id = u.id
      WHERE u.id = :uid 
        AND u.role IN ('member', 'manager', 'committee', 'admin') -- อนุญาตทุกบทบาทที่เป็นสมาชิก
        AND u.is_active=1 AND m.station_id = :st
      LIMIT 1
    ");
    $st->execute([':uid'=>$current_user_id, ':st'=>$station_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $member_id = (int)$row['member_id'];
      // [แก้ไข] ใช้ full_name จาก users โดยตรงเพื่อความถูกต้อง
      if (!empty($row['full_name'])) $current_name = $row['full_name'];
    }
  } catch (Throwable $e) { $db_err = $e->getMessage(); }
}

/* ====== สรุปปันผล & ประวัติ ====== */
$div_summary = [
  'last_year'   => null,   // ปี ค.ศ.
  'last_amount' => 0.00,
  'accumulate'  => 0.00,
  'rate'        => null,
];
$dividend_history = []; // [{year, amount, paid_date, note, status}]

if ($db_ok && $member_id > 0) {
  try {
    // รวมยอดปันผลที่ "จ่ายแล้ว"
    $acc = $pdo->prepare("SELECT COALESCE(SUM(dividend_amount),0) FROM dividend_payments WHERE member_id=:mid AND payment_status='paid'");
    $acc->execute([':mid'=>$member_id]);
    $div_summary['accumulate'] = (float)$acc->fetchColumn();

    // ปีล่าสุดที่มีการจ่าย (จากตาราง period + payments)
    $stLast = $pdo->prepare("
      SELECT dper.year,
             COALESCE(SUM(dp.dividend_amount),0) AS amt,
             MAX(COALESCE(dp.paid_at, dper.payment_date)) AS last_paid_at,
             MAX(dper.dividend_rate) AS rate
      FROM dividend_payments dp
      LEFT JOIN dividend_periods dper ON dper.id = dp.period_id
      WHERE dp.member_id = :mid AND dp.payment_status='paid'
      GROUP BY dper.year
      ORDER BY dper.year DESC
      LIMIT 1
    ");
    $stLast->execute([':mid'=>$member_id]);
    if ($last = $stLast->fetch(PDO::FETCH_ASSOC)) {
      $div_summary['last_year']   = (int)$last['year'];
      $div_summary['last_amount'] = (float)$last['amt'];
      $div_summary['rate']        = is_null($last['rate']) ? null : (float)$last['rate'];
    }

    // [แก้ไข] ประวัติทุกรายการของสมาชิก (รวมทุกสถานะ)
    $stHis = $pdo->prepare("
      SELECT dper.year,
             dp.dividend_amount,
             COALESCE(dp.paid_at, dper.payment_date) AS paid_date,
             dp.payment_status AS status, -- ดึงสถานะมาด้วย
             dper.period_name, dper.status AS period_status
      FROM dividend_payments dp
      LEFT JOIN dividend_periods dper ON dper.id = dp.period_id
      WHERE dp.member_id = :mid 
      ORDER BY (paid_date IS NULL), paid_date DESC, dper.year DESC
    ");
    $stHis->execute([':mid'=>$member_id]);
    $rows = $stHis->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
      $note = !empty($r['period_name'])
                ? $r['period_name']
                : ('ปันผลประจำปี ' . (int)$r['year']);
      $dividend_history[] = [
        'year'      => (int)$r['year'],
        'amount'    => (float)$r['dividend_amount'],
        'paid_date' => $r['paid_date'] ? date('Y-m-d', strtotime($r['paid_date'])) : '-',
        'note'      => $note,
        'status'    => $r['status'] // เพิ่มสถานะ
      ];
    }
  } catch (Throwable $e) {
    $db_err = $e->getMessage();
  }
}
if (!$div_summary['last_year']) { $div_summary['last_year'] = (int)date('Y') - 1; }
if ($div_summary['rate'] === null) { $div_summary['rate'] = 0.00; }

/* ====== สรุป "เฉลี่ยคืน" (rebate) & ประวัติ ====== */
$rebate_summary = [
  'accumulate'     => 0.00,   // รวมที่จ่ายแล้วทั้งหมด
  'last_period'    => null,   // ชื่อ/ปีงวดล่าสุดของสมาชิก (อาจ pending)
  'last_amount'    => 0.00,   // ยอดเฉลี่ยคืนในงวดล่าสุด (ของสมาชิก)
  'last_purchase'  => 0.00,   // ยอดซื้อตาม snapshot ของงวดล่าสุด
  'status'         => null,   // สถานะของรายการล่าสุด (pending/paid)
  'per_baht'       => 0.00,   // อัตราเฉลี่ยคืนต่อบาท (ถ้ามีใน period)
  'budget'         => 0.00,   // งบเฉลี่ยคืนรวมของงวดล่าสุด
  'payment_date'   => null,   // วันที่กำหนดจ่าย (ถ้ามี)
];
$rebate_history = []; // [{year, period_name, purchase, amount, status, paid_date}]

if ($db_ok && $member_id > 0) {
  try {
    // รวมยอดเฉลี่ยคืนที่ "จ่ายแล้ว"
    $acc = $pdo->prepare("SELECT COALESCE(SUM(rebate_amount),0) FROM rebate_payments WHERE member_id=:mid AND payment_status='paid'");
    $acc->execute([':mid'=>$member_id]);
    $rebate_summary['accumulate'] = (float)$acc->fetchColumn();

    // งวดล่าสุดของสมาชิก (จัดเรียงตาม วันที่จ่ายจริง > payment_date ของงวด > id)
    $stLast = $pdo->prepare("
      SELECT rp.year, rp.period_name, rp.rebate_type, rp.rebate_value, rp.rebate_per_baht,
             rp.total_rebate_budget, rp.payment_date, rp.status AS period_status,
             rpay.rebate_amount, rpay.purchase_amount_at_time,
             rpay.payment_status, rpay.paid_at
      FROM rebate_payments rpay
      JOIN rebate_periods rp ON rp.id = rpay.period_id
      WHERE rpay.member_id = :mid
      ORDER BY COALESCE(rpay.paid_at, rp.payment_date) DESC, rp.id DESC
      LIMIT 1
    ");
    $stLast->execute([':mid'=>$member_id]);
    if ($last = $stLast->fetch(PDO::FETCH_ASSOC)) {
      $rebate_summary['last_period']   = trim($last['period_name'] ?: ('เฉลี่ยคืนประจำปี '.$last['year']));
      $rebate_summary['last_amount']   = (float)$last['rebate_amount'];
      $rebate_summary['last_purchase'] = (float)$last['purchase_amount_at_time'];
      $rebate_summary['status']        = (string)$last['payment_status'];
      $rebate_summary['per_baht']      = (float)($last['rebate_per_baht'] ?? 0);
      $rebate_summary['budget']        = (float)($last['total_rebate_budget'] ?? 0);
      $rebate_summary['payment_date']  = $last['paid_at'] ?: $last['payment_date'];
    }

    // ประวัติทั้งหมดของสมาชิก
    $stHis = $pdo->prepare("
      SELECT rp.year, rp.period_name,
             rpay.purchase_amount_at_time AS purchase,
             rpay.rebate_amount AS amount,
             rpay.payment_status,
             COALESCE(rpay.paid_at, rp.payment_date) AS paid_date
      FROM rebate_payments rpay
      JOIN rebate_periods rp ON rp.id = rpay.period_id
      WHERE rpay.member_id = :mid
      ORDER BY (paid_date IS NULL), paid_date DESC, rp.year DESC, rp.id DESC
    ");
    $stHis->execute([':mid'=>$member_id]);
    $rebate_history = $stHis->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $db_err = $e->getMessage();
  }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ปันผล & เฉลี่ยคืน | <?= h($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    .panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
    .panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
    .badge-soft{border:1px solid var(--border);background:rgba(0,0,0,.04);padding:.15rem .5rem;border-radius:999px}
    .badge-paid{background:rgba(16,185,129,.12);color:#047857;border-color:rgba(16,185,129,.35)}
    .badge-pending{background:rgba(245,158,11,.12);color:#92400e;border-color:rgba(245,158,11,.35)}
  </style>
</head>
<body>

  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="member_dashboard.php"><?= h($site_name) ?></a>
      </div>
      <div class="d-flex align-items-center gap-3 ms-auto">
        <div class="nav-identity text-end d-none d-sm-block">
          <div class="nav-name"><?= h($current_name) ?></div>
          <div class="nav-sub"><?= h($current_role_th) ?></div>
        </div>
        <a href="profile.php" class="avatar-circle text-decoration-none"><?= h($avatar_text) ?></a>
      </div>
    </div>
  </nav>

  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= h($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Member</span></h3></div>
      <nav class="sidebar-menu">
        <a href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
        <a href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
        <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล & เฉลี่ยคืน</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Member</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
          <a href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
          <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
          <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล & เฉลี่ยคืน</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>

      <main class="col-lg-10 p-4">
        <div class="main-header">
          <h2><i class="fa-solid fa-gift me-2"></i>ปันผล & เฉลี่ยคืน</h2>
        </div>

        <?php if (!$db_ok): ?>
          <div class="alert alert-danger">เชื่อมต่อฐานข้อมูลไม่สำเร็จ: <?= h($db_err) ?></div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
          <div class="stat-card">
            <h5>ปันผลสะสมทั้งหมด</h5>
            <h3 class="text-primary">฿<?= number_format((float)$div_summary['accumulate'], 2) ?></h3>
            <p class="mb-0 text-muted">ตั้งแต่เริ่มเป็นสมาชิก</p>
          </div>
          <div class="stat-card">
            <h5>ปันผลปีล่าสุด (<?= h($div_summary['last_year']) ?>)</h5>
            <h3 class="text-success">฿<?= number_format((float)$div_summary['last_amount'], 2) ?></h3>
            <p class="mb-0 text-muted">อัตรา: <?= number_format((float)$div_summary['rate'], 2) ?>%</p>
          </div>

          <div class="stat-card">
            <h5>เฉลี่ยคืนสะสมทั้งหมด</h5>
            <h3 class="text-primary">฿<?= number_format((float)$rebate_summary['accumulate'], 2) ?></h3>
            <p class="mb-0 text-muted">เฉพาะรายการที่จ่ายแล้ว</p>
          </div>
          <div class="stat-card">
            <h5>งวดเฉลี่ยคืนล่าสุด<?= $rebate_summary['last_period'] ? ' — '.h($rebate_summary['last_period']) : '' ?></h5>
            <h3 class="<?= ($rebate_summary['status']==='paid'?'text-success':'text-warning') ?>">
              ฿<?= number_format((float)$rebate_summary['last_amount'], 2) ?>
            </h3>
            <p class="mb-0 text-muted">
              ยอดซื้ออ้างอิง: ฿<?= number_format((float)$rebate_summary['last_purchase'], 2) ?>
              <?php if ($rebate_summary['per_baht']>0): ?>
                | อัตราต่อบาท: <?= number_format((float)$rebate_summary['per_baht'], 6) ?>
              <?php endif; ?>
              <?php if ($rebate_summary['payment_date']): ?>
                | วันจ่าย: <?= h(date('Y-m-d', strtotime($rebate_summary['payment_date']))) ?>
              <?php endif; ?>
              <?php if ($rebate_summary['status']): ?>
                | สถานะ:
                <span class="badge badge-soft <?= $rebate_summary['status']==='paid'?'badge-paid':'badge-pending' ?>">
                  <?= $rebate_summary['status']==='paid'?'จ่ายแล้ว':'รอจ่าย' ?>
                </span>
              <?php endif; ?>
            </p>
          </div>
        </div>

        <div class="panel mb-4">
          <div class="panel-head">
            <h5><i class="fa-solid fa-history me-2"></i>ประวัติการรับปันผล</h5>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>ปีที่ปันผล</th>
                  <th class="text-end">จำนวนเงิน (บาท)</th>
                  <th>วันที่จ่าย / กำหนด</th>
                  <th>สถานะ</th> <th>หมายเหตุ</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($dividend_history)): ?>
                  <tr><td colspan="5" class="text-center text-muted">ยังไม่มีประวัติการรับปันผล</td></tr>
                <?php else: ?>
                  <?php foreach($dividend_history as $h): 
                    $status = $h['status'];
                    $badge = $status==='paid'?'badge-paid':'badge-pending';
                    $label = $status==='paid'?'จ่ายแล้ว':'รอจ่าย';
                  ?>
                  <tr>
                    <td><b><?= h($h['year']) ?></b></td>
                    <td class="text-end text-success fw-bold"><?= number_format((float)$h['amount'], 2) ?></td>
                    <td><?= h($h['paid_date']) ?></td>
                    <td>
                      <span class="badge badge-soft <?= $badge ?>"><?= $label ?></span>
                    </td>
                    <td class="text-muted"><?= h($h['note']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h5><i class="fa-solid fa-rotate me-2"></i>ประวัติการรับเฉลี่ยคืน</h5>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>ปี / งวด</th>
                  <th class="text-end">ยอดซื้ออ้างอิง (บาท)</th>
                  <th class="text-end">เฉลี่ยคืนที่ได้รับ (บาท)</th>
                  <th>สถานะ</th>
                  <th>วันที่จ่าย / กำหนด</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rebate_history)): ?>
                  <tr><td colspan="5" class="text-center text-muted">ยังไม่มีประวัติเฉลี่ยคืน</td></tr>
                <?php else: ?>
                  <?php foreach ($rebate_history as $r):
                    $status = (string)$r['payment_status'];
                    $badge  = $status==='paid' ? 'badge-paid' : 'badge-pending';
                    $label  = $status==='paid' ? 'จ่ายแล้ว' : 'รอจ่าย';
                  ?>
                  <tr>
                    <td>
                      <b><?= h($r['year']) ?></b>
                      <div class="text-muted small"><?= h($r['period_name']) ?></div>
                    </td>
                    <td class="text-end"><?= number_format((float)$r['purchase'], 2) ?></td>
                    <td class="text-end <?= $status==='paid'?'text-success fw-bold':'text-warning' ?>">
                      <?= number_format((float)$r['amount'], 2) ?>
                    </td>
                    <td><span class="badge badge-soft <?= $badge ?>"><?= $label ?></span></td>
                    <td><?= h($r['paid_date'] ? date('Y-m-d', strtotime($r['paid_date'])) : '-') ?></td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= h($site_name) ?> — ศูนย์สมาชิก</footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>