<?php
// admin/api/save_settings.php
session_start();
header('Content-Type: application/json; charset=utf-8');

$dbFile = __DIR__ . '/../../config/db.php';
require_once $dbFile; // $pdo

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
  exit;
}

if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_csrf']);
  exit;
}

function save_settings(PDO $pdo, string $key, array $data): bool {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $st = $pdo->prepare("
    INSERT INTO app_settings (`key`, json_value) VALUES (:k, CAST(:v AS JSON))
    ON DUPLICATE KEY UPDATE json_value = CAST(:v AS JSON)
  ");
  return $st->execute([':k'=>$key, ':v'=>$json]);
}

$group = $_POST['group'] ?? '';
$data  = $_POST['data'] ?? '';

if (!$group || !$data) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_params']);
  exit;
}

$payload = json_decode($data, true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'bad_json']);
  exit;
}

try {
  $ok = save_settings($pdo, $group, $payload);
  echo json_encode(['ok'=>$ok]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
