<?php
// dividend_create_period.php
session_start();
date_default_timezone_set('Asia/Bangkok');
require_once '../config/db.php';

function redirect_back($msg, $err = false) {
    $p = $err ? 'err' : 'ok';
    header("Location: dividend.php?tab=periods-panel&{$p}=" . urlencode($msg));
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    redirect_back('คุณไม่มีสิทธิ์ดำเนินการนี้', true);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_back('Invalid request method.', true);
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    redirect_back('CSRF token ไม่ถูกต้อง', true);
}

// รับค่า
$year = (int)$_POST['year'];
$period_name = trim($_POST['period_name'] ?? "ปันผลประจำปี $year");
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];
$total_profit = (float)$_POST['total_profit'];
$dividend_rate = (float)$_POST['dividend_rate'];
$total_shares_at_time = (int)$_POST['total_shares_at_time'];
$total_dividend_amount = (float)$_POST['total_dividend_amount'];
$dividend_per_share = ($total_shares_at_time > 0) ? ($total_dividend_amount / $total_shares_at_time) : 0;
// $notes = trim($_POST['notes'] ?? ''); // [ลบ] ไม่ต้องรับค่า notes แล้ว

if ($year <= 2020 || empty($start_date) || empty($end_date) || $total_profit <= 0 || $dividend_rate <= 0 || $total_shares_at_time <= 0) {
    redirect_back('ข้อมูลไม่ถูกต้อง กรุณากรอกข้อมูลสำคัญให้ครบ', true);
}

try {
    $pdo->beginTransaction();

    // 1. ตรวจสอบว่าปีนี้ถูกสร้างไปหรือยัง
    $check = $pdo->prepare("SELECT COUNT(*) FROM dividend_periods WHERE year = ?");
    $check->execute([$year]);
    if ($check->fetchColumn() > 0) {
        redirect_back("งวดปันผลสำหรับปี $year ได้ถูกสร้างไปแล้ว", true);
    }

    // 2. สร้างงวดปันผลหลัก
    // [แก้ไข] ลบ 'notes' ออกจาก SQL
    $ins_period = $pdo->prepare("
        INSERT INTO dividend_periods
            (year, period_name, start_date, end_date, total_profit, dividend_rate,
             total_shares_at_time, total_dividend_amount, dividend_per_share, status, created_at, approved_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)
    ");
    // [แก้ไข] ลบ $notes ออกจาก execute
    $ins_period->execute([
        $year, $period_name, $start_date, $end_date, $total_profit, $dividend_rate,
        $total_shares_at_time, $total_dividend_amount, $dividend_per_share, $_SESSION['full_name']
    ]);
    $period_id = $pdo->lastInsertId();

    // 3. ดึงสมาชิกทั้งหมดที่มีหุ้น
    $members_sql = "
        (SELECT id, 'member' as type, shares FROM members WHERE is_active = 1 AND shares > 0)
        UNION ALL
        (SELECT id, 'manager' as type, shares FROM managers WHERE shares > 0)
        UNION ALL
        (SELECT id, 'committee' as type, shares FROM committees WHERE shares > 0)
    ";
    $stmt_members = $pdo->query($members_sql);
    $all_members = $stmt_members->fetchAll(PDO::FETCH_ASSOC);

    // 4. เตรียมสร้างรายการจ่าย (Payment)
    $ins_payment = $pdo->prepare("
        INSERT INTO dividend_payments
            (period_id, member_id, member_type, shares_at_time, dividend_amount, payment_status)
        VALUES
            (?, ?, ?, ?, ?, 'pending')
    ");

    $payment_count = 0;
    foreach ($all_members as $member) {
        $member_id = $member['id'];
        $member_type = $member['type'];
        $shares = (int)$member['shares'];
        $dividend_amount = $shares * $dividend_per_share;

        if ($dividend_amount > 0) {
            $ins_payment->execute([
                $period_id,
                $member_id,
                $member_type,
                $shares,
                $dividend_amount
            ]);
            $payment_count++;
        }
    }

    $pdo->commit();
    redirect_back("สร้างงวดปันผลปี $year สำเร็จ สร้างรายการจ่ายสำหรับสมาชิก $payment_count คน");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Dividend Create Error: " . $e->getMessage());
    redirect_back('เกิดข้อผิดพลาด: ' . $e->getMessage(), true);
}
?>