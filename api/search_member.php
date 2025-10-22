<?php
// /api/search_member.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../config/db.php';

// ตรวจสอบการล็อกอิน
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$term = trim($_GET['term'] ?? '');

if (strlen($term) < 3) {
    echo json_encode(['error' => 'Search term too short']);
    exit;
}

try {
    // Normalize term (เอาเฉพาะตัวเลข)
    $term_digits = preg_replace('/\D+/', '', $term);
    
    // ค้นหาจากเบอร์โทร หรือ บ้านเลขที่
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.full_name,
            u.phone,
            m.house_number,
            m.id as member_id
        FROM users u
        INNER JOIN members m ON m.user_id = u.id
        WHERE m.is_active = 1
          AND (
              (u.phone IS NOT NULL AND REPLACE(REPLACE(REPLACE(REPLACE(u.phone, '-', ''), ' ', ''), '(', ''), ')', '') LIKE :phone_pattern)
              OR (m.house_number IS NOT NULL AND m.house_number LIKE :house_pattern)
          )
        ORDER BY 
            CASE 
                WHEN REPLACE(REPLACE(REPLACE(REPLACE(u.phone, '-', ''), ' ', ''), '(', ''), ')', '') = :phone_exact THEN 1
                WHEN m.house_number = :house_exact THEN 2
                ELSE 3
            END
        LIMIT 1
    ");
    
    $stmt->execute([
        ':phone_pattern' => "%{$term_digits}%",
        ':house_pattern' => "%{$term}%",
        ':phone_exact'   => $term_digits,
        ':house_exact'   => $term
    ]);
    
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($member) {
        echo json_encode([
            'id' => (int)$member['id'],
            'member_id' => (int)$member['member_id'],
            'full_name' => $member['full_name'],
            'phone' => $member['phone'] ?? '',
            'house_number' => $member['house_number'] ?? ''
        ]);
    } else {
        echo json_encode(['error' => 'Member not found']);
    }
    
} catch (PDOException $e) {
    error_log("Search member error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}