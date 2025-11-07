<?php
// employee/stock_movement_report.php - รายงานความเคลื่อนไหวสต็อก
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอินและสิทธิ์ =====
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
  exit();
}

// Database connection
$dbFile = __DIR__ . '/../config/db.php';
require_once $dbFile; // ต้องกำหนดตัวแปร $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== ดึงข้อมูลผู้ใช้และสิทธิ์ =====
try {
  $current_name = $_SESSION['full_name'] ?? 'พนักงาน';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'employee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
  exit();
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== Helpers =====
function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
function d($s, $fmt = 'd/m/Y H:i') { 
    if (empty($s)) return '-';
    $t = strtotime($s); 
    return $t ? date($fmt, $t) : '-'; 
}
function ymd($s){ $t=strtotime($s); return $t? date('Y-m-d',$t) : null; }

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
 * ดึงข้อมูลสำหรับรายงาน
 *
 * ============================================== */
$error_message = null;
$report_rows = [];
$tanks = [];
$start_balance = 0;
$current_balance = 0;
$total_in = 0;
$total_out = 0;

// 1. ดึงข้อมูลถังทั้งหมดสำหรับ Filter
try {
    $stmt_tanks = $pdo->prepare("
        SELECT t.id, t.code, t.name, fp.fuel_name 
        FROM fuel_tanks t
        JOIN fuel_prices fp ON t.fuel_id = fp.fuel_id
        WHERE t.station_id = :sid 
        ORDER BY fp.fuel_name, t.code
    ");
    $stmt_tanks->execute([':sid' => $station_id]);
    $tanks = $stmt_tanks->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error_message = "ไม่สามารถโหลดข้อมูลถังน้ำมันได้: " . $e->getMessage();
}

// 2. กำหนดช่วงวันที่ (จาก finance.php)
$quick = $_GET['quick_range'] ?? '7d';
$in_from = ymd($_GET['date_from'] ?? '');
$in_to   = ymd($_GET['date_to']   ?? '');
$selected_tank_id = (int)($_GET['tank_id'] ?? 0);

$today = new DateTime('today');
$from = null; $to = null;

if ($in_from && $in_to) {
    $from = new DateTime($in_from);
    $to = new DateTime($in_to);
    $quick = ''; // Clear quick select if custom range is used
} else {
    switch ($quick) {
      case 'today':      $from=$today; $to=clone $today; break;
      case 'yesterday':  $from=(clone $today)->modify('-1 day'); $to=(clone $today)->modify('-1 day'); break;
      case '30d':        $from=(clone $today)->modify('-29 day'); $to=$today; break;
      case 'this_month': $from=new DateTime(date('Y-m-01')); $to=$today; break;
      case 'last_month': $from=new DateTime(date('Y-m-01', strtotime('first day of last month'))); $to=new DateTime(date('Y-m-t', strtotime('last day of last month'))); break;
      default:           $from=(clone $today)->modify('-6 day'); $to=$today; $quick = '7d'; break; // Default 7 วัน
    }
}
if ($from && $to && $to < $from) { $tmp=$from; $from=$to; $to=$tmp; }

$rangeFromStr = $from ? $from->format('Y-m-d') : null;
$rangeToStr   = $to   ? $to->format('Y-m-d')   : null;

// 3. ดึงข้อมูลการเคลื่อนไหว (ถ้าเลือกถังแล้ว)
if ($selected_tank_id > 0) {
    try {
        // A. หายอดยกมา (Start Balance)
        $start_date_sql = $rangeFromStr ? $rangeFromStr . ' 00:00:00' : '1970-01-01 00:00:00';
        $stmt_start = $pdo->prepare("
            SELECT COALESCE(SUM(CASE 
                                WHEN type IN ('receive', 'adjust_plus', 'transfer_in') THEN liters
                                ELSE -liters 
                              END), 0) AS start_balance
            FROM fuel_moves
            WHERE tank_id = :tid AND occurred_at < :start_date
        ");
        $stmt_start->execute([':tid' => $selected_tank_id, ':start_date' => $start_date_sql]);
        $start_balance = (float)$stmt_start->fetchColumn();
        
        $running_balance = $start_balance;

        // B. ดึงรายการเคลื่อนไหวในช่วงวันที่
        $sql_moves = "
            SELECT fm.occurred_at, fm.type, fm.liters, fm.ref_doc, u.full_name AS user_name
            FROM fuel_moves fm
            LEFT JOIN users u ON fm.user_id = u.id
            WHERE fm.tank_id = :tid 
        ";
        $params_moves = [':tid' => $selected_tank_id];

        if ($rangeFromStr) {
            $sql_moves .= " AND DATE(fm.occurred_at) >= :f";
            $params_moves[':f'] = $rangeFromStr;
        }
        if ($rangeToStr) {
            $sql_moves .= " AND DATE(fm.occurred_at) <= :t";
            $params_moves[':t'] = $rangeToStr;
        }
        
        $sql_moves .= " ORDER BY fm.occurred_at ASC, fm.id ASC";
        
        $stmt_moves = $pdo->prepare($sql_moves);
        $stmt_moves->execute($params_moves);
        
        while ($row = $stmt_moves->fetch(PDO::FETCH_ASSOC)) {
            $in = 0.0; $out = 0.0;
            
            if (in_array($row['type'], ['receive', 'adjust_plus', 'transfer_in'])) {
                $in = (float)$row['liters'];
                $total_in += $in;
            } else {
                $out = (float)$row['liters']; // 'sale_out', 'adjust_minus', 'transfer_out'
                $total_out += $out;
            }
            
            $running_balance += ($in - $out);
            
            $report_rows[] = [
                'date' => $row['occurred_at'],
                'type' => $row['type'],
                'ref' => $row['ref_doc'],
                'user' => $row['user_name'],
                'in' => $in,
                'out' => $out,
                'balance' => $running_balance
            ];
        }
        
        // C. ดึงยอดปัจจุบัน (เพื่อยืนยัน)
        $stmt_current = $pdo->prepare("SELECT current_volume_l FROM fuel_tanks WHERE id = :tid");
        $stmt_current->execute([':tid' => $selected_tank_id]);
        $current_balance = (float)$stmt_current->fetchColumn();

    } catch (Throwable $e) {
        $error_message = "เกิดข้อผิดพลาดในการดึงรายงาน: " . $e->getMessage();
    }
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
<title>รายงานเคลื่อนไหวสต็อก | <?= htmlspecialchars($site_name) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
<link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
<style>
    .panel {
        background: var(--surface-glass);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 1.5rem;
    }
    .nav-tabs .nav-link { font-weight: 600; }
    .nav-tabs .nav-link.active { color: var(--navy); border-color: var(--border) var(--border) #fff; }
    
    .table-responsive {
        max-height: 70vh; /* จำกัดความสูงตาราง */
    }
    .table thead {
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .col-in { color: var(--mint); }
    .col-out { color: var(--amber); }
    .col-balance { color: var(--navy); font-weight: 700; }
</style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
      <a class="navbar-brand" href="profile.php"><?= htmlspecialchars($site_name) ?></a>
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

<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Employee</span></h3></div>
    <nav class="sidebar-menu">
      <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
      <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
      <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
      <a class="active" href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
      <a href="stock_movement_report.php" class="active" style="padding-left: 2.5rem;"><i class="fa-solid fa-chart-line"></i> รายงานเคลื่อนไหว</a>
      <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
      <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
    </nav>
    <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Employee</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
        <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
        <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
        <a class="active" href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
        <a href="stock_movement_report.php" class="active" style="padding-left: 2.5rem;"><i class="fa-solid fa-chart-line"></i> รายงานเคลื่อนไหว</a>
        <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
            <div class="main-header d-flex justify-content-between align-items-center">
                <h2 class="mb-0"><i class="fa-solid fa-chart-line"></i> รายงานความเคลื่อนไหวสต็อก</h2>
                <a href="inventory.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> กลับไปหน้าคลังสต็อก
                </a>
            </div>

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
            
            <div class="panel filter-section mt-4">
                <form method="GET" action="" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label for="tank_id" class="form-label small fw-bold">เลือกถังน้ำมัน <span class="text-danger">*</span></label>
                        <select class="form-select" name="tank_id" id="tank_id" required>
                            <option value="">-- กรุณาเลือกถัง --</option>
                            <?php foreach ($tanks as $tank): ?>
                            <option value="<?= $tank['id'] ?>" <?= $selected_tank_id === $tank['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tank['code'] . ' - ' . $tank['fuel_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_from" class="form-label small fw-bold">จากวันที่</label>
                        <input type="date" class="form-control" name="date_from" id="date_from" value="<?= htmlspecialchars($rangeFromStr ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="date_to" class="form-label small fw-bold">ถึงวันที่</label>
                        <input type="date" class="form-control" name="date_to" id="date_to" value="<?= htmlspecialchars($rangeToStr ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small d-none d-md-block">&nbsp;</label>
                        <div class="btn-group w-100" role="group">
                            <a href="?quick_range=7d&tank_id=<?= $selected_tank_id ?>" class="btn btn-outline-secondary <?= $quick === '7d' ? 'active' : '' ?>">7 วัน</a>
                            <a href="?quick_range=30d&tank_id=<?= $selected_tank_id ?>" class="btn btn-outline-secondary <?= $quick === '30d' ? 'active' : '' ?>">30 วัน</a>
                            <a href="?quick_range=this_month&tank_id=<?= $selected_tank_id ?>" class="btn btn-outline-secondary <?= $quick === 'this_month' ? 'active' : '' ?>">เดือนนี้</a>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small d-none d-md-block">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> แสดงรายงาน</button>
                    </div>
                </form>
            </div>

            <?php if ($selected_tank_id > 0): ?>
                <div class="stats-grid my-4">
                    <div class="stat-card text-center">
                        <h5>ยอดยกมา (ก่อน <?= d($rangeFromStr, 'd/m/Y') ?>)</h5>
                        <h3 class="text-secondary"><?= nf($start_balance, 2) ?> <small>ลิตร</small></h3>
                    </div>
                    <div class="stat-card text-center">
                        <h5>รับเข้า (ในช่วง)</h5>
                        <h3 class="text-success">+<?= nf($total_in, 2) ?> <small>ลิตร</small></h3>
                    </div>
                    <div class="stat-card text-center">
                        <h5>จ่ายออก (ในช่วง)</h5>
                        <h3 class="text-warning">-<?= nf($total_out, 2) ?> <small>ลิตร</small></h3>
                    </div>
                    <div class="stat-card text-center">
                        <h5>ยอดคงเหลือ (คำนวณ)</h5>
                        <h3 class="text-primary"><?= nf($start_balance + $total_in - $total_out, 2) ?> <small>ลิตร</small></h3>
                        <p class="mb-0 text-muted">ยอดจริงในถัง: <?= nf($current_balance, 2) ?> ลิตร</p>
                    </div>
                </div>

                <div class="panel">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>วันที่/เวลา</th>
                                    <th>ประเภท</th>
                                    <th>เอกสารอ้างอิง</th>
                                    <th>พนักงาน</th>
                                    <th class="text-end">รับเข้า (ลิตร)</th>
                                    <th class="text-end">จ่ายออก (ลิตร)</th>
                                    <th class="text-end">ยอดคงเหลือ (ลิตร)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="table-secondary">
                                    <td colspan="6"><strong>ยอดยกมา (ณ <?= d($rangeFromStr . ' 00:00:00') ?>)</strong></td>
                                    <td class="text-end"><strong><?= nf($start_balance, 2) ?></strong></td>
                                </tr>
                                <?php if (empty($report_rows)): ?>
                                    <tr><td colspan="7" class="text-center text-muted p-4">ไม่พบการเคลื่อนไหวในช่วงวันที่ที่เลือก</td></tr>
                                <?php endif; ?>
                                <?php foreach ($report_rows as $row): ?>
                                <tr>
                                    <td><?= d($row['date']) ?></td>
                                    <td><?= htmlspecialchars($row['type']) ?></td>
                                    <td><?= htmlspecialchars($row['ref'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['user'] ?? '-') ?></td>
                                    <td class="text-end col-in"><?= $row['in'] > 0 ? '+'.nf($row['in'], 2) : '-' ?></td>
                                    <td class="text-end col-out"><?= $row['out'] > 0 ? '-'.nf($row['out'], 2) : '-' ?></td>
                                    <td class="text-end col-balance"><?= nf($row['balance'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-light">
                                    <td colspan="4"><strong>สรุปยอดเคลื่อนไหวในช่วง</strong></td>
                                    <td class="text-end text-success fw-bold">+<?= nf($total_in, 2) ?></td>
                                    <td class="text-end text-warning fw-bold">-<?= nf($total_out, 2) ?></td>
                                    <td class="text-end text-primary fw-bold"><?= nf($start_balance + $total_in - $total_out, 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            
            <?php elseif (empty($error_message)): ?>
                <div class="alert alert-info text-center mt-4">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    กรุณาเลือกถังน้ำมันและช่วงวันที่ที่ต้องการ เพื่อเริ่มแสดงรายงาน
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

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
  if (okMsg) { showToast(okMsg, true); window.history.replaceState({}, document.title, window.location.pathname); }
  if (errMsg) { showToast(errMsg, false); window.history.replaceState({}, document.title, window.location.pathname); }
});
</script>

</body>
</html>