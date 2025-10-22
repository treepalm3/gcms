<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

// Helper function for redirection
function redirect_with_message($url, $message, $is_error = false) {
    $param = $is_error ? 'err' : 'ok';
    header("Location: {$url}?{$param}=" . urlencode($message));
    exit();
}

// Security & DB
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    redirect_with_message('/index/login.php', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้', true);
}
require_once '../config/db.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pump_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$pump = null;
$fuels = [];
$error_msg = '';
$success_msg = '';

if (!$pump_id) {
    redirect_with_message('inventory.php', 'ID ปั๊มไม่ถูกต้อง', true);
}

// Fetch available fuels for dropdown
try {
    $fuels = $pdo->query("SELECT fuel_id, fuel_name FROM fuel_prices ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error_msg = 'ไม่สามารถโหลดข้อมูลชนิดน้ำมันได้';
}

// Handle POST request for update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error_msg = 'CSRF token ไม่ถูกต้อง';
    } else {
        $post_id = filter_input(INPUT_POST, 'pump_id', FILTER_VALIDATE_INT);
        if ($post_id === $pump_id) {
            $pump_name = trim($_POST['pump_name'] ?? '');
            $fuel_id = filter_input(INPUT_POST, 'fuel_id', FILTER_VALIDATE_INT);
            $status = in_array($_POST['status'], ['active', 'maintenance', 'offline']) ? $_POST['status'] : 'active';
            $last_maintenance = trim($_POST['last_maintenance'] ?? '');
            $pump_location = trim($_POST['pump_location'] ?? '');

            if (empty($pump_name)) {
                $error_msg = 'กรุณากรอกชื่อปั๊ม';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE pumps SET 
                            pump_name = :name, fuel_id = :fuel_id, status = :status, 
                            last_maintenance = :maintenance, pump_location = :location
                         WHERE pump_id = :id"
                    );
                    $stmt->execute([
                        ':name' => $pump_name,
                        ':fuel_id' => $fuel_id ?: null,
                        ':status' => $status,
                        ':maintenance' => empty($last_maintenance) ? null : $last_maintenance,
                        ':location' => $pump_location ?: null,
                        ':id' => $pump_id
                    ]);
                    redirect_with_message('inventory.php#pumps-panel', 'อัปเดตข้อมูลปั๊มสำเร็จ');
                } catch (Throwable $e) {
                    $error_msg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch pump data for GET request
try {
    $stmt = $pdo->prepare("SELECT * FROM pumps WHERE pump_id = ?");
    $stmt->execute([$pump_id]);
    $pump = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pump) {
        redirect_with_message('inventory.php', 'ไม่พบปั๊มที่ระบุ', true);
    }
} catch (Throwable $e) {
    if (empty($error_msg)) $error_msg = 'ไม่สามารถโหลดข้อมูลปั๊มได้: ' . $e->getMessage();
}

// Basic user info for layout
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$current_name = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
$current_role = $_SESSION['role'] ?? 'admin';
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>จัดการปั๊ม | <?= htmlspecialchars($site_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
<link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container-fluid">
    <a class="navbar-brand fw-800" href="admin_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
    <div class="d-flex align-items-center gap-3 ms-auto">
      <div class="nav-identity text-end d-none d-sm-block">
        <div class="nav-name"><?= htmlspecialchars($current_name) ?></div>
        <div class="nav-sub"><?= htmlspecialchars($current_role_th) ?></div>
      </div>
      <a href="profile.php" class="avatar-circle text-decoration-none"><?= htmlspecialchars($avatar_text) ?></a>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <div class="row">
    <main class="col-12 p-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fa-solid fa-cog me-2"></i> จัดการปั๊มน้ำมัน</h2>
        <a href="inventory.php#pumps-panel" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> กลับไปหน้ารายการ</a>
      </div>

      <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
      <?php endif; ?>
      <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
      <?php endif; ?>

      <?php if ($pump): ?>
      <div class="panel" style="max-width: 800px; margin: auto;">
        <form method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="pump_id" value="<?= htmlspecialchars($pump['pump_id']) ?>">
          
          <div class="row g-3">
            <div class="col-md-6">
              <label for="pump_name" class="form-label">ชื่อปั๊ม <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="pump_name" name="pump_name" value="<?= htmlspecialchars($pump['pump_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label for="pump_location" class="form-label">ตำแหน่ง</label>
              <input type="text" class="form-control" id="pump_location" name="pump_location" value="<?= htmlspecialchars($pump['pump_location'] ?? '') ?>" placeholder="เช่น หน้าลาน, หลังร้าน">
            </div>
            <div class="col-md-6">
              <label for="fuel_id" class="form-label">ชนิดน้ำมัน</label>
              <select class="form-select" id="fuel_id" name="fuel_id">
                <option value="">-- ไม่กำหนด --</option>
                <?php foreach ($fuels as $fuel): ?>
                  <option value="<?= $fuel['fuel_id'] ?>" <?= ($pump['fuel_id'] == $fuel['fuel_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($fuel['fuel_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label for="status" class="form-label">สถานะ</label>
              <select class="form-select" id="status" name="status" required>
                <option value="active" <?= ($pump['status'] == 'active') ? 'selected' : '' ?>>ใช้งานได้</option>
                <option value="maintenance" <?= ($pump['status'] == 'maintenance') ? 'selected' : '' ?>>บำรุงรักษา</option>
                <option value="offline" <?= ($pump['status'] == 'offline') ? 'selected' : '' ?>>ปิดใช้งาน</option>
              </select>
            </div>
            <div class="col-md-6">
              <label for="last_maintenance" class="form-label">วันที่บำรุงรักษาล่าสุด</label>
              <input type="date" class="form-control" id="last_maintenance" name="last_maintenance" value="<?= htmlspecialchars($pump['last_maintenance'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">ยอดขายวันนี้</label>
              <input type="text" class="form-control" value="฿<?= number_format((float)($pump['total_sales_today'] ?? 0), 2) ?>" readonly>
            </div>
          </div>

          <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i> บันทึกการเปลี่ยนแปลง</button>
            <a href="inventory.php#pumps-panel" class="btn btn-secondary">ยกเลิก</a>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>

<footer class="footer">© <?= date('Y'); ?> <?= htmlspecialchars($site_name) ?></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

