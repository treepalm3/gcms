<?php
// dividend_create_period.php — สร้างงวดปันผลใหม่
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
    exit();
}

// ตรวจสอบสิทธิ์ (เฉพาะ admin)
if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    header('Location: dividend.php?err=คุณไม่มีสิทธิ์สร้างงวดปันผล');
    exit();
}

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) {
    $dbFile = __DIR__ . '/config/db.php';
}
require_once $dbFile;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ตรวจสอบ CSRF Token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        header('Location: dividend.php?err=CSRF token ไม่ถูกต้อง');
        exit();
    }

    // รับข้อมูลจากฟอร์ม
    $year = (int)($_POST['year'] ?? date('Y'));
    $period_name = trim($_POST['period_name'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $total_profit = (float)($_POST['total_profit'] ?? 0);
    $dividend_rate = (float)($_POST['dividend_rate'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    // Validation
    $errors = [];

    if ($year < 2020 || $year > 2050) {
        $errors[] = 'ปีไม่ถูกต้อง (ต้องอยู่ระหว่าง 2020-2050)';
    }

    // Validate วันที่
    if (empty($start_date) || empty($end_date)) {
        $errors[] = 'กรุณาระบุวันที่เริ่มต้นและวันที่สิ้นสุด';
    } else {
        $start = strtotime($start_date);
        $end = strtotime($end_date);
        
        if ($start === false || $end === false) {
            $errors[] = 'รูปแบบวันที่ไม่ถูกต้อง';
        } elseif ($end < $start) {
            $errors[] = 'วันที่สิ้นสุดต้องมากกว่าหรือเท่ากับวันที่เริ่มต้น';
        } elseif ($end - $start > 366 * 24 * 60 * 60) {
            $errors[] = 'ช่วงเวลาต้องไม่เกิน 1 ปี';
        }
    }

    if ($total_profit <= 0) {
        $errors[] = 'กำไรสุทธิต้องมากกว่า 0 บาท';
    }

    if ($dividend_rate <= 0 || $dividend_rate > 100) {
        $errors[] = 'อัตราปันผลต้องอยู่ระหว่าง 0.1-100%';
    }

    if (!empty($errors)) {
        $error_message = implode(', ', $errors);
        header("Location: dividend.php?err=" . urlencode($error_message));
        exit();
    }
    try {
        // เริ่ม Transaction
        $pdo->beginTransaction();

        // 1) ตรวจสอบว่ามีงวดของปีนี้อยู่แล้วหรือไม่
        $check_year_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM dividend_periods WHERE year = :year
        ");
        $check_year_stmt->execute([':year' => $year]);
        
        if ((int)$check_year_stmt->fetchColumn() > 0) {
            throw new Exception("มีงวดปันผลของปี {$year} อยู่ในระบบแล้ว (แต่ละปีสามารถมีได้เพียง 1 งวด)");
        }

        // 2) นับจำนวนหุ้นรวมทั้งหมด
        $total_shares = 0;

        // 2.1) สมาชิก
        $member_shares_stmt = $pdo->query("
            SELECT COALESCE(SUM(shares), 0) FROM members WHERE is_active = 1
        ");
        $total_shares += (int)$member_shares_stmt->fetchColumn();

        // 2.2) ผู้บริหาร
        try {
            $manager_shares_stmt = $pdo->query("
                SELECT COALESCE(SUM(COALESCE(shares, 0)), 0) 
                FROM managers 
                WHERE COALESCE(shares, 0) > 0
            ");
            $manager_shares = (int)$manager_shares_stmt->fetchColumn();
            $total_shares += $manager_shares;
            $shares_breakdown['manager'] = $manager_shares;
            
            error_log("Manager shares found: {$manager_shares}");
        } catch (Throwable $e) {
            error_log("Manager shares error: " . $e->getMessage());
            $shares_breakdown['manager'] = 0;
        }

        // 2.3) กรรมการ
        try {
            $committee_shares_stmt = $pdo->query("
                SELECT COALESCE(SUM(shares), 0) FROM committees
            ");
            $total_shares += (int)$committee_shares_stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("Committee shares error: " . $e->getMessage());
        }

        if ($total_shares <= 0) {
            throw new Exception('ไม่พบหุ้นในระบบ กรุณาเพิ่มสมาชิกที่มีหุ้นก่อนสร้างงวดปันผล');
        }

        // 3) คำนวณปันผล
        $total_dividend_amount = $total_profit * ($dividend_rate / 100);
        $dividend_per_share = $total_dividend_amount / $total_shares;

        // 4) สร้างงวดปันผล
        $insert_period_stmt = $pdo->prepare("
        INSERT INTO dividend_periods 
        (year, start_date, end_date, period_name, total_profit, dividend_rate, 
        total_shares_at_time, total_dividend_amount, dividend_per_share, 
        status, created_at, approved_by)
        VALUES 
        (:year, :start_date, :end_date, :name, :profit, :rate, 
        :shares, :total_dividend, :per_share, 
        'pending', NOW(), NULL)
        ");

        $insert_period_stmt->execute([
        ':year' => $year,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':name' => $period_name ?: "ปันผลประจำปี {$year}",
        ':profit' => $total_profit,
        ':rate' => $dividend_rate,
        ':shares' => $total_shares,
        ':total_dividend' => $total_dividend_amount,
        ':per_share' => $dividend_per_share
        ]);

        $period_id = $pdo->lastInsertId();

        // 5) สร้างรายการจ่ายปันผลสำหรับทุกคน
        $member_count = 0;

        // 5.1) สมาชิกทั่วไป
        $members_stmt = $pdo->query("
            SELECT id, shares FROM members WHERE is_active = 1 AND shares > 0
        ");

        foreach ($members_stmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
            $dividend_amount = $member['shares'] * $dividend_per_share;
            
            $insert_payment_stmt = $pdo->prepare("
                INSERT INTO dividend_payments 
                (period_id, member_id, member_type, shares_at_time, 
                 dividend_amount, payment_status)
                VALUES 
                (:period_id, :member_id, 'member', :shares, :amount, 'pending')
            ");

            $insert_payment_stmt->execute([
                ':period_id' => $period_id,
                ':member_id' => $member['id'],
                ':shares' => $member['shares'],
                ':amount' => $dividend_amount
            ]);

            $member_count++;
        }

        // 5.2) ผู้บริหาร
try {
    $managers_stmt = $pdo->query("
        SELECT id, COALESCE(shares, 0) AS shares 
        FROM managers 
        WHERE COALESCE(shares, 0) > 0
    ");

    $managers_count = 0;
    $managers_data = $managers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($managers_data) . " managers with shares > 0");

    foreach ($managers_data as $manager) {
        $shares = (int)$manager['shares'];
        $dividend_amount = $shares * $dividend_per_share;
        
        error_log("Creating dividend payment for manager id={$manager['id']}, shares={$shares}, amount={$dividend_amount}");
        
        $insert_payment_stmt = $pdo->prepare("
            INSERT INTO dividend_payments 
            (period_id, member_id, member_type, shares_at_time, 
             dividend_amount, payment_status)
            VALUES 
            (:period_id, :member_id, 'manager', :shares, :amount, 'pending')
        ");

        $insert_payment_stmt->execute([
            ':period_id' => $period_id,
            ':member_id' => $manager['id'],
            ':shares' => $shares,
            ':amount' => $dividend_amount
        ]);

        $managers_count++;
        $member_count++;
    }
    
    $payment_breakdown['manager'] = $managers_count;
    error_log("Created {$managers_count} manager dividend payments");
    
} catch (Throwable $e) {
    error_log("Manager dividend payment error: " . $e->getMessage());
    error_log("Manager error stack trace: " . $e->getTraceAsString());
    $payment_breakdown['manager'] = 0;
}

        // 5.3) กรรมการ
        try {
            $committees_stmt = $pdo->query("
                SELECT id, COALESCE(shares, 0) AS shares 
                FROM committees 
                WHERE COALESCE(shares, 0) > 0
            ");

            foreach ($committees_stmt->fetchAll(PDO::FETCH_ASSOC) as $committee) {
                $dividend_amount = $committee['shares'] * $dividend_per_share;
                
                $insert_payment_stmt = $pdo->prepare("
                    INSERT INTO dividend_payments 
                    (period_id, member_id, member_type, shares_at_time, 
                     dividend_amount, payment_status)
                    VALUES 
                    (:period_id, :member_id, 'committee', :shares, :amount, 'pending')
                ");

                $insert_payment_stmt->execute([
                    ':period_id' => $period_id,
                    ':member_id' => $committee['id'],
                    ':shares' => $committee['shares'],
                    ':amount' => $dividend_amount
                ]);

                $member_count++;
            }
        } catch (Throwable $e) {
            error_log("Committee dividend payment error: " . $e->getMessage());
        }

        // 6) บันทึก Log (ถ้ามีตาราง)
        try {
            $log_check = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetch();
            if ($log_check) {
                $log_stmt = $pdo->prepare("
                    INSERT INTO activity_logs 
                    (user_id, action, description, created_at)
                    VALUES 
                    (:uid, 'create_dividend_period', :desc, NOW())
                ");
                $log_stmt->execute([
                    ':uid' => $_SESSION['user_id'],
                    ':desc' => "สร้างงวดปันผลปี {$year} สำหรับ {$member_count} คน ยอดรวม ฿" . number_format($total_dividend_amount, 2)
                ]);
            }
        } catch (Throwable $e) {
            error_log("Activity log error: " . $e->getMessage());
        }

        // Commit Transaction
        $pdo->commit();

        // Redirect พร้อมข้อความสำเร็จ
        $success_message = "✅ สร้างงวดปันผลปี {$year} สำเร็จ! จำนวน {$member_count} คน | ยอดรวม ฿" . number_format($total_dividend_amount, 2);
        header("Location: dividend.php?ok=" . urlencode($success_message));
        exit();

    } catch (Exception $e) {
        // Rollback on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Create dividend period error: " . $e->getMessage());
        header("Location: dividend.php?err=" . urlencode($e->getMessage()));
        exit();

    } catch (Throwable $e) {
        // Rollback on critical error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Critical dividend period error: " . $e->getMessage());
        header("Location: dividend.php?err=" . urlencode('เกิดข้อผิดพลาดร้ายแรง: ' . $e->getMessage()));
        exit();
    }

} else {
    // ถ้าไม่ใช่ POST ให้กลับไปหน้า dividend
    header('Location: dividend.php');
    exit();
}