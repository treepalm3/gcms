<?php
// dividend_approve.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');
require_once '../config/db.php';

$response = ['ok' => false, 'error' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
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
    $stmt = $pdo->prepare("
        UPDATE dividend_periods
        SET status = 'approved', approved_by = ?
        WHERE id = ? AND status = 'pending'
    ");
    $stmt->execute([$_SESSION['full_name'], $period_id]);
    
    if ($stmt->rowCount() > 0) {
        $response['ok'] = true;
        $response['message'] = 'อนุมัติงวดปันผลสำเร็จ';
    } else {
        $response['error'] = 'ไม่พบงวดปันผลที่รออนุมัติ หรืออาจถูกอนุมัติไปแล้ว';
    }

} catch (Throwable $e) {
    error_log("Dividend Approve Error: " . $e->getMessage());
    $response['error'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>