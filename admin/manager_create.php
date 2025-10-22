<?php
// admin/manager_create.php
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
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'admin') {
    header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=' . urlencode('เกิดข้อผิดพลาดของระบบ'));
  exit();
}

/* ---------- Helpers ---------- */
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
function get_setting(PDO $pdo, string $name, $default=null){
  try{
    if (!table_exists($pdo,'settings')) return $default;
    if (column_exists($pdo,'settings','setting_name') && column_exists($pdo,'settings','setting_value')){
      $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name=:n LIMIT 1');
      $st->execute([':n'=>$name]);
      $v = $st->fetchColumn();
      return $v!==false ? $v : $default;
    }
  }catch(Throwable $e){}
  return $default;
}
function redirect_back(string $ok=null, string $err=null){
  $q = [];
  if ($ok !== null)  $q[] = 'ok='.urlencode($ok);
  if ($err !== null) $q[] = 'err='.urlencode($err);
  header('Location: manager.php' . (empty($q)?'':'?'.implode('&',$q)));
  exit();
}
function random_password(int $len=12): string {
  $bytes = random_bytes($len);
  return substr(strtr(base64_encode($bytes), '+/=', 'xyz'), 0, $len);
}
function slug_username(string $base): string {
  $base = strtolower(preg_replace('/[^a-z0-9]+/i','',$base));
  return $base !== '' ? $base : 'user';
}

/* ---------- CSRF ---------- */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  http_response_code(403);
  redirect_back(null, 'โทเค็นไม่ถูกต้อง กรุณาลองใหม่');
}

/* ---------- Read & validate form ---------- */
$mode    = $_POST['mode'] ?? 'create';            // create | edit | replace
$mode    = in_array($mode, ['create','edit','replace'], true) ? $mode : 'create';
$mgr_id  = (int)($_POST['current_manager_id'] ?? 0);

$full_name = trim($_POST['full_name'] ?? '');
$email     = strtolower(trim($_POST['email'] ?? ''));
$phone     = trim($_POST['phone'] ?? '');
$access    = $_POST['access_level'] ?? 'readonly';
$salary    = (string)($_POST['salary'] ?? '0');
$score     = (string)($_POST['performance_score'] ?? '0');
$shares    = (string)($_POST['shares'] ?? '0');
$house_no  = trim($_POST['house_number'] ?? '');
$addr      = trim($_POST['address'] ?? '');

if ($full_name === '' || $email === '') {
  redirect_back(null, 'กรอกชื่อและอีเมลให้ครบ');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  redirect_back(null, 'รูปแบบอีเมลไม่ถูกต้อง');
}
$allowedAccess = ['readonly','limited','full'];
if (!in_array($access, $allowedAccess, true)) $access = 'readonly';

$salary = is_numeric($salary) ? max(0, (float)$salary) : 0.0;
$score  = is_numeric($score)  ? min(100, max(0, (float)$score)) : 0.0;
$shares = ctype_digit((string)$shares) ? (int)$shares : 0;

if (!table_exists($pdo,'users') || !table_exists($pdo,'managers')) {
  redirect_back(null, 'ยังไม่มีตาราง users หรือ managers');
}

/* ---------- Station ---------- */
$station_id = (int)get_setting($pdo, 'station_id', 1);

/* ---------- Main logic ---------- */
try {
  $pdo->beginTransaction();

  // โหลดแถวผู้จัดการปัจจุบัน (ถ้ามี)
  // โหลดแถวผู้จัดการปัจจุบัน (ถ้ามี) + อีเมลเดิมของผู้ใช้
  $current_manager = null;
  if ($mgr_id > 0) {
    $stm = $pdo->prepare('
      SELECT m.id, m.user_id, u.email AS current_email
      FROM managers m
      JOIN users u ON u.id = m.user_id
      WHERE m.id = :id
      LIMIT 1
    ');
    $stm->execute([':id'=>$mgr_id]);
    $current_manager = $stm->fetch(PDO::FETCH_ASSOC) ?: null;
  } else {
    $stm = $pdo->query('
      SELECT m.id, m.user_id, u.email AS current_email
      FROM managers m
      JOIN users u ON u.id = m.user_id
      ORDER BY m.created_at DESC, m.id DESC
      LIMIT 1
    ');
    $current_manager = $stm->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  $target_user_id = null;

  if ($mode === 'edit' && $current_manager) {
    $target_user_id = (int)$current_manager['user_id'];
    $current_email_in_db = strtolower($current_manager['current_email'] ?? '');
  
    if ($email !== '' && $email !== $current_email_in_db) {
      // มีการเปลี่ยนอีเมล
      $find = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
      $find->execute([':email'=>$email]);
      $urow = $find->fetch(PDO::FETCH_ASSOC);
  
      if ($urow && (int)$urow['id'] !== $target_user_id) {
        // อีเมลนี้เป็นของ "ผู้ใช้อื่น" → ถือเป็นการเปลี่ยนผู้บริหารอัตโนมัติ
        $target_user_id = (int)$urow['id'];
        $updUser = $pdo->prepare('
          UPDATE users
          SET full_name=:name, phone=:phone, updated_at=NOW(),
              role = CASE WHEN role="" THEN "manager" ELSE role END
          WHERE id=:id
        ');
        $updUser->execute([
          ':name'=>$full_name, ':phone'=>$phone, ':id'=>$target_user_id
        ]);
        // *สำคัญ*: ไม่อัปเดตอีเมลของผู้ใช้เดิม เพื่อไม่ให้ชน UNIQUE
      } else {
        // อีเมลใหม่นี้ยังไม่มีใครใช้ → อัปเดตผู้ใช้เดิมได้เลย
        $updUser = $pdo->prepare('
          UPDATE users
          SET full_name=:name, email=:email, phone=:phone, updated_at=NOW()
          WHERE id=:id
        ');
        $updUser->execute([
          ':name'=>$full_name, ':email'=>$email, ':phone'=>$phone, ':id'=>$target_user_id
        ]);
      }
    } else {
      // ไม่ได้เปลี่ยนอีเมล → อัปเดตฟิลด์อื่นๆ ของผู้ใช้เดิม
      $updUser = $pdo->prepare('
        UPDATE users
        SET full_name=:name, phone=:phone, updated_at=NOW()
        WHERE id=:id
      ');
      $updUser->execute([
        ':name'=>$full_name, ':phone'=>$phone, ':id'=>$target_user_id
      ]);
    }
  } else {
    // create / replace : หา user จากอีเมล หรือสร้างใหม่ แล้วชี้ managers ไปยัง user นี้
    $find = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $find->execute([':email'=>$email]);
    $urow = $find->fetch(PDO::FETCH_ASSOC);

    if ($urow) {
      $target_user_id = (int)$urow['id'];
      // อัปเดตโปรไฟล์ด้วยข้อมูลล่าสุด
      $updUser = $pdo->prepare('UPDATE users SET full_name=:name, phone=:phone, updated_at=NOW(), role = CASE WHEN role="" THEN "manager" ELSE role END WHERE id=:id');
      $updUser->execute([
        ':name'=>$full_name, ':phone'=>$phone, ':id'=>$target_user_id
      ]);
    } else {
      // สร้าง user ใหม่
      $local = strstr($email, '@', true) ?: $full_name;
      $baseUsername = slug_username($local);
      $username = $baseUsername;

      // ทำให้ username ไม่ซ้ำ
      $i = 1;
      $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username=:u');
      while (true) {
        $chk->execute([':u'=>$username]);
        if ((int)$chk->fetchColumn() === 0) break;
        $i++;
        $username = $baseUsername . $i;
      }

      $plain = random_password(12);
      $hash  = password_hash($plain, PASSWORD_BCRYPT);

      $insUser = $pdo->prepare('
        INSERT INTO users (username, email, full_name, phone, password_hash, is_active, role, created_at, updated_at)
        VALUES (:username, :email, :name, :phone, :hash, 1, "manager", NOW(), NOW())
      ');
      $insUser->execute([
        ':username'=>$username, ':email'=>$email, ':name'=>$full_name, ':phone'=>$phone, ':hash'=>$hash
      ]);
      $target_user_id = (int)$pdo->lastInsertId();
      // หมายเหตุ: ถ้าต้องการแจ้งรหัสผ่านให้ผู้ใช้ สามารถต่อยอดส่งอีเมล/พิมพ์ออกได้จากที่นี่
    }
  }

  // ป้องกันความว่างผิดปกติ
  if (!$target_user_id) {
    throw new RuntimeException('ไม่สามารถกำหนดผู้ใช้สำหรับผู้บริหารได้');
  }

  // อัปเซิร์ตผู้จัดการ 1 แถว (ใช้ uq_one_row บังคับ single row)
  $upsert = $pdo->prepare('
    INSERT INTO managers (user_id, station_id, salary, performance_score, access_level, shares, house_number, address)
    VALUES (:user_id, :station_id, :salary, :score, :access, :shares, :house_no, :addr)
    AS new
    ON DUPLICATE KEY UPDATE
      user_id           = new.user_id,
      salary            = new.salary,
      performance_score = new.performance_score,
      access_level      = new.access_level,
      shares            = new.shares,
      house_number      = new.house_number,
      address           = new.address
  ');
  $upsert->execute([
    ':user_id'    => $target_user_id,
    ':station_id' => $station_id,
    ':salary'     => $salary,
    ':score'      => $score,
    ':access'     => $access,
    ':shares'     => $shares,
    ':house_no'   => ($house_no !== '' ? $house_no : null),
    ':addr'       => ($addr !== '' ? $addr : null),
  ]);

  $pdo->commit();

  $msg = $mode === 'edit' ? 'บันทึกการแก้ไขผู้บริหารสำเร็จ'
       : ($mode === 'replace' ? 'เปลี่ยนผู้บริหารสำเร็จ' : 'กำหนดผู้บริหารสำเร็จ');
  redirect_back($msg, null);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  // จับ error กรณี FK/Unique ให้ข้อความอ่านง่ายขึ้น
  $err = $e->getMessage();
  if (stripos($err, 'fk_managers_user') !== false) {
    $err = 'ไม่พบผู้ใช้งานที่เลือก หรือถูกลบไปแล้ว';
  } elseif (stripos($err, 'uq_manager_user') !== false) {
    $err = 'อีเมลนี้ถูกใช้เป็นผู้บริหารอยู่แล้ว';
  } elseif (stripos($err, 'uq_one_row') !== false) {
    $err = 'ไม่สามารถสร้างแถวใหม่ซ้ำได้ (ระบบอนุญาตผู้บริหารได้เพียง 1 คน)';
  }
  redirect_back(null, 'บันทึกล้มเหลว: ' . $err);
}
