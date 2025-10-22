<?php
// manager/setting_save.php — Endpoint บันทึกการตั้งค่า app_settings (สำหรับ Manager)
session_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok');

// ==== ตรวจสอบสิทธิ์ล็อกอิน/บทบาท ====
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'โปรดเข้าสู่ระบบก่อน']); exit;
}
$current_role = $_SESSION['role'] ?? 'guest';
if (!in_array($current_role, ['manager','admin'], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'คุณไม่มีสิทธิ์เข้าถึง']); exit;
}
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==== เชื่อมต่อฐานข้อมูล ====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'เชื่อมต่อฐานข้อมูลไม่สำเร็จ']); exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ==== Helpers: load/save JSON settings ====
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

// ==== รับ/ตรวจสอบ payload ====
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'รูปแบบข้อมูลไม่ถูกต้อง']); exit;
}

$csrf = $payload['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'CSRF token ไม่ถูกต้อง']); exit;
}

$section = $payload['section'] ?? '';
$data    = $payload['data'] ?? null;
$allowed_sections = ['system_settings','notification_settings','security_settings','fuel_price_settings'];

if (!in_array($section, $allowed_sections, true) || !is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'section หรือ data ไม่ถูกต้อง']); exit;
}

// ==== ค่าตั้งต้นใช้สำหรับ merge ====
$defaults = [
  'system_settings' => [
    'site_name' => 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง',
    'site_subtitle' => 'ระบบบริหารจัดการปั๊มน้ำมัน',
    'contact_phone' => '02-123-4567',
    'contact_email' => 'info@coop-fuel.com',
    'address' => '',
    'tax_id' => '',
    'registration_number' => '',
    'timezone' => 'Asia/Bangkok',
    'currency' => 'THB',
    'date_format' => 'd/m/Y',
    'language' => 'th'
  ],
  'notification_settings' => [
    'low_stock_alert' => true,
    'low_stock_threshold' => 1000,
    'daily_report_email' => true,
    'maintenance_reminder' => true,
    'payment_alerts' => true,
    'email_notifications' => true,
    'sms_notifications' => false,
    'line_notifications' => true
  ],
  'security_settings' => [
    'session_timeout' => 60,
    'max_login_attempts' => 5,
    'password_min_length' => 8,
    'require_special_chars' => true,
    'two_factor_auth' => false,
    'ip_whitelist_enabled' => false,
    'audit_log_enabled' => true,
    'backup_frequency' => 'daily'
  ],
  'fuel_price_settings' => [
    'auto_price_update' => false,
    'price_source' => 'manual',
    'price_update_time' => '06:00',
    'markup_percentage' => 2.5,
    'round_to_satang' => 25
  ],
];

// ==== ฟังก์ชัน sanitize/validate ตามกลุ่ม ====
function boolvalStrict($v){ return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false; }
function numberOr($v,$def){ return is_numeric($v) ? 0+$v : $def; }
function oneOf($v, array $choices, $def){ return in_array($v,$choices,true) ? $v : $def; }
function timeHHMM($v,$def='06:00'){ return preg_match('/^\d{2}:\d{2}$/',$v) ? $v : $def; }
function clip($v,$min,$max,$def){ if(!is_numeric($v)) return $def; $v=0+$v; return max($min, min($max, $v)); }

function sanitize_section(string $section, array $data, array $defaults): array {
  switch ($section) {
    case 'system_settings':
      return [
        'site_name' => trim((string)($data['site_name'] ?? $defaults['site_name'])),
        'site_subtitle' => trim((string)($data['site_subtitle'] ?? $defaults['site_subtitle'])),
        'contact_phone' => trim((string)($data['contact_phone'] ?? '')),
        'contact_email' => filter_var(($data['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: $defaults['contact_email'],
        'address' => trim((string)($data['address'] ?? '')),
        'tax_id' => trim((string)($data['tax_id'] ?? '')),
        'registration_number' => trim((string)($data['registration_number'] ?? '')),
        'timezone' => oneOf(($data['timezone'] ?? ''), ['Asia/Bangkok','UTC'], $defaults['timezone']),
        'currency' => oneOf(($data['currency'] ?? ''), ['THB','USD'], $defaults['currency']),
        'date_format' => oneOf(($data['date_format'] ?? ''), ['d/m/Y','Y-m-d','m/d/Y'], $defaults['date_format']),
        'language' => oneOf(($data['language'] ?? ''), ['th','en'], $defaults['language']),
      ];

    case 'notification_settings':
      return [
        'low_stock_alert'     => boolvalStrict($data['low_stock_alert'] ?? $defaults['low_stock_alert']),
        'low_stock_threshold' => max(0, (int) numberOr($data['low_stock_threshold'] ?? $defaults['low_stock_threshold'], 1000)),
        'daily_report_email'  => boolvalStrict($data['daily_report_email'] ?? $defaults['daily_report_email']),
        'maintenance_reminder'=> boolvalStrict($data['maintenance_reminder'] ?? $defaults['maintenance_reminder']),
        'payment_alerts'      => boolvalStrict($data['payment_alerts'] ?? $defaults['payment_alerts']),
        'email_notifications' => boolvalStrict($data['email_notifications'] ?? $defaults['email_notifications']),
        'sms_notifications'   => boolvalStrict($data['sms_notifications'] ?? $defaults['sms_notifications']),
        'line_notifications'  => boolvalStrict($data['line_notifications'] ?? $defaults['line_notifications']),
      ];

    case 'security_settings':
      return [
        'session_timeout'     => clip(($data['session_timeout'] ?? $defaults['session_timeout']), 5, 480, 60),
        'max_login_attempts'  => clip(($data['max_login_attempts'] ?? $defaults['max_login_attempts']), 3, 10, 5),
        'password_min_length' => clip(($data['password_min_length'] ?? $defaults['password_min_length']), 6, 20, 8),
        'require_special_chars'=> boolvalStrict($data['require_special_chars'] ?? $defaults['require_special_chars']),
        'two_factor_auth'     => boolvalStrict($data['two_factor_auth'] ?? $defaults['two_factor_auth']),
        'ip_whitelist_enabled'=> boolvalStrict($data['ip_whitelist_enabled'] ?? $defaults['ip_whitelist_enabled']),
        'audit_log_enabled'   => boolvalStrict($data['audit_log_enabled'] ?? $defaults['audit_log_enabled']),
        'backup_frequency'    => oneOf(($data['backup_frequency'] ?? $defaults['backup_frequency']), ['daily','weekly','monthly','manual'], 'daily'),
      ];

    case 'fuel_price_settings':
      return [
        'auto_price_update' => boolvalStrict($data['auto_price_update'] ?? $defaults['auto_price_update']),
        'price_source'      => oneOf(($data['price_source'] ?? $defaults['price_source']), ['manual','ptt','bangchak'], 'manual'),
        'price_update_time' => timeHHMM(($data['price_update_time'] ?? $defaults['price_update_time']), '06:00'),
        'markup_percentage' => numberOr(($data['markup_percentage'] ?? $defaults['markup_percentage']), 2.5),
        'round_to_satang'   => (int) oneOf((int)($data['round_to_satang'] ?? $defaults['round_to_satang']), [1,5,25,50], 25),
      ];
  }
  return $defaults[$section] ?? [];
}

// ==== รวมกับค่าปัจจุบัน + บันทึก ====
try {
  $current = load_settings($pdo, $section, $defaults[$section]);
  $clean   = sanitize_section($section, $data, $defaults[$section]);
  $merged  = array_replace_recursive($current, $clean);

  if (!save_settings($pdo, $section, $merged)) {
    throw new RuntimeException('บันทึกไม่สำเร็จ');
  }

  echo json_encode(['ok'=>true,'message'=>'บันทึกการตั้งค่าเรียบร้อย','section'=>$section]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'เกิดข้อผิดพลาด: '.$e->getMessage()]);
}
