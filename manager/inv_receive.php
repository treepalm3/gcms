<?php
// manager/inv_receive.php — รับ/เติม น้ำมันเข้าถัง (สำหรับผู้บริหาร)
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== ตรวจสิทธิ์ + วิธีเรียก + CSRF ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: inventory.php?err=วิธีการเรียกไม่ถูกต้อง'); exit;
}
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'manager')) {
  header('Location: /index/login.php?err=สิทธิ์ไม่พอ'); exit;
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
  header('Location: inventory.php?err=CSRF ไม่ถูกต้อง'); exit;
}

/* ===== เชื่อมฐานข้อมูล ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) {
  header('Location: inventory.php?err=เชื่อมต่อฐานข้อมูลไม่ได้'); exit;
}

/* ===== helpers ===== */
function redirect($ok=null,$err=null){ $q = $ok ? ('ok='.urlencode($ok)) : ('err='.urlencode($err)); header('Location: inventory.php?'.$q); exit; }
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb");
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch(Throwable $e){ return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:col");
    $st->execute([':db'=>$db, ':tb'=>$table, ':col'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch(Throwable $e){ return false; }
}
function get_station_id(PDO $pdo): int {
  if (!empty($_SESSION['station_id'])) return (int)$_SESSION['station_id'];
  try {
    $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
    $sid = $st ? $st->fetchColumn() : false;
    return $sid !== false ? (int)$sid : 1;
  } catch(Throwable $e){ return 1; }
}

/* ===== รับค่าจากฟอร์ม ===== */
try {
  $tank_id     = isset($_POST['tank_id']) ? (int)$_POST['tank_id'] : null;
  $tank_code   = trim($_POST['tank_code'] ?? '');
  $liters      = (float)($_POST['liters'] ?? 0);
  $unit_price  = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : null;
  $ref_doc     = trim($_POST['ref_doc'] ?? '');
  $ref_note    = trim($_POST['ref_note'] ?? '');
  $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
  $user_id     = (int)($_SESSION['user_id']);

  if ($liters <= 0) throw new RuntimeException('จำนวนลิตรต้องมากกว่า 0');
  if (!$tank_id && $tank_code==='') throw new RuntimeException('กรุณาเลือกถัง');

  $station_id = get_station_id($pdo);

  $pdo->beginTransaction();

  // โหลดถัง + ล็อกแถว (กรองตามสถานี)
  if ($tank_id) {
    $st = $pdo->prepare("
      SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l
      FROM fuel_tanks
      WHERE id = :id AND (:sid IS NULL OR station_id = :sid)
      FOR UPDATE
    ");
    $st->execute([':id'=>$tank_id, ':sid'=>$station_id]);
  } else {
    $st = $pdo->prepare("
      SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l
      FROM fuel_tanks
      WHERE code = :c AND (:sid IS NULL OR station_id = :sid)
      FOR UPDATE
    ");
    $st->execute([':c'=>$tank_code, ':sid'=>$station_id]);
  }
  $tank = $st->fetch(PDO::FETCH_ASSOC);
  if (!$tank) throw new RuntimeException('ไม่พบถังที่เลือก');

  $cur = (float)$tank['current_volume_l'];
  $cap = (float)$tank['capacity_l'];
  $max = (float)$tank['max_threshold_l'];

  if ($cap > 0 && ($cur + $liters) > $cap)  throw new RuntimeException('ปริมาณเกินความจุถัง');
  if ($max > 0 && ($cur + $liters) > $max)  throw new RuntimeException('ปริมาณเกินระดับสูงสุดที่กำหนด (Max)');

  // บันทึก movement
  $ins = $pdo->prepare("
    INSERT INTO fuel_moves(occurred_at, type, tank_id, liters, unit_price, ref_doc, ref_note, user_id)
    VALUES (NOW(), 'receive', :tid, :liters, :price, NULLIF(:doc,''), NULLIF(:note,''), :uid)
  ");
  $ins->execute([
    ':tid'=>$tank['id'], ':liters'=>$liters, ':price'=>$unit_price,
    ':doc'=>$ref_doc, ':note'=>$ref_note, ':uid'=>$user_id
  ]);

  // อัปเดตยอดในถัง
  $pdo->prepare("
    UPDATE fuel_tanks
       SET current_volume_l = current_volume_l + :liters,
           updated_at = NOW()
     WHERE id = :tid
  ")->execute([':liters'=>$liters, ':tid'=>$tank['id']]);

  // ซิงก์ยอดรวมตามชนิดน้ำมัน (รองรับทั้งกรณีมี/ไม่มีคอลัมน์ station_id)
  $has_station_col = column_exists($pdo,'fuel_stock','station_id');

  if ($has_station_col) {
    $up = $pdo->prepare("
      INSERT INTO fuel_stock (fuel_id, station_id, current_stock, capacity, min_threshold, max_threshold, last_refill_date, last_refill_amount)
      VALUES (:fid, :sid, :liters, 0, 0, 0, CURDATE(), :liters)
      ON DUPLICATE KEY UPDATE
        current_stock      = current_stock + VALUES(current_stock),
        last_refill_date   = VALUES(last_refill_date),
        last_refill_amount = VALUES(last_refill_amount)
    ");
    $up->execute([':fid'=>$tank['fuel_id'], ':sid'=>$station_id, ':liters'=>$liters]);
  } else {
    $up = $pdo->prepare("
      INSERT INTO fuel_stock (fuel_id, current_stock, capacity, min_threshold, max_threshold, last_refill_date, last_refill_amount)
      VALUES (:fid, :liters, 10000.00, 1000.00, 8000.00, CURDATE(), :liters)
      ON DUPLICATE KEY UPDATE
        current_stock      = current_stock + VALUES(current_stock),
        last_refill_date   = VALUES(last_refill_date),
        last_refill_amount = VALUES(last_refill_amount)
    ");
    $up->execute([':fid'=>$tank['fuel_id'], ':liters'=>$liters]);
  }

  // เก็บ last_price ของถังถ้ามีราคา
  if ($unit_price !== null && $unit_price > 0) {
    $pdo->prepare("UPDATE fuel_tanks SET updated_at=NOW() WHERE id=:id")->execute([':id'=>$tank['id']]);
  }

  // บันทึก fuel_receive_log ถ้ามีตาราง (อ้างอิง fuel_id จากถัง)
  if (table_exists($pdo,'fuel_receive_log')) {
    $insLog = $pdo->prepare("
      INSERT INTO fuel_receive_log (fuel_id, amount, cost, supplier_id, received_date, notes)
      VALUES (:fid, :amt, :cost, :sid, CURDATE(), :note)
    ");
    $insLog->execute([
      ':fid'  => (int)$tank['fuel_id'],
      ':amt'  => $liters,
      ':cost' => $unit_price ?: null,
      ':sid'  => $supplier_id,
      ':note' => $ref_note ?: null
    ]);
  }

  // อัปเดตวันที่ส่งล่าสุดของซัพพลายเออร์ (ถ้าระบุ)
  if (!empty($supplier_id) && table_exists($pdo,'suppliers')) {
    $pdo->prepare("UPDATE suppliers SET last_delivery_date = CURDATE() WHERE supplier_id = ?")->execute([$supplier_id]);
  }

  $pdo->commit();
  redirect('บันทึกรับเข้าเรียบร้อย');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect(null, $e->getMessage());
}
