<?php
// admin/api/search_member.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');

$response = ['error' => 'Invalid request'];

// --- การเชื่อมต่อและความปลอดภัย ---
// [แก้ไข] ตรวจสอบทั้ง Session ปกติ หรือ Kiosk Session
$is_logged_in_session = (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'employee');
$is_logged_in_kiosk = (isset($_SESSION['kiosk_emp_id']) && (int)$_SESSION['kiosk_emp_id'] > 0);

if (!$is_logged_in_session && !$is_logged_in_kiosk) {
    $response['error'] = 'Access Denied (API)';
    echo json_encode($response);
    exit;
}

require_once __DIR__ . '/../../config/db.php'; 
if (!isset($pdo)) {
    $response['error'] = 'Database connection failed';
    echo json_encode($response);
    exit;
}

// --- รับค่าค้นหา ---
$term = trim($_GET['term'] ?? '');
if (empty($term)) {
    $response['error'] = 'Search term is empty';
    echo json_encode($response);
    exit;
}

// เตรียมค่าสำหรับค้นหา
$phone_term = preg_replace('/\D+/', '', $term); // เบอร์โทร (ตัวเลขล้วน)
$house_term = $term; // บ้านเลขที่ (ค้นหาตรงๆ)

try {
    // [แก้ไข] ใช้ชื่อ Placeholder ที่ไม่ซ้ำกันสำหรับ UNION
    
    $phone_compare_sql = "REGEXP_REPLACE(u.phone, '[^0-9]', '')";

    // 1. ค้นหาใน Members
    $sql_member = "
        SELECT u.full_name, u.phone, m.house_number, m.member_code, 'สมาชิก' as type_th, 
               m.id as member_pk -- [เพิ่ม] ดึง member_id จากตาราง members
        FROM members m
        JOIN users u ON m.user_id = u.id
        WHERE m.is_active = 1 
          AND (TRIM(m.house_number) = :house_term_1 OR {$phone_compare_sql} = :phone_term_1)
    ";
    
    // 2. ค้นหาใน Managers
    $sql_manager = "
        SELECT u.full_name, u.phone, mg.house_number, CONCAT('MGR-', mg.id) as member_code, 'ผู้บริหาร' as type_th,
               m.id as member_pk -- [เพิ่ม] ดึง member_id จากตาราง members
        FROM managers mg
        JOIN users u ON mg.user_id = u.id
        LEFT JOIN members m ON u.id = m.user_id AND m.is_active = 1 -- [เพิ่ม] Join ตาราง members
        WHERE (TRIM(mg.house_number) = :house_term_2 OR {$phone_compare_sql} = :phone_term_2)
    ";
    
    // 3. ค้นหาใน Committees
    $sql_committee = "
        SELECT u.full_name, u.phone, c.house_number, c.committee_code as member_code, 'กรรมการ' as type_th,
               m.id as member_pk -- [เพิ่ม] ดึง member_id จากตาราง members
        FROM committees c
        JOIN users u ON c.user_id = u.id
        LEFT JOIN members m ON u.id = m.user_id AND m.is_active = 1 -- [เพิ่ม] Join ตาราง members
        WHERE (TRIM(c.house_number) = :house_term_3 OR {$phone_compare_sql} = :phone_term_3)
    ";

    // รวม Query
    $sql_union = "
        ({$sql_member})
        UNION
        ({$sql_manager})
        UNION
        ({$sql_committee})
        LIMIT 1 
    ";
    
    $stmt = $pdo->prepare($sql_union);
    
    // [แก้ไข] ส่งค่า Parameter ให้ครบทุกตัว (แม้ว่าจะเป็นค่าเดียวกัน)
    $stmt->execute([
        ':house_term_1' => $house_term,
        ':phone_term_1' => $phone_term,
        ':house_term_2' => $house_term,
        ':phone_term_2' => $phone_term,
        ':house_term_3' => $house_term,
        ':phone_term_3' => $phone_term
    ]);
    
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        // พบข้อมูล
        echo json_encode([
            'full_name'    => $member['full_name'],
            'phone'        => $member['phone'],
            'house_number' => $member['house_number'],
            'member_code'  => $member['member_code'],
            'type_th'      => $member['type_th'],
            'member_pk'    => $member['member_pk'] // [เพิ่ม] ส่ง ID จากตาราง members กลับไป
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