<?php
// finance_edit.php
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

// ใช้รหัสรายการเป็นกุญแจ (ฟอร์ม set เป็น readonly)
$transaction_code = trim($_POST['transaction_code'] ?? '');
$transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
$type             = trim($_POST['type'] ?? '');
$category         = trim($_POST['category'] ?? '');
$description      = trim($_POST['description'] ?? '');
$amount           = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$reference        = trim($_POST['reference'] ?? '');

if ($transaction_code==='' || $transaction_date==='' || $type==='' || $category==='' || $description==='' || $amount<=0) {
  redirect_back('ข้อมูลไม่ครบถ้วน', true);
}
if (!in_array($type, ['income','expense'], true)) {
  redirect_back('ประเภทต้องเป็น income หรือ expense เท่านั้น', true);
}

try {
  // มีรายการนี้ไหม
  $chk = $pdo->prepare("SELECT id FROM financial_transactions WHERE transaction_code=:c");
  $chk->execute([':c'=>$transaction_code]);
  $row = $chk->fetch(PDO::FETCH_ASSOC);
  if (!$row) redirect_back('ไม่พบรหัสรายการที่ต้องการแก้ไข', true);

  $upd = $pdo->prepare("
    UPDATE financial_transactions
      SET transaction_date=:dt, type=:type, category=:cat, description=:desc, amount=:amt, reference_id=:ref, updated_at=NOW()
    WHERE transaction_code=:code
  ");
  $upd->execute([
    ':dt'=>$transaction_date,
    ':type'=>$type,
    ':cat'=>$category,
    ':desc'=>$description,
    ':amt'=>$amount,
    ':ref'=>($reference!==''? $reference: null),
    ':code'=>$transaction_code
  ]);

  redirect_back('แก้ไขรายการสำเร็จ');
} catch (Throwable $e) {
  // error_log($e->getMessage());
  redirect_back('เกิดข้อผิดพลาดในการบันทึกข้อมูล', true);
}
