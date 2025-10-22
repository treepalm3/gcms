<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$token = $_GET['token'] ?? '';
$is_token_valid = false;
$error_msg = '';

if ($token) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = :token LIMIT 1");
        $stmt->execute([':token' => $token]);
        $reset_request = $stmt->fetch();

        if ($reset_request && strtotime($reset_request['expires_at']) > time()) {
            $is_token_valid = true;
        } else {
            $error_msg = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว';
        }
    } catch (Exception $e) {
        $error_msg = 'เกิดข้อผิดพลาดในระบบ';
    }
} else {
    $error_msg = 'ไม่พบ Token สำหรับการรีเซ็ตรหัสผ่าน';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ตั้งรหัสผ่านใหม่ — สหกรณ์ชุมชนบ้านภูเขาทอง</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../../assets/css/login.css" />
  <style> body { font-family: 'Prompt', sans-serif; } </style>
</head>
<body>
  <main class="auth">
    <section class="auth-card" role="form">
      <h1 class="auth-title">ตั้งรหัสผ่านใหม่</h1>

      <?php if ($is_token_valid): ?>
        <p class="auth-subtitle">กรุณาตั้งรหัสผ่านใหม่ของคุณ</p>
        <form class="form" action="reset_password_process.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
          <div class="form-group">
            <label for="password">รหัสผ่านใหม่</label>
            <div class="input">
              <i class="fa-solid fa-lock input-icon"></i>
              <input id="password" name="password" type="password" required placeholder="กรอกรหัสผ่านใหม่อย่างน้อย 8 ตัว">
            </div>
          </div>
          <div class="form-group">
            <label for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
            <div class="input">
              <i class="fa-solid fa-lock input-icon"></i>
              <input id="confirm_password" name="confirm_password" type="password" required placeholder="กรอกรหัสผ่านใหม่อีกครั้ง">
            </div>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk"></i> บันทึกรหัสผ่านใหม่
          </button>
        </form>
      <?php else: ?>
        <div class="alert alert-error" role="alert">
          <i class="fa-solid fa-circle-exclamation"></i>
          <span><?= htmlspecialchars($error_msg) ?></span>
        </div>
        <a class="btn btn-outline w-full" href="forgot_password.php"><i class="fa-solid fa-arrow-left"></i> ลองอีกครั้ง</a>
      <?php endif; ?>

    </section>
  </main>
  <footer class="footer">
    <div class="copyright">© <?= date('Y'); ?> Phukhaothong Community Cooperative</div>
  </footer>
</body>
</html>