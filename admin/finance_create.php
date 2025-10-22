<?php
// finance_create.php
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

// รับค่า
$transaction_code = trim($_POST['transaction_code'] ?? '');
$transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
$type             = trim($_POST['type'] ?? '');
$category         = trim($_POST['category'] ?? '');
$description      = trim($_POST['description'] ?? '');
$amount           = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$reference        = trim($_POST['reference'] ?? '');

if ($transaction_code==='' || $transaction_date==='' || $type==='' || $category==='' || $description==='' || $amount<=0) {
  redirect_back('กรอกข้อมูลให้ครบ (รหัส/วันที่/ประเภท/หมวด/รายละเอียด/จำนวนเงิน>0)', true);
}
if (!in_array($type, ['income','expense'], true)) {
  redirect_back('ประเภทต้องเป็น income หรือ expense เท่านั้น', true);
}

try {
  // รหัสซ้ำ
  $st = $pdo->prepare("SELECT COUNT(*) FROM financial_transactions WHERE transaction_code=:c");
  $st->execute([':c'=>$transaction_code]);
  if ($st->fetchColumn() > 0) {
    redirect_back('รหัสรายการนี้มีอยู่แล้วในระบบ', true);
  }

  $pdo->beginTransaction();
  $ins = $pdo->prepare("
    INSERT INTO financial_transactions
      (transaction_code, transaction_date, type, category, description, amount, reference_id, user_id, created_at, updated_at)
    VALUES
      (:code, :dt, :type, :cat, :desc, :amt, :ref, :uid, NOW(), NOW())
  ");
  $ins->execute([
    ':code'=>$transaction_code,
    ':dt'=>$transaction_date,
    ':type'=>$type,
    ':cat'=>$category,
    ':desc'=>$description,
    ':amt'=>$amount,
    ':ref'=>($reference!==''? $reference: null),
    ':uid'=>(int)$_SESSION['user_id'],
  ]);
  $pdo->commit();
  redirect_back('เพิ่มรายการสำเร็จ');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  // error_log($e->getMessage());
  redirect_back('เกิดข้อผิดพลาดในการบันทึกข้อมูล', true);
}
