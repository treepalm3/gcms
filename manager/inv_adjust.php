<?php
// manager/inv_adjust.php — ปรับยอดสต๊อก (สำหรับผู้บริหาร)
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ====== ตรวจสิทธิ์ + CSRF ====== */
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'manager')) {
  header('Location: /index/login.php?err=สิทธิ์ไม่พอ'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: inventory.php?err=วิธีการเรียกไม่ถูกต้อง'); exit;
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
  header('Location: inventory.php?err=CSRF ไม่ถูกต้อง'); exit;
}

/* ====== เชื่อมฐานข้อมูล ====== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) {
  header('Location: inventory.php?err=เชื่อมต่อฐานข้อมูลไม่ได้'); exit;
}

/* ====== helper redirect ====== */
function redirect($ok=null,$err=null){
  $q = $ok ? ('ok='.urlencode($ok)) : ('err='.urlencode($err));
  header('Location: inventory.php?'.$q); exit;
}

/* ====== อ่านค่าจากฟอร์ม ====== */
try {
  $tank_id   = isset($_POST['tank_id']) ? (int)$_POST['tank_id'] : null;
  $tank_code = trim($_POST['tank_code'] ?? '');
  $adj_type  = ($_POST['adj_type'] ?? 'plus') === 'minus' ? 'minus' : 'plus';
  $liters    = (float)($_POST['liters'] ?? 0);
  $ref_note  = trim($_POST['ref_note'] ?? '');
  $user_id   = (int)($_SESSION['user_id']);

  if ($liters <= 0) throw new RuntimeException('จำนวนลิตรต้องมากกว่า 0');
  if (!$tank_id && $tank_code === '') throw new RuntimeException('กรุณาเลือกถัง');

  // หา station_id (จาก session ก่อน, ไม่มีก็ดึงจาก settings, สุดท้าย fallback = 1)
  $station_id = isset($_SESSION['station_id']) ? (int)$_SESSION['station_id'] : 1;
  if ($station_id <= 0) {
    try {
      $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
      $sid = $st ? $st->fetchColumn() : false;
      if ($sid !== false) $station_id = (int)$sid;
      if ($station_id <= 0) $station_id = 1;
    } catch (Throwable $e) { $station_id = 1; }
  }

  $pdo->beginTransaction();

  // ดึงข้อมูลถัง + lock แถว
  if ($tank_id) {
    $st = $pdo->prepare("
      SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l
      FROM fuel_tanks
      WHERE id = :id AND station_id = :sid
      FOR UPDATE
    ");
    $st->execute([':id'=>$tank_id, ':sid'=>$station_id]);
  } else {
    $st = $pdo->prepare("
      SELECT id, fuel_id, code, capacity_l, max_threshold_l, current_volume_l
      FROM fuel_tanks
      WHERE code = :c AND station_id = :sid
      FOR UPDATE
    ");
    $st->execute([':c'=>$tank_code, ':sid'=>$station_id]);
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

  // บันทึก movement
  $type = $adj_type === 'minus' ? 'adjust_minus' : 'adjust_plus';
  $pdo->prepare("
    INSERT INTO fuel_moves(occurred_at,type,tank_id,liters,unit_price,ref_doc,ref_note,user_id)
    VALUES (NOW(),:type,:tid,:liters,NULL,NULL,NULLIF(:note,''),:uid)
  ")->execute([
    ':type'=>$type, ':tid'=>$tank['id'], ':liters'=>$liters, ':note'=>$ref_note, ':uid'=>$user_id
  ]);

  // อัปเดตปริมาตรถัง
  $delta = ($adj_type === 'minus') ? -$liters : $liters;
  $pdo->prepare("
    UPDATE fuel_tanks
       SET current_volume_l = current_volume_l + :d,
           updated_at = NOW()
     WHERE id = :tid
  ")->execute([':d'=>$delta, ':tid'=>$tank['id']]);

  // อัปเดตรวมใน fuel_stock แบบแยกสถานี
  $pdo->prepare("
    INSERT INTO fuel_stock (fuel_id, station_id, current_stock, capacity, min_threshold, max_threshold, last_refill_date, last_refill_amount)
    VALUES (:fid, :sid, :d, 0, 0, 0, NULL, NULL)
    ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)
  ")->execute([':fid'=>$tank['fuel_id'], ':sid'=>$station_id, ':d'=>$delta]);

  $pdo->commit();
  redirect('บันทึกปรับยอดเรียบร้อย');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect(null, $e->getMessage());
}
