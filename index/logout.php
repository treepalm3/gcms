<?php
// logout.php
declare(strict_types=1);
session_start();

// (ถ้ามี) เคลียร์คุกกี้ remember me
if (isset($_COOKIE['remember_token'])) {
  setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
}

// พยายามบันทึกเวลาล็อกเอาต์ลง DB ถ้าโครงสร้างรองรับ
$userId = $_SESSION['user_id'] ?? null;
try {
  // รองรับทั้งกรณี db.php อยู่ ../config หรือ ./config
  $dbFile = __DIR__ . '/../config/db.php';
  if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }

  if ($userId && file_exists($dbFile)) {
    require_once $dbFile; // ต้องมีตัวแปร $pdo (PDO)
    if (isset($pdo)) {
      // เช็คว่ามีคอลัมน์ last_logout_at ในตาราง users หรือไม่
      $sql = "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'users'
                AND column_name = 'last_logout_at'";
      $hasCol = (int)$pdo->query($sql)->fetchColumn() > 0;

      if ($hasCol) {
        $stmt = $pdo->prepare("UPDATE users SET last_logout_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $userId]);
      }
    }
  }
} catch (Throwable $e) {
  // เงียบไว้ในโปรดักชัน หรือจะเขียน log ก็ได้
}

// ล้างตัวแปรทั้งหมดใน session
$_SESSION = [];

// ลบคุกกี้ของ session (ถ้าถูกตั้งค่าแบบใช้คุกกี้)
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params['path'], $params['domain'], $params['secure'], $params['httponly']
  );
}

// ทำลาย session
session_destroy();

// (ออปชัน) รีเจนไอดีใหม่เพิ่มความปลอดภัยในรอบถัดไป
session_start();
session_regenerate_id(true);
session_write_close();

// ส่งกลับหน้าเข้าสู่ระบบพร้อมข้อความ
$ok = urlencode('ออกจากระบบเรียบร้อย');
header("Location: ../index.php");
exit;
