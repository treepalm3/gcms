<?php
// refill.php — รับน้ำมันเข้าคลังแบบ Auto เลือกถังจาก fuel_id
session_start();
date_default_timezone_set('Asia/Bangkok');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: inventory.php?err=วิธีการเรียกไม่ถูกต้อง'); exit;
}
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
  header('Location: /index/login.php?err=สิทธิ์ไม่พอ'); exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  header('Location: inventory.php?err=CSRF ไม่ถูกต้อง'); exit;
}

require_once __DIR__ . '/../config/db.php'; // ต้องมี $pdo (PDO)

$fuel_id       = (int)($_POST['fuel_id'] ?? 0);
$amount        = (float)($_POST['amount'] ?? 0);            // ลิตรที่รับเข้า (รวมทั้งหมด)
$unit_cost     = isset($_POST['cost']) ? (float)$_POST['cost'] : 0.0; // ต้นทุน/ลิตร (บาท) จากฟอร์ม
$tax_per_liter = isset($_POST['tax_per_liter']) ? (float)$_POST['tax_per_liter'] : 0.0;
$other_costs   = isset($_POST['other_costs']) ? (float)$_POST['other_costs'] : 0.0; // คชจ.อื่นรวมก้อน
$supplier_id   = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
$notes         = trim((string)($_POST['notes'] ?? ''));
$received_date = date('Y-m-d H:i:s');
$user_id       = (int)($_SESSION['user_id']);

if ($fuel_id <= 0 || $amount <= 0) {
  header('Location: inventory.php?err=ข้อมูลรับไม่ถูกต้อง'); exit;
}

// ดึง station_id (ถ้าไม่มีใน settings ให้เป็น 1)
$station_id = 1;
try {
  $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
  $sid = $st ? $st->fetchColumn() : false;
  if ($sid !== false) $station_id = (int)$sid;
} catch (Throwable $e) {}

try {
  $pdo->beginTransaction();

  // ล็อกถังของเชื้อนี้เพื่อกันแข่งกันเขียน
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
    throw new RuntimeException('ไม่มีถังสำหรับเชื้อน้ำมันนี้');
  }

  // ตรวจความจุรวมว่าง
  $total_free = array_sum(array_map(fn($t)=> (float)$t['free_l'], $tanks));
  if ($total_free + 1e-6 < $amount) { // กัน floating error เล็กน้อย
    throw new RuntimeException('ปริมาณที่รับมากกว่าพื้นที่ว่างในถังรวม');
  }

  // 1) fuel_receives (เก็บ Log หลัก)
  $insRecv = $pdo->prepare("
    INSERT INTO fuel_receives (fuel_id, amount, cost, supplier_id, notes, received_date, created_by)
    VALUES (?,?,?,?,?,?,?)
  ");
  $insRecv->execute([$fuel_id, $amount, $unit_cost, $supplier_id, $notes, $received_date, $user_id]);
  $receive_id = (int)$pdo->lastInsertId();

  // 2) กระจายลิตรลงถัง + บันทึก lot และ move ต่อถัง
  $remain = $amount;
  $allocs = []; // เก็บ [tank_id => liters]
  foreach ($tanks as $t) {
    if ($remain <= 0) break;
    $free = max(0.0, (float)$t['free_l']);
    if ($free <= 0) continue;

    $put = min($free, $remain);
    $allocs[(int)$t['id']] = $put;
    $remain -= $put;
  }
  if ($remain > 1e-6) { // เหลือค้าง แสดงว่า capacity คำนวณเพี้ยน
    throw new RuntimeException('ไม่สามารถจัดสรรลงถังได้ครบ');
  }

  // คิดสัดส่วนค่าใช้จ่ายอื่น ๆ ต่อถัง
  $other_left = $other_costs;
  $insLot = $pdo->prepare("
    INSERT INTO fuel_lots
      (station_id, fuel_id, tank_id, receive_id, supplier_id, lot_code, received_at,
       observed_liters, corrected_liters, unit_cost, tax_per_liter, other_costs,
       density_kg_per_l, temp_c, notes, created_by)
    VALUES
      (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  $insMove = $pdo->prepare("
    INSERT INTO fuel_moves (occurred_at, type, tank_id, liters, unit_price, ref_doc, ref_note, user_id)
    VALUES (NOW(), 'receive', ?, ?, NULL, ?, ?, ?)
  ");

  $updTank = $pdo->prepare("
    UPDATE fuel_tanks
      SET current_volume_l = current_volume_l + ?
    WHERE id = ?
  ");

  foreach ($allocs as $tank_id => $lit) {
    // สัดส่วน other_costs ต่อถัง
    $part_other = ($amount > 0) ? round($other_costs * ($lit / $amount), 2) : 0.0;
    // กันเศษกลมให้ตัวสุดท้าย
    if ($tank_id === array_key_last($allocs)) { $part_other = $other_left; }
    $other_left -= $part_other;

    // lot_code ต้องยูนิคภายในสถานี
    $lot_code = sprintf('LOT-%s-%02d-%s', date('YmdHis'), $fuel_id, substr(bin2hex(random_bytes(3)),0,6));

    // fuel_lots
    $insLot->execute([
      $station_id, $fuel_id, $tank_id, $receive_id, $supplier_id, $lot_code, $received_date,
      $lit, null,                      // observed_liters, corrected_liters(null = เท่ากับ observed ผ่าน generated col.)
      $unit_cost, $tax_per_liter, $part_other,
      null, null, $notes, $user_id
    ]);

    // fuel_moves (รับเข้า)
    $insMove->execute([$tank_id, $lit, $lot_code, $notes, $user_id]);

    // อัปเดตปริมาตรถัง
    $updTank->execute([$lit, $tank_id]);
  }

  // 3) อัปเดต supplier วันที่ส่งล่าสุด (ถ้ามี)
  if (!empty($supplier_id)) {
    $pdo->prepare("UPDATE suppliers SET last_delivery_date = CURDATE() WHERE supplier_id = ?")
        ->execute([$supplier_id]);
  }

  // 4) (ทางเลือก) เก็บ last_refill ใน fuel_stock แต่ไม่แตะ current_stock
  $pdo->prepare("
    INSERT INTO fuel_stock (fuel_id, station_id, current_stock, capacity, min_threshold, max_threshold, last_refill_date, last_refill_amount)
    VALUES (?, ?, 0, 0, 90, 500, NOW(), ?)
    ON DUPLICATE KEY UPDATE last_refill_date = VALUES(last_refill_date), last_refill_amount = VALUES(last_refill_amount)
  ")->execute([$fuel_id, $station_id, $amount]);

  $pdo->commit();

  header('Location: inventory.php?ok=บันทึกการรับน้ำมันสำเร็จ');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('refill.php error: '.$e->getMessage());
  $msg = 'ไม่สามารถบันทึกการรับได้';
  if (stripos($e->getMessage(), 'ไม่มีถัง') !== false) $msg = $e->getMessage();
  if (stripos($e->getMessage(), 'ปริมาณที่รับมากกว่า') !== false) $msg = $e->getMessage();
  header('Location: inventory.php?err=' . $msg);
}
