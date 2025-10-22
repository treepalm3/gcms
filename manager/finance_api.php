<?php
// finance_api.php — JSON API สำหรับ add/edit/delete FT
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($_SESSION['user_id'])) throw new Exception('unauthenticated', 401);
  if (($_SESSION['role'] ?? '') !== 'admin') throw new Exception('forbidden', 403);

  $dbFile = __DIR__ . '/../config/db.php';
  if (!file_exists($dbFile)) $dbFile = __DIR__ . '/config/db.php';
  require_once $dbFile; // $pdo

  // โหลด stationId เดิมจาก settings (ของคุณมีอยู่แล้วใน finance.php)
  $stationId = 1;
  try {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute(); $sid = $st->fetchColumn();
    if ($sid !== false) $stationId = (int)$sid;
  } catch (Throwable $e) {}

  $method = $_SERVER['REQUEST_METHOD'];
  $action = $_GET['action'] ?? '';
  if (!in_array($action, ['create','update','delete','get'])) throw new Exception('unsupported action', 400);

  // ตรวจ CSRF เฉพาะเขียน
  if (in_array($action, ['create','update','delete'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
      throw new Exception('invalid csrf', 403);
    }
  }

  if ($action === 'create' && $method === 'POST') {
    $code = trim($_POST['transaction_code'] ?? '');
    $date = $_POST['transaction_date'] ?? '';
    $type = $_POST['type'] ?? '';
    $cat  = trim($_POST['category'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $amt  = (float)($_POST['amount'] ?? 0);
    $ref  = trim($_POST['reference_id'] ?? '');

    if (!in_array($type, ['income','expense'])) throw new Exception('invalid type', 422);
    if (!$date || !$desc || $amt <= 0) throw new Exception('missing fields', 422);

    if ($code === '') {
      $code = 'FT-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)),0,4);
    }

    $st = $pdo->prepare("INSERT INTO financial_transactions
      (station_id, transaction_code, transaction_date, type, category, description, amount, reference_id, user_id)
      VALUES (:sid, :code, :dt, :type, :cat, :desc, :amt, :ref, :uid)");
    $st->execute([
      ':sid'=>$stationId, ':code'=>$code, ':dt'=>$date, ':type'=>$type, ':cat'=>$cat?:null,
      ':desc'=>$desc, ':amt'=>$amt, ':ref'=>$ref?:null, ':uid'=>$_SESSION['user_id']
    ]);

    echo json_encode(['ok'=>true, 'code'=>$code]); exit;
  }

  if ($action === 'update' && $method === 'POST') {
    $code = $_POST['transaction_code'] ?? '';
    if ($code === '') throw new Exception('missing code', 422);

    $date = $_POST['transaction_date'] ?? null;
    $type = $_POST['type'] ?? null;
    $cat  = isset($_POST['category']) ? trim($_POST['category']) : null;
    $desc = isset($_POST['description']) ? trim($_POST['description']) : null;
    $amt  = isset($_POST['amount']) ? (float)$_POST['amount'] : null;
    $ref  = isset($_POST['reference_id']) ? trim($_POST['reference_id']) : null;

    $fields = []; $params = [':sid'=>$stationId, ':code'=>$code];
    if ($date) { $fields[]='transaction_date=:dt'; $params[':dt']=$date; }
    if ($type && in_array($type,['income','expense'])) { $fields[]='type=:type'; $params[':type']=$type; }
    if ($cat !== null) { $fields[]='category=:cat'; $params[':cat']=$cat?:null; }
    if ($desc !== null){ $fields[]='description=:desc'; $params[':desc']=$desc; }
    if ($amt !== null && $amt>0){ $fields[]='amount=:amt'; $params[':amt']=$amt; }
    if ($ref !== null){ $fields[]='reference_id=:ref'; $params[':ref']=$ref?:null; }

    if (!$fields) throw new Exception('nothing to update', 422);

    $sql = "UPDATE financial_transactions SET ".implode(',',$fields)." WHERE station_id=:sid AND (transaction_code=:code OR CONCAT('FT-',id)=:code)";
    $st = $pdo->prepare($sql); $st->execute($params);

    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'delete' && $method === 'POST') {
    $code = $_POST['transaction_code'] ?? '';
    if ($code === '') throw new Exception('missing code', 422);
    $st = $pdo->prepare("DELETE FROM financial_transactions WHERE station_id=:sid AND (transaction_code=:code OR CONCAT('FT-',id)=:code) LIMIT 1");
    $st->execute([':sid'=>$stationId, ':code'=>$code]);
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'get') {
    $code = $_GET['code'] ?? '';
    if ($code === '') throw new Exception('missing code', 422);
    $st = $pdo->prepare("
      SELECT station_id, transaction_code, transaction_date, type, category, description, amount, reference_id,
             u.full_name AS created_by, ft.created_at
      FROM financial_transactions ft
      LEFT JOIN users u ON u.id = ft.user_id
      WHERE ft.station_id=:sid AND (ft.transaction_code=:code OR CONCAT('FT-',ft.id)=:code)
      LIMIT 1
    ");
    $st->execute([':sid'=>$stationId, ':code'=>$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('not found', 404);
    echo json_encode(['ok'=>true,'data'=>$row]); exit;
  }

} catch (Throwable $e) {
  $code = is_numeric($e->getCode()) ? (int)$e->getCode() : 500;
  http_response_code(($code>=100 && $code<=599)?$code:500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
