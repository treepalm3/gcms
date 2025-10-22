<?php
// manager/member_create.php
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== Auth & DB ===== */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
  header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
  exit();
}
require_once __DIR__ . '/../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
  header('Location: member.php?err=' . urlencode('เชื่อมต่อฐานข้อมูลไม่ได้')); exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

/* ===== Helpers ===== */
function redirect_back($ok=null,$err=null){
  $q=[]; if($ok!==null)$q[]='ok='.urlencode($ok); if($err!==null)$q[]='err='.urlencode($err);
  header('Location: member.php'.(empty($q)?'':'?'.implode('&',$q))); exit();
}

/* ===== CSRF Check ===== */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  redirect_back(null, 'โทเค็นไม่ถูกต้อง กรุณาลองใหม่');
}

/* ===== Read & Validate Form Data ===== */
// User data
$username   = trim($_POST['username'] ?? '');
$password   = $_POST['password'] ?? '';
$full_name  = trim($_POST['full_name'] ?? '');
$email      = trim($_POST['email'] ?? '');

// Member data
$member_code  = trim($_POST['member_code'] ?? '');
$phone        = trim($_POST['phone'] ?? '');
$tier         = trim($_POST['tier'] ?? 'Bronze');
$shares       = (int)($_POST['shares'] ?? 0);
$points       = (int)($_POST['points'] ?? 0);
$joined_date  = trim($_POST['joined_date'] ?? '');
$house_number = trim($_POST['house_number'] ?? '');
$address      = trim($_POST['address'] ?? '');

if ($username === '' || $password === '' || $full_name === '' || $member_code === '') {
  redirect_back(null, 'กรอกข้อมูลที่จำเป็นให้ครบถ้วน (ชื่อผู้ใช้, รหัสผ่าน, ชื่อ-สกุล, รหัสสมาชิก)');
}
if (strlen($password) < 8) {
  redirect_back(null, 'รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร');
}

$joined_date = $joined_date !== '' ? $joined_date : date('Y-m-d');

/* ===== Main Logic ===== */
try {
  $pdo->beginTransaction();

  // Check for duplicates
  $st = $pdo->prepare("SELECT 1 FROM users WHERE username = :u LIMIT 1");
  $st->execute([':u'=>$username]);
  if ($st->fetchColumn()) { throw new Exception('Username นี้มีผู้ใช้แล้ว'); }

  if ($email !== '') {
    $st = $pdo->prepare("SELECT 1 FROM users WHERE email = :em LIMIT 1");
    $st->execute([':em'=>$email]);
    if ($st->fetchColumn()) { throw new Exception('อีเมลนี้มีผู้ใช้แล้ว'); }
  }

  $st = $pdo->prepare("SELECT 1 FROM members WHERE member_code = :code LIMIT 1");
  $st->execute([':code'=>$member_code]);
  if ($st->fetchColumn()) { throw new Exception('รหัสสมาชิกนี้มีอยู่แล้ว'); }

  // 1. Create user
  $password_hash = password_hash($password, PASSWORD_DEFAULT);
  $st = $pdo->prepare("
    INSERT INTO users (username, email, full_name, phone, password_hash, is_active, role, created_at)
    VALUES (:u, :em, :fn, :ph, :pw, 1, 'member', NOW())
  ");
  $st->execute([
    ':u'  => $username,
    ':em' => ($email ?: null),
    ':fn' => $full_name,
    ':ph' => ($phone ?: null),
    ':pw' => $password_hash
  ]);
  $new_uid = (int)$pdo->lastInsertId();

  // 2. Create member record
  $st_mem = $pdo->prepare("
    INSERT INTO members (user_id, member_code, tier, points, shares, house_number, address, joined_date, is_active, created_at)
    VALUES (:uid, :code, :tier, :points, :shares, :house, :addr, :joined, 1, NOW())
  ");
  $st_mem->execute([
    ':uid'    => $new_uid,
    ':code'   => $member_code,
    ':tier'   => $tier,
    ':points' => $points,
    ':shares' => $shares,
    ':house'  => ($house_number ?: null),
    ':addr'   => ($address ?: null),
    ':joined' => $joined_date,
  ]);

  $pdo->commit();
  redirect_back('เพิ่มสมาชิกเรียบร้อย');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_back(null, 'เพิ่มสมาชิกไม่สำเร็จ: '.$e->getMessage());
}