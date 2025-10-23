<?php
// pos.php — ระบบ POS ปั๊มน้ำมัน (UX/UI ปรับปรุงใหม่)
// *** ไม่มีการใช้ SESSION สำหรับยืนยันตัวตน แต่ใช้สำหรับ CSRF Token ***
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Bangkok');

// ===== 1. รับรหัสพนักงานจาก URL =====
$emp_code = $_GET['emp_code'] ?? null;

if (empty($emp_code)) {
    die('
        <meta charset="UTF-8">
        <title>ข้อผิดพลาด</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <div class="alert alert-danger m-4">
            <strong>ข้อผิดพลาด:</strong> ไม่พบรหัสพนักงาน (emp_code) ใน URL
            <p>กรุณาเข้าผ่านหน้าป้อนรหัสพนักงาน หรือติดต่อผู้ดูแล</p>
            <p>ตัวอย่าง URL ที่ถูกต้อง: <code>pos.php?emp_code=E-001</code></p>
        </div>
    ');
}

// ===== 2. การเชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องกำหนดตัวแปร $pdo (PDO)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


// ===== 3. ค้นหาพนักงานจากรหัส =====
$current_user_id = null;
$current_name = 'พนักงาน (ไม่ระบุตัวตน)';
$avatar_text = 'E';
$current_role_th = 'พนักงาน';

try {
    $stmt_emp = $pdo->prepare("
        SELECT u.id, u.full_name 
        FROM users u
        JOIN employees e ON u.id = e.user_id
        WHERE e.emp_code = :emp_code AND u.is_active = 1
        LIMIT 1
    ");
    $stmt_emp->execute([':emp_code' => $emp_code]);
    $employee_data = $stmt_emp->fetch(PDO::FETCH_ASSOC);

    if ($employee_data) {
        $current_user_id = (int)$employee_data['id'];
        $current_name = $employee_data['full_name'];
        $avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
    } else {
        // ** (สำคัญ) แก้ไข ID 3 ให้เป็น ID ของ User กลางสำหรับ POS ถ้ามี **
        $current_user_id = 3; 
        $current_name = "พนักงาน (รหัส {$emp_code})";
        error_log("POS: Employee code '{$emp_code}' not found or not linked to user.");
    }
} catch (Throwable $e) {
    error_log("Employee lookup failed: " . $e->getMessage());
    $current_user_id = 3; // ID พนักงานสำรอง
}


// ===== 4. CSRF (ยังคงใช้ Session) =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== Helpers =====
function get_setting(PDO $pdo, string $name, $default = null) {
  try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name = :n LIMIT 1");
    $stmt->execute([':n' => $name]);
    $v = $stmt->fetchColumn();
    // คืนค่าเป็น string หรือ default
    return $v !== false ? (string)$v : $default;
  } catch (Throwable $e) { return $default; }
}

function has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = []; $key = $table.'.'.$column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1";
    $st = $pdo->prepare($sql); $st->execute([':t'=>$table, ':c'=>$column]);
    $cache[$key] = (bool)$st->fetchColumn();
  } catch (Throwable $e) { $cache[$key] = false; }
  return $cache[$key];
}

/* ================== ค่าพื้นฐาน ================== */
$site_name = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง'; // Default
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

$station_id = (int)get_setting($pdo, 'station_id', 1);

/* ================== ดึงข้อมูลน้ำมันและราคา ================== */
$fuel_types = [];
$fuel_colors_by_name = [
  'ดีเซล'         => '#F2B705', // เหลืองเข้ม
  'แก๊สโซฮอล์ 95' => '#D94B18', // ส้ม-แดง
  'แก๊สโซฮอล์ 91' => '#34A853', // เขียว
  'B7'            => '#F2B705',
  'B10'           => '#A67C00',
  'B20'           => '#734C00',
  'E20'           => '#34A853',
  'E85'           => '#4285F4',
];

try {
  // ***** START: แก้ไข Query *****
  $stmt_fuel = $pdo->prepare("
      SELECT fuel_id, fuel_name, price
      FROM fuel_prices
      WHERE station_id = :sid
      AND fuel_name != 'แก๊สโซฮอล์ 91' -- <-- 1. ลบ 91 ออก
      ORDER BY COALESCE(display_order, 99) ASC, fuel_id ASC
    ");
  // ***** END: แก้ไข Query *****
    
    $stmt_fuel->execute([':sid' => $station_id]);
  
  while ($row = $stmt_fuel->fetch(PDO::FETCH_ASSOC)) {
    $name  = $row['fuel_name'];
    $color = $fuel_colors_by_name[$name] ?? '#6c757d'; // สีเทา ถ้าไม่พบ
    $fuel_types[(string)$row['fuel_id']] = [
      'name'  => $name,
      'price' => (float)$row['price'],
      'color' => $color
    ];
  }
} catch (Throwable $e) {
  error_log("Could not fetch fuel prices: " . $e->getMessage());
}

/* ================== การประมวลผลฟอร์มขาย ================== */
$sale_success   = false;
$sale_error     = null;
$sale_data      = null;
$sale_data_json = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_sale') {
  // CSRF
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $sale_error = 'Session ไม่ถูกต้อง กรุณารีเฟรชหน้าจอแล้วลองใหม่';
  } else {
    
    // ... (โค้ด PHP ส่วนประมวลผลฟอร์มขายทั้งหมด ... เหมือนเดิม) ...
    // (รับค่า, ตรวจสอบ, คำนวณ, บันทึกลง DB)
    
    $fuel_id        = (string)($_POST['fuel_type'] ?? '');
    $sale_type      = $_POST['sale_type'] ?? 'amount';
    $quantity       = filter_var($_POST['quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $customer_phone = preg_replace('/\D+/', '', (string)($_POST['customer_phone'] ?? ''));
    $household_no   = trim((string)($_POST['household_no'] ?? ''));
    $discount       = (float)($_POST['discount'] ?? 0);
    $discount       = max(0.0, min(100.0, $discount));
    $allowed_payments  = ['cash', 'qr', 'transfer', 'card'];
    $allowed_sale_type = ['liters','amount'];

    if (!array_key_exists($fuel_id, $fuel_types)) {
      $sale_error = 'กรุณาเลือกชนิดน้ำมันให้ถูกต้อง';
    } elseif ($quantity === false || $quantity <= 0.01) {
      $sale_error = 'กรุณาใส่จำนวนเงินหรือปริมาณลิตรให้ถูกต้อง (มากกว่า 0)';
    } elseif (!in_array($payment_method, $allowed_payments, true)) {
      $sale_error = 'วิธีการชำระเงินไม่ถูกต้อง';
    } elseif (!in_array($sale_type, $allowed_sale_type, true)) {
      $sale_error = 'ประเภทการขายไม่ถูกต้อง';
    } else {
      $fuel_price = (float)$fuel_types[$fuel_id]['price'];
      $fuel_name  = $fuel_types[$fuel_id]['name'];
      if ($sale_type === 'liters') {
        $liters_raw   = (float)$quantity; $liters_disp  = round($liters_raw, 3);
        $liters_db    = round($liters_raw, 2); $total_amount = round($liters_db * $fuel_price, 2);
      } else {
        $total_amount = round((float)$quantity, 2);
        $liters_calc  = ($fuel_price > 0 ? $total_amount / $fuel_price : 0.0);
        $liters_disp  = round($liters_calc, 3); $liters_db    = round($liters_calc, 2);
      }
      $discount_amount = round($total_amount * ($discount/100.0), 2);
      $net_amount      = round($total_amount - $discount_amount, 2);
      $POINT_RATE     = 20;
      $has_loyalty_id = (bool)($customer_phone || $household_no);
      $points_earned  = $has_loyalty_id ? (int)floor($net_amount / $POINT_RATE) : 0;
      $now = date('Y-m-d H:i:s');
      $sale_data = [
        'site_name' => $site_name, 'receipt_no' => '', 'datetime' => $now,
        'fuel_type' => $fuel_id, 'fuel_name' => $fuel_name, 'price_per_liter'  => $fuel_price,
        'liters' => $liters_disp, 'total_amount' => $total_amount, 'discount_percent' => $discount,
        'discount_amount' => $discount_amount, 'net_amount' => $net_amount, 'payment_method' => $payment_method,
        'customer_phone' => $customer_phone, 'household_no' => $household_no, 'points_earned' => $points_earned,
        'employee_id' => $current_user_id, 'employee_name'    => $current_name
      ];
      try {
        $pdo->beginTransaction();
        $col_phone   = has_column($pdo, 'sales', 'customer_phone');
        $col_house   = has_column($pdo, 'sales', 'household_no');
        $col_discpct = has_column($pdo, 'sales', 'discount_pct');
        $col_discamt = has_column($pdo, 'sales', 'discount_amount');
        $col_emp_id  = has_column($pdo, 'sales', 'employee_user_id');
        $tries = 0; $sale_id = null;
        do {
          $receipt_no = 'R'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
          $cols = ['station_id','sale_code','total_amount','net_amount','sale_date','payment_method','created_by'];
          $params = [
            ':station_id'     => $station_id, ':sale_code'      => $receipt_no,
            ':total_amount'   => $total_amount, ':net_amount'     => $net_amount,
            ':sale_date'      => $now, ':payment_method' => $payment_method,
            ':created_by'     => $current_user_id,
          ];
          if ($col_phone)   { $cols[] = 'customer_phone';  $params[':customer_phone']  = $customer_phone ?: null; }
          if ($col_house)   { $cols[] = 'household_no';    $params[':household_no']    = $household_no ?: null; }
          if ($col_discpct) { $cols[] = 'discount_pct';    $params[':discount_pct']    = $discount; }
          if ($col_discamt) { $cols[] = 'discount_amount'; $params[':discount_amount'] = $discount_amount; }
          if ($col_emp_id)  { $cols[] = 'employee_user_id'; $params[':employee_user_id'] = $current_user_id; }
          $placeholders = implode(',', array_keys($params));
          $sql = "INSERT INTO sales (".implode(',', $cols).") VALUES ($placeholders)";
          try {
            $stmtSale = $pdo->prepare($sql); $stmtSale->execute($params);
            $sale_id = (int)$pdo->lastInsertId(); $sale_data['receipt_no'] = $receipt_no;
            break;
          } catch (PDOException $ex) { if ($ex->getCode() === '23000' && ++$tries <= 5) continue; throw $ex; }
        } while ($tries <= 5);
        if (!$sale_id) { throw new RuntimeException('ไม่สามารถสร้างเลขที่ใบเสร็จได้'); }
        $tank_id = null;
        try {
          $findTank = $pdo->prepare("SELECT id FROM fuel_tanks WHERE station_id = :sid AND fuel_id = :fid AND is_active = 1 AND current_volume_l >= :liters ORDER BY current_volume_l DESC LIMIT 1");
          $findTank->execute([':sid'=>$station_id, ':fid'=>(int)$fuel_id, ':liters' => $liters_db]);
          $tank_id = $findTank->fetchColumn() ?: null;
        } catch (Throwable $e) { $tank_id = null; }
        $stmtItem = $pdo->prepare("INSERT INTO sales_items (sale_id, fuel_id, tank_id, fuel_type, liters, price_per_liter) VALUES (:sale_id, :fuel_id, :tank_id, :fuel_type, :liters, :price_per_liter)");
        $stmtItem->execute([':sale_id' => $sale_id, ':fuel_id' => (int)$fuel_id, ':tank_id' => $tank_id, ':fuel_type' => $fuel_name, ':liters' => $liters_db, ':price_per_liter' => round($fuel_price, 2)]);
        try {
          if ($tank_id) {
            $sel = $pdo->prepare("SELECT id FROM fuel_tanks WHERE id = :tid FOR UPDATE"); $sel->execute([':tid' => (int)$tank_id]);
            if ($sel->fetch()) {
              $lit2 = $liters_db;
              $stmtUpd = $pdo->prepare("UPDATE fuel_tanks SET current_volume_l = current_volume_l - ? WHERE id = ? AND current_volume_l >= ?");
              $stmtUpd->execute([$lit2, $tank_id, $lit2]);
              if ($stmtUpd->rowCount() > 0) {
                $stmtMove = $pdo->prepare("INSERT INTO fuel_moves (occurred_at, type, tank_id, liters, unit_price, ref_doc, ref_note, user_id, sale_id) VALUES (NOW(), 'sale_out', :tank_id, :liters, :unit_price, :ref_doc, :ref_note, :user_id, :sale_id)");
                $stmtMove->execute([':tank_id' => (int)$tank_id, ':liters' => $lit2, ':unit_price' => round($fuel_price, 2), ':ref_doc' => $sale_data['receipt_no'], ':ref_note' => 'POS sale', ':user_id' => $current_user_id, ':sale_id' => $sale_id]);
                $move_id = (int)$pdo->lastInsertId();
                if ($move_id > 0 && has_column($pdo, 'fuel_lot_allocations', 'lot_id')) {
                    $liters_to_allocate = $liters_db;
                    $getLots = $pdo->prepare("SELECT id, remaining_liters, unit_cost_full FROM v_open_fuel_lots WHERE tank_id = :tid ORDER BY received_at ASC, id ASC");
                    $getLots->execute([':tid' => (int)$tank_id]);
                    $insAlloc = $pdo->prepare("INSERT INTO fuel_lot_allocations (lot_id, move_id, allocated_liters, unit_cost_snapshot) VALUES (:lot_id, :move_id, :liters, :cost)");
                    while ($liters_to_allocate > 1e-6 && ($lot = $getLots->fetch(PDO::FETCH_ASSOC))) {
                        $lot_id = (int)$lot['id']; $available_in_lot = (float)$lot['remaining_liters'];
                        $cost_snapshot = (float)$lot['unit_cost_full']; $take_from_lot = min($liters_to_allocate, $available_in_lot);
                        if ($take_from_lot > 0) {
                            $insAlloc->execute([':lot_id' => $lot_id, ':move_id' => $move_id, ':liters' => $take_from_lot, ':cost' => $cost_snapshot]);
                            $liters_to_allocate -= $take_from_lot;
                        }
                    }
                    if ($liters_to_allocate > 1e-6) { error_log("COGS Warning: สต็อกใน Lot ไม่พอสำหรับ Tank ID {$tank_id} (ขาดไป {$liters_to_allocate} ลิตร) แต่ยังคงการขายไว้"); }
                }
                if (has_column($pdo, 'fuel_stock', 'fuel_id')) {
                    $sync = $pdo->prepare("UPDATE fuel_stock SET current_stock = GREATEST(0, current_stock - :l) WHERE station_id = :sid AND fuel_id = :fid");
                    $sync->execute([':l' => $liters_db, ':sid' => $station_id, ':fid' => (int)$fuel_id]);
                }
              } else { error_log('Inventory not enough for tank '.$tank_id.' sale '.$sale_id); }
            } else { error_log("Tank not found (FOR UPDATE) id={$tank_id}"); }
          } else { error_log("No active tank for station {$station_id} and fuel {$fuel_id} with enough stock ({$liters_db}L) for sale {$sale_id}"); }
        } catch (Throwable $invE) { error_log("Inventory update skipped: ".$invE->getMessage()); }
        if ($points_earned > 0 && ($customer_phone !== '' || $household_no !== '')) {
          try {
            $member_id = null; $where_conditions = []; $params = [];
            if ($customer_phone !== '') { $where_conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(u.phone, '-', ''), ' ', ''), '(', ''), ')', '') = :phone"; $params[':phone'] = $customer_phone; }
            if ($household_no !== '') { $where_conditions[] = "m.house_number = :house"; $params[':house'] = $household_no; }
            if (!empty($where_conditions)) {
              $where_clause = implode(' OR ', $where_conditions);
              $q = $pdo->prepare("SELECT m.id AS member_id FROM users u INNER JOIN members m ON m.user_id = u.id WHERE m.is_active = 1 AND ({$where_clause}) LIMIT 1");
              $q->execute($params); $member_id = $q->fetchColumn();
              if (!$member_id) error_log("Member not found - Phone: {$customer_phone}, House: {$household_no}");
            }
            if ($member_id) {
              $insScore = $pdo->prepare("INSERT INTO scores (member_id, score, activity, score_date) VALUES (:member_id, :score, :activity, NOW())");
              $insScore->execute([':member_id' => (int)$member_id, ':score' => (int)$points_earned, ':activity' => 'POS '.$sale_data['receipt_no']]);
              error_log("Points earned: {$points_earned} for member_id: {$member_id}");
            }
          } catch (Throwable $ptsE) { error_log("Point earn error: ".$ptsE->getMessage()); }
        }
        $pdo->commit();
        $sale_success   = true;
        $sale_data_json = json_encode($sale_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $sale_error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
      }
    }
  }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ขายน้ำมัน | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  
  <style>
    :root {
        --primary: #0d6efd;
        --primary-light: #e7f1ff;
        --success: #198754;
        --danger: #dc3545;
        --warning: #ffc107;
        --dark: #212529;
        --surface: #ffffff;
        --border: #dee2e6;
        --shadow-sm: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        --shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
        --radius: 0.75rem; /* 12px */
    }
    body {
        font-family: 'Prompt', sans-serif;
        background-color: #f4f7f6; /* สีพื้นหลังอ่อนๆ */
    }
    /* ใช้ .container แทน .main-content เพื่อให้ Bootstrap จัดการความกว้าง */
    .container {
        max-width: 1200px;
    }
    .avatar-circle {
        width: 40px; height: 40px; border-radius: 50%;
        background: #fff; color: var(--primary);
        display: flex; align-items: center; justify-content: center;
        font-weight: 600; font-size: 1.1rem; text-decoration: none;
    }
    .nav-identity { color: rgba(255,255,255,0.9); }
    .nav-name { font-weight: 500; }
    .nav-sub { font-size: 0.8rem; opacity: 0.8; }
    
    .fuel-selector{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem}
    .fuel-card{
        border:3px solid var(--border);
        border-radius:var(--radius);
        padding: 1rem;
        cursor:pointer;
        transition: all .2s ease-in-out;
        text-align:center; 
        box-shadow: var(--shadow-sm);
        background-color: var(--surface);
    }
    .fuel-card:hover{
        border-color: var(--primary);
        transform: translateY(-3px); 
        box-shadow: 0 8px 15px rgba(0,0,0,.07);
    }
    .fuel-card.selected{
        border-color:var(--primary);
        background-color:var(--primary-light);
        box-shadow:0 4px 15px rgba(13, 110, 253, 0.25);
        transform: translateY(-3px);
    }
    .fuel-icon{
        width:50px; height:50px; border-radius:50%;
        margin:0 auto .5rem;
        display:flex; align-items:center; justify-content:center;
        font-size:1.5rem; color:#fff;
    }
    
    .pos-panel{
        background:var(--surface);
        border:1px solid var(--border);
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        padding:1.5rem;
        @supports (backdrop-filter: blur(10px)) {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
        }
    }
    .amount-display{
        background:var(--dark);
        color:#20e8a0;
        font-family:"Courier New",monospace;
        border-radius:var(--radius);
        padding: 1rem 1.5rem;
        text-align:right;
        font-size: 2.5rem; /* ใหญ่ขึ้น */
        font-weight:700;
        margin-bottom:1rem;
        min-height:78px; /* ปรับความสูง */
    }
    .numpad-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem}
    .numpad-btn{
        aspect-ratio: 1.3 / 1; /* ปรับให้สูงขึ้นเล็กน้อย */
        border:1px solid var(--border);
        background:var(--surface);
        border-radius:var(--radius);
        font-size: 1.75rem; /* ใหญ่ขึ้น */
        font-weight:600;
        color: #495057;
        cursor:pointer;
        transition: all .15s ease;
    }
    .numpad-btn:hover{background: #f1f1f1;}
    .numpad-btn:active{transform: scale(0.95); background: #e0e0e0;}
    .numpad-btn[data-action="backspace"] { font-size: 1.5rem; color: var(--danger); }
    
    .receipt{font-family:'Courier New',monospace}
    
    /* --- CSS ใหม่สำหรับ UX Steps --- */
    .step-indicator {
      position: relative;
      padding: 0.5rem 0.25rem;
      transition: all 0.3s;
    }
    .step-number {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #e9ecef; /* สีเทาเริ่มต้น */
      color: #6c757d;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 1.25rem;
      margin: 0 auto 0.5rem;
      transition: all 0.3s;
      border: 3px solid #e9ecef;
    }
    .step-indicator.active .step-number {
      background: var(--primary-light);
      color: var(--primary);
      border-color: var(--primary);
    }
    .step-indicator.completed .step-number {
      background: var(--success);
      color: white;
      border-color: var(--success);
    }
    .step-label {
      font-size: 0.875rem;
      color: #6c757d;
      font-weight: 500;
    }
    .step-indicator.active .step-label {
      color: var(--primary);
      font-weight: 700;
    }
    .step-indicator.completed .step-label {
      color: var(--success);
    }

    .sale-type-card {
      border: 3px solid #e9ecef;
      border-radius: 12px;
      padding: 1.5rem;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s;
      height: 100%;
      background: var(--surface);
      box-shadow: var(--shadow-sm);
    }
    .sale-type-card:hover {
      border-color: var(--primary);
      transform: translateY(-5px);
      box-shadow: 0 8px 24px rgba(0,0,0,.1);
    }
    .sale-type-card.selected {
      border-color: var(--primary);
      background: #e7f1ff; 
      box-shadow: 0 8px 24px rgba(13, 110, 253, 0.3);
    }
    .sale-type-card i {
      color: var(--primary);
      font-size: 2.5rem;
    }
    .sale-type-card h5 {
        font-size: 1.1rem;
        font-weight: 600;
    }
    .sale-type-card p {
        font-size: 0.9rem;
    }

    #previewCalc {
      min-height: 120px;
      font-size: 1rem;
      background-color: #f8f9fa;
      border: 1px solid #dee2e6;
    }
    #previewCalc .text-muted {
        font-size: 1rem;
    }

    #finalSummary {
      background: #f8f9fa;
      color: #212529;
      padding: 1.5rem;
      border-radius: 12px;
      border: 1px solid #dee2e6;
    }
    #finalSummary .row { margin-bottom: 0.5rem; }
    #finalSummary hr { border-color: rgba(0,0,0,0.1); margin: 0.75rem 0; }
    #finalSummary h4 { color: var(--primary); font-weight: 700; }
    
    /* --- CSS ใหม่: ปุ่ม Quick Amount --- */
    .quick-amount-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.75rem;
    }
    .quick-btn {
        border: 1px solid var(--border);
        background: var(--surface);
        border-radius: var(--radius);
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--primary);
        cursor: pointer;
        transition: all .15s ease;
        padding: 0.75rem 0.5rem;
    }
    .quick-btn:hover {
        background: var(--primary-light);
        border-color: var(--primary);
    }
    .quick-btn:active {
        transform: scale(0.95);
        background: var(--primary);
        color: #fff;
    }
    .quick-btn.btn-full-tank:disabled {
        background: #e9ecef;
        color: #adb5bd;
        border-color: #dee2e6;
        cursor: not-allowed;
    }
    /* --- สิ้นสุด CSS ใหม่ --- */

    .footer {
        padding: 1.5rem 0;
        text-align: center;
        color: #6c757d;
        font-size: 0.9rem;
        border-top: 1px solid var(--border);
        margin-top: 2rem;
    }

    @media print{
        body { background: #fff; }
        .navbar, .footer, .main-content form, .alert, .card.mb-4 { display: none; }
        body *{visibility:hidden}
        .receipt-print-area,.receipt-print-area *{visibility:visible}
        .receipt-print-area{position:absolute;left:0;top:0;width:100%}
    }
  </style>
</head>

<body>
  <nav class="navbar navbar-dark bg-primary shadow-sm">
    <div class="container main-content">
      <div class="d-flex align-items-center gap-2">
        <a class="navbar-brand" href="pos.php?emp_code=<?= htmlspecialchars($emp_code) ?>">
            <i class="bi bi-fuel-pump-fill"></i>
            <?= htmlspecialchars($site_name) ?>
        </a>
      </div>
      <div class="d-flex align-items-center gap-3 ms-auto">
        <div class="nav-identity text-end text-white">
          <div class="nav-name" style="font-weight: 500;"><?= htmlspecialchars($current_name) ?></div>
          <div class="nav-sub" style="font-size: 0.8rem; opacity: 0.8;"><?= htmlspecialchars($current_role_th) ?></div>
        </div>
        <div class="avatar-circle">
            <?= htmlspecialchars($avatar_text) ?>
        </div>
      </div>
    </div>
  </nav>

  <main class="container mt-4 main-content">
        
      <?php if ($sale_success && $sale_data): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>บันทึกสำเร็จ!</strong> เลขที่ใบเสร็จ: <?= htmlspecialchars($sale_data['receipt_no']) ?>.
          <button class="btn btn-sm btn-outline-success ms-2" data-bs-toggle="modal" data-bs-target="#receiptModal">
            <i class="bi bi-printer"></i> พิมพ์ใบเสร็จ
          </button>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if ($sale_error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>เกิดข้อผิดพลาด!</strong> <?= htmlspecialchars($sale_error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="card mb-4 shadow-sm border-0">
        <div class="card-body p-2 p-md-3">
          <div class="row text-center">
            <div class="col-3">
              <div id="step1-indicator" class="step-indicator active">
                <div class="step-number">1</div>
                <div class="step-label">เลือกน้ำมัน</div>
              </div>
            </div>
            <div class="col-3">
              <div id="step2-indicator" class="step-indicator">
                <div class="step-number">2</div>
                <div class="step-label">เลือกประเภท</div>
              </div>
            </div>
            <div class="col-3">
              <div id="step3-indicator" class="step-indicator">
                <div class="step-number">3</div>
                <div class="step-label">กรอกจำนวน</div>
              </div>
            </div>
            <div class="col-3">
              <div id="step4-indicator" class="step-indicator">
                <div class="step-number">4</div>
                <div class="step-label">ข้อมูลและบันทึก</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <form id="posForm" method="POST" autocomplete="off" novalidate 
            action="pos.php?emp_code=<?= htmlspecialchars($emp_code) ?>"> <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="process_sale">
        <input type="hidden" name="fuel_type" id="selectedFuel" required>
        <input type="hidden" name="quantity" id="quantityInput" value="0" required>
        <input type="hidden" name="sale_type" id="saleTypeInput" value="">
        <input type="hidden" name="discount" id="discountInput" value="0"> 

        <div id="step1-panel" class="pos-panel">
          <h5 class="mb-3">
            <i class="bi bi-fuel-pump-fill me-2"></i>
            ขั้นตอนที่ 1: เลือกชนิดน้ำมัน
          </h5>
          <div class="fuel-selector">
            <?php if (empty($fuel_types)): ?>
                <div class="alert alert-warning w-100">ไม่พบข้อมูลราคาน้ำมัน กรุณาติดต่อผู้ดูแลระบบ</div>
            <?php endif; ?>
            <?php foreach ($fuel_types as $key => $fuel): ?>
            <div class="fuel-card" data-fuel="<?= htmlspecialchars($key) ?>" 
                 data-price="<?= htmlspecialchars($fuel['price']) ?>"
                 data-name="<?= htmlspecialchars($fuel['name']) ?>">
              <div class="fuel-icon" style="background-color: <?= htmlspecialchars($fuel['color']) ?>">
                <i class="bi bi-droplet-fill"></i>
              </div>
              <h6 class="mt-2"><?= htmlspecialchars($fuel['name']) ?></h6>
              <div class="text-muted"><?= number_format($fuel['price'], 2) ?> ฿/ลิตร</div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="text-center mt-4">
            <button type="button" class="btn btn-primary btn-lg" id="nextToStep2" disabled>
              ถัดไป <i class="bi bi-arrow-right ms-2"></i>
            </button>
          </div>
        </div>

        <div id="step2-panel" class="pos-panel" style="display:none;">
          <h5 class="mb-3">
            <i class="bi bi-gear-fill me-2"></i>
            ขั้นตอนที่ 2: เลือกประเภทการขาย
          </h5>
          <div id="selectedFuelInfo" class="alert alert-info mb-4"></div>
          
          <div class="row g-3">
            <div class="col-md-6">
              <div class="sale-type-card" data-type="amount">
                <i class="bi bi-cash-stack display-4 mb-3"></i>
                <h5>ขายตามจำนวนเงิน</h5>
                <p class="text-muted">กรอกจำนวนเงิน (บาท)</p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="sale-type-card" data-type="liters">
                <i class="bi bi-droplet display-4 mb-3"></i>
                <h5>ขายตามปริมาณ</h5>
                <p class="text-muted">กรอกปริมาณ (ลิตร)</p>
              </div>
            </div>
          </div>

          <div class="text-center mt-4">
            <button type="button" class="btn btn-outline-secondary me-2" onclick="goToStep(1)">
              <i class="bi bi-arrow-left me-2"></i> ย้อนกลับ
            </button>
            <button type="button" class="btn btn-primary btn-lg" id="nextToStep3" disabled>
              ถัดไป <i class="bi bi-arrow-right ms-2"></i>
            </button>
          </div>
        </div>

        <div id="step3-panel" class="pos-panel" style="display:none;">
          <h5 class="mb-3">
            <i class="bi bi-calculator-fill me-2"></i>
            ขั้นตอนที่ 3: กรอกจำนวน<span id="saleTypeLabel"></span>
          </h5>

          <div class="row g-4">
            <div class="col-md-7">
                <div id="amountDisplay" class="amount-display">0</div>
                
                <div id="quickAmountPanel">
                    <h6 class="text-muted small mb-2" id="quickAmountLabel">หรือเลือกยอดที่เติมบ่อย (บาท)</h6>
                    <div class="quick-amount-grid mb-3">
                        <button type="button" class="quick-btn" data-amount="20">20</button>
                        <button type="button" class="quick-btn" data-amount="40">40</button>
                        <button type="button" class="quick-btn" data-amount="50">50</button>
                        <button type="button" class="quick-btn" data-amount="100">100</button>
                        <button type="button" class="quick-btn" data-amount="500">500</button>
                        <button type="button" class="quick-btn" data-amount="1000">1000</button>
                    </div>
                </div>
                
                <div id="quickLiterPanel" style="display: none;">
                    <h6 class="text-muted small mb-2">หรือเลือกปริมาณ (ลิตร)</h6>
                    <div class="quick-amount-grid mb-3">
                        <button type="button" class="quick-btn" data-liter="10">10 ลิตร</button>
                        <button type="button" class="quick-btn" data-liter="20">20 ลิตร</button>
                        <button type="button" class="quick-btn" data-liter="30">30 ลิตร</button>
                        </div>
                </div>
                <div class="numpad-grid">
                  <button type="button" class="numpad-btn" data-num="7">7</button>
                  <button type="button" class="numpad-btn" data-num="8">8</button>
                  <button type="button" class="numpad-btn" data-num="9">9</button>
                  <button type="button" class="numpad-btn" data-num="4">4</button>
                  <button type="button" class="numpad-btn" data-num="5">5</button>
                  <button type="button" class="numpad-btn" data-num="6">6</button>
                  <button type="button" class="numpad-btn" data-num="1">1</button>
                  <button type="button" class="numpad-btn" data-num="2">2</button>
                  <button type="button" class="numpad-btn" data-num="3">3</button>
                  <button type="button" class="numpad-btn" data-action="decimal">.</button>
                  <button type="button" class="numpad-btn" data-num="0">0</button>
                  <button type="button" class="numpad-btn" data-action="backspace">
                    <i class="bi bi-backspace-fill"></i>
                  </button>
                </div>
                <button type="button" class="btn btn-danger w-100 mt-3" data-action="clear">
                  ล้างค่า (C)
                </button>
            </div>
            <div class="col-md-5">
                <h6 class="text-muted">คำนวณเบื้องต้น</h6>
                <div id="previewCalc" class="p-3 rounded">
                   <p class="text-muted text-center">กรุณากรอกจำนวน</p>
                </div>
                
                <div class="text-center mt-4 d-grid gap-2">
                    <button type="button" class="btn btn-primary btn-lg" id="nextToStep4" disabled>
                        ถัดไป <i class="bi bi-arrow-right ms-2"></i>
                        <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">
                          <i class="bi bi-arrow-left me-2"></i> ย้อนกลับ
                        </button>
                </div>
            </div>
          </div>
        </div>

        <div id="step4-panel" style="display:none;">
          <div class="row g-4">
            <div class="col-lg-7">
              <div class="pos-panel">
                <h5 class="mb-3">
                  <i class="bi bi-card-checklist me-2"></i>
                  ขั้นตอนที่ 4: ระบุข้อมูลการขาย
                </h5>

                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label">วิธีการชำระเงิน <span class="text-danger">*</span></label>
                    <select class="form-select form-select-lg" name="payment_method" required>
                      <option value="cash">💵 เงินสด</option>
                      <option value="qr">📱 QR Code</option>
                      <option value="transfer">🏦 โอนเงิน</option>
                      <option value="card">💳 บัตรเครดิต</option>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">เบอร์โทร (สะสมแต้ม)</label>
                    <input type="tel" class="form-control" name="customer_phone" 
                           placeholder="08xxxxxxxx" pattern="[0-9\s\-]{8,20}">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">บ้านเลขที่</label>
                    <input type="text" class="form-control" name="household_no" 
                           placeholder="เช่น 123/4">
                  </div>

                  <div class="col-12" id="memberInfo" style="display: none;">
                    <div class="alert alert-info py-2 px-3">
                      <i class="bi bi-person-check-fill me-2"></i>
                      <span id="memberName"></span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-lg-5">
              <div class="pos-panel">
                <h5 class="mb-3">
                  <i class="bi bi-receipt me-2"></i>
                  สรุปรายการขาย
                </h5>
                <div id="finalSummary" class="mb-3">
                    </div>

                <div class="d-grid gap-2 mt-4">
                  <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    ยืนยันและบันทึกการขาย
                  </button>
                  <button type="button" class="btn btn-outline-secondary" onclick="goToStep(3)">
                    <i class="bi bi-arrow-left me-2"></i> ย้อนกลับ
                  </button>
                  <button type="button" class="btn btn-outline-danger" onclick="resetAll()">
                    <i class="bi bi-x-circle me-2"></i> ยกเลิกทั้งหมด
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </form>
    </main>
  
  <?php if ($sale_success && $sale_data_json): ?>
  <div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">ใบเสร็จรับเงิน</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <?php
        $pay_th = [
          'cash'     => 'เงินสด', 'qr' => 'QR Code',
          'transfer' => 'โอนเงิน', 'card' => 'บัตรเครดิต',
        ];
        ?>
        <div class="modal-body">
          <div id="receiptContent" class="receipt receipt-print-area">
            <div class="text-center border-bottom border-dark border-dashed pb-2 mb-2">
              <h5><?= htmlspecialchars($sale_data['site_name']) ?></h5>
              <p class="mb-0">ใบเสร็จรับเงิน</p>
              <p class="mb-0">เลขที่: <?= htmlspecialchars($sale_data['receipt_no']) ?></p>
              <p class="mb-0">วันที่: <?= date('d/m/Y H:i', strtotime($sale_data['datetime'])) ?></p>
            </div>
            <?php if (!empty($sale_data['customer_phone'])): ?>
              <div class="d-flex justify-content-between"><span>เบอร์โทร:</span><span><?= htmlspecialchars($sale_data['customer_phone']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($sale_data['household_no'])): ?>
              <div class="d-flex justify-content-between"><span>บ้านเลขที่:</span><span><?= htmlspecialchars($sale_data['household_no']) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between"><span>รายการ:</span><span><?= htmlspecialchars($sale_data['fuel_name']) ?></span></div>
            <div class="d-flex justify-content-between"><span>ราคา/ลิตร:</span><span><?= number_format($sale_data['price_per_liter'], 2) ?></span></div>
            <div class="d-flex justify-content-between"><span>ปริมาณ:</span><span><?= number_format($sale_data['liters'], 3) ?> ลิตร</span></div>
            <hr class="my-1 border-dark border-dashed">
            <div class="d-flex justify-content-between"><span>ยอดรวม:</span><span><?= number_format($sale_data['total_amount'], 2) ?> บาท</span></div>
            <?php if ($sale_data['discount_amount'] > 0): ?>
              <div class="d-flex justify-content-between text-danger"><span>ส่วนลด (<?= $sale_data['discount_percent'] ?>%):</span><span>-<?= number_format($sale_data['discount_amount'], 2) ?></span></div>
            <?php endif; ?>
            <hr class="my-1 border-dark border-dashed">
            <div class="d-flex justify-content-between fw-bold fs-5"><span>ยอดสุทธิ:</span><span><?= number_format($sale_data['net_amount'], 2) ?> บาท</span></div>
            <hr class="my-1 border-dark border-dashed">
            <?php if (!empty($sale_data['points_earned'])): ?>
              <div class="d-flex justify-content-between"><span>แต้มที่ได้รับ:</span><span><?= number_format($sale_data['points_earned']) ?> แต้ม</span></div>
              <hr class="my-1 border-dark border-dashed">
            <?php endif; ?>
            <div class="d-flex justify-content-between"><span>ชำระโดย:</span><span><?= htmlspecialchars($pay_th[$sale_data['payment_method']] ?? $sale_data['payment_method']) ?></span></div>
            <div class="d-flex justify-content-between"><span>พนักงาน:</span><span><?= htmlspecialchars($sale_data['employee_name']) ?></span></div>
            <p class="text-center mt-3">** ขอบคุณที่ใช้บริการ **</p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
          <button type="button" class="btn btn-primary" onclick="printReceipt()"><i class="bi bi-printer"></i> พิมพ์</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <footer class="footer mt-4 text-center text-muted">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
(function(){ 

// ===== State =====
let currentStep = 1;
let selectedFuel = null;
let selectedFuelName = '';
let currentPrice = 0;
let saleType = '';
let currentInput = '0';

// ===== DOM =====
const fuelCards = document.querySelectorAll('.fuel-card');
const saleTypeCards = document.querySelectorAll('.sale-type-card');
const numpadBtns = document.querySelectorAll('.numpad-btn');
const quickAmountBtns = document.querySelectorAll('#quickAmountPanel .quick-btn'); 
const quickLiterBtns = document.querySelectorAll('#quickLiterPanel .quick-btn');   
const display = document.getElementById('amountDisplay');
const quantityInput = document.getElementById('quantityInput');
const selectedFuelInp = document.getElementById('selectedFuel');
const saleTypeInput = document.getElementById('saleTypeInput');
const discountInput = document.getElementById('discountInput');
const customerPhoneInput = document.querySelector('input[name="customer_phone"]');
const householdNoInput = document.querySelector('input[name="household_no"]');
const memberInfoDiv = document.getElementById('memberInfo');
const memberNameSpan = document.getElementById('memberName');
const previewCalcDiv = document.getElementById('previewCalc');
const finalSummaryDiv = document.getElementById('finalSummary');

// ===== Event Listeners =====
fuelCards.forEach(card => card.addEventListener('click', handleFuelSelect));
saleTypeCards.forEach(card => card.addEventListener('click', handleSaleTypeSelect));
numpadBtns.forEach(btn => btn.addEventListener('click', handleNumpad));
quickAmountBtns.forEach(btn => btn.addEventListener('click', handleQuickAmount)); 
quickLiterBtns.forEach(btn => btn.addEventListener('click', handleQuickLiter));   
discountInput?.addEventListener('input', updateFinalSummary);
customerPhoneInput.addEventListener('input', handleMemberSearch);
householdNoInput.addEventListener('input', handleMemberSearch);

document.getElementById('nextToStep2').addEventListener('click', () => goToStep(2));
document.getElementById('nextToStep3').addEventListener('click', () => goToStep(3));
document.getElementById('nextToStep4').addEventListener('click', () => goToStep(4));

document.querySelector('[data-action="clear"]').addEventListener('click', function() {
  currentInput = '0';
  updateDisplay();
});

// ===== Step 1: Select Fuel =====
function handleFuelSelect(e) {
  fuelCards.forEach(c => c.classList.remove('selected'));
  const card = e.currentTarget;
  card.classList.add('selected');
  
  selectedFuel = card.dataset.fuel;
  selectedFuelName = card.dataset.name;
  currentPrice = parseFloat(card.dataset.price);
  selectedFuelInp.value = selectedFuel;
  
  document.getElementById('nextToStep2').disabled = false;
  updateStepIndicator(1, 'completed');
}

// ===== Step 2: Select Sale Type =====
function handleSaleTypeSelect(e) {
  saleTypeCards.forEach(c => c.classList.remove('selected'));
  const card = e.currentTarget;
  card.classList.add('selected');
  
  saleType = card.dataset.type;
  saleTypeInput.value = saleType;
  
  document.getElementById('nextToStep3').disabled = false;
  updateStepIndicator(2, 'completed');
  
  currentInput = '0';
  updateDisplay();
}

// ===== Step 3: Enter Amount =====
function handleQuickAmount(e) {
  const amount = e.currentTarget.dataset.amount;
  if (!amount || saleType !== 'amount') return; 

  currentInput = amount;
  updateDisplay();
}

function handleQuickLiter(e) {
  const liters = e.currentTarget.dataset.liter;
  if (!liters || saleType !== 'liters') return; 

  if (liters === 'full') {
    alert('ฟังก์ชันเต็มถังยังไม่พร้อมใช้งาน');
    return;
  }

  currentInput = liters;
  updateDisplay();
}

function handleNumpad(e) {
  const btn = e.currentTarget;
  const num = btn.dataset.num;
  const action = btn.dataset.action;

  if (num !== undefined) {
    if (currentInput === '0') currentInput = '';
    if (currentInput.includes('.') && currentInput.split('.')[1].length >= 2) {
       return; 
    }
    if (currentInput.length < 9) {
       currentInput += num;
    }
  } else if (action === 'decimal') {
    if (!currentInput.includes('.')) currentInput += '.';
  } else if (action === 'backspace') {
    currentInput = currentInput.slice(0, -1);
    if (currentInput === '') currentInput = '0';
  }
  
  updateDisplay();
}

function updateDisplay() {
  display.textContent = currentInput;
  quantityInput.value = currentInput;
  updatePreview();
  
  const qty = parseFloat(currentInput);
  const isQtyValid = (qty > 0.01);
  
  document.getElementById('nextToStep4').disabled = !isQtyValid; 
  
  if (isQtyValid) {
    updateStepIndicator(3, 'completed');
  } else {
    updateStepIndicator(3, 'active'); 
  }
}

function updatePreview() {
  const qty = parseFloat(currentInput) || 0;
  if (qty === 0) {
    previewCalcDiv.innerHTML = '<p class="text-muted text-center">กรุณากรอกจำนวน</p>';
    return;
  }

  let liters, amount;
  if (saleType === 'liters') {
    liters = qty;
    amount = liters * currentPrice;
  } else {
    amount = qty;
    liters = (currentPrice > 0) ? (amount / currentPrice) : 0;
  }

  const html = `
    <div class="d-flex justify-content-between mb-2">
      <strong>น้ำมัน:</strong>
      <span>${selectedFuelName}</span>
    </div>
    <div class="d-flex justify-content-between mb-2">
      <strong>ราคา/ลิตร:</strong>
      <span>${currentPrice.toFixed(2)} ฿</span>
    </div>
    <hr class="my-1">
    <div class="d-flex justify-content-between mb-2">
      <strong>ปริมาณ:</strong>
      <span class="text-primary fw-bold">${liters.toFixed(3)} ลิตร</span>
    </div>
    <div class="d-flex justify-content-between">
      <strong>ยอดรวม:</strong>
      <span class="text-success fw-bold h5 mb-0">${amount.toFixed(2)} บาท</span>
    </div>
  `;
  
  previewCalcDiv.innerHTML = html;
}

// ===== Step 4: Final Summary =====
function updateFinalSummary() {
  const qty = parseFloat(currentInput) || 0;
  const disc = parseFloat(discountInput?.value || '0') || 0;

  let liters, total;
  if (saleType === 'liters') {
    liters = qty;
    total = liters * currentPrice;
  } else {
    total = qty;
    liters = (currentPrice > 0) ? (total / currentPrice) : 0;
  }

  const discAmount = total * (disc / 100);
  const net = total - discAmount;
  const points = Math.floor(net / 20); 

  const html = `
    <div class="row mb-2">
      <div class="col-5">น้ำมัน:</div>
      <div class="col-7 text-end"><strong>${selectedFuelName}</strong></div>
    </div>
    <div class="row mb-2">
      <div class="col-5">ราคา/ลิตร:</div>
      <div class="col-7 text-end">${currentPrice.toFixed(2)} ฿</div>
    </div>
    <hr>
    <div class="row mb-2">
      <div class="col-5">ปริมาณ:</div>
      <div class="col-7 text-end">${liters.toFixed(3)} ลิตร</div>
    </div>
    <div class="row mb-2">
      <div class="col-5">ยอดรวม:</div>
      <div class="col-7 text-end">${total.toFixed(2)} ฿</div>
    </div>
    ${disc > 0 ? `
    <div class="row mb-2 text-danger">
      <div class="col-5">ส่วนลด (${disc}%):</div>
      <div class="col-7 text-end">-${discAmount.toFixed(2)} ฿</div>
    </div>` : ''}
    <hr>
    <div class="row mb-3 align-items-center">
      <div class="col-5"><h4 class="mb-0">ยอดสุทธิ:</h4></div>
      <div class="col-7 text-end"><h4 class="mb-0">${net.toFixed(2)} ฿</h4></div>
    </div>
    ${points > 0 ? `
    <div class="text-center">
      <span class="badge bg-warning text-dark fs-6">🎁 รับแต้ม ${points} แต้ม</span>
    </div>` : ''}
  `;

  finalSummaryDiv.innerHTML = html;
}

// ===== Navigation =====
function goToStep(step) {
    currentStep = step;
    
    document.getElementById('step1-panel').style.display = 'none';
    document.getElementById('step2-panel').style.display = 'none';
    document.getElementById('step3-panel').style.display = 'none';
    document.getElementById('step4-panel').style.display = 'none';
    
    document.getElementById(`step${currentStep}-panel`).style.display = 'block';
    
    for (let i = 1; i <= 4; i++) {
        const indicator = document.getElementById(`step${i}-indicator`);
        indicator.classList.remove('active', 'completed');
        if (i < currentStep) {
            indicator.classList.add('completed');
        } else if (i === currentStep) {
            indicator.classList.add('active');
        }
    }
    
    if (step === 1) {
        document.getElementById('nextToStep2').disabled = !selectedFuel;
    } else if (step === 2) {
        document.getElementById('selectedFuelInfo').innerHTML = `
          <strong>เลือกแล้ว:</strong> ${selectedFuelName} (${currentPrice.toFixed(2)} ฿/ลิตร)
        `;
        document.getElementById('nextToStep3').disabled = !saleType;
    } else if (step === 3) {
        const label = saleType === 'liters' ? ' (ลิตร)' : ' (บาท)';
        document.getElementById('saleTypeLabel').textContent = label;
        
        const isAmountMode = (saleType === 'amount');
        document.getElementById('quickAmountPanel').style.display = isAmountMode ? 'block' : 'none';
        document.getElementById('quickLiterPanel').style.display = isAmountMode ? 'none' : 'block';
        
        updateDisplay();
    } else if (step === 4) {
        updateFinalSummary();
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateStepIndicator(step, status) {
  const indicator = document.getElementById(`step${step}-indicator`);
  indicator.classList.remove('active'); 
  
  if (status === 'completed') {
    indicator.classList.add('completed');
  } else if (status === 'active') {
     indicator.classList.add('active');
  } else {
     indicator.classList.remove('completed');
  }
}

function resetAll() {
  currentStep = 1;
  selectedFuel = null;
  saleType = '';
  currentInput = '0';
  
  fuelCards.forEach(c => c.classList.remove('selected'));
  saleTypeCards.forEach(c => c.classList.remove('selected'));
  
  document.getElementById('nextToStep2').disabled = true;
  document.getElementById('nextToStep3').disabled = true;
  document.getElementById('nextToStep4').disabled = true;

  customerPhoneInput.value = '';
  householdNoInput.value = '';
  memberInfoDiv.style.display = 'none';
  if (discountInput) discountInput.value = '0';
  
  updateDisplay();
  goToStep(1);
}

// ===== Member Search =====
let searchTimeout;
function handleMemberSearch(e) {
  clearTimeout(searchTimeout);
  
  const phone = customerPhoneInput.value.trim();
  const house = householdNoInput.value.trim();
  const term = phone || house; 

  if (!term) {
    memberInfoDiv.style.display = 'none';
    return;
  }
  
  searchTimeout = setTimeout(() => findMember(phone, house), 500);
}

async function findMember(phone, house) {
  memberInfoDiv.style.display = 'block';
  const alertDiv = memberInfoDiv.querySelector('.alert');
  memberNameSpan.innerHTML = 'กำลังค้นหา...';
  alertDiv.className = 'alert alert-secondary py-2 px-3';

  try {
    const res = await fetch(`/api/search_member.php?phone=${encodeURIComponent(phone)}&house=${encodeURIComponent(house)}`);
    const member = await res.json();

    if (member && !member.error) {
      alertDiv.className = 'alert alert-info py-2 px-3';
      memberNameSpan.innerHTML = `<i class="bi bi-person-check-fill me-2"></i>สมาชิก: ${member.full_name}`;
      customerPhoneInput.value = member.phone || '';
      householdNoInput.value = member.house_number || '';
      updateFinalSummary();
    } else {
      alertDiv.className = 'alert alert-warning py-2 px-3';
      memberNameSpan.innerHTML = '<i class="bi bi-person-exclamation me-2"></i>ไม่พบสมาชิก';
    }
  } catch (error) {
    alertDiv.className = 'alert alert-danger py-2 px-3';
    memberNameSpan.innerHTML = '<i class="bi bi-wifi-off me-2"></i>การเชื่อมต่อล้มเหลว';
  }
}

// ===== Print Receipt =====
function printReceipt() {
  if (typeof saleDataForReceipt === 'undefined' || !saleDataForReceipt) {
    alert('ไม่มีข้อมูลใบเสร็จ');
    return;
  }
  
  const {
    site_name, receipt_no, datetime, fuel_name, price_per_liter, liters,
    total_amount, discount_percent, discount_amount, net_amount,
    payment_method, employee_name, customer_phone, household_no, points_earned
  } = saleDataForReceipt;

  const saleDate = new Date(datetime).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
  const payMap = { cash:'เงินสด', qr:'QR Code', transfer:'โอนเงิน', card:'บัตรเครดิต' };
  const payKey  = (payment_method || '').toString().toLowerCase();
  const payLabel = payMap[payKey] || payment_method || 'ไม่ระบุ';

  const receiptHTML = `
    <html><head><title>ใบเสร็จ ${receipt_no}</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap" rel="stylesheet">
    <style>
      body { font-family:'Sarabun',sans-serif; width:300px; margin:0 auto; padding:10px; color:#000; font-size:14px; }
      h3,h4,p{ margin:0; text-align:center; }
      h3{ font-size:1.1rem } h4{ font-weight:normal; font-size:.9rem }
      hr{ border:none; border-top:1px dashed #000; margin:6px 0 }
      .row{ display:flex; justify-content:space-between; margin-bottom:2px; }
      .total{ font-weight:700; font-size:1.05rem }
    </style></head><body>
      <h3>${site_name}</h3><h4>ใบเสร็จรับเงิน</h4><hr>
      <div class="row"><span>เลขที่:</span><span>${receipt_no}</span></div>
      <div class="row"><span>วันที่:</span><span>${saleDate}</span></div><hr>
      ${customer_phone ? `<div class="row"><span>เบอร์โทร:</span><span>${customer_phone}</span></div>`:''}
      ${household_no ? `<div class="row"><span>บ้านเลขที่:</span><span>${household_no}</span></div>`:''}
      <div class="row"><span>${parseFloat(liters).toFixed(3)} L. @ ${parseFloat(price_per_liter).toFixed(2)}</span><span>${parseFloat(total_amount).toFixed(2)}</span></div><hr>
      ${parseFloat(discount_amount)>0?`<div class="row"><span>ส่วนลด (${parseFloat(discount_percent)}%):</span><span>-${parseFloat(discount_amount).toFixed(2)}</span></div>`:''}
      <div class="row total"><span>รวมทั้งสิ้น</span><span>${parseFloat(net_amount).toFixed(2)} บาท</span></div><hr>
      ${parseInt(points_earned)>0?`<div class="row"><span>แต้มที่ได้รับ</span><span>${parseInt(points_earned)} แต้ม</span></div><hr>`:''}
      <div class="row"><span>ชำระโดย:</span><span>${payLabel}</span></div>
      <div class="row"><span>พนักงาน:</span><span>${employee_name}</span></div>
      <p style="margin-top:10px;">** ขอบคุณที่ใช้บริการ **</p>
    </body></html>`;
  
  try {
    const w = window.open('', '_blank');
    w.document.write(receiptHTML); 
    w.document.close(); 
    w.focus();
    setTimeout(()=>{ w.print(); w.close(); }, 250);
  } catch(e) {
    console.error("Print failed:", e);
  }
}

// ✅ Expose functions เป็น global เพื่อให้ onclick ใช้ได้
window.goToStep = goToStep;
window.resetAll = resetAll;
window.printReceipt = printReceipt;

// ===== Init =====
<?php if ($sale_success && $sale_data_json): ?>
  const saleDataForReceipt = <?= $sale_data_json; ?>;
  const receiptModalEl = document.getElementById('receiptModal');
  if (receiptModalEl) {
    const receiptModal = new bootstrap.Modal(receiptModalEl);
    receiptModal.show(); 
    receiptModalEl.addEventListener('hidden.bs.modal', event => {
        resetAll();
    });
  }
<?php endif; ?>

goToStep(1);

})(); 
</script>
</body>
</html>