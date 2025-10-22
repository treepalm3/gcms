<?php
// manager/member_edit.php
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
$user_id      = (int)($_POST['user_id'] ?? 0);
$member_code  = trim($_POST['member_code'] ?? '');
$full_name    = trim($_POST['full_name'] ?? '');
$phone        = trim($_POST['phone'] ?? '');
$tier         = trim($_POST['tier'] ?? 'Bronze');
$shares       = (int)($_POST['shares'] ?? 0);
$points       = (int)($_POST['points'] ?? 0);
$joined_date  = trim($_POST['joined_date'] ?? '');
$house_number = trim($_POST['house_number'] ?? '');
$address      = trim($_POST['address'] ?? '');

if ($user_id <= 0 || $member_code === '' || $full_name === '') {
  redirect_back(null, 'กรอกข้อมูลไม่ครบ (รหัส, ชื่อ-สกุล)');
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

  // 2. Update members table
  $st_mem = $pdo->prepare("
    UPDATE members SET
      tier = :tier,
      points = :points,
      shares = :shares,
      house_number = :house,
      address = :addr,
      joined_date = :joined
    WHERE user_id = :uid
  ");
  $st_mem->execute([
    ':tier' => $tier, ':points' => $points, ':shares' => $shares,
    ':house' => ($house_number ?: null), ':addr' => ($address ?: null),
    ':joined' => ($joined_date ?: null), ':uid' => $user_id
  ]);

  $pdo->commit();
  redirect_back('บันทึกข้อมูลสมาชิกเรียบร้อย');
} catch (Throwable $e) {
  $pdo->rollBack();
  redirect_back(null, 'บันทึกล้มเหลว: ' . $e->getMessage());
}