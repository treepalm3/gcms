<?php
// manager/employee_edit.php
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
$user_id       = (int)($_POST['user_id'] ?? 0);
$full_name     = trim($_POST['full_name'] ?? '');
$employee_code = trim($_POST['employee_code'] ?? '');
$position      = trim($_POST['position'] ?? 'พนักงานปั๊ม');
$phone         = trim($_POST['phone'] ?? '');
$joined_date   = trim($_POST['joined_date'] ?? '');
$salary_in     = $_POST['salary'] ?? null;
$address       = trim($_POST['address'] ?? '');

if ($user_id <= 0 || $employee_code === '' || $full_name === '') {
  redirect_back(null, 'กรอกข้อมูลไม่ครบ (รหัส, ชื่อ-สกุล)');
}

$has_salary = column_exists($pdo, 'employees', 'salary');
$salary = null;
if ($has_salary && $salary_in !== null && $salary_in !== '') {
  if (!is_numeric($salary_in) || $salary_in < 0) {
    redirect_back(null, 'รูปแบบเงินเดือนไม่ถูกต้อง');
  }
  $salary = round((float)$salary_in, 2);
}

/* ===== Main Logic ===== */
$pdo->beginTransaction();
try {
  // 1. Update users table
  $st_user = $pdo->prepare("UPDATE users SET full_name = :name, phone = :phone, updated_at = NOW() WHERE id = :id");
  $st_user->execute([
    ':name' => $full_name,
    ':phone' => ($phone !== '' ? $phone : null),
    ':id' => $user_id
  ]);

  // 2. Update employees table
  $sets = "emp_code = :code, position = :pos, joined_date = :joined, address = :addr";
  $params = [
    ':code' => $employee_code,
    ':pos' => $position,
    ':joined' => ($joined_date ?: null),
    ':addr' => ($address !== '' ? $address : null),
    ':uid' => $user_id
  ];

  if ($has_salary) {
    $sets .= ", salary = :sal";
    $params[':sal'] = $salary;
  }

  $sql_emp = "UPDATE employees SET $sets WHERE user_id = :uid";
  $st_emp = $pdo->prepare($sql_emp);
  $st_emp->execute($params);

  $pdo->commit();
  redirect_back('บันทึกข้อมูลพนักงานเรียบร้อย');
} catch (Throwable $e) {
  $pdo->rollBack();
  redirect_back(null, 'บันทึกล้มเหลว: ' . $e->getMessage());
}

