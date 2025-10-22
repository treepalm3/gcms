<?php
// manager/committee_delete.php
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
require_once $dbFile; // ต้องมี $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Role: อนุญาต manager และ admin ---------- */
try {
  $role = $_SESSION['role'] ?? 'guest';
  if (!in_array($role, ['manager','admin'], true)) {
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

/* ---------- Input ---------- */
$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$delete_user = isset($_POST['delete_user']) && (string)$_POST['delete_user'] === '1';
if ($user_id <= 0) redirect_back(null, 'ข้อมูลไม่ถูกต้อง: ไม่พบผู้ใช้ที่ต้องการลบ');

/* ---------- Pre-check ---------- */
if (!table_exists($pdo,'users'))       redirect_back(null, 'ยังไม่มีตาราง users');
if (!table_exists($pdo,'committees'))  redirect_back(null, 'ยังไม่มีตาราง committees');

try {
  // ข้อมูลไว้แสดงข้อความ และกันลบผู้ที่ไม่ใช่กรรมการ
  $st = $pdo->prepare('
    SELECT u.full_name, u.email, u.role, c.committee_code
    FROM users u
    LEFT JOIN committees c ON c.user_id = u.id
    WHERE u.id = :uid
    LIMIT 1
  ');
  $st->execute([':uid'=>$user_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) redirect_back(null, 'ไม่พบผู้ใช้นี้');

  $full_name = $row['full_name'] ?? 'ไม่ระบุชื่อ';
  $code = $row['committee_code'] ?? '';
  $target_role = $row['role'] ?? '';

  // อนุญาตจัดการได้เฉพาะบัญชีที่เป็นกรรมการ
  if ($target_role !== 'committee') {
    redirect_back(null, 'ไม่สามารถลบได้: บัญชีนี้ไม่ใช่บทบาทกรรมการ');
  }

  $pdo->beginTransaction();

  if ($delete_user) {
    // กันเคสลบไม่ได้เพราะถูกใช้งานที่ตารางที่ RESTRICT (เช่น managers)
    if (table_exists($pdo,'managers')) {
      $chk = $pdo->prepare('SELECT COUNT(*) FROM managers WHERE user_id=:uid');
      $chk->execute([':uid'=>$user_id]);
      if ((int)$chk->fetchColumn() > 0) {
        throw new RuntimeException('ไม่สามารถลบผู้ใช้ได้ เพราะยังเป็นผู้บริหารอยู่ (ตาราง managers)');
      }
    }

    // ลบ user -> ถ้า FK เป็น CASCADE จะลบบทบาทที่เกี่ยวข้องไปด้วย
    $delU = $pdo->prepare('DELETE FROM users WHERE id=:uid');
    $delU->execute([':uid'=>$user_id]);
    if ($delU->rowCount() < 1) throw new RuntimeException('ลบผู้ใช้ไม่สำเร็จ');

    $pdo->commit();
    redirect_back("ลบบัญชีผู้ใช้ {$full_name}" . ($code ? " ({$code})" : '') . " สำเร็จ");
  } else {
    // ลบเฉพาะบทบาทกรรมการ
    $delC = $pdo->prepare('DELETE FROM committees WHERE user_id=:uid');
    $delC->execute([':uid'=>$user_id]);
    if ($delC->rowCount() < 1) throw new RuntimeException('ไม่พบแถวกรรมการของผู้ใช้นี้');

    $pdo->commit();
    redirect_back("ลบกรรมการ {$full_name}" . ($code ? " ({$code})" : '') . " สำเร็จ");
  }

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $msg = $e->getMessage();
  // แปลข้อความ FK ให้เข้าใจง่าย
  $low = strtolower($msg);
  if (str_contains($low,'foreign key') || str_contains($low,'restrict')) {
    $msg = 'ลบไม่ได้ เพราะผู้ใช้นี้ยังถูกอ้างอิงอยู่ในตารางอื่น (เช่น managers/invoices/ฯลฯ)';
  }
  redirect_back(null, 'บันทึกล้มเหลว: ' . $msg);
}
