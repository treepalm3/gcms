<?php
// inv_adjust.php — ปรับยอดสต๊อก (no triggers)
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
  $tank_id   = isset($_POST['tank_id']) ? (int)$_POST['tank_id'] : null;
  $tank_code = trim($_POST['tank_code'] ?? '');
  $adj_type  = ($_POST['adj_type'] ?? 'plus') === 'minus' ? 'minus' : 'plus';
  $liters    = (float)($_POST['liters'] ?? 0);
  $ref_note  = trim($_POST['ref_note'] ?? '');
  $user_id   = $_SESSION['user_id'] ?? null;

  if ($liters <= 0) throw new RuntimeException('จำนวนลิตรต้องมากกว่า 0');
  if (!$tank_id && !$tank_code) throw new RuntimeException('กรุณาเลือกถัง');

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

  if ($adj_type === 'minus' && $liters > $cur) {
    throw new RuntimeException('สต๊อกไม่พอสำหรับการปรับลด');
  }
  if ($adj_type === 'plus') {
    if ($cap > 0 && ($cur + $liters) > $cap) {
      throw new RuntimeException('ปริมาณเกินความจุถัง');
    }
    if ($max > 0 && ($cur + $liters) > $max) {
      throw new RuntimeException('ปริมาณเกินระดับสูงสุดที่กำหนด (Max)');
    }
  }

  $pdo->beginTransaction();

  $type = $adj_type === 'minus' ? 'adjust_minus' : 'adjust_plus';
  $pdo->prepare("
    INSERT INTO fuel_moves(occurred_at,type,tank_id,liters,unit_price,ref_doc,ref_note,user_id)
    VALUES (NOW(),:type,:tid,:liters,NULL,NULL,NULLIF(:note,''),:uid)
  ")->execute([
    ':type'=>$type, ':tid'=>$tank['id'], ':liters'=>$liters, ':note'=>$ref_note, ':uid'=>$user_id
  ]);

  $delta = ($adj_type === 'minus') ? -$liters : $liters;

  // อัปเดตถัง
  $pdo->prepare("UPDATE fuel_tanks
                   SET current_volume_l = current_volume_l + :d,
                       updated_at = NOW()
                 WHERE id = :tid")
      ->execute([':d'=>$delta, ':tid'=>$tank['id']]);

  // อัปเดตยอดรวม fuel_stock
  $pdo->prepare("
    INSERT INTO fuel_stock (fuel_id, current_stock, capacity, min_threshold, max_threshold, last_refill_date, last_refill_amount)
    VALUES (:fid, :d, 10000.00, 1000.00, 8000.00, NULL, NULL)
    ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)
  ")->execute([':fid'=>$tank['fuel_id'], ':d'=>$delta]);

  $pdo->commit();
  redirect('บันทึกปรับยอดเรียบร้อย');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect(null, $e->getMessage());
}
