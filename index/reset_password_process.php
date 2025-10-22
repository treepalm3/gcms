<?php
session_start();
require_once __DIR__ . '/../../config/db.php'; // $pdo

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Invalid request');
}

$token = $_POST['token'] ?? '';
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// ตรวจสอบข้อมูลเบื้องต้น
if (empty($token) || empty($password) || $password !== $confirm_password) {
    header('Location: login.php?err=ข้อมูลไม่ถูกต้อง');
    exit();
}
if (strlen($password) < 8) {
    header('Location: reset_password.php?token='.$token.'&err=รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. ตรวจสอบ Token อีกครั้ง
    $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = :token AND expires_at > NOW() LIMIT 1");
    $stmt->execute([':token' => $token]);
    $reset_request = $stmt->fetch();

    if (!$reset_request) {
        $pdo->rollBack();
        header('Location: login.php?err=ลิงก์หมดอายุหรือไม่ถูกต้อง');
        exit();
    }

    $email = $reset_request['email'];

    // 2. Hash รหัสผ่านใหม่
    $hashed_password = password_hash($password, PASSWORD_ARGON2ID);

    // 3. อัปเดตรหัสผ่านในตาราง users
    $stmt_update = $pdo->prepare("UPDATE users SET password = :password WHERE email = :email");
    $stmt_update->execute([':password' => $hashed_password, ':email' => $email]);
    
    // 4. ลบ Token ที่ใช้งานแล้ว
    $stmt_delete = $pdo->prepare("DELETE FROM password_resets WHERE email = :email");
    $stmt_delete->execute([':email' => $email]);

    $pdo->commit();

    header('Location: login.php?msg=เปลี่ยนรหัสผ่านสำเร็จแล้ว กรุณาเข้าสู่ระบบอีกครั้ง');
    exit();

} catch (Exception $e) {
    $pdo->rollBack();
    // error_log($e->getMessage());
    header('Location: login.php?err=เกิดข้อผิดพลาดร้ายแรง');
    exit();
}