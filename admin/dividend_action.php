<?php
// admin/dividend_action.php - Handles AJAX requests for dividend management
session_start();
date_default_timezone_set('Asia/Bangkok');

// Set header to return JSON
header('Content-Type: application/json');

// --- Response Helper ---
function json_response($success, $message, $data = []) {
    echo json_encode(['ok' => $success, ($success ? 'message' : 'error') => $message, 'data' => $data]);
    exit();
}

// --- Check Login & Role ---
// *** CHANGED: Now only checks if user is admin OR manager ***
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    json_response(false, 'ไม่ได้รับอนุญาตให้เข้าถึง');
}
$current_role = $_SESSION['role'];
$current_user_name = $_SESSION['full_name'] ?? ('User #' . $_SESSION['user_id']);


// --- Get Input Data ---
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    json_response(false, 'รูปแบบข้อมูลนำเข้าไม่ถูกต้อง');
}

// --- Check CSRF Token ---
// Use null coalescing for $_SESSION['csrf_token'] to avoid undefined index warning if not set yet
if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
    json_response(false, 'โทเค็นความปลอดภัยไม่ถูกต้อง');
}

// --- Database Connection ---
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    json_response(false, 'การเชื่อมต่อฐานข้อมูลล้มเหลว');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- Get Action and Data ---
$action = $input['action'] ?? null;
$period_id = isset($input['period_id']) ? (int)$input['period_id'] : null;
$payment_id = isset($input['payment_id']) ? (int)$input['payment_id'] : null;
$year = isset($input['year']) ? (int)$input['year'] : null; // For process_payout

// --- Process Actions ---
try {
    switch ($action) {
        // == การดำเนินการกับงวดปันผล ==
        case 'approve_period': // อนุมัติงวด
            if (!$period_id) json_response(false, 'ไม่ได้ระบุ ID งวด');
            // *** REMOVED Admin-only check ***
            // if ($_SESSION['role'] !== 'admin') json_response(false, 'เฉพาะ Admin เท่านั้นที่สามารถอนุมัติได้');

            $stmt = $pdo->prepare("UPDATE dividend_periods SET status = 'approved', approved_by = :approver WHERE id = :id AND status = 'pending'");
            $stmt->execute([':id' => $period_id, ':approver' => $current_user_name]); // Use current user's name
            if ($stmt->rowCount() > 0) {
                json_response(true, 'งวดปันผลได้รับการอนุมัติแล้ว');
            } else {
                json_response(false, 'ไม่สามารถอนุมัติงวดปันผลได้ (อาจอนุมัติไปแล้ว, จ่ายแล้ว, หรือ ID ไม่ถูกต้อง)');
            }
            break;

        case 'unapprove_period': // ยกเลิกการอนุมัติ
            if (!$period_id) json_response(false, 'ไม่ได้ระบุ ID งวด');
             // *** REMOVED Admin-only check ***
            // if ($_SESSION['role'] !== 'admin') json_response(false, 'เฉพาะ Admin เท่านั้นที่สามารถยกเลิกการอนุมัติได้');

             // Check if it's already paid first
             $stmt_check = $pdo->prepare("SELECT status FROM dividend_periods WHERE id = :id");
             $stmt_check->execute([':id' => $period_id]);
             $status = $stmt_check->fetchColumn();
             if ($status === 'paid') {
                  json_response(false, 'ไม่สามารถยกเลิกการอนุมัติงวดที่จ่ายไปแล้วได้');
             }

            $stmt = $pdo->prepare("UPDATE dividend_periods SET status = 'pending', approved_by = NULL WHERE id = :id AND status = 'approved'");
            $stmt->execute([':id' => $period_id]);
            if ($stmt->rowCount() > 0) {
                json_response(true, 'ยกเลิกการอนุมัติงวดปันผลแล้ว');
            } else {
                json_response(false, 'ไม่สามารถยกเลิกการอนุมัติได้ (อาจยังไม่ได้อนุมัติ หรือ ID ไม่ถูกต้อง)');
            }
            break;

        case 'process_payout': // ดำเนินการจ่ายปันผล (already allowed both)
            if (!$year) json_response(false, 'ไม่ได้ระบุปี');

            // Get period ID from year
            $stmt_find = $pdo->prepare("SELECT id, status FROM dividend_periods WHERE `year` = :year LIMIT 1");
            $stmt_find->execute([':year' => $year]);
            $period_info = $stmt_find->fetch(PDO::FETCH_ASSOC);

            if (!$period_info) {
                 json_response(false, "ไม่พบงวดปันผลสำหรับปี $year");
            }
             if ($period_info['status'] !== 'approved') {
                 json_response(false, "งวดปันผลปี $year ยังไม่อยู่ในสถานะ 'อนุมัติแล้ว' (สถานะปัจจุบัน: {$period_info['status']})");
            }
            $found_period_id = (int)$period_info['id'];


            $pdo->beginTransaction();
            $now = date('Y-m-d H:i:s');
            // 1. Update period status and payment date
            $stmt_period = $pdo->prepare("UPDATE dividend_periods SET status = 'paid', payment_date = CURDATE() WHERE id = :id AND status = 'approved'");
            $stmt_period->execute([':id' => $found_period_id]);
             $period_updated = $stmt_period->rowCount();

            // 2. Update payment status for all related payments that are pending
            $stmt_payments = $pdo->prepare("UPDATE dividend_payments SET payment_status = 'paid', paid_at = :now WHERE period_id = :id AND payment_status = 'pending'");
            $stmt_payments->execute([':id' => $found_period_id, ':now' => $now]);
            $count = $stmt_payments->rowCount();

            if ($period_updated > 0 || $count > 0) { // If anything changed
                $pdo->commit();
                json_response(true, "จ่ายปันผลสำหรับปี $year จำนวน $count รายการเรียบร้อยแล้ว");
            } else {
                 $pdo->rollBack(); // Nothing changed, likely already paid or error
                 json_response(false, "ไม่สามารถดำเนินการจ่ายปันผลปี $year ได้ (อาจจ่ายไปแล้ว หรือไม่มีรายการรอจ่าย)");
            }
            break;

        case 'delete_period': // ลบงวดปันผล
            if (!$period_id) json_response(false, 'ไม่ได้ระบุ ID งวด');
             // *** REMOVED Admin-only check ***
            // if ($_SESSION['role'] !== 'admin') json_response(false, 'เฉพาะ Admin เท่านั้นที่สามารถลบงวดได้');

            // ตรวจสอบสถานะก่อนลบ (ทางเลือกเพื่อความปลอดภัย)
            $stmt_check = $pdo->prepare("SELECT status FROM dividend_periods WHERE id = :id");
            $stmt_check->execute([':id' => $period_id]);
            $status = $stmt_check->fetchColumn();

            if ($status === false) {
                 json_response(false, 'ไม่พบงวดปันผลที่ต้องการลบ');
            }
            if ($status === 'paid') {
                 json_response(false, 'ไม่สามารถลบงวดปันผลที่จ่ายไปแล้วได้');
            }

            $pdo->beginTransaction(); // เริ่ม Transaction
            // 1. ลบรายการจ่ายก่อน (CASCADE constraint might handle this, but explicit is safer)
            $stmt_del_pay = $pdo->prepare("DELETE FROM dividend_payments WHERE period_id = :id");
            $stmt_del_pay->execute([':id' => $period_id]);
            $deleted_payments = $stmt_del_pay->rowCount();

            // 2. ลบงวด
            $stmt_del_period = $pdo->prepare("DELETE FROM dividend_periods WHERE id = :id");
            $stmt_del_period->execute([':id' => $period_id]);
            $deleted_periods = $stmt_del_period->rowCount();

            $pdo->commit(); // ยืนยัน Transaction
            if ($deleted_periods > 0) {
                json_response(true, "ลบงวดปันผล ID $period_id และรายการจ่าย $deleted_payments รายการเรียบร้อยแล้ว");
            } else {
                 json_response(false, 'ไม่สามารถลบงวดปันผลได้ (อาจถูกลบไปแล้ว)');
            }
            break;

        // == การดำเนินการกับรายการจ่าย == (already allowed both)
        case 'mark_paid': // ทำเครื่องหมายว่าจ่ายแล้ว
            if (!$payment_id) json_response(false, 'ไม่ได้ระบุ ID การจ่าย');

            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE dividend_payments SET payment_status = 'paid', paid_at = :now WHERE id = :id AND payment_status = 'pending'");
            $stmt->execute([':id' => $payment_id, ':now' => $now]);
            if ($stmt->rowCount() > 0) {
                json_response(true, 'บันทึกการจ่ายเรียบร้อย');
            } else {
                json_response(false, 'ไม่สามารถบันทึกการจ่ายได้ (อาจจ่ายไปแล้ว หรือ ID ไม่ถูกต้อง)');
            }
            break;

        case 'mark_pending': // ยกเลิกสถานะจ่าย (กลับเป็นรอจ่าย)
            if (!$payment_id) json_response(false, 'ไม่ได้ระบุ ID การจ่าย');

             // Check parent period status - cannot revert if whole period is paid
             $stmt_check_period = $pdo->prepare("SELECT p.status FROM dividend_payments dp JOIN dividend_periods p ON dp.period_id = p.id WHERE dp.id = :pid");
             $stmt_check_period->execute([':pid' => $payment_id]);
             $period_status = $stmt_check_period->fetchColumn();

             if ($period_status === 'paid') {
                 json_response(false, 'ไม่สามารถยกเลิกสถานะจ่ายได้ เนื่องจากงวดปันผลทั้งหมดถูกจ่ายไปแล้ว');
             }


            $stmt = $pdo->prepare("UPDATE dividend_payments SET payment_status = 'pending', paid_at = NULL WHERE id = :id AND payment_status = 'paid'");
            $stmt->execute([':id' => $payment_id]);
            if ($stmt->rowCount() > 0) {
                json_response(true, 'ยกเลิกสถานะการจ่ายแล้ว');
            } else {
                json_response(false, 'ไม่สามารถยกเลิกสถานะได้ (อาจยังไม่ได้จ่าย หรือ ID ไม่ถูกต้อง)');
            }
            break;

        default: // Action ไม่ถูกต้อง
            json_response(false, 'Action ที่ระบุไม่ถูกต้อง');
            break;
    }

} catch (PDOException $e) { // ดักจับข้อผิดพลาดจากฐานข้อมูล
    if ($pdo->inTransaction()) {
        $pdo->rollBack(); // ย้อนกลับ Transaction ถ้าเกิดข้อผิดพลาด
    }
    error_log('Dividend Action Error: ' . $e->getMessage());
    json_response(false, 'Database error: ' . $e->getMessage());
} catch (Throwable $e) { // ดักจับข้อผิดพลาดทั่วไป
     if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Dividend Action Error: ' . $e->getMessage());
    json_response(false, 'General error: ' . $e->getMessage());
}