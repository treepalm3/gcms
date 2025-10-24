<?php
// dividend_member_history.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');
require_once '../config/db.php';

$response = ['ok' => false, 'history' => [], 'summary' => [], 'error' => 'Invalid request'];

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    $response['error'] = 'Access denied';
    echo json_encode($response); exit;
}

$member_id = (int)($_GET['member_id'] ?? 0);
$member_type = trim($_GET['member_type'] ?? '');

if ($member_id <= 0 || empty($member_type)) {
    $response['error'] = 'Invalid member parameters';
    echo json_encode($response); exit;
}

try {
    // 1. ดึงประวัติปันผล (Dividend)
    $sql_dividend = "
        SELECT 
            p.year, p.period_name, p.dividend_rate, 
            dp.shares_at_time, dp.dividend_amount, dp.payment_status, p.payment_date
        FROM dividend_payments dp
        JOIN dividend_periods p ON dp.period_id = p.id
        WHERE dp.member_id = ? AND dp.member_type = ?
        ORDER BY p.year DESC
    ";
    $stmt_div = $pdo->prepare($sql_dividend);
    $stmt_div->execute([$member_id, $member_type]);
    $dividend_history = $stmt_div->fetchAll(PDO::FETCH_ASSOC);

    // 2. ดึงประวัติเฉลี่ยคืน (Rebate) - (ถ้ามีตาราง)
    $rebate_history = [];
    try {
        $sql_rebate = "
            SELECT 
                p.year, p.period_name, p.rebate_per_baht, p.total_rebate_budget,
                rp.purchase_amount_at_time, rp.rebate_amount, rp.payment_status, p.payment_date
            FROM rebate_payments rp
            JOIN rebate_periods p ON rp.period_id = p.id
            WHERE rp.member_id = ? AND rp.member_type = ?
            ORDER BY p.year DESC
        ";
        $stmt_reb = $pdo->prepare($sql_rebate);
        $stmt_reb->execute([$member_id, $member_type]);
        $rebate_history = $stmt_reb->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ตาราง rebate_payments อาจยังไม่มี */ }

    // 3. เตรียมข้อมูลส่งกลับ (ในตัวอย่างนี้ ขอส่งแค่ปันผลก่อน)
    // *** คุณต้องปรับปรุงส่วนนี้เพื่อรวม $rebate_history เข้าไปด้วย ***
    
    $history_list = [];
    $total_received = 0;
    $total_pending = 0;
    
    foreach ($dividend_history as $item) {
        $status = $item['payment_status'];
        $amount = (float)$item['dividend_amount'];
        
        if ($status == 'paid') {
            $total_received += $amount;
        } else {
            $total_pending += $amount;
        }
        
        $history_list[] = [
            'year' => $item['year'],
            'period_name' => $item['period_name'],
            'shares_at_time' => (int)$item['shares_at_time'],
            'dividend_rate' => (float)$item['dividend_rate'],
            'dividend_amount' => $amount,
            'dividend_amount_formatted' => '฿' . nf($amount),
            'payment_status' => $status,
            'payment_date_formatted' => ($status == 'paid' && $item['payment_date']) ? d($item['payment_date']) : '-'
        ];
    }
    
    // (เพิ่ม Loop สำหรับ $rebate_history ที่นี่ และบวกยอด vào $total_received / $total_pending)

    $response['ok'] = true;
    $response['history'] = $history_list;
    $response['summary'] = [
        'total_received' => $total_received,
        'total_pending' => $total_pending,
        'total_received_formatted' => '฿' . nf($total_received),
        'total_pending_formatted' => '฿' . nf($total_pending),
        'payment_count' => count($history_list) // (ต้องบวก count ของ rebate ด้วย)
    ];
    $response['error'] = '';
    
} catch (Throwable $e) {
    error_log("Member History Error: " . $e->getMessage());
    $response['error'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>