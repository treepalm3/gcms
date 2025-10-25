<?php
// dividend_member_history.php — API ประวัติรับปันผล/เฉลี่ยคืน (JSON)
session_start();
date_default_timezone_set('Asia/Bangkok');
header('Content-Type: application/json; charset=utf-8');

// ===== Auth guard =====
if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}
$role = $_SESSION['role'] ?? 'guest';
if (!in_array($role, ['admin','manager','committee'], true)) {
    echo json_encode(['ok' => false, 'error' => 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== DB connect =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องกำหนด $pdo ในไฟล์นี้
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['ok' => false, 'error' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== Helpers =====
function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
function dth($s, $fmt = 'd/m/Y') {
    if (empty($s) || strpos($s, '0000-00-00') === 0) return '-';
    $t = strtotime($s);
    return $t ? date($fmt, $t) : '-';
}

// ===== Inputs =====
$member_id = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$member_type = $_GET['member_type'] ?? '';
$allowed_types = ['member','manager','committee','admin'];
if ($member_id <= 0 || !in_array($member_type, $allowed_types, true)) {
    echo json_encode(['ok' => false, 'error' => 'พารามิเตอร์ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== Prepare containers =====
$history = [];
$total_dividend_paid = 0.0;
$total_dividend_pending = 0.0;
$total_rebate_paid = 0.0;
$total_rebate_pending = 0.0;

try {
    // ===== Dividends (ตามหุ้น) =====
    // ตาราง: dividend_payments(paid_at, payment_status 'pending'|'paid'), dividend_periods(status 'pending'|'approved'|'paid')
    $sqlDiv = "
        SELECT 
            p.`year`,
            p.`status` AS period_status,
            p.`dividend_rate`,
            dp.dividend_amount AS amount,
            dp.payment_status,
            dp.shares_at_time,
            dp.paid_at AS payment_dt
        FROM dividend_payments dp
        JOIN dividend_periods p ON dp.period_id = p.id
        WHERE dp.member_id = :mid AND dp.member_type = :mtype
        ORDER BY p.`year` DESC, p.id DESC
    ";
    $st = $pdo->prepare($sqlDiv);
    $st->execute([':mid' => $member_id, ':mtype' => $member_type]);

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $amount = (float)$row['amount'];
        // ทำสถานะที่ส่งไปหน้า UI: ถ้าจ่ายแล้ว => paid, ถ้ายังไม่จ่ายแต่ period อนุมัติ => approved, ไม่งั้น => pending
        $status_effective = ($row['payment_status'] === 'paid')
            ? 'paid'
            : (($row['period_status'] ?? '') === 'approved' ? 'approved' : 'pending');

        if ($status_effective === 'paid') $total_dividend_paid += $amount;
        else                               $total_dividend_pending += $amount;

        $shares_at_time = (int)($row['shares_at_time'] ?? 0);
        $rate = isset($row['dividend_rate']) ? (float)$row['dividend_rate'] : null;

        $history[] = [
            'year' => (int)$row['year'],
            'type' => 'ปันผล (หุ้น)',
            'amount' => $amount,
            'amount_formatted' => '฿' . nf($amount, 2),
            'payment_status' => $status_effective,
            'payment_date' => $row['payment_dt'],
            'payment_date_formatted' => dth($row['payment_dt']),
            'shares_at_time' => $shares_at_time,
            'dividend_rate'  => $rate,
            // เผื่อ UI ต้องการสตริงสรุปรายการ
            'details' => number_format($shares_at_time) . ' หุ้น' . ($rate !== null ? (' @ ' . nf($rate, 1) . '%') : '')
        ];
    }

    // ===== Rebates (เฉลี่ยคืนตามยอดซื้อ) =====
    // ตาราง: rebate_payments(paid_at, payment_status), rebate_periods(status, rebate_per_baht, rebate_type, rebate_value)
    $sqlRb = "
        SELECT 
            r.`year`,
            r.`status` AS period_status,
            r.rebate_type,
            r.rebate_value,
            r.rebate_per_baht,
            rp.rebate_amount AS amount,
            rp.payment_status,
            rp.purchase_amount_at_time,
            rp.paid_at AS payment_dt
        FROM rebate_payments rp
        JOIN rebate_periods r ON rp.period_id = r.id
        WHERE rp.member_id = :mid AND rp.member_type = :mtype
        ORDER BY r.`year` DESC, r.id DESC
    ";
    $st2 = $pdo->prepare($sqlRb);
    $st2->execute([':mid' => $member_id, ':mtype' => $member_type]);

    while ($row = $st2->fetch(PDO::FETCH_ASSOC)) {
        $amount = (float)$row['amount'];
        $status_effective = ($row['payment_status'] === 'paid')
            ? 'paid'
            : (($row['period_status'] ?? '') === 'approved' ? 'approved' : 'pending');

        if ($status_effective === 'paid') $total_rebate_paid += $amount;
        else                               $total_rebate_pending += $amount;

        $purchase = isset($row['purchase_amount_at_time']) ? (float)$row['purchase_amount_at_time'] : null;
        $per_baht = isset($row['rebate_per_baht']) ? (float)$row['rebate_per_baht'] : null;
        $rebate_type = $row['rebate_type'] ?? 'rate';
        $rebate_val = isset($row['rebate_value']) ? (float)$row['rebate_value'] : null;

        // รายละเอียดเพื่อแสดงในตาราง (สอดคล้องกับ JS ฝั่งหน้า)
        if ($purchase !== null) {
            if ($per_baht && $per_baht > 0) {
                $detail = 'ซื้อ ' . nf($purchase, 2) . ' @ ' . nf($per_baht, 4) . '/บาท';
            } elseif ($rebate_type === 'rate' && $rebate_val !== null) {
                $detail = 'ซื้อ ' . nf($purchase, 2) . ' @ ' . nf($rebate_val, 1) . '%';
            } else {
                $detail = 'ซื้อ ' . nf($purchase, 2);
            }
        } else {
            $detail = null;
        }

        $history[] = [
            'year' => (int)$row['year'],
            'type' => 'เฉลี่ยคืน (ซื้อ)',
            'amount' => $amount,
            'amount_formatted' => '฿' . nf($amount, 2),
            'payment_status' => $status_effective,
            'payment_date' => $row['payment_dt'],
            'payment_date_formatted' => dth($row['payment_dt']),
            'purchase_amount_at_time' => $purchase,
            'rebate_per_baht' => $per_baht,
            'rebate_type' => $rebate_type,
            'rebate_value' => $rebate_val,
            'details' => $detail
        ];
    }

    // ===== Sort ให้ออกปีล่าสุดก่อน และให้รายการปันผลมาก่อนเฉลี่ยคืนในปีเดียวกัน =====
    usort($history, function($a, $b) {
        $c = $b['year'] <=> $a['year'];
        if ($c !== 0) return $c;
        $rank = ['ปันผล (หุ้น)' => 0, 'เฉลี่ยคืน (ซื้อ)' => 1];
        return ($rank[$a['type']] ?? 9) <=> ($rank[$b['type']] ?? 9);
    });

    // ===== Summary =====
    $summary = [
        'total_received' => $total_dividend_paid,
        'total_pending' => $total_dividend_pending,
        'total_rebate_received' => $total_rebate_paid,
        'total_rebate_pending' => $total_rebate_pending,

        'total_received_formatted' => '฿' . nf($total_dividend_paid, 2),
        'total_pending_formatted' => '฿' . nf($total_dividend_pending, 2),
        'total_rebate_received_formatted' => '฿' . nf($total_rebate_paid, 2),
        'total_rebate_pending_formatted' => '฿' . nf($total_rebate_pending, 2),
    ];

    echo json_encode([
        'ok' => true,
        'member_id' => $member_id,
        'member_type' => $member_type,
        'summary' => $summary,
        'history' => $history
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('dividend_member_history error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'เกิดข้อผิดพลาดในการดึงข้อมูล'], JSON_UNESCAPED_UNICODE);
}
