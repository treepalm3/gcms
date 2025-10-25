<?php
//txn_receipt.php
session_start(); // เริ่ม session ถ้าจำเป็นต้องใช้ข้อมูลผู้ใช้ ฯลฯ
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอิน/สิทธิ์ หากจำเป็นสำหรับการดูใบเสร็จ
if (!isset($_SESSION['user_id'])) {
    // อาจจะ redirect ไปหน้า login หรือแสดงข้อผิดพลาด
    http_response_code(403);
    exit('เข้าถึงถูกปฏิเสธ กรุณาเข้าสู่ระบบ');
}
// คุณอาจต้องการตรวจสอบ role ที่เจาะจงมากขึ้นที่นี่ด้วย

require_once __DIR__ . '/../config/db.php'; // โหลดการเชื่อมต่อ PDO

// --- การจัดการ Parameter ---
$rawCode = trim($_GET['code'] ?? '');
$rawId   = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null; // ตรวจสอบว่าเป็น integer

$whereClause = '';
$params      = [];

// ให้ความสำคัญกับ ID ก่อน หากมีและถูกต้อง
if ($rawId !== null && $rawId !== false && $rawId > 0) {
  $whereClause = 'ft.id = :lookup_value';
  $params[':lookup_value'] = $rawId;
}
// ถ้าไม่มี ID หรือ ID ไม่ถูกต้อง ค่อยไปดู Code
elseif ($rawCode !== '') {
  $whereClause = 'ft.transaction_code = :lookup_value';
  $params[':lookup_value'] = $rawCode;
}
// ถ้าไม่มีทั้ง ID และ Code ที่ถูกต้อง ถือว่าผิดพลาด
else {
  http_response_code(400);
  exit('ไม่พบพารามิเตอร์ ID หรือ Code ของใบเสร็จที่ถูกต้อง');
}

// --- การ Query ฐานข้อมูล ---
$sql = "
  SELECT
    ft.*,
    COALESCE(u.full_name,'-') AS created_by_name, /* ตั้งชื่อแฝงให้ชื่อผู้สร้าง */
    s.site_name, /* ดึงชื่อไซต์จาก settings */
    s.address as site_address, /* ดึงที่อยู่ไซต์ */
    s.contact_phone as site_phone, /* ดึงเบอร์โทรไซต์ */
    s.tax_id as site_tax_id /* ดึงเลขผู้เสียภาษีไซต์ */
  FROM financial_transactions ft
  LEFT JOIN users u ON u.id = ft.user_id
  LEFT JOIN ( /* Subquery หรือ JOIN เพื่อดึง settings */
      SELECT
          MAX(CASE WHEN `key` = 'system_settings' THEN JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.site_name')) END) as site_name,
          MAX(CASE WHEN `key` = 'system_settings' THEN JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.address')) END) as address,
          MAX(CASE WHEN `key` = 'system_settings' THEN JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.contact_phone')) END) as contact_phone,
          MAX(CASE WHEN `key` = 'system_settings' THEN JSON_UNQUOTE(JSON_EXTRACT(json_value, '$.tax_id')) END) as tax_id
      FROM app_settings
      WHERE `key` = 'system_settings'
  ) s ON 1=1 /* Join settings เข้ามาเสมอ */
  WHERE {$whereClause}
  LIMIT 1
";

$tx = null; // กำหนดค่าเริ่มต้นให้ตัวแปร transaction

try {
    $stmt = $pdo->prepare($sql);

    // Execute คำสั่ง SQL ด้วย parameter เพียงตัวเดียว
    if (!$stmt->execute($params)) {
        // จัดการกรณี execute ล้มเหลว
        $errorInfo = $stmt->errorInfo();
        error_log("Receipt Query Execute Failed: " . ($errorInfo[2] ?? 'Unknown PDO error') . " | SQL: " . $sql . " | Params: " . print_r($params, true));
        http_response_code(500);
        // แสดงข้อความผิดพลาดที่ผู้ใช้เข้าใจง่าย
        $errorMessage = 'เกิดข้อผิดพลาดในการดึงข้อมูลใบเสร็จ (Execute)';
        // อาจจะใส่รายละเอียด error เพิ่มเติมถ้าอยู่ในโหมด debug (ทางเลือก)
        // if (defined('DEBUG_MODE') && DEBUG_MODE) { $errorMessage .= ': ' . ($errorInfo[2] ?? 'Unknown PDO error'); }
        exit($errorMessage);
    }

    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
      http_response_code(404);
      // แสดงค่า ID/Code ที่หาไม่เจอเพื่อช่วยดีบัก
      $notFoundValue = $rawId ?: $rawCode;
      exit('ไม่พบรายการที่ขอ (ID/Code: ' . htmlspecialchars($notFoundValue) . ')');
    }

} catch (PDOException $e) { // ดักจับข้อผิดพลาด PDO (เช่น ตอน prepare/execute)
    error_log("Receipt Query Prepare/Execute Error: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . print_r($params, true));
    http_response_code(500);
    exit('เกิดข้อผิดพลาดในการดึงข้อมูลใบเสร็จ (PDO)');
} catch (Throwable $e) { // ดักจับข้อผิดพลาดทั่วไปอื่นๆ
    error_log("Receipt General Error: " . $e->getMessage());
    http_response_code(500);
    exit('เกิดข้อผิดพลาดไม่ทราบสาเหตุ');
}

// --- เตรียมข้อมูลสำหรับ Template ใบเสร็จ ---
// ตอนนี้คุณควรจะมีรายละเอียดรายการอยู่ใน array $tx แล้ว
// เช่น: $tx['transaction_code'], $tx['transaction_date'], $tx['amount'], etc.
// เพิ่มข้อมูลไซต์จาก query
$site_name = $tx['site_name'] ?? 'สหกรณ์ฯ'; // ใช้ค่า default ถ้าไม่พบ setting
$site_address = $tx['site_address'] ?? '-';
$site_phone = $tx['site_phone'] ?? '-';
$site_tax_id = $tx['site_tax_id'] ?? '-';

// --- Include Template ใบเสร็จ ---
// ส่วนนี้สมมติว่าคุณมีไฟล์ `receipt.php` แยกต่างหากสำหรับจัดการ layout HTML
// มันจะใช้ตัวแปรที่กำหนดไว้ข้างบน ($tx, $site_name, etc.).
$forceType = 'transaction'; // บอก receipt.php ว่านี่คือใบเสร็จประเภทไหน
require __DIR__ . '/receipt.php';
?>