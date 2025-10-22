<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$msg = $_GET['msg'] ?? '';
$status = $_GET['status'] ?? '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ลืมรหัสผ่าน — สหกรณ์ชุมชนบ้านภูเขาทอง</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/login.css" />
  <style> body { font-family: 'Prompt', sans-serif; } </style>
</head>
<body>
  <main class="auth">
    <section class="auth-card" role="form">
      <h1 class="auth-title">ลืมรหัสผ่าน</h1>
      
      <?php if ($msg && $status === 'success'): ?>
        <div class="alert alert-success" role="alert">
          <i class="fa-solid fa-circle-check"></i>
          <span><?= htmlspecialchars($msg) ?></span>
        </div>
        <p class="auth-subtitle">กรุณาตรวจสอบกล่องจดหมาย (Inbox) และ Junk Mail ของคุณ</p>
        <a class="btn btn-outline w-full" href="login.php"><i class="fa-solid fa-right-to-bracket"></i> กลับไปหน้าเข้าสู่ระบบ</a>
      <?php else: ?>
        <p class="auth-subtitle">กรุณากรอกอีเมลที่ผูกกับบัญชีของคุณ เราจะส่งลิงก์สำหรับรีเซ็ตรหัสผ่านไปให้</p>
        <?php if ($msg && $status === 'error'): ?>
        <div class="alert alert-error" role="alert">
          <i class="fa-solid fa-circle-exclamation"></i>
          <span><?= htmlspecialchars($msg) ?></span>
        </div>
        <?php endif; ?>
        <form class="form" action="forgot_password_process.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
          <div class="form-group">
            <label for="email">อีเมล</label>
            <div class="input">
              <i class="fa-regular fa-envelope input-icon" aria-hidden="true"></i>
              <input id="email" name="email" type="email" required placeholder="กรอกอีเมลของคุณ" />
            </div>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-paper-plane"></i> ส่งลิงก์รีเซ็ตรหัสผ่าน
          </button>
          <div class="divider"></div>
          <a class="btn btn-outline w-full" href="login.php">
            <i class="fa-solid fa-chevron-left"></i> กลับไปหน้าเข้าสู่ระบบ
          </a>
        </form>
      <?php endif; ?>
    </section>
  </main>
  <footer class="footer">
    <div class="copyright">© <?= date('Y'); ?> Phukhaothong Community Cooperative</div>
  </footer>
</body>
</html>