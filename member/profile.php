<?php
// employee/profile.php — โปรไฟล์สมาชิก (เชื่อมฐานข้อมูลจริง)
session_start();
date_default_timezone_set('Asia/Bangkok');

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ====== ตรวจสิทธิ์ ====== */
try {
  $current_name = $_SESSION['full_name'] ?? 'สมาชิกสหกรณ์';
  $current_role = $_SESSION['role'] ?? 'guest';
  $current_user_id = (int)($_SESSION['user_id'] ?? 0);
  if ($current_role !== 'member' || $current_user_id <= 0) {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
  exit();
}

/* ====== เชื่อมต่อฐานข้อมูล ====== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; } // เผื่อวางไฟล์ต่างตำแหน่ง
require_once $dbFile; // expect $pdo (PDO)

$db_ok = true; $db_err = null;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('ไม่พบตัวแปร $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET NAMES utf8mb4");
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}
} catch (Throwable $e) { $db_ok = false; $db_err = $e->getMessage(); }

/* ====== ฟังก์ชันตอบ JSON สำหรับ AJAX ====== */
function jsonResponse($ok, $message, $extra = []) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>$ok, 'message'=>$message] + $extra, JSON_UNESCAPED_UNICODE);
  exit();
}

/* ====== ค่าพื้นฐานไซต์/สถานี ====== */
$site_name = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
$station_id = 1;

if ($db_ok) {
  try {
    // อ่าน station_id / ชื่อจาก settings (comment)
    $rowSt = $pdo->query("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($rowSt) {
      $station_id = (int)$rowSt['setting_value'];
      if (!empty($rowSt['comment'])) $site_name = $rowSt['comment'];
    }
    // อ่าน system_settings จาก app_settings
    $sys = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings'")->fetchColumn();
    if ($sys) {
      $sysj = json_decode($sys, true);
      if (!empty($sysj['site_name'])) $site_name = $sysj['site_name'];
      if (!empty($sysj['site_subtitle'])) $site_subtitle = $sysj['site_subtitle'];
    }
  } catch (Throwable $e) { /* ignore */ }
}

/* ====== โหลดข้อมูลสมาชิกจาก DB ====== */
$member = [
  'member_code' => '-',
  'name' => $current_name,
  'tier' => 'Bronze',
  'points' => 0,
  'joined' => null,
  'address' => '',
  'house_number' => '',
  'shares' => 0,
  'email' => '',
  'phone' => '',
  'balance' => 0.00, // ไม่มีคอลัมน์ wallet ในสคีมาปัจจุบัน — ใช้ 0.00
];

$last_login_at = null;
$twofa_enabled_global = false; // ค่าในระบบ (global)
if ($db_ok) {
  try {
    // ผู้ใช้ + สมาชิก
    $st = $pdo->prepare("
      SELECT
        u.full_name, u.email, u.phone, u.last_login_at,
        m.id AS member_id, m.member_code, m.joined_date, m.address, m.points, m.house_number, m.tier, m.shares
      FROM users u
      LEFT JOIN members m ON m.user_id = u.id AND m.station_id = :st
      WHERE u.id = :uid AND u.is_active = 1
      LIMIT 1
    ");
    $st->execute([':uid'=>$current_user_id, ':st'=>$station_id]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $current_name = $row['full_name'] ?: $current_name;
      $member['name'] = $current_name;
      $member['email'] = $row['email'] ?? '';
      $member['phone'] = $row['phone'] ?? '';
      $member['member_code'] = $row['member_code'] ?: '-';
      $member['joined'] = $row['joined_date'] ?: null;
      $member['address'] = $row['address'] ?? '';
      $member['points'] = (int)($row['points'] ?? 0);
      $member['house_number'] = (string)($row['house_number'] ?? '');
      $member['tier'] = $row['tier'] ?: 'Bronze';
      $member['shares'] = (int)($row['shares'] ?? 0);
      $last_login_at = $row['last_login_at'] ?: null;
    }

    // สถานะ 2FA (ทั้งระบบ)
    $sec = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='security_settings'")->fetchColumn();
    if ($sec) {
      $secj = json_decode($sec, true);
      $twofa_enabled_global = !empty($secj['two_factor_auth']);
    }
  } catch (Throwable $e) {
    $db_err = $e->getMessage();
  }
}

/* ====== จัดการคำขอ AJAX (บันทึกโปรไฟล์ / เปลี่ยนรหัสผ่าน) ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if (!$db_ok) jsonResponse(false, 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $db_err);
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    jsonResponse(false, 'ไม่ผ่านการตรวจสอบความปลอดภัย (CSRF)');
  }

  $action = $_POST['action'];

  if ($action === 'update_profile') {
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if ($full_name === '') jsonResponse(false, 'กรุณากรอกชื่อ-สกุล');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'รูปแบบอีเมลไม่ถูกต้อง');
    if ($phone !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/u', $phone)) jsonResponse(false, 'รูปแบบเบอร์โทรไม่ถูกต้อง');

    try {
      $pdo->beginTransaction();

      // อัปเดต users
      $su = $pdo->prepare("UPDATE users SET full_name=:n, email=:e, phone=:p WHERE id=:uid LIMIT 1");
      $su->execute([':n'=>$full_name, ':e'=>($email ?: null), ':p'=>($phone ?: null), ':uid'=>$current_user_id]);

      // อัปเดต members.address (ถ้ามีแถวของสมาชิก)
      $sm = $pdo->prepare("UPDATE members SET address=:a WHERE user_id=:uid AND station_id=:st LIMIT 1");
      $sm->execute([':a'=>$address, ':uid'=>$current_user_id, ':st'=>$station_id]);

      $pdo->commit();

      // อัปเดตค่าใน session เพื่อให้ header/Avatar ทันที
      $_SESSION['full_name'] = $full_name;

      jsonResponse(true, 'บันทึกข้อมูลโปรไฟล์เรียบร้อยแล้ว', [
        'full_name'=>$full_name, 'email'=>$email, 'phone'=>$phone, 'address'=>$address
      ]);
    } catch (PDOException $ex) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // handle duplicate email (unique)
      if ($ex->getCode()==='23000') {
        jsonResponse(false, 'อีเมลนี้ถูกใช้งานแล้ว');
      }
      jsonResponse(false, 'ไม่สามารถบันทึกข้อมูลได้: '.$ex->getMessage());
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      jsonResponse(false, 'ผิดพลาด: '.$e->getMessage());
    }
  }

  if ($action === 'change_password') {
    $old = (string)($_POST['oldPassword'] ?? '');
    $new = (string)($_POST['newPassword'] ?? '');
    $confirm = (string)($_POST['confirmPassword'] ?? '');

    if ($new === '' || $confirm === '' || $old === '') jsonResponse(false, 'กรุณากรอกข้อมูลให้ครบ');
    if ($new !== $confirm) jsonResponse(false, 'รหัสผ่านใหม่ไม่ตรงกัน');
    if (strlen($new) < 8) jsonResponse(false, 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 8 ตัวอักษร');

    try {
      $hash = $pdo->prepare("SELECT password_hash FROM users WHERE id=:uid LIMIT 1");
      $hash->execute([':uid'=>$current_user_id]);
      $cur = $hash->fetchColumn();
      if (!$cur || !password_verify($old, $cur)) {
        jsonResponse(false, 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
      }

      $newHash = password_hash($new, PASSWORD_DEFAULT);
      $upd = $pdo->prepare("UPDATE users SET password_hash=:h WHERE id=:uid LIMIT 1");
      $upd->execute([':h'=>$newHash, ':uid'=>$current_user_id]);

      jsonResponse(true, 'เปลี่ยนรหัสผ่านสำเร็จ');
    } catch (Throwable $e) {
      jsonResponse(false, 'ไม่สามารถเปลี่ยนรหัสผ่านได้: '.$e->getMessage());
    }
  }

  // สคีมาปัจจุบันไม่มี per-user 2FA จึงไม่รองรับการ toggle เฉพาะบุคคล
  if ($action === 'toggle_2fa') {
    jsonResponse(false, 'ยังไม่รองรับการตั้งค่า 2FA รายบุคคลในเวอร์ชันนี้');
  }

  jsonResponse(false, 'ไม่รู้จักคำสั่งที่ร้องขอ');
}

/* ====== เตรียมค่าที่ใช้ใน UI ====== */
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
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
  <title>โปรไฟล์ | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    .panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
    .panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
    .panel-head h5{margin:0;color:var(--steel);font-weight:700}
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
        <a class="navbar-brand" href="member_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Member</span></h3></div>
      <nav class="sidebar-menu">
        <a href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
        <a href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
        <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a class="active" href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="../index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Member</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
          <a href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
          <a href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a class="active" href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="../index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>

      <!-- Content -->
      <main class="col-lg-10 p-4">
        <div class="main-header">
          <h2><i class="fa-solid fa-user-gear me-2"></i>โปรไฟล์และการตั้งค่า</h2>
        </div>

        <?php if (!$db_ok): ?>
          <div class="alert alert-danger">เชื่อมต่อฐานข้อมูลไม่สำเร็จ: <?= htmlspecialchars($db_err) ?></div>
        <?php endif; ?>

        <div class="row g-4">
          <!-- Profile Form -->
          <div class="col-12 col-lg-7">
            <div class="panel h-100">
              <div class="panel-head">
                <h5>ข้อมูลส่วนตัว</h5>
              </div>
              <form id="profileForm" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="col-12 col-md-6">
                  <label class="form-label">รหัสสมาชิก</label>
                  <input class="form-control" value="<?= htmlspecialchars($member['member_code']) ?>" readonly>
                </div>
                <div class="col-12 col-md-6">
                  <label class="form-label">วันที่สมัคร</label>
                  <input class="form-control" value="<?= htmlspecialchars($member['joined'] ? date('Y-m-d', strtotime($member['joined'])) : '-') ?>" readonly>
                </div>
                <div class="col-12">
                  <label for="nameInput" class="form-label">ชื่อ-สกุล</label>
                  <input type="text" id="nameInput" name="full_name" class="form-control" value="<?= htmlspecialchars($member['name']) ?>" required>
                </div>
                <div class="col-12 col-md-6">
                  <label for="emailInput" class="form-label">อีเมล</label>
                  <input type="email" id="emailInput" name="email" class="form-control" value="<?= htmlspecialchars($member['email']) ?>">
                </div>
                <div class="col-12 col-md-6">
                  <label for="phoneInput" class="form-label">โทรศัพท์</label>
                  <input type="tel" id="phoneInput" name="phone" class="form-control" value="<?= htmlspecialchars($member['phone']) ?>">
                </div>
                <div class="col-12 col-md-6">
                  <label class="form-label">เลขที่ครัวเรือน</label>
                  <input class="form-control" value="<?= htmlspecialchars($member['house_number'] ?: '-') ?>" readonly>
                </div>
                <div class="col-12 col-md-6">
                  <label class="form-label">หุ้น</label>
                  <input class="form-control" value="<?= htmlspecialchars(number_format($member['shares'])) ?>" readonly>
                </div>
                <div class="col-12">
                  <label for="addressInput" class="form-label">ที่อยู่</label>
                  <textarea id="addressInput" name="address" class="form-control" rows="2"><?= htmlspecialchars($member['address']) ?></textarea>
                </div>
                <div class="col-12 mt-4">
                  <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> บันทึกข้อมูล</button>
                  <button type="reset" class="btn btn-outline-secondary">คืนค่าเดิม</button>
                </div>
              </form>
            </div>
          </div>

          <!-- Security -->
          <div class="col-12 col-lg-5">
            <div class="panel h-100">
              <div class="panel-head">
                <h5>ความปลอดภัย</h5>
              </div>
              <div class="d-flex flex-column gap-3">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="fw-semibold">เปลี่ยนรหัสผ่าน</div>
                    <div class="small-muted">แนะนำให้เปลี่ยนรหัสผ่านเป็นประจำ</div>
                  </div>
                  <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="fa-solid fa-key me-1"></i> เปลี่ยนรหัส
                  </button>
                </div>
                <hr class="my-1">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="fw-semibold">ยืนยันตัวตน 2 ขั้นตอน (2FA)</div>
                    <div class="small-muted">สถานะระบบ: <?= $twofa_enabled_global ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?> (ทั้งระบบ)</div>
                  </div>
                  <div class="form-check form-switch" title="ยังไม่รองรับการตั้งค่า 2FA รายบุคคล">
                    <input class="form-check-input" type="checkbox" role="switch" id="2faSwitch" <?= $twofa_enabled_global ? 'checked' : '' ?> disabled>
                  </div>
                </div>
              </div>

              <h5 class="mt-4">ประวัติการเข้าสู่ระบบ</h5>
              <div class="table-responsive">
                <table class="table table-sm small">
                  <tbody>
                    <tr>
                      <td>
                        <i class="fa-solid fa-display me-2 text-muted"></i>เข้าสู่ระบบล่าสุด
                        <div class="small-muted ps-4"><?= htmlspecialchars($last_login_at ? date('Y-m-d H:i:s', strtotime($last_login_at)) : '-') ?></div>
                      </td>
                    </tr>
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

  <!-- Change Password Modal -->
  <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form id="passwordForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <div class="modal-header">
            <h5 class="modal-title" id="changePasswordModalLabel"><i class="fa-solid fa-key me-2"></i>เปลี่ยนรหัสผ่าน</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="oldPassword" class="form-label">รหัสผ่านปัจจุบัน</label>
              <input type="password" class="form-control" id="oldPassword" required>
            </div>
            <div class="mb-3">
              <label for="newPassword" class="form-label">รหัสผ่านใหม่</label>
              <input type="password" class="form-control" id="newPassword" required minlength="8">
            </div>
            <div class="mb-3">
              <label for="confirmPassword" class="form-label">ยืนยันรหัสผ่านใหม่</label>
              <input type="password" class="form-control" id="confirmPassword" required minlength="8">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-primary">บันทึกรหัสผ่านใหม่</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Toast -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
    <div id="liveToast" class="toast text-bg-dark border-0" role="status" aria-live="polite" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="ปิด"></button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const toastEl = document.getElementById('liveToast');
      const toastBody = toastEl.querySelector('.toast-body');
      const toast = new bootstrap.Toast(toastEl, { delay: 2500 });

      function showToast(msg){ toastBody.textContent = msg; toast.show(); }

      // บันทึกโปรไฟล์ (AJAX)
      document.getElementById('profileForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', 'update_profile');
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
        try {
          const res = await fetch(location.href, { method:'POST', body: fd });
          const data = await res.json();
          showToast(data.message || (data.ok ? 'บันทึกแล้ว' : 'ผิดพลาด'));
          if (data.ok && data.full_name) {
            // อัปเดตชื่อบนแอพบาร์แบบทันที
            document.querySelector('.nav-name').textContent = data.full_name;
          }
        } catch (err) { showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ'); }
      });

      // เปลี่ยนรหัสผ่าน (AJAX)
      document.getElementById('passwordForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const oldPassword = document.getElementById('oldPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;

        const fd = new FormData();
        fd.append('action', 'change_password');
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
        fd.append('oldPassword', oldPassword);
        fd.append('newPassword', newPassword);
        fd.append('confirmPassword', confirmPassword);

        try {
          const res = await fetch(location.href, { method:'POST', body: fd });
          const data = await res.json();
          showToast(data.message || (data.ok ? 'สำเร็จ' : 'ผิดพลาด'));
          if (data.ok) {
            const modal = bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'));
            modal.hide();
            e.target.reset();
          }
        } catch (err) { showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ'); }
      });

      // 2FA switch — disabled (แสดงสถานะระบบเท่านั้น)
      const s2fa = document.getElementById('2faSwitch');
      s2fa?.addEventListener('click', (ev) => {
        showToast('ยังไม่รองรับการตั้งค่า 2FA รายบุคคล');
        ev.preventDefault();
      });
    });
  </script>
</body>
</html>
