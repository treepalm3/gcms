<?php
session_start();
require_once __DIR__ . '/../../config/db.php'; // $pdo

// 1. ตรวจสอบ Method และ CSRF Token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Invalid request');
}

$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);

if (!$email) {
    header('Location: forgot_password.php?status=error&msg=รูปแบบอีเมลไม่ถูกต้อง');
    exit();
}

try {
    // 2. ตรวจสอบว่ามีอีเมลนี้ในระบบหรือไม่
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        // 3. สร้าง Token ที่ปลอดภัยและไม่ซ้ำกัน
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token ใช้งานได้ 1 ชั่วโมง

        // 4. บันทึก Token ลงฐานข้อมูล
        $stmt_insert = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at)");
        $stmt_insert->execute([':email' => $email, ':token' => $token, ':expires_at' => $expires_at]);
        
        // 5. ส่งอีเมลพร้อมลิงก์สำหรับรีเซ็ต (ส่วนนี้ต้องใช้ Library เพิ่มเติม)
        $reset_link = "http://localhost/your-project/auth/reset_password.php?token=" . $token; // << แก้ไข URL ให้ถูกต้อง
        
        // ============ ส่วนของการส่งอีเมล (ตัวอย่าง) ============
        // ในการใช้งานจริง แนะนำให้ใช้ Library เช่น PHPMailer
        // $to = $email;
        // $subject = "คำขอรีเซ็ตรหัสผ่านสำหรับ สหกรณ์ฯ";
        // $message = "สวัสดีครับ,\n\nกรุณาคลิกลิงก์ด้านล่างเพื่อรีเซ็ตรหัสผ่านของคุณ:\n" . $reset_link . "\n\nหากคุณไม่ได้ร้องขอ โปรดเพิกเฉยอีเมลนี้\n\nขอบคุณครับ";
        // $headers = 'From: noreply@yourcoop.com' . "\r\n";
        // mail($to, $subject, $message, $headers);
        // ====================================================

    }
    // ไม่ว่าจะเจออีเมลหรือไม่ ให้แสดงข้อความสำเร็จเสมอ เพื่อป้องกันการเดาอีเมลในระบบ
    $success_msg = "หากอีเมลของคุณมีอยู่ในระบบ เราได้ส่งลิงก์สำหรับรีเซ็ตรหัสผ่านไปให้แล้ว";
    header('Location: forgot_password.php?status=success&msg=' . urlencode($success_msg));
    exit();

} catch (Exception $e) {
    // สามารถ Log error ไว้ดูเบื้องหลังได้
    // error_log($e->getMessage());
    header('Location: forgot_password.php?status=error&msg=เกิดข้อผิดพลาดในระบบ');
    exit();
}