<?php
// manager/inventory.php - Enhanced Fuel Management System (รับน้ำมันเข้าคลัง -> กระจายลงถังอัตโนมัติ)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
    exit();
}

// Database connection
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
  $current_name = $_SESSION['full_name'] ?: 'ผู้บริหาร';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'manager') {
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
function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
function d($s, $fmt = 'd/m/Y H:i') { 
    if (empty($s)) return '-'; // [แก้ไข] ป้องกัน error
    $t = strtotime($s); 
    return $t ? date($fmt, $t) : '-'; 
}
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb");
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
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

// ===== ดึงข้อมูลสำหรับหน้า =====
$fuels = [];
$suppliers = [];
$fuel_lots = [];
$alerts = [];
$error_message = null;
$view_exists = table_exists($pdo, 'v_fuel_stock_live'); // ตรวจสอบ View

try {
    // 1. [แก้ไข] ดึงข้อมูล "สรุปตามประเภทน้ำมัน" (ใช้ Query ที่ง่ายขึ้น)
    if (table_exists($pdo,'fuel_prices') && $view_exists) {
        $sql = "
            SELECT
                fp.fuel_id,
                fp.fuel_name,
                fp.price,
                fp.display_order,
                COALESCE(vfs.current_stock, 0) AS current_stock,
                COALESCE(vfs.capacity, 0) AS capacity,
                COALESCE(vfs.min_threshold, 0) AS min_threshold
            FROM fuel_prices fp
            LEFT JOIN v_fuel_stock_live vfs ON vfs.fuel_id = fp.fuel_id AND vfs.station_id = fp.station_id
            WHERE fp.station_id = :sid
            ORDER BY fp.display_order ASC, fp.fuel_id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':sid' => $station_id]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $capacity = (float)$row['capacity'];
            $current  = (float)$row['current_stock'];
            $pct = $capacity > 0 ? max(0,min(100, ($current / $capacity) * 100)) : 0;
            $fuels[$row['fuel_id']] = [
                'name' => $row['fuel_name'],
                'price' => (float)$row['price'],
                'current_stock' => $current,
                'capacity' => $capacity,
                'min_threshold' => (float)$row['min_threshold'],
                'stock_percentage' => $pct
                // [ลบ] last_refill_date และ last_refill_amount ที่ซับซ้อนออกไป
            ];
        }
    }

    // 2. ดึงข้อมูลผู้จำหน่าย (Suppliers)
    $suppliers_stmt = $pdo->prepare("SELECT supplier_id, supplier_name FROM suppliers WHERE station_id = ? ORDER BY supplier_name");
    $suppliers_stmt->execute([$station_id]);
    $suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. ดึงข้อมูล Lot ล่าสุด
    if (table_exists($pdo, 'v_fuel_lots_current')) {
        $fuel_lots_stmt = $pdo->prepare("
            SELECT 
                l.id, l.lot_code, l.received_at, l.initial_liters, l.unit_cost_full, 
                l.initial_total_cost, v.remaining_liters_calc, v.status_calc,
                fp.fuel_name, t.code as tank_code
            FROM fuel_lots l
            JOIN v_fuel_lots_current v ON l.id = v.id
            JOIN fuel_tanks t ON l.tank_id = t.id
            JOIN fuel_prices fp ON l.fuel_id = fp.fuel_id
            WHERE l.station_id = ?
            ORDER BY l.received_at DESC
            LIMIT 100
        ");
        $fuel_lots_stmt->execute([$station_id]);
        $fuel_lots = $fuel_lots_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 4. Alerts (low stock)
    foreach ($fuels as $fuel_id => $fuel) {
        if ($fuel['current_stock'] <= $fuel['min_threshold'] && $fuel['capacity'] > 0) {
            $alerts[] = ['type'=>'danger','icon'=>'fa-triangle-exclamation', 'message'=>"สต็อก {$fuel['name']} เหลือน้อย: ".nf($fuel['current_stock'],0).' ลิตร'];
        } elseif ($fuel['current_stock'] <= ($fuel['min_threshold'] * 1.5) && $fuel['capacity'] > 0) {
            $alerts[] = ['type'=>'warning','icon'=>'fa-exclamation-triangle', 'message'=>"สต็อก {$fuel['name']} ใกล้หมด: ".nf($fuel['current_stock'],0).' ลิตร'];
        }
    }
    
    if (!$view_exists) {
         $alerts[] = ['type'=>'danger','icon'=>'fa-server', 'message'=>'ไม่พบ View ที่จำเป็น (v_fuel_stock_live, v_fuel_lots_current). สต็อกจะไม่แสดงผล.'];
    }

} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    error_log("Inventory Page Error: " . $e->getMessage());
}

$role_th_map = ['admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// คำนวณสถิติ 4 ช่องบน (จาก $fuels)
$total_capacity = array_sum(array_column($fuels, 'capacity'));
$total_stock    = array_sum(array_column($fuels, 'current_stock'));
$available_cap  = max(0, $total_capacity - $total_stock);
$utilization    = $total_capacity > 0 ? ($total_stock / $total_capacity) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>จัดการน้ำมัน | <?= htmlspecialchars($site_name) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
<link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
<style>
        .stock-card { 
            border-left: 4px solid var(--steel); 
            transition: all .3s ease; 
            background: #fff;
            border-radius: var(--radius);
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            border: 1px solid var(--border);
            border-left-width: 4px;
            box-shadow: var(--shadow);
            padding: 1rem 1.25rem;
        }
        .stock-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--hover-shadow);
        }
        .stock-card.low-stock { border-left-color: var(--amber); }
        .stock-card.medium-stock { border-left-color: var(--gold); }
        .stock-card.high-stock { border-left-color: var(--mint); }
        
        .stock-progress { height: 8px; background-color: #e9ecef; border-radius: 4px; overflow: hidden; }
        .stock-progress-bar { height: 100%; transition: width .3s ease; }
        .stock-progress-bar.low { background: linear-gradient(90deg, #dc3545, #fd7e14); }
        .stock-progress-bar.medium { background: linear-gradient(90deg, #ffc107, #fd7e14); }
        .stock-progress-bar.high { background: linear-gradient(90deg, #28a745, #20c997); }

        .fuel-price-card{border:1px solid #e9ecef; border-radius:12px; transition:.25s; background:#fff}
        .fuel-price-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.06); transform:translateY(-1px)}
        .fuel-price-actions{display:flex; gap:.5rem; margin-top:.75rem; flex-wrap:wrap}
        .badge-price{font-weight:600}
        .nav-tabs .nav-link { font-weight: 600; }
        .nav-tabs .nav-link.active { color: var(--navy); border-color: var(--border) var(--border) #fff; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"><span class="navbar-toggler-icon"></span></button>
      <a class="navbar-brand fw-800" href="manager_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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

<!-- Offcanvas Sidebar -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Manager</span></h3></div>
    <nav class="sidebar-menu">
      <a href="manager_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
      <a href="inventory.php" class="active"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
    </nav>
    <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Manager</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="manager_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
        <a href="inventory.php" class="active"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
            <div class="main-header d-flex justify-content-between align-items-center">
                <h2 class="mb-0"><i class="bi bi-fuel-pump-fill"></i> จัดการน้ำมัน</h2>
                <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#modalRefill">
                    <i class="bi bi-plus-circle-fill me-1"></i> รับน้ำมันเข้าถัง
                </button>
            </div>

            <!-- Toast Messages -->
            <?php if (isset($_GET['ok'])): ?>
                <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                    <?= htmlspecialchars($_GET['ok']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['err']) || $error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                    <?= htmlspecialchars($_GET['err'] ?? $error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- สถิติ 4 ช่อง (ดีไซน์เดิม) -->
            <div class="stats-grid mt-4 mb-4">
                <div class="stat-card">
                    <h5><i class="fa-solid fa-droplet me-2"></i>สต็อกรวม (ลิตร)</h5>
                    <h3 class="text-primary"><?= nf($total_stock, 0) ?></h3>
                    <p class="mb-0 text-muted">จากถังที่ใช้งานทั้งหมด</p>
                </div>
                <div class="stat-card">
                    <h5><i class="fa-solid fa-inbox me-2"></i>พื้นที่ว่าง (ลิตร)</h5>
                    <h3><?= nf($available_cap, 0) ?></h3>
                     <p class="mb-0 text-muted">ความจุรวม: <?= nf($total_capacity, 0) ?> ลิตร</p>
                </div>
                <div class="stat-card">
                    <h5><i class="fa-solid fa-percent me-2"></i>อัตราการใช้งาน</h5>
                    <h3 class="text-info"><?= nf($utilization, 1) ?>%</h3>
                    <p class="mb-0 text-muted">เปอร์เซ็นต์คงเหลือในถัง</p>
                </div>
                <div class="stat-card">
                    <h5><i class="fa-solid fa-truck-droplet me-2"></i>ผู้จัดจำหน่าย</h5>
                    <h3><?= count($suppliers) ?> <small>ราย</small></h3>
                    <p class="mb-0 text-muted">ที่ลงทะเบียนในระบบ</p>
                </div>
            </div>

            <!-- Alerts (ถ้ามี) -->
            <?php if (!empty($alerts)): ?>
            <div class="panel mb-4">
                <h5 class="mb-3">การแจ้งเตือน</h5>
                <?php foreach ($alerts as $alert): ?>
                  <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> alert-banner mb-2">
                    <i class="fa-solid <?= htmlspecialchars($alert['icon']) ?> me-2"></i><?= htmlspecialchars($alert['message']) ?>
                  </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" id="inventoryTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stock-panel" type="button" role="tab"><i class="fa-solid fa-oil-can me-2"></i>สถานะสต็อก (สรุป)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#lots-panel" type="button" role="tab"><i class="bi bi-box-seam me-2"></i>ประวัติ Lot ต้นทุน</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#price-panel" type="button" role="tab">
                        <i class="fa-solid fa-tags me-2"></i>จัดการราคา
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="inventoryTabContent">
                <!-- สถานะสต็อก (ดีไซน์เดิม) -->
                <div class="tab-pane fade show active" id="stock-panel" role="tabpanel">
                    <div class="panel">
                        <div class="row g-3">
                            <?php if (empty($fuels)): ?>
                                <div class="col-12"><div class="alert alert-warning text-center">ไม่พบข้อมูลน้ำมัน กรุณาตั้งค่า `fuel_prices` และ `fuel_tanks`</div></div>
                            <?php endif; ?>
                            <?php foreach ($fuels as $id => $fuel):
                                $pct = (float)$fuel['stock_percentage'];
                                $stock_class = $pct <= 25 ? 'low' : ($pct <= 50 ? 'medium' : 'high');
                                $stock_card_class = $pct <= 25 ? 'low-stock' : ($pct <= 50 ? 'medium-stock' : 'high-stock');
                            ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card stock-card <?= $stock_card_class ?>" data-fuel="<?= htmlspecialchars((string)$id) ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="card-title mb-0"><?= htmlspecialchars($fuel['name']) ?></h6>
                                            <span class="badge bg-primary badge-price" id="badge-price-<?= htmlspecialchars((string)$id) ?>">฿<?= nf($fuel['price'], 2) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">สต็อกปัจจุบัน</span>
                                            <strong class="stock-amount"><?= nf($fuel['current_stock'], 0) ?> ลิตร</strong>
                                        </div>
                                        <div class="stock-progress mb-2">
                                            <div class="stock-progress-bar <?= $stock_class ?>" style="width: <?= $pct ?>%"></div>
                                        </div>
                                        <div class="row text-center">
                                            <div class="col">
                                                <small class="text-muted">ความจุ</small><br>
                                                <small><strong><?= nf($fuel['capacity'], 0) ?></strong></small>
                                            </div>
                                            <div class="col">
                                                <small class="text-muted">เปอร์เซ็นต์</small><br>
                                                <small><strong><?= nf($pct, 1) ?>%</strong></small>
                                            </div>
                                        </div>
                                        <!-- [ลบ] Last Refill (เพราะ Query ซับซ้อน) -->
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>


                <!-- ประวัติ Lot -->
                <div class="tab-pane fade" id="lots-panel" role="tabpanel">
                     <div class="panel">
                        <h5 class="mb-3"><i class="bi bi-box-seam me-2"></i>ประวัติการรับน้ำมัน (Lots)</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>วันที่รับ</th>
                                        <th>Lot Code</th>
                                        <th>ถัง</th>
                                        <th>น้ำมัน</th>
                                        <th class="text-end">ลิตร (เริ่มต้น)</th>
                                        <th class="text-end">ต้นทุน/ลิตร</th>
                                        <th class="text-end">ลิตร (คงเหลือ)</th>
                                        <th class="text-center">สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($fuel_lots)): ?>
                                        <tr><td colspan="8" class="text-center text-muted p-4">ยังไม่มีประวัติการรับน้ำมัน</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($fuel_lots as $lot): 
                                        $is_open = $lot['status_calc'] === 'OPEN';
                                    ?>
                                    <tr class="<?= $is_open ? '' : 'table-light text-muted' ?>">
                                        <td><?= d($lot['received_at']) ?></td>
                                        <td><strong><?= htmlspecialchars($lot['lot_code']) ?></strong></td>
                                        <td><?= htmlspecialchars($lot['tank_code']) ?></td>
                                        <td><?= htmlspecialchars($lot['fuel_name']) ?></td>
                                        <td class="text-end"><?= nf($lot['initial_liters'], 2) ?></td>
                                        <td class="text-end text-danger"><?= nf($lot['unit_cost_full'], 4) ?></td>
                                        <td class="text-end fw-bold <?= $is_open ? 'text-primary' : '' ?>"><?= nf($lot['remaining_liters_calc'], 2) ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= $is_open ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $is_open ? 'เปิดใช้งาน' : 'หมดแล้ว' ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Price Panel (คงเดิม) -->
                <div class="tab-pane fade" id="price-panel" role="tabpanel">
                  <div class="panel">
                    <h5 class="mb-3"><i class="fa-solid fa-tags me-2"></i>จัดการราคาน้ำมัน</h5>
                    <div class="alert alert-warning mb-3">
                      <i class="fa-solid fa-triangle-exclamation me-1"></i>
                      การเปลี่ยนแปลงราคาจะมีผลกับการขายครั้งถัดไป กรุณาตรวจสอบความถูกต้องก่อนบันทึก
                    </div>
                    <div class="row g-3">
                      <?php foreach ($fuels as $id => $data): ?>
                        <div class="col-md-6 col-lg-4">
                          <div class="fuel-price-card p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                              <label for="price_<?= $id ?>" class="form-label fw-bold m-0">
                                <i class="bi bi-fuel-pump-fill me-2"></i><?= htmlspecialchars($data['name']) ?>
                              </label>
                              <span class="text-muted small">ปัจจุบัน:
                                <strong id="current-price-<?= $id ?>">฿<?= nf($data['price'], 2) ?></strong>
                              </span>
                            </div>
                            <form class="fuel-price-form" data-fuel-id="<?= $id ?>" onsubmit="return false;">
                              <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                              <input type="hidden" name="fuel_id" value="<?= $id ?>">
                              <div class="input-group">
                                <input type="number"
                                       id="price_<?= $id ?>"
                                       name="price"
                                       class="form-control"
                                       value="<?= nf($data['price'], 2) ?>"
                                       step="0.01" min="0" inputmode="decimal" required>
                                <span class="input-group-text">บาท/ลิตร</span>
                              </div>
                              <div class="fuel-price-actions">
                                <button type="button" class="btn btn-success btn-sm btn-save" data-fuel-id="<?= $id ?>">
                                  <i class="fa-solid fa-floppy-disk me-1"></i> บันทึก
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-reset"
                                        data-default="<?= nf($data['price'], 2) ?>"
                                        data-input="#price_<?= $id ?>">
                                  <i class="fa-solid fa-rotate-left me-1"></i> คืนค่าเดิม
                                </button>
                                <span class="ms-auto d-flex align-items-center gap-2" aria-live="polite">
                                  <span class="save-status small text-muted"></span>
                                  <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                </span>
                              </div>
                            </form>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>

              </div>
        </main>
    </div>
</div>

<!-- Modal: Refill (ปรับปรุงให้มีช่องต้นทุน) -->
<div class="modal fade" id="modalRefill" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="refill.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle-fill me-2"></i>รับน้ำมันเข้าถัง (Refill)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">หน้านี้จะรับน้ำมันเข้า Lot, เติมลิตรเข้าถัง และบันทึกค่าใช้จ่ายลงบัญชีโดยตรง</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">เชื้อเพลิง <span class="text-danger">*</span></label>
                        <select class="form-select" name="fuel_id" required>
                            <option value="">-- เลือกเชื้อเพลิง --</option>
                            <!-- [แก้ไข] Bug ที่คุณแจ้งมา -->
                            <?php foreach($fuels as $fuel_id => $fuel): ?>
                                <option value="<?= $fuel_id ?>"><?= htmlspecialchars($fuel['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">ผู้จัดจำหน่าย (Supplier)</label>
                        <select class="form-select" name="supplier_id">
                            <option value="">-- ไม่ระบุ --</option>
                             <?php foreach($suppliers as $sup): ?>
                                <option value="<?= $sup['supplier_id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-12"><hr class="my-2"></div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">จำนวนลิตร (รวม) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="amount" step="0.01" min="1" required placeholder="0.00">
                            <span class="input-group-text">ลิตร</span>
                        </div>
                        <div class="form-text">ระบบจะกระจายลงถังที่ว่างให้อัตโนมัติ</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">ต้นทุน / ลิตร (บาท) <span class="text-danger">*</span></label>
                         <div class="input-group">
                            <input type="number" class="form-control" name="cost" step="0.0001" min="0.01" required placeholder="0.0000">
                            <span class="input-group-text">บาท</span>
                        </div>
                        <div class="form-text">ต้นทุนนี้จะใช้สำหรับคำนวณกำไร (COGS)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">ภาษี / ลิตร (บาท)</label>
                         <div class="input-group">
                            <input type="number" class="form-control" name="tax_per_liter" step="0.0001" value="0" placeholder="0.0000">
                            <span class="input-group-text">บาท</span>
                        </div>
                    </div>
                     <div class="col-md-6">
                        <label class="form-label fw-bold">ค่าใช้จ่ายอื่น (รวม)</label>
                         <div class="input-group">
                            <input type="number" class="form-control" name="other_costs" step="0.01" value="0" placeholder="0.00">
                            <span class="input-group-text">บาท</span>
                        </div>
                         <div class="form-text">เช่น ค่าขนส่ง (จะถูกเฉลี่ยเข้าต้นทุน)</div>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label fw-bold">เลขที่ใบแจ้งหนี้ (Invoice No.)</label>
                        <input type="text" class="form-control" name="invoice_no" placeholder="INV-12345">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">หมายเหตุ</label>
                        <input type="text" class="form-control" name="notes" placeholder="ข้อมูลเพิ่มเติม...">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> ยืนยันรับน้ำมันเข้าถัง</button>
            </div>
        </form>
    </div>
</div>


<footer class="footer ">
        <span class="text">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>  
</footer>


<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="toastBox" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">บันทึกสำเร็จ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const toastEl = document.getElementById('toastBox');
  const toastMsg = document.getElementById('toastMsg');
  let bsToast = toastEl ? new bootstrap.Toast(toastEl, { delay: 2200 }) : null;

  function showToast(message, ok=true){
    if (!toastEl) return alert(message);
    toastEl.classList.toggle('text-bg-success', ok);
    toastEl.classList.toggle('text-bg-danger', !ok);
    toastMsg.textContent = message;
    bsToast.show();
  }

  // messages from query string
  const urlParams = new URLSearchParams(window.location.search);
  const okMsg = urlParams.get('ok');
  const errMsg = urlParams.get('err');
  if (okMsg) { showToast(okMsg, true); window.history.replaceState({}, document.title, window.location.pathname + window.location.hash); }
  if (errMsg) { showToast(errMsg, false); window.history.replaceState({}, document.title, window.location.pathname + window.location.hash); }

  // reset price input to default
  document.querySelectorAll('.btn-reset').forEach(btn => {
    btn.addEventListener('click', () => {
      const selector = btn.getAttribute('data-input');
      const defVal   = btn.getAttribute('data-default');
      const input    = document.querySelector(selector);
      if (input) input.value = defVal;
    });
  });

  // save fuel price
  document.querySelectorAll('.btn-save').forEach(btn => {
    btn.addEventListener('click', async () => {
      const fuelId = btn.getAttribute('data-fuel-id');
      const form   = btn.closest('.fuel-price-form');
      if (!form) return;

      const input  = form.querySelector('input[name="price"]');
      const price  = parseFloat(input.value || '0');
      if (isNaN(price) || price < 0) { showToast('กรุณากรอกราคาให้ถูกต้อง', false); return; }

      const spinner = form.querySelector('.spinner-border');
      const status  = form.querySelector('.save-status');
      spinner.classList.remove('d-none');
      btn.disabled = true;

      try{
        const formData = new URLSearchParams();
        formData.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
        formData.append('fuel_id', fuelId);
        formData.append('price', price.toFixed(2));
        
        // [ข้อควรระวัง] ต้องสร้างไฟล์นี้
        const res = await fetch('update_price.php', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData.toString()
        });
        const data = await res.json();

        if (data && data.ok){
          const badge = document.getElementById('badge-price-' + fuelId);
          if (badge) badge.textContent = '฿' + Number(data.new_price).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2});
          const cur   = document.getElementById('current-price-' + fuelId);
          if (cur)   cur.textContent   = '฿' + Number(data.new_price).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2});
          if (status) { status.textContent = 'บันทึกเมื่อ ' + new Date().toLocaleTimeString('th-TH'); }
          showToast('บันทึกราคา ' + (data.fuel_name || fuelId) + ' สำเร็จ');
        } else {
          showToast(data && data.error ? data.error : 'บันทึกล้มเหลว', false);
        }
      }catch(err){
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', false);
      }finally{
        spinner.classList.add('d-none');
        btn.disabled = false;
      }
    });
  });
  
    // Activate tab based on URL hash
    const hash = window.location.hash || '#stock-panel'; // [แก้ไข] เปลี่ยน Default tab
    const tabTrigger = document.querySelector(`button[data-bs-target="${hash}"]`);
    if (tabTrigger) {
        bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
    }
     document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', event => {
            history.pushState(null, null, event.target.dataset.bsTarget);
        })
    });
});
</script>

</body>
</html>

