<?php
// manager/inv_transfer.php — โอนย้ายสต๊อกระหว่างถัง (สำหรับผู้บริหาร)
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
require_once $dbFile; // ต้องมีตัวแปร $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) {
  header('Location: inventory.php?err=เชื่อมต่อฐานข้อมูลไม่ได้'); exit;
}

/* ===== helpers ===== */
function redirect($ok=null,$err=null){
  $q = $ok ? ('ok='.urlencode($ok)) : ('err='.urlencode($err));
  header('Location: inventory.php?'.$q); exit;
}
function table_exists(PDO $pdo, string $table): bool {
  try{
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb");
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  }catch(Throwable $e){ return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try{
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:col");
    $st->execute([':db'=>$db, ':tb'=>$table, ':col'=>$col]);
    return (int)$st->fetchColumn() > 0;
  }catch(Throwable $e){ return false; }
}
function get_station_id(PDO $pdo): int {
  if (!empty($_SESSION['station_id'])) return (int)$_SESSION['station_id'];
  try{
    $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
    $sid = $st ? $st->fetchColumn() : false;
    return $sid !== false ? (int)$sid : 1;
  }catch(Throwable $e){ return 1; }
}

/* ===== รับค่าจากฟอร์ม ===== */
try {
  $from_code = trim($_POST['from_tank'] ?? '');
  $to_code   = trim($_POST['to_tank'] ?? '');
  $liters    = (float)($_POST['liters'] ?? 0);
  $ref_note  = trim($_POST['ref_note'] ?? '');
  $user_id   = (int)($_SESSION['user_id'] ?? 0);

  if (!$from_code || !$to_code || $from_code === $to_code || $liters <= 0) {
    throw new RuntimeException('ข้อมูลโอนไม่ครบหรือไม่ถูกต้อง');
  }

  $station_id = get_station_id($pdo);
  $has_sid_tanks = column_exists($pdo,'fuel_tanks','station_id');

  $pdo->beginTransaction();

  // ล็อกแถวถังทั้งสองใบ (กรองตามสถานีถ้ามีคอลัมน์)
  if ($has_sid_tanks) {
    $st = $pdo->prepare("
      SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l, station_id
      FROM fuel_tanks
      WHERE code IN (?, ?) AND station_id = ?
      FOR UPDATE
    ");
    $st->execute([$from_code, $to_code, $station_id]);
  } else {
    $st = $pdo->prepare("
      SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l
      FROM fuel_tanks
      WHERE code IN (?, ?)
      FOR UPDATE
    ");
    $st->execute([$from_code, $to_code]);
  }
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

  // บันทึก movement ออก (transfer_out)
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

  // บันทึก movement เข้า (transfer_in) อ้างอิงคู่ผ่าน ref_doc
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

  // อัปเดตปริมาณในถัง (ต้นทางลบ / ปลายทางเพิ่ม)
  $upd = $pdo->prepare("UPDATE fuel_tanks SET current_volume_l = current_volume_l + :d, updated_at = NOW() WHERE id = :id");
  $upd->execute([':d' => -$liters, ':id' => $from['id']]);
  $upd->execute([':d' =>  $liters, ':id' => $to['id']]);

  // ไม่ต้องแตะ fuel_stock — ยอดรวมตามชนิดไม่เปลี่ยนจากการโอนถัง→ถัง

  $pdo->commit();
  redirect('บันทึกโอนย้ายเรียบร้อย');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect(null, $e->getMessage());
}
