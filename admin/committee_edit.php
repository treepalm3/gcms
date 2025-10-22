<?php
// admin/committee_edit.php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน'));
  exit();
}

$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (($_SESSION['role'] ?? 'guest') !== 'admin') {
  header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
  exit();
}

/* Helpers */
function table_exists(PDO $pdo, string $table): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
  $st->execute([':db'=>$db, ':tb'=>$table]);
  return (int)$st->fetchColumn() > 0;
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:c');
  $st->execute([':db'=>$db, ':tb'=>$table, ':c'=>$col]);
  return (int)$st->fetchColumn() > 0;
}
function redirect_back($ok=null,$err=null){
  $q=[]; if($ok!==null)$q[]='ok='.urlencode($ok); if($err!==null)$q[]='err='.urlencode($err);
  header('Location: committee.php'.(empty($q)?'':'?'.implode('&',$q))); exit();
}

/* CSRF */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  redirect_back(null, 'โทเค็นไม่ถูกต้อง กรุณาลองใหม่');
}

if (!table_exists($pdo,'users') || !table_exists($pdo,'committees')) {
  redirect_back(null, 'ยังไม่มีตาราง users หรือ committees');
}

$has_dept = column_exists($pdo,'committees','department');

/* Read form */
$user_id   = (int)($_POST['user_id'] ?? 0);
$code      = trim($_POST['committee_code'] ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$phone     = trim($_POST['phone'] ?? '');
$position  = trim($_POST['position'] ?? '');
$joined    = trim($_POST['joined_date'] ?? '');
$shares    = (string)($_POST['shares'] ?? '0');
$house_no  = trim($_POST['house_number'] ?? '');
$address   = trim($_POST['address'] ?? '');
$department= $has_dept ? trim($_POST['department'] ?? '') : '';

if ($user_id<=0 || $code==='') redirect_back(null, 'ข้อมูลไม่ครบ');

/* Validate */
$shares = ctype_digit((string)$shares) ? (int)$shares : 0;
$joined = $joined !== '' ? $joined : null;

try {
  $pdo->beginTransaction();

  // Update users
  $st = $pdo->prepare('UPDATE users SET full_name=:n, phone=:p, updated_at=NOW() WHERE id=:id');
  $st->execute([
    ':n'=>$full_name, ':p'=>($phone!==''?$phone:null), ':id'=>$user_id
  ]);

  // Update committees
  $sets = [
    'committee_code = :code',
    'position = :pos',
    'joined_date = :joined',
    'shares = :shares',
    'house_number = :house_no',
    'address = :addr',
  ];
  $params = [
    ':code'=>$code,
    ':pos'=>($position!==''?$position:null),
    ':joined'=>$joined,
    ':shares'=>$shares,
    ':house_no'=>($house_no!==''?$house_no:null),
    ':addr'=>($address!==''?$address:null),
    ':uid'=>$user_id,
  ];
  if ($has_dept) {
    $sets[] = 'department = :dept';
    $params[':dept'] = ($department!==''?$department:null);
  }

  $sql = 'UPDATE committees SET '.implode(', ',$sets).' WHERE user_id = :uid';
  $st2 = $pdo->prepare($sql);
  $st2->execute($params);

  $pdo->commit();
  redirect_back('บันทึกการแก้ไขกรรมการสำเร็จ');

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $msg = $e->getMessage();
  if (stripos($msg,'duplicate')!==false || stripos($msg,'unique')!==false) {
    if (stripos($msg,'committee_code')!==false) $msg = 'รหัสกรรมการนี้ถูกใช้แล้ว';
  }
  redirect_back(null,'บันทึกล้มเหลว: '.$msg);
}
