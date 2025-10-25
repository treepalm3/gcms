<?php
// dividend_payout.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');
require_once '../config/db.php';

$response = ['ok' => false, 'error' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
    $response['error'] = 'Access denied';
    echo json_encode($response); exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json);
$csrf_token = $data->csrf_token ?? '';
$period_id = $data->period_id ?? 0;

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token) || $period_id <= 0) {
    $response['error'] = 'Invalid parameters or CSRF token';
    echo json_encode($response); exit;
}

try {
    $pdo->beginTransaction();
    
    // 1. อัปเดตงวดหลัก
    $stmt_period = $pdo->prepare("
        UPDATE dividend_periods
        SET status = 'paid', payment_date = NOW()
        WHERE id = ? AND status = 'approved'
    ");
    $stmt_period->execute([$period_id]);
    
    if ($stmt_period->rowCount() == 0) {
        throw new Exception('ไม่พบงวดปันผลที่อนุมัติแล้ว หรืออาจถูกจ่ายไปแล้ว');
    }

    // 2. อัปเดตรายการจ่ายย่อย
    $stmt_payments = $pdo->prepare("
        UPDATE dividend_payments
        SET payment_status = 'paid', paid_at = NOW()
        WHERE period_id = ? AND payment_status = 'pending'
    ");
    $stmt_payments->execute([$period_id]);
    
    $paid_count = $stmt_payments->rowCount();
    
    $pdo->commit();
    $response['ok'] = true;
    $response['message'] = "บันทึกการจ่ายปันผลสำเร็จ (อัปเดต $paid_count รายการ)";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Dividend Payout Error: " . $e->getMessage());
    $response['error'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>