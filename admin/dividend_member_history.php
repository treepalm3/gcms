<?php
// dividend_member_history.php — ดูประวัติการรับปันผลของสมาชิก/ผู้บริหาร/กรรมการ
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

// รับพารามิเตอร์
$member_id = (int)($_GET['member_id'] ?? 0);
$member_type = trim($_GET['member_type'] ?? '');

// Validation
if ($member_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'ไม่พบรหัสสมาชิก'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!in_array($member_type, ['member', 'manager', 'committee'])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'ประเภทสมาชิกไม่ถูกต้อง'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // ดึงข้อมูลสมาชิก
    $member_info = null;
    
    switch ($member_type) {
        case 'member':
            $stmt = $pdo->prepare("
                SELECT m.id, m.member_code AS code, u.full_name, m.shares
                FROM members m
                JOIN users u ON m.user_id = u.id
                WHERE m.id = :id AND m.is_active = 1
            ");
            $stmt->execute([':id' => $member_id]);
            $member_info = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'manager':
            $stmt = $pdo->prepare("
                SELECT 
                    mg.id, 
                    CONCAT('MGR-', LPAD(mg.id, 3, '0')) AS code,
                    u.full_name,
                    mg.shares
                FROM managers mg
                JOIN users u ON mg.user_id = u.id
                WHERE mg.id = :id
            ");
            $stmt->execute([':id' => $member_id]);
            $member_info = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'committee':
            $stmt = $pdo->prepare("
                SELECT 
                    c.id,
                    c.committee_code AS code,
                    u.full_name,
                    COALESCE(c.shares, 0) AS shares
                FROM committees c
                JOIN users u ON c.user_id = u.id
                WHERE c.id = :id
            ");
            $stmt->execute([':id' => $member_id]);
            $member_info = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
    }
    
    if (!$member_info) {
        throw new Exception('ไม่พบข้อมูลสมาชิก');
    }

    // ดึงประวัติการรับปันผล
    $history_stmt = $pdo->prepare("
        SELECT 
            dp.id,
            dp.shares_at_time,
            dp.dividend_amount,
            dp.payment_status,
            dp.paid_at,
            per.id AS period_id,
            per.year,
            per.period_name,
            per.dividend_rate,
            per.payment_date,
            per.status AS period_status
        FROM dividend_payments dp
        JOIN dividend_periods per ON dp.period_id = per.id
        WHERE dp.member_id = :member_id
          AND dp.member_type = :member_type
        ORDER BY per.year DESC, per.id DESC
    ");
    
    $history_stmt->execute([
        ':member_id' => $member_id,
        ':member_type' => $member_type
    ]);
    
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // คำนวณสรุป
    $total_received = 0;
    $total_pending = 0;
    $payment_count = 0;
    
    foreach ($history as &$item) {
        $payment_count++;
        
        $amount = (float)$item['dividend_amount'];
        
        if ($item['payment_status'] === 'paid') {
            $total_received += $amount;
        } else {
            $total_pending += $amount;
        }
        
        // จัดรูปแบบข้อมูล
        $item['dividend_amount_formatted'] = '฿' . number_format($amount, 2);
        $item['dividend_rate'] = (float)$item['dividend_rate'];
        $item['shares_at_time'] = (int)$item['shares_at_time'];
        
        // จัดรูปแบบวันที่
        if ($item['paid_at']) {
            $date = new DateTime($item['paid_at']);
            $item['payment_date_formatted'] = $date->format('d/m/Y H:i');
        } elseif ($item['payment_date']) {
            $date = new DateTime($item['payment_date']);
            $item['payment_date_formatted'] = $date->format('d/m/Y');
        } else {
            $item['payment_date_formatted'] = '-';
        }
    }
    
    // ส่งผลลัพธ์
    echo json_encode([
        'ok' => true,
        'member' => [
            'id' => $member_info['id'],
            'code' => $member_info['code'],
            'full_name' => $member_info['full_name'],
            'shares' => (int)$member_info['shares'],
            'type' => $member_type
        ],
        'history' => $history,
        'summary' => [
            'total_received' => $total_received,
            'total_received_formatted' => '฿' . number_format($total_received, 2),
            'total_pending' => $total_pending,
            'total_pending_formatted' => '฿' . number_format($total_pending, 2),
            'payment_count' => $payment_count
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Member history error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("Critical member history error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'เกิดข้อผิดพลาดร้ายแรง'
    ], JSON_UNESCAPED_UNICODE);
}
