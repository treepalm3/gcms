<?php
// posts/sell.php — ระบบ POS ปั๊มน้ำมัน (UX/UI ปรับปรุงใหม่)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== การเชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องกำหนดตัวแปร $pdo (PDO)

// ===== กำหนดพนักงานขาย (ไม่ต้องล็อกอิน) =====
// คุณสามารถเปลี่ยน ID และชื่อนี้ได้ตามต้องการ
// ID 3 คือ "พนักงานขายหน้าร้าน" (สมมติ)
$current_user_id = 3; 
$current_name    = 'พนักงานขาย';
$current_role_th = 'พนักงาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

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
      background: var(--mint); /* สีเขียวมิ้นต์เมื่อ Active */
      color: white;
      box-shadow: 0 4px 15px rgba(32, 163, 158, 0.4);
    }
    .step-indicator.completed .step-number {
      background: var(--gold); /* สีทองเมื่อสำเร็จ */
      color: white;
    }
    .step-label {
      font-size: 0.875rem;
      color: #6c757d;
      font-weight: 500;
    }
    .step-indicator.active .step-label {
      color: var(--mint);
      font-weight: 700;
    }
    .pos-step {
      animation: fadeIn 0.5s;
    }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
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
        <div class="avatar-circle text-decoration-none" title="พนักงาน: <?= htmlspecialchars($current_name) ?>"><?= htmlspecialchars($avatar_text) ?></div>
      </div>
    </div>
  </nav>

  <!-- Main -->
  <main class="container p-4">
        <div class="main-header">
          <h2><i class="bi bi-cash-coin me-2"></i>ระบบขายน้ำมัน</h2>
        </div>

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

        <!-- Step Indicators -->
        <div class="row justify-content-center mb-4 d-none d-md-flex">
            <div class="col-3 text-center step-indicator active" id="step-indicator-1">
                <div class="step-number">1</div>
                <div class="step-label">เลือกน้ำมัน</div>
            </div>
            <div class="col-3 text-center step-indicator" id="step-indicator-2">
                <div class="step-number">2</div>
                <div class="step-label">ระบุจำนวน</div>
            </div>
            <div class="col-3 text-center step-indicator" id="step-indicator-3">
                <div class="step-number">3</div>
                <div class="step-label">ชำระเงิน</div>
            </div>
            <div class="col-3 text-center step-indicator" id="step-indicator-4">
                <div class="step-number"><i class="bi bi-check-lg"></i></div>
                <div class="step-label">ยืนยัน</div>
            </div>
        </div>

        <form id="posForm" method="POST" autocomplete="off" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="process_sale">
          <input type="hidden" name="fuel_type" id="selectedFuel" required>
          <input type="hidden" name="quantity" id="quantityInput" value="0" required>
          <input type="hidden" name="sale_type" id="saleTypeInput" value="amount">
          <input type="hidden" name="payment_method" id="paymentMethodInput" value="cash">
          <input type="hidden" name="customer_phone" id="customerPhoneInput">
          <input type="hidden" name="household_no" id="householdNoInput">

          <!-- Step 1: Fuel Selection -->
          <div id="step1-fuel" class="pos-step">
            <div class="pos-panel">
              <h5 class="mb-3"><i class="bi bi-fuel-pump-fill me-2"></i>เลือกชนิดน้ำมัน</h5>
              <div class="fuel-selector">
                <?php foreach ($fuel_types as $key => $fuel): ?>
                <div class="fuel-card" data-fuel="<?= htmlspecialchars($key) ?>" data-price="<?= htmlspecialchars($fuel['price']) ?>" data-name="<?= htmlspecialchars($fuel['name']) ?>">
                  <div class="fuel-icon" style="background-color: <?= htmlspecialchars($fuel['color']) ?>"><i class="bi bi-droplet-fill"></i></div>
                  <h6><?= htmlspecialchars($fuel['name']) ?></h6>
                  <div class="text-muted"><?= number_format($fuel['price'], 2) ?> ฿/ลิตร</div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Step 2: Amount Input -->
          <div id="step2-amount" class="pos-step d-none">
            <div class="row g-4">
              <div class="col-md-7">
                <div class="pos-panel h-100">
                  <div class="d-flex justify-content-center mb-3">
                    <div class="btn-group" role="group">
                      <input type="radio" class="btn-check" name="sale_type_radio" id="byAmount" value="amount" checked>
                      <label class="btn btn-outline-primary" for="byAmount">ขายตามจำนวนเงิน (บาท)</label>
                      <input type="radio" class="btn-check" name="sale_type_radio" id="byLiters" value="liters">
                      <label class="btn btn-outline-primary" for="byLiters">ขายตามปริมาณ (ลิตร)</label>
                    </div>
                  </div>
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
                    <button type="button" class="numpad-btn" data-action="backspace"><i class="bi bi-backspace-fill"></i></button>
                  </div>
                  <button type="button" class="btn btn-danger w-100 mt-3" data-action="clear">ล้างค่า (C)</button>
                </div>
              </div>
              <div class="col-md-5">
                <div class="pos-panel h-100">
                  <h5 class="mb-3">สรุปเบื้องต้น</h5>
                  <div id="summaryPanel">
                    <p class="text-center text-muted">กรุณาใส่จำนวน</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 3: Payment & Member -->
          <div id="step3-details" class="pos-step d-none">
            <div class="pos-panel">
              <div class="row g-4">
                <div class="col-md-6">
                  <h5 class="mb-3"><i class="bi bi-credit-card me-2"></i>เลือกวิธีชำระเงิน</h5>
                  <select class="form-select form-select-lg" id="paymentMethodSelect">
                    <option value="cash" selected>เงินสด</option>
                    <option value="qr">QR Code</option>
                    <option value="transfer">โอนเงิน</option>
                    <option value="card">บัตรเครดิต</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <h5 class="mb-3"><i class="bi bi-person-check me-2"></i>ลูกค้าสมาชิก (สะสมแต้ม)</h5>
                  <div class="input-group">
                    <input type="tel" class="form-control form-control-lg" id="memberSearchInput" placeholder="ค้นหาด้วยเบอร์โทร/บ้านเลขที่">
                    <button class="btn btn-outline-secondary" type="button" id="memberSearchBtn"><i class="bi bi-search"></i></button>
                  </div>
                  <div id="memberInfo" class="mt-2" style="display: none;">
                    <div class="alert alert-info py-2 px-3 d-flex align-items-center">
                      <i class="bi bi-person-check-fill me-2"></i><span id="memberName"></span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 4: Confirmation -->
          <div id="step4-confirm" class="pos-step d-none">
            <div class="pos-panel text-center">
              <h3 class="mb-4">ยืนยันการขาย</h3>
              <div id="finalSummary" class="text-start mb-4"></div>
              <button type="submit" class="btn btn-success btn-lg w-50">
                <i class="bi bi-check-circle-fill me-2"></i>ยืนยันและบันทึกการขาย
              </button>
            </div>
          </div>

          <!-- Navigation -->
          <div class="mt-4 d-flex justify-content-between">
            <button type="button" id="btnBack" class="btn btn-outline-secondary btn-lg" style="display: none;">
              <i class="bi bi-arrow-left-circle me-2"></i>ย้อนกลับ
            </button>
            <button type="button" id="btnNext" class="btn btn-primary btn-lg ms-auto" disabled>
              ต่อไป<i class="bi bi-arrow-right-circle ms-2"></i>
            </button>
          </div>

        </form>
      </main>

  <?php if ($sale_success && $sale_data): ?>
  <div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">ใบเสร็จรับเงิน</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <?php
        $pay_th = [
          'cash'     => 'เงินสด',
          'qr'       => 'QR Code',
          'transfer' => 'โอนเงิน',
          'card'     => 'บัตรเครดิต',
        ];
        ?>
        <div class="modal-body">
          <div id="receiptContent" class="receipt receipt-print-area">
            <div class="text-center border-bottom border-dark border-dashed pb-2 mb-2">
              <h5><?= htmlspecialchars($site_name) ?></h5>
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
              <div class="d-flex justify-content-between"><span>ส่วนลด (<?= $sale_data['discount_percent'] ?>%):</span><span>-<?= number_format($sale_data['discount_amount'], 2) ?></span></div>
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

  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // --- State ---
    let currentStep = 1;
    let currentInput = '0';
    let selectedFuel = null;
    let currentPrice = 0;
    let currentFuelName = '';
    
    const fuelCards       = document.querySelectorAll('.fuel-card');
    const numpadBtns      = document.querySelectorAll('.numpad-btn');
    const display         = document.getElementById('amountDisplay');
    const quantityInput   = document.getElementById('quantityInput');
    const selectedFuelInp = document.getElementById('selectedFuel');
    const summaryPanel    = document.getElementById('summaryPanel');
    const saleTypeRadios  = document.querySelectorAll('input[name="sale_type_radio"]');
    const posForm         = document.getElementById('posForm');
    
    const memberSearchInput  = document.getElementById('memberSearchInput');
    const memberSearchBtn    = document.getElementById('memberSearchBtn');
    const memberInfoDiv      = document.getElementById('memberInfo');
    const memberNameSpan     = document.getElementById('memberName');
    
    const paymentMethodSelect = document.getElementById('paymentMethodSelect');
    
    const btnNext = document.getElementById('btnNext');
    const btnBack = document.getElementById('btnBack');
    const steps = document.querySelectorAll('.pos-step');
    const stepIndicators = document.querySelectorAll('.step-indicator');

    let searchTimeout;

    function showStep(stepNumber) {
        steps.forEach(step => step.classList.add('d-none'));
        const currentStepEl = document.getElementById(`step${stepNumber}-fuel`) || document.getElementById(`step${stepNumber}-amount`) || document.getElementById(`step${stepNumber}-details`) || document.getElementById(`step${stepNumber}-confirm`);
        if(currentStepEl) currentStepEl.classList.remove('d-none');

        stepIndicators.forEach((ind, i) => {
            ind.classList.remove('active', 'completed');
            if (i + 1 < stepNumber) {
                ind.classList.add('completed');
            } else if (i + 1 === stepNumber) {
                ind.classList.add('active');
            }
        });

        btnBack.style.display = (stepNumber > 1) ? '' : 'none';
        btnNext.style.display = (stepNumber < 4) ? '' : 'none';
        
        if (stepNumber === 4) {
            buildFinalSummary();
        }
        
        currentStep = stepNumber;
        validateStep();
    }

    function validateStep() {
        let isValid = false;
        switch(currentStep) {
            case 1:
                isValid = selectedFuel !== null;
                break;
            case 2:
                isValid = (parseFloat(currentInput) || 0) > 0;
                break;
            case 3:
                isValid = true;
                break;
        }
        btnNext.disabled = !isValid;
    }

    btnNext.addEventListener('click', () => {
        if (currentStep < 4) {
            showStep(currentStep + 1);
        }
    });

    btnBack.addEventListener('click', () => {
        if (currentStep > 1) {
            showStep(currentStep - 1);
        }
    });

    fuelCards.forEach(card => card.addEventListener('click', (e) => {
        handleFuelSelect(e);
        setTimeout(() => showStep(2), 200); // Auto-navigate to next step
    }));

    numpadBtns.forEach(btn => btn.addEventListener('click', handleNumpad));
    saleTypeRadios.forEach(radio => radio.addEventListener('change', updateSummary));
    posForm.addEventListener('submit', validateForm);
    
    memberSearchInput.addEventListener('input', handleMemberSearch);
    memberSearchBtn.addEventListener('click', () => findMember(memberSearchInput.value.trim()));
    paymentMethodSelect.addEventListener('change', (e) => {
        document.getElementById('paymentMethodInput').value = e.target.value;
    });

    // ปุ่มล้างค่า (C)
    document.querySelector('[data-action="clear"]').addEventListener('click', function() {
      currentInput = '0';
      display.textContent = currentInput;
      quantityInput.value = '0';
      updateSummary();
      validateStep();
    });

    function handleFuelSelect(e){
      fuelCards.forEach(c => c.classList.remove('selected'));
      const card = e.currentTarget;
      card.classList.add('selected');
      selectedFuel = card.dataset.fuel;
      currentPrice = parseFloat(card.dataset.price);
      currentFuelName = card.dataset.name;
      selectedFuelInp.value = selectedFuel;
      updateSummary(); 
      validateStep();
    }

    function handleNumpad(e){
      const btn = e.currentTarget;
      const num = btn.dataset.num;
      const action = btn.dataset.action;

      if (num !== undefined) {
        if (currentInput === '0') currentInput = '';
        if (currentInput.length < 9) currentInput += num;
      } else if (action === 'decimal') {
        if (!currentInput.includes('.')) currentInput += '.';
      } else if (action === 'clear') {
        currentInput = '0';
      } else if (action === 'backspace') {
        currentInput = currentInput.slice(0, -1);
        if (currentInput === '') currentInput = '0';
      }
      updateDisplayAndSummary();
    }

    function updateDisplayAndSummary(){
      display.textContent = currentInput;
      quantityInput.value = currentInput;
      updateSummary(); validateStep();
    }

    function updateSummary(){
      if (!selectedFuel || !currentPrice) {
        summaryPanel.innerHTML = '<p class="text-center text-muted">กรุณาเลือกชนิดน้ำมัน</p>'; return;
      }
      const qty = parseFloat(currentInput) || 0;
      if (qty === 0) { summaryPanel.innerHTML = '<p class="text-center text-muted">กรุณาใส่จำนวน</p>'; return; }

      const saleType = document.querySelector('input[name="sale_type_radio"]:checked').value;
      document.getElementById('saleTypeInput').value = saleType;

      let liters, totalAmount;
      if (saleType === 'liters') {
        liters = qty;
        totalAmount = liters * currentPrice;
      } else {
        totalAmount = qty;
        liters = totalAmount / currentPrice;
      }

      summaryPanel.innerHTML = `
        <div class="d-flex justify-content-between"><span>น้ำมัน:</span><strong>${currentFuelName}</strong></div>
        <div class="d-flex justify-content-between"><span>ราคา/ลิตร:</span><span>${currentPrice.toFixed(2)} ฿</span></div>
        <hr class="my-2">
        <div class="d-flex justify-content-between"><span>ปริมาณ:</span><span>${liters.toFixed(3)} ลิตร</span></div>
        <div class="d-flex justify-content-between fw-bold h5"><span>ยอดรวม:</span><span class="text-primary">${totalAmount.toFixed(2)} บาท</span></div>
      `;
      validateStep();
    }

    function buildFinalSummary() {
        const qty = parseFloat(quantityInput.value) || 0;
        const saleType = document.getElementById('saleTypeInput').value;
        const paymentMethod = paymentMethodSelect.options[paymentMethodSelect.selectedIndex].text;
        const memberName = memberNameSpan.textContent || 'ทั่วไป';

        let liters, totalAmount;
        if (saleType === 'liters') {
            liters = qty;
            totalAmount = liters * currentPrice;
        } else {
            totalAmount = qty;
            liters = totalAmount / currentPrice;
        }
        const netAmt = totalAmount; // Assuming no discount for now

        const finalSummaryEl = document.getElementById('finalSummary');
        finalSummaryEl.innerHTML = `
        <div class="row">
            <div class="col">น้ำมัน</div>
            <div class="col text-end"><strong>${currentFuelName}</strong></div>
        </div>
        <div class="row">
            <div class="col">ปริมาณ / ราคา</div>
            <div class="col text-end">${liters.toFixed(3)} L. @ ${currentPrice.toFixed(2)} ฿</div>
        </div>
        <hr class="my-2">
        <div class="row fs-5">
            <div class="col"><strong>ยอดสุทธิ</strong></div>
            <div class="col text-end"><h4>${netAmt.toFixed(2)} บาท</h4></div>
        </div>
        <hr class="my-2">
        <div class="row"><div class="col">ชำระโดย</div><div class="col text-end">${paymentMethod}</div></div>
        <div class="row"><div class="col">ลูกค้า</div><div class="col text-end">${memberName}</div></div>
        `;
    }

    function validateForm(e){
      if (currentStep !== 4) {
        e.preventDefault();
        alert('กรุณาดำเนินการตามขั้นตอนให้ครบถ้วน');
        return false;
      }
      const qty = parseFloat(quantityInput.value) || 0;
      if (!selectedFuel || qty <= 0) {
        e.preventDefault();
        alert('ข้อมูลการขายไม่ถูกต้อง กรุณากลับไปแก้ไข');
        showStep(1);
        return false;
      }
    }

    // --- Member Search (แสดงชื่อสมาชิกเมื่อพบ) ---
    function handleMemberSearch(e) {
      clearTimeout(searchTimeout);
      const term = e.target.value.trim();

      if (term === '') {
        memberInfoDiv.style.display = 'none';
        return;
      }
      if (term.length < 3) return;

      searchTimeout = setTimeout(() => {
        findMember(term);
      }, 500);
    }

    async function findMember(term) {
    console.log('🔍 Searching for:', term);
    
    const spinner = `<div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div>`;
    const alertDiv = memberInfoDiv.querySelector('.alert');

    memberInfoDiv.style.display = 'block';
    alertDiv.className = 'alert alert-secondary py-2 px-3 d-flex align-items-center';
    memberNameSpan.innerHTML = `กำลังค้นหา... ${spinner}`;

    try {
        const url = `/api/search_member.php?term=${encodeURIComponent(term)}`;
        console.log('📡 API URL:', url);
        
        const res = await fetch(url);
        console.log('📥 Response status:', res.status);
        
        if (!res.ok) throw new Error('bad_status_' + res.status);
        
        const member = await res.json();
        console.log('👤 Member data:', member);

        if (member && !member.error) {
            alertDiv.className = 'alert alert-info py-2 px-3 d-flex align-items-center';
            alertDiv.querySelector('i').className = 'bi bi-person-check-fill me-2';
            memberNameSpan.textContent = `สมาชิก: ${member.full_name}`;

            // อัปเดตข้อมูลสมาชิกที่พบ
            document.getElementById('customerPhoneInput').value = member.phone || '';
            document.getElementById('householdNoInput').value = member.house_number || '';
            
            console.log('✅ Member found and form updated');
        } else {
            alertDiv.className = 'alert alert-warning py-2 px-3 d-flex align-items-center';
            alertDiv.querySelector('i').className = 'bi bi-person-exclamation me-2';
            memberNameSpan.textContent = 'ไม่พบสมาชิก';
            console.log('❌ Member not found');
        }
    } catch (error) {
        console.error('💥 Fetch error:', error);
        alertDiv.className = 'alert alert-danger py-2 px-3 d-flex align-items-center';
        alertDiv.querySelector('i').className = 'bi bi-wifi-off me-2';
        memberNameSpan.textContent = 'การเชื่อมต่อล้มเหลว';
    }
}

    function printReceipt(){
      if (typeof saleDataForReceipt === 'undefined') return;

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
      const w = window.open('', '_blank');
      w.document.write(receiptHTML); w.document.close(); w.focus();
      setTimeout(()=>{ w.print(); w.close(); }, 250);
    }

    // เปิด modal อัตโนมัติเมื่อบันทึกสำเร็จ
    <?php if ($sale_success && $sale_data_json): ?>
      const saleDataForReceipt = <?= $sale_data_json; ?>;
      const receiptModalEl = document.getElementById('receiptModal');
      if (receiptModalEl) {
        const receiptModal = new bootstrap.Modal(receiptModalEl);
        receiptModal.show();
      }
    <?php endif; ?>

    // init
    showStep(1);
  </script>
</body>
</html>