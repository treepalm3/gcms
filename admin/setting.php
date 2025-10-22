<?php
// setting.php — ตั้งค่าระบบสหกรณ์
session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo

// ===== Helpers สำหรับโหลด/บันทึก settings แบบ JSON ในตาราง app_settings =====
function load_settings(PDO $pdo, string $key, array $defaults): array {
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`=:k LIMIT 1");
  $st->execute([':k'=>$key]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return $defaults;

  $data = json_decode($row['json_value'] ?? '{}', true);
  if (!is_array($data)) $data = [];

  // merge defaults -> data (ค่าไหนหายไปใช้ของเดิม)
  return array_replace_recursive($defaults, $data);
}

function save_settings(PDO $pdo, string $key, array $data): bool {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $st = $pdo->prepare("
    INSERT INTO app_settings (`key`, json_value) VALUES (:k, CAST(:v AS JSON))
    ON DUPLICATE KEY UPDATE json_value = CAST(:v AS JSON)
  ");
  return $st->execute([':k'=>$key, ':v'=>$json]);
}

// ดึง users จริงจากฐานข้อมูล
function fetch_users(PDO $pdo): array {
  // ปรับชื่อคอลัมน์ได้ตามสคีมาของคุณ
  $sql = "
    SELECT id, username, full_name, email, role, status,
           COALESCE(last_login, created_at) AS last_login,
           COALESCE(created_at, created_date) AS created_date
    FROM users
    ORDER BY id ASC
  ";
  try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    // ทำความสะอาดค่าที่จำเป็น
    foreach ($rows as &$r) {
      $r['status'] = $r['status'] ?: 'active';
      $r['last_login'] = $r['last_login'] ?: date('Y-m-d H:i:s');
      $r['created_date'] = $r['created_date'] ?: date('Y-m-d');
    }
    return $rows;
  } catch (Throwable $e) {
    // ถ้าตารางหรือคอลัมน์ต่างชื่อ ให้แก้ SQL ด้านบนตามจริง
    return [];
  }
}

// ค่าเริ่มต้น (ใช้เมื่อ DB ยังไม่มีค่า หรือคีย์บางตัวไม่มี)
$system_defaults = [
  'site_name' => 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง',
  'site_subtitle' => 'ระบบบริหารจัดการปั๊มน้ำมัน',
  'contact_phone' => '02-123-4567',
  'contact_email' => 'info@coop-fuel.com',
  'address' => '123 ถนนมิตรภาพ ตำบลในเมือง อำเภอเมือง จังหวัดขอนแก่น 40000',
  'tax_id' => '1234567890123',
  'registration_number' => 'สหกรณ์ที่ 12345',
  'timezone' => 'Asia/Bangkok',
  'currency' => 'THB',
  'date_format' => 'd/m/Y',
  'language' => 'th'
];
$notification_defaults = [
  'low_stock_alert' => true,
  'low_stock_threshold' => 1000,
  'daily_report_email' => true,
  'maintenance_reminder' => true,
  'payment_alerts' => true,
  'email_notifications' => true,
  'sms_notifications' => false,
  'line_notifications' => true
];
$security_defaults = [
  'session_timeout' => 60,
  'max_login_attempts' => 5,
  'password_min_length' => 8,
  'require_special_chars' => true,
  'two_factor_auth' => false,
  'ip_whitelist_enabled' => false,
  'audit_log_enabled' => true,
  'backup_frequency' => 'daily'
];
$fuel_price_defaults = [
  'auto_price_update' => false,
  'price_source' => 'manual',
  'price_update_time' => '06:00',
  'markup_percentage' => 2.5,
  'round_to_satang' => 25
];

// โหลดค่าจริงจาก DB (ถ้าไม่มีจะได้ defaults)
$system_settings        = load_settings($pdo, 'system_settings',        $system_defaults);
$notification_settings  = load_settings($pdo, 'notification_settings',  $notification_defaults);
$security_settings      = load_settings($pdo, 'security_settings',      $security_defaults);
$fuel_price_settings    = load_settings($pdo, 'fuel_price_settings',    $fuel_price_defaults);

// ดึง users จริง
$users_list = fetch_users($pdo);

// ใช้ชื่อไซต์จาก system_settings
$site_name     = $system_settings['site_name'] ?? 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = $system_settings['site_subtitle'] ?? 'ระบบบริหารจัดการปั๊มน้ำมัน';


// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
  $current_name = $_SESSION['full_name'] ?: 'ผู้ดูแลระบบ';
  $current_role = $_SESSION['role'];
  if($current_role!=='admin'){
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
try {
    // ผู้ใช้ปัจจุบัน
    if (isset($_SESSION['user_id'])) {
        $st = $pdo->prepare("SELECT full_name, role FROM users WHERE id=:id LIMIT 1");
        $st->execute([':id' => $_SESSION['user_id']]);
        if ($u = $st->fetch()) {
            $current_name = $u['full_name'] ?: $current_name;
            $role = $u['role'] ?: 'guest';
        }
    }
} catch (Throwable $e) {}

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
  <title>ตั้งค่าระบบ | สหกรณ์ปั๊มน้ำมัน</title>

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

  <!-- Global theme -->
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .setting-section {
      border: 1px solid #e9ecef;
      border-radius: 12px;
      background: #fff;
      margin-bottom: 20px;
    }
    .setting-header {
      background: #f8f9fa;
      border-radius: 12px 12px 0 0;
      padding: 15px 20px;
      border-bottom: 1px solid #e9ecef;
    }
    .setting-body {
      padding: 20px;
    }
    .setting-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid #f1f3f4;
    }
    .setting-item:last-child {
      border-bottom: none;
    }
    .switch {
      position: relative;
      display: inline-block;
      width: 50px;
      height: 24px;
    }
    .switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }
    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: #ccc;
      transition: .4s;
      border-radius: 24px;
    }
    .slider:before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      left: 3px;
      bottom: 3px;
      background-color: white;
      transition: .4s;
      border-radius: 50%;
    }
    input:checked + .slider {
      background-color: #0d6efd;
    }
    input:checked + .slider:before {
      transform: translateX(26px);
    }
    .status-active {
      color: #198754;
      font-weight: 600;
    }
    .status-inactive {
      color: #dc3545;
      font-weight: 600;
    }
    .last-backup {
      color: #6c757d;
      font-size: 0.9rem;
    }
    .nav-tabs .nav-link {
      color: var(--steel);
      font-weight: 600;
    }
    .nav-tabs .nav-link.active { 
      color: var(--navy); 
      border-color: var(--border) var(--border) #fff; 
      border-bottom-width: 2px; 
    }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button"
              data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"
              aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
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

<!-- Offcanvas Sidebar (มือถือ/แท็บเล็ต) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2">
        <h3><span>Admin</span></h3>
      </div>
    <nav class="sidebar-menu">
      <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
      <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
      <a class="active" href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่าระบบ</a>
    </nav>
    <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
        <a class="active" href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <!-- Content -->
    <main class="col-lg-10 p-4">
      <div class="main-header">
        <h2><i class="bi bi-gear-fill"></i> ตั้งค่าระบบ</h2>
      </div>

      <!-- Tab Navigation -->
      <ul class="nav nav-tabs mb-4" id="settingTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general-panel" type="button" role="tab">
            <i class="bi bi-gear-fill me-2"></i>ทั่วไป
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="users-tab" data-bs-toggle="tab" data-bs-target="#users-panel" type="button" role="tab">
            <i class="bi bi-people-fill me-2"></i>ผู้ใช้งาน
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications-panel" type="button" role="tab">
            <i class="bi bi-bell-fill me-2"></i>การแจ้งเตือน
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security-panel" type="button" role="tab">
            <i class="bi bi-shield-lock-fill me-2"></i>ความปลอดภัย
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="backup-tab" data-bs-toggle="tab" data-bs-target="#backup-panel" type="button" role="tab">
            <i class="bi bi-cloud-download me-2"></i>สำรอง
          </button>
        </li>
      </ul>

      <!-- Tab Content -->
      <div class="tab-content" id="settingTabContent">
        <!-- General Settings Panel -->
        <div class="tab-pane fade show active" id="general-panel" role="tabpanel">
          <div class="row g-4">
            <!-- Site Information -->
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-building me-2"></i>ข้อมูลสหกรณ์</h6>
                </div>
                <div class="setting-body">
                  <form id="siteInfoForm">
                    <div class="mb-3">
                      <label class="form-label">ชื่อสหกรณ์</label>
                      <input type="text" class="form-control" value="<?= htmlspecialchars($system_settings['site_name']) ?>">
                    </div>
                    <div class="mb-3">
                      <label class="form-label">คำอธิบาย</label>
                      <input type="text" class="form-control" value="<?= htmlspecialchars($system_settings['site_subtitle']) ?>">
                    </div>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">เบอร์โทร</label>
                        <input type="tel" class="form-control" value="<?= htmlspecialchars($system_settings['contact_phone']) ?>">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">อีเมล</label>
                        <input type="email" class="form-control" value="<?= htmlspecialchars($system_settings['contact_email']) ?>">
                      </div>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">ที่อยู่</label>
                      <textarea class="form-control" rows="3"><?= htmlspecialchars($system_settings['address']) ?></textarea>
                    </div>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">เลขประจำตัวผู้เสียภาษี</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($system_settings['tax_id']) ?>">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">เลขที่ทะเบียนสหกรณ์</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($system_settings['registration_number']) ?>">
                      </div>
                    </div>
                    <div class="mt-3">
                      <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> บันทึกข้อมูล
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- System Preferences -->
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-sliders me-2"></i>การตั้งค่าระบบ</h6>
                </div>
                <div class="setting-body">
                  <form id="systemPrefsForm">
                    <div class="mb-3">
                      <label class="form-label">เขตเวลา</label>
                      <select class="form-select">
                        <option value="Asia/Bangkok" selected>Asia/Bangkok (GMT+7)</option>
                        <option value="UTC">UTC (GMT+0)</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">สกุลเงิน</label>
                      <select class="form-select">
                        <option value="THB" selected>บาทไทย (THB)</option>
                        <option value="USD">ดอลลาร์สหรัฐ (USD)</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">รูปแบบวันที่</label>
                      <select class="form-select">
                        <option value="d/m/Y" selected>วว/ดด/ปปปป</option>
                        <option value="Y-m-d">ปปปป-ดด-วว</option>
                        <option value="m/d/Y">ดด/วว/ปปปป</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">ภาษา</label>
                      <select class="form-select">
                        <option value="th" selected>ไทย</option>
                        <option value="en">English</option>
                      </select>
                    </div>
                    <div class="mt-3">
                      <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> บันทึกการตั้งค่า
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- Fuel Price Settings -->
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-fuel-pump-fill me-2"></i>การตั้งค่าราคาน้ำมัน</h6>
                </div>
                <div class="setting-body">
                  <div class="setting-item">
                    <div>
                      <strong>อัปเดตราคาอัตโนมัติ</strong>
                      <div class="text-muted small">ดึงราคาจากแหล่งข้อมูลภายนอก</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $fuel_price_settings['auto_price_update'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="row g-3 mt-2">
                    <div class="col-md-6">
                      <label class="form-label">แหล่งข้อมูลราคา</label>
                      <select class="form-select">
                        <option value="manual" selected>กำหนดเอง</option>
                        <option value="ptt">PTT API</option>
                        <option value="bangchak">บางจาก API</option>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">เวลาอัปเดต</label>
                      <input type="time" class="form-control" value="<?= htmlspecialchars($fuel_price_settings['price_update_time']) ?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">มาร์กอัป (%)</label>
                      <input type="number" class="form-control" step="0.1" value="<?= htmlspecialchars($fuel_price_settings['markup_percentage']) ?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">ปัดเศษ (สตางค์)</label>
                      <select class="form-select">
                        <option value="1">1 สตางค์</option>
                        <option value="5">5 สตางค์</option>
                        <option value="25" selected>25 สตางค์</option>
                        <option value="50">50 สตางค์</option>
                      </select>
                    </div>
                  </div>
                  <div class="mt-3">
                    <button class="btn btn-primary">
                      <i class="bi bi-save me-1"></i> บันทึก
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Users Management Panel -->
        <div class="tab-pane fade" id="users-panel" role="tabpanel">
          <div class="setting-section">
            <div class="setting-header d-flex justify-content-between align-items-center">
              <h6 class="mb-0"><i class="bi bi-people-fill me-2"></i>จัดการผู้ใช้งาน</h6>
              <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddUser">
                <i class="bi bi-plus me-1"></i> เพิ่มผู้ใช้
              </button>
            </div>
            <div class="setting-body">
              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th>ผู้ใช้งาน</th>
                      <th>อีเมล</th>
                      <th>บทบาท</th>
                      <th>สถานะ</th>
                      <th>เข้าใช้ล่าสุด</th>
                      <th class="text-end">จัดการ</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($users_list as $user): ?>
                    <tr>
                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <div class="rounded-circle d-inline-flex align-items-center justify-content-center"
                               style="width:32px;height:32px;background:#0d6efd;color:white;font-weight:700;font-size:0.9rem;">
                            <?= htmlspecialchars(mb_substr($user['full_name'], 0, 1, 'UTF-8')) ?>
                          </div>
                          <div>
                            <div class="fw-semibold"><?= htmlspecialchars($user['full_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($user['username']) ?></small>
                          </div>
                        </div>
                      </td>
                      <td><?= htmlspecialchars($user['email']) ?></td>
                      <td>
                        <?php
                        $role_badges = [
                          'admin' => 'danger',
                          'manager' => 'warning',
                          'employee' => 'primary'
                        ];
                        $badge_class = $role_badges[$user['role']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $badge_class ?>"><?= $role_th_map[$user['role']] ?? $user['role'] ?></span>
                      </td>
                      <td>
                        <span class="<?= $user['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                          <?= $user['status'] === 'active' ? 'ใช้งาน' : 'ปิดใช้งาน' ?>
                        </span>
                      </td>
                      <td><?= date('d/m/Y H:i', strtotime($user['last_login'])) ?></td>
                      <td class="text-end">
                        <div class="btn-group">
                          <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?= $user['id'] ?>)">
                            <i class="bi bi-pencil"></i>
                          </button>
                          <?php if($user['role'] !== 'admin'): ?>
                          <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?= $user['id'] ?>)">
                            <i class="bi bi-trash"></i>
                          </button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Notifications Panel -->
        <div class="tab-pane fade" id="notifications-panel" role="tabpanel">
          <div class="row g-4">
            <!-- Alert Settings -->
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>การแจ้งเตือน</h6>
                </div>
                <div class="setting-body">
                  <div class="setting-item">
                    <div>
                      <strong>แจ้งเตือนสต็อกต่ำ</strong>
                      <div class="text-muted small">แจ้งเตือนเมื่อน้ำมันใกล้หมด</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $notification_settings['low_stock_alert'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>รายงานประจำวัน</strong>
                      <div class="text-muted small">ส่งรายงานยอดขายทางอีเมล</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $notification_settings['daily_report_email'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>เตือนบำรุงรักษา</strong>
                      <div class="text-muted small">แจ้งเตือนกำหนดการบำรุงรักษาปั๊ม</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $notification_settings['maintenance_reminder'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>การแจ้งเตือนการชำระเงิน</strong>
                      <div class="text-muted small">แจ้งเตือนการชำระและค้างชำระ</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $notification_settings['payment_alerts'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="mt-3">
                    <label class="form-label">ขีดจำกัดสต็อกต่ำ (ลิตร)</label>
                    <input type="number" class="form-control" value="<?= $notification_settings['low_stock_threshold'] ?>">
                  </div>
                </div>
              </div>
            </div>

            <!-- Communication Channels -->
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-chat-dots me-2"></i>ช่องทางการแจ้งเตือน</h6>
                </div>
                <div class="setting-body">
                  <div class="setting-item">
                    <div>
                      <strong>อีเมล</strong>
                      <div class="text-muted small">ส่งการแจ้งเตือนทางอีเมล</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $notification_settings['email_notifications'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>SMS</strong>
                      <div class="text-muted small">ส่งข้อความสั้น</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $notification_settings['sms_notifications'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>Line Notify</strong>
                      <div class="text-muted small">ส่งการแจ้งเตือนผ่าน Line</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $notification_settings['line_notifications'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="mt-3">
                    <button class="btn btn-outline-primary">
                      <i class="bi bi-send me-1"></i> ทดสอบการแจ้งเตือน
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Security Panel -->
        <div class="tab-pane fade" id="security-panel" role="tabpanel">
          <div class="row g-4">
            <!-- Authentication Settings -->
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-key me-2"></i>การรักษาความปลอดภัย</h6>
                </div>
                <div class="setting-body">
                  <div class="mb-3">
                    <label class="form-label">ระยะเวลา Session (นาที)</label>
                    <input type="number" class="form-control" value="<?= $security_settings['session_timeout'] ?>" min="5" max="480">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">จำนวนครั้งที่พยายามเข้าสู่ระบบ</label>
                    <input type="number" class="form-control" value="<?= $security_settings['max_login_attempts'] ?>" min="3" max="10">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">ความยาวรหัสผ่านขั้นต่ำ</label>
                    <input type="number" class="form-control" value="<?= $security_settings['password_min_length'] ?>" min="6" max="20">
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>กำหนดให้ใช้อักขระพิเศษ</strong>
                      <div class="text-muted small">รหัสผ่านต้องมีอักขระพิเศษ</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $security_settings['require_special_chars'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>การยืนยันตัวตนสองขั้น</strong>
                      <div class="text-muted small">ใช้ OTP หรือ Google Authenticator</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $security_settings['two_factor_auth'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                </div>
              </div>
            </div>

            <!-- Access Control -->
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>การควบคุมการเข้าถึง</h6>
                </div>
                <div class="setting-body">
                  <div class="setting-item">
                    <div>
                      <strong>จำกัด IP ที่เข้าถึงได้</strong>
                      <div class="text-muted small">อนุญาตเฉพาะ IP ที่กำหนด</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $security_settings['ip_whitelist_enabled'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>บันทึกการใช้งาน</strong>
                      <div class="text-muted small">เก็บ log การใช้งานระบบ</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $security_settings['audit_log_enabled'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="mt-3">
                    <label class="form-label">IP ที่อนุญาต</label>
                    <textarea class="form-control" rows="4" placeholder="192.168.1.1&#10;203.154.0.0/24&#10;10.0.0.0/8"></textarea>
                    <small class="text-muted">ใส่ IP address หรือ subnet หนึ่งบรรทัดต่อหนึ่ง IP</small>
                  </div>
                  <div class="mt-3">
                    <button class="btn btn-warning">
                      <i class="bi bi-exclamation-triangle me-1"></i> ดูการเข้าสู่ระบบที่ล้มเหลว
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Backup Panel -->
        <div class="tab-pane fade" id="backup-panel" role="tabpanel">
          <div class="row g-4">
            <!-- Backup Settings -->
            <div class="col-lg-8">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-cloud-upload me-2"></i>การสำรองข้อมูล</h6>
                </div>
                <div class="setting-body">
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label">ความถี่ในการสำรอง</label>
                      <select class="form-select">
                        <option value="daily" selected>รายวัน</option>
                        <option value="weekly">รายสัปดาห์</option>
                        <option value="monthly">รายเดือน</option>
                        <option value="manual">กำหนดเอง</option>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">เวลาสำรอง</label>
                      <input type="time" class="form-control" value="02:00">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">จำนวนไฟล์สำรองที่เก็บ</label>
                      <input type="number" class="form-control" value="30" min="1" max="365">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">ที่เก็บไฟล์สำรอง</label>
                      <select class="form-select">
                        <option value="local" selected>เครื่องเซิร์ฟเวอร์</option>
                        <option value="google_drive">Google Drive</option>
                        <option value="dropbox">Dropbox</option>
                        <option value="aws_s3">Amazon S3</option>
                      </select>
                    </div>
                  </div>
                  
                  <div class="mt-4">
                    <h6>สำรองข้อมูลทันที</h6>
                    <div class="row g-2">
                      <div class="col-md-4">
                        <button class="btn btn-success w-100" onclick="backupDatabase()">
                          <i class="bi bi-database me-1"></i> สำรองฐานข้อมูล
                        </button>
                      </div>
                      <div class="col-md-4">
                        <button class="btn btn-info w-100" onclick="backupFiles()">
                          <i class="bi bi-files me-1"></i> สำรองไฟล์ระบบ
                        </button>
                      </div>
                      <div class="col-md-4">
                        <button class="btn btn-primary w-100" onclick="backupFull()">
                          <i class="bi bi-cloud-download me-1"></i> สำรองทั้งหมด
                        </button>
                      </div>
                    </div>
                  </div>

                  <div class="mt-4">
                    <h6>คืนค่าข้อมูล</h6>
                    <div class="input-group">
                      <input type="file" class="form-control" accept=".sql,.zip,.tar.gz">
                      <button class="btn btn-warning" onclick="restoreBackup()">
                        <i class="bi bi-upload me-1"></i> คืนค่าข้อมูล
                      </button>
                    </div>
                    <small class="text-warning">
                      <i class="bi bi-exclamation-triangle me-1"></i>
                      การคืนค่าข้อมูลจะเขียนทับข้อมูลปัจจุบัน กรุณาสำรองข้อมูลก่อน
                    </small>
                  </div>
                </div>
              </div>
            </div>

            <!-- Backup History -->
            <div class="col-lg-4">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>ประวัติการสำรอง</h6>
                </div>
                <div class="setting-body">
                  <div class="list-group list-group-flush">
                    <div class="list-group-item px-0">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <strong>สำรองอัตโนมัติ</strong>
                          <div class="last-backup">วันนี้ 02:00</div>
                        </div>
                        <span class="badge bg-success">สำเร็จ</span>
                      </div>
                      <small class="text-muted">ขนาด: 24.5 MB</small>
                    </div>
                    <div class="list-group-item px-0">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <strong>สำรองอัตโนมัติ</strong>
                          <div class="last-backup">เมื่อวาน 02:00</div>
                        </div>
                        <span class="badge bg-success">สำเร็จ</span>
                      </div>
                      <small class="text-muted">ขนาด: 24.2 MB</small>
                    </div>
                    <div class="list-group-item px-0">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <strong>สำรองด้วยตนเอง</strong>
                          <div class="last-backup">2 วันที่แล้ว 15:30</div>
                        </div>
                        <span class="badge bg-success">สำเร็จ</span>
                      </div>
                      <small class="text-muted">ขนาด: 23.8 MB</small>
                    </div>
                  </div>
                  <div class="mt-3">
                    <button class="btn btn-outline-primary btn-sm w-100">
                      <i class="bi bi-list me-1"></i> ดูประวัติทั้งหมด
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tips -->
      <div class="alert alert-info mt-4 mb-0">
        <i class="bi bi-lightbulb me-1"></i>
        เคล็ดลับ: การเปลี่ยนแปลงการตั้งค่าบางอย่างอาจต้องรีสตาร์ทระบบ และควรสำรองข้อมูลก่อนทำการปรับเปลี่ยนการตั้งค่าที่สำคัญ
      </div>
    </main>
  </div>
</div>

<!-- Footer -->
<footer class="footer">© <?= date('Y') ?> สหกรณ์ปั๊มน้ำมัน — ตั้งค่าระบบ</footer>

<!-- ===== Modals ===== -->
<!-- Add User Modal -->
<div class="modal fade" id="modalAddUser" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="formAddUser" method="post" action="#" onsubmit="event.preventDefault();">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>เพิ่มผู้ใช้งานใหม่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label">ชื่อผู้ใช้</label>
            <input type="text" class="form-control" id="addUsername" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">ชื่อ-นามสกุล</label>
            <input type="text" class="form-control" id="addFullName" required>
          </div>
          <div class="col-12">
            <label class="form-label">อีเมล</label>
            <input type="email" class="form-control" id="addEmail" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">บทบาท</label>
            <select id="addRole" class="form-select" required>
              <option value="">เลือกบทบาท</option>
              <option value="manager">ผู้บริหาร</option>
              <option value="employee">พนักงาน</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label">รหัสผ่าน</label>
            <input type="password" class="form-control" id="addPassword" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> สร้างผู้ใช้</button>
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
  <div id="liveToast" class="toast align-items-center text-bg-dark border-0" role="status" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">บันทึกเรียบร้อย</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="ปิด"></button>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const $ = (s, p=document)=>p.querySelector(s);
const $$ = (s, p=document)=>[...p.querySelectorAll(s)];

const toast = (msg, success=true)=>{
  const t = $('#liveToast');
  t.className = `toast align-items-center border-0 ${success ? 'text-bg-success' : 'text-bg-danger'}`;
  t.querySelector('.toast-body').textContent = msg || 'บันทึกเรียบร้อย';
  bootstrap.Toast.getOrCreateInstance(t, { delay: 2000 }).show();
};

// Form submissions
$('#siteInfoForm')?.addEventListener('submit', (e) => {
  e.preventDefault();
  toast('บันทึกข้อมูลสหกรณ์แล้ว');
});

$('#systemPrefsForm')?.addEventListener('submit', (e) => {
  e.preventDefault();
  toast('บันทึกการตั้งค่าระบบแล้ว');
});

$('#formAddUser')?.addEventListener('submit', () => {
  const username = $('#addUsername').value.trim();
  const fullName = $('#addFullName').value.trim();
  const email = $('#addEmail').value.trim();
  const role = $('#addRole').value;
  
  if(!username || !fullName || !email || !role) {
    toast('กรุณากรอกข้อมูลให้ครบถ้วน', false);
    return;
  }
  
  bootstrap.Modal.getInstance($('#modalAddUser')).hide();
  $('#formAddUser').reset();
  toast('เพิ่มผู้ใช้งานใหม่แล้ว');
});

// User management functions
function editUser(userId) {
  toast('เปิดหน้าแก้ไขผู้ใช้ ID: ' + userId);
}

function deleteUser(userId) {
  if(confirm('ต้องการลบผู้ใช้นี้หรือไม่?')) {
    toast('ลบผู้ใช้แล้ว');
  }
}

// Backup functions
function backupDatabase() {
  toast('กำลังสำรองข้อมูลฐานข้อมูล...');
}

function backupFiles() {
  toast('กำลังสำรองไฟล์ระบบ...');
}

function backupFull() {
  toast('กำลังสำรองข้อมูลทั้งหมด...');
}

function restoreBackup() {
  if(confirm('การคืนค่าข้อมูลจะเขียนทับข้อมูลปัจจุบัน ต้องการดำเนินการต่อหรือไม่?')) {
    toast('กำลังคืนค่าข้อมูล...');
  }
}

// Switch change handlers
$$('input[type="checkbox"]').forEach(checkbox => {
  checkbox.addEventListener('change', (e) => {
    const setting = e.target.closest('.setting-item')?.querySelector('strong')?.textContent;
    if(setting) {
      toast(`${e.target.checked ? 'เปิด' : 'ปิด'}การใช้งาน: ${setting}`);
    }
  });
});

// Auto-save on input changes
$$('input, select, textarea').forEach(input => {
  if(input.type !== 'checkbox' && input.type !== 'file') {
    input.addEventListener('change', () => {
      // Auto-save functionality can be implemented here
    });
  }
});
</script>
</body>
</html>