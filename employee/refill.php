<?php
// refill.php — รับน้ำมันเข้าคลัง (Auto เลือก/กระจายลงถังตาม fuel_id ของสถานีนั้น)
// หมายเหตุ:
// - รองรับทั้งสคีมาที่ "มี" และ "ยังไม่มี" คอลัมน์ fuel_receives.station_id (เช็คแบบ runtime)
// - อ้างอิง station_id จากตาราง settings (setting_name='station_id')

session_start();
date_default_timezone_set('Asia/Bangkok');

/* ============ Validate Request / Auth / CSRF ============ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: inventory.php?err=วิธีการเรียกไม่ถูกต้อง');
  exit;
}
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'employee')) {
  header('Location: /index/login.php?err=สิทธิ์ไม่พอ');
  exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  header('Location: inventory.php?err=CSRF ไม่ถูกต้อง');
  exit;
}

/* ============ DB ============ */
require_once __DIR__ . '/../config/db.php'; // ต้องกำหนดตัวแปร $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) {
  header('Location: inventory.php?err=เชื่อมต่อฐานข้อมูลไม่สำเร็จ');
  exit;
}

/* ============ Helpers ============ */
/**
 * ตรวจว่าตารางมีคอลัมน์หรือไม่ (cache ในหน่วยความจำของ process)
 */
function has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) return $cache[$key];

  try {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':t' => $table, ':c' => $column]);
    $cache[$key] = (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    // ถ้าอ่าน information_schema ไม่ได้ ให้ถือว่าไม่มีคอลัมน์
    $cache[$key] = false;
  }
  return $cache[$key];
}

/* ============ Read Inputs ============ */
$fuel_id       = (int)($_POST['fuel_id'] ?? 0);
$amount        = (float)($_POST['amount'] ?? 0);                       // ลิตรที่รับเข้า ทั้งหมด
$unit_cost     = isset($_POST['cost']) ? (float)$_POST['cost'] : 0.0;  // ต้นทุน/ลิตร (บาท)
$tax_per_liter = isset($_POST['tax_per_liter']) ? (float)$_POST['tax_per_liter'] : 0.0;
$other_costs   = isset($_POST['other_costs']) ? (float)$_POST['other_costs'] : 0.0; // คชจ.อื่นรวมก้อน
$supplier_id   = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
$notes         = trim((string)($_POST['notes'] ?? ''));
$received_date = date('Y-m-d H:i:s');
$user_id       = (int)($_SESSION['user_id']);

if ($fuel_id <= 0 || $amount <= 0) {
  header('Location: inventory.php?err=ข้อมูลรับไม่ถูกต้อง');
  exit;
}

/* ============ Resolve station_id จาก settings ============ */
$station_id = 1;
try {
  $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
  $sid = $st ? $st->fetchColumn() : false;
  if ($sid !== false) $station_id = (int)$sid;
} catch (Throwable $e) {
  // fallback เป็น 1
}

/* ============ Main Tx ============ */
try {
  $pdo->beginTransaction();

  // ล็อกถังของสถานีนี้ + เชื้อเพลิงนี้ เพื่อกันแข่งกันเขียน
  $sqlTanks = "
    SELECT id, capacity_l, current_volume_l, (capacity_l - current_volume_l) AS free_l
    FROM fuel_tanks
    WHERE station_id = :sid AND fuel_id = :fid AND is_active = 1
    ORDER BY free_l DESC, id ASC
    FOR UPDATE
  ";
  $stmt = $pdo->prepare($sqlTanks);
  $stmt->execute([':sid' => $station_id, ':fid' => $fuel_id]);
  $tanks = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$tanks) {
    throw new RuntimeException('ไม่มีถังสำหรับเชื้อน้ำมันนี้');
  }

  // ตรวจความจุรวมว่าง
  $total_free = array_sum(array_map(fn($t) => (float)$t['free_l'], $tanks));
  if ($total_free + 1e-6 < $amount) { // กัน floating error เล็กน้อย
    throw new RuntimeException('ปริมาณที่รับมากกว่าพื้นที่ว่างในถังรวม');
  }

  // ตรวจว่า fuel_receives มีคอลัมน์ station_id หรือยัง
  $fr_has_station = has_column($pdo, 'fuel_receives', 'station_id');

  // 1) fuel_receives (เก็บ Log หลัก)
  if ($fr_has_station) {
    $insRecv = $pdo->prepare("
      INSERT INTO fuel_receives (station_id, fuel_id, amount, cost, supplier_id, notes, received_date, created_by)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    $insRecv->execute([$station_id, $fuel_id, $amount, $unit_cost, $supplier_id, $notes, $received_date, $user_id]);
  } else {
    // สคีมายังไม่มี station_id -> เก็บฟิลด์ที่มีได้ก่อน
    $insRecv = $pdo->prepare("
      INSERT INTO fuel_receives (fuel_id, amount, cost, supplier_id, notes, received_date, created_by)
      VALUES (?,?,?,?,?,?,?)
    ");
    $insRecv->execute([$fuel_id, $amount, $unit_cost, $supplier_id, $notes, $received_date, $user_id]);
  }
  $receive_id = (int)$pdo->lastInsertId();

  // 2) กระจายลิตรลงถัง + บันทึก lot / move ต่อถัง
  $remain = $amount;
  $allocs = []; // [tank_id => liters ที่จะเติมจริง]
  foreach ($tanks as $t) {
    if ($remain <= 0) break;
    $free = max(0.0, (float)$t['free_l']);
    if ($free <= 1e-12) continue;

    $put = min($free, $remain);
    $allocs[(int)$t['id']] = $put;
    $remain -= $put;
  }
  if ($remain > 1e-6) {
    throw new RuntimeException('ไม่สามารถจัดสรรลงถังได้ครบ');
  }

  // เตรียม statement
  $insLot = $pdo->prepare("
    INSERT INTO fuel_lots
      (station_id, fuel_id, tank_id, receive_id, supplier_id, lot_code, received_at,
       observed_liters, corrected_liters, unit_cost, tax_per_liter, other_costs,
       density_kg_per_l, temp_c, notes, created_by)
    VALUES
      (:station_id, :fuel_id, :tank_id, :receive_id, :supplier_id, :lot_code, :received_at,
       :observed_liters, NULL, :unit_cost, :tax_per_liter, :other_costs,
       NULL, NULL, :notes, :created_by)
  ");

  $updLotInit = $pdo->prepare("
    UPDATE fuel_lots
      SET liters_received = initial_liters,
          total_cost      = initial_total_cost,
          remaining_liters= initial_liters
    WHERE id = :id
  ");

  $insMove = $pdo->prepare("
    INSERT INTO fuel_moves (occurred_at, type, tank_id, liters, unit_price, ref_doc, ref_note, user_id)
    VALUES (NOW(), 'receive', :tank_id, :liters, NULL, :ref_doc, :ref_note, :user_id)
  ");

  $updTank = $pdo->prepare("
    UPDATE fuel_tanks
      SET current_volume_l = current_volume_l + :lit
    WHERE id = :tid
  ");

  // คิดสัดส่วนค่าใช้จ่ายอื่น ๆ ต่อถัง
  $other_left = $other_costs;

  foreach ($allocs as $tank_id => $lit) {
    // สัดส่วน other_costs ต่อถังตามส่วนของลิตร
    $part_other = ($amount > 0) ? round($other_costs * ($lit / $amount), 2) : 0.0;
    // กันเศษกลมให้ตัวสุดท้าย
    if ($tank_id === array_key_last($allocs)) {
      $part_other = $other_left;
    }
    $other_left -= $part_other;

    // lot_code ยูนิคภายในสถานี
    $lot_code = sprintf('LOT-%s-%02d-%s', date('YmdHis'), $fuel_id, substr(bin2hex(random_bytes(3)), 0, 6));

    // บันทึก lot
    $insLot->execute([
      ':station_id'     => $station_id,
      ':fuel_id'        => $fuel_id,
      ':tank_id'        => $tank_id,
      ':receive_id'     => $receive_id,
      ':supplier_id'    => $supplier_id,
      ':lot_code'       => $lot_code,
      ':received_at'    => $received_date,
      ':observed_liters'=> round($lit, 2),
      ':unit_cost'      => round($unit_cost, 4),
      ':tax_per_liter'  => round($tax_per_liter, 4),
      ':other_costs'    => round($part_other, 2),
      ':notes'          => $notes,
      ':created_by'     => $user_id
    ]);
    $lot_id = (int)$pdo->lastInsertId();

    // ตั้งค่าเริ่มต้นของช่อง summary ใน lot (ตาม generated columns)
    $updLotInit->execute([':id' => $lot_id]);

    // ลง movement: receive
    $insMove->execute([
      ':tank_id' => $tank_id,
      ':liters'  => round($lit, 2),
      ':ref_doc' => $lot_code,
      ':ref_note'=> $notes,
      ':user_id' => $user_id
    ]);

    // อัปเดตปริมาตรถัง
    $updTank->execute([':lit' => round($lit, 2), ':tid' => $tank_id]);
  }

  // 3) อัปเดต supplier วันที่ส่งล่าสุด (ถ้ามี)
  if (!empty($supplier_id)) {
    $pdo->prepare("UPDATE suppliers SET last_delivery_date = CURDATE() WHERE supplier_id = ?")
        ->execute([$supplier_id]);
  }

  // 4) เก็บ last_refill ใน fuel_stock (ไม่แตะ current_stock; ใช้รายงาน/อ้างอิงล่าสุด)
  $pdo->prepare("
    INSERT INTO fuel_stock (fuel_id, station_id, current_stock, capacity, min_threshold, max_threshold, last_refill_date, last_refill_amount)
    VALUES (?, ?, 0, 0, 90, 500, NOW(), ?)
    ON DUPLICATE KEY UPDATE last_refill_date = VALUES(last_refill_date),
                            last_refill_amount = VALUES(last_refill_amount)
  ")->execute([$fuel_id, $station_id, $amount]);

  $pdo->commit();

  header('Location: inventory.php?ok=บันทึกการรับน้ำมันสำเร็จ');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('refill.php error: ' . $e->getMessage());

  // ข้อความที่เป็นมิตรกับผู้ใช้
  $msg = 'ไม่สามารถบันทึกการรับได้';
  $err = $e->getMessage();
  if (stripos($err, 'ไม่มีถัง') !== false) $msg = 'ไม่มีถังสำหรับเชื้อน้ำมันนี้';
  if (stripos($err, 'มากกว่าพื้นที่ว่าง') !== false) $msg = 'ปริมาณที่รับมากกว่าพื้นที่ว่างในถังรวม';
  if (stripos($err, 'จัดสรรลงถังได้ครบ') !== false) $msg = 'ไม่สามารถจัดสรรลิตรลงถังได้ครบ';

  header('Location: inventory.php?err=' . $msg);
  exit;
}
