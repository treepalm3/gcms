<?php
// finance_delete.php
session_start();
date_default_timezone_set('Asia/Bangkok');

function redirect_back($msg, $err=false){
  $p = $err ? 'err' : 'ok';
  header("Location: finance.php?{$p}=".urlencode($msg));
  exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  redirect_back('คุณไม่มีสิทธิ์ดำเนินการนี้', true);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect_back('Invalid request method.', true);
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
  redirect_back('CSRF token ไม่ถูกต้อง', true);
}

require_once '../config/db.php';

$transaction_code = trim($_POST['transaction_code'] ?? '');
if ($transaction_code==='') {
  redirect_back('รหัสรายการไม่ถูกต้อง', true);
}

try {
  $st = $pdo->prepare("SELECT id FROM financial_transactions WHERE transaction_code=:c");
  $st->execute([':c'=>$transaction_code]);
  if (!$st->fetch(PDO::FETCH_ASSOC)) {
    redirect_back('ไม่พบรายการที่ต้องการลบ', true);
  }

  $del = $pdo->prepare("DELETE FROM financial_transactions WHERE transaction_code=:c");
  $del->execute([':c'=>$transaction_code]);

  redirect_back('ลบรายการสำเร็จ');
} catch (Throwable $e) {
  if ($e instanceof PDOException && $e->getCode()==='23000') {
    redirect_back('ไม่สามารถลบได้ เนื่องจากมีข้อมูลอื่นเชื่อมโยงอยู่', true);
  }
  // error_log($e->getMessage());
  redirect_back('เกิดข้อผิดพลาดในการลบข้อมูล', true);
}
