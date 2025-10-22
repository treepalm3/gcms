<?php
// manager/setting.php — ตั้งค่าระบบ (ฝั่งผู้บริหาร)
session_start();
date_default_timezone_set('Asia/Bangkok');

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== เชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== Helpers: โหลด/บันทึก settings แบบ JSON ในตาราง app_settings =====
function load_settings(PDO $pdo, string $key, array $defaults): array {
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`=:k LIMIT 1");
  $st->execute([':k'=>$key]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return $defaults;

  $data = json_decode($row['json_value'] ?? '{}', true);
  if (!is_array($data)) $data = [];
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

// ===== ตรวจสอบสิทธิ์ผู้ใช้งาน =====
try {
  if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit();
  }
  $current_name = $_SESSION['full_name'] ?? 'ผู้บริหาร';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'manager') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
  // ดึงชื่อผู้ใช้จริง (ถ้ามี)
  $st = $pdo->prepare("SELECT full_name, role FROM users WHERE id=:id LIMIT 1");
  $st->execute([':id' => $_SESSION['user_id']]);
  if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
    $current_name = $u['full_name'] ?: $current_name;
    $current_role = $u['role'] ?: $current_role;
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}

// ===== ค่าเริ่มต้นของแต่ละชุดการตั้งค่า =====
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

// ===== โหลดค่าจริงจาก DB =====
$system_settings        = load_settings($pdo, 'system_settings',        $system_defaults);
$notification_settings  = load_settings($pdo, 'notification_settings',  $notification_defaults);
$security_settings      = load_settings($pdo, 'security_settings',      $security_defaults);
$fuel_price_settings    = load_settings($pdo, 'fuel_price_settings',    $fuel_price_defaults);

// ใช้ชื่อไซต์จาก system_settings
$site_name     = $system_settings['site_name'] ?? 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = $system_settings['site_subtitle'] ?? 'ระบบบริหารจัดการปั๊มน้ำมัน';

// ภาษาแสดงบทบาท
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',   'member'=>'สมาชิกสหกรณ์',
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
    .setting-section { border:1px solid #e9ecef; border-radius:12px; background:#fff; margin-bottom:20px; }
    .setting-header { background:#f8f9fa; border-radius:12px 12px 0 0; padding:15px 20px; border-bottom:1px solid #e9ecef; }
    .setting-body { padding:20px; }
    .setting-item { display:flex; align-items:center; justify-content:space-between; padding:12px 0; border-bottom:1px solid #f1f3f4; }
    .setting-item:last-child { border-bottom:none; }
    .switch{position:relative;display:inline-block;width:50px;height:24px;}
    .switch input{opacity:0;width:0;height:0;}
    .slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#ccc;transition:.4s;border-radius:24px;}
    .slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background:#fff;transition:.4s;border-radius:50%;}
    input:checked + .slider{background:#0d6efd;}
    input:checked + .slider:before{transform:translateX(26px);}
    .status-active{color:#198754;font-weight:600;}
    .status-inactive{color:#dc3545;font-weight:600;}
    .last-backup{color:#6c757d;font-size:.9rem;}
    .nav-tabs .nav-link { color: var(--steel); font-weight:600; }
    .nav-tabs .nav-link.active { color: var(--navy); border-color: var(--border) var(--border) #fff; border-bottom-width:2px; }
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
    <div class="side-brand mb-2"><h3><span>Manager</span></h3></div>
    <nav class="sidebar-menu">
      <a href="manager_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
      <a class="active" href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
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

      <!-- Tab Navigation (สำหรับ Manager: ไม่มีแท็บ "ผู้ใช้งาน") -->
      <ul class="nav nav-tabs mb-4" id="settingTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general-panel" type="button" role="tab">
            <i class="bi bi-gear-fill me-2"></i>ทั่วไป
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
                  <form id="siteInfoForm" method="post" action="#" onsubmit="event.preventDefault();">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
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
                  <form id="systemPrefsForm" method="post" action="#" onsubmit="event.preventDefault();">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="mb-3">
                      <label class="form-label">เขตเวลา</label>
                      <select class="form-select">
                        <option value="Asia/Bangkok" <?= ($system_settings['timezone']==='Asia/Bangkok'?'selected':'') ?>>Asia/Bangkok (GMT+7)</option>
                        <option value="UTC" <?= ($system_settings['timezone']==='UTC'?'selected':'') ?>>UTC (GMT+0)</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">สกุลเงิน</label>
                      <select class="form-select">
                        <option value="THB" <?= ($system_settings['currency']==='THB'?'selected':'') ?>>บาทไทย (THB)</option>
                        <option value="USD" <?= ($system_settings['currency']==='USD'?'selected':'') ?>>ดอลลาร์สหรัฐ (USD)</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">รูปแบบวันที่</label>
                      <select class="form-select">
                        <option value="d/m/Y" <?= ($system_settings['date_format']==='d/m/Y'?'selected':'') ?>>วว/ดด/ปปปป</option>
                        <option value="Y-m-d" <?= ($system_settings['date_format']==='Y-m-d'?'selected':'') ?>>ปปปป-ดด-วว</option>
                        <option value="m/d/Y" <?= ($system_settings['date_format']==='m/d/Y'?'selected':'') ?>>ดด/วว/ปปปป</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">ภาษา</label>
                      <select class="form-select">
                        <option value="th" <?= ($system_settings['language']==='th'?'selected':'') ?>>ไทย</option>
                        <option value="en" <?= ($system_settings['language']==='en'?'selected':'') ?>>English</option>
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
                        <option value="manual" <?= ($fuel_price_settings['price_source']==='manual'?'selected':'') ?>>กำหนดเอง</option>
                        <option value="ptt" <?= ($fuel_price_settings['price_source']==='ptt'?'selected':'') ?>>PTT API</option>
                        <option value="bangchak" <?= ($fuel_price_settings['price_source']==='bangchak'?'selected':'') ?>>บางจาก API</option>
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
                        <option value="1"   <?= ($fuel_price_settings['round_to_satang']==1?'selected':'') ?>>1 สตางค์</option>
                        <option value="5"   <?= ($fuel_price_settings['round_to_satang']==5?'selected':'') ?>>5 สตางค์</option>
                        <option value="25"  <?= ($fuel_price_settings['round_to_satang']==25?'selected':'') ?>>25 สตางค์</option>
                        <option value="50"  <?= ($fuel_price_settings['round_to_satang']==50?'selected':'') ?>>50 สตางค์</option>
                      </select>
                    </div>
                  </div>
                  <div class="mt-3">
                    <button class="btn btn-primary"><i class="bi bi-save me-1"></i> บันทึก</button>
                  </div>
                </div>
              </div>
            </div>

          </div><!-- /row -->
        </div><!-- /general -->

        <!-- Notifications Panel -->
        <div class="tab-pane fade" id="notifications-panel" role="tabpanel">
          <div class="row g-4">
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
                    <input type="number" class="form-control" value="<?= (int)$notification_settings['low_stock_threshold'] ?>">
                  </div>
                </div>
              </div>
            </div>

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

          </div><!-- /row -->
        </div><!-- /notifications -->

        <!-- Security Panel -->
        <div class="tab-pane fade" id="security-panel" role="tabpanel">
          <div class="row g-4">
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-key me-2"></i>การรักษาความปลอดภัย</h6>
                </div>
                <div class="setting-body">
                  <div class="mb-3">
                    <label class="form-label">ระยะเวลา Session (นาที)</label>
                    <input type="number" class="form-control" value="<?= (int)$security_settings['session_timeout'] ?>" min="5" max="480">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">จำนวนครั้งที่พยายามเข้าสู่ระบบ</label>
                    <input type="number" class="form-control" value="<?= (int)$security_settings['max_login_attempts'] ?>" min="3" max="10">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">ความยาวรหัสผ่านขั้นต่ำ</label>
                    <input type="number" class="form-control" value="<?= (int)$security_settings['password_min_length'] ?>" min="6" max="20">
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

          </div><!-- /row -->
        </div><!-- /security -->

        <!-- Backup Panel -->
        <div class="tab-pane fade" id="backup-panel" role="tabpanel">
          <div class="row g-4">
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
                        <option value="daily"   <?= ($security_settings['backup_frequency']==='daily'?'selected':'') ?>>รายวัน</option>
                        <option value="weekly"  <?= ($security_settings['backup_frequency']==='weekly'?'selected':'') ?>>รายสัปดาห์</option>
                        <option value="monthly" <?= ($security_settings['backup_frequency']==='monthly'?'selected':'') ?>>รายเดือน</option>
                        <option value="manual"  <?= ($security_settings['backup_frequency']==='manual'?'selected':'') ?>>กำหนดเอง</option>
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

          </div><!-- /row -->
        </div><!-- /backup -->
      </div><!-- /tab-content -->

      <!-- Tips -->
      <div class="alert alert-info mt-4 mb-0">
        <i class="bi bi-lightbulb me-1"></i>
        เคล็ดลับ: การเปลี่ยนแปลงการตั้งค่าบางอย่างอาจต้องรีสตาร์ทระบบ และควรสำรองข้อมูลก่อนปรับตั้งค่าที่สำคัญ
      </div>
    </main>
  </div>
</div>

<!-- Footer -->
<footer class="footer">© <?= date('Y') ?> สหกรณ์ปั๊มน้ำมัน — ตั้งค่าระบบ</footer>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
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

// อ่าน ok/err จาก query (ถ้ามี)
const urlParams = new URLSearchParams(window.location.search);
const okMsg = urlParams.get('ok');
const errMsg = urlParams.get('err');
if (okMsg) { toast(okMsg, true); window.history.replaceState({}, document.title, window.location.pathname); }
if (errMsg) { toast(errMsg, false); window.history.replaceState({}, document.title, window.location.pathname); }

// ===== Utility =====
function formToObject(form){
  const fd = new FormData(form);
  const obj = Object.fromEntries(fd.entries());
  // รวม checkbox ที่ไม่ได้ติ๊กให้เป็น false
  form.querySelectorAll('input[type="checkbox"][name]').forEach(cb=>{
    if (!fd.has(cb.name)) obj[cb.name] = false;
  });
  // แปลง string "on"/"true"/"false" -> boolean
  Object.keys(obj).forEach(k=>{
    if (typeof obj[k] === 'string') {
      if (obj[k].toLowerCase() === 'on') obj[k] = true;
      else if (obj[k].toLowerCase() === 'true') obj[k] = true;
      else if (obj[k].toLowerCase() === 'false') obj[k] = false;
    }
  });
  return obj;
}

async function saveSection(section, data){
  try {
    const res = await fetch('setting_save.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'Accept':'application/json'},
      body: JSON.stringify({ section, data, csrf_token: '<?= $_SESSION['csrf_token'] ?>' })
    });
    const out = await res.json();
    if (out.ok) toast(out.message, true);
    else toast(out.error || 'บันทึกไม่สำเร็จ', false);
  } catch (e) {
    toast('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', false);
  }
}

// ====== Hook ฟอร์มต่าง ๆ ======
// 1) ข้อมูลสหกรณ์ (system_settings)
$('#siteInfoForm')?.addEventListener('submit', (e)=>{
  e.preventDefault();
  // เพิ่ม name ให้ input/textarea ตามนี้ใน HTML:
  // site_name, site_subtitle, contact_phone, contact_email, address, tax_id, registration_number
  // (ดูตัวอย่าง name attributes ด้านล่าง)
  const data = formToObject(e.target);
  saveSection('system_settings', data);
});

// 2) การตั้งค่าระบบ (timezone, currency, date_format, language)
$('#systemPrefsForm')?.addEventListener('submit', (e)=>{
  e.preventDefault();
  const data = formToObject(e.target);
  saveSection('system_settings', data); // อยู่ในกลุ่มเดียวกัน
});

// 3) การแจ้งเตือน (notification_settings)
$('#notifications-panel')?.addEventListener('change', (e)=>{
  // autosave เมื่อแก้ไขค่าใด ๆ
  if (!e.target.closest('.setting-section')) return;
  const wrap = $('#notifications-panel');
  // สร้าง form ชั่วคราวรวม field ที่เกี่ยวข้อง (หรือจะใส่ใน <form id="notifyForm"> ก็ได้)
  const data = {
    low_stock_alert:        wrap.querySelector('input[name="low_stock_alert"]')?.checked ?? false,
    low_stock_threshold:    wrap.querySelector('input[name="low_stock_threshold"]')?.value ?? 1000,
    daily_report_email:     wrap.querySelector('input[name="daily_report_email"]')?.checked ?? false,
    maintenance_reminder:   wrap.querySelector('input[name="maintenance_reminder"]')?.checked ?? false,
    payment_alerts:         wrap.querySelector('input[name="payment_alerts"]')?.checked ?? false,
    email_notifications:    wrap.querySelector('input[name="email_notifications"]')?.checked ?? true,
    sms_notifications:      wrap.querySelector('input[name="sms_notifications"]')?.checked ?? false,
    line_notifications:     wrap.querySelector('input[name="line_notifications"]')?.checked ?? true,
  };
  saveSection('notification_settings', data);
});

// 4) ความปลอดภัย (security_settings) — กดปุ่มบันทึกเองก็ได้ หรือ autosave ตอนเปลี่ยน
$('#security-panel')?.addEventListener('change', (e)=>{
  if (!e.target.closest('.setting-section')) return;
  const wrap = $('#security-panel');
  const data = {
    session_timeout:        wrap.querySelector('input[name="session_timeout"]')?.value ?? 60,
    max_login_attempts:     wrap.querySelector('input[name="max_login_attempts"]')?.value ?? 5,
    password_min_length:    wrap.querySelector('input[name="password_min_length"]')?.value ?? 8,
    require_special_chars:  wrap.querySelector('input[name="require_special_chars"]')?.checked ?? true,
    two_factor_auth:        wrap.querySelector('input[name="two_factor_auth"]')?.checked ?? false,
    ip_whitelist_enabled:   wrap.querySelector('input[name="ip_whitelist_enabled"]')?.checked ?? false,
    audit_log_enabled:      wrap.querySelector('input[name="audit_log_enabled"]')?.checked ?? true,
    backup_frequency:       wrap.querySelector('select[name="backup_frequency"]')?.value ?? 'daily',
  };
  saveSection('security_settings', data);
});

// 5) การตั้งค่าราคาน้ำมัน (fuel_price_settings) — กดบันทึกปุ่มด้านล่าง
$('#general-panel')?.addEventListener('click', (e)=>{
  const btn = e.target.closest('.setting-section .btn.btn-primary');
  if (!btn) return;
  const box = btn.closest('.setting-section');
  if (!box || !box.querySelector('i.bi.bi-fuel-pump-fill')) return; // เฉพาะบล็อกน้ำมัน
  const data = {
    auto_price_update:  box.querySelector('input[name="auto_price_update"]')?.checked ?? false,
    price_source:       box.querySelector('select[name="price_source"]')?.value ?? 'manual',
    price_update_time:  box.querySelector('input[name="price_update_time"]')?.value ?? '06:00',
    markup_percentage:  box.querySelector('input[name="markup_percentage"]')?.value ?? 2.5,
    round_to_satang:    box.querySelector('select[name="round_to_satang"]')?.value ?? 25,
  };
  saveSection('fuel_price_settings', data);
});

// ปุ่มสำรอง/คืนค่า (เดโมเดิม)
function backupDatabase(){ toast('กำลังสำรองข้อมูลฐานข้อมูล...'); }
function backupFiles(){ toast('กำลังสำรองไฟล์ระบบ...'); }
function backupFull(){ toast('กำลังสำรองข้อมูลทั้งหมด...'); }
function restoreBackup(){
  if(confirm('การคืนค่าข้อมูลจะเขียนทับข้อมูลปัจจุบัน ต้องการดำเนินการต่อหรือไม่?')){
    toast('กำลังคืนค่าข้อมูล...');
  }
}
</script>
</body>
</html>
