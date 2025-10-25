<?php
// dividend_member_history.php — [ใหม่] API อ่านประวัติรับปันผล/เฉลี่ยคืนของสมาชิก (JSON)
session_start();
date_default_timezone_set('Asia/Bangkok');
header('Content-Type: application/json; charset=utf-8');

// ===== Guard =====
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}
$role = $_SESSION['role'] ?? 'guest';
if (!in_array($role, ['committee','admin','manager'], true)) {
    echo json_encode(['ok' => false, 'error' => 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== DB =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['ok' => false, 'error' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== Helpers =====
function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
function dth($s, $fmt = 'd/m/Y') {
    if (empty($s) || strpos($s, '0000-00-00') === 0) return '-';
    $t = strtotime($s); return $t ? date($fmt, $t) : '-';
}
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb");
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:cl");
    $st->execute([':db'=>$db, ':tb'=>$table, ':cl'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

// ===== Inputs =====
$member_id = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$member_type = $_GET['member_type'] ?? '';
$allowed_types = ['member','manager','committee','admin'];
if ($member_id <= 0 || !in_array($member_type, $allowed_types, true)) {
    echo json_encode(['ok' => false, 'error' => 'พารามิเตอร์ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== Prepare =====
$history = [];
$total_dividend_paid = 0.0;
$total_dividend_pending = 0.0;
$total_rebate_paid = 0.0;
$total_rebate_pending = 0.0;

// ===== Fetch =====
try {
    // --- Dividend Payments ---
    if (table_exists($pdo, 'dividend_payments') && table_exists($pdo, 'dividend_periods')) {
        $has_shares_at_time = col_exists($pdo, 'dividend_payments', 'shares_at_time');
        $has_payment_date   = col_exists($pdo, 'dividend_payments', 'payment_date');

        $sql = "
            SELECT 
              p.`year`,
              dp.dividend_amount AS amount,
              dp.payment_status,
              p.dividend_rate,
              ".($has_shares_at_time ? "dp.shares_at_time" : "NULL")." AS shares_at_time,
              ".($has_payment_date ? "dp.payment_date" : "NULL")." AS payment_date
            FROM dividend_payments dp
            JOIN dividend_periods p ON dp.period_id = p.id
            WHERE dp.member_id = :mid AND dp.member_type = :mtype
            ORDER BY p.`year` DESC, p.id DESC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':mid'=>$member_id, ':mtype'=>$member_type]);

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $amount = (float)($row['amount'] ?? 0);
            $status = $row['payment_status'] ?? 'pending';
            if ($status === 'paid') $total_dividend_paid += $amount;
            else $total_dividend_pending += $amount;

            $shares_at_time = isset($row['shares_at_time']) ? (float)$row['shares_at_time'] : null;
            $rate = isset($row['dividend_rate']) ? (float)$row['dividend_rate'] : null;
            $details = null;
            if ($shares_at_time !== null && $rate !== null) {
                $details = number_format($shares_at_time) . " หุ้น @ " . nf($rate, 1) . "%";
            }

            $history[] = [
                'year' => (int)$row['year'],
                'type' => 'ปันผล (หุ้น)',
                'amount' => $amount,
                'amount_formatted' => '฿' . nf($amount, 2),
                'payment_status' => $status,
                'payment_date' => $row['payment_date'] ?? null,
                'payment_date_formatted' => dth($row['payment_date'] ?? null),
                'shares_at_time' => $shares_at_time,   // ให้ JS ใช้สร้าง detail เองได้
                'dividend_rate'  => $rate,
                'details' => $details                  // และมี details สำเร็จรูปไว้ให้ด้วย (compat)
            ];
        }
    }

    // --- Rebate Payments ---
    if (table_exists($pdo, 'rebate_payments') && table_exists($pdo, 'rebate_periods')) {
        $has_purchase_amount = col_exists($pdo, 'rebate_payments', 'purchase_amount_at_time') || col_exists($pdo, 'rebate_payments', 'purchase_amount');
        $has_payment_date    = col_exists($pdo, 'rebate_payments', 'payment_date');

        // รองรับชื่อคอลัมน์ purchase_amount vs purchase_amount_at_time
        $purchase_col = col_exists($pdo, 'rebate_payments', 'purchase_amount_at_time') ? 'purchase_amount_at_time'
                      : (col_exists($pdo, 'rebate_payments', 'purchase_amount') ? 'purchase_amount' : null);

        $sql = "
            SELECT 
              r.`year`,
              rp.rebate_amount AS amount,
              rp.payment_status,
              r.rebate_type,
              r.rebate_value,
              r.rebate_per_baht,
              ".($purchase_col ? "rp.`$purchase_col`" : "NULL")." AS purchase_amount_at_time,
              ".($has_payment_date ? "rp.payment_date" : "NULL")." AS payment_date
            FROM rebate_payments rp
            JOIN rebate_periods r ON rp.period_id = r.id
            WHERE rp.member_id = :mid AND rp.member_type = :mtype
            ORDER BY r.`year` DESC, r.id DESC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':mid'=>$member_id, ':mtype'=>$member_type]);

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $amount = (float)($row['amount'] ?? 0);
            $status = $row['payment_status'] ?? 'pending';
            if ($status === 'paid') $total_rebate_paid += $amount; else $total_rebate_pending += $amount;

            $per_baht = isset($row['rebate_per_baht']) ? (float)$row['rebate_per_baht'] : null;
            $purchase = isset($row['purchase_amount_at_time']) ? (float)$row['purchase_amount_at_time'] : null;
            $details = null;
            if ($purchase !== null) {
                if ($per_baht && $per_baht > 0) {
                    $details = 'ซื้อ ' . nf($purchase, 2) . ' @ ' . nf($per_baht, 4) . '/บาท';
                } elseif (!empty($row['rebate_value'])) {
                    $details = 'ซื้อ ' . nf($purchase, 2) . ' @ ' . nf($row['rebate_value'], 1) . '%';
                }
            }

            $history[] = [
                'year' => (int)$row['year'],
                'type' => 'เฉลี่ยคืน (ซื้อ)',
                'amount' => $amount,
                'amount_formatted' => '฿' . nf($amount, 2),
                'payment_status' => $status,
                'payment_date' => $row['payment_date'] ?? null,
                'payment_date_formatted' => dth($row['payment_date'] ?? null),
                'purchase_amount_at_time' => $purchase, // ให้ JS ใช้ประกอบ detail ได้
                'rebate_per_baht' => $per_baht,
                'rebate_type' => $row['rebate_type'] ?? null,
                'rebate_value' => isset($row['rebate_value']) ? (float)$row['rebate_value'] : null,
                'details' => $details                      // details สำเร็จรูป (compat)
            ];
        }
    }

    // --- Sort (ปีล่าสุดก่อน) ---
    usort($history, function($a, $b) {
        // ปี DESC, แล้วตามชนิด (ปันผลมาก่อน)
        $c = ($b['year'] <=> $a['year']);
        if ($c !== 0) return $c;
        $rank = ['ปันผล (หุ้น)' => 0, 'เฉลี่ยคืน (ซื้อ)' => 1];
        return ($rank[$a['type']] ?? 9) <=> ($rank[$b['type']] ?? 9);
    });

    // --- Summary ---
    $summary = [
        'total_received'               => (float)$total_dividend_paid,
        'total_pending'                => (float)$total_dividend_pending,
        'total_rebate_received'        => (float)$total_rebate_paid,
        'total_rebate_pending'         => (float)$total_rebate_pending,

        'total_received_formatted'        => '฿' . nf($total_dividend_paid, 2),
        'total_pending_formatted'         => '฿' . nf($total_dividend_pending, 2),
        'total_rebate_received_formatted' => '฿' . nf($total_rebate_paid, 2),
        'total_rebate_pending_formatted'  => '฿' . nf($total_rebate_pending, 2)
    ];

    echo json_encode([
        'ok' => true,
        'member_id' => $member_id,
        'member_type' => $member_type,
        'summary' => $summary,
        'history' => $history
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('dividend_member_history error: '.$e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'เกิดข้อผิดพลาดในการดึงข้อมูล: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
