<?php
//txn_receipt.php
require_once __DIR__ . '/../config/db.php'; // โหลด PDO ก่อน

$rawCode = trim($_GET['code'] ?? '');
$rawId   = isset($_GET['id']) ? (int)$_GET['id'] : null;

$whereClause = '';
$params      = [];

// [แก้ไข] จัดลำดับความสำคัญ: ใช้ id ก่อน ถ้ามี
if ($rawId) {
  $whereClause = 'ft.id = :lookup_value';
  $params[':lookup_value'] = $rawId;
}
// ถ้าไม่มี id ค่อยมาดู code
elseif ($rawCode !== '') {
  $whereClause = 'ft.transaction_code = :lookup_value';
  $params[':lookup_value'] = $rawCode;
}
// ถ้าไม่มีทั้ง id และ code ก็ถือว่าผิดพลาด
else {
  http_response_code(400);
  exit('ไม่พบพารามิเตอร์ ID หรือ Code ของใบเสร็จ');
}

// [แก้ไข] ใช้ $whereClause ที่เลือกแล้ว
$sql = "
  SELECT ft.*, COALESCE(u.full_name,'-') AS created_by
  FROM financial_transactions ft
  LEFT JOIN users u ON u.id = ft.user_id
  WHERE {$whereClause}
  LIMIT 1
";

try { // [เพิ่ม] try-catch ครอบคลุม query
    $stmt = $pdo->prepare($sql);
    // [แก้ไข] ส่ง $params ที่มี :lookup_value เพียงตัวเดียว
    if (!$stmt->execute($params)) {
        // กรณี execute ล้มเหลว
        $errorInfo = $stmt->errorInfo();
        error_log("Receipt Query Execute Failed: " . ($errorInfo[2] ?? 'Unknown PDO error'));
        http_response_code(500);
        exit('เกิดข้อผิดพลาดในการดึงข้อมูลใบเสร็จ (Execute)');
    }

    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
      http_response_code(404);
      // แสดง ID/Code ที่หาไม่เจอเพื่อช่วยดีบัก
      $notFoundValue = $rawId ?: $rawCode;
      exit('ไม่พบรายการที่ขอ (ID/Code: ' . htmlspecialchars($notFoundValue) . ')');
    }

} catch (PDOException $e) {
    error_log("Receipt Query Prepare/Execute Error: " . $e->getMessage());
    http_response_code(500);
    exit('เกิดข้อผิดพลาดในการดึงข้อมูลใบเสร็จ (PDO)');
} catch (Throwable $e) {
    error_log("Receipt General Error: " . $e->getMessage());
    http_response_code(500);
    exit('เกิดข้อผิดพลาดไม่ทราบสาเหตุ');
}


// เรนเดอร์หลังจากมี $tx แล้ว
$forceType = 'transaction';
require __DIR__ . '/receipt.php';
?>