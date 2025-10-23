<?php
// index.php (หรือ pos_login.php)
// หน้าสำหรับให้พนักงานป้อนรหัสก่อนเข้า POS
date_default_timezone_set('Asia/Bangkok');

// (เชื่อมต่อ DB ...
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; 

$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง'; // Default
try {
    // 1. ลองดึงจาก app_settings (json) ก่อน
    $st_app = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
    if ($r_app = $st_app->fetch(PDO::FETCH_ASSOC)) {
        $sys = json_decode($r_app['json_value'], true) ?: [];
        if (!empty($sys['site_name'])) {
            $site_name = $sys['site_name'];
        }
    } else {
        // 2. ถ้าไม่เจอ ให้ลองดึงจาก settings.comment (แบบเดิม)
        $st_name = $pdo->query("SELECT comment FROM settings WHERE setting_name='site_name' LIMIT 1");
        $sn = $st_name ? $st_name->fetchColumn() : false;
        if (!empty($sn)) {
            $site_name = $sn;
        }
    }
} catch (Throwable $e) { /* ใช้ Default */ }

$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Prompt', sans-serif;
            background-color: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1);
        }
        .login-card .form-control-lg {
            font-size: 1.2rem;
            padding: 0.75rem 1.25rem;
        }
        .login-card .btn-lg {
            padding: 0.75rem 1.25rem;
            font-size: 1.1rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="login-card text-center">
        <i class="bi bi-fuel-pump-fill" style="font-size: 3rem; color: #0d6efd;"></i>
        <h2 class="h4 my-3 fw-bold"><?= htmlspecialchars($site_name) ?></h2>
        <p class="text-muted">กรุณาป้อนรหัสพนักงานเพื่อเริ่มการขาย</p>
        
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="pos.php" method="GET">
            <div class="mb-3">
                <label for="emp_code" class="form-label visually-hidden">รหัสพนักงาน</label>
                <input type="text" 
                       class="form-control form-control-lg text-center" 
                       name="emp_code" 
                       id="emp_code" 
                       placeholder="รหัสพนักงาน (เช่น E-001)" 
                       required 
                       autofocus>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-box-arrow-in-right me-2"></i>เข้าสู่ระบบ
                </button>
            </div>
        </form>
    </div>
</body>
</html>