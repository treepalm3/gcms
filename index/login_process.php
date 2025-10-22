<?php
// index/login_process.php
session_start();
require_once __DIR__ . '/../config/db.php';

// เปิด error reporting ช่วงพัฒนา (ปิดในโปรดักชัน)
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ===== CSRF ===== */
if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
  header('Location: login.php?err=ไม่สามารถยืนยันแบบฟอร์มได้');
  exit();
}

/* ===== รับค่าจากฟอร์ม ===== */
$login = trim($_POST['username'] ?? '');
$pass  = (string)($_POST['password'] ?? '');
$role  = strtolower(trim($_POST['role'] ?? ''));

$allowed_roles = ['admin','manager','employee','committee','member'];
if ($login === '' || $pass === '' || $role === '' || !in_array($role, $allowed_roles, true)) {
  header('Location: login.php?err=กรอกข้อมูลให้ครบถ้วนหรือบทบาทไม่ถูกต้อง');
  exit();
}

/* ===== ดึง station_id จาก settings ===== */
try {
  $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
  $stmt->execute();
  $station_id = (int)($stmt->fetchColumn() ?: 1);
} catch (Throwable $e) {
  error_log("Settings Error: ".$e->getMessage());
  $station_id = 1; // fallback
}

/* ===== ตรวจสอบผู้ใช้ + รหัสผ่าน =====
   NOTE: ห้ามใช้ชื่อ placeholder ซ้ำกัน → ใช้ :login_user และ :login_email
*/
$stmt = $pdo->prepare("
  SELECT id, username, email, full_name, password_hash, is_active
  FROM users
  WHERE (username = :login_user OR email = :login_email)
  LIMIT 1
");
$stmt->execute([
  ':login_user'  => $login,
  ':login_email' => $login,
]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || (int)$user['is_active'] !== 1 || !password_verify($pass, $user['password_hash'])) {
  header('Location: login.php?err=ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง');
  exit();
}

$user_id = (int)$user['id'];

/* ===== helper: ตารางมีคอลัมน์ station_id ไหม ===== */
function table_has_station_id(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE 'station_id'");
    $st->execute();
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    error_log("Check column error on {$table}: ".$e->getMessage());
    return false;
  }
}

/* ===== ตรวจสอบการเป็นสมาชิกตามบทบาทที่เลือก ===== */
$is_member = false;
$table = match ($role) {
  'admin'     => 'admins',
  'manager'   => 'managers',
  'employee'  => 'employees',
  'committee' => 'committees',
  'member'    => 'members',
  default     => null,
};

if ($table === null) {
  header('Location: login.php?err=ประเภทผู้ใช้ไม่ถูกต้อง');
  exit();
}

try {
  if (table_has_station_id($pdo, $table)) {
    $st = $pdo->prepare("SELECT id FROM `$table` WHERE user_id = :uid AND station_id = :sid LIMIT 1");
    $st->execute([':uid' => $user_id, ':sid' => $station_id]);
  } else {
    $st = $pdo->prepare("SELECT id FROM `$table` WHERE user_id = :uid LIMIT 1");
    $st->execute([':uid' => $user_id]);
  }
  $is_member = (bool)$st->fetchColumn();
} catch (Throwable $e) {
  error_log("Role Check Error: ".$e->getMessage());
  header('Location: login.php?err=เกิดข้อผิดพลาดในการตรวจสอบสิทธิ์');
  exit();
}

/* ===== ไม่ใช่สมาชิกบทบาทนั้น ===== */
if (!$is_member) {
  header('Location: login.php?err=คุณไม่มีสิทธิ์เข้าสู่ระบบในฐานะ' . $role);
  exit();
}

/* ===== สำเร็จ → ตั้งค่า session + อัปเดตเวลาเข้าใช้ ===== */
session_regenerate_id(true);
$_SESSION['user_id']    = $user_id;
$_SESSION['username']   = $user['username'];
$_SESSION['full_name']  = $user['full_name'];
$_SESSION['role']       = $role;
$_SESSION['station_id'] = $station_id;

try {
  $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user_id]);
} catch (Throwable $e) {
  error_log("Login Update Error: ".$e->getMessage());
}

/* ===== Redirect ตามบทบาท ===== */
switch ($role) {
  case 'admin':     header('Location: ../admin/admin_dashboard.php');       break;
  case 'manager':   header('Location: ../manager/manager_dashboard.php');   break;
  case 'employee':  header('Location: ../employee/employee_dashboard.php'); break;
  case 'committee': header('Location: ../committee/committee_dashboard.php'); break;
  case 'member':
  default:          header('Location: ../member/member_dashboard.php');     break;
}
exit();
