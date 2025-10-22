<?php
// dividend_payout.php — ประมวลผลการจ่ายปันผล
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
if ($_SESSION['role'] !== ['admin', 'manager']) {
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

// ตรวจสอบ period_code
$period_code = trim($data['period_code'] ?? '');
if (empty($period_code)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'กรุณาระบุรหัสงวดปันผล'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // เริ่ม transaction
    $pdo->beginTransaction();

    // 1) ดึงข้อมูลงวดปันผล
    $stmt = $pdo->prepare("
        SELECT id, period_code, `year`, period_name, total_profit, dividend_rate,
               total_shares_at_time, total_dividend_amount, status
        FROM dividend_periods
        WHERE period_code = :code
        LIMIT 1
    ");
    $stmt->execute([':code' => $period_code]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        throw new Exception('ไม่พบงวดปันผลที่ระบุ');
    }

    // ตรวจสอบสถานะ
    if ($period['status'] === 'paid') {
        throw new Exception('งวดนี้จ่ายปันผลแล้ว');
    }

    if ($period['status'] !== 'approved') {
        throw new Exception('งวดนี้ยังไม่ได้รับการอนุมัติ กรุณาอนุมัติก่อนจ่ายปันผล');
    }

    $period_id = (int)$period['id'];
    $dividend_rate = (float)$period['dividend_rate'];
    $total_profit = (float)$period['total_profit'];

    // คำนวณปันผลต่อหุ้น
    $total_dividend = $total_profit * ($dividend_rate / 100);
    $total_shares = (int)$period['total_shares_at_time'];
    
    if ($total_shares <= 0) {
        throw new Exception('จำนวนหุ้นรวมไม่ถูกต้อง');
    }

    $dividend_per_share = $total_dividend / $total_shares;

    // 2) ตรวจสอบว่ามีการจ่ายแล้วหรือไม่
    $check_stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM dividend_payments 
        WHERE period_id = :pid AND payment_status = 'paid'
    ");
    $check_stmt->execute([':pid' => $period_id]);
    $already_paid = (int)$check_stmt->fetchColumn();

    if ($already_paid > 0) {
        throw new Exception('มีการจ่ายปันผลงวดนี้ไปแล้วบางส่วน');
    }

    // 3) รวบรวมสมาชิกทุกประเภท
    $all_members = [];

    // 3.1) สมาชิกทั่วไป
    $member_stmt = $pdo->query("
        SELECT id, member_code, shares, 'member' AS member_type
        FROM members
        WHERE is_active = 1
    ");
    foreach ($member_stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $all_members[] = $m;
    }

    // 3.2) ผู้บริหาร (ไม่มี manager_code → สร้างจาก id)
    try {
        $manager_stmt = $pdo->query("
            SELECT id,
                CONCAT('MGR-', LPAD(id, 3, '0')) AS member_code,
                shares,
                'manager' AS member_type
            FROM managers
        ");
        foreach ($manager_stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $all_members[] = $m;
        }
    } catch (Throwable $e) {
        error_log("Manager fetch error: " . $e->getMessage());
    }

    // 3.3) กรรมการ (มี committee_code)
    try {
        $committee_stmt = $pdo->query("
            SELECT id,
                committee_code AS member_code,
                COALESCE(shares, 0) AS shares,
                'committee' AS member_type
            FROM committees
        ");
        foreach ($committee_stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $all_members[] = $m;
        }
    } catch (Throwable $e) {
        error_log("Committee fetch error: " . $e->getMessage());
    }

    if (empty($all_members)) {
        throw new Exception('ไม่พบผู้ถือหุ้นในระบบ');
    }
    // 4) ตรวจสอบว่าตาราง dividend_payments มี member_type หรือไม่
    $dp_columns = $pdo->query("SHOW COLUMNS FROM dividend_payments")->fetchAll(PDO::FETCH_COLUMN);
    $has_member_type = in_array('member_type', $dp_columns);

    // 5) สร้าง/อัปเดตรายการจ่ายปันผล
    $total_paid = 0;
    $member_count = 0;

    foreach ($all_members as $member) {
        $member_id = (int)$member['id'];
        $shares = (int)($member['shares'] ?? 0);
        $member_type = $member['member_type'] ?? 'member';

        if ($shares <= 0) continue;

        $dividend_amount = $shares * $dividend_per_share;
        $total_paid += $dividend_amount;
        $member_count++;

        // ตรวจสอบว่ามีรายการอยู่แล้วหรือไม่
        if ($has_member_type) {
            $check = $pdo->prepare("
                SELECT id FROM dividend_payments 
                WHERE period_id = :pid AND member_id = :mid AND member_type = :mtype
            ");
            $check->execute([':pid' => $period_id, ':mid' => $member_id, ':mtype' => $member_type]);
        } else {
            $check = $pdo->prepare("
                SELECT id FROM dividend_payments 
                WHERE period_id = :pid AND member_id = :mid
            ");
            $check->execute([':pid' => $period_id, ':mid' => $member_id]);
        }
        
        $existing = $check->fetchColumn();

        if ($existing) {
            // อัปเดตรายการที่มีอยู่
            if ($has_member_type) {
                $update = $pdo->prepare("
                    UPDATE dividend_payments 
                    SET dividend_amount = :amt,
                        shares_at_time = :shares,
                        payment_status = 'paid',
                        payment_date = NOW(),
                        updated_at = NOW()
                    WHERE period_id = :pid AND member_id = :mid AND member_type = :mtype
                ");
                $update->execute([
                    ':amt' => $dividend_amount,
                    ':shares' => $shares,
                    ':pid' => $period_id,
                    ':mid' => $member_id,
                    ':mtype' => $member_type
                ]);
            } else {
                $update = $pdo->prepare("
                    UPDATE dividend_payments 
                    SET dividend_amount = :amt,
                        shares_at_time = :shares,
                        payment_status = 'paid',
                        payment_date = NOW(),
                        updated_at = NOW()
                    WHERE period_id = :pid AND member_id = :mid
                ");
                $update->execute([
                    ':amt' => $dividend_amount,
                    ':shares' => $shares,
                    ':pid' => $period_id,
                    ':mid' => $member_id
                ]);
            }
        } else {
            // สร้างรายการใหม่
            if ($has_member_type) {
                $insert = $pdo->prepare("
                    INSERT INTO dividend_payments 
                    (period_id, member_id, member_type, dividend_amount, shares_at_time, 
                     payment_status, payment_date, created_at)
                    VALUES 
                    (:pid, :mid, :mtype, :amt, :shares, 'paid', NOW(), NOW())
                ");
                $insert->execute([
                    ':pid' => $period_id,
                    ':mid' => $member_id,
                    ':mtype' => $member_type,
                    ':amt' => $dividend_amount,
                    ':shares' => $shares
                ]);
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO dividend_payments 
                    (period_id, member_id, dividend_amount, shares_at_time, 
                     payment_status, payment_date, created_at)
                    VALUES 
                    (:pid, :mid, :amt, :shares, 'paid', NOW(), NOW())
                ");
                $insert->execute([
                    ':pid' => $period_id,
                    ':mid' => $member_id,
                    ':amt' => $dividend_amount,
                    ':shares' => $shares
                ]);
            }
        }
    }

    // 6) อัปเดตสถานะงวดปันผล
    $update_period = $pdo->prepare("
        UPDATE dividend_periods 
        SET status = 'paid',
            payment_date = NOW(),
            updated_at = NOW()
        WHERE id = :pid
    ");
    $update_period->execute([':pid' => $period_id]);

    // 7) บันทึก log (ถ้ามีตาราง activity_logs)
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
                ':desc' => "จ่ายปันผล {$period_code} (ปี {$period['year']}) จำนวน {$member_count} คน รวม ฿" . number_format($total_paid, 2)
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
        'message' => "จ่ายปันผลสำเร็จ! จ่ายให้ {$member_count} คน รวม ฿" . number_format($total_paid, 2),
        'data' => [
            'period_code' => $period_code,
            'year' => $period['year'],
            'member_count' => $member_count,
            'total_paid' => $total_paid,
            'dividend_per_share' => $dividend_per_share
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