<?php
// update_price.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']); exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  http_response_code(403);
  echo json_encode(['error' => 'Forbidden']); exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  http_response_code(400);
  echo json_encode(['error' => 'CSRF token invalid']); exit;
}

require_once __DIR__ . '/../config/db.php';

$fuel_id = trim((string)($_POST['fuel_id'] ?? ''));
$price   = floatval($_POST['price'] ?? -1);

if ($fuel_id === '' || $price < 0) {
  echo json_encode(['error' => 'ข้อมูลไม่ถูกต้อง']); exit;
}

try {
  $pdo->beginTransaction();

  // อัปเดตราคา
  $st = $pdo->prepare("UPDATE fuel_prices SET price=? WHERE fuel_id=?");
  $st->execute([$price, $fuel_id]);

  // ดึงชื่อสำหรับตอบกลับ
  $st = $pdo->prepare("SELECT fuel_name FROM fuel_prices WHERE fuel_id=?");
  $st->execute([$fuel_id]);
  $fuel_name = $st->fetchColumn() ?: $fuel_id;

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'fuel_id' => $fuel_id,
    'fuel_name' => $fuel_name,
    'new_price' => number_format($price, 2, '.', '')
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error' => 'ไม่สามารถบันทึกราคาได้']);
}
