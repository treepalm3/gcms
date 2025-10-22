<?php
// admin/committee_create.php
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ---------- Auth ---------- */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน'));
  exit();
}

/* ---------- DB ---------- */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Role ---------- */
try {
  if (($_SESSION['role'] ?? 'guest') !== 'admin') {
    header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=' . urlencode('เกิดข้อผิดพลาดของระบบ'));
  exit();
}

/* ---------- Helpers ---------- */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:c');
    $st->execute([':db'=>$db, ':tb'=>$table, ':c'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function get_setting(PDO $pdo, string $name, $default=null){
  try{
    if (!table_exists($pdo,'settings')) return $default;
    if (column_exists($pdo,'settings','setting_name') && column_exists($pdo,'settings','setting_value')){
      $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name=:n LIMIT 1');
      $st->execute([':n'=>$name]);
      $v = $st->fetchColumn();
      return $v!==false ? $v : $default;
    } elseif (column_exists($pdo,'settings','site_name')) {
      $st = $pdo->query("SELECT site_name FROM settings WHERE id=1");
      if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r['site_name'] ?: $default;
    }
  }catch(Throwable $e){}
  return $default;
}
function redirect_back(?string $ok=null, ?string $err=null){
  $q = [];
  if ($ok !== null)  $q[] = 'ok='  . urlencode($ok);
  if ($err !== null) $q[] = 'err=' . urlencode($err);
  header('Location: committee.php' . (empty($q) ? '' : '?' . implode('&', $q)));
  exit();
}

/* ---------- CSRF ---------- */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  redirect_back(null, 'โทเค็นไม่ถูกต้อง กรุณาลองใหม่');
}

/* ---------- Require tables ---------- */
if (!table_exists($pdo,'users') || !table_exists($pdo,'committees')) {
  redirect_back(null, 'ยังไม่มีตาราง users หรือ committees');
}

/* ---------- Read & validate form ---------- */
$username  = trim($_POST['username'] ?? '');
$password  = (string)($_POST['password'] ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$email_raw = trim($_POST['email'] ?? '');
$email     = $email_raw !== '' ? strtolower($email_raw) : null;

$committee_code = trim($_POST['committee_code'] ?? '');
$phone     = trim($_POST['phone'] ?? '');
$position  = trim($_POST['position'] ?? '');
$department= trim($_POST['department'] ?? ''); // จะถูกใช้ก็ต่อเมื่อมีคอลัมน์ใน DB
$joined    = trim($_POST['joined_date'] ?? '');
$shares    = (string)($_POST['shares'] ?? '0');
$house_no  = trim($_POST['house_number'] ?? '');
$address   = trim($_POST['address'] ?? '');

if ($username === '' || $password === '' || $full_name === '' || $committee_code === '') {
  redirect_back(null, 'กรอกข้อมูลที่จำเป็นไม่ครบ (ชื่อผู้ใช้, รหัสผ่าน, ชื่อ-สกุล, รหัสกรรมการ)');
}
if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) {
  redirect_back(null, 'รูปแบบชื่อผู้ใช้ไม่ถูกต้อง (อนุญาต a-z, 0-9, ., _, - ความยาว 3–30)');
}
if (strlen($password) < 8) {
  redirect_back(null, 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร');
}
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  redirect_back(null, 'รูปแบบอีเมลไม่ถูกต้อง');
}
$shares = is_numeric($shares) ? max(0, (int)$shares) : 0;

/* joined date: ว่างให้เป็นวันนี้ */
$joined_date = $joined !== '' ? $joined : date('Y-m-d');

/* station id (ถ้ามีคอลัมน์) */
$station_id = (int)get_setting($pdo, 'station_id', 1);

/* ---------- Business rules: uniqueness ---------- */
try {
  // username
  $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username=:u');
  $st->execute([':u'=>$username]);
  if ((int)$st->fetchColumn() > 0) {
    redirect_back(null, 'ชื่อผู้ใช้นี้ถูกใช้แล้ว');
  }
  // email (ถ้ามี)
  if ($email !== null && $email !== '') {
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email=:e');
    $st->execute([':e'=>$email]);
    if ((int)$st->fetchColumn() > 0) {
      redirect_back(null, 'อีเมลนี้ถูกใช้แล้ว');
    }
  }
  // committee_code
  $st = $pdo->prepare('SELECT COUNT(*) FROM committees WHERE committee_code=:c');
  $st->execute([':c'=>$committee_code]);
  if ((int)$st->fetchColumn() > 0) {
    redirect_back(null, 'รหัสกรรมการนี้ถูกใช้แล้ว');
  }
} catch (Throwable $e) {
  redirect_back(null, 'ตรวจสอบซ้ำไม่สำเร็จ: ' . $e->getMessage());
}

/* ---------- Insert ---------- */
try {
  $pdo->beginTransaction();

  // 1) Users
  $password_hash = password_hash($password, PASSWORD_BCRYPT);
  $insUserSql = '
    INSERT INTO users (username, email, full_name, phone, password_hash, is_active, role, created_at, updated_at)
    VALUES (:username, :email, :full_name, :phone, :hash, 1, "committee", NOW(), NOW())
  ';
  $insUser = $pdo->prepare($insUserSql);
  $insUser->execute([
    ':username'  => $username,
    ':email'     => $email,       // NULL ได้ถ้าไม่กรอก
    ':full_name' => $full_name,
    ':phone'     => ($phone !== '' ? $phone : null),
    ':hash'      => $password_hash
  ]);
  $user_id = (int)$pdo->lastInsertId();
  if ($user_id <= 0) throw new RuntimeException('ไม่สามารถสร้างผู้ใช้ได้');

  // 2) Committees — ใส่เฉพาะคอลัมน์ที่มีจริง
  $colVals = [
    'user_id'        => $user_id,
    'committee_code' => $committee_code,
  ];

  if (column_exists($pdo,'committees','position'))     $colVals['position']     = ($position !== '' ? $position : null);
  if (column_exists($pdo,'committees','department'))   $colVals['department']   = ($department !== '' ? $department : null);
  if (column_exists($pdo,'committees','joined_date'))  $colVals['joined_date']  = $joined_date;
  if (column_exists($pdo,'committees','shares'))       $colVals['shares']       = $shares;
  if (column_exists($pdo,'committees','house_number')) $colVals['house_number'] = ($house_no !== '' ? $house_no : null);
  if (column_exists($pdo,'committees','address'))      $colVals['address']      = ($address !== '' ? $address : null);
  if (column_exists($pdo,'committees','station_id'))   $colVals['station_id']   = $station_id;
  // บางสคีมาอาจมี phone ใน committees ด้วย (ส่วนใหญ่ไม่มี)
  if (column_exists($pdo,'committees','phone'))        $colVals['phone']        = ($phone !== '' ? $phone : null);

  $cols = array_keys($colVals);
  $ph   = array_map(fn($c)=>':'.$c, $cols);
  $sql  = 'INSERT INTO committees ('.implode(',', $cols).') VALUES ('.implode(',', $ph).')';
  $params = [];
  foreach ($colVals as $c=>$v) { $params[':'.$c] = $v; }

  $insCom = $pdo->prepare($sql);
  $insCom->execute($params);

  $pdo->commit();
  redirect_back('เพิ่มกรรมการสำเร็จ');

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  $msg = $e->getMessage();
  $lower = strtolower($msg);
  if (str_contains($lower, 'duplicate') || str_contains($lower, 'unique')) {
    if (str_contains($lower, 'username'))       $msg = 'ชื่อผู้ใช้นี้ถูกใช้แล้ว';
    elseif (str_contains($lower, 'email'))      $msg = 'อีเมลนี้ถูกใช้แล้ว';
    elseif (str_contains($lower, 'committee'))  $msg = 'รหัสกรรมการนี้ถูกใช้แล้ว';
  } elseif (str_contains($lower, 'foreign key') && str_contains($lower, 'user')) {
    $msg = 'อ้างอิงผู้ใช้ไม่ถูกต้อง';
  }

  redirect_back(null, 'บันทึกล้มเหลว: ' . $msg);
}
