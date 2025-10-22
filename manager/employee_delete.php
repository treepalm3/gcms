<?php
// manager/employee_delete.php
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== Auth & DB ===== */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
  header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
  exit();
}
require_once __DIR__ . '/../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
  header('Location: employee.php?err=' . urlencode('เชื่อมต่อฐานข้อมูลไม่ได้')); exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

/* ===== Helpers ===== */
function redirect_back($ok=null,$err=null){
  $q=[]; if($ok!==null)$q[]='ok='.urlencode($ok); if($err!==null)$q[]='err='.urlencode($err);
  header('Location: employee.php'.(empty($q)?'':'?'.implode('&',$q))); exit();
}
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

/* ===== CSRF Check ===== */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  redirect_back(null, 'โทเค็นไม่ถูกต้อง กรุณาลองใหม่');
}

/* ===== Read & Validate Form Data ===== */
$target_user_id = (int)($_POST['user_id'] ?? 0);
$delete_user    = isset($_POST['delete_user']) && $_POST['delete_user'] == '1';

if ($target_user_id <= 0) {
  redirect_back(null, 'ไม่พบรหัสผู้ใช้ที่ต้องการลบ');
}

if ($delete_user && $target_user_id === (int)$_SESSION['user_id']) {
  redirect_back(null, 'ไม่สามารถลบบัญชีผู้ใช้ของตัวเองได้');
}

/* ===== Main Logic ===== */
try {
  $pdo->beginTransaction();

  // 1. Check if the target is indeed an employee
  $st_check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
  $st_check->execute([$target_user_id]);
  $target_role = $st_check->fetchColumn();

  if ($target_role !== 'employee') {
    throw new RuntimeException('ผู้ใช้ที่เลือกไม่ใช่พนักงาน');
  }

  // 2. Delete from employees table
  $delEmp = $pdo->prepare("DELETE FROM employees WHERE user_id = ?");
  $delEmp->execute([$target_user_id]);

  // 3. If "delete user" is checked, proceed to delete from users table
  if ($delete_user) {
    // Check for other roles that might prevent deletion
    $has_other_roles = false;
    $checkTables = [
      'managers'  => "SELECT COUNT(*) FROM managers WHERE user_id = ?",
      'committee' => "SELECT COUNT(*) FROM committee WHERE user_id = ?",
      'admins'    => "SELECT COUNT(*) FROM admins WHERE user_id = ?",
    ];

    foreach ($checkTables as $table => $sql) {
      if (table_exists($pdo, $table)) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$target_user_id]);
        if ((int)$stmt->fetchColumn() > 0) {
          $has_other_roles = true;
          break;
        }
      }
    }

    if ($has_other_roles) {
      // Cannot delete user, but employee role is removed. Commit and notify.
      $pdo->commit();
      redirect_back('ลบสถานะพนักงานแล้ว แต่ไม่สามารถลบบัญชีผู้ใช้ได้เนื่องจากมีบทบาทอื่นอยู่');
    } else {
      // Safe to delete user
      $delUser = $pdo->prepare("DELETE FROM users WHERE id = ?");
      $delUser->execute([$target_user_id]);
    }
  }

  $pdo->commit();
  $msg = $delete_user ? 'ลบพนักงานและบัญชีผู้ใช้เรียบร้อย' : 'ลบข้อมูลพนักงานเรียบร้อย';
  redirect_back($msg);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_back(null, 'ล้มเหลว: ' . $e->getMessage());
}

