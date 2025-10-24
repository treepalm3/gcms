<?php
// finance_create.php
session_start();
date_default_timezone_set('Asia/Bangkok');

function redirect_back($msg, $err=false){
  $p = $err ? 'err' : 'ok';
  // [แก้ไข] เพิ่ม tab=financial เพื่อให้กลับไปแท็บเดิม
  header("Location: finance.php?tab=financial&{$p}=".urlencode($msg));
  exit;
}

// ตรวจสอบสิทธิ์และการเข้าถึง
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  redirect_back('คุณไม่มีสิทธิ์ดำเนินการนี้', true);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect_back('Invalid request method.', true);
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
  redirect_back('CSRF token ไม่ถูกต้อง', true);
}

// เชื่อมต่อฐานข้อมูล
require_once '../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    // ควร log error จริงๆ ไว้ด้วย
    error_log("Database connection failed in finance_create.php");
    redirect_back('เชื่อมต่อฐานข้อมูลล้มเหลว', true);
}

// ดึงค่า stationId (จาก session หรือ settings ถ้ามี) - ต้องมีค่านี้!
$stationId = $_SESSION['station_id'] ?? 1; // สมมติว่าเก็บ station_id ใน session หรือใช้ค่า default=1
// หรือจะดึงจากตาราง settings ก็ได้ ถ้าไม่ได้เก็บใน session
/*
try {
    $stmt_sid = $pdo->query("SELECT setting_value FROM settings WHERE setting_name = 'station_id' LIMIT 1");
    $sid_val = $stmt_sid->fetchColumn();
    if ($sid_val !== false) {
        $stationId = (int)$sid_val;
    }
} catch (Throwable $e) {
    error_log("Could not fetch station_id from settings: " . $e->getMessage());
    // อาจจะใช้ค่า default หรือ redirect แจ้งข้อผิดพลาด
    redirect_back('ไม่สามารถระบุรหัสสถานีได้', true);
}
*/


// รับค่าจากฟอร์ม
$transaction_code = trim($_POST['transaction_code'] ?? ''); // รับค่าที่สร้างอัตโนมัติมา
$transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d H:i:s'));
$type             = trim($_POST['type'] ?? '');
$category         = trim($_POST['category'] ?? '');
$description      = trim($_POST['description'] ?? '');
$amount           = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$reference        = trim($_POST['reference_id'] ?? ''); // ใช้ reference_id ให้ตรงกับ name ในฟอร์ม

// ตรวจสอบข้อมูลที่จำเป็น (ยกเว้น transaction_code)
if ($transaction_date==='' || $type==='' || $category==='' || $description==='' || $amount<=0) {
  redirect_back('กรอกข้อมูลให้ครบ (วันที่/ประเภท/หมวด/รายละเอียด/จำนวนเงิน>0)', true);
}
if (!in_array($type, ['income','expense'], true)) {
  redirect_back('ประเภทต้องเป็น income หรือ expense เท่านั้น', true);
}

// --- เริ่มการบันทึก ---
try {
  // ไม่ต้องเช็ครหัสซ้ำแล้ว ลบส่วนนั้นออก

  // สร้าง Transaction Code ใหม่ที่นี่ เพื่อความแน่นอน หรือจะใช้ค่าจาก form ก็ได้
  // ถ้าใช้ค่าจาก Form ต้องมั่นใจว่า Form ส่งค่ามาเสมอและรูปแบบถูกต้อง
  if (empty($transaction_code)) {
      $transaction_code = 'FT-' . date('Ymd-His'); // สร้างใหม่ถ้าค่าที่ส่งมาว่างเปล่า (กันเหนียว)
  }

  $pdo->beginTransaction();

  $ins = $pdo->prepare("
    INSERT INTO financial_transactions
      (transaction_code, transaction_date, type, category, description, amount, reference_id, user_id, created_at, updated_at, station_id)
    VALUES
      (:code, :dt, :type, :cat, :desc, :amt, :ref, :uid, NOW(), NOW(), :sid)
  ");

  $success = $ins->execute([
    ':code' => $transaction_code,
    ':dt'   => $transaction_date, // ควรตรวจสอบ format ให้ถูกต้องก่อนบันทึก
    ':type' => $type,
    ':cat'  => $category,
    ':desc' => $description,
    ':amt'  => $amount,
    ':ref'  => ($reference !== '' ? $reference : null),
    ':uid'  => (int)$_SESSION['user_id'],
    ':sid'  => (int)$stationId, // บันทึก station_id
  ]);

  if ($success) {
      $pdo->commit();
      redirect_back('เพิ่มรายการสำเร็จ');
  } else {
      // กรณี execute ล้มเหลว (อาจเกิดจาก constraint อื่นๆ ใน DB)
      $pdo->rollBack();
      $errorInfo = $ins->errorInfo();
      error_log("Finance Create Execute Failed: " . ($errorInfo[2] ?? 'Unknown error'));
      redirect_back('เกิดข้อผิดพลาดในการบันทึกข้อมูล (Execute failed)', true);
  }

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
      $pdo->rollBack();
  }
  // Log error จริงๆ ไว้เสมอ
  error_log("Finance Create Error: " . $e->getMessage());
  // แสดง error จริงๆ ตอน redirect กลับไป (ถ้าต้องการดีบักง่ายขึ้น)
  redirect_back('เกิดข้อผิดพลาด: ' . $e->getMessage(), true);
  // หรือใช้ข้อความทั่วไป
  // redirect_back('เกิดข้อผิดพลาดในการบันทึกข้อมูล', true);
}
?>