<?php
// inv_receive.php — รับ/เติม น้ำมันเข้าถัง (no triggers)
session_start();
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
  http_response_code(400); exit('Invalid CSRF');
}

$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;

function redirect($ok=null,$err=null){
  $q = $ok ? ('ok='.urlencode($ok)) : ('err='.urlencode($err));
  header('Location: inventory.php?'.$q); exit;
}

try {
  // รับค่าจากฟอร์ม
  $tank_id   = isset($_POST['tank_id']) ? (int)$_POST['tank_id'] : null;
  $tank_code = trim($_POST['tank_code'] ?? '');
  $liters    = (float)($_POST['liters'] ?? 0);
  $unit_price= isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : null;
  $ref_doc   = trim($_POST['ref_doc'] ?? '');
  $ref_note  = trim($_POST['ref_note'] ?? '');
  $user_id   = $_SESSION['user_id'] ?? null;

  if ($liters <= 0) throw new RuntimeException('จำนวนลิตรต้องมากกว่า 0');
  if (!$tank_id && !$tank_code) throw new RuntimeException('กรุณาเลือกถัง');

  // โหลดถัง + ล็อกแถวเพื่อกันชนกัน
  if ($tank_id) {
    $st = $pdo->prepare("SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l FROM fuel_tanks WHERE id=:id FOR UPDATE");
    $st->execute([':id'=>$tank_id]);
  } else {
    $st = $pdo->prepare("SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l FROM fuel_tanks WHERE code=:c FOR UPDATE");
    $st->execute([':c'=>$tank_code]);
  }
  $tank = $st->fetch(PDO::FETCH_ASSOC);
  if (!$tank) throw new RuntimeException('ไม่พบถังที่เลือก');

  $cur = (float)$tank['current_volume_l'];
  $cap = (float)$tank['capacity_l'];
  $max = (float)$tank['max_threshold_l'];

  // ตรวจไม่ให้เกิน capacity / max
  if ($cap > 0 && ($cur + $liters) > $cap) {
    throw new RuntimeException('ปริมาณเกินความจุถัง');
  }
  if ($max > 0 && ($cur + $liters) > $max) {
    throw new RuntimeException('ปริมาณเกินระดับสูงสุดที่กำหนด (Max)');
  }

  $pdo->beginTransaction();

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
  $pdo->prepare("UPDATE fuel_tanks
                   SET current_volume_l = current_volume_l + :liters,
                       updated_at = NOW()
                 WHERE id = :tid")
      ->execute([':liters'=>$liters, ':tid'=>$tank['id']]);

  // ซิงก์ยอดรวมตามชนิดน้ำมัน (มีแถวเสมอ)
  $up = $pdo->prepare("
    INSERT INTO fuel_stock (fuel_id, current_stock, capacity, min_threshold, max_threshold, last_refill_date, last_refill_amount)
    VALUES (:fid, :liters, 10000.00, 1000.00, 8000.00, CURDATE(), :liters)
    ON DUPLICATE KEY UPDATE
      current_stock      = current_stock + VALUES(current_stock),
      last_refill_date   = VALUES(last_refill_date),
      last_refill_amount = VALUES(last_refill_amount)
  ");
  $up->execute([':fid'=>$tank['fuel_id'], ':liters'=>$liters]);

  // เก็บ last_price ของถังถ้าระบุ
  if ($unit_price !== null && $unit_price > 0) {
    $pdo->prepare("UPDATE fuel_tanks SET updated_at=NOW() WHERE id=:id")->execute([':id'=>$tank['id']]);
  }
// หลัง commit หรือก่อน commit (แนะนำก่อน แล้ว commit ทีเดียว)
if (table_exists($pdo,'fuel_receive_log')) {
  // หา fuel_id จาก tank_id ที่รับเข้า
  $fid = (int)$pdo->query("SELECT fuel_id FROM fuel_tanks WHERE id=".(int)$tank['id'])->fetchColumn();
  $ins = $pdo->prepare("
    INSERT INTO fuel_receive_log (fuel_id, amount, cost, supplier_id, received_date, notes)
    VALUES (:fid, :amt, :cost, :sid, CURDATE(), :note)
  ");
  $ins->execute([
    ':fid'  => $fid,
    ':amt'  => $liters,
    ':cost' => $unit_price ?: null,
    ':sid'  => !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
    ':note' => $ref_note ?: null
  ]);
}

  $pdo->commit();
  redirect('บันทึกรับเข้าเรียบร้อย');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect(null, $e->getMessage());
}
