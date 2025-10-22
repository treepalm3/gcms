<?php
// inv_transfer.php — โอนย้ายสต๊อกระหว่างถัง (no triggers)
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
  $from_code = trim($_POST['from_tank'] ?? '');
  $to_code   = trim($_POST['to_tank'] ?? '');
  $liters    = (float)($_POST['liters'] ?? 0);
  $ref_note  = trim($_POST['ref_note'] ?? '');
  $user_id   = $_SESSION['user_id'] ?? null;

  if (!$from_code || !$to_code || $from_code === $to_code || $liters <= 0) {
    throw new RuntimeException('ข้อมูลโอนไม่ครบหรือไม่ถูกต้อง');
  }

  $pdo->beginTransaction();

  // ล็อกแถวถังทั้งสองใบ
  $st = $pdo->prepare("
    SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l
    FROM fuel_tanks
    WHERE code IN (?, ?)
    FOR UPDATE
  ");
  $st->execute([$from_code, $to_code]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $from = $to = null;
  foreach ($rows as $r) {
    if ($r['code'] === $from_code) $from = $r;
    if ($r['code'] === $to_code)   $to   = $r;
  }
  if (!$from) throw new RuntimeException('ไม่พบถังต้นทาง: '.$from_code);
  if (!$to)   throw new RuntimeException('ไม่พบถังปลายทาง: '.$to_code);

  // ต้องเป็นน้ำมันชนิดเดียวกัน
  if ((int)$from['fuel_id'] !== (int)$to['fuel_id']) {
    throw new RuntimeException('ชนิดน้ำมันของถังต้นทาง/ปลายทางต้องเหมือนกัน');
  }

  $from_cur = (float)$from['current_volume_l'];
  $to_cur   = (float)$to['current_volume_l'];
  $to_cap   = (float)$to['capacity_l'];
  $to_max   = (float)$to['max_threshold_l'];

  if ($liters > $from_cur) {
    throw new RuntimeException('สต๊อกต้นทางไม่เพียงพอ');
  }
  if ($to_cap > 0 && ($to_cur + $liters) > $to_cap) {
    throw new RuntimeException('ปริมาณปลายทางเกินความจุถัง');
  }
  if ($to_max > 0 && ($to_cur + $liters) > $to_max) {
    throw new RuntimeException('ปริมาณปลายทางเกินระดับสูงสุดที่กำหนด (Max)');
  }

  // บันทึก movement ออก
  $insOut = $pdo->prepare("
    INSERT INTO fuel_moves(occurred_at,type,tank_id,liters,unit_price,ref_doc,ref_note,user_id)
    VALUES (NOW(),'transfer_out',:tid,:liters,NULL,:doc,:note,:uid)
  ");
  $insOut->execute([
    ':tid'    => $from['id'],
    ':liters' => $liters,
    ':doc'    => $from_code.'→'.$to_code,
    ':note'   => $ref_note ?: null,
    ':uid'    => $user_id
  ]);
  $pairId = (int)$pdo->lastInsertId();

  // บันทึก movement เข้า (อ้างอิงคู่ไว้ใน ref_doc)
  $insIn = $pdo->prepare("
    INSERT INTO fuel_moves(occurred_at,type,tank_id,liters,unit_price,ref_doc,ref_note,user_id)
    VALUES (NOW(),'transfer_in',:tid,:liters,NULL,:doc,:note,:uid)
  ");
  $insIn->execute([
    ':tid'    => $to['id'],
    ':liters' => $liters,
    ':doc'    => 'PAIR#'.$pairId.' '.$from_code.'→'.$to_code,
    ':note'   => $ref_note ?: null,
    ':uid'    => $user_id
  ]);

  // ปรับปริมาณในถัง (ต้นทางลบ / ปลายทางเพิ่ม)
  $upd = $pdo->prepare("UPDATE fuel_tanks SET current_volume_l = current_volume_l + :d, updated_at = NOW() WHERE id = :id");
  $upd->execute([':d' => -$liters, ':id' => $from['id']]);
  $upd->execute([':d' =>  $liters, ':id' => $to['id']]);

  // NOTE: ไม่ต้องแตะ fuel_stock เพราะยอดรวมตามชนิดไม่เปลี่ยนจากการโอนถัง→ถัง

  $pdo->commit();
  redirect('บันทึกโอนย้ายเรียบร้อย');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect(null, $e->getMessage());
}
