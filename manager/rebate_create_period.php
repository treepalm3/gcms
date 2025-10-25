<?php
// manager/rebate_create_period.php
session_start();
date_default_timezone_set('Asia/Bangkok');
require_once '../config/db.php';

function redirect_back($msg, $err = false) {
    $p = $err ? 'err' : 'ok';
    header("Location: dividend.php?tab=rebate-panel&{$p}=" . urlencode($msg));
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
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
$period_name = trim($_POST['period_name'] ?? "เฉลี่ยคืนประจำปี $year");
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];
$total_profit = (float)($_POST['total_profit'] ?? 0); 
$rebate_rate_percent = (float)($_POST['rebate_rate_percent'] ?? 0);
$total_rebate_budget = (float)($_POST['total_rebate_budget'] ?? 0);
$rebate_base = $_POST['rebate_base'] ?? 'profit';
$rebate_type = $_POST['rebate_type'] ?? 'rate';
$rebate_mode = $_POST['rebate_mode'] ?? 'weighted';
$rebate_value = $rebate_rate_percent;

if ($year <= 2020 || empty($start_date) || empty($end_date) || $total_profit <= 0 || $rebate_rate_percent <= 0 || $total_rebate_budget <= 0) {
    redirect_back('ข้อมูลไม่ถูกต้อง กรุณากรอกข้อมูลสำคัญให้ครบ (ปี, ช่วงวันที่, กำไร, อัตรา%)', true);
}

try {
    $pdo->beginTransaction();

    // 1. ตรวจสอบว่าปีนี้ถูกสร้างไปหรือยัง
    $check = $pdo->prepare("SELECT COUNT(*) FROM rebate_periods WHERE year = ?");
    $check->execute([$year]);
    if ($check->fetchColumn() > 0) {
        redirect_back("งวดเฉลี่ยคืนสำหรับปี $year ได้ถูกสร้างไปแล้ว", true);
    }

    // 2. [แก้ไข] ดึงยอดซื้อรวม โดย JOIN ด้วย "บ้านเลขที่"
    $sql_purchases = "
        SELECT 
            COALESCE(m.id, mg.id, c.id) AS member_id,
            COALESCE(m.member_type, mg.member_type, c.member_type) AS member_type,
            SUM(s.total_amount) as total_purchase
        FROM sales s
        
        /* Join สมาชิก (members) ด้วยบ้านเลขที่ */
        LEFT JOIN (
            SELECT id, house_number, 'member' as member_type 
            FROM members WHERE is_active = 1 AND house_number IS NOT NULL AND house_number != ''
        ) m ON s.household_no = m.house_number
        
        /* Join ผู้บริหาร (managers) ด้วยบ้านเลขที่ */
        LEFT JOIN (
            SELECT id, house_number, 'manager' as member_type
            FROM managers WHERE house_number IS NOT NULL AND house_number != ''
        ) mg ON s.household_no = mg.house_number
        
        /* Join กรรมการ (committees) ด้วยบ้านเลขที่ */
        LEFT JOIN (
            SELECT id, house_number, 'committee' as member_type
            FROM committees WHERE house_number IS NOT NULL AND house_number != ''
        ) c ON s.household_no = c.house_number

        WHERE s.sale_date BETWEEN ? AND ?
          AND s.household_no IS NOT NULL AND s.household_no != ''
          /* ต้องมั่นใจว่า Join เจออย่างน้อย 1 ตาราง */
          AND COALESCE(m.id, mg.id, c.id) IS NOT NULL 
        
        GROUP BY member_id, member_type
        HAVING total_purchase > 0
    ";
    
    $stmt_purchases = $pdo->prepare($sql_purchases);
    $stmt_purchases->execute([$start_date, $end_date]);
    $all_purchases = $stmt_purchases->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_purchases)) {
        redirect_back('ไม่พบยอดซื้อของสมาชิก (ที่ผูกกับบ้านเลขที่) ในปีที่เลือก', true);
    }

    $total_purchase_amount = array_sum(array_column($all_purchases, 'total_purchase'));
    $buyers_count = count($all_purchases);
    $rebate_per_baht = 0;

    if ($rebate_mode === 'weighted') { // "คนซื้อเยอะได้เยอะ"
        $rebate_per_baht = ($total_purchase_amount > 0) ? ($total_rebate_budget / $total_purchase_amount) : 0;
    }

    // 3. สร้างงวดเฉลี่ยคืนหลัก
    $ins_period = $pdo->prepare("
        INSERT INTO rebate_periods
            (year, period_name, start_date, end_date, total_profit_snapshot, rebate_base, rebate_type, rebate_value,
             total_rebate_budget, total_purchase_amount, rebate_per_baht, status, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $ins_period->execute([
        $year, $period_name, $start_date, $end_date, $total_profit, $rebate_base, $rebate_type, $rebate_value,
        $total_rebate_budget, $total_purchase_amount, $rebate_per_baht
    ]);
    $period_id = $pdo->lastInsertId();

    // 4. เตรียมสร้างรายการจ่ายเฉลี่ยคืน
    $ins_payment = $pdo->prepare("
        INSERT INTO rebate_payments
            (period_id, member_id, member_type, purchase_amount_at_time, rebate_amount, payment_status)
        VALUES
            (?, ?, ?, ?, ?, 'pending')
    ");

    foreach ($all_purchases as $member) {
        $purchase_amount = (float)$member['total_purchase'];
        $rebate_amount = 0;

        if ($rebate_mode === 'weighted') { // "คนซื้อเยอะได้เยอะ"
            $rebate_amount = $purchase_amount * $rebate_per_baht;
        } else { // (กรณีแบ่งเท่ากัน)
            $rebate_amount = $total_rebate_budget / $buyers_count;
        }

        if ($rebate_amount > 0) {
            $ins_payment->execute([
                $period_id,
                (int)$member['member_id'],
                $member['member_type'],
                $purchase_amount,
                $rebate_amount
            ]);
        }
    }

    $pdo->commit();
    redirect_back("สร้างงวดเฉลี่ยคืนปี $year สำเร็จ สร้างรายการจ่ายสำหรับสมาชิก $buyers_count คน (จากบ้านเลขที่)");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Rebate Create Error: " . $e->getMessage());
    redirect_back('เกิดข้อผิดพลาด: ' . $e->getMessage(), true);
}
?>