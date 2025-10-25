<?php
// manager/dividend_member_history.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');
require_once '../config/db.php';

// Helper function
if (!function_exists('nf')) {
    function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
}
if (!function_exists('d')) {
    function d($s, $fmt = 'd/m/Y') { $t = strtotime($s); return $t ? date($fmt, $t) : '-'; }
}

$response = ['ok' => false, 'history' => [], 'summary' => [], 'error' => 'Invalid request'];

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
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
    $history_list = [];
    $total_received = 0;
    $total_pending = 0;
    $total_rebate_received = 0;
    $total_rebate_pending = 0;

    // 1. ดึงประวัติปันผล (Dividend)
    $sql_dividend = "
        SELECT 
            p.year, p.period_name, p.dividend_rate, 
            dp.shares_at_time, dp.dividend_amount, dp.payment_status, p.payment_date,
            'dividend' as type
        FROM dividend_payments dp
        JOIN dividend_periods p ON dp.period_id = p.id
        WHERE dp.member_id = ? AND dp.member_type = ?
    ";
    $stmt_div = $pdo->prepare($sql_dividend);
    $stmt_div->execute([$member_id, $member_type]);
    $dividend_history = $stmt_div->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dividend_history as $item) {
        $status = $item['payment_status'];
        $amount = (float)$item['dividend_amount'];
        if ($status == 'paid') $total_received += $amount;
        else $total_pending += $amount;
        
        $history_list[] = [
            'year' => $item['year'],
            'type' => 'ปันผล (หุ้น)',
            'period_name' => $item['period_name'],
            'shares_at_time' => (int)$item['shares_at_time'],
            'dividend_rate' => (float)$item['dividend_rate'],
            'amount_formatted' => '฿' . nf($amount),
            'details' => nf($item['shares_at_time']) . ' หุ้น @ ' . nf($item['dividend_rate'], 1) . '%',
            'payment_status' => $status,
            'payment_date_formatted' => ($status == 'paid' && $item['payment_date']) ? d($item['payment_date']) : '-'
        ];
    }
    
    // 2. ดึงประวัติเฉลี่ยคืน (Rebate)
    try {
        $sql_rebate = "
            SELECT 
                p.year, p.period_name, p.rebate_per_baht,
                rp.purchase_amount_at_time, rp.rebate_amount, rp.payment_status, p.payment_date,
                'rebate' as type
            FROM rebate_payments rp
            JOIN rebate_periods p ON rp.period_id = p.id
            WHERE rp.member_id = ? AND rp.member_type = ?
        ";
        $stmt_reb = $pdo->prepare($sql_rebate);
        $stmt_reb->execute([$member_id, $member_type]);
        $rebate_history = $stmt_reb->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($rebate_history as $item) {
            $status = $item['payment_status'];
            $amount = (float)$item['rebate_amount'];
            if ($status == 'paid') $total_rebate_received += $amount;
            else $total_rebate_pending += $amount;

            $history_list[] = [
                'year' => $item['year'],
                'type' => 'เฉลี่ยคืน (ซื้อ)',
                'period_name' => $item['period_name'],
                'purchase_amount_at_time' => (float)$item['purchase_amount_at_time'],
                'rebate_per_baht' => (float)$item['rebate_per_baht'],
                'amount_formatted' => '฿' . nf($amount),
                'details' => 'ซื้อ ' . nf($item['purchase_amount_at_time']) . ' @ ฿' . nf($item['rebate_per_baht'], 4) . '/บาท',
                'payment_status' => $status,
                'payment_date_formatted' => ($status == 'paid' && $item['payment_date']) ? d($item['payment_date']) : '-'
            ];
        }
    } catch (Throwable $e) { /* ตารางยังไม่มี */ }

    // 3. เรียงข้อมูลรวมตามปี
    usort($history_list, function($a, $b) {
        return $b['year'] <=> $a['year']; // เรียงจากปีล่าสุด
    });
    
    $response['ok'] = true;
    $response['history'] = $history_list;
    $response['summary'] = [
        'total_received' => $total_received,
        'total_pending' => $total_pending,
        'total_received_formatted' => '฿' . nf($total_received),
        'total_pending_formatted' => '฿' . nf($total_pending),
        'total_rebate_received' => $total_rebate_received,
        'total_rebate_pending' => $total_rebate_pending,
        'total_rebate_received_formatted' => '฿' . nf($total_rebate_received),
        'total_rebate_pending_formatted' => '฿' . nf($total_rebate_pending),
        'payment_count' => count($history_list)
    ];
    $response['error'] = '';
    
} catch (Throwable $e) {
    error_log("Member History Error: " . $e->getMessage());
    $response['error'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>