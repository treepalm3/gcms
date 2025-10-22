<?php
// admin/member_create.php — เพิ่มสมาชิกใหม่ (สร้าง users + members)
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
  header('Location: member.php?err=' . urlencode('CSRF token ไม่ถูกต้อง'));
  exit();
}

/* ===== Helpers ===== */
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:c');
    $st->execute([':db'=>$db, ':tb'=>$table, ':c'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

/* ===== รับค่า ===== */
$username     = trim($_POST['username'] ?? '');
$password_raw = (string)($_POST['password'] ?? '');
$full_name    = trim($_POST['full_name'] ?? '');
$email        = trim($_POST['email'] ?? '');
$phone        = trim($_POST['phone'] ?? '');

$member_code  = trim($_POST['member_code'] ?? '');
$tier         = trim($_POST['tier'] ?? '');
$shares       = (int)($_POST['shares'] ?? 0);
$points       = (int)($_POST['points'] ?? 0);
$joined_date  = trim($_POST['joined_date'] ?? date('Y-m-d'));
$house_number = trim($_POST['house_number'] ?? '');
$address      = trim($_POST['address'] ?? '');

if ($username==='' || $password_raw==='' || $full_name==='' || $member_code==='') {
  header('Location: member.php?err=' . urlencode('กรอกข้อมูลไม่ครบ'));
  exit();
}

try {
  $pdo->beginTransaction();

  // กัน username/member_code ซ้ำ
  $st = $pdo->prepare("SELECT 1 FROM users WHERE username=? LIMIT 1");
  $st->execute([$username]);
  if ($st->fetchColumn()) { throw new RuntimeException('Username นี้มีผู้ใช้แล้ว'); }

  $st = $pdo->prepare("SELECT 1 FROM members WHERE member_code=? LIMIT 1");
  $st->execute([$member_code]);
  if ($st->fetchColumn()) { throw new RuntimeException('รหัสสมาชิกนี้มีอยู่แล้ว'); }

  // รองรับชื่อคอลัมน์รหัสผ่านทั้ง password_hash และ password
  $pwdCol = column_exists($pdo,'users','password_hash') ? 'password_hash'
         : (column_exists($pdo,'users','password') ? 'password' : 'password_hash');

  // สร้างผู้ใช้ (role=member)
  $sqlUser = "INSERT INTO users (username, {$pwdCol}, email, full_name, phone, role, created_at)
              VALUES (:u, :p, :e, :f, :ph, 'member', NOW())";
  $stmt = $pdo->prepare($sqlUser);
  $stmt->execute([
    ':u'=>$username,
    ':p'=>password_hash($password_raw, PASSWORD_DEFAULT),
    ':e'=>$email ?: null,
    ':f'=>$full_name,
    ':ph'=>$phone ?: null,
  ]);
  $newUserId = (int)$pdo->lastInsertId();

  // สร้างสมาชิก
  $sqlMem = "INSERT INTO members (user_id, member_code, tier, points, shares, joined_date, house_number, address)
             VALUES (:uid, :code, :tier, :points, :shares, :joined, :hn, :addr)";
  $stmt = $pdo->prepare($sqlMem);
  $stmt->execute([
    ':uid'=>$newUserId,
    ':code'=>$member_code,
    ':tier'=>$tier ?: null,
    ':points'=>$points,
    ':shares'=>$shares,
    ':joined'=>$joined_date ?: date('Y-m-d'),
    ':hn'=>$house_number ?: null,
    ':addr'=>$address ?: null,
  ]);

  $pdo->commit();
  header('Location: member.php?ok=' . urlencode('เพิ่มสมาชิกเรียบร้อย'));
  exit();

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $msg = $e->getMessage();
  if (stripos($msg,'duplicate')!==false || stripos($msg,'unique')!==false) {
    $msg = 'ข้อมูลซ้ำ (username หรือ member_code)';
  }
  header('Location: member.php?err=' . urlencode('เพิ่มไม่สำเร็จ: '.$msg));
  exit();
}
