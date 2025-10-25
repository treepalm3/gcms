<?php
// employee/sell.php — ระบบ POS ปั๊มน้ำมัน (สคีมา: sales + sales_items + fuel_* )
// ข้อสำคัญ: ต้องมีไฟล์ /api/search_member.php สำหรับค้นสมาชิก (ดูตัวอย่างที่ส่งให้ก่อนหน้า)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== บังคับล็อกอิน =====
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_name    = $_SESSION['full_name'] ?? 'พนักงาน';
$current_role    = $_SESSION['role'] ?? 'employee';
if ($current_user_id === 0 || $current_role !== 'employee') {
  header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
  exit();
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== การเชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องกำหนดตัวแปร $pdo (PDO)

// ===== Helpers =====
function get_setting(PDO $pdo, string $name, $default = null) {
  try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name = :n LIMIT 1");
    $stmt->execute([':n' => $name]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : $default;
  } catch (Throwable $e) {
    return $default;
  }
}

function has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table.'.'.$column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':t'=>$table, ':c'=>$column]);
    $cache[$key] = (bool)$st->fetchColumn();
  } catch (Throwable $e) { $cache[$key] = false; }
  return $cache[$key];
}

/* ================== ค่าพื้นฐานและผู้ใช้ ================== */
$site_name     = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',  'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

$station_id = get_setting($pdo, 'station_id', 1);

/* ================== ดึงข้อมูลน้ำมันและราคา ================== */
$fuel_types = [];
$fuel_colors_by_name = [
  'ดีเซล'         => '#CCA43B',
  'แก๊สโซฮอล์ 95' => '#20A39E',
  'แก๊สโซฮอล์ 91' => '#B66D0D',
];

try {
  try {
    $stmt_fuel = $pdo->prepare("
      SELECT fuel_id, fuel_name, price
      FROM fuel_prices
      WHERE station_id = :sid
      ORDER BY display_order ASC, fuel_id ASC
    ");
    $stmt_fuel->execute([':sid' => $station_id]);
  } catch (Throwable $e) {
    // fallback กรณีไม่มีคอลัมน์ display_order
    $stmt_fuel = $pdo->prepare("
      SELECT fuel_id, fuel_name, price
      FROM fuel_prices
      WHERE station_id = :sid
      ORDER BY fuel_id ASC
    ");
    $stmt_fuel->execute([':sid' => $station_id]);
  }
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

    // [แก้ไข] รับค่าจากช่องค้นหารวม
    $member_identifier = trim((string)($_POST['member_identifier'] ?? ''));
    $customer_phone = '';
    $household_no   = '';

    // ตรวจสอบว่าสิ่งที่กรอกมาเป็นเบอร์โทร (มีแต่ตัวเลข, -) หรือ บ้านเลขที่ (อาจมีตัวอักษร, /)
    if (preg_match('/^[0-9\s\-()+]+$/', $member_identifier)) {
        // ถ้าเหมือนเบอร์โทร
        $customer_phone = preg_replace('/\D+/', '', $member_identifier);
    } else {
        // ถ้าไม่เหมือนเบอร์โทร ให้ถือเป็นบ้านเลขที่
        $household_no = $member_identifier;
    }

    $discount_in    = $_POST['discount'] ?? 0;
    $discount       = is_numeric($discount_in) ? (float)$discount_in : 0.0;
    $discount       = max(0.0, min(100.0, $discount));

    $allowed_payments  = ['cash', 'qr', 'transfer', 'card'];
    $allowed_sale_type = ['liters','amount'];

    // ===== ตรวจสอบ =====
    if (!array_key_exists($fuel_id, $fuel_types)) {
      $sale_error = 'กรุณาเลือกชนิดน้ำมันให้ถูกต้อง';
    } elseif ($quantity === false || $quantity <= 0) {
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
      $discount_amount = round($total_amount * ($discount/100.0), 2);
      $net_amount      = round($total_amount - $discount_amount, 2);

      // [แก้ไข] แต้มสะสม: ปรับเป็น 30 บาท = 1 แต้ม
      $POINT_RATE     = 30; // <-- ปรับจาก 20 เป็น 30
      $has_loyalty_id = (bool)($customer_phone || $household_no);
      $points_earned  = $has_loyalty_id ? (int)floor($net_amount / $POINT_RATE) : 0;

      $now = date('Y-m-d H:i:s');

      // ===== เตรียมข้อมูลใบเสร็จสำหรับแสดงผล =====
      $sale_data = [
        'site_name'        => $site_name,
        'receipt_no'       => '', // จะถูกกำหนดตอน insert sales สำเร็จ
        'datetime'         => $now,
        'fuel_type'        => $fuel_id,
        'fuel_name'        => $fuel_name,
        'price_per_liter'  => $fuel_price,
        'liters'           => $liters_disp,
        'total_amount'     => $total_amount,
        'discount_percent' => $discount,
        'discount_amount'  => $discount_amount,
        'net_amount'       => $net_amount,
        'payment_method'   => $payment_method,
        'customer_phone'   => $customer_phone,
        'household_no'     => $household_no,
        'points_earned'    => $points_earned,
        'employee_id'      => $current_user_id,
        'employee_name'    => $current_name
      ];

      // ====== บันทึกลงฐานข้อมูล ======
      try {
        $pdo->beginTransaction();

        $col_phone   = has_column($pdo, 'sales', 'customer_phone');
        $col_house   = has_column($pdo, 'sales', 'household_no');
        $col_discpct = has_column($pdo, 'sales', 'discount_pct');
        $col_discamt = has_column($pdo, 'sales', 'discount_amount');

        $tries = 0;
        $sale_id = null;
        do {
          $receipt_no = 'R'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
          $cols = ['station_id','sale_code','total_amount','net_amount','sale_date','payment_method','created_by'];
          $params = [
            ':station_id'     => $station_id,
            ':sale_code'      => $receipt_no,
            ':total_amount'   => $total_amount,
            ':net_amount'     => $net_amount,
            ':sale_date'      => $now,
            ':payment_method' => $payment_method,
            ':created_by'     => $current_user_id,
          ];
          // [แก้ไข] ใช้ตัวแปร $customer_phone และ $household_no ที่เรากรองไว้
          if ($col_phone)   { $cols[] = 'customer_phone';  $params[':customer_phone']  = $customer_phone ?: null; }
          if ($col_house)   { $cols[] = 'household_no';    $params[':household_no']    = $household_no ?: null; }
          if ($col_discpct) { $cols[] = 'discount_pct';    $params[':discount_pct']    = $discount; }
          if ($col_discamt) { $cols[] = 'discount_amount'; $params[':discount_amount'] = $discount_amount; }

          $placeholders = array_map(fn($c) => ':'.$c, $cols);
          foreach ($cols as $c) { $k = ':'.$c; if (!array_key_exists($k, $params)) { $params[$k] = null; } }

          $sql = "INSERT INTO sales (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
          try {
            $stmtSale = $pdo->prepare($sql);
            $stmtSale->execute($params);
            $sale_id = (int)$pdo->lastInsertId();
            $sale_data['receipt_no'] = $receipt_no;
            break;
          } catch (PDOException $ex) {
            if ($ex->getCode() === '23000' && ++$tries <= 5) { continue; }
            throw $ex;
          }
        } while ($tries <= 5);

        if (!$sale_id) { throw new RuntimeException('ไม่สามารถสร้างเลขที่ใบเสร็จได้'); }

        // --- หา tank ---
        $tank_id = null;
        try {
          $findTank = $pdo->prepare("
            SELECT id FROM fuel_tanks
            WHERE station_id = :sid AND fuel_id = :fid AND is_active = 1 AND current_volume_l >= :liters
            ORDER BY current_volume_l DESC LIMIT 1
          ");
          $findTank->execute([':sid'=>$station_id, ':fid'=>(int)$fuel_id, ':liters' => $liters_db]);
          $tank_id = $findTank->fetchColumn() ?: null;
        } catch (Throwable $e) { $tank_id = null; }

        // 2) รายการ -> sales_items
        $stmtItem = $pdo->prepare("
          INSERT INTO sales_items (sale_id, fuel_id, tank_id, fuel_type, liters, price_per_liter)
          VALUES (:sale_id, :fuel_id, :tank_id, :fuel_type, :liters, :price_per_liter)
        ");
        $stmtItem->execute([
          ':sale_id'         => $sale_id,
          ':fuel_id'         => (int)$fuel_id,
          ':tank_id'         => $tank_id,
          ':fuel_type'       => $fuel_name,
          ':liters'          => $liters_db,
          ':price_per_liter' => round($fuel_price, 2)
        ]);

        // 3) ตัดสต็อก + บันทึก movement
        try {
          if ($tank_id) {
            $sel = $pdo->prepare("SELECT id, current_volume_l FROM fuel_tanks WHERE id = :tid FOR UPDATE");
            $sel->execute([':tid' => (int)$tank_id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);

            if ($row) {
              $lit2 = $liters_db;
              $stmtUpd = $pdo->prepare("
                  UPDATE fuel_tanks SET current_volume_l = current_volume_l - ? WHERE id = ? AND current_volume_l >= ?
              ");
              $stmtUpd->execute([$lit2, $tank_id, $lit2]);

              if ($stmtUpd->rowCount() > 0) {
                // ลง movement
                $stmtMove = $pdo->prepare("
                  INSERT INTO fuel_moves (occurred_at, type, tank_id, liters, unit_price, ref_doc, ref_note, user_id, sale_id)
                  VALUES (NOW(), 'sale_out', :tank_id, :liters, :unit_price, :ref_doc, :ref_note, :user_id, :sale_id)
                ");
                $stmtMove->execute([
                  ':tank_id'    => (int)$tank_id,
                  ':liters'     => $lit2,
                  ':unit_price' => round($fuel_price, 2),
                  ':ref_doc'    => $sale_data['receipt_no'],
                  ':ref_note'   => 'POS sale',
                  ':user_id'    => $current_user_id,
                  ':sale_id'    => $sale_id
                ]);
                $move_id = (int)$pdo->lastInsertId();

                // 3.1) จัดสรร COGS
                if ($move_id > 0) {
                    $liters_to_allocate = $liters_db;
                    $getLots = $pdo->prepare("
                        SELECT id, remaining_liters, unit_cost_full FROM v_open_fuel_lots
                        WHERE tank_id = :tid ORDER BY received_at ASC, id ASC
                    ");
                    $getLots->execute([':tid' => (int)$tank_id]);
                    $insAlloc = $pdo->prepare("
                        INSERT INTO fuel_lot_allocations (lot_id, move_id, allocated_liters, unit_cost_snapshot)
                        VALUES (:lot_id, :move_id, :liters, :cost)
                    ");
                    while ($liters_to_allocate > 1e-6 && ($lot = $getLots->fetch(PDO::FETCH_ASSOC))) {
                        $lot_id = (int)$lot['id'];
                        $available_in_lot = (float)$lot['remaining_liters'];
                        $cost_snapshot = (float)$lot['unit_cost_full'];
                        $take_from_lot = min($liters_to_allocate, $available_in_lot);
                        if ($take_from_lot > 0) {
                            $insAlloc->execute([':lot_id' => $lot_id, ':move_id' => $move_id, ':liters' => $take_from_lot, ':cost' => $cost_snapshot]);
                            $liters_to_allocate -= $take_from_lot;
                        }
                    }
                    if ($liters_to_allocate > 1e-6) {
                        throw new RuntimeException("COGS Error: สต็อกใน Lot ไม่พอสำหรับ Tank ID {$tank_id} (ขาดไป {$liters_to_allocate} ลิตร)");
                    }
                }

                // sync ตาราง fuel_stock
                $sync = $pdo->prepare("
                  UPDATE fuel_stock SET current_stock = GREATEST(0, current_stock - :l)
                  WHERE station_id = :sid AND fuel_id = :fid
                ");
                $sync->execute([':l' => $liters_db, ':sid' => $station_id, ':fid' => (int)$fuel_id]);
              } else {
                error_log('Inventory not enough for tank '.$tank_id.' sale '.$sale_id);
              }
            } else {
              error_log("Tank not found (FOR UPDATE) id={$tank_id}");
            }
          } else {
            error_log("No active tank for station {$station_id} and fuel {$fuel_id} with enough stock ({$liters_db}L) for sale {$sale_id}");
          }
        } catch (Throwable $invE) {
          error_log("Inventory update skipped: ".$invE->getMessage());
        }

        // 4) สะสมแต้ม -> scores (ค้นหาจากเบอร์หรือบ้านเลขที่)
        if ($points_earned > 0 && ($customer_phone !== '' || $household_no !== '')) {
          try {
            $member_id = null;
            $where_conditions = [];
            $params_pts = [];
            
            if ($customer_phone !== '') {
              $where_conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(u.phone, '-', ''), ' ', ''), '(', ''), ')', '') = :phone";
              $params_pts[':phone'] = $customer_phone;
            }
            if ($household_no !== '') {
              // [แก้ไข] แก้ไขการ Join ให้รองรับ 3 ตาราง
              $where_conditions[] = "m.house_number = :house";
              $where_conditions[] = "mg.house_number = :house";
              $where_conditions[] = "c.house_number = :house";
              $params_pts[':house'] = $household_no;
            }
            
            if (!empty($where_conditions)) {
              $where_clause = implode(' OR ', $where_conditions);
              
              // [แก้ไข] Query ให้ค้นหาจาก 3 ตาราง
              $q = $pdo->prepare("
                SELECT u.id as user_id, m.id as member_pk
                FROM users u
                LEFT JOIN members m ON m.user_id = u.id AND m.is_active = 1
                LEFT JOIN managers mg ON mg.user_id = u.id
                LEFT JOIN committees c ON c.user_id = u.id
                WHERE ({$where_clause})
                  AND COALESCE(m.id, mg.id, c.id) IS NOT NULL
                LIMIT 1
              ");
              
              $q->execute($params_pts);
              $found_user = $q->fetch(PDO::FETCH_ASSOC);
              
              // [แก้ไข] ต้องใช้ member_id (pk) จากตาราง members เท่านั้นสำหรับตาราง scores
              if ($found_user && $found_user['member_pk']) {
                 $member_id = $found_user['member_pk'];
              } else if ($found_user) {
                 error_log("Member found (User ID: {$found_user['user_id']}) but is not in 'members' table. Cannot add points.");
              } else {
                 error_log("Member not found - Phone: {$customer_phone}, House: {$household_no}");
              }
            }
            
            // ถ้าพบสมาชิก (ที่เป็น members) ให้บันทึกแต้ม
            if ($member_id) {
              $insScore = $pdo->prepare("
                INSERT INTO scores (member_id, score, activity, score_date)
                VALUES (:member_id, :score, :activity, NOW())
              ");
              $insScore->execute([
                ':member_id' => (int)$member_id,
                ':score'     => (int)$points_earned,
                ':activity'  => 'POS '.$sale_data['receipt_no']
              ]);
              error_log("Points earned: {$points_earned} for member_id: {$member_id}");
            }
          } catch (Throwable $ptsE) {
            error_log("Point earn error: ".$ptsE->getMessage());
          }
        }

        $pdo->commit();

        $sale_success   = true;
        $sale_data_json = json_encode(
          $sale_data,
          JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );

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
    .fuel-selector{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem}
    .fuel-card{border:2px solid var(--border);border-radius:var(--radius);padding:1rem;cursor:pointer;transition:.2s;text-align:center}
    .fuel-card:hover{border-color:var(--primary);transform:translateY(-3px)}
    .fuel-card.selected{border-color:var(--primary);background-color:var(--primary-light);box-shadow:0 4px 15px rgba(32,163,158,.25)}
    .fuel-icon{width:50px;height:50px;border-radius:50%;margin:0 auto .5rem;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff}
    .pos-panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.5rem}
    .amount-display{background:var(--dark);color:#20e8a0;font-family:"Courier New",monospace;border-radius:var(--radius);padding:1rem;text-align:right;font-size:2.25rem;font-weight:700;margin-bottom:1rem;min-height:70px}
    .numpad-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem}
    .numpad-btn{aspect-ratio:1.2/1;border:1px solid var(--border);background:var(--surface);border-radius:var(--radius);font-size:1.5rem;font-weight:600;cursor:pointer;transition:.15s}
    .numpad-btn:hover{background:var(--primary);color:#fff}
    .receipt{font-family:'Courier New',monospace}
    @media print{body *{visibility:hidden}.receipt-print-area,.receipt-print-area *{visibility:visible}.receipt-print-area{position:absolute;left:0;top:0;width:100%}}
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

  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Employee</span></h3></div>
      <nav class="sidebar-menu">
        <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i>แดชบอร์ด</a>
        <a class="active"  href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
        <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
        <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="bi bi-box-arrow-right"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Employee</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i>แดชบอร์ด</a>
          <a class="active" href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
          <a href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
          <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i>ออกจากระบบ</a>
      </aside>

      <main class="col-lg-10 p-4">
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

        <form id="posForm" method="POST" autocomplete="off" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="process_sale">
          <input type="hidden" name="fuel_type" id="selectedFuel" required>
          <input type="hidden" name="quantity" id="quantityInput" value="0" required>
          <input type="hidden" name="customer_phone" id="customerPhoneHidden">
          <input type="hidden" name="household_no" id="householdNoHidden">

          <div class="row g-4">
            <div class="col-lg-7">
              <div class="pos-panel">
                <h5 class="mb-3"><i class="bi bi-fuel-pump-fill me-2"></i>1. เลือกชนิดน้ำมัน</h5>
                <div class="fuel-selector mb-4">
                  <?php foreach ($fuel_types as $key => $fuel): ?>
                  <div class="fuel-card" data-fuel="<?= htmlspecialchars($key) ?>" data-price="<?= htmlspecialchars($fuel['price']) ?>">
                    <div class="fuel-icon" style="background-color: <?= htmlspecialchars($fuel['color']) ?>"><i class="bi bi-droplet-fill"></i></div>
                    <h6><?= htmlspecialchars($fuel['name']) ?></h6>
                    <div class="text-muted"><?= number_format($fuel['price'], 2) ?> ฿/ลิตร</div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <hr>
                <h5 class="mb-3"><i class="bi bi-gear-fill me-2"></i>2. ระบุข้อมูลการขาย</h5>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">วิธีการชำระเงิน</label>
                    <select class="form-select" name="payment_method" required>
                      <option value="cash">เงินสด</option>
                      <option value="qr">QR Code</option>
                      <option value="transfer">โอนเงิน</option>
                      <option value="card">บัตรเครดิต</option>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">ค้นหาสมาชิก (บ้านเลขที่ / เบอร์โทร)</label>
                    <input type="text" class="form-control" id="memberIdentifierInput" placeholder="กรอกบ้านเลขที่ หรือ เบอร์โทร">
                    <div class="form-text">สำหรับสะสมแต้มและเฉลี่ยคืน</div>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">ส่วนลด (%)</label>
                    <input type="number" class="form-control" name="discount" id="discountInput" value="0" min="0" max="100" step="0.1">
                  </div>
                  
                  <div class="col-12">
                    <div id="memberInfo" class="mt-2" style="display: none;">
                      <div class="alert alert-info py-2 px-3 d-flex align-items-center">
                        <i class="bi bi-person-check-fill me-2"></i><span id="memberName"></span>
                      </div>
                    </div>
                  </div>

                </div>
              </div>
            </div>

            <div class="col-lg-5">
              <div class="pos-panel sticky-top" style="top: 20px;">
                <div class="d-flex justify-content-center mb-3">
                  <div class="btn-group" role="group">
                    <input type="radio" class="btn-check" name="sale_type" id="byAmount" value="amount" checked>
                    <label class="btn btn-outline-primary" for="byAmount">ขายตามจำนวนเงิน (บาท)</label>
                    <input type="radio" class="btn-check" name="sale_type" id="byLiters" value="liters">
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
                <hr>

                <div id="summaryPanel" class="mb-3">
                  <p class="text-center text-muted">กรุณาเลือกชนิดน้ำมันและใส่จำนวน</p>
                </div>

                <div class="d-grid gap-2">
                  <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" disabled>
                    <i class="bi bi-check-circle-fill me-2"></i>บันทึกการขาย
                  </button>
                  <button type="button" class="btn btn-outline-secondary" onclick="window.location.href = 'list_sell.php';">
                    <i class="fa-solid fa-list-ul"></i> รายการขาย
                  </button>
                </div>

              </div>
            </div>
          </div>
        </form>
      </main>
    </div>
  </div>

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
    let currentInput = '0';
    let selectedFuel = null;
    let currentPrice = 0;

    // --- DOM ---
    const fuelCards       = document.querySelectorAll('.fuel-card');
    const numpadBtns      = document.querySelectorAll('.numpad-btn');
    const display         = document.getElementById('amountDisplay');
    const quantityInput   = document.getElementById('quantityInput');
    const selectedFuelInp = document.getElementById('selectedFuel');
    const summaryPanel    = document.getElementById('summaryPanel');
    const discountInput   = document.getElementById('discountInput');
    const saleTypeRadios  = document.querySelectorAll('input[name="sale_type"]');
    const submitBtn       = document.getElementById('submitBtn');
    const posForm         = document.getElementById('posForm');

    // --- [แก้ไข] Member Search ---
    const memberIdentifierInput = document.getElementById('memberIdentifierInput'); // ช่องค้นหาใหม่
    const customerPhoneHidden = document.getElementById('customerPhoneHidden'); // ช่องซ่อนสำหรับส่งเบอร์โทร
    const householdNoHidden   = document.getElementById('householdNoHidden');   // ช่องซ่อนสำหรับส่งบ้านเลขที่
    const memberInfoDiv       = document.getElementById('memberInfo');
    const memberNameSpan      = document.getElementById('memberName');
    let searchTimeout;

    fuelCards.forEach(card => card.addEventListener('click', handleFuelSelect));
    numpadBtns.forEach(btn => btn.addEventListener('click', handleNumpad));
    discountInput.addEventListener('input', updateSummary);
    saleTypeRadios.forEach(radio => radio.addEventListener('change', updateSummary));
    posForm.addEventListener('submit', validateForm);
    
    // [แก้ไข] เปลี่ยน Event Listener
    memberIdentifierInput.addEventListener('input', handleMemberSearch);

    document.querySelector('[data-action="clear"]').addEventListener('click', function() {
      currentInput = '0';
      selectedFuel = null;
      display.textContent = currentInput;
      quantityInput.value = '0';
      summaryPanel.innerHTML = '<p class="text-center text-muted">กรุณาเลือกชนิดน้ำมันและใส่จำนวน</p>';
      submitBtn.disabled = true;
      // [เพิ่ม] ล้างค่าสมาชิกด้วย
      memberIdentifierInput.value = '';
      customerPhoneHidden.value = '';
      householdNoHidden.value = '';
      memberInfoDiv.style.display = 'none';
    });

    function handleFuelSelect(e){
      fuelCards.forEach(c => c.classList.remove('selected'));
      const card = e.currentTarget;
      card.classList.add('selected');
      selectedFuel = card.dataset.fuel;
      currentPrice = parseFloat(card.dataset.price);
      selectedFuelInp.value = selectedFuel;
      updateSummary(); validateState();
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
      updateSummary(); validateState();
    }

    function updateSummary(){
      if (!selectedFuel || !currentPrice) {
        summaryPanel.innerHTML = '<p class="text-center text-muted">กรุณาเลือกชนิดน้ำมัน</p>'; return;
      }
      const qty = parseFloat(currentInput) || 0;
      if (qty === 0) { summaryPanel.innerHTML = '<p class="text-center text-muted">กรุณาใส่จำนวน</p>'; return; }

      const saleType = document.querySelector('input[name="sale_type"]:checked').value;
      const discPct  = parseFloat(discountInput.value) || 0;
      const fuelName = document.querySelector(`.fuel-card[data-fuel="${selectedFuel}"] h6`).textContent;

      let liters, totalAmount;
      if (saleType === 'liters') {
        liters = qty;
        totalAmount = liters * currentPrice;
      } else {
        totalAmount = qty;
        liters = totalAmount / currentPrice;
      }
      const discAmt = totalAmount * (discPct/100);
      const netAmt  = totalAmount - discAmt;

      summaryPanel.innerHTML = `
        <div class="d-flex justify-content-between"><span>น้ำมัน:</span><strong>${fuelName}</strong></div>
        <div class="d-flex justify-content-between"><span>ราคา/ลิตร:</span><span>${currentPrice.toFixed(2)} ฿</span></div>
        <hr class="my-2">
        <div class="d-flex justify-content-between"><span>ปริมาณ:</span><span>${liters.toFixed(3)} ลิตร</span></div>
        <div class="d-flex justify-content-between"><span>ยอดรวม:</span><span>${totalAmount.toFixed(2)} ฿</span></div>
        ${discPct>0?`<div class="d-flex justify-content-between text-danger"><span>ส่วนลด (${discPct}%):</span><span>-${discAmt.toFixed(2)} ฿</span></div>`:''}
        <hr class="my-2">
        <div class="d-flex justify-content-between fw-bold h4"><span>ยอดสุทธิ:</span><span class="text-primary">${netAmt.toFixed(2)} บาท</span></div>
      `;
    }

    function validateState(){
      const qty = parseFloat(currentInput);
      submitBtn.disabled = !(selectedFuel && qty > 0);
    }

    function validateForm(e){
      if (submitBtn.disabled) { e.preventDefault(); alert('ข้อมูลยังไม่ครบถ้วน กรุณาเลือกชนิดน้ำมันและใส่จำนวน'); }
    }

    // --- [แก้ไข] Member Search ---
    function handleMemberSearch(e) {
      clearTimeout(searchTimeout);
      const term = e.target.value.trim();

      // ล้างค่าที่ซ่อนไว้ก่อน
      customerPhoneHidden.value = '';
      householdNoHidden.value = '';

      if (term === '') {
        memberInfoDiv.style.display = 'none';
        return;
      }
      // อนุญาตให้ค้นหาเลขสั้นๆ (เช่น บ้านเลขที่ '12')
      if (term.length < 2) { 
        memberInfoDiv.style.display = 'none';
        return;
      }

      searchTimeout = setTimeout(() => {
        findMember(term);
      }, 500); // 500ms delay
    }

    async function findMember(term) {
      const spinner = `<div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div>`;
      const alertDiv = memberInfoDiv.querySelector('.alert');

      memberInfoDiv.style.display = 'block';
      alertDiv.className = 'alert alert-secondary py-2 px-3 d-flex align-items-center';
      memberNameSpan.innerHTML = `กำลังค้นหา... ${spinner}`;

      try {
          const url = `/api/search_member.php?term=${encodeURIComponent(term)}`; // [แก้ไข] ใช้ /api/
          const res = await fetch(url);
          
          // [แก้ไข] ลบ 's ที่เป็น Syntax Error ออก
          if (!res.ok) throw new Error('bad_status_' + res.status);
          
          const member = await res.json();

          if (member && !member.error) {
              alertDiv.className = 'alert alert-info py-2 px-3 d-flex align-items-center';
              alertDiv.querySelector('i').className = 'bi bi-person-check-fill me-2';
              memberNameSpan.textContent = `สมาชิก: ${member.full_name} (${member.type_th})`;

              // อัปเดตข้อมูลที่พบลงในช่อง input ที่ซ่อนไว้
              customerPhoneHidden.value = member.phone || '';
              householdNoHidden.value = member.house_number || '';
              
              // อัปเดตค่าในช่องที่แสดงผล ให้เป็นค่าที่ค้นเจอ
              if (member.house_number === term) {
                 memberIdentifierInput.value = member.house_number;
              } else if (member.phone.includes(term)) {
                 memberIdentifierInput.value = member.phone;
              }

          } else {
              alertDiv.className = 'alert alert-warning py-2 px-3 d-flex align-items-center';
              alertDiv.querySelector('i').className = 'bi bi-person-exclamation me-2';
              memberNameSpan.textContent = 'ไม่พบสมาชิก';

              // ถ้าไม่เจอ ต้องส่งค่าที่ผู้ใช้กรอกไปตรงๆ
              if (/^[0-9\s\-()+]+$/.test(term)) {
                  customerPhoneHidden.value = term.replace(/\D+/g, '');
                  householdNoHidden.value = ''; // ล้างค่าบ้านเลขที่
              } else {
                  householdNoHidden.value = term;
                  customerPhoneHidden.value = ''; // ล้างค่าเบอร์โทร
              }
          }
      } catch (error) {
          console.error('Fetch error:', error);
          alertDiv.className = 'alert alert-danger py-2 px-3 d-flex align-items-center';
          alertDiv.querySelector('i').className = 'bi bi-wifi-off me-2';
          memberNameSpan.textContent = 'การเชื่อมต่อล้มเหลว';
      }
    }
    // --- (จบส่วน Member Search) ---

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
          ${parseInt(points_earned)>0?`<div class="row"><span>แต้มที่ได้รับ:</span><span>${parseInt(points_earned)} แต้ม</span></div><hr>`:''}
          <div class="row"><span>ชำระโดย:</span><span>${payLabel}</span></div>
          <div class="row"><span>พนักงาน:</span><span>${employee_name}</span></div>
          <p style="margin-top:10px;">** ขอบคุณที่ใช้บริการ **</p>
        </body></html>`;
      const w = window.open('', '_blank');
      w.document.write(receiptHTML); w.document.close(); w.focus();
      setTimeout(()=>{ w.print(); w.close(); }, 250);
    }

    <?php if ($sale_success && $sale_data_json): ?>
      const saleDataForReceipt = <?= $sale_data_json; ?>;
      const receiptModalEl = document.getElementById('receiptModal');
      if (receiptModalEl) {
        const receiptModal = new bootstrap.Modal(receiptModalEl);
        receiptModal.show();
      }
    <?php endif; ?>

    (function(){ display.textContent='0'; })();
  </script>
</body>
</html>