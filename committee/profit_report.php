<?php
// committee/profit_report.php

session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== ตรวจสอบการล็อกอินและสิทธิ์ ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
  exit();
}

/* ===== เชื่อมต่อฐานข้อมูล ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ===== ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์ ===== */
try {
  $current_name = $_SESSION['full_name'] ?? 'กรรมการ';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'committee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
  exit();
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== Helpers =====
if (!function_exists('nf')) {
    function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
}

// ===== โหลด system settings =====
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$station_id = 1;
try {
    $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $station_id = (int)$r['setting_value'];
        $site_name = $r['comment'] ?: $site_name;
    }
} catch (Throwable $e) {}


/* ==============================================
 *
 * ดึงข้อมูลกำไรคงเหลือ
 *
 * ============================================== */
$lots_data = [];
$totals = [
    'liters' => 0,
    'cost_value' => 0,
    'sale_value' => 0,
    'profit' => 0
];
$error_message = null;

try {
    // ใช้ View v_fuel_lots_current
    $stmt = $pdo->prepare("
        SELECT
            v.lot_code,
            t.name AS tank_name,
            fp.fuel_name,
            v.remaining_liters_calc AS remaining_liters,
            v.unit_cost_full AS cost_per_liter, 
            fp.price AS price_per_liter,
            v.remaining_value AS remaining_cost_value,
            (v.remaining_liters_calc * fp.price) AS potential_sale_value,
            ((v.remaining_liters_calc * fp.price) - v.remaining_value) AS potential_profit
        FROM
            v_fuel_lots_current v
        JOIN
            fuel_tanks t ON v.tank_id = t.id
        JOIN
            fuel_prices fp ON v.fuel_id = fp.fuel_id AND v.station_id = fp.station_id
        WHERE
            v.remaining_liters_calc > 0.01
        ORDER BY
            t.name, v.received_at
    ");
    
    $stmt->execute(); // ไม่ต้อง bind station_id เพราะ View v_fuel_lots_current ควรสรุปมาแล้ว
    $lots_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // สรุปยอดรวม
    foreach ($lots_data as $lot) {
        $totals['liters'] += (float)$lot['remaining_liters'];
        $totals['cost_value'] += (float)$lot['remaining_cost_value'];
        $totals['sale_value'] += (float)$lot['potential_sale_value'];
        $totals['profit'] += (float)$lot['potential_profit'];
    }

} catch (Throwable $e) {
    if (strpos($e->getMessage(), "42S02") !== false) {
        $error_message = "เกิดข้อผิดพลาด: ไม่พบ View 'v_fuel_lots_current' ในฐานข้อมูล กรุณาตรวจสอบว่าได้ติดตั้ง View ที่จำเป็นครบถ้วนแล้ว";
    } else {
        $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    }
    error_log("Profit Report Error: " . $e->getMessage());
}

$role_th_map = ['admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>รายงานกำไรคงเหลือ | <?= htmlspecialchars($site_name) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
    
    <!-- [ลบ] <style> inline ออก -->

</head>
<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"><span class="navbar-toggler-icon"></span></button>
            <a class="navbar-brand" href="admin_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
        </div>
        <div class="d-flex align-items-center gap-3 ms-auto">
            <div class="nav-identity text-end d-none d-sm-block">
                <div class="nav-name"><?= htmlspecialchars($current_name) ?></div>
                <div class="nav-sub"><?= htmlspecialchars($current_role_th) ?></div>
            </div>
            <a href="profile.php" class="avatar-circle text-decoration-none"><?= htmlspecialchars($avatar_text) ?></a>
        </div>
    </div>
</nav>

<!-- Sidebar Offcanvas (มือถือ) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu">
        <a href="committee_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="committee_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
          <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>
        <main class="col-lg-10 p-4">
            <!-- [แก้ไข] ใช้ .main-header -->
            <div class="main-header">
                <h2><i class="fa-solid fa-chart-line me-2"></i>รายงานกำไรคงเหลือในถัง</h2>
                <a href="committee_dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>กลับหน้าหลัก
                </a>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php else: ?>
                
                <!-- [แก้ไข] ใช้ .stats-grid และ .stat-card -->
                <div class="stats-grid mb-4">
                    <div class="stat-card text-center">
                        <h5><i class="bi bi-cash-stack text-success"></i> กำไรขั้นต้นที่รออยู่</h5>
                        <h3 class="text-success">฿<?= nf($totals['profit'], 2) ?></h3>
                        <p class="text-muted mb-0">ถ้าน้ำมันที่เหลือขายหมด</p>
                    </div>
                    <div class="stat-card text-center">
                        <h5><i class="bi bi-fuel-pump text-primary"></i> น้ำมันคงเหลือรวม</h5>
                        <h3 class="text-primary"><?= nf($totals['liters'], 2) ?> <small>ลิตร</small></h3>
                        <p class="text-muted mb-0">จาก Lot ที่ยังขายไม่หมด</p>
                    </div>
                    <div class="stat-card text-center">
                        <h5><i class="bi bi-box-seam text-warning"></i> มูลค่าต้นทุนคงเหลือ</h5>
                        <h3 class="text-warning">฿<?= nf($totals['cost_value'], 2) ?></h3> <!-- [แก้ไข] ใช้สี text-warning (amber) -->
                        <p class="text-muted mb-0">ต้นทุนของน้ำมันที่เหลือ</p>
                    </div>
                </div>
                
                <!-- [แก้ไข] ใช้ .panel -->
                <div class="panel">
                    <h5 class="mb-3">รายละเอียดตาม Lot น้ำมัน (FIFO)</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Lot Code</th>
                                    <th>ถัง</th>
                                    <th>น้ำมัน</th>
                                    <th class="text-end">ลิตรคงเหลือ</th>
                                    <th class="text-end">ต้นทุน/ลิตร (บาท)</th>
                                    <th class="text-end">ราคาขาย/ลิตร (บาท)</th>
                                    <th class="text-end">กำไรที่รอ (บาท)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($lots_data)): ?>
                                    <tr><td colspan="7" class="text-center text-muted p-4">ไม่พบข้อมูล Lot น้ำมันคงเหลือ</td></tr>
                                <?php endif; ?>
                                <?php foreach ($lots_data as $lot): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($lot['lot_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($lot['tank_name']) ?></td>
                                    <td><?= htmlspecialchars($lot['fuel_name']) ?></td>
                                    <td class="text-end"><?= nf($lot['remaining_liters'], 2) ?></td>
                                    <td class="text-end text-warning"><?= nf($lot['cost_per_liter'], 4) ?></td> <!-- [แก้ไข] text-danger -> text-warning -->
                                    <td class="text-end text-success"><?= nf($lot['price_per_liter'], 2) ?></td>
                                    <td class="text-end fw-bold text-primary">฿<?= nf($lot['potential_profit'], 2) ?></td> <!-- [แก้ไข] ใช้ text-primary (teal) -->
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if (!empty($lots_data)): ?>
                            <tfoot class="table-light"> <!-- [แก้ไข] table-dark -> table-light -->
                                <tr>
                                    <td colspan="3" class="text-end fw-bold">รวมทั้งหมด</td>
                                    <td class="text-end fw-bold"><?= nf($totals['liters'], 2) ?> ลิตร</td>
                                    <td colspan="2"></td>
                                    <td class="text-end fw-bold fs-5 text-success">฿<?= nf($totals['profit'], 2) ?></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<footer class="footer mt-auto py-3 bg-light">
    <div class="container text-center">
        <span class="text-muted">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
