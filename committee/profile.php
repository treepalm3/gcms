<?php
// committee/profile.php — โปรไฟล์กรรมการ (ต่อฐานข้อมูลจริงตามสคีมา)
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit();
}
$current_name = $_SESSION['full_name'] ?? 'กรรมการ';
$current_role = $_SESSION['role'] ?? 'guest';
if ($current_role !== 'committee') {
  header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
}

require_once __DIR__ . '/../config/db.php'; // $pdo

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ===== โหลดชื่อไซต์จาก app_settings ===== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
try {
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch()) {
    $sys = json_decode($r['json_value'] ?? '', true) ?: [];
    $site_name     = $sys['site_name']     ?? $site_name;
    $site_subtitle = $sys['site_subtitle'] ?? $site_subtitle;
  }
} catch (Throwable $e) {}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน',
  'member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name ?: 'ก', 0, 1, 'UTF-8');

$error_msg   = $_GET['err'] ?? '';
$success_msg = $_GET['ok']  ?? '';

/* ===== ดึงข้อมูลผู้ใช้ + ข้อมูลในตาราง committees ===== */
$user_data = null;
$comm_data = null;
try {
  $u = $pdo->prepare("
    SELECT id, username, full_name, email, phone, role,
           created_at, updated_at, last_login_at
    FROM users
    WHERE id = ? AND role = 'committee'
    LIMIT 1
  ");
  $u->execute([$_SESSION['user_id']]);
  $user_data = $u->fetch();

  if (!$user_data) { header('Location: /index/login.php?err=ไม่พบข้อมูลผู้ใช้'); exit(); }

  $c = $pdo->prepare("
    SELECT id, committee_code, station_id, department, position,
           shares, house_number, address, joined_date
    FROM committees
    WHERE user_id = ?
    LIMIT 1
  ");
  $c->execute([$_SESSION['user_id']]);
  $comm_data = $c->fetch() ?: null;
} catch (Throwable $e) {
  $error_msg = 'เกิดข้อผิดพลาดในการดึงข้อมูลผู้ใช้';
}

/* ===== POST actions ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ok_csrf = hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
  if (!$ok_csrf) {
    header('Location: profile.php?err=' . urlencode('การตรวจสอบความปลอดภัยล้มเหลว')); exit();
  }

  $action = $_POST['action'] ?? '';

  if ($action === 'update_profile') {
    $full_name   = trim($_POST['full_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $house_no    = trim($_POST['house_number'] ?? '');
    $address     = trim($_POST['address'] ?? '');

    if ($full_name === '') {
      header('Location: profile.php?err=' . urlencode('กรุณากรอกชื่อ-นามสกุล')); exit();
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      header('Location: profile.php?err=' . urlencode('รูปแบบอีเมลไม่ถูกต้อง')); exit();
    }
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    try {
      // กันอีเมลซ้ำ (ยกเว้นตัวเอง)
      if ($email !== '') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
        $chk->execute([$email, $_SESSION['user_id']]);
        if ((int)$chk->fetchColumn() > 0) {
          header('Location: profile.php?err=' . urlencode('อีเมลนี้ถูกใช้แล้ว')); exit();
        }
      }

      // อัปเดตตาราง users
      $upd = $pdo->prepare("
        UPDATE users
        SET full_name = ?, email = ?, phone = ?, updated_at = NOW()
        WHERE id = ? AND role = 'committee'
      ");
      $upd->execute([$full_name, ($email ?: null), ($phone ?: null), $_SESSION['user_id']]);

      // อัปเดต/แทรกตาราง committees (เก็บที่อยู่/บ้านเลขที่)
      $comm_id = $comm_data['id'] ?? null;
      if ($comm_id) {
        $cup = $pdo->prepare("UPDATE committees SET house_number = ?, address = ? WHERE id = ?");
        $cup->execute([$house_no ?: null, $address ?: null, $comm_id]);
      } else {
        // ยังไม่มี row ของกรรมการ -> สร้างใหม่ (ต้องมี committee_code)
        $code = 'C-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $ins = $pdo->prepare("
          INSERT INTO committees (committee_code, user_id, station_id, house_number, address, joined_date)
          VALUES (?, ?, 1, ?, ?, CURDATE())
        ");
        $ins->execute([$code, $_SESSION['user_id'], $house_no ?: null, $address ?: null]);
      }

      $_SESSION['full_name'] = $full_name;

      // audit (ถ้ายังไม่มีจะสร้าง)
      try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_audit_log(
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL, action VARCHAR(50) NOT NULL,
          detail TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $lg = $pdo->prepare("INSERT INTO user_audit_log (user_id, action, detail) VALUES (?,?,?)");
        $lg->execute([$_SESSION['user_id'], 'update_profile', json_encode(['ip'=>$_SERVER['REMOTE_ADDR'] ?? '','ua'=>$_SERVER['HTTP_USER_AGENT'] ?? ''], JSON_UNESCAPED_UNICODE)]);
      } catch (Throwable $e) {}

      header('Location: profile.php?ok=' . urlencode('อัพเดทข้อมูลโปรไฟล์เรียบร้อยแล้ว')); exit();
    } catch (Throwable $e) {
      header('Location: profile.php?err=' . urlencode('เกิดข้อผิดพลาดในการอัพเดทข้อมูล')); exit();
    }
  }

  if ($action === 'change_password') {
    $current_password = (string)($_POST['current_password'] ?? '');
    $new_password     = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
      header('Location: profile.php?err=' . urlencode('กรุณากรอกข้อมูลให้ครบถ้วน')); exit();
    }
    if ($new_password !== $confirm_password) {
      header('Location: profile.php?err=' . urlencode('รหัสผ่านใหม่และการยืนยันไม่ตรงกัน')); exit();
    }
    if (strlen($new_password) < 8) { // ตาม security_settings
      header('Location: profile.php?err=' . urlencode('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร')); exit();
    }

    try {
      $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND role = 'committee'");
      $st->execute([$_SESSION['user_id']]);
      $stored_hash = $st->fetchColumn();

      if (!$stored_hash || !password_verify($current_password, $stored_hash)) {
        header('Location: profile.php?err=' . urlencode('รหัสผ่านเดิมไม่ถูกต้อง')); exit();
      }
      if (password_verify($new_password, $stored_hash)) {
        header('Location: profile.php?err=' . urlencode('โปรดใช้รหัสผ่านใหม่ที่แตกต่างจากเดิม')); exit();
      }

      $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
      $up = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ? AND role = 'committee'");
      $up->execute([$new_hash, $_SESSION['user_id']]);

      try {
        $lg = $pdo->prepare("INSERT INTO user_audit_log (user_id, action, detail) VALUES (?,?,?)");
        $lg->execute([$_SESSION['user_id'], 'change_password', json_encode(['ip'=>$_SERVER['REMOTE_ADDR'] ?? ''], JSON_UNESCAPED_UNICODE)]);
      } catch (Throwable $e) {}

      header('Location: profile.php?ok=' . urlencode('เปลี่ยนรหัสผ่านเรียบร้อยแล้ว')); exit();
    } catch (Throwable $e) {
      header('Location: profile.php?err=' . urlencode('เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน')); exit();
    }
  }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($site_name) ?> - โปรไฟล์กรรมการ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <style>
    .profile-section{background:#fff;border-radius:15px;box-shadow:0 4px 6px rgba(0,0,0,.1);padding:2rem;margin-bottom:2rem}
    .profile-avatar{width:100px;height:100px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-size:2.5rem;font-weight:600;margin:0 auto 1rem}
    .info-row{padding:.75rem 0;border-bottom:1px solid #f0f0f0;display:flex;align-items:center}
    .info-row:last-child{border-bottom:none}
    .info-label{font-weight:600;color:#495057;min-width:120px}
    .info-value{color:#6c757d}
    .form-section{background:#fff;border-radius:15px;box-shadow:0 4px 6px rgba(0,0,0,.1);padding:2rem;margin-bottom:2rem}
    .section-title{color:#495057;font-weight:600;margin-bottom:1.5rem;padding-bottom:.5rem;border-bottom:2px solid #e9ecef}
    .btn-custom{border-radius:8px;padding:.5rem 1.5rem;font-weight:500}
    .alert-custom{border-radius:10px;border:none}
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="committee_dashboard.php"><?= h($site_name) ?></a>
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
      <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu">
        <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php" class="active"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <div class="col-md-3 col-lg-2 d-none d-md-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
          <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
          <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
          <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
          <a href="profile.php" class="active"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </div>

      <main class="col-md-9 col-lg-10 p-4 fade-in">
        <div class="main-header"><h2><i class="fa-solid fa-user-gear me-2"></i>โปรไฟล์กรรมการ</h2></div>

        <?php if ($error_msg): ?>
          <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
          <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= h($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <div class="row">
          <div class="col-lg-4">
            <div class="profile-section">
              <div class="text-center">
                <div class="profile-avatar"><?= h($avatar_text) ?></div>
                <h4 class="mb-1"><?= h($user_data['full_name'] ?? 'ไม่ระบุ') ?></h4>
                <p class="text-muted mb-3"><?= h($current_role_th) ?></p>
              </div>
              <div class="info-row"><span class="info-label"><i class="bi bi-person-badge me-2"></i>ชื่อผู้ใช้:</span><span class="info-value"><?= h($user_data['username'] ?? '-') ?></span></div>
              <div class="info-row"><span class="info-label"><i class="bi bi-envelope me-2"></i>อีเมล:</span><span class="info-value"><?= h($user_data['email'] ?? '-') ?></span></div>
              <div class="info-row"><span class="info-label"><i class="bi bi-telephone me-2"></i>เบอร์โทร:</span><span class="info-value"><?= h($user_data['phone'] ?? '-') ?></span></div>
              <div class="info-row"><span class="info-label"><i class="bi bi-calendar-plus me-2"></i>สมาชิกเมื่อ:</span><span class="info-value"><?= !empty($user_data['created_at']) ? date('d/m/Y', strtotime($user_data['created_at'])) : 'ไม่ระบุ' ?></span></div>
              <div class="info-row"><span class="info-label"><i class="bi bi-clock-history me-2"></i>เข้าสู่ระบบล่าสุด:</span><span class="info-value"><?= !empty($user_data['last_login_at']) ? date('d/m/Y H:i', strtotime($user_data['last_login_at'])) : 'ไม่ระบุ' ?></span></div>
              <?php if ($comm_data): ?>
                <div class="info-row"><span class="info-label"><i class="bi bi-upc-scan me-2"></i>รหัสกรรมการ:</span><span class="info-value"><?= h($comm_data['committee_code']) ?></span></div>
                <div class="info-row"><span class="info-label"><i class="bi bi-people me-2"></i>ตำแหน่ง:</span><span class="info-value"><?= h($comm_data['position'] ?? '-') ?></span></div>
                <div class="info-row"><span class="info-label"><i class="bi bi-star me-2"></i>หุ้น:</span><span class="info-value"><?= number_format((int)($comm_data['shares'] ?? 0)) ?> หุ้น</span></div>
                <div class="info-row"><span class="info-label"><i class="bi bi-calendar2-check me-2"></i>เริ่มเมื่อ:</span><span class="info-value"><?= !empty($comm_data['joined_date']) ? date('d/m/Y', strtotime($comm_data['joined_date'])) : '-' ?></span></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-lg-8">
            <div class="form-section">
              <h5 class="section-title"><i class="bi bi-person-fill-gear me-2"></i>แก้ไขข้อมูลส่วนตัว</h5>
              <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="full_name" value="<?= h($user_data['full_name'] ?? '') ?>" required>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">อีเมล</label>
                    <input type="email" class="form-control" name="email" value="<?= h($user_data['email'] ?? '') ?>">
                  </div>
                </div>
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <input type="text" class="form-control" name="phone" value="<?= h($user_data['phone'] ?? '') ?>">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">ชื่อผู้ใช้</label>
                    <input type="text" class="form-control" value="<?= h($user_data['username'] ?? '') ?>" disabled>
                    <div class="form-text">ไม่สามารถเปลี่ยนชื่อผู้ใช้ได้</div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-md-4 mb-3">
                    <label class="form-label">บ้านเลขที่</label>
                    <input type="text" class="form-control" name="house_number" value="<?= h($comm_data['house_number'] ?? '') ?>">
                  </div>
                  <div class="col-md-8 mb-3">
                    <label class="form-label">ที่อยู่</label>
                    <textarea class="form-control" name="address" rows="2"><?= h($comm_data['address'] ?? '') ?></textarea>
                  </div>
                </div>
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary btn-custom"><i class="bi bi-check-lg me-1"></i>บันทึกการเปลี่ยนแปลง</button>
                  <button type="reset" class="btn btn-outline-secondary btn-custom"><i class="bi bi-arrow-clockwise me-1"></i>รีเซ็ต</button>
                </div>
              </form>
            </div>

            <div class="form-section">
              <h5 class="section-title"><i class="bi bi-shield-lock me-2"></i>เปลี่ยนรหัสผ่าน</h5>
              <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="row">
                  <div class="col-md-4 mb-3">
                    <label class="form-label">รหัสผ่านปัจจุบัน <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="current_password" required>
                  </div>
                  <div class="col-md-4 mb-3">
                    <label class="form-label">รหัสผ่านใหม่ <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="new_password" minlength="8" required>
                    <div class="form-text">อย่างน้อย 8 ตัวอักษร</div>
                  </div>
                  <div class="col-md-4 mb-3">
                    <label class="form-label">ยืนยันรหัสผ่านใหม่ <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required>
                  </div>
                </div>
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-warning btn-custom"><i class="bi bi-key me-1"></i>เปลี่ยนรหัสผ่าน</button>
                  <button type="reset" class="btn btn-outline-secondary btn-custom"><i class="bi bi-arrow-clockwise me-1"></i>ล้างข้อมูล</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= h($site_name) ?> - <?= h($site_subtitle) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('confirm_password').addEventListener('input', function() {
      const form = this.closest('form');
      const newPass = form.querySelector('input[name="new_password"]').value;
      this.setCustomValidity(newPass !== this.value ? 'รหัสผ่านไม่ตรงกัน' : '');
    });
    setTimeout(()=>document.querySelectorAll('.alert').forEach(a=>new bootstrap.Alert(a).close()), 5000);
  </script>
</body>
</html>
