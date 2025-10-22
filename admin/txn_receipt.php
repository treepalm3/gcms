<?php
require_once __DIR__ . '/../config/db.php'; // โหลด PDO ก่อน

$rawCode = trim($_GET['code'] ?? '');
$rawId   = isset($_GET['id']) ? (int)$_GET['id'] : null;

$where  = [];
$params = [];

// ถ้ามาแบบ id -> หาโดย id
if ($rawId) {
  $where[] = 'ft.id = :id';
  $params[':id'] = $rawId;
}

// ถ้ามาแบบ code และไม่ใช่ FT-ตัวเลข (กรณีนั้นเราจะส่งเป็น id อยู่แล้ว)
if ($rawCode !== '' && !preg_match('/^FT-\d+$/', $rawCode)) {
  $where[] = 'ft.transaction_code = :code';
  $params[':code'] = $rawCode;
}

if (!$where) { http_response_code(400); exit('ไม่พบพารามิเตอร์ใบเสร็จ'); }

$sql = "
  SELECT ft.*, COALESCE(u.full_name,'-') AS created_by
  FROM financial_transactions ft
  LEFT JOIN users u ON u.id = ft.user_id
  WHERE " . implode(' OR ', $where) . "
  LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tx = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tx) { http_response_code(404); exit('ไม่พบรายการที่ขอ'); }

// เรนเดอร์หลังจากมี $tx แล้ว
$forceType = 'transaction';
require __DIR__ . '/receipt.php';
