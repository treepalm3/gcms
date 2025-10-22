<?php
// dividend_member_history.php — ประวัติการรับปันผลของสมาชิก
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'โปรดเข้าสู่ระบบก่อน'
    ]);
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
        'error' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ'
    ]);
    exit();
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ตรวจสอบสิทธิ์
$current_role = $_SESSION['role'] ?? 'guest';
if (!in_array($current_role, ['admin', 'manager', 'member', 'committee'])) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้'
    ]);
    exit();
}

// รับข้อมูลจาก Query String
$member_id = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$member_type = isset($_GET['member_type']) ? trim($_GET['member_type']) : '';

// Validate
if ($member_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'รหัสสมาชิกไม่ถูกต้อง'
    ]);
    exit();
}

if (!in_array($member_type, ['member', 'manager', 'committee'])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'ประเภทสมาชิกไม่ถูกต้อง'
    ]);
    exit();
}

try {
    // 1) ตรวจสอบว่าสมาชิกมีอยู่จริง
    $member_info = null;

    switch ($member_type) {
        case 'member':
            $stmt = $pdo->prepare("
                SELECT m.id, m.member_code AS code, u.full_name, m.shares
                FROM members m
                JOIN users u ON m.user_id = u.id
                WHERE m.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $member_id]);
            $member_info = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'manager':
            try {
                $stmt = $pdo->prepare("
                    SELECT mg.id,
                           CONCAT('MGR-', LPAD(mg.id, 3, '0')) AS code,
                           u.full_name,
                           mg.shares
                    FROM managers mg
                    JOIN users u ON mg.user_id = u.id
                    WHERE mg.id = :id
                    LIMIT 1
                ");
                $stmt->execute([':id' => $member_id]);
                $member_info = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                error_log("Manager fetch error: " . $e->getMessage());
            }
            break;
            
        case 'committee':
            try {
                $stmt = $pdo->prepare("
                    SELECT c.id,
                           c.committee_code AS code,
                           u.full_name,
                           COALESCE(c.shares, 0) AS shares
                    FROM committees c
                    JOIN users u ON c.user_id = u.id
                    WHERE c.id = :id
                    LIMIT 1
                ");
                $stmt->execute([':id' => $member_id]);
                $member_info = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                error_log("Committee fetch error: " . $e->getMessage());
            }
            break;
    }

    if (!$member_info) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => 'ไม่พบข้อมูลสมาชิก'
        ]);
        exit();
    }

    // 2) ดึงประวัติการรับปันผล
    $history_stmt = $pdo->prepare("
        SELECT 
            dp.period_id,
            dp.shares_at_time,
            dp.dividend_amount,
            dp.payment_status,
            dp.paid_at,
            per.year,
            per.period_name,
            per.start_date,
            per.end_date,
            per.dividend_rate,
            per.payment_date
        FROM dividend_payments dp
        JOIN dividend_periods per ON per.id = dp.period_id
        WHERE dp.member_id = :member_id 
          AND dp.member_type = :member_type
        ORDER BY per.year DESC, per.id DESC
    ");

    $history_stmt->execute([
        ':member_id' => $member_id,
        ':member_type' => $member_type
    ]);

    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3) จัดรูปแบบข้อมูล
    $formatted_history = [];
    $total_received = 0;
    $total_pending = 0;
    $payment_count = 0;

    foreach ($history as $item) {
        $payment_count++;

        // คำนวณยอดรวม
        if ($item['payment_status'] === 'paid') {
            $total_received += (float)$item['dividend_amount'];
        } elseif ($item['payment_status'] === 'approved') {
            $total_pending += (float)$item['dividend_amount'];
        }

        // จัดรูปแบบวันที่
        $payment_date_formatted = '-';
        if (!empty($item['payment_date'])) {
            try {
                $date = new DateTime($item['payment_date']);
                $payment_date_formatted = $date->format('d/m/Y');
            } catch (Exception $e) {
                error_log("Date format error: " . $e->getMessage());
            }
        }

        // จัดรูปแบบช่วงวันที่
        $date_range = '';
        if (!empty($item['start_date']) && !empty($item['end_date'])) {
            try {
                $start = new DateTime($item['start_date']);
                $end = new DateTime($item['end_date']);
                $date_range = $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y');
            } catch (Exception $e) {
                error_log("Date range format error: " . $e->getMessage());
            }
        }

        $formatted_history[] = [
            'period_id' => (int)$item['period_id'],
            'year' => (int)$item['year'],
            'period_name' => $item['period_name'] ?: "ปันผลประจำปี {$item['year']}",
            'date_range' => $date_range,
            'shares_at_time' => (int)$item['shares_at_time'],
            'dividend_rate' => (float)$item['dividend_rate'],
            'dividend_amount' => (float)$item['dividend_amount'],
            'dividend_amount_formatted' => '฿' . number_format((float)$item['dividend_amount'], 2),
            'payment_status' => $item['payment_status'],
            'payment_status_th' => [
                'paid' => 'จ่ายแล้ว',
                'approved' => 'อนุมัติแล้ว',
                'pending' => 'รออนุมัติ'
            ][$item['payment_status']] ?? 'ไม่ทราบสถานะ',
            'payment_date' => $item['payment_date'],
            'payment_date_formatted' => $payment_date_formatted,
            'paid_at' => $item['paid_at']
        ];
    }

    // 4) สร้างสรุปข้อมูล
    $summary = [
        'member_id' => (int)$member_id,
        'member_code' => $member_info['code'],
        'member_name' => $member_info['full_name'],
        'current_shares' => (int)$member_info['shares'],
        'payment_count' => $payment_count,
        'total_received' => $total_received,
        'total_received_formatted' => '฿' . number_format($total_received, 2),
        'total_pending' => $total_pending,
        'total_pending_formatted' => '฿' . number_format($total_pending, 2),
        'total_all' => $total_received + $total_pending,
        'total_all_formatted' => '฿' . number_format($total_received + $total_pending, 2)
    ];

    // 5) ส่งผลลัพธ์
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'member_type' => $member_type,
        'summary' => $summary,
        'history' => $formatted_history
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log("Database error in dividend_member_history: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage()
    ]);
    exit();

} catch (Throwable $e) {
    error_log("Critical error in dividend_member_history: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'เกิดข้อผิดพลาดร้ายแรง'
    ]);
    exit();
}