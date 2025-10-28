<?php
// employee/profile.php — ระบบจัดการโปรไฟล์พนักงาน (ต่อฐานข้อมูลจริง)
session_start();

if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php');
  exit();
}
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$site_name     = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';

// ====== DB connect ======
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // $pdo
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// ====== AuthZ ======
$current_user_id = (int)$_SESSION['user_id'];
$current_name    = $_SESSION['full_name'] ?? 'พนักงาน';
$current_role    = $_SESSION['role']      ?? 'employee';
if ($current_role !== 'employee') {
  header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
  exit();
}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name ?: 'พ', 0, 1, 'UTF-8');

// ====== โหลดโปรไฟล์จากฐานข้อมูล ======
function load_profile(PDO $pdo, int $uid): array {
  $sql = "SELECT 
            u.id            AS user_id,
            u.full_name     AS full_name,
            u.email         AS email,
            u.phone         AS phone,
            u.last_login_at AS last_login,
            u.is_active     AS is_active,
            e.emp_code      AS employee_id,
            e.position      AS position,
            e.address       AS emp_address,
            e.joined_date   AS joined_date,
            e.salary        AS salary,
            e.station_id    AS station_id
          FROM users u
          LEFT JOIN employees e ON e.user_id = u.id
          WHERE u.id = :uid
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':uid' => $uid]);
  $row = $st->fetch() ?: [];

  // ค่าเริ่มต้นหากยังไม่มีเรคคอร์ดฝั่ง employees
  $row['employee_id'] = $row['employee_id'] ?? '';
  $row['position']    = $row['position']    ?? 'พนักงานปั๊ม';
  $row['emp_address'] = $row['emp_address'] ?? '';
  $row['joined_date'] = $row['joined_date'] ?? null;
  $row['salary']      = isset($row['salary']) ? (float)$row['salary'] : 0.0;
  $row['station_id']  = $row['station_id'] ?? 1;

  return $row;
}

try {
  $profile = load_profile($pdo, $current_user_id);
} catch (Throwable $e) {
  die('เชื่อมต่อฐานข้อมูลล้มเหลว: '.$e->getMessage());
}

// ====== สถิติการทำงานของพนักงาน (จากยอดขายของ user นี้) ======
function load_work_stats(PDO $pdo, int $uid): array {
  // ช่วงเดือนนี้
  $sqlThis = "SELECT 
                COUNT(DISTINCT s.id)                          AS bills,
                COALESCE(SUM(s.net_amount),0)                 AS sales,
                COUNT(DISTINCT DATE(s.sale_date))             AS sale_days
              FROM sales s
              JOIN fuel_moves fm ON fm.sale_id = s.id AND fm.type='sale_out'
              WHERE fm.user_id = :uid
                AND s.sale_date >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
                AND s.sale_date <  DATE_FORMAT(DATE_ADD(CURRENT_DATE(), INTERVAL 1 MONTH), '%Y-%m-01')";
  $st1 = $pdo->prepare($sqlThis);
  $st1->execute([':uid'=>$uid]);
  $t = $st1->fetch() ?: ['bills'=>0,'sales'=>0,'sale_days'=>0];

  // เดือนก่อน
  $sqlPrev = "SELECT 
                COALESCE(SUM(s.net_amount),0) AS sales
              FROM sales s
              JOIN fuel_moves fm ON fm.sale_id = s.id AND fm.type='sale_out'
              WHERE fm.user_id = :uid
                AND s.sale_date >= DATE_FORMAT(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                AND s.sale_date <  DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')";
  $st2 = $pdo->prepare($sqlPrev);
  $st2->execute([':uid'=>$uid]);
  $prevSales = (float)($st2->fetchColumn() ?: 0);

  // นับจำนวนวันทำงานทั้งหมด (มีการขาย)
  $sqlDays = "SELECT COUNT(DISTINCT DATE(occurred_at)) 
              FROM fuel_moves 
              WHERE type='sale_out' AND user_id=:uid";
  $st3 = $pdo->prepare($sqlDays);
  $st3->execute([':uid'=>$uid]);
  $totalDays = (int)($st3->fetchColumn() ?: 0);

  $thisSales = (float)$t['sales'];
  $bills     = (int)$t['bills'];
  $saleDays  = max(1, (int)$t['sale_days']);
  $avgPerDay = $thisSales / $saleDays;
  $growth    = $prevSales > 0 ? (($thisSales - $prevSales) / $prevSales) * 100 : 0;

  return [
    'this_month_sales'     => $thisSales,
    'this_month_bills'     => $bills,
    'work_days_this_month' => (int)$t['sale_days'],
    'avg_sale_per_day'     => $avgPerDay,
    'total_work_days'      => $totalDays,
    'performance_rating'   => $growth >= 5 ? 4.7 : ($growth > 0 ? 4.3 : 4.0), // ประเมินง่ายๆ
    'last_month_sales'     => $prevSales,
    'sales_growth'         => $growth,
  ];
}
$work_stats = load_work_stats($pdo, $current_user_id);

// ====== ประวัติการเข้าสู่ระบบ (ไม่มีตารางโดยตรง ใช้ last_login_at) ======
$login_history = [];
if (!empty($profile['last_login'])) {
  $login_history[] = [
    'date'   => $profile['last_login'],
    'device' => 'อุปกรณ์ล่าสุด',
    'ip'     => '-',
    'status' => 'success'
  ];
}

// ====== การประมวลผลฟอร์ม ======
$update_success = false;
$update_message = '';
$error_message  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    die('Invalid CSRF token');
  }

  $action = $_POST['action'] ?? '';

  if ($action === 'update_profile') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name && $email && $phone) {
      try {
        $pdo->beginTransaction();

        // อัปเดต users
        $su = $pdo->prepare("UPDATE users 
                             SET full_name = :n, email = :e, phone = :p, updated_at = NOW() 
                             WHERE id = :uid");
        $su->execute([':n'=>$name, ':e'=>$email, ':p'=>$phone, ':uid'=>$current_user_id]);

        // อัปเดต/เพิ่ม employees.address
        $chk = $pdo->prepare("SELECT id FROM employees WHERE user_id = :uid LIMIT 1");
        $chk->execute([':uid'=>$current_user_id]);
        $empId = $chk->fetchColumn();

        if ($empId) {
          $se = $pdo->prepare("UPDATE employees SET address = :addr WHERE id = :id");
          $se->execute([':addr'=>$address, ':id'=>$empId]);
        } else {
          // ถ้ายังไม่มีเรคคอร์ดพนักงาน สร้างให้แบบเบื้องต้น
          $ins = $pdo->prepare("INSERT INTO employees (user_id, station_id, emp_code, position, address, joined_date, salary)
                                VALUES (:uid, :st, NULL, 'พนักงานปั๊ม', :addr, NULL, NULL)");
          $ins->execute([':uid'=>$current_user_id, ':st'=> ($profile['station_id'] ?? 1), ':addr'=>$address]);
        }

        $pdo->commit();
        $update_success = true;
        $update_message = 'อัปเดตข้อมูลส่วนตัวสำเร็จ';
        $profile = load_profile($pdo, $current_user_id);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = 'เกิดข้อผิดพลาดในการบันทึก: '.$e->getMessage();
      }
    } else {
      $error_message = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
    }
  }

  if ($action === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($current_password && $new_password && $confirm_password) {
      if ($new_password === $confirm_password) {
        if (strlen($new_password) >= 6) {
          try {
            // ดึง hash ปัจจุบัน
            $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = :uid");
            $st->execute([':uid'=>$current_user_id]);
            $hash = $st->fetchColumn();

            if (!$hash || !password_verify($current_password, $hash)) {
              $error_message = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
            } else {
              $newHash = password_hash($new_password, PASSWORD_BCRYPT);
              $up = $pdo->prepare("UPDATE users SET password_hash = :h, updated_at = NOW() WHERE id = :uid");
              $up->execute([':h'=>$newHash, ':uid'=>$current_user_id]);

              $update_success = true;
              $update_message = 'เปลี่ยนรหัสผ่านสำเร็จ';
            }
          } catch (Throwable $e) {
            $error_message = 'เกิดข้อผิดพลาด: '.$e->getMessage();
          }
        } else {
          $error_message = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
        }
      } else {
        $error_message = 'รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน';
      }
    } else {
      $error_message = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    }
  }
}

// ====== map ข้อมูลสำหรับ UI ======
$current_user = [
  'name'        => $profile['full_name']   ?? 'พนักงาน',
  'email'       => $profile['email']       ?? '',
  'phone'       => $profile['phone']       ?? '',
  'address'     => $profile['emp_address'] ?? '',
  'position'    => $profile['position']    ?? 'พนักงานปั๊ม',
  'employee_id' => $profile['employee_id'] ?? '',
  'start_date'  => $profile['joined_date'] ?? null,
  'salary'      => $profile['salary']      ?? 0,
  'department'  => '',     // ไม่มีในสคีมา employees
  'shift'       => '-',    // ไม่มีในสคีมา
  'avatar'      => null,
  'last_login'  => $profile['last_login'] ?? null,
  'status'      => !empty($profile['is_active']) ? 'active' : 'inactive'
];

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>โปรไฟล์ | สหกรณ์ปั๊มน้ำมัน</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .profile-card{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.5rem;height:100%}
    .profile-avatar{width:120px;height:120px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;font-size:3rem;font-weight:700;color:#fff;margin:0 auto 1.5rem;position:relative;overflow:hidden}
    .avatar-upload{position:absolute;bottom:0;right:0;background:var(--dark);color:#fff;border:none;border-radius:50%;width:35px;height:35px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.9rem}
    .stat-mini{text-align:center;padding:1rem;background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:1rem}
    .stat-mini .number{font-size:1.5rem;font-weight:700;color:var(--steel);display:block}
    .stat-mini .label{font-size:.9rem;color:var(--steel);margin-top:.25rem}
    .performance-badge{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1rem;border-radius:50px;font-weight:600;font-size:.9rem}
    .performance-excellent{background:linear-gradient(135deg,#28a745,#20c997);color:#fff}
    .form-section{margin-bottom:2rem;padding-bottom:2rem;border-bottom:1px solid var(--border)}
    .form-section:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
    .section-title{display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;color:var(--steel);font-weight:700}
    .login-history-item{display:flex;align-items:center;justify-content:space-between;padding:1rem;background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:.5rem}
    .device-info{display:flex;align-items:center;gap:1rem}
    .device-icon{width:40px;height:40px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;color:var(--primary)}
    .status-success{color:var(--success)} .status-failed{color:var(--danger)}
    .nav-tabs .nav-link{border:1px solid transparent;color:var(--steel);font-weight:500}
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
        <a href="profile.php" class="avatar-circle text-decoration-none"><?= htmlspecialchars($avatar_text) ?></a>
      </div>
    </div>
  </nav>

  <!-- Offcanvas Sidebar -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Employee</span></h3></div>
      <nav class="sidebar-menu">
        <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
        <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
        <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
        <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
        <a class="active" href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="../index.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Employee</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
          <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
          <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
          <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
          <a class="active" href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket me-1"></i> ออกจากระบบ</a>
      </aside>

      <main class="col-lg-10 p-4">
        <div class="main-header">
          <h2><i class="fa-solid fa-user-gear me-2"></i>โปรไฟล์และการตั้งค่า</h2>
        </div>

        <?php if ($update_success): ?>
          <div class="alert alert-success d-flex align-items-center mb-4">
            <i class="bi bi-check-circle-fill me-2"></i>
            <div><?= htmlspecialchars($update_message) ?></div>
          </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
          <div class="alert alert-danger d-flex align-items-center mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <div><?= htmlspecialchars($error_message) ?></div>
          </div>
        <?php endif; ?>

        <div class="row g-4">
          <div class="col-12 col-lg-4">
            <div class="profile-card">
              <div class="profile-avatar">
                <?= htmlspecialchars(mb_substr($current_user['name'], 0, 1, 'UTF-8')) ?>
                <button class="avatar-upload" onclick="uploadAvatar()"><i class="bi bi-camera"></i></button>
              </div>
              <div class="text-center mb-3">
                <h4 class="mb-1"><?= htmlspecialchars($current_user['name']) ?></h4>
                <p class="text-muted mb-2"><?= htmlspecialchars($current_user['position']) ?></p>
                <div class="performance-badge performance-excellent">
                  <i class="bi bi-star-fill"></i>
                  คะแนน <?= number_format($work_stats['performance_rating'],1) ?>/5
                </div>
              </div>
              <div class="row g-2">
                <div class="col-6"><div class="stat-mini"><span class="number"><?= number_format($work_stats['work_days_this_month']) ?></span><div class="label">วันทำงานเดือนนี้</div></div></div>
                <div class="col-6"><div class="stat-mini"><span class="number"><?= number_format($work_stats['this_month_bills']) ?></span><div class="label">บิลเดือนนี้</div></div></div>
                <div class="col-12"><div class="stat-mini"><span class="number text-success">฿<?= number_format($work_stats['this_month_sales'],0) ?></span><div class="label">ยอดขายเดือนนี้</div></div></div>
              </div>
              <hr>
              <div class="d-grid gap-2">
                <button class="btn btn-primary" onclick="showTab('personal')"><i class="bi bi-pencil-square me-1"></i>แก้ไขข้อมูล</button>
                <button class="btn btn-outline-primary" onclick="showTab('security')"><i class="bi bi-shield-lock me-1"></i>ความปลอดภัย</button>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-8">
            <ul class="nav nav-tabs" id="profileTabs" role="tablist">
              <li class="nav-item"><button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal" type="button"><i class="bi bi-person-circle me-1"></i>ข้อมูลส่วนตัว</button></li>
              <li class="nav-item"><button class="nav-link" id="work-tab" data-bs-toggle="tab" data-bs-target="#work" type="button"><i class="bi bi-briefcase me-1"></i>ข้อมูลการทำงาน</button></li>
              <li class="nav-item"><button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button"><i class="bi bi-shield-lock me-1"></i>ความปลอดภัย</button></li>
              <li class="nav-item"><button class="nav-link" id="stats-tab" data-bs-toggle="tab" data-bs-target="#stats" type="button"><i class="bi bi-graph-up me-1"></i>สถิติ</button></li>
            </ul>

            <div class="tab-content" id="profileTabContent">
              <!-- Personal -->
              <div class="tab-pane fade show active" id="personal">
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="update_profile">
                  <div class="form-section">
                    <h5 class="section-title"><i class="bi bi-person-fill"></i>ข้อมูลพื้นฐาน</h5>
                    <div class="row g-3">
                      <div class="col-md-6"><label class="form-label">ชื่อ-นามสกุล</label><input type="text" class="form-control" name="name" value="<?= htmlspecialchars($current_user['name']) ?>" required></div>
                      <div class="col-md-6"><label class="form-label">อีเมล</label><input type="email" class="form-control" name="email" value="<?= htmlspecialchars($current_user['email']) ?>" required></div>
                      <div class="col-md-6"><label class="form-label">เบอร์โทรศัพท์</label><input type="tel" class="form-control" name="phone" value="<?= htmlspecialchars($current_user['phone']) ?>" required></div>
                      <div class="col-md-6"><label class="form-label">รหัสพนักงาน</label><input type="text" class="form-control" value="<?= htmlspecialchars($current_user['employee_id'] ?: '-') ?>" readonly></div>
                      <div class="col-12"><label class="form-label">ที่อยู่</label><textarea class="form-control" name="address" rows="3"><?= htmlspecialchars($current_user['address']) ?></textarea></div>
                    </div>
                  </div>
                  <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>บันทึกการเปลี่ยนแปลง</button>
                    <button type="reset" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>รีเซ็ต</button>
                  </div>
                </form>
              </div>

              <!-- Work -->
              <div class="tab-pane fade" id="work">
                <div class="form-section">
                  <h5 class="section-title"><i class="bi bi-building"></i>ข้อมูลการทำงาน</h5>
                  <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">ตำแหน่ง</label><input type="text" class="form-control" value="<?= htmlspecialchars($current_user['position']) ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">แผนก</label><input type="text" class="form-control" value="<?= htmlspecialchars($current_user['department'] ?: '-') ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">วันเริ่มงาน</label><input type="text" class="form-control" value="<?= $current_user['start_date'] ? date('d/m/Y', strtotime($current_user['start_date'])) : '-' ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">กะการทำงาน</label><input type="text" class="form-control" value="<?= htmlspecialchars($current_user['shift']) ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">อายุงาน(วัน)</label><input type="text" class="form-control" value="<?= number_format($work_stats['total_work_days']) ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">สถานะ</label><input type="text" class="form-control" value="<?= $current_user['status'] === 'active' ? 'ปฏิบัติงาน' : 'หยุดงาน' ?>" readonly></div>
                  </div>
                </div>
                <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>ข้อมูลการทำงานแก้ไขได้โดยฝ่ายบุคคลเท่านั้น</div>
              </div>

              <!-- Security -->
              <div class="tab-pane fade" id="security">
                <div class="form-section">
                  <h5 class="section-title"><i class="bi bi-key-fill"></i>เปลี่ยนรหัสผ่าน</h5>
                  <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3">
                      <div class="col-12"><label class="form-label">รหัสผ่านปัจจุบัน</label><input type="password" class="form-control" name="current_password" required></div>
                      <div class="col-md-6"><label class="form-label">รหัสผ่านใหม่</label><input type="password" class="form-control" name="new_password" minlength="6" required></div>
                      <div class="col-md-6"><label class="form-label">ยืนยันรหัสผ่านใหม่</label><input type="password" class="form-control" name="confirm_password" minlength="6" required></div>
                    </div>
                    <div class="mt-3"><button type="submit" class="btn btn-warning"><i class="bi bi-shield-check me-1"></i>เปลี่ยนรหัสผ่าน</button></div>
                  </form>
                </div>

                <div class="form-section">
                  <h5 class="section-title"><i class="bi bi-clock-history"></i>ประวัติการเข้าสู่ระบบ</h5>
                  <div class="mb-3">
                    <span class="badge bg-success">
                      การเข้าสู่ระบบล่าสุด: <?= $current_user['last_login'] ? date('d/m/Y H:i:s', strtotime($current_user['last_login'])) : '-' ?>
                    </span>
                  </div>
                  <?php if (empty($login_history)): ?>
                    <div class="text-muted">ไม่มีข้อมูลประวัติ</div>
                  <?php else: foreach ($login_history as $login): ?>
                    <div class="login-history-item">
                      <div class="device-info">
                        <div class="device-icon"><i class="bi bi-device-desktop"></i></div>
                        <div>
                          <div class="fw-bold"><?= htmlspecialchars($login['device']) ?></div>
                          <small class="text-muted">IP: <?= htmlspecialchars($login['ip']) ?></small>
                        </div>
                      </div>
                      <div class="text-end">
                        <div class="status-<?= $login['status'] ?> fw-bold"><?= $login['status'] === 'success' ? 'สำเร็จ' : 'ล้มเหลว' ?></div>
                        <small class="text-muted"><?= date('d/m/Y H:i', strtotime($login['date'])) ?></small>
                      </div>
                    </div>
                  <?php endforeach; endif; ?>
                </div>
              </div>

              <!-- Stats -->
              <div class="tab-pane fade" id="stats">
                <div class="form-section">
                  <h5 class="section-title"><i class="bi bi-graph-up-arrow"></i>สถิติการทำงานเดือนนี้</h5>
                  <div class="row g-3">
                    <div class="col-md-6 col-lg-3"><div class="stat-mini"><span class="number text-success">฿<?= number_format($work_stats['this_month_sales'],0) ?></span><div class="label">ยอดขายรวม</div></div></div>
                    <div class="col-md-6 col-lg-3"><div class="stat-mini"><span class="number text-primary"><?= number_format($work_stats['this_month_bills']) ?></span><div class="label">จำนวนบิล</div></div></div>
                    <div class="col-md-6 col-lg-3"><div class="stat-mini"><span class="number text-info">฿<?= number_format($work_stats['avg_sale_per_day'],0) ?></span><div class="label">เฉลี่ย/วัน</div></div></div>
                    <div class="col-md-6 col-lg-3"><div class="stat-mini"><span class="number text-warning"><?= number_format($work_stats['work_days_this_month']) ?></span><div class="label">วันทำงาน</div></div></div>
                  </div>
                </div>
                <div class="form-section">
                  <h5 class="section-title"><i class="bi bi-trophy"></i>ผลการดำเนินงาน</h5>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <div class="alert alert-success">
                        <h6><i class="bi bi-arrow-up-circle me-1"></i>เติบโตจากเดือนก่อน</h6>
                        <p class="mb-0"><strong><?= sprintf('%+.1f%%',$work_stats['sales_growth']) ?></strong> (<?= number_format($work_stats['this_month_sales'] - $work_stats['last_month_sales'],0) ?> บาท)</p>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="alert alert-info">
                        <h6><i class="bi bi-star me-1"></i>คะแนนประเมิน</h6>
                        <p class="mb-0"><strong><?= number_format($work_stats['performance_rating'],1) ?>/5</strong></p>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="alert alert-light"><i class="bi bi-lightbulb me-2"></i>สถิติคำนวณจากบิลที่คุณเป็นผู้ขาย (fuel_moves.user_id) เท่านั้น</div>
              </div>

            </div>
          </div>
        </div>

      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> สหกรณ์ปั๊มน้ำมัน — โปรไฟล์พนักงาน</footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function showTab(name){ document.querySelector(`#${name}-tab`)?.click(); }
    function uploadAvatar(){ alert('ฟีเจอร์อัปโหลดรูปโปรไฟล์ - กำลังพัฒนา'); }
    document.querySelector('input[name="confirm_password"]')?.addEventListener('input', function(){
      const newPassword = document.querySelector('input[name="new_password"]').value;
      this.setCustomValidity(newPassword !== this.value ? 'รหัสผ่านไม่ตรงกัน' : '');
    });
  </script>
</body>
</html>
