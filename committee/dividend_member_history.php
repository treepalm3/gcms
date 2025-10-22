<?php
// manager/dividend_member_history.php
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
  }

  $dbFile = __DIR__ . '/../config/db.php';
  if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
  require_once $dbFile; // $pdo

  $member_id = (int)($_GET['member_id'] ?? 0);
  if ($member_id <= 0) { echo json_encode(['ok'=>true,'history'=>[]]); exit; }

  $sql = "
    SELECT 
      dp.member_id,
      dp.period_id,
      dp.shares_at_time,
      dp.dividend_amount,
      dp.payment_status,
      dp.paid_at,
      d.period_code,
      d.year,
      d.period_name,
      d.dividend_rate
    FROM dividend_payments dp
    JOIN dividend_periods d ON d.id = dp.period_id
    WHERE dp.member_id = :mid
    ORDER BY d.year DESC, d.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':mid'=>$member_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true,'history'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
