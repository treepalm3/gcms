<?php
// admin/profile.php (เวอร์ชันเชื่อม DB ตามสคีมาปัจจุบัน)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอิน =====
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
  exit();
}

/* ========== เชื่อมฐานข้อมูล ========== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}

// ใช้ throw exceptions ถ้า config/db.php ไม่ได้ตั้งไว้
try { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Throwable $e) {}

/* ========== ตรวจสิทธิ์ ========== */
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

/* ===== Helpers: ตรวจคอลัมน์ ===== */
function column_exists(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c
  ");
  $stmt->execute([':t'=>$table, ':c'=>$col]);
  return (int)$stmt->fetchColumn() > 0;
}

function table_exists(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = :t
  ");
  $stmt->execute([':t'=>$table]);
  return (int)$stmt->fetchColumn() > 0;
}

// ดึง station_id จากตาราง settings (ถ้าไม่มีให้เป็น 1)
function get_station_id(PDO $pdo): int {
  try {
    $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1")->fetchColumn();
    return (int)($v ?: 1);
  } catch (Throwable $e) { return 1; }
}
$DEFAULT_STATION_ID = get_station_id($pdo);

/* ========== โหลดค่าพื้นฐานจาก DB ========== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
try {
  // ใช้ app_settings.key='system_settings' ซึ่งมี site_name/site_subtitle จริง
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $sys = json_decode($r['json_value'], true) ?: [];
    if (!empty($sys['site_name']))     $site_name = $sys['site_name'];
    if (!empty($sys['site_subtitle'])) $site_subtitle = $sys['site_subtitle'];
  }
} catch(Throwable $e){}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';

/* ========== ตรวจคอลัมน์จริง/ที่ใช้จริง ========== */
$HAS_USERS_LASTLOGIN = column_exists($pdo, 'users', 'last_login_at')
  ? 'last_login_at' : (column_exists($pdo, 'users', 'last_login') ? 'last_login' : null);
$PWD_COL = column_exists($pdo, 'users', 'password_hash') ? 'password_hash'
  : (column_exists($pdo, 'users', 'password') ? 'password' : null);
if ($PWD_COL === null) { die('ตาราง users ไม่มีคอลัมน์รหัสผ่านที่รองรับ (password_hash/password)'); }

// NEW/CHANGED: ใช้ admins เป็นแหล่งของ address/house_number
$HAS_ADMINS = table_exists($pdo, 'admins');
$HAS_ADMIN_ADDRESS = $HAS_ADMINS && column_exists($pdo, 'admins', 'address');
$HAS_ADMIN_HOUSE   = $HAS_ADMINS && column_exists($pdo, 'admins', 'house_number');

/* ========== ดึงข้อมูลผู้ใช้ปัจจุบัน (join กับ admins) ========== */
$user_data = [
  'id'=>null, 'username'=>'', 'full_name'=>'', 'email'=>'', 'phone'=>'',
  'created_at'=>null, 'updated_at'=>null, 'last_login_at'=>null, 'role'=>'admin',
  'address'=>'', 'house_number'=>'', 'station_id'=>$DEFAULT_STATION_ID
];

try {
  $select = [
    "u.id","u.username","u.full_name","u.email","u.phone",
    "u.created_at","u.updated_at","u.role"
  ];
  if ($HAS_USERS_LASTLOGIN) $select[] = "u.{$HAS_USERS_LASTLOGIN} AS last_login_at";

  $joinAdmins = '';
  if ($HAS_ADMINS) {
    if ($HAS_ADMIN_ADDRESS) $select[] = "a.address AS admin_address";
    if ($HAS_ADMIN_HOUSE)   $select[] = "a.house_number AS admin_house_number";
    $select[] = "a.station_id AS admin_station_id";
    $joinAdmins = " LEFT JOIN admins a ON a.user_id = u.id ";
  }

  $sql = "SELECT ".implode(',', $select)." FROM users u {$joinAdmins}
          WHERE u.id = :id AND u.role='admin' LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':id'=>$_SESSION['user_id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    header('Location: /index/login.php?err=ไม่พบข้อมูลผู้ใช้');
    exit();
  }

  // รวมข้อมูลจาก users
  $user_data = array_merge($user_data, $row);

  // NEW/CHANGED: map ข้อมูลจาก admins
  if ($HAS_ADMINS) {
    if ($HAS_ADMIN_ADDRESS) $user_data['address'] = $row['admin_address'] ?? '';
    if ($HAS_ADMIN_HOUSE)   $user_data['house_number'] = $row['admin_house_number'] ?? '';
    if (isset($row['admin_station_id'])) $user_data['station_id'] = (int)$row['admin_station_id'];
  }
} catch (Throwable $e) {
  $error_msg = 'เกิดข้อผิดพลาดในการดึงข้อมูล';
}

/* ========== จัดการการส่งข้อมูล ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ตรวจสอบ CSRF token
  if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $error_msg = 'การตรวจสอบความปลอดภัยล้มเหลว';
  } else {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
      $full_name = trim($_POST['full_name'] ?? '');
      $email     = trim($_POST['email'] ?? '');
      $phone     = trim($_POST['phone'] ?? '');
    
      // NEW/CHANGED: รับเลขที่บ้าน/ที่อยู่จากฟอร์ม (ไปลง admins)
      $house_number = trim($_POST['house_number'] ?? '');
      $address      = trim($_POST['address'] ?? '');
    
      if ($full_name === '') {
        $error_msg = 'กรุณากรอกชื่อ-นามสกุล';
      } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'รูปแบบอีเมลไม่ถูกต้อง';
      } else {
        try {
          $pdo->beginTransaction();
    
          // อัปเดต users
          $sets = ["full_name = :full_name", "email = :email", "phone = :phone", "updated_at = NOW()"];
          $params = [
            ':full_name'=>$full_name,
            ':email'=>$email !== '' ? $email : null,
            ':phone'=>$phone !== '' ? $phone : null,
            ':id'=>$_SESSION['user_id']
          ];
          $sql = "UPDATE users SET ".implode(', ', $sets)." WHERE id = :id AND role = 'admin'";
          $pdo->prepare($sql)->execute($params);
    
          // NEW/CHANGED: upsert -> admins
          if ($HAS_ADMINS) {
            $exists = $pdo->prepare("SELECT id FROM admins WHERE user_id = :id LIMIT 1");
            $exists->execute([':id'=>$_SESSION['user_id']]);
            $admin_id = $exists->fetchColumn();
    
            if ($admin_id) {
              $pdo->prepare("UPDATE admins 
                             SET house_number = :house, address = :addr 
                             WHERE user_id = :id")
                  ->execute([
                    ':house' => ($house_number !== '' ? $house_number : null),
                    ':addr'  => ($address !== '' ? $address : null),
                    ':id'    => $_SESSION['user_id']
                  ]);
            } else {
              $pdo->prepare("INSERT INTO admins (user_id, station_id, house_number, address, comment)
                             VALUES (:id, :station, :house, :addr, '')")
                  ->execute([
                    ':id'      => $_SESSION['user_id'],
                    ':station' => $DEFAULT_STATION_ID,
                    ':house'   => ($house_number !== '' ? $house_number : null),
                    ':addr'    => ($address !== '' ? $address : null)
                  ]);
            }
          }
    
          $pdo->commit();
    
          // อัปเดต session display name
          $_SESSION['full_name'] = $full_name;
    
          // อ่านข้อมูลใหม่ (join admins อีกครั้ง)
          $select = "u.id,u.username,u.full_name,u.email,u.phone,u.created_at,u.updated_at,u.role";
          if ($HAS_USERS_LASTLOGIN) $select .= ",u.{$HAS_USERS_LASTLOGIN} AS last_login_at";
          $select .= $HAS_ADMINS ? 
            ( ($HAS_ADMIN_ADDRESS ? ",a.address AS admin_address" : "")
             .($HAS_ADMIN_HOUSE ? ",a.house_number AS admin_house_number" : "")
             .",a.station_id AS admin_station_id" ) : "";
          $re = $pdo->prepare("SELECT {$select} FROM users u ".($HAS_ADMINS?"LEFT JOIN admins a ON a.user_id=u.id ":"")." WHERE u.id=:id AND u.role='admin' LIMIT 1");
          $re->execute([':id'=>$_SESSION['user_id']]);
          $user_data = $re->fetch(PDO::FETCH_ASSOC) ?: $user_data;
          if ($HAS_ADMINS) {
            if ($HAS_ADMIN_ADDRESS) $user_data['address'] = $user_data['admin_address'] ?? '';
            if ($HAS_ADMIN_HOUSE)   $user_data['house_number'] = $user_data['admin_house_number'] ?? '';
            if (isset($user_data['admin_station_id'])) $user_data['station_id'] = (int)$user_data['admin_station_id'];
          }
    
          $success_msg = 'อัปเดตข้อมูลโปรไฟล์เรียบร้อยแล้ว';
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $error_msg = 'เกิดข้อผิดพลาดในการอัปเดตข้อมูล';
        }
      }
    
    } elseif ($action === 'change_password') {
      $current_password = $_POST['current_password'] ?? '';
      $new_password     = $_POST['new_password'] ?? '';
      $confirm_password = $_POST['confirm_password'] ?? '';

      if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $error_msg = 'กรุณากรอกข้อมูลให้ครบถ้วน';
      } elseif ($new_password !== $confirm_password) {
        $error_msg = 'รหัสผ่านใหม่และการยืนยันไม่ตรงกัน';
      } elseif (strlen($new_password) < 6) {
        $error_msg = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
      } else {
        try {
          // ตรวจรหัสผ่านเดิม
          $stmt = $pdo->prepare("SELECT {$PWD_COL} FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
          $stmt->execute([':id'=>$_SESSION['user_id']]);
          $stored_hash = $stmt->fetchColumn();

          if ($stored_hash && password_verify($current_password, $stored_hash)) {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET {$PWD_COL} = :h, updated_at = NOW() WHERE id = :id AND role = 'admin'");
            $stmt->execute([':h'=>$new_hash, ':id'=>$_SESSION['user_id']]);
            $success_msg = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
          } else {
            $error_msg = 'รหัสผ่านเดิมไม่ถูกต้อง';
          }
        } catch (Throwable $e) {
          $error_msg = 'เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน';
        }
      }
    }
  }
}

// อัพเดท avatar text หลังจากอาจเปลี่ยนชื่อ
$current_name = $user_data['full_name'] ?? 'ผู้ดูแลระบบ';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($site_name) ?> - โปรไฟล์ผู้ดูแลระบบ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <style>
    .profile-section{background:#fff;border-radius:15px;box-shadow:0 4px 6px rgba(0,0,0,.1);padding:2rem;margin-bottom:2rem}
    .profile-avatar{width:120px;height:120px;border-radius:50%;background:linear-gradient(135deg,#dc3545 0%,#fd7e14 100%);display:flex;align-items:center;justify-content:center;color:#fff;font-size:3rem;font-weight:700;margin:0 auto 1rem;box-shadow:0 4px 15px rgba(220,53,69,.3)}
    .info-row{padding:.75rem 0;border-bottom:1px solid #f0f0f0;display:flex;align-items:center}
    .info-row:last-child{border-bottom:none}
    .info-label{font-weight:600;color:#495057;min-width:120px}
    .info-value{color:#6c757d}
    .form-section{background:#fff;border-radius:15px;box-shadow:0 4px 6px rgba(0,0,0,.1);padding:2rem;margin-bottom:2rem}
    .section-title{color:#495057;font-weight:600;margin-bottom:1.5rem;padding-bottom:.5rem;border-bottom:2px solid #e9ecef}
    .btn-custom{border-radius:8px;padding:.5rem 1.5rem;font-weight:500}
    .alert-custom{border-radius:10px;border:none}
    .admin-badge{background:linear-gradient(135deg,#dc3545 0%,#fd7e14 100%);color:#fff;padding:.25rem .75rem;border-radius:20px;font-size:.85rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
    .privilege-section{background:linear-gradient(135deg,#fff3cd 0%,#ffeaa7 100%);border:1px solid #ffeaa7;border-radius:10px;padding:1rem;margin-bottom:1rem}
    .privilege-title{color:#856404;font-weight:600;margin-bottom:.5rem}
    .privilege-text{color:#664d03;font-size:.9rem}
  </style>
</head>

<body>
  <!-- Navbar -->
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
  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu">
        <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout mt-auto" href="../index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <div class="col-md-3 col-lg-2 d-none d-md-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
          <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
          <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
          <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
          <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
          <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
        </nav>
        <a class="logout" href="../index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </div>

      <!-- Content -->
      <main class="col-md-9 col-lg-10 p-4 fade-in">
        <div class="main-header"><h2><i class="bi bi-person-gear me-2"></i>โปรไฟล์ผู้ดูแลระบบ</h2></div>

        <!-- Alert Messages -->
        <?php if (!empty($error_msg)): ?>
          <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        <?php if (!empty($success_msg)): ?>
          <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <div class="row">
          <!-- ข้อมูลโปรไฟล์ -->
          <div class="col-lg-4">
            <div class="profile-section">
              <div class="text-center">
                <div class="profile-avatar"><?= htmlspecialchars(mb_substr($user_data['full_name'] ?? 'ผ', 0, 1, 'UTF-8')) ?></div>
                <h4 class="mb-1"><?= htmlspecialchars($user_data['full_name'] ?? 'ไม่ระบุ') ?></h4>
                <div class="admin-badge mb-3">ADMINISTRATOR</div>
              </div>

              <div class="privilege-section">
                <div class="privilege-title"><i class="bi bi-shield-fill-check me-1"></i>สิทธิ์ผู้ดูแลระบบ</div>
                <div class="privilege-text">คุณมีสิทธิ์เข้าถึงและจัดการระบบทั้งหมด รวมถึงการตั้งค่า จัดการผู้ใช้ และดูรายงานทุกประเภท</div>
              </div>

              <div class="info-row">
                <span class="info-label"><i class="bi bi-person-badge me-2"></i>ชื่อผู้ใช้:</span>
                <span class="info-value"><?= htmlspecialchars($user_data['username'] ?? 'ไม่ระบุ') ?></span>
              </div>

              <div class="info-row">
                <span class="info-label"><i class="bi bi-envelope me-2"></i>อีเมล:</span>
                <span class="info-value"><?= htmlspecialchars($user_data['email'] ?? 'ไม่ระบุ') ?></span>
              </div>

              <div class="info-row">
                <span class="info-label"><i class="bi bi-telephone me-2"></i>เบอร์โทร:</span>
                <span class="info-value"><?= htmlspecialchars($user_data['phone'] ?? 'ไม่ระบุ') ?></span>
              </div>

              <div class="info-row">
                <span class="info-label"><i class="bi bi-calendar-plus me-2"></i>สร้างบัญชีเมื่อ:</span>
                <span class="info-value">
                  <?= !empty($user_data['created_at']) ? date('d/m/Y', strtotime($user_data['created_at'])) : 'ไม่ระบุ' ?>
                </span>
              </div>

              <div class="info-row">
                <span class="info-label"><i class="bi bi-clock-history me-2"></i>เข้าสู่ระบบล่าสุด:</span>
                <span class="info-value">
                  <?= !empty($user_data['last_login_at']) ? date('d/m/Y H:i', strtotime($user_data['last_login_at'])) : 'ไม่ระบุ' ?>
                </span>
              </div>
            </div>
          </div>

          <!-- ฟอร์มแก้ไขข้อมูล -->
          <div class="col-lg-8">
            <!-- แก้ไขข้อมูลส่วนตัว -->
<div class="form-section">
  <h5 class="section-title"><i class="bi bi-person-fill-gear me-2"></i>แก้ไขข้อมูลส่วนตัว</h5>
  <?php if(!$HAS_ADMINS): ?>
    <div class="alert alert-warning">
      ไม่พบตาราง <code>admins</code> ในฐานข้อมูล ฟิลด์ “ที่อยู่/เลขที่บ้าน” จะไม่ถูกบันทึก
    </div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="update_profile">

    <div class="row">
      <div class="col-md-6 mb-3">
        <label for="full_name" class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="full_name" name="full_name"
               value="<?= htmlspecialchars($user_data['full_name'] ?? '') ?>" required>
      </div>

      <div class="col-md-6 mb-3">
        <label for="email" class="form-label">อีเมล</label>
        <input type="email" class="form-control" id="email" name="email"
               value="<?= htmlspecialchars($user_data['email'] ?? '') ?>">
      </div>
    </div>

    <div class="row">
      <div class="col-md-6 mb-3">
        <label for="phone" class="form-label">เบอร์โทรศัพท์</label>
        <input type="text" class="form-control" id="phone" name="phone"
               value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>">
      </div>

      <div class="col-md-6 mb-3">
        <label for="username_display" class="form-label">ชื่อผู้ใช้</label>
        <input type="text" class="form-control" id="username_display"
               value="<?= htmlspecialchars($user_data['username'] ?? '') ?>" disabled>
        <div class="form-text">ไม่สามารถเปลี่ยนชื่อผู้ใช้ได้</div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-6 mb-3">
        <label for="house_number" class="form-label">เลขที่บ้าน</label>
        <input type="text" class="form-control" id="house_number" name="house_number"
               value="<?= htmlspecialchars($user_data['house_number'] ?? '') ?>" <?= $HAS_ADMINS ? '' : 'disabled' ?>>
      </div>
      <div class="col-md-6 mb-3">
        <label for="station_id" class="form-label">สถานี (อ่านอย่างเดียว)</label>
        <input type="text" class="form-control" id="station_id"
               value="<?= htmlspecialchars((string)($user_data['station_id'] ?? $DEFAULT_STATION_ID)) ?>" disabled>
      </div>
    </div>

    <div class="mb-3">
      <label for="address" class="form-label">ที่อยู่</label>
      <textarea class="form-control" id="address" name="address" rows="3" <?= $HAS_ADMINS ? '' : 'disabled' ?>><?= htmlspecialchars($user_data['address'] ?? '') ?></textarea>
      <?php if (!$HAS_ADMINS): ?>
        <div class="form-text text-warning">
          ไม่พบตาราง <code>admins</code> ฟิลด์นี้จะไม่ถูกบันทึก
        </div>
      <?php endif; ?>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary btn-custom">
        <i class="bi bi-check-lg me-1"></i>บันทึกการเปลี่ยนแปลง
      </button>
      <button type="reset" class="btn btn-outline-secondary btn-custom">
        <i class="bi bi-arrow-clockwise me-1"></i>รีเซ็ต
      </button>
    </div>
  </form>
</div>

            <!-- เปลี่ยนรหัสผ่าน -->
            <div class="form-section">
              <h5 class="section-title"><i class="bi bi-shield-lock me-2"></i>เปลี่ยนรหัสผ่าน</h5>

              <div class="alert alert-warning" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>คำเตือน:</strong> กรุณาใช้รหัสผ่านที่แข็งแรงและไม่เปิดเผยให้ผู้อื่น
              </div>

              <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="row">
                  <div class="col-md-4 mb-3">
                    <label for="current_password" class="form-label">รหัสผ่านปัจจุบัน <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                  </div>

                  <div class="col-md-4 mb-3">
                    <label for="new_password" class="form-label">รหัสผ่านใหม่ <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="new_password" name="new_password" minlength="6" required>
                    <div class="form-text">อย่างน้อย 6 ตัวอักษร แนะนำให้ใช้ตัวอักษรผสมตัวเลขและสัญลักษณ์</div>
                  </div>

                  <div class="col-md-4 mb-3">
                    <label for="confirm_password" class="form-label">ยืนยันรหัสผ่านใหม่ <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="6" required>
                  </div>
                </div>

                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-warning btn-custom">
                    <i class="bi bi-key me-1"></i>เปลี่ยนรหัสผ่าน
                  </button>
                  <button type="reset" class="btn btn-outline-secondary btn-custom">
                    <i class="bi bi-arrow-clockwise me-1"></i>ล้างข้อมูล
                  </button>
                </div>
              </form>
            </div>

            <!-- ข้อมูลเพิ่มเติม -->
            <div class="form-section">
              <h5 class="section-title"><i class="bi bi-info-circle me-2"></i>ข้อมูลการใช้งาน</h5>
              <div class="row">
                <div class="col-md-6">
                  <div class="card border-primary">
                    <div class="card-body text-center">
                      <i class="bi bi-calendar-check text-primary" style="font-size:2rem;"></i>
                      <h6 class="card-title mt-2">สถานะบัญชี</h6>
                      <span class="badge bg-success">ใช้งานอยู่</span>
                    </div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="card border-info">
                    <div class="card-body text-center">
                      <i class="bi bi-shield-fill-check text-info" style="font-size:2rem;"></i>
                      <h6 class="card-title mt-2">ระดับสิทธิ์</h6>
                      <span class="badge bg-danger">SUPER ADMIN</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div><!-- /col-lg-8 -->
        </div><!-- /row -->
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> - <?= htmlspecialchars($site_subtitle) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ตรวจสอบรหัสผ่านใหม่ตรงกัน
    document.getElementById('confirm_password').addEventListener('input', function() {
      const newPassword = document.getElementById('new_password').value;
      const confirmPassword = this.value;
      this.setCustomValidity(newPassword !== confirmPassword ? 'รหัสผ่านไม่ตรงกัน' : '');
    });

    // ซ่อน alert หลัง 5 วิ
    setTimeout(function() {
      document.querySelectorAll('.alert.alert-dismissible').forEach(function(el){
        new bootstrap.Alert(el).close();
      });
    }, 5000);
  </script>
</body>
</html>
