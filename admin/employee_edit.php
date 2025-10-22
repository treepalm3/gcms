<?php
// admin/employee_edit.php
session_start();
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/../config/db.php';

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  header('Location: employee.php?err=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

/* ===== Auth ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน'));
  exit();
}

/* ===== DB ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบ $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

/* ===== Admin check ===== */
function is_admin(PDO $pdo): bool {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  if (($_SESSION['role'] ?? '') === 'admin') return true;
  try {
    $st = $pdo->prepare("SELECT 1 FROM admins WHERE user_id=? LIMIT 1");
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
if (!is_admin($pdo)) {
  header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
  exit();
}

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

/* ===== CSRF ===== */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  redirect_back(null, 'โทเค็นไม่ถูกต้อง กรุณาลองใหม่');
}

/* ===== Read form ===== */
$user_id      = (int)($_POST['user_id'] ?? 0);
$full_name    = trim($_POST['full_name'] ?? '');
$employee_code= trim($_POST['employee_code'] ?? '');
$position     = trim($_POST['position'] ?? 'พนักงานปั๊ม');
$phone        = trim($_POST['phone'] ?? '');
$joined_date  = trim($_POST['joined_date'] ?? '');
$salary       = isset($_POST['salary']) && $_POST['salary'] !== '' ? (float)$_POST['salary'] : null;
$address      = trim($_POST['address'] ?? '');

if ($user_id <= 0 || $employee_code === '' || $full_name === '') {
  header('Location: employee.php?err=' . urlencode('ข้อมูลไม่ครบ')); exit;
}

$pdo->beginTransaction();
try {
  // อัปเดต users
  $st = $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
  $st->execute([$full_name, $phone, $user_id]);

  // อัปเดต employees (อ้างจาก user_id ซึ่ง unique ใน employees)
  $sets = "emp_code = ?, position = ?, joined_date = ?, address = ?";
  $params = [$employee_code, $position, ($joined_date ?: null), ($address !== '' ? $address : null), $user_id];

  // แนบ salary ถ้ามีคอลัมน์ และส่งมาจริง
  $hasSalaryCol = true; // หรือใช้ฟังก์ชันตรวจคอลัมน์ก็ได้ถ้ามี
  if ($hasSalaryCol) {
    $sets .= ", salary = ?";
    array_splice($params, -1, 0, [$salary]); // แทรกก่อน $user_id
  }

  $sql = "UPDATE employees SET $sets WHERE user_id = ?";
  $st2 = $pdo->prepare($sql);
  $st2->execute($params);

  $pdo->commit();
  header('Location: employee.php?ok=' . urlencode('บันทึกข้อมูลพนักงานเรียบร้อย'));
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: employee.php?err=' . urlencode('บันทึกล้มเหลว: '.$e->getMessage()));
}

if ($user_id<=0 || $employee_code==='' || $full_name==='') {
  redirect_back(null, 'กรอกข้อมูลไม่ครบ (รหัส, ชื่อ-สกุล)');
}
if ($joined_date==='') $joined_date = date('Y-m-d');

$has_salary = column_exists($pdo,'employees','salary');
$salary_val = null;
if ($has_salary) {
  if ($salary_in==='') $salary_in='0';
  if (!is_numeric($salary_in) || $salary_in < 0) redirect_back(null,'เงินเดือนไม่ถูกต้อง');
  $salary_val = round((float)$salary_in, 2);
}

/* ===== Load target employee & station ===== */
try {
  $st = $pdo->prepare("SELECT station_id FROM employees WHERE user_id=:u LIMIT 1");
  $st->execute([':u'=>$user_id]);
  $station_id = $st->fetchColumn();
  if ($station_id === false) redirect_back(null,'ไม่พบข้อมูลพนักงาน');
  $station_id = (int)$station_id;

  // emp_code not duplicate on same station (exclude self)
  $st = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE station_id=:s AND emp_code=:c AND user_id<>:u");
  $st->execute([':s'=>$station_id, ':c'=>$employee_code, ':u'=>$user_id]);
  if ((int)$st->fetchColumn()>0) redirect_back(null,'รหัสพนักงานนี้ถูกใช้แล้วในสถานีนี้');
} catch (Throwable $e) {
  redirect_back(null,'ตรวจสอบข้อมูลไม่สำเร็จ: '.$e->getMessage());
}

/* ===== Update ===== */
try {
  $pdo->beginTransaction();

  // users (ชื่อ/เบอร์)
  $stU = $pdo->prepare("UPDATE users SET full_name=:n, phone=:p, updated_at=NOW() WHERE id=:id");
  $stU->execute([':n'=>$full_name, ':p'=>($phone!==''?$phone:null), ':id'=>$user_id]);

  // employees
  $set = "emp_code=:c, position=:pos, joined_date=:j";
  $params = [':c'=>$employee_code, ':pos'=>($position!==''?$position:null), ':j'=>$joined_date, ':u'=>$user_id];
  if ($has_salary) { $set .= ", salary=:sal"; $params[':sal']=$salary_val; }

  $stE = $pdo->prepare("UPDATE employees SET $set WHERE user_id=:u");
  $stE->execute($params);

  $pdo->commit();
  redirect_back('แก้ไขข้อมูลพนักงานสำเร็จ');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_back(null,'บันทึกล้มเหลว: '.$e->getMessage());
}
