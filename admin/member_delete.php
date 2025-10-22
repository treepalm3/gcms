<?php
// admin/member_delete.php — Soft-delete สมาชิก + (ทางเลือก) ลบบัญชีผู้ใช้ถ้าไม่ผูกกับบทบาทอื่น
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== ตรวจสอบการล็อกอิน ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน'));
  exit();
}

/* ===== เชื่อมต่อฐานข้อมูล ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

/* ===== ตรวจสิทธิ์เป็นแอดมิน ===== */
try {
  $role = $_SESSION['role'] ?? '';
  if ($role !== 'admin') {
    header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์'));
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=' . urlencode('เกิดข้อผิดพลาดของระบบ'));
  exit();
}

/* ===== ตรวจ CSRF + รับค่า ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: member.php?err=' . urlencode('วิธีการเรียกไม่ถูกต้อง'));
  exit();
}
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
  header('Location: member.php?err=' . urlencode('CSRF token ไม่ถูกต้อง'));
  exit();
}

$user_id = (int)($_POST['user_id'] ?? 0);
$want_delete_user = !empty($_POST['delete_user']) && $_POST['delete_user'] == '1';
if ($user_id <= 0) {
  header('Location: member.php?err=' . urlencode('ไม่พบผู้ใช้ที่ต้องการลบ'));
  exit();
}

/* ===== Helpers ===== */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

try {
  $pdo->beginTransaction();

  // 1) ตรวจว่ามีสมาชิก active ของ user นี้ไหม
  $st = $pdo->prepare("SELECT id, member_code FROM members WHERE user_id = ? AND is_active = 1 LIMIT 1");
  $st->execute([$user_id]);
  $mem = $st->fetch(PDO::FETCH_ASSOC);

  if (!$mem) {
    $pdo->rollBack();
    header('Location: member.php?err=' . urlencode('ไม่พบสมาชิกที่ใช้งานอยู่ หรือถูกลบไปแล้ว'));
    exit();
  }

  // 2) Soft-delete แถวสมาชิก (ไม่ยุ่งกับ member_code)
  $st = $pdo->prepare("UPDATE members SET is_active=0, deleted_at=NOW() WHERE user_id=? AND is_active=1");
  $st->execute([$user_id]);

  // 3) ถ้าติ๊ก “ลบบัญชีผู้ใช้ด้วย” -> ลบ users ก็ต่อเมื่อไม่ผูกกับบทบาทอื่น
  if ($want_delete_user) {
    $has_other_roles = false;

    // ตรวจในตารางบทบาทอื่นๆ อย่างปลอดภัย (มีตารางอยู่จริงค่อยเช็ค)
    $checkTables = [
      'employees' => "SELECT COUNT(*) FROM employees WHERE user_id = ? AND (1=1) LIMIT 1",
      'managers'  => "SELECT COUNT(*) FROM managers  WHERE user_id = ? LIMIT 1",
      'committee' => "SELECT COUNT(*) FROM committee WHERE user_id = ? LIMIT 1",
      // ถ้ามีตารางอื่น ๆ ให้เติมที่นี่
    ];
    foreach ($checkTables as $tb => $sql) {
      if (table_exists($pdo, $tb)) {
        $s = $pdo->prepare($sql);
        $s->execute([$user_id]);
        if ((int)$s->fetchColumn() > 0) {
          $has_other_roles = true; break;
        }
      }
    }

    // มีสมาชิกอื่น active ของ user เดียวกันอยู่หรือไม่ (ปกติควรไม่มี)
    $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE user_id = ? AND is_active = 1");
    $st->execute([$user_id]);
    if ((int)$st->fetchColumn() > 0) {
      $has_other_roles = true;
    }

    if (!$has_other_roles) {
      // ปลอดภัย: ลบผู้ใช้ออก (หรือจะเปลี่ยนสถานะแทนก็ได้)
      $del = $pdo->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
      $del->execute([$user_id]);
    }
  }

  $pdo->commit();
  header('Location: member.php?ok=' . urlencode('ลบสมาชิกเรียบร้อย'));
  exit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log($e->getMessage());
  header('Location: member.php?err=' . urlencode('ลบไม่สำเร็จ: '.$e->getCode()));
  exit();
}
