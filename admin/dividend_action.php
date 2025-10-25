<?php
// admin/dividend_action.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');
require_once '../config/db.php'; // ตรวจสอบว่า path ถูกต้อง

$response = ['ok' => false, 'error' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    $response['error'] = 'Access denied';
    echo json_encode($response); exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json);
$csrf_token = $data->csrf_token ?? '';
$action = $data->action ?? '';

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $response['error'] = 'Invalid CSRF token';
    echo json_encode($response); exit;
}

try {
    $pdo->beginTransaction();

    switch ($action) {
        // --- ACTIONS FOR dividend_periods ---
        case 'approve_period':
            $period_id = (int)($data->period_id ?? 0);
            if ($period_id <= 0) throw new Exception('Invalid Period ID');
            $stmt = $pdo->prepare("UPDATE dividend_periods SET status = 'approved', approved_by = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$_SESSION['full_name'], $period_id]);
            if ($stmt->rowCount() == 0) throw new Exception('ไม่พบงวดที่รออนุมัติ หรืออาจถูกอนุมัติไปแล้ว');
            $response['message'] = 'อนุมัติงวดปันผลสำเร็จ';
            break;

        case 'unapprove_period':
            $period_id = (int)($data->period_id ?? 0);
            if ($period_id <= 0) throw new Exception('Invalid Period ID');
            $stmt = $pdo->prepare("UPDATE dividend_periods SET status = 'pending', approved_by = NULL WHERE id = ? AND status = 'approved'");
            $stmt->execute([$period_id]);
            if ($stmt->rowCount() == 0) throw new Exception('ไม่พบงวดที่อนุมัติแล้ว');
            $response['message'] = 'ยกเลิกการอนุมัติสำเร็จ';
            break;

        case 'process_payout':
            $period_id = (int)($data->period_id ?? 0);
            if ($period_id <= 0) throw new Exception('Invalid Period ID');
            
            // 1. อัปเดตงวดหลัก
            $stmt_period = $pdo->prepare("UPDATE dividend_periods SET status = 'paid', payment_date = CURDATE() WHERE id = ? AND status = 'approved'");
            $stmt_period->execute([$period_id]);
            if ($stmt_period->rowCount() == 0) throw new Exception('ไม่พบงวดที่อนุมัติแล้ว หรืออาจถูกจ่ายไปแล้ว');

            // 2. อัปเดตรายการจ่ายย่อย
            $stmt_payments = $pdo->prepare("UPDATE dividend_payments SET payment_status = 'paid', paid_at = NOW() WHERE period_id = ? AND payment_status = 'pending'");
            $stmt_payments->execute([$period_id]);
            $paid_count = $stmt_payments->rowCount();
            
            $response['message'] = "บันทึกการจ่ายปันผลสำเร็จ (อัปเดต $paid_count รายการ)";
            break;

        case 'delete_period':
            $period_id = (int)($data->period_id ?? 0);
            if ($period_id <= 0) throw new Exception('Invalid Period ID');
            // ตาราง dividend_payments ควรมี ON DELETE CASCADE (ตาม SQL ที่ให้ไปก่อนหน้า)
            $stmt = $pdo->prepare("DELETE FROM dividend_periods WHERE id = ?");
            $stmt->execute([$period_id]);
            if ($stmt->rowCount() == 0) throw new Exception('ไม่พบงวดปันผลที่ต้องการลบ');
            $response['message'] = 'ลบงวดปันผลสำเร็จ';
            break;

        // --- ACTIONS FOR dividend_payments (รายบุคคล) ---
        case 'mark_paid':
            $payment_id = (int)($data->payment_id ?? 0);
            if ($payment_id <= 0) throw new Exception('Invalid Payment ID');
            $stmt = $pdo->prepare("UPDATE dividend_payments SET payment_status = 'paid', paid_at = NOW() WHERE id = ? AND payment_status = 'pending'");
            $stmt->execute([$payment_id]);
            if ($stmt->rowCount() == 0) throw new Exception('ไม่พบรายการที่รอจ่าย');
            $response['message'] = 'บันทึกการจ่ายรายบุคคลสำเร็จ';
            break;

        case 'mark_pending':
            $payment_id = (int)($data->payment_id ?? 0);
            if ($payment_id <= 0) throw new Exception('Invalid Payment ID');
            $stmt = $pdo->prepare("UPDATE dividend_payments SET payment_status = 'pending', paid_at = NULL WHERE id = ? AND payment_status = 'paid'");
            $stmt->execute([$payment_id]);
            if ($stmt->rowCount() == 0) throw new Exception('ไม่พบรายการที่จ่ายแล้ว');
            $response['message'] = 'ยกเลิกการจ่ายสำเร็จ';
            break;
            
        default:
            throw new Exception('Action ที่ร้องขอไม่ถูกต้อง');
    }

    $pdo->commit();
    $response['ok'] = true;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Dividend Action Error: " . $e->getMessage());
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>