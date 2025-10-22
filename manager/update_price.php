<?php
// manager/update_price.php — อัปเดตราคาน้ำมัน (JSON)
// รองรับสิทธิ์ manager และ admin

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false, 'error'=>'Method not allowed']); exit;
}

if (empty($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? ''), ['manager','admin'], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'error'=>'Forbidden']); exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'CSRF token invalid']); exit;
}

// DB
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'เชื่อมต่อฐานข้อมูลไม่สำเร็จ']); exit;
}

// Helpers
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:c');
    $st->execute([':db'=>$db, ':tb'=>$table, ':c'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

// รับค่าจากฟอร์ม
$fuel_id_raw = trim((string)($_POST['fuel_id'] ?? ''));
$price       = isset($_POST['price']) ? (float)$_POST['price'] : -1;
$user_id     = (int)($_SESSION['user_id'] ?? 0);

if ($fuel_id_raw === '' || $price < 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'ข้อมูลไม่ถูกต้อง']); exit;
}

// ตรวจ station_id (ถ้ามีใน settings)
$station_id = 1;
try {
  if (table_exists($pdo,'settings') && column_exists($pdo,'settings','setting_name')) {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    $sid = $st->fetchColumn();
    if ($sid !== false) $station_id = (int)$sid;
  }
} catch (Throwable $e) {}

// เตรียม WHERE ให้สอดคล้อง schema
$hasStationCol = column_exists($pdo, 'fuel_prices', 'station_id');

// ตรวจสอบว่ามีรายการน้ำมันนี้จริง
try {
  $pdo->beginTransaction();

  $params = [':fid' => $fuel_id_raw];
  $where  = "fuel_id = :fid";
  if ($hasStationCol) { $where .= " AND station_id = :sid"; $params[':sid'] = $station_id; }

  // เลือกชื่อและราคาปัจจุบัน
  $sel = $pdo->prepare("SELECT fuel_name, price FROM fuel_prices WHERE $where LIMIT 1");
  $sel->execute($params);
  $row = $sel->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok'=>false, 'error'=>'ไม่พบรายการน้ำมัน']); exit;
  }

  $fuel_name = (string)($row['fuel_name'] ?? $fuel_id_raw);
  $old_price = (float)($row['price'] ?? 0);

  // สร้าง SET fields ตามคอลัมน์ที่มีอยู่จริง
  $setFields = ["price = :p"];
  $updParams = $params + [':p' => $price];
  if (column_exists($pdo,'fuel_prices','updated_at')) { $setFields[] = "updated_at = NOW()"; }
  if (column_exists($pdo,'fuel_prices','updated_by')) { $setFields[] = "updated_by = :uid"; $updParams[':uid'] = $user_id; }

  $sqlUpd = "UPDATE fuel_prices SET ".implode(', ', $setFields)." WHERE $where";
  $upd = $pdo->prepare($sqlUpd);
  $upd->execute($updParams);

  // (ถ้ามีตารางประวัติ ให้บันทึกไว้)
  if (table_exists($pdo,'fuel_price_history')) {
    $cols = ['fuel_id','old_price','new_price','changed_at','user_id','station_id'];
    $has  = [];
    foreach ($cols as $c) { $has[$c] = column_exists($pdo,'fuel_price_history',$c); }

    $insCols = [];
    $insVals = [];
    $insPrms = [];

    if ($has['fuel_id'])    { $insCols[]='fuel_id';    $insVals[]=':fid';  $insPrms[':fid']=$fuel_id_raw; }
    if ($has['old_price'])  { $insCols[]='old_price';  $insVals[]=':op';   $insPrms[':op']=$old_price; }
    if ($has['new_price'])  { $insCols[]='new_price';  $insVals[]=':np';   $insPrms[':np']=$price; }
    if ($has['changed_at']) { $insCols[]='changed_at'; $insVals[]='NOW()'; }
    if ($has['user_id'])    { $insCols[]='user_id';    $insVals[]=':uid';  $insPrms[':uid']=$user_id; }
    if ($has['station_id']) { $insCols[]='station_id'; $insVals[]=':sid';  $insPrms[':sid']=$station_id; }

    if ($insCols) {
      $ins = $pdo->prepare("INSERT INTO fuel_price_history (".implode(',',$insCols).") VALUES (".implode(',',$insVals).")");
      $ins->execute($insPrms);
    }
  }

  $pdo->commit();

  echo json_encode([
    'ok'        => true,
    'fuel_id'   => $fuel_id_raw,
    'fuel_name' => $fuel_name,
    'new_price' => number_format($price, 2, '.', '')
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'ไม่สามารถบันทึกราคาได้']);
}
