<?php
// manager/employee_create.php
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== Auth & DB ===== */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
  header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
  exit();
}
require_once __DIR__ . '/../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
  header('Location: employee.php?err=' . urlencode('เชื่อมต่อฐานข้อมูลไม่ได้')); exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

/* ===== Helpers ===== */
function redirect_back($ok=null,$err=null){
  $q=[]; if($ok!==null)$q[]='ok='.urlencode($ok); if($err!==null)$q[]='err='.urlencode($err);
  header('Location: employee.php'.(empty($q)?'':'?'.implode('&',$q))); exit();
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:c');
    $st->execute([':db'=>$db, ':tb'=>$table, ':c'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch(Throwable $e){ return false; }
}

/* ===== CSRF Check ===== */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  redirect_back(null, 'โทเค็นไม่ถูกต้อง กรุณาลองใหม่');
}

/* ===== Read & Validate Form Data ===== */
$username   = trim($_POST['username'] ?? '');
$password   = $_POST['password'] ?? '';
$full_name  = trim($_POST['full_name'] ?? '');
$email      = trim($_POST['email'] ?? '');
$phone      = trim($_POST['phone'] ?? '');

$emp_code   = trim($_POST['employee_code'] ?? '');
$position   = trim($_POST['position'] ?? 'พนักงานปั๊ม');
$joined     = trim($_POST['joined_date'] ?? '');
$salary_in  = $_POST['salary'] ?? null;
$address    = trim($_POST['address'] ?? '');

if ($username==='' || $password==='' || $full_name==='' || $emp_code==='') {
  redirect_back(null, 'กรอกข้อมูลที่จำเป็นให้ครบถ้วน (ชื่อผู้ใช้, รหัสผ่าน, ชื่อ-สกุล, รหัสพนักงาน)');
}
if (strlen($password) < 8) {
  redirect_back(null, 'รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร');
}

$has_salary = column_exists($pdo, 'employees', 'salary');
$salary = null;
if ($has_salary && $salary_in !== null && $salary_in !== '') {
  if (!is_numeric($salary_in) || $salary_in < 0) {
    redirect_back(null, 'รูปแบบเงินเดือนไม่ถูกต้อง');
  }
  $salary = round((float)$salary_in, 2);
}

$joined_date = $joined !== '' ? $joined : date('Y-m-d');

/* ===== Get Station ID ===== */
$station_id = 1;
try {
  $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
  $st->execute();
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) $station_id = (int)$row['setting_value'];
} catch (Throwable $e) { /* Use default 1 */ }

/* ===== Main Logic ===== */
try {
  $pdo->beginTransaction();

  // Check for duplicates
  $st = $pdo->prepare("SELECT 1 FROM users WHERE username = :u LIMIT 1");
  $st->execute([':u'=>$username]);
  if ($st->fetchColumn()) { throw new Exception('Username นี้มีผู้ใช้แล้ว'); }

  if ($email!=='') {
    $st = $pdo->prepare("SELECT 1 FROM users WHERE email = :em LIMIT 1");
    $st->execute([':em'=>$email]);
    if ($st->fetchColumn()) { throw new Exception('อีเมลนี้มีผู้ใช้แล้ว'); }
  }

  $st = $pdo->prepare("SELECT 1 FROM employees WHERE emp_code = :code AND station_id = :sid LIMIT 1");
  $st->execute([':code'=>$emp_code, ':sid' => $station_id]);
  if ($st->fetchColumn()) { throw new Exception('รหัสพนักงานนี้มีอยู่แล้วในสถานีนี้'); }

  // 1. Create user
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

  // 2. Create employee record
  $sql_emp = "INSERT INTO employees (user_id,station_id,emp_code,position,address,joined_date" . ($has_salary ? ",salary" : "") . ",created_at)
              VALUES (:uid,:sid,:code,:pos,:addr,:joined" . ($has_salary ? ",:sal" : "") . ",NOW())";
  $st_emp = $pdo->prepare($sql_emp);
  $params_emp = [
    ':uid'=>$new_uid,
    ':sid'=>$station_id,
    ':code'=>$emp_code,
    ':pos'=>$position,
    ':addr'=>($address===''? null : $address),
    ':joined'=>$joined_date,
  ];
  if ($has_salary) {
    $params_emp[':sal'] = $salary;
  }
  $st_emp->execute($params_emp);

  $pdo->commit();
  redirect_back('เพิ่มพนักงานเรียบร้อย');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_back(null, 'เพิ่มพนักงานไม่สำเร็จ: '.$e->getMessage());
}

