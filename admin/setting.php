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

// [ลบ] function fetch_users()

// [เพิ่ม] เพิ่ม Helper function d() ที่ขาดหายไป
function d($s, $fmt = 'd/m/Y H:i') { 
    if (empty($s)) return '-';
    $t = strtotime($s); 
    return $t ? date($fmt, $t) : '-'; 
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
// [ลบ] fuel_price_defaults

// โหลดค่าจริงจาก DB (ถ้าไม่มีจะได้ defaults)
$system_settings        = load_settings($pdo, 'system_settings',        $system_defaults);
$notification_settings  = load_settings($pdo, 'notification_settings',  $notification_defaults);
$security_settings      = load_settings($pdo, 'security_settings',      $security_defaults);
// [ลบ] load fuel_price_settings

// [ลบ] ดึง users จริง
// $users_list = fetch_users($pdo);

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
  <title>ตั้งค่าระบบ | <?= htmlspecialchars($site_name) ?></title>

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

  <!-- Global theme -->
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    /* สไตล์สำหรับหน้านี้โดยเฉพาะ */
    .setting-section {
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: var(--surface);
      margin-bottom: 20px;
      box-shadow: var(--shadow);
    }
    .setting-header {
      background: linear-gradient(180deg,#fff,rgba(226,212,183,.35));
      border-radius: var(--radius) var(--radius) 0 0;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--border);
    }
    .setting-header h6 {
        margin-bottom: 0;
        font-weight: 700;
        color: var(--navy);
    }
    .setting-body {
      padding: 1.25rem;
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
      background-color: var(--mint); /* สี mint */
    }
    input:checked + .slider:before {
      transform: translateX(26px);
    }
    .status-active {
      color: var(--mint); /* สี mint */
      font-weight: 600;
    }
    .status-inactive {
      color: var(--amber); /* สี amber */
      font-weight: 600;
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
        <!-- [ลบ] แท็บผู้ใช้งาน -->
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
          </div>
        </div>

        <!-- [ลบ] Users Panel -->

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
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-shield-lock-fill me-2"></i>ตั้งค่าความปลอดภัย</h6>
                </div>
                <div class="setting-body">
                  <div class="setting-item">
                    <div>
                      <strong>Two-Factor Auth (2FA)</strong>
                      <div class="text-muted small">บังคับใช้การยืนยันตัวตนสองขั้นตอน</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $security_settings['two_factor_auth'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>จำกัด IP ที่อนุญาต</strong>
                      <div class="text-muted small">จำกัดการเข้าถึงจาก IP ที่กำหนด</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $security_settings['ip_whitelist_enabled'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                  <div class="setting-item">
                    <div>
                      <strong>เปิดใช้งาน Audit Log</strong>
                      <div class="text-muted small">บันทึกการกระทำสำคัญของผู้ใช้</div>
                    </div>
                    <label class="switch">
                      <input type="checkbox" <?= $security_settings['audit_log_enabled'] ? 'checked' : '' ?>>
                      <span class="slider"></span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="col-lg-6">
              <div class="setting-section">
                <div class="setting-header">
                  <h6 class="mb-0"><i class="bi bi-key-fill me-2"></i>นโยบายรหัสผ่าน</h6>
                </div>
                <div class="setting-body">
                  <div class="mb-3">
                    <label class="form-label">หมดเวลา Session (นาที)</label>
                    <input type="number" class="form-control" value="<?= $security_settings['session_timeout'] ?>">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">จำนวนครั้งที่ล็อกอินผิดพลาด</label>
                    <input type="number" class="form-control" value="<?= $security_settings['max_login_attempts'] ?>">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">ความยาวรหัสผ่านขั้นต่ำ</label>
                    <input type="number" class="form-control" value="<?= $security_settings['password_min_length'] ?>">
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
        เคล็ดลับ: การเปลี่ยนแปลงการตั้งค่าบางอย่างอาจต้องรีเฟรชหน้า หรือเข้าสู่ระบบใหม่
      </div>
    </main>
  </div>
</div>

<!-- Footer -->
<footer class="footer">© <?= date('Y') ?> สหกรณ์ปั๊มน้ำมัน — ตั้งค่าระบบ</footer>

<!-- ===== Modals ===== -->
<!-- [ลบ] Add User Modal -->

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

// [ลบ] Form Add User

// [ลบ] User management functions

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

