<?php
// tanks_by_fuel.php — คืนรายการถังตาม fuel_id (ต้องล็อกอินและเป็น admin)
session_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok');

try {
  if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'forbidden']); exit;
  }

  $fuel_id = isset($_GET['fuel_id']) ? (int)$_GET['fuel_id'] : 0;
  if ($fuel_id <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'invalid fuel_id']); exit;
  }

  $dbFile = __DIR__ . '/../config/db.php';
  if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
  require_once $dbFile; // expect $pdo

  // station_id จาก settings (ถ้าไม่มี ใช้ 1)
  $station_id = 1;
  try {
    $sid = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1")->fetchColumn();
    if ($sid !== false) $station_id = (int)$sid;
  } catch (Throwable $e) {}

  $stmt = $pdo->prepare("
    SELECT id, code, name, capacity_l, current_volume_l
    FROM fuel_tanks
    WHERE station_id = ? AND fuel_id = ? AND is_active = 1
    ORDER BY code ASC, id ASC
  ");
  $stmt->execute([$station_id, $fuel_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $tanks = array_map(function($r){
    $cap = (float)$r['capacity_l'];
    $cur = (float)$r['current_volume_l'];
    $remain = max(0.0, $cap - $cur);
    return [
      'id'       => (int)$r['id'],
      'label'    => sprintf('%s (%s) — คงเหลือ %.2f / ความจุ %.0f ลิตร', $r['name'], $r['code'], $remain, $cap),
      'capacity' => $cap,
      'current'  => $cur,
      'free'     => $remain
    ];
  }, $rows);

  echo json_encode(['ok'=>true, 'tanks'=>$tanks], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'server_error']); 
}
