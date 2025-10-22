<?php
// dividend_payout.php — ประมวลผลการจ่ายปันผล (เฉพาะสมาชิก)
session_start();
date_default_timezone_set('Asia/Bangkok');

header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ตรวจสอบสิทธิ์
if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'คุณไม่มีสิทธิ์ดำเนินการนี้'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) {
    $dbFile = __DIR__ . '/config/db.php';
}
require_once $dbFile;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// รับข้อมูล JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'ข้อมูลไม่ถูกต้อง'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ตรวจสอบ CSRF token
$csrf_token = $data['csrf_token'] ?? '';
if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'CSRF token ไม่ถูกต้อง'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// รับ year
$year = (int)($data['year'] ?? 0);

if ($year <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'กรุณาระบุปีที่ต้องการจ่ายปันผล'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // เริ่ม transaction
    $pdo->beginTransaction();

    // 1) ดึงข้อมูลงวดปันผล
    $stmt = $pdo->prepare("
        SELECT id, `year`, period_name, total_profit, dividend_rate,
               total_shares_at_time, total_dividend_amount, status
        FROM dividend_periods
        WHERE `year` = :year
        LIMIT 1
    ");
    $stmt->execute([':year' => $year]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        throw new Exception('ไม่พบงวดปันผลของปี ' . $year);
    }

    // ตรวจสอบสถานะ
    if ($period['status'] === 'paid') {
        throw new Exception('งวดนี้จ่ายปันผลแล้ว');
    }

    if ($period['status'] === 'pending') {
        throw new Exception('งวดนี้ยังไม่ได้รับการอนุมัติ กรุณาอนุมัติก่อนจ่ายปันผล');
    }

    $period_id = (int)$period['id'];

    // 2) อัปเดตสถานะรายการจ่ายปันผลเป็น 'paid' (เฉพาะสมาชิก)
    $update_payments = $pdo->prepare("
        UPDATE dividend_payments 
        SET payment_status = 'paid',
            paid_at = NOW()
        WHERE period_id = :pid
          AND member_type = 'member'
          AND payment_status = 'approved'
    ");
    $update_payments->execute([':pid' => $period_id]);
    
    $affected_rows = $update_payments->rowCount();

    if ($affected_rows === 0) {
        throw new Exception('ไม่มีรายการสมาชิกที่พร้อมจ่าย (ต้องอนุมัติก่อน)');
    }

    // 3) อัปเดตสถานะงวดปันผล
    $update_period = $pdo->prepare("
        UPDATE dividend_periods 
        SET status = 'paid',
            payment_date = NOW()
        WHERE id = :pid
    ");
    $update_period->execute([':pid' => $period_id]);

    // 4) คำนวณยอดรวมที่จ่าย (เฉพาะสมาชิก)
    $total_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as member_count,
            SUM(dividend_amount) as total_paid
        FROM dividend_payments
        WHERE period_id = :pid
          AND member_type = 'member'
          AND payment_status = 'paid'
    ");
    $total_stmt->execute([':pid' => $period_id]);
    $totals = $total_stmt->fetch(PDO::FETCH_ASSOC);

    $member_count = (int)($totals['member_count'] ?? 0);
    $total_paid = (float)($totals['total_paid'] ?? 0);

    // 5) บันทึก log
    try {
        $log_check = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetch();
        if ($log_check) {
            $log_stmt = $pdo->prepare("
                INSERT INTO activity_logs 
                (user_id, action, description, created_at)
                VALUES 
                (:uid, 'dividend_payout', :desc, NOW())
            ");
            $log_stmt->execute([
                ':uid' => $_SESSION['user_id'],
                ':desc' => "จ่ายปันผลปี {$year} ให้สมาชิก {$member_count} คน รวม ฿" . number_format($total_paid, 2)
            ]);
        }
    } catch (Throwable $e) {
        error_log("Activity log error: " . $e->getMessage());
    }

    // Commit transaction
    $pdo->commit();

    // ส่งผลลัพธ์สำเร็จ
    echo json_encode([
        'ok' => true,
        'message' => "จ่ายปันผลสำเร็จ! จ่ายให้สมาชิก {$member_count} คน รวม ฿" . number_format($total_paid, 2),
        'data' => [
            'year' => $period['year'],
            'member_count' => $member_count,
            'total_paid' => $total_paid
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Dividend payout error: " . $e->getMessage());

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Rollback on critical error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Critical dividend payout error: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'เกิดข้อผิดพลาดร้ายแรง: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}