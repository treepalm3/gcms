<?php
// admin/api/search_member.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');

$response = ['error' => 'Invalid request'];

// --- การเชื่อมต่อและความปลอดภัย ---
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    $response['error'] = 'Access Denied';
    echo json_encode($response);
    exit;
}

require_once __DIR__ . '/../../config/db.php'; // ปรับ Path กลับไป 2 ชั้น
if (!isset($pdo)) {
    $response['error'] = 'Database connection failed';
    echo json_encode($response);
    exit;
}

// --- รับค่าค้นหา ---
$term = trim($_GET['term'] ?? '');
if (empty($term) || mb_strlen($term) < 2) {
    $response['error'] = 'Search term is too short';
    echo json_encode($response);
    exit;
}

// เตรียมค่าสำหรับค้นหา
$phone_term = preg_replace('/\D+/', '', $term); // เบอร์โทร (ตัวเลขล้วน)
$house_term = $term; // บ้านเลขที่ (ค้นหาตรงๆ)

try {
    // [แก้ไข] เปลี่ยน SQL ให้ใช้ REGEXP_REPLACE เพื่อลบทุกอย่างที่ไม่ใช่ตัวเลข ออกจาก u.phone
    $phone_compare_sql = "REGEXP_REPLACE(u.phone, '[^0-9]', '') = :phone_term";

    // 1. ค้นหาใน Members
    $sql_member = "
        SELECT u.full_name, u.phone, m.house_number, m.member_code, 'สมาชิก' as type_th
        FROM members m
        JOIN users u ON m.user_id = u.id
        WHERE m.is_active = 1 
          AND (m.house_number = :house_term OR {$phone_compare_sql})
    ";
    
    // 2. ค้นหาใน Managers
    $sql_manager = "
        SELECT u.full_name, u.phone, mg.house_number, CONCAT('MGR-', mg.id) as member_code, 'ผู้บริหาร' as type_th
        FROM managers mg
        JOIN users u ON mg.user_id = u.id
        WHERE (mg.house_number = :house_term OR {$phone_compare_sql})
    ";
    
    // 3. ค้นหาใน Committees
    $sql_committee = "
        SELECT u.full_name, u.phone, c.house_number, c.committee_code as member_code, 'กรรมการ' as type_th
        FROM committees c
        JOIN users u ON c.user_id = u.id
        WHERE (c.house_number = :house_term OR {$phone_compare_sql})
    ";

    // รวม Query ทั้ง 3 ส่วน
    $sql_union = "
        ({$sql_member})
        UNION
        ({$sql_manager})
        UNION
        ({$sql_committee})
        LIMIT 1 
    ";
    
    $stmt = $pdo->prepare($sql_union);
    $stmt->execute([
        ':house_term' => $house_term,
        ':phone_term' => $phone_term
    ]);
    
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        // พบข้อมูล
        echo json_encode([
            'full_name'    => $member['full_name'],
            'phone'        => $member['phone'],
            'house_number' => $member['house_number'],
            'member_code'  => $member['member_code'],
            'type_th'      => $member['type_th']
        ]);
    } else {
        // ไม่พบ
        echo json_encode(['error' => 'Member not found']);
    }

} catch (Throwable $e) {
    error_log("API Search Member Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database query failed: ' . $e->getMessage()]);
}
?>