<?php
// dividend_fetch_purchases.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');
require_once '../config/db.php';

$response = ['ok' => false, 'items' => [], 'error' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    $response['error'] = 'Access denied';
    echo json_encode($response);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json);
$csrf_token = $data->csrf_token ?? '';

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $response['error'] = 'CSRF token mismatch';
    echo json_encode($response);
    exit;
}

$start_date = $data->start_date ?? null;
$end_date = $data->end_date ?? null;

if (!$start_date || !$end_date) {
    $response['error'] = 'Missing date range';
    echo json_encode($response);
    exit;
}

try {
    // Query เดิมที่ใช้ดึงยอดซื้อ
    $sql_purchases = "
        SELECT 
            COALESCE(m.id, mg.id, c.id) AS member_id,
            COALESCE(m.member_type, mg.member_type, c.member_type) AS member_type,
            SUM(s.total_amount) as total_purchase
        FROM sales s
        JOIN users u ON s.customer_phone = u.phone
        LEFT JOIN (SELECT id, user_id, 'member' as member_type FROM members WHERE is_active = 1) m ON u.id = m.user_id
        LEFT JOIN (SELECT id, user_id, 'manager' as member_type FROM managers) mg ON u.id = mg.user_id
        LEFT JOIN (SELECT id, user_id, 'committee' as member_type FROM committees) c ON u.id = c.user_id
        WHERE s.sale_date BETWEEN ? AND ?
          AND s.customer_phone IS NOT NULL
          AND COALESCE(m.id, mg.id, c.id) IS NOT NULL
        GROUP BY member_id, member_type
        HAVING total_purchase > 0
    ";
    
    $stmt_purchases = $pdo->prepare($sql_purchases);
    $stmt_purchases->execute([$start_date, $end_date]);
    $all_purchases = $stmt_purchases->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($all_purchases as $row) {
        $items[] = [
            'key' => $row['member_type'] . '_' . $row['member_id'],
            'amount' => (float)$row['total_purchase']
        ];
    }
    
    $response['ok'] = true;
    $response['items'] = $items;
    $response['error'] = '';

} catch (Throwable $e) {
    error_log("Fetch Purchases Error: " . $e->getMessage());
    $response['error'] = 'Database query failed: ' . $e->getMessage();
}

echo json_encode($response);
?>