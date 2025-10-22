<?php
// recent_receive.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['error' => 'Forbidden']); exit;
}

require_once __DIR__ . '/../config/db.php';

try {
  $st = $pdo->query("
    SELECT r.created_at, r.amount, r.cost_price, r.notes,
           fp.fuel_name,
           s.supplier_name,
           r.created_by
    FROM fuel_receives r
    JOIN fuel_prices fp ON fp.fuel_id = r.fuel_id
    LEFT JOIN suppliers s ON s.supplier_id = r.supplier_id
    ORDER BY r.created_at DESC
    LIMIT 5
  ");
  $rows = $st->fetchAll();

  echo json_encode(['ok'=>true, 'items'=>$rows]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'โหลดข้อมูลไม่สำเร็จ']);
}
