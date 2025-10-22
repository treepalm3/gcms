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

$supplier_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$supplier = null;
$error_msg = '';
$success_msg = '';

if (!$supplier_id) {
    redirect_with_message('inventory.php', 'ID ซัพพลายเออร์ไม่ถูกต้อง', true);
}

// Handle POST request for update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error_msg = 'CSRF token ไม่ถูกต้อง';
    } else {
        $post_id = filter_input(INPUT_POST, 'supplier_id', FILTER_VALIDATE_INT);
        if ($post_id === $supplier_id) {
            $supplier_name = trim($_POST['supplier_name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $fuel_types = trim($_POST['fuel_types'] ?? '');
            $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 5]]);

            if (empty($supplier_name)) {
                $error_msg = 'กรุณากรอกชื่อบริษัทซัพพลายเออร์';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE suppliers SET 
                            supplier_name = :name, contact_person = :contact, phone = :phone, 
                            email = :email, fuel_types = :fuels, rating = :rating
                         WHERE supplier_id = :id"
                    );
                    $stmt->execute([
                        ':name' => $supplier_name,
                        ':contact' => $contact_person ?: null,
                        ':phone' => $phone ?: null,
                        ':email' => empty($email) ? null : (filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null),
                        ':fuels' => $fuel_types ?: null,
                        ':rating' => $rating === false ? 0 : $rating,
                        ':id' => $supplier_id
                    ]);
                    redirect_with_message('inventory.php#suppliers-panel', 'อัปเดตข้อมูลซัพพลายเออร์สำเร็จ');
                } catch (Throwable $e) {
                    $error_msg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch supplier data for GET request
try {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        redirect_with_message('inventory.php', 'ไม่พบซัพพลายเออร์ที่ระบุ', true);
    }
} catch (Throwable $e) {
    $error_msg = 'ไม่สามารถโหลดข้อมูลซัพพลายเออร์ได้: ' . $e->getMessage();
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
<title>แก้ไขซัพพลายเออร์ | <?= htmlspecialchars($site_name) ?></title>
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
    <!-- Sidebar is omitted for a focused edit page, but can be included if needed -->
    <main class="col-12 p-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fa-solid fa-truck-fast me-2"></i> แก้ไขข้อมูลซัพพลายเออร์</h2>
        <a href="inventory.php#suppliers-panel" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> กลับไปหน้ารายการ</a>
      </div>

      <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
      <?php endif; ?>
      <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
      <?php endif; ?>

      <?php if ($supplier): ?>
      <div class="panel" style="max-width: 800px; margin: auto;">
        <form method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="supplier_id" value="<?= htmlspecialchars($supplier['supplier_id']) ?>">
          
          <div class="row g-3">
            <div class="col-12">
              <label for="supplier_name" class="form-label">ชื่อบริษัท <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="supplier_name" name="supplier_name" value="<?= htmlspecialchars($supplier['supplier_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label for="contact_person" class="form-label">ผู้ติดต่อ</label>
              <input type="text" class="form-control" id="contact_person" name="contact_person" value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label for="phone" class="form-label">โทรศัพท์</label>
              <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label for="email" class="form-label">อีเมล</label>
              <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($supplier['email'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label for="fuel_types" class="form-label">ประเภทน้ำมันที่จัดส่ง</label>
              <input type="text" class="form-control" id="fuel_types" name="fuel_types" value="<?= htmlspecialchars($supplier['fuel_types'] ?? '') ?>" placeholder="เช่น ดีเซล, แก๊สโซฮอล์ 95">
            </div>
            <div class="col-md-6">
              <label for="rating" class="form-label">คะแนน (0-5)</label>
              <input type="number" class="form-control" id="rating" name="rating" value="<?= htmlspecialchars($supplier['rating'] ?? '0') ?>" min="0" max="5">
            </div>
            <div class="col-md-6">
              <label class="form-label">ส่งล่าสุด</label>
              <input type="text" class="form-control" value="<?= htmlspecialchars($supplier['last_delivery_date'] ?? 'ยังไม่มีข้อมูล') ?>" readonly>
            </div>
          </div>

          <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i> บันทึกการเปลี่ยนแปลง</button>
            <a href="inventory.php#suppliers-panel" class="btn btn-secondary">ยกเลิก</a>
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

