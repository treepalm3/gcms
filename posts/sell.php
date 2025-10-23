<?php
// employee/sell.php — ระบบ POS ปั๊มน้ำมัน (UX/UI ปรับปรุงใหม่)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== บังคับล็อกอิน =====
// (ส่วนนี้ผมลบออกตามคำขอของคุณที่ว่า "ไม่ต้องเข้าระบบ")
/*
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_name    = $_SESSION['full_name'] ?? 'พนักงาน';
$current_role    = $_SESSION['role'] ?? 'employee';
if ($current_user_id === 0 || $current_role !== 'employee') {
  header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
  exit();
}
*/

// ===== แต่เรายังต้องการ User ID และ ชื่อพนักงาน (แม้จะไม่ได้ล็อกอิน) =====
// ===== !! นี่คือส่วนที่คุณต้องแก้ไข ให้ตรงกับระบบของคุณ !! =====

// ** สมมติว่าคุณมีหน้า "ป้อนรหัสพนักงาน" ก่อนหน้านี้
// ** แล้วหน้านั้นส่ง 'emp_code' มายังหน้านี้
$emp_code = $_GET['emp_code'] ?? 'E-001'; // << สมมติว่ารับรหัสพนักงานมา

// ===== การเชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องกำหนดตัวแปร $pdo (PDO)

// ===== ค้นหาพนักงานจากรหัส =====
$current_user_id = null;
$current_name = 'พนักงาน (ไม่ระบุตัวตน)';
$avatar_text = 'E';
$current_role_th = 'พนักงาน';

try {
    $stmt_emp = $pdo->prepare("
        SELECT u.id, u.full_name 
        FROM users u
        JOIN employees e ON u.id = e.user_id
        WHERE e.emp_code = :emp_code
        LIMIT 1
    ");
    $stmt_emp->execute([':emp_code' => $emp_code]);
    $employee_data = $stmt_emp->fetch(PDO::FETCH_ASSOC);

    if ($employee_data) {
        $current_user_id = (int)$employee_data['id'];
        $current_name = $employee_data['full_name'];
        $avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
    } else {
        // ถ้าไม่เจอพนักงาน อาจจะยังให้ขายได้ แต่ใช้ ID กลาง
        $current_user_id = 3; // << สมมติ ID 3 คือ "พนักงานขายหน้าร้าน"
        $current_name = "พนักงาน (รหัส {$emp_code})";
        error_log("POS: Employee code '{$emp_code}' not found or not linked to user.");
    }
} catch (Throwable $e) {
    // กรณีตาราง employees ไม่มี
    error_log("Employee lookup failed: " . $e->getMessage());
    $current_user_id = 3; // ID พนักงานสำรอง
}


// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== Helpers =====
function get_setting(PDO $pdo, string $name, $default = null) {
  try {
    // (โค้ด helper ... เหมือนเดิม)
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name = :n LIMIT 1");
    $stmt->execute([':n' => $name]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : $default;
  } catch (Throwable $e) { return $default; }
}

function has_column(PDO $pdo, string $table, string $column): bool {
  // (โค้ด helper ... เหมือนเดิม)
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
$site_name = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$station_id = get_setting($pdo, 'station_id', 1);

/* ================== ดึงข้อมูลน้ำมันและราคา ================== */
$fuel_types = [];
$fuel_colors_by_name = [
  'ดีเซล'         => '#CCA43B',
  'แก๊สโซฮอล์ 95' => '#20A39E',
  'แก๊สโซฮอล์ 91' => '#B66D0D',
];

try {
  // (โค้ดดึงราคาน้ำมัน ... เหมือนเดิม)
  $stmt_fuel = $pdo->prepare("
      SELECT fuel_id, fuel_name, price
      FROM fuel_prices
      WHERE station_id = :sid
      ORDER BY COALESCE(display_order, 99) ASC, fuel_id ASC
    ");
    $stmt_fuel->execute([':sid' => $station_id]);
  
  while ($row = $stmt_fuel->fetch(PDO::FETCH_ASSOC)) {
    $name  = $row['fuel_name'];
    $color = $fuel_colors_by_name[$name] ?? '#6c757d';
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
    // ===== รับค่า =====
    $fuel_id        = (string)($_POST['fuel_type'] ?? '');
    $sale_type      = $_POST['sale_type'] ?? 'amount'; // amount|liters
    $quantity       = filter_var($_POST['quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $customer_phone = preg_replace('/\D+/', '', (string)($_POST['customer_phone'] ?? ''));
    $household_no   = trim((string)($_POST['household_no'] ?? ''));
    $discount       = 0.0; // สมมติว่าไม่มีส่วนลดใน UX ใหม่นี้ (หรือดึงจาก $_POST['discount'] ถ้ามี)

    $allowed_payments  = ['cash', 'qr', 'transfer', 'card'];
    $allowed_sale_type = ['liters','amount'];

    // ===== ตรวจสอบ =====
    if (!array_key_exists($fuel_id, $fuel_types)) {
      $sale_error = 'กรุณาเลือกชนิดน้ำมันให้ถูกต้อง';
    } elseif ($quantity === false || $quantity <= 0.01) { // ป้องกันยอด 0
      $sale_error = 'กรุณาใส่จำนวนเงินหรือปริมาณลิตรให้ถูกต้อง';
    } elseif (!in_array($payment_method, $allowed_payments, true)) {
      $sale_error = 'วิธีการชำระเงินไม่ถูกต้อง';
    } elseif (!in_array($sale_type, $allowed_sale_type, true)) {
      $sale_error = 'ประเภทการขายไม่ถูกต้อง';
    } else {
      // ===== คำนวณ =====
      $fuel_price = (float)$fuel_types[$fuel_id]['price'];
      $fuel_name  = $fuel_types[$fuel_id]['name'];

      if ($sale_type === 'liters') {
        $liters_raw   = (float)$quantity;
        $liters_disp  = round($liters_raw, 3);
        $liters_db    = round($liters_raw, 2);
        $total_amount = round($liters_db * $fuel_price, 2);
      } else {
        $total_amount = round((float)$quantity, 2);
        $liters_calc  = ($fuel_price > 0 ? $total_amount / $fuel_price : 0.0);
        $liters_disp  = round($liters_calc, 3);
        $liters_db    = round($liters_calc, 2);
      }
      
      // คำนวณส่วนลด (ถ้ามี)
      $discount_amount = round($total_amount * ($discount/100.0), 2);
      $net_amount      = round($total_amount - $discount_amount, 2);

      $POINT_RATE     = 20; // 1 แต้ม ต่อ 20 บาท
      $has_loyalty_id = (bool)($customer_phone || $household_no);
      $points_earned  = $has_loyalty_id ? (int)floor($net_amount / $POINT_RATE) : 0;
      $now = date('Y-m-d H:i:s');

      // ===== เตรียมข้อมูลใบเสร็จสำหรับแสดงผล =====
      $sale_data = [
        'site_name'        => $site_name, 'receipt_no' => '', 'datetime' => $now,
        'fuel_type'        => $fuel_id, 'fuel_name' => $fuel_name, 'price_per_liter'  => $fuel_price,
        'liters'           => $liters_disp, 'total_amount' => $total_amount, 'discount_percent' => $discount,
        'discount_amount'  => $discount_amount, 'net_amount' => $net_amount, 'payment_method' => $payment_method,
        'customer_phone'   => $customer_phone, 'household_no' => $household_no, 'points_earned' => $points_earned,
        'employee_id'      => $current_user_id, 'employee_name'    => $current_name
      ];

      // ====== บันทึกลงฐานข้อมูล ======
      try {
        $pdo->beginTransaction();

        // (โค้ดส่วน INSERT sales, sales_items, fuel_moves, fuel_lot_allocations, scores ... เหมือนเดิมทุกประการ)
        // ...
        // 1. INSERT sales
        $col_phone   = has_column($pdo, 'sales', 'customer_phone');
        // ... (โค้ดสร้าง $sale_id) ...
        $tries = 0; $sale_id = null;
        do {
          $receipt_no = 'R'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
          // ... (โค้ดเตรียม $cols, $params) ...
          $cols = ['station_id','sale_code','total_amount','net_amount','sale_date','payment_method','created_by'];
          $params = [
            ':station_id' => $station_id, ':sale_code' => $receipt_no,
            ':total_amount' => $total_amount, ':net_amount' => $net_amount,
            ':sale_date' => $now, ':payment_method' => $payment_method,
            ':created_by' => $current_user_id, // ใช้ ID พนักงานที่หาเจอ
          ];
          if ($col_phone) { $cols[] = 'customer_phone';  $params[':customer_phone']  = $customer_phone ?: null; }
          // ... (เพิ่ม $cols, $params อื่นๆ) ...
          
          $sql = "INSERT INTO sales (".implode(',', $cols).") VALUES (".implode(',', array_keys($params)).")";
          try {
            $stmtSale = $pdo->prepare($sql);
            $stmtSale->execute($params);
            $sale_id = (int)$pdo->lastInsertId();
            $sale_data['receipt_no'] = $receipt_no;
            break;
          } catch (PDOException $ex) {
            if ($ex->getCode() === '23000' && ++$tries <= 5) continue;
            throw $ex;
          }
        } while ($tries <= 5);
        if (!$sale_id) { throw new RuntimeException('ไม่สามารถสร้างเลขที่ใบเสร็จได้'); }

        // 2. หา Tank ID
        $tank_id = null;
        try {
          $findTank = $pdo->prepare("SELECT id FROM fuel_tanks WHERE station_id = :sid AND fuel_id = :fid AND is_active = 1 AND current_volume_l >= :liters ORDER BY current_volume_l DESC LIMIT 1");
          $findTank->execute([':sid'=>$station_id, ':fid'=>(int)$fuel_id, ':liters' => $liters_db]);
          $tank_id = $findTank->fetchColumn() ?: null;
        } catch (Throwable $e) { $tank_id = null; }

        // 3. INSERT sales_items
        $stmtItem = $pdo->prepare("INSERT INTO sales_items (sale_id, fuel_id, tank_id, fuel_type, liters, price_per_liter) VALUES (:sale_id, :fuel_id, :tank_id, :fuel_type, :liters, :price_per_liter)");
        $stmtItem->execute([':sale_id' => $sale_id, ':fuel_id' => (int)$fuel_id, ':tank_id' => $tank_id, ':fuel_type' => $fuel_name, ':liters' => $liters_db, ':price_per_liter' => round($fuel_price, 2)]);

        // 4. ตัดสต็อก, บันทึก move, COGS
        try {
          if ($tank_id) {
            // ... (โค้ดส่วนตัดสต็อก, fuel_moves, fuel_lot_allocations, fuel_stock ... เหมือนเดิม) ...
          } else {
            error_log("No active tank for station {$station_id} and fuel {$fuel_id} with enough stock ({$liters_db}L) for sale {$sale_id}");
          }
        } catch (Throwable $invE) { error_log("Inventory update skipped: ".$invE->getMessage()); }
        
        // 5. สะสมแต้ม
        if ($points_earned > 0 && ($customer_phone !== '' || $household_no !== '')) {
            // ... (โค้ดค้นหาสมาชิกและ INSERT scores ... เหมือนเดิม) ...
        }

        // ... (สิ้นสุดโค้ดประมวลผลฟอร์ม) ...

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

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    /* (CSS ... เหมือนเดิม) */
    .fuel-selector{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem}
    .fuel-card{border:3px solid var(--border);border-radius:12px;padding:1rem;cursor:pointer;transition:.2s;text-align:center; box-shadow: 0 4px 10px rgba(0,0,0,.03);}
    .fuel-card:hover{border-color:var(--primary);transform:translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,.07);}
    .fuel-card.selected{border-color:var(--primary);background-color:var(--primary-light);box-shadow:0 4px 15px rgba(32,163,158,.25)}
    .fuel-icon{width:50px;height:50px;border-radius:50%;margin:0 auto .5rem;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff}
    .pos-panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:1.5rem}
    .amount-display{background:var(--dark);color:#20e8a0;font-family:"Courier New",monospace;border-radius:var(--radius);padding:1rem;text-align:right;font-size:2.25rem;font-weight:700;margin-bottom:1rem;min-height:70px}
    .numpad-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem}
    .numpad-btn{aspect-ratio:1.2/1;border:1px solid var(--border);background:var(--surface);border-radius:var(--radius);font-size:1.5rem;font-weight:600;cursor:pointer;transition:.15s}
    .numpad-btn:hover{background:var(--primary);color:#fff}
    .receipt{font-family:'Courier New',monospace}
    
    /* --- CSS ใหม่สำหรับ UX Steps --- */
    .step-indicator {
      position: relative;
      padding: 1rem;
      transition: all 0.3s;
    }
    .step-number {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: #e9ecef; /* สีเทาเริ่มต้น */
      color: #6c757d;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 1.5rem;
      margin: 0 auto 0.5rem;
      transition: all 0.3s;
    }
    .step-indicator.active .step-number {
      background: var(--primary); /* สีฟ้า/น้ำเงินเมื่อ Active */
      color: white;
      box-shadow: 0 4px 15px rgba(13, 110, 253, 0.4);
    }
    .step-indicator.completed .step-number {
      background: #198754; /* สีเขียวเมื่อสำเร็จ */
      color: white;
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

    .sale-type-card {
      border: 3px solid #e9ecef;
      border-radius: 12px;
      padding: 2rem;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s;
      height: 100%;
    }
    .sale-type-card:hover {
      border-color: var(--primary);
      transform: translateY(-5px);
      box-shadow: 0 8px 24px rgba(0,0,0,.1);
    }
    .sale-type-card.selected {
      border-color: var(--primary);
      background: #e7f1ff; /* สีฟ้าอ่อน (primary-light) */
      box-shadow: 0 8px 24px rgba(13, 110, 253, 0.3);
    }
    .sale-type-card i {
      color: var(--primary);
    }

    #previewCalc {
      min-height: 100px;
      font-size: 0.95rem;
    }

    #finalSummary {
      background: #f8f9fa; /* สีเทาอ่อน */
      color: #212529; /* สีดำ */
      padding: 1.5rem;
      border-radius: 12px;
      border: 1px solid #dee2e6;
    }
    #finalSummary .row { margin-bottom: 0.5rem; }
    #finalSummary hr { border-color: rgba(0,0,0,0.1); margin: 0.75rem 0; }
    #finalSummary h4 { color: var(--primary); }
    /* --- สิ้นสุด CSS ใหม่ --- */

    @media print{body *{visibility:hidden}.receipt-print-area,.receipt-print-area *{visibility:visible}.receipt-print-area{position:absolute;left:0;top:0;width:100%}}
  </style>
</head>

<body>
  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <a class="navbar-brand" href="sell.php"><?= htmlspecialchars($site_name) ?></a>
      </div>
      <div class="d-flex align-items-center gap-3 ms-auto">
        <div class="nav-identity text-end">
          <div class="nav-name"><?= htmlspecialchars($current_name) ?></div>
          <div class="nav-sub"><?= htmlspecialchars($current_role_th) ?></div>
        </div>
        <a href="profile.php" class="avatar-circle text-decoration-none"><?= htmlspecialchars($avatar_text) ?></a>
      </div>
    </div>
  </nav>

  <div class="container-fluid mt-4">
    <main class="p-0">
        
      <?php if ($sale_success && $sale_data): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>บันทึกสำเร็จ!</strong> เลขที่ใบเสร็จ: <?= htmlspecialchars($sale_data['receipt_no']) ?>.
          <button class="btn btn-sm btn-outline-success ms-2" data-bs-toggle="modal" data-bs-target="#receiptModal">
            <i class="bi bi-printer"></i> พิมพ์ใบเสร็จ
          </button>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <?php if ($sale_error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>เกิดข้อผิดพลาด!</strong> <?= htmlspecialchars($sale_error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <div class="card mb-4 shadow-sm">
        <div class="card-body">
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

      <form id="posForm" method="POST" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="process_sale">
        <input type="hidden" name="fuel_type" id="selectedFuel" required>
        <input type="hidden" name="quantity" id="quantityInput" value="0" required>
        <input type="hidden" name="sale_type" id="saleTypeInput" value="">

        <div id="step1-panel" class="pos-panel">
          <h5 class="mb-3">
            <i class="bi bi-fuel-pump-fill me-2"></i>
            ขั้นตอนที่ 1: เลือกชนิดน้ำมัน
          </h5>
          <div class="fuel-selector">
            <?php foreach ($fuel_types as $key => $fuel): ?>
            <div class="fuel-card" data-fuel="<?= htmlspecialchars($key) ?>" 
                 data-price="<?= htmlspecialchars($fuel['price']) ?>"
                 data-name="<?= htmlspecialchars($fuel['name']) ?>">
              <div class="fuel-icon" style="background-color: <?= htmlspecialchars($fuel['color']) ?>">
                <i class="bi bi-droplet-fill"></i>
              </div>
              <h6><?= htmlspecialchars($fuel['name']) ?></h6>
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

          <div class="row">
            <div class="col-md-7">
                <div id="amountDisplay" class="amount-display">0</div>
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
                <div id="previewCalc" class="p-3 bg-light rounded">
                   <p class="text-muted text-center">กรุณากรอกจำนวน</p>
                </div>
                
                <div class="text-center mt-4 d-grid gap-2">
                    <button type="button" class="btn btn-primary btn-lg" id="nextToStep4" disabled>
                        ถัดไป <i class="bi bi-arrow-right ms-2"></i>
                    </button>
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
                  
                  <input type="hidden" name="discount" id="discountInput" value="0">


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
                <div id="finalSummary">
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
  </div>

  <?php if ($sale_success && $sale_data_json): ?>
  <div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
         </div>
    </div>
  </div>
  <?php endif; ?>

  <footer class="footer mt-4">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
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
discountInput?.addEventListener('input', updateFinalSummary); // (ถ้ามีช่องส่วนลด)
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
  
  // รีเซ็ตค่าตัวเลขเมื่อเปลี่ยนประเภท
  currentInput = '0';
  updateDisplay();
}

// ===== Step 3: Enter Amount =====
function handleNumpad(e) {
  const btn = e.currentTarget;
  const num = btn.dataset.num;
  const action = btn.dataset.action;

  if (num !== undefined) {
    if (currentInput === '0') currentInput = '';
    if (currentInput.includes('.') && currentInput.split('.')[1].length >= 2) {
       // จำกัดทศนิยม 2 ตำแหน่ง
    } else if (currentInput.length < 9) {
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
  document.getElementById('nextToStep4').disabled = !(qty > 0.01); // ต้องมากกว่า 0
  
  if (qty > 0.01) {
    updateStepIndicator(3, 'completed');
  } else {
    updateStepIndicator(3, 'active'); // กลับเป็น active ถ้าค่าเป็น 0
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
    <hr>
    <div class="d-flex justify-content-between mb-2">
      <strong>ปริมาณ:</strong>
      <span class="text-primary">${liters.toFixed(3)} ลิตร</span>
    </div>
    <div class="d-flex justify-content-between">
      <strong>ยอดรวม:</strong>
      <span class="text-success fw-bold">${amount.toFixed(2)} บาท</span>
    </div>
  `;
  
  previewCalcDiv.innerHTML = html;
}

// ===== Step 4: Final Summary =====
function updateFinalSummary() {
  const qty = parseFloat(currentInput) || 0;
  const disc = parseFloat(discountInput?.value || '0') || 0; // (ถ้ามีช่องส่วนลด)

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
      <div class="col-6">น้ำมัน:</div>
      <div class="col-6 text-end"><strong>${selectedFuelName}</strong></div>
    </div>
    <div class="row mb-2">
      <div class="col-6">ราคา/ลิตร:</div>
      <div class="col-6 text-end">${currentPrice.toFixed(2)} ฿</div>
    </div>
    <hr>
    <div class="row mb-2">
      <div class="col-6">ปริมาณ:</div>
      <div class="col-6 text-end">${liters.toFixed(3)} ลิตร</div>
    </div>
    <div class="row mb-2">
      <div class="col-6">ยอดรวม:</div>
      <div class="col-6 text-end">${total.toFixed(2)} ฿</div>
    </div>
    ${disc > 0 ? `
    <div class="row mb-2 text-danger">
      <div class="col-6">ส่วนลด (${disc}%):</div>
      <div class="col-6 text-end">-${discAmount.toFixed(2)} ฿</div>
    </div>` : ''}
    <hr>
    <div class="row mb-3">
      <div class="col-6"><h4 class="mb-0">ยอดสุทธิ:</h4></div>
      <div class="col-6 text-end"><h4 class="mb-0">${net.toFixed(2)} ฿</h4></div>
    </div>
    ${points > 0 ? `
    <div class="text-center">
      <span class="badge bg-warning text-dark">🎁 รับแต้ม ${points} แต้ม</span>
    </div>` : ''}
  `;

  finalSummaryDiv.innerHTML = html;
}

// ===== Navigation =====
function goToStep(step) {
  currentStep = step;
  
  // Hide all panels
  for (let i = 1; i <= 4; i++) {
    document.getElementById(`step${i}-panel`).style.display = 'none';
  }
  
  // Show current panel
  document.getElementById(`step${currentStep}-panel`).style.display = 'block';
  
  // Update indicators
  for (let i = 1; i <= 4; i++) {
    const indicator = document.getElementById(`step${i}-indicator`);
    indicator.classList.remove('active');
    if (i < currentStep) {
      indicator.classList.add('completed');
    } else if (i === currentStep) {
      indicator.classList.add('active');
    } else {
      indicator.classList.remove('completed');
    }
  }
  
  // Update content based on step
  if (step === 2) {
    document.getElementById('selectedFuelInfo').innerHTML = `
      <strong>เลือกแล้ว:</strong> ${selectedFuelName} (${currentPrice.toFixed(2)} ฿/ลิตร)
    `;
  } else if (step === 3) {
    const label = saleType === 'liters' ? ' (ลิตร)' : ' (บาท)';
    document.getElementById('saleTypeLabel').textContent = label;
    updateDisplay(); // อัปเดต display และ preview
  } else if (step === 4) {
    updateFinalSummary();
  }
  
  // Scroll to top
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateStepIndicator(step, status) {
  const indicator = document.getElementById(`step${step}-indicator`);
  if (status === 'completed') {
    indicator.classList.add('completed');
  } else {
    indicator.classList.remove('completed');
  }
}

function resetAll() {
  if (!confirm('ยกเลิกและเริ่มใหม่?')) return;
  
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
  
  // ค้นหาเมื่อมีอย่างน้อยหนึ่งช่องที่มีข้อมูล
  const phone = customerPhoneInput.value.trim();
  const house = householdNoInput.value.trim();
  const term = phone || house; // ใช้เบอร์โทรเป็นหลัก ถ้าไม่มีก็ใช้บ้านเลขที่

  if (!term) {
    memberInfoDiv.style.display = 'none';
    return;
  }
  // อนุญาตให้ค้นหาแม้จะน้อยกว่า 3 ตัวอักษร (เผื่อบ้านเลขที่สั้นๆ)
  // if (term.length < 3) return; 

  searchTimeout = setTimeout(() => findMember(phone, house), 500);
}

async function findMember(phone, house) {
  memberInfoDiv.style.display = 'block';
  memberNameSpan.innerHTML = 'กำลังค้นหา...';

  try {
    // ส่งทั้งสองค่าไปให้ API
    const res = await fetch(`/api/search_member.php?phone=${encodeURIComponent(phone)}&house=${encodeURIComponent(house)}`);
    const member = await res.json();

    if (member && !member.error) {
      memberInfoDiv.className = 'alert alert-info py-2 px-3';
      memberNameSpan.innerHTML = `<i class="bi bi-person-check-fill me-2"></i>สมาชิก: ${member.full_name}`;
      // อัปเดตทั้งสองช่องให้ตรงกัน
      customerPhoneInput.value = member.phone || '';
      householdNoInput.value = member.house_number || '';
      updateFinalSummary();
    } else {
      memberInfoDiv.className = 'alert alert-warning py-2 px-3';
      memberNameSpan.innerHTML = '<i class="bi bi-person-exclamation me-2"></i>ไม่พบสมาชิก';
    }
  } catch (error) {
    memberInfoDiv.className = 'alert alert-danger py-2 px-3';
    memberNameSpan.innerHTML = '<i class="bi bi-wifi-off me-2"></i>การเชื่อมต่อล้มเหลว';
  }
}

// ===== Print Receipt (เหมือนเดิม) =====
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

// ===== Init =====
// เปิด modal อัตโนมัติเมื่อบันทึกสำเร็จ (เหมือนเดิม)
<?php if ($sale_success && $sale_data_json): ?>
  const saleDataForReceipt = <?= $sale_data_json; ?>;
  const receiptModalEl = document.getElementById('receiptModal');
  if (receiptModalEl) {
    const receiptModal = new bootstrap.Modal(receiptModalEl);
    receiptModal.show();
  }
<?php endif; ?>

// เริ่มต้นที่ Step 1
goToStep(1);

})();
  </script>
</body>
</html>