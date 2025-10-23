<?php
// employee/sell.php — ระบบ POS ปั๊มน้ำมัน (สคีมา: sales + sales_items + fuel_* )
// ข้อสำคัญ: ต้องมีไฟล์ /api/search_member.php สำหรับค้นสมาชิก (ดูตัวอย่างที่ส่งให้ก่อนหน้า)
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

/**
 * ตรวจว่าตารางมีคอลัมน์หรือไม่ (แคชในหน่วยความจำ)
 */
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
  } catch (Throwable $e) {
    // ถ้าอ่าน information_schema ไม่ได้ ให้ถือว่าไม่มีคอลัมน์
    $cache[$key] = false;
  }
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

    // Normalize เบอร์โทร -> ตัวเลขล้วน
    $customer_phone = preg_replace('/\D+/', '', (string)($_POST['customer_phone'] ?? ''));
    $household_no   = trim((string)($_POST['household_no'] ?? ''));

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
        $liters_disp  = round($liters_raw, 3);                  // สำหรับแสดงผล
        $liters_db    = round($liters_raw, 2);                  // ตรงกับสคีมา sales_items.liters DECIMAL(10,2)
        $total_amount = round($liters_db * $fuel_price, 2);     // ให้สอดคล้องกับ liters_db
      } else {
        $total_amount = round((float)$quantity, 2);
        $liters_calc  = ($fuel_price > 0 ? $total_amount / $fuel_price : 0.0);
        $liters_disp  = round($liters_calc, 3);
        $liters_db    = round($liters_calc, 2);
      }
      $discount_amount = round($total_amount * ($discount/100.0), 2);
      $net_amount      = round($total_amount - $discount_amount, 2);

      // แต้มสะสม: 1 แต้ม ต่อ 20 บาทสุทธิ (ถ้ามีข้อมูลสมาชิก)
      $POINT_RATE     = 20;
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
        'liters'           => $liters_disp,      // แสดงผล 3 ตำแหน่ง
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

        // ตรวจคอลัมน์เสริมใน sales (บันทึกลูกค้า/ส่วนลด ถ้ามี)
        $col_phone   = has_column($pdo, 'sales', 'customer_phone');
        $col_house   = has_column($pdo, 'sales', 'household_no');
        $col_discpct = has_column($pdo, 'sales', 'discount_pct');
        $col_discamt = has_column($pdo, 'sales', 'discount_amount');

        // วน generate sale_code กันชน (ต้องตั้ง UNIQUE(sale_code) ใน DB)
        $tries = 0;
        $sale_id = null;
        do {
          $receipt_no = 'R'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3))); // 6 hex ~ 16,777,216 แบบ/วัน
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
          if ($col_phone)   { $cols[] = 'customer_phone';  $params[':customer_phone']  = $customer_phone ?: null; }
          if ($col_house)   { $cols[] = 'household_no';    $params[':household_no']    = $household_no ?: null; }
          if ($col_discpct) { $cols[] = 'discount_pct';    $params[':discount_pct']    = $discount; }
          if ($col_discamt) { $cols[] = 'discount_amount'; $params[':discount_amount'] = $discount_amount; }

          $placeholders = array_map(fn($c) => ':'.$c, $cols);
          // ให้แน่ใจว่า key ใน $params ครบตาม $placeholders
          foreach ($cols as $c) {
            $k = ':'.$c;
            if (!array_key_exists($k, $params)) {
              $params[$k] = null;
            }
          }

          $sql = "INSERT INTO sales (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
          try {
            $stmtSale = $pdo->prepare($sql);
            $stmtSale->execute($params);
            $sale_id = (int)$pdo->lastInsertId();
            $sale_data['receipt_no'] = $receipt_no; // สำหรับแสดง/พิมพ์
            break; // สำเร็จ
          } catch (PDOException $ex) {
            // 23000 = Duplicate entry (ชน UNIQUE sale_code)
            if ($ex->getCode() === '23000' && ++$tries <= 5) {
              continue; // ลอง gen ใหม่
            }
            throw $ex;
          }
        } while ($tries <= 5);

        if (!$sale_id) { throw new RuntimeException('ไม่สามารถสร้างเลขที่ใบเสร็จได้'); }

        // --- หา tank ของสถานีนี้ + เชื้อเพลิงนี้ (ให้ได้ $tank_id ก่อนใช้จริง) ---
        $tank_id = null;
        try {
          // ค้นหาถังที่มีน้ำมันพอ และเลือกถังที่น้ำมันเยอะสุดก่อน
          $findTank = $pdo->prepare("
            SELECT id
            FROM fuel_tanks
            WHERE station_id = :sid AND fuel_id = :fid AND is_active = 1 AND current_volume_l >= :liters
            ORDER BY current_volume_l DESC
            LIMIT 1
          ");
          $findTank->execute([':sid'=>$station_id, ':fid'=>(int)$fuel_id, ':liters' => $liters_db]);
          $tank_id = $findTank->fetchColumn() ?: null;
        } catch (Throwable $e) {
          $tank_id = null;
        }

        // 2) รายการ -> sales_items (ผูก fuel_id + tank_id)
        $stmtItem = $pdo->prepare("
          INSERT INTO sales_items (sale_id, fuel_id, tank_id, fuel_type, liters, price_per_liter)
          VALUES (:sale_id, :fuel_id, :tank_id, :fuel_type, :liters, :price_per_liter)
        ");
        $stmtItem->execute([
          ':sale_id'         => $sale_id,
          ':fuel_id'         => (int)$fuel_id,
          ':tank_id'         => $tank_id,                 // จะเป็น NULL ถ้าไม่พบถัง
          ':fuel_type'       => $fuel_name,               // snapshot ชื่อ ณ ขณะขาย
          ':liters'          => $liters_db,               // DECIMAL(10,2)
          ':price_per_liter' => round($fuel_price, 2)
        ]);
        // หมายเหตุ: line_amount เป็น GENERATED COLUMN ฝั่ง DB

        // 3) ตัดสต็อก + บันทึก movement -> fuel_moves + sync fuel_stock
        try {
          if ($tank_id) {
            // ล็อคแถวกันชน (เข้มกว่าแบบอ่อน)
            $sel = $pdo->prepare("SELECT id, current_volume_l FROM fuel_tanks WHERE id = :tid FOR UPDATE");
            $sel->execute([':tid' => (int)$tank_id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);

            if ($row) {
              $lit2 = $liters_db; // ใช้จำนวนเดียวกับที่ลงใน sales_items
              $stmtUpd = $pdo->prepare("
                  UPDATE fuel_tanks 
                  SET current_volume_l = current_volume_l - ? 
                  WHERE id = ? 
                    AND current_volume_l >= ?
              ");

            $stmtUpd->execute([$lit2, $tank_id, $lit2]);
              // ถ้าตัดสต็อกสำเร็จ (rowCount > 0) ค่อยบันทึก movement และ sync ตารางอื่น
              if ($stmtUpd->rowCount() > 0) {
                // ลง movement ผูก sale_id (กันซ้ำด้วย UNIQUE(sale_id,is_sale_out))
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

                // 3.1) จัดสรรต้นทุนขาย (COGS) จาก Lot ที่เปิดอยู่ (FIFO)
                if ($move_id > 0) {
                    $liters_to_allocate = $liters_db;
                    // ใช้ View v_open_fuel_lots ที่คำนวณ remaining_liters มาให้แล้ว
                    $getLots = $pdo->prepare("
                        SELECT id, remaining_liters, unit_cost_full
                        FROM v_open_fuel_lots
                        WHERE tank_id = :tid
                        ORDER BY received_at ASC, id ASC
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

                    // ถ้าจัดสรรไม่ครบ แสดงว่าสต็อกใน tank กับ lot ไม่ตรงกัน -> ยกเลิกการขาย
                    if ($liters_to_allocate > 1e-6) {
                        throw new RuntimeException("COGS Error: สต็อกใน Lot ไม่พอสำหรับ Tank ID {$tank_id} (ขาดไป {$liters_to_allocate} ลิตร)");
                    }
                }

                // sync ตาราง fuel_stock (บางหน้ารายงานอ่านตารางนี้)
                $sync = $pdo->prepare("
                  UPDATE fuel_stock
                  SET current_stock = GREATEST(0, current_stock - :l)
                  WHERE station_id = :sid AND fuel_id = :fid
                ");
                $sync->execute([
                  ':l'   => $liters_db,
                  ':sid' => $station_id,
                  ':fid' => (int)$fuel_id,
                ]);
              } else {
                // ถ้าสต็อกไม่พอ ให้ log แล้วไม่ fail ใบเสร็จ (หรือจะ throw เพื่อยกเลิกทั้งทรานแซกชันก็ได้)
                error_log('Inventory not enough for tank '.$tank_id.' sale '.$sale_id);
              }
            } else {
              error_log("Tank not found (FOR UPDATE) id={$tank_id}");
            }
          } else {
            // เพิ่มข้อมูลใน log ให้ชัดเจนขึ้น
            error_log("No active tank for station {$station_id} and fuel {$fuel_id} with enough stock ({$liters_db}L) for sale {$sale_id}");
          }
        } catch (Throwable $invE) {
          error_log("Inventory update skipped: ".$invE->getMessage());
        }

        // 4) สะสมแต้ม -> scores (ค้นหาจากเบอร์หรือบ้านเลขที่)
        if ($points_earned > 0 && ($customer_phone !== '' || $household_no !== '')) {
          try {
            $member_id = null;
            
            // สร้าง WHERE clause แบบยืดหยุ่น
            $where_conditions = [];
            $params = [];
            
            if ($customer_phone !== '') {
              $where_conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(u.phone, '-', ''), ' ', ''), '(', ''), ')', '') = :phone";
              $params[':phone'] = $customer_phone;
            }
            
            if ($household_no !== '') {
              $where_conditions[] = "m.house_number = :house";
              $params[':house'] = $household_no;
            }
            
            if (!empty($where_conditions)) {
              $where_clause = implode(' OR ', $where_conditions);
              
              $q = $pdo->prepare("
                SELECT m.id AS member_id
                FROM users u
                INNER JOIN members m ON m.user_id = u.id
                WHERE m.is_active = 1 AND ({$where_clause})
                LIMIT 1
              ");
              
              $q->execute($params);
              $member_id = $q->fetchColumn();
              
              // Debug log
              if (!$member_id) {
                error_log("Member not found - Phone: {$customer_phone}, House: {$household_no}");
              }
            }
            
            // ถ้าพบสมาชิก ให้บันทึกแต้ม
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

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

  <!-- Global theme -->
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
    .step-indicator {
  position: relative;
  padding: 1rem;
  transition: all 0.3s;
}

.step-number {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: #e9ecef;
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
  background: var(--primary);
  color: white;
  box-shadow: 0 4px 15px rgba(32, 163, 158, 0.4);
}

.step-indicator.completed .step-number {
  background: #28a745;
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
}

.sale-type-card:hover {
  border-color: var(--primary);
  transform: translateY(-5px);
  box-shadow: 0 8px 24px rgba(0,0,0,.1);
}

.sale-type-card.selected {
  border-color: var(--primary);
  background: var(--primary-light);
  box-shadow: 0 8px 24px rgba(32, 163, 158, 0.3);
}

.sale-type-card i {
  color: var(--primary);
}

#previewCalc {
  min-height: 100px;
  font-size: 0.95rem;
}

#finalSummary {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  padding: 1.5rem;
  border-radius: 12px;
}

#finalSummary .row {
  margin-bottom: 0.5rem;
}

#finalSummary hr {
  border-color: rgba(255,255,255,0.3);
  margin: 0.75rem 0;
}
  </style>
</head>

<body>
  <!-- App Bar -->
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

  <!-- Offcanvas Sidebar -->
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
      <!-- Sidebar Desktop -->
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

      <!-- Main -->
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
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($sale_error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <strong>เกิดข้อผิดพลาด!</strong> <?= htmlspecialchars($sale_error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Progress Steps -->
  <div class="card mb-4">
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

    <div class="row g-4">
      <!-- Step 1: เลือกน้ำมัน -->
      <div class="col-12" id="step1-panel">
        <div class="pos-panel">
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
      </div>

      <!-- Step 2: เลือกประเภท -->
      <div class="col-12" id="step2-panel" style="display:none;">
        <div class="pos-panel">
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

          <input type="hidden" name="sale_type" id="saleTypeInput" value="">

          <div class="text-center mt-4">
            <button type="button" class="btn btn-outline-secondary me-2" onclick="goToStep(1)">
              <i class="bi bi-arrow-left me-2"></i> ย้อนกลับ
            </button>
            <button type="button" class="btn btn-primary btn-lg" id="nextToStep3" disabled>
              ถัดไป <i class="bi bi-arrow-right ms-2"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Step 3: กรอกจำนวน -->
      <div class="col-12" id="step3-panel" style="display:none;">
        <div class="pos-panel">
          <h5 class="mb-3">
            <i class="bi bi-calculator-fill me-2"></i>
            ขั้นตอนที่ 3: กรอกจำนวน<span id="saleTypeLabel"></span>
          </h5>

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

          <div id="previewCalc" class="mt-4 p-3 bg-light rounded"></div>

          <div class="text-center mt-4">
            <button type="button" class="btn btn-outline-secondary me-2" onclick="goToStep(2)">
              <i class="bi bi-arrow-left me-2"></i> ย้อนกลับ
            </button>
            <button type="button" class="btn btn-primary btn-lg" id="nextToStep4" disabled>
              ถัดไป <i class="bi bi-arrow-right ms-2"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Step 4: ข้อมูลและบันทึก -->
      <div class="col-12" id="step4-panel" style="display:none;">
        <div class="row g-4">
          <div class="col-lg-6">
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

                <div class="col-12">
                  <label class="form-label">ส่วนลด (%)</label>
                  <input type="number" class="form-control" name="discount" 
                         id="discountInput" value="0" min="0" max="100" step="0.1">
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

          <div class="col-lg-6">
            <div class="pos-panel">
              <h5 class="mb-3">
                <i class="bi bi-receipt me-2"></i>
                สรุปรายการขาย
              </h5>
              <div id="finalSummary"></div>

              <div class="d-grid gap-2 mt-4">
                <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                  <i class="bi bi-check-circle-fill me-2"></i>
                  บันทึกการขาย
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

// ===== Event Listeners =====
fuelCards.forEach(card => card.addEventListener('click', handleFuelSelect));
saleTypeCards.forEach(card => card.addEventListener('click', handleSaleTypeSelect));
numpadBtns.forEach(btn => btn.addEventListener('click', handleNumpad));
discountInput.addEventListener('input', updateFinalSummary);
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
}

// ===== Step 3: Enter Amount =====
function handleNumpad(e) {
  const btn = e.currentTarget;
  const num = btn.dataset.num;
  const action = btn.dataset.action;

  if (num !== undefined) {
    if (currentInput === '0') currentInput = '';
    if (currentInput.length < 9) currentInput += num;
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
  document.getElementById('nextToStep4').disabled = !(qty > 0);
  
  if (qty > 0) {
    updateStepIndicator(3, 'completed');
  }
}

function updatePreview() {
  const qty = parseFloat(currentInput) || 0;
  if (qty === 0) {
    document.getElementById('previewCalc').innerHTML = '<p class="text-muted text-center">กรุณากรอกจำนวน</p>';
    return;
  }

  let liters, amount;
  if (saleType === 'liters') {
    liters = qty;
    amount = liters * currentPrice;
  } else {
    amount = qty;
    liters = amount / currentPrice;
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
  
  document.getElementById('previewCalc').innerHTML = html;
}

// ===== Step 4: Final Summary =====
function updateFinalSummary() {
  const qty = parseFloat(currentInput) || 0;
  const disc = parseFloat(discountInput.value) || 0;

  let liters, total;
  if (saleType === 'liters') {
    liters = qty;
    total = liters * currentPrice;
  } else {
    total = qty;
    liters = total / currentPrice;
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
    <div class="row mb-2 text-warning">
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

  document.getElementById('finalSummary').innerHTML = html;
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
    updatePreview();
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
  
  goToStep(1);
}

// ===== Member Search =====
let searchTimeout;
function handleMemberSearch(e) {
  clearTimeout(searchTimeout);
  const term = e.target.value.trim();

  if (!term || term.length < 3) {
    memberInfoDiv.style.display = 'none';
    return;
  }

  searchTimeout = setTimeout(() => findMember(term), 500);
}

async function findMember(term) {
  memberInfoDiv.style.display = 'block';
  memberNameSpan.innerHTML = 'กำลังค้นหา...';

  try {
    const res = await fetch(`/api/search_member.php?term=${encodeURIComponent(term)}`);
    const member = await res.json();

    if (member && !member.error) {
      memberNameSpan.textContent = `สมาชิก: ${member.full_name}`;
      customerPhoneInput.value = member.phone || '';
      householdNoInput.value = member.house_number || '';
      updateFinalSummary();
    } else {
      memberNameSpan.textContent = 'ไม่พบสมาชิก';
    }
  } catch (error) {
    memberNameSpan.textContent = 'การเชื่อมต่อล้มเหลว';
  }
}

// ===== Print Receipt =====
function printReceipt() {
  // ใช้โค้ดเดิม
}

// ===== Init =====
<?php if ($sale_success && $sale_data_json): ?>
  const saleDataForReceipt = <?= $sale_data_json; ?>;
  const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
  receiptModal.show();
<?php endif; ?>
</script>
</body>
</html>