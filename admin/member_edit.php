<?php
// admin/member_edit.php — แก้ไขข้อมูลสมาชิก (users + members)
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== ตรวจสอบการล็อกอินและสิทธิ์ ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน'));
  exit();
}
$role = $_SESSION['role'] ?? '';
if ($role !== 'admin') {
  header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
  exit();
}

/* ===== เชื่อมต่อฐานข้อมูล ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบ $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

/* ===== ตรวจวิธีเรียก + CSRF ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: member.php?err=' . urlencode('วิธีการเรียกไม่ถูกต้อง'));
  exit();
}
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
  header('Location: member.php?err=' . urlencode('CSRF token ไม่ถูกต้อง'));
  exit();
}

/* ===== รับและตรวจสอบข้อมูล ===== */
$user_id     = (int)($_POST['user_id'] ?? 0);
$member_code = trim((string)($_POST['member_code'] ?? '')); // readonly ในฟอร์ม
$full_name   = trim((string)($_POST['full_name'] ?? ''));
$phone       = trim((string)($_POST['phone'] ?? ''));
$tier        = trim((string)($_POST['tier'] ?? ''));
$shares      = (int)($_POST['shares'] ?? 0);
$points      = (int)($_POST['points'] ?? 0);
$joined_date = trim((string)($_POST['joined_date'] ?? ''));
$house_number= trim((string)($_POST['house_number'] ?? ''));
$address     = trim((string)($_POST['address'] ?? ''));

if ($user_id <= 0 || $member_code === '' || $full_name === '') {
  header('Location: member.php?err=' . urlencode('ข้อมูลไม่ครบถ้วน'));
  exit();
}
if ($joined_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $joined_date)) {
  $joined_date = date('Y-m-d');
}

/* ===== อัปเดต ===== */
try {
  $pdo->beginTransaction();

  // 1) users
  $st = $pdo->prepare('UPDATE users SET full_name=?, phone=? WHERE id=? LIMIT 1');
  $st->execute([$full_name, $phone, $user_id]);

  // 2) members (ระบุทั้ง user_id และ member_code เพื่อความแม่นยำ)
  $st = $pdo->prepare('
      UPDATE members
         SET tier=?, points=?, joined_date=?, shares=?, house_number=?, address=?
       WHERE user_id=? AND member_code=?
       LIMIT 1
  ');
  $st->execute([$tier, $points, $joined_date ?: null, $shares, $house_number, $address, $user_id, $member_code]);

  if ($st->rowCount() === 0) {
    // ไม่พบแถวให้แก้ไข (อาจถูกลบ หรือ member_code ไม่ตรง)
    $pdo->rollBack();
    header('Location: member.php?err=' . urlencode('ไม่พบข้อมูลสมาชิกที่จะแก้ไข'));
    exit();
  }

  $pdo->commit();
  header('Location: member.php?ok=' . urlencode('แก้ไขข้อมูลสมาชิกเรียบร้อย'));
  exit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('member_edit error: '.$e->getMessage());
  header('Location: member.php?err=' . urlencode('แก้ไขไม่สำเร็จ'));
  exit();
}
