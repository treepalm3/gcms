<?php
// employee/member_points.php — จัดการแต้มสมาชิก (เชื่อม DB จริง + ปรับเสถียรภาพ)
session_start();

// ====== บังคับล็อกอิน ======
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php');
  exit();
}

// ====== CSRF ======
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ====== การเชื่อมต่อฐานข้อมูล ======
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // expect $pdo

// ====== เตรียม PDO ======
$db_ok = false; $db_err = null;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('ไม่พบตัวแปร $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET NAMES utf8mb4");
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}
  $pdo->query("SELECT 1");
  $db_ok = true;
} catch (Throwable $e) {
  $db_err = $e->getMessage();
}

// ====== ผู้ใช้ปัจจุบัน ======
$site_name = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';

$current_user_id = (int)$_SESSION['user_id'];
$current_name = $_SESSION['full_name'] ?? 'พนักงาน';
$current_role = $_SESSION['role'] ?? 'guest';
if ($current_role !== 'employee') {
  header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
  exit();
}

// อ่านชื่อไซต์/สถานี (ถ้ามีในตารางจริง)
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
    }
  } catch (Throwable $e) { /* ignore */ }
}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน', 'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ====== ฟอร์ม/ตัวแปรหลัก ======
$search_term = trim($_GET['search'] ?? '');
$member_data = null;        // { member_id, user_id, full_name, phone, house_number, points, tier }
$point_history = [];        // จากตาราง scores
$error_message = null;
$update_message = null;

// ====== จัดการฟอร์มปรับแต้ม ======
if ($db_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_points') {
  // CSRF
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $error_message = 'Invalid CSRF token.';
  } else {
    $member_id = (int)($_POST['member_id'] ?? 0);      // ต้องเป็น members.id
    $points = (int)($_POST['points'] ?? 0);
    $note = trim($_POST['note'] ?? 'ปรับแต้มโดยพนักงาน');

    if ($member_id <= 0) {
      $error_message = 'ข้อมูลสมาชิกไม่ถูกต้อง';
    } elseif ($points === 0) {
      $error_message = 'กรุณาระบุจำนวนแต้ม (+/-) ที่ต้องการปรับ';
    } else {
      try {
        $pdo->beginTransaction();

        // ล็อกแถวสมาชิก และยืนยันว่าอยู่สถานีเดียวกัน
        $stSel = $pdo->prepare("SELECT points, station_id FROM members WHERE id = :mid FOR UPDATE");
        $stSel->execute([':mid'=>$member_id]);
        $row = $stSel->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('ไม่พบสมาชิก');
        if ((int)$row['station_id'] !== (int)$station_id) {
          throw new RuntimeException('สมาชิกไม่ได้อยู่สถานีนี้');
        }

        $curPoints = (int)$row['points'];
        $newPoints = $curPoints + $points;
        if ($newPoints < 0) {
          throw new RuntimeException('แต้มคงเหลือจะติดลบ — ยกเลิกการบันทึก');
        }

        // ปรับแต้ม
        $stUpd = $pdo->prepare("UPDATE members SET points = :p WHERE id = :mid");
        $stUpd->execute([':p'=>$newPoints, ':mid'=>$member_id]);

        // บันทึกประวัติลง scores
        $activity = 'Adjust: ' . ($note !== '' ? $note : 'ปรับแต้ม') . ' (by ' . $current_name . ')';
        $stIns = $pdo->prepare("INSERT INTO scores(member_id, score, activity, score_date) VALUES(:mid, :score, :act, NOW())");
        $stIns->execute([':mid'=>$member_id, ':score'=>$points, ':act'=>$activity]);

        $pdo->commit();
        $update_message = 'ปรับปรุงแต้มสำเร็จ';

        // อัปเดตค่าในหน้าให้เห็นทันที (ถ้ากำลังแสดงสมาชิกอยู่)
        if ($member_data && (int)$member_data['member_id'] === $member_id) {
          $member_data['points'] = $newPoints;
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
      }
    }
  }
}

// ====== ค้นหาสมาชิก ======
if ($db_ok && $search_term !== '') {
  try {
    $digits = preg_replace('/\D+/', '', $search_term);
    
    // [แก้ไข] กำหนดค่า LIKE สำหรับชื่อ/บ้านเลขที่ ให้ใช้เฉพาะเมื่อไม่ใช่ตัวเลขล้วน
    if ($digits === $search_term) {
        // ถ้าค้นหาด้วยตัวเลขล้วน (digits เท่ากับ search_term) ให้ตั้งค่า LIKE เป็น "NO MATCH" ชั่วคราว
        // เพื่อบังคับให้ไปค้นหาที่เบอร์โทรหรือบ้านเลขที่แทน
        $likeNameHouse = '%$#@%'; // ใช้สตริงที่ไม่น่าจะเจอในชื่อ/บ้านเลขที่
    } else {
        // ถ้ามีตัวอักษรผสม ให้ค้นหาแบบเดิม (ชื่อ, บ้านเลขที่)
        $likeNameHouse = '%'.$search_term.'%';
    }
    
    $likeAll   = '%'.$search_term.'%';
    $likePhone = $digits ? '%'.$digits.'%' : $likeAll;

    $sql = "
      SELECT
        u.id            AS user_id,
        u.full_name     AS full_name,
        u.phone         AS phone,
        m.id            AS member_id,
        m.house_number  AS house_number,
        m.points        AS points,
        m.tier          AS tier
      FROM users u
      JOIN members m ON m.user_id = u.id
      WHERE u.is_active = 1
        AND u.role IN ('member', 'manager', 'committee', 'admin') 
        AND m.station_id = :st
        AND (
             u.full_name    LIKE :term_name
          OR REPLACE(REPLACE(u.phone,'-',''),' ','') LIKE :phone_like
          OR m.house_number LIKE :term_house
          OR m.member_code  LIKE :term_code -- เพิ่มการค้นหาด้วยรหัสสมาชิก
        )
      ORDER BY u.full_name
      LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    
    // [แก้ไข] ผูกค่าใหม่ตามเงื่อนไข
    $st->bindValue(':st',         (int)$station_id, PDO::PARAM_INT);
    $st->bindValue(':term_name',  $likeNameHouse,   PDO::PARAM_STR); // ใช้ตัวแปรใหม่
    $st->bindValue(':term_house', $likeNameHouse,   PDO::PARAM_STR); // ใช้ตัวแปรใหม่
    $st->bindValue(':phone_like', $likePhone,       PDO::PARAM_STR);
    $st->bindValue(':term_code',  $likeAll,         PDO::PARAM_STR); // ผูกรหัสสมาชิก

    $st->execute();
    $member_data = $st->fetch(PDO::FETCH_ASSOC);

    if ($member_data) {
      // ประวัติแต้มจาก scores
      $stH = $pdo->prepare("
        SELECT score_date AS transaction_date, score AS points, activity
        FROM scores
        WHERE member_id = :mid
        ORDER BY score_date DESC
        LIMIT 50
      ");
      $stH->execute([':mid'=>$member_data['member_id']]);
      $point_history = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $error_message = "ไม่พบสมาชิกที่ตรงกับ '".htmlspecialchars($search_term, ENT_QUOTES)."'";
    }
  } catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาดในการค้นหา: " . $e->getMessage();
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>จัดการแต้มสมาชิก | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .stat-card { background: var(--surface-glass); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 1.5rem; }
    .points-display { font-size: 2.5rem; font-weight: 700; color: var(--primary); }
    .points-earn { color: var(--success); }
    .points-negative { color: var(--danger); }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="employee_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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

  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Employee</span></h3></div>
      <nav class="sidebar-menu">
        <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i>แดชบอร์ด</a>
        <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
        <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
        <a class="active" href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="bi bi-box-arrow-right"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Employee</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i>แดชบอร์ด</a>
          <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
          <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
          <a class="active" href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i>ออกจากระบบ</a>
      </aside>

      <main class="col-lg-10 p-4">
        <div class="main-header">
          <h2><i class="bi bi-star-fill me-2"></i>จัดการแต้มสมาชิก</h2>
        </div>

        <?php if (!$db_ok): ?>
          <div class="alert alert-danger">เชื่อมต่อฐานข้อมูลไม่สำเร็จ: <?= htmlspecialchars($db_err) ?></div>
        <?php endif; ?>

        <?php if ($update_message): ?><div class="alert alert-success"><?= htmlspecialchars($update_message) ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>

        <div class="stat-card mb-4">
          <form method="GET" action="">
            <div class="row g-2 align-items-end">
              <div class="col-md-10">
                <label for="search" class="form-label">ค้นหาสมาชิก (ชื่อ, เบอร์โทร, บ้านเลขที่)</label>
                <input type="search" class="form-control form-control-lg" id="search" name="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="เช่น สมชาย, 0812345678, 123/4" required>
              </div>
              <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-search"></i> ค้นหา</button>
              </div>
            </div>
          </form>
        </div>

        <?php if ($member_data): ?>
        <div class="row g-4">
          <div class="col-lg-4">
            <div class="stat-card">
              <h5><i class="bi bi-person-circle me-2"></i>ข้อมูลสมาชิก</h5>
              <hr>
              <h4><?= htmlspecialchars($member_data['full_name']) ?></h4>
              <div class="points-display"><?= number_format((int)$member_data['points']) ?> <small class="fs-5 text-muted">แต้ม</small></div>
              <div class="mb-3">ระดับ: <span class="badge bg-info"><?= htmlspecialchars($member_data['tier']) ?></span></div>
              <p class="mb-1"><i class="bi bi-telephone-fill me-2"></i><?= htmlspecialchars($member_data['phone'] ?: '-') ?></p>
              <p class="mb-1"><i class="bi bi-house-door-fill me-2"></i><?= htmlspecialchars($member_data['house_number'] ?: '-') ?></p>
              <hr>
              <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#adjustPointsModal">
                <i class="bi bi-pencil-square me-1"></i>ปรับปรุงแต้ม
              </button>
            </div>
          </div>

          <div class="col-lg-8">
            <div class="stat-card">
              <h5><i class="bi bi-clock-history me-2"></i>ประวัติแต้ม</h5>
              <div class="table-responsive">
                <table class="table table-sm table-hover">
                  <thead>
                    <tr>
                      <th>วันที่</th>
                      <th>กิจกรรม</th>
                      <th class="text-end">แต้ม</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($point_history)): ?>
                      <tr><td colspan="3" class="text-center text-muted">ไม่มีประวัติ</td></tr>
                    <?php else: foreach ($point_history as $item): ?>
                      <tr>
                        <td><?= htmlspecialchars(date('d/m/y H:i', strtotime($item['transaction_date']))) ?></td>
                        <td><?= htmlspecialchars($item['activity'] ?? '') ?></td>
                        <td class="text-end <?= ((int)$item['points']>=0?'points-earn':'points-negative') ?>">
                          <?= ((int)$item['points']>0?'+':'').number_format((int)$item['points']) ?>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <?php elseif(empty($search_term)): ?>
          <div class="text-center p-5 stat-card">
            <i class="bi bi-search display-4 text-muted"></i>
            <h4 class="mt-3">กรุณาค้นหาสมาชิกเพื่อดูข้อมูล</h4>
            <p class="text-muted">ค้นหาด้วยชื่อ, เบอร์โทรศัพท์ หรือบ้านเลขที่</p>
          </div>
        <?php endif; ?>
      </main>
    </div>
  </div>

  <?php if ($member_data): ?>
  <div class="modal fade" id="adjustPointsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="adjust_points">
          <input type="hidden" name="member_id" value="<?= (int)$member_data['member_id'] ?>"><div class="modal-header">
            <h5 class="modal-title">ปรับปรุงแต้ม: <?= htmlspecialchars($member_data['full_name']) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="points" class="form-label">จำนวนแต้มที่ต้องการปรับ</label>
              <input type="number" class="form-control" id="points" name="points" required placeholder="ใส่ค่าบวกเพื่อเพิ่ม, ค่าลบเพื่อลด">
              <div class="form-text">ตัวอย่าง: 100 (เพิ่ม 100 แต้ม), -50 (ลด 50 แต้ม)</div>
            </div>
            <div class="mb-3">
              <label for="note" class="form-label">เหตุผล/หมายเหตุ</label>
              <textarea class="form-control" id="note" name="note" rows="3" required></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-primary">บันทึกการเปลี่ยนแปลง</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>