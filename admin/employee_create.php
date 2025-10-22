<?php
// admin/employee_create.php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน')); exit;
}
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
  header('Location: employee.php?err=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
  header('Location: employee.php?err=' . urlencode('เชื่อมต่อฐานข้อมูลไม่ได้')); exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

// อ่านค่า
$username   = trim($_POST['username'] ?? '');
$password   = $_POST['password'] ?? '';
$full_name  = trim($_POST['full_name'] ?? '');
$email      = trim($_POST['email'] ?? '');
$phone      = trim($_POST['phone'] ?? '');

$emp_code   = trim($_POST['employee_code'] ?? '');
$position_in= trim($_POST['position'] ?? '');
$joined     = trim($_POST['joined_date'] ?? '');
$salary_in  = $_POST['salary'] ?? null;
$address    = trim($_POST['address'] ?? '');

// whitelist ตำแหน่ง
$allowed_positions = ['พนักงานปั๊ม','แคชเชียร์','หัวหน้ากะ','ธุรการ','ช่างเทคนิค'];
$position = in_array($position_in, $allowed_positions, true) ? $position_in : 'พนักงานปั๊ม';

if ($username==='' || $password==='' || $full_name==='' || $emp_code==='') {
  header('Location: employee.php?err=' . urlencode('กรอกข้อมูลให้ครบถ้วน')); exit;
}

$salary = is_numeric($salary_in) ? (float)$salary_in : null;
$joined_date = $joined !== '' ? $joined : null;

// หา station_id จาก settings
$station_id = 1;
try {
  $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
  $st->execute();
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) $station_id = (int)$row['setting_value'];
} catch (Throwable $e) { /* default 1 */ }

try {
  $pdo->beginTransaction();

  // กันซ้ำ username/email
  $st = $pdo->prepare("SELECT 1 FROM users WHERE username = :u LIMIT 1");
  $st->execute([':u'=>$username]);
  if ($st->fetchColumn()) { throw new Exception('Username ซ้ำ'); }

  if ($email!=='') {
    $st = $pdo->prepare("SELECT 1 FROM users WHERE email = :em LIMIT 1");
    $st->execute([':em'=>$email]);
    if ($st->fetchColumn()) { throw new Exception('อีเมลซ้ำ'); }
  }

  // กันรหัสพนักงานซ้ำ
  $st = $pdo->prepare("SELECT 1 FROM employees WHERE emp_code = :code LIMIT 1");
  $st->execute([':code'=>$emp_code]);
  if ($st->fetchColumn()) { throw new Exception('รหัสพนักงานซ้ำ'); }

  // สร้างผู้ใช้
  $password_hash = password_hash($password, PASSWORD_DEFAULT);
  $st = $pdo->prepare("
    INSERT INTO users (username,email,full_name,phone,password_hash,is_active,role,created_at)
    VALUES (:u,:em,:fn,:ph,:pw,1,'employee',NOW())
  ");
  $st->execute([
    ':u'=>$username, ':em'=>($email?:null), ':fn'=>$full_name,
    ':ph'=>($phone?:null), ':pw'=>$password_hash
  ]);
  $new_uid = (int)$pdo->lastInsertId();

  // ผูก employees
  $st = $pdo->prepare("
    INSERT INTO employees (user_id,station_id,emp_code,position,address,joined_date,salary,created_at)
    VALUES (:uid,:sid,:code,:pos,:addr,:joined,:sal,NOW())
  ");
  $st->execute([
    ':uid'=>$new_uid,
    ':sid'=>$station_id,
    ':code'=>$emp_code,
    ':pos'=>$position,
    ':addr'=>($address===''? null : $address),
    ':joined'=>$joined_date,
    ':sal'=>$salary
  ]);

  $pdo->commit();
  header('Location: employee.php?ok=' . urlencode('เพิ่มพนักงานเรียบร้อย'));
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: employee.php?err=' . urlencode('เพิ่มพนักงานไม่สำเร็จ: '.$e->getMessage()));
}
