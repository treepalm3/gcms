<?php
// admin/employee_delete.php — ลบพนักงาน (และเลือกได้ว่าจะลบ user ทิ้งด้วย)
// ข้อดี: ตรวจสิทธิ์, ตรวจ CSRF, ใช้ transaction, จัดการ FK/บทบาทที่เกี่ยวข้อง

session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== ต้องล็อกอินก่อน ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน'));
  exit();
}

/* ===== เชื่อมต่อฐานข้อมูล ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องกำหนด $pdo เป็น PDO

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบ $pdo ใน config/db.php');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

/* ===== ตรวจสิทธิ์ (admin) ===== */
try {
  $current_user_id = (int)($_SESSION['user_id'] ?? 0);
  $current_role    = $_SESSION['role'] ?? '';
  $is_admin = ($current_role === 'admin');

  if (!$is_admin) {
    $st = $pdo->prepare("SELECT 1 FROM admins WHERE user_id = ? LIMIT 1");
    $st->execute([$current_user_id]);
    $is_admin = (bool)$st->fetchColumn();
  }
  if (!$is_admin) {
    header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=' . urlencode('เกิดข้อผิดพลาดของระบบ'));
  exit();
}

/* ===== ตรวจ CSRF ===== */
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  header('Location: employee.php?err=' . urlencode('CSRF token ไม่ถูกต้อง'));
  exit();
}

/* ===== Utils บางส่วน ===== */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

/* ===== หา station_id จาก settings (เพื่อความปลอดภัย multi-station) ===== */
$station_id = 1;
try {
  if (table_exists($pdo,'settings')) {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $station_id = (int)($row['setting_value'] ?? 1);
    }
  }
} catch (Throwable $e) {
  // ใช้ค่า default 1
}

/* ===== รับค่า ===== */
$target_user_id = (int)($_POST['user_id'] ?? 0);
$delete_user    = isset($_POST['delete_user']) && $_POST['delete_user'] == '1';

if ($target_user_id <= 0) {
  header('Location: employee.php?err=' . urlencode('ไม่พบรหัสผู้ใช้ที่ต้องการลบ'));
  exit();
}

/* ===== ป้องกันลบบัญชีตัวเองแบบพ่วง (เพื่อไม่ให้ล็อกอินหลุดโดยไม่ตั้งใจ) ===== */
if ($delete_user && $target_user_id === (int)$_SESSION['user_id']) {
  header('Location: employee.php?err=' . urlencode('ไม่สามารถลบบัญชีผู้ใช้ของตัวเองได้'));
  exit();
}

try {
  $pdo->beginTransaction();

  // ตรวจว่ามีพนักงานในสถานีนี้หรือไม่ (อิงตาม unique uq_employee_user)
  $chk = $pdo->prepare("SELECT id, station_id FROM employees WHERE user_id = ? LIMIT 1");
  $chk->execute([$target_user_id]);
  $emp = $chk->fetch(PDO::FETCH_ASSOC);

  if ($emp && (int)$emp['station_id'] !== $station_id) {
    // กันการลบต่างสถานี
    throw new RuntimeException('พนักงานไม่อยู่ในสถานีนี้');
  }

  // 1) ลบแถวพนักงานในตาราง employees (หากมี)
  if ($emp) {
    $delEmp = $pdo->prepare("DELETE FROM employees WHERE user_id = ? AND station_id = ?");
    $delEmp->execute([$target_user_id, $station_id]);
  }

  // 2) ถ้าติ๊ก "ลบผู้ใช้" — เคลียร์บทบาทที่อาจ RESTRICT ก่อน แล้วค่อยลบ users
  if ($delete_user) {
    // ตารางที่ RESTRICT การลบ users: managers (ON DELETE RESTRICT)
    if (table_exists($pdo, 'managers')) {
      $pdo->prepare("DELETE FROM managers WHERE user_id = ?")->execute([$target_user_id]);
    }

    // ตารางอื่น ๆ ส่วนใหญ่กำหนด CASCADE หรือ SET NULL ไว้แล้ว:
    // - admins, committees, members => CASCADE
    // - employees => SET NULL (เราเพิ่งลบไปแล้ว)
    // - fuel_moves.user_id => SET NULL
    // จึงไม่ต้องยุ่งเพิ่ม

    // ลบ users
    $delUser = $pdo->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
    $delUser->execute([$target_user_id]);
  }

  $pdo->commit();

  $msg = $delete_user ? 'ลบพนักงานและบัญชีผู้ใช้เรียบร้อย' : 'ลบพนักงานเรียบร้อย';
  header('Location: employee.php?ok=' . urlencode($msg));
  exit();

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  // แยกข้อความ FK ชัด ๆ ให้พอเข้าใจ
  $err = $e->getMessage();
  if (stripos($err, 'foreign key') !== false || stripos($err, 'constraint') !== false) {
    $err = 'ลบไม่สำเร็จ เนื่องจากมีข้อมูลที่เกี่ยวข้อง (Foreign Key Constraint)';
  }
  header('Location: employee.php?err=' . urlencode('ล้มเหลว: ' . $err));
  exit();
}
