<?php
// admin/auto_topup.php
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
  }

  $csrf = $_POST['csrf_token'] ?? '';
  if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'csrf']); exit;
  }

  $fuel_id = (int)($_POST['fuel_id'] ?? 0);
  if ($fuel_id <= 0) { echo json_encode(['ok'=>false,'error'=>'invalid_fuel_id']); exit; }

  // DB
  $dbFile = __DIR__ . '/../config/db.php';
  if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
  require_once $dbFile;
  if (!isset($pdo) || !($pdo instanceof PDO)) { throw new Exception('DB connect error'); }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // ฟังก์ชันหาสถานี (ตามที่คุณใช้ settings.station_id)
  $station_id = 1;
  $stm = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
  if ($stm->execute() && ($v = $stm->fetchColumn())) $station_id = (int)$v;

  // เริ่มทรานแซกชัน
  $pdo->beginTransaction();

  // ล็อกสต็อกสำรองของเชื้อเพลิงนี้
  // หมายเหตุ: จากสคีมาที่ให้มา fuel_stock ไม่มี station_id
  $stStock = $pdo->prepare("SELECT current_stock FROM fuel_stock WHERE fuel_id = :fid FOR UPDATE");
  $stStock->execute([':fid'=>$fuel_id]);
  $rowStock = $stStock->fetch(PDO::FETCH_ASSOC);
  $available = $rowStock ? (float)$rowStock['current_stock'] : 0.0;

  if ($available <= 0) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>'สต็อกสำรอง (fuel_stock) ไม่พอ']); exit;
  }

  // ล็อคถังทั้งหมดของเชื้อนี้ในสถานีนี้ (เรียงจาก % เต็มน้อยสุดก่อน)
  $stTanks = $pdo->prepare("
    SELECT id, capacity_l, max_threshold_l, current_volume_l
    FROM fuel_tanks
    WHERE station_id = :st AND fuel_id = :fid AND is_active = 1
    ORDER BY (CASE WHEN capacity_l>0 THEN current_volume_l/capacity_l ELSE 1 END) ASC
    FOR UPDATE
  ");
  $stTanks->execute([':st'=>$station_id, ':fid'=>$fuel_id]);
  $tanks = $stTanks->fetchAll(PDO::FETCH_ASSOC);

  if (empty($tanks)) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>'ไม่มีถังสำหรับเชื้อเพลิงนี้']); exit;
  }

  $allocs = []; // รายการจัดสรรต่อตถัง
  $moved_total = 0.0;
  $remaining = $available;

  foreach ($tanks as $t) {
    $tankId = (int)$t['id'];
    $cap = (float)$t['capacity_l'];
    $maxThr = (float)($t['max_threshold_l'] ?? 0);
    $curr = (float)$t['current_volume_l'];

    if ($cap <= 0) continue;
    $target = $maxThr > 0 ? min($maxThr, $cap) : $cap;
    $need = max(0.0, $target - $curr);
    if ($need <= 0) continue;

    $move = min($need, $remaining);
    if ($move <= 0) break;

    // อัปเดตถัง
    $u1 = $pdo->prepare("UPDATE fuel_tanks SET current_volume_l = current_volume_l + :m, updated_at = NOW() WHERE id = :id");
    $u1->execute([':m'=>$move, ':id'=>$tankId]);

    // บันทึก movement (ถือเป็น transfer_in เข้าถังขาย)
    $ins = $pdo->prepare("
      INSERT INTO fuel_moves (occurred_at, type, tank_id, liters, unit_price, ref_doc, ref_note, user_id)
      VALUES (NOW(), 'transfer_in', :tank, :l, NULL, 'AUTO-TOPUP', 'เติมจาก fuel_stock โดยระบบ', :uid)
    ");
    $ins->execute([':tank'=>$tankId, ':l'=>$move, ':uid'=>$_SESSION['user_id']]);

    $allocs[] = ['tank_id'=>$tankId, 'liters'=>$move];
    $moved_total += $move;
    $remaining -= $move;

    if ($remaining <= 0) break;
  }

  if ($moved_total <= 0) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>'ถังเต็มตามระดับเป้าหมาย หรือไม่ต้องเติม']); exit;
  }

  // ตัดสต็อกสำรอง
  $uStock = $pdo->prepare("UPDATE fuel_stock SET current_stock = current_stock - :mv WHERE fuel_id = :fid");
  $uStock->execute([':mv'=>$moved_total, ':fid'=>$fuel_id]);

  $pdo->commit();

  echo json_encode([
    'ok'=>true,
    'moved_total'=>$moved_total,
    'allocations'=>$allocs,
    'stock_remaining'=>$remaining
  ]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'internal_error','detail'=>$e->getMessage()]);
}
