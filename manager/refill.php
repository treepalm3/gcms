<?php
// manager/refill.php — [แก้ไข] รับน้ำมันเข้าถังโดยตรง + บันทึกบัญชี (ไม่ใช้ fuel_stock/fuel_receives)
session_start();
date_default_timezone_set('Asia/Bangkok');

// --- การตรวจสอบ Request Method, Session, CSRF ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: inventory.php?err=วิธีการเรียกไม่ถูกต้อง'); exit;
}
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'manager')) {
  header('Location: /index/login.php?err=สิทธิ์ไม่พอ'); exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  header('Location: inventory.php?err=CSRF ไม่ถูกต้อง'); exit;
}

require_once __DIR__ . '/../config/db.php'; // ต้องมี $pdo (PDO)

// --- ดึงข้อมูลจากฟอร์ม ---
$fuel_id       = (int)($_POST['fuel_id'] ?? 0);
$amount        = (float)($_POST['amount'] ?? 0);
$unit_cost     = isset($_POST['cost']) ? (float)$_POST['cost'] : 0.0;
$tax_per_liter = isset($_POST['tax_per_liter']) ? (float)$_POST['tax_per_liter'] : 0.0;
$other_costs   = isset($_POST['other_costs']) ? (float)$_POST['other_costs'] : 0.0;
$supplier_id   = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
$notes         = trim((string)($_POST['notes'] ?? ''));
$received_date = date('Y-m-d H:i:s');
$user_id       = (int)($_SESSION['user_id']);
$invoice_no    = trim((string)($_POST['invoice_no'] ?? null));

// --- ตรวจสอบข้อมูลเบื้องต้น ---
if ($fuel_id <= 0 || $amount <= 0) {
  header('Location: inventory.php?err=ข้อมูลรับไม่ถูกต้อง (เชื้อเพลิงหรือจำนวนลิตร)'); exit;
}
if ($unit_cost <= 0) {
    header('Location: inventory.php?err=กรุณาระบุ "ต้นทุน/ลิตร" ที่ถูกต้อง (มากกว่า 0) เพื่อการคำนวณกำไร'); exit;
}

// --- ดึง station_id ---
$station_id = 1;
try {
  $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
  $sid = $st ? $st->fetchColumn() : false;
  if ($sid !== false) $station_id = (int)$sid;
} catch (Throwable $e) {}

// --- เริ่ม Transaction หลัก ---
try {
  $pdo->beginTransaction();

  // --- 1. ค้นหาและล็อกถัง (FOR UPDATE) ---
  $sqlTanks = "
    SELECT id, capacity_l, current_volume_l, (capacity_l - current_volume_l) AS free_l
    FROM fuel_tanks
    WHERE station_id = ? AND fuel_id = ? AND is_active = 1
    ORDER BY free_l DESC, id ASC
    FOR UPDATE
  ";
  $stmt = $pdo->prepare($sqlTanks);
  $stmt->execute([$station_id, $fuel_id]);
  $tanks = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$tanks) {
    throw new RuntimeException('ไม่มีถังที่ใช้งานได้สำหรับเชื้อน้ำมันนี้');
  }

  // --- 2. ตรวจสอบพื้นที่ว่างรวม ---
  $total_free = array_sum(array_map(fn($t)=> (float)$t['free_l'], $tanks));
  if ($total_free + 1e-6 < $amount) { // กัน floating error
    throw new RuntimeException('ปริมาณที่รับ ('.number_format($amount).' L) มากกว่าพื้นที่ว่างในถังรวม ('.number_format($total_free).' L)');
  }

  $receive_id = null; // ไม่ใช้ ID จาก fuel_receives

  // --- 3. กระจายน้ำมันลงถัง ---
  $remain = $amount;
  $allocs = []; // เก็บ [tank_id => liters]
  $first_lot_code_generated = null;
  foreach ($tanks as $t) {
    if ($remain <= 0) break;
    $free = max(0.0, (float)$t['free_l']);
    if ($free <= 0) continue;

    $put = min($free, $remain);
    $allocs[(int)$t['id']] = $put;
    $remain -= $put;
  }
  if ($remain > 1e-6) {
    throw new RuntimeException('ไม่สามารถจัดสรรลงถังได้ครบ (คำนวณพื้นที่ว่างพลาด)');
  }

  // --- 4. เตรียม SQL Statements ---
  $other_left = $other_costs;
  $insLot = $pdo->prepare("
    INSERT INTO fuel_lots
      (station_id, fuel_id, tank_id, receive_id, supplier_id, lot_code, received_at,
       observed_liters, corrected_liters, unit_cost, tax_per_liter, other_costs,
       notes, created_by, invoice_no)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) 
  ");
  // [แก้ไข] ลบ density_kg_per_l, temp_c (ไม่มีใน SQL ของคุณ)
  
  $insMove = $pdo->prepare("
    INSERT INTO fuel_moves (occurred_at, type, tank_id, liters, unit_price, ref_doc, ref_note, user_id)
    VALUES (NOW(), 'receive', ?, ?, NULL, ?, ?, ?)
  ");
  $updTank = $pdo->prepare("
    UPDATE fuel_tanks SET current_volume_l = current_volume_l + ? WHERE id = ?
  ");

  // --- 5. วนลูปบันทึก Lot, Move, Update Tank ---
  foreach ($allocs as $tank_id => $lit) {
    $part_other = ($amount > 0) ? round($other_costs * ($lit / $amount), 2) : 0.0;
    if ($tank_id === array_key_last($allocs)) { $part_other = $other_left; }
    $other_left -= $part_other;

    $lot_code = sprintf('LOT-%s-%02d-%s', date('YmdHis'), $fuel_id, substr(bin2hex(random_bytes(3)),0,6));
    if ($first_lot_code_generated === null) {
        $first_lot_code_generated = $lot_code;
    }

    // Insert fuel_lots (จำเป็นสำหรับ COGS)
    $insLot->execute([
      $station_id, $fuel_id, $tank_id, $receive_id, $supplier_id, $lot_code, $received_date,
      $lit, null, $unit_cost, $tax_per_liter, $part_other,
      $notes, $user_id, $invoice_no
    ]);

    // Insert fuel_moves (จำเป็นสำหรับ COGS)
    $insMove->execute([$tank_id, $lit, $lot_code, $notes, $user_id]);

    // Update fuel_tanks (เพิ่มลิตรเข้าถัง)
    $updTank->execute([$lit, $tank_id]);
  }

  // --- 6. อัปเดต Supplier (ถ้ามี) ---
  if (!empty($supplier_id)) {
    $pdo->prepare("UPDATE suppliers SET last_delivery_date = CURDATE() WHERE supplier_id = ?")
        ->execute([$supplier_id]);
  }

  // ==========================================================
  // [ลบ] 7. อัปเดต fuel_stock (ตามคำขอ)
  // ==========================================================
  
  // ==========================================================
  // [คงไว้] 7. บันทึกยอดรวมค่าใช้จ่ายลง Financial Transactions
  // ==========================================================
  try {
      $fuelNameStmt = $pdo->prepare("SELECT fuel_name FROM fuel_prices WHERE fuel_id = ? LIMIT 1");
      $fuelNameStmt->execute([$fuel_id]);
      $fuel_name = $fuelNameStmt->fetchColumn() ?: 'ไม่ทราบชนิด';

      $total_actual_cost = ($unit_cost * $amount) + ($tax_per_liter * $amount) + $other_costs;

      $ft_code = 'FT-' . date('Ymd-His');
      $desc = "ซื้อ {$fuel_name} {$amount} ลิตร";
      if (!empty($invoice_no)) {
          $desc .= " (Inv: {$invoice_no})";
      }
      $reference_for_ft = $first_lot_code_generated ?: $invoice_no;

      $insFT = $pdo->prepare("
        INSERT INTO financial_transactions
          (station_id, transaction_code, transaction_date, type, category, description, amount, reference_id, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $insFT->execute([
          $station_id,
          $ft_code,
          $received_date,
          'expense',
          'ซื้อน้ำมัน',
          $desc,
          $total_actual_cost,
          $reference_for_ft,
          $user_id
      ]);
  } catch (Throwable $e) {
      throw new RuntimeException("ไม่สามารถบันทึกบัญชีการเงินได้: " . $e->getMessage());
  }
  
  // ==========================================================
  // [ลบ] 9. บันทึก Log สรุปไปที่ fuel_receives (ตามคำขอ)
  // ==========================================================

  // --- 8. Commit Transaction ---
  $pdo->commit();

  // --- 9. Redirect กลับพร้อมข้อความสำเร็จ ---
  header('Location: inventory.php?ok=รับน้ำมันเข้าถัง และบันทึกค่าใช้จ่ายเรียบร้อยแล้ว');

} catch (Throwable $e) {
  // --- กรณีเกิด Error: Rollback ---
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('refill.php error: '.$e->getMessage());
  $msg = 'ไม่สามารถบันทึกการรับได้: ' . $e->getMessage();
  if (stripos($e->getMessage(), 'ไม่มีถัง') !== false) $msg = $e->getMessage();
  if (stripos($e->getMessage(), 'ปริมาณที่รับมากกว่า') !== false) $msg = $e->getMessage();
  header('Location: inventory.php?err=' . urlencode($msg));
}
?>

