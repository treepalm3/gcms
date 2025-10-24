<?php
// dividend.php — จัดการปันผลและเฉลี่ยคืนสหกรณ์

session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
    exit();
}

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
    $current_name = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
    $current_role = $_SESSION['role'] ?? 'guest';
    if ($current_role !== 'admin') {
        header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        exit();
    }
} catch (Throwable $e) {
    header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$dividend_periods = [];
$members_dividends = [];
$error_message = null;

try {
    // 1) งวดปันผล (รายปี) - เพิ่มฟิลด์เฉลี่ยคืน
    $dividend_periods = $pdo->query("
        SELECT id, `year`, start_date, end_date, period_name, 
               total_profit, dividend_rate, patronage_rate,
               total_shares_at_time, total_dividend_amount, total_patronage_amount,
               status, payment_date, created_at, approved_by
        FROM dividend_periods
        ORDER BY `year` DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2) รวมสมาชิกทุกประเภท
    $all_members = [];
    
    // 2.1) สมาชิกทั่วไป
    try {
        $stmt = $pdo->query("
            SELECT 
                m.id AS member_id,
                m.member_code,
                u.full_name,
                m.shares,
                'member' AS member_type
            FROM members m
            JOIN users u ON m.user_id = u.id
            WHERE m.is_active = 1
        ");
        $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        error_log("Error fetching members: " . $e->getMessage());
    }
    
    // 2.2) ผู้บริหาร
    try {
        $stmt = $pdo->query("
            SELECT 
                mg.id AS member_id,
                CONCAT('MGR-', LPAD(mg.id, 3, '0')) AS member_code,
                u.full_name,
                mg.shares,
                'manager' AS member_type
            FROM managers mg
            JOIN users u ON mg.user_id = u.id
        ");
        $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        error_log("Error fetching managers: " . $e->getMessage());
    }
    
    // 2.3) กรรมการ
    try {
        $stmt = $pdo->query("
            SELECT 
                c.id AS member_id,
                c.committee_code AS member_code,
                u.full_name,
                COALESCE(c.shares, 0) AS shares,
                'committee' AS member_type
            FROM committees c
            JOIN users u ON c.user_id = u.id
        ");
        $all_members = array_merge($all_members, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        error_log("Error fetching committees: " . $e->getMessage());
    }
    
    // เรียงตามรหัส
    usort($all_members, function($a, $b) {
        return strcmp($a['member_code'] ?? '', $b['member_code'] ?? '');
    });

    // สร้าง array สำหรับแสดงผล - เพิ่มข้อมูลยอดซื้อและเฉลี่ยคืน
    $members_dividends = [];
    foreach ($all_members as $row) {
        $key = $row['member_type'] . '_' . $row['member_id'];
        
        // ดึงยอดซื้อน้ำมันรวม (ถ้ามีตาราง fuel_purchases)
        $total_purchases = 0;
        try {
            $purchase_stmt = $pdo->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total
                FROM fuel_purchases
                WHERE member_id = ? AND member_type = ?
            ");
            $purchase_stmt->execute([$row['member_id'], $row['member_type']]);
            $total_purchases = (float)$purchase_stmt->fetchColumn();
        } catch (Throwable $e) {
            // ถ้าไม่มีตารางก็ข้าม
        }
        
        $members_dividends[$key] = [
            'id' => $row['member_id'],
            'code' => $row['member_code'],
            'member_name' => $row['full_name'],
            'shares' => (int)$row['shares'],
            'total_purchases' => $total_purchases,
            'type' => $row['member_type'],
            'type_th' => [
                'member' => 'สมาชิก',
                'manager' => 'ผู้บริหาร',
                'committee' => 'กรรมการ'
            ][$row['member_type']] ?? 'อื่นๆ',
            'dividend_payments' => [],
            'patronage_payments' => [],
            'total_dividend_received' => 0.0,
            'total_patronage_received' => 0.0,
            'total_received' => 0.0
        ];
    }

    // 3) การจ่ายปันผล
    $dividend_payments = $pdo->query("
        SELECT dp.member_id, dp.member_type, dp.period_id, 
               dp.dividend_amount, dp.payment_status
        FROM dividend_payments dp
    ");

    foreach ($dividend_payments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $key = $payment['member_type'] . '_' . $payment['member_id'];
        if (!isset($members_dividends[$key])) continue;

        $pid = (int)$payment['period_id'];
        $amt = (float)$payment['dividend_amount'];

        $members_dividends[$key]['dividend_payments'][$pid] = $amt;
        
        if (($payment['payment_status'] ?? 'pending') === 'paid') {
            $members_dividends[$key]['total_dividend_received'] += $amt;
            $members_dividends[$key]['total_received'] += $amt;
        }
    }
    
    // 4) การจ่ายเฉลี่ยคืน
    try {
        $patronage_payments = $pdo->query("
            SELECT pp.member_id, pp.member_type, pp.period_id, 
                   pp.patronage_amount, pp.payment_status
            FROM patronage_payments pp
        ");

        foreach ($patronage_payments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $key = $payment['member_type'] . '_' . $payment['member_id'];
            if (!isset($members_dividends[$key])) continue;

            $pid = (int)$payment['period_id'];
            $amt = (float)$payment['patronage_amount'];

            $members_dividends[$key]['patronage_payments'][$pid] = $amt;
            
            if (($payment['payment_status'] ?? 'pending') === 'paid') {
                $members_dividends[$key]['total_patronage_received'] += $amt;
                $members_dividends[$key]['total_received'] += $amt;
            }
        }
    } catch (Throwable $e) {
        // ถ้าไม่มีตาราง patronage_payments ก็ข้าม
        error_log("Patronage payments table may not exist: " . $e->getMessage());
    }

} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    error_log("Dividend page error: " . $e->getMessage());
}

// ดึงข้อมูลระบบ
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
    $st = $pdo->query("SELECT site_name FROM settings WHERE id=1");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $site_name = $r['site_name'] ?: $site_name;
    }
} catch (Throwable $e) {}

$role_th_map = [
    'admin'=>'ผู้ดูแลระบบ', 
    'manager'=>'ผู้บริหาร',
    'employee'=>'พนักงาน', 
    'member'=>'สมาชิกสหกรณ์',
    'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// คำนวณสถิติ
$total_dividend_paid = 0;
$total_patronage_paid = 0;
$pending_dividend = 0;
$pending_patronage = 0;
$total_members = count($members_dividends);
$total_shares = array_sum(array_column($members_dividends, 'shares'));
$total_purchases = array_sum(array_column($members_dividends, 'total_purchases'));

try {
    $stats = $pdo->query("
        SELECT 
            (SELECT COALESCE(SUM(total_dividend_amount), 0) 
             FROM dividend_periods WHERE status = 'paid') as total_dividend_paid,
            (SELECT COALESCE(SUM(total_patronage_amount), 0) 
             FROM dividend_periods WHERE status = 'paid') as total_patronage_paid,
            (SELECT COALESCE(SUM(total_dividend_amount), 0) 
             FROM dividend_periods WHERE status = 'approved') as pending_dividend,
            (SELECT COALESCE(SUM(total_patronage_amount), 0) 
             FROM dividend_periods WHERE status = 'approved') as pending_patronage
    ")->fetch(PDO::FETCH_ASSOC);
    
    $total_dividend_paid = (float)($stats['total_dividend_paid'] ?? 0);
    $total_patronage_paid = (float)($stats['total_patronage_paid'] ?? 0);
    $pending_dividend = (float)($stats['pending_dividend'] ?? 0);
    $pending_patronage = (float)($stats['pending_patronage'] ?? 0);
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ปันผลและเฉลี่ยคืน | <?= htmlspecialchars($site_name) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
    <style>
        body { font-family: 'Prompt', sans-serif; background: #f5f7fa; }
        .status-paid { background: #d1f4dd; color: #0f5132; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .status-approved { background: #fff3cd; color: #664d03; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .status-pending { background: #f8d7da; color: #842029; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .card-stat { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s, box-shadow 0.2s; border-radius: 16px; }
        .card-stat:hover { transform: translateY(-4px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .stat-icon { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; }
        .bg-gradient-dividend { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-gradient-patronage { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .bg-gradient-info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .bg-gradient-total { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .section-title { font-size: 1.75rem; font-weight: 700; color: #2d3748; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 3px solid #667eea; }
        .formula-box { background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%); border: 2px solid #667eea40; border-radius: 12px; padding: 1.5rem; margin: 1rem 0; font-family: 'Courier New', monospace; }
        .example-box { background: #fff; border-left: 4px solid #667eea; border-radius: 8px; padding: 1.25rem; margin: 1rem 0; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .comparison-table { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .comparison-table th { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: 600; padding: 1rem; }
        .comparison-table td { padding: 1rem; border-bottom: 1px solid #e2e8f0; }
        .tab-custom { border: none; border-bottom: 3px solid #e2e8f0; }
        .tab-custom .nav-link { color: #64748b; font-weight: 500; border: none; border-bottom: 3px solid transparent; padding: 1rem 1.5rem; }
        .tab-custom .nav-link.active { color: #667eea; border-bottom-color: #667eea; background: none; }
        .member-row { transition: all 0.2s; }
        .member-row:hover { background-color: #f8fafc; transform: scale(1.01); }
    </style>
</head>
<body>

<!-- Modal: เฉลยการคำนวณ -->
<div class="modal fade" id="modalExplanation" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-gradient-dividend text-white">
                <h5 class="modal-title">
                    <i class="bi bi-mortarboard me-2"></i>เฉลย: วิธีคำนวณปันผลและเฉลี่ยคืน
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                
                <!-- ความแตกต่าง -->
                <div class="mb-5">
                    <h5 class="fw-bold text-primary mb-3">
                        <i class="bi bi-arrow-left-right me-2"></i>ความแตกต่างระหว่างปันผลและเฉลี่ยคืน
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card border-primary h-100">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>ปันผล (Dividend)</h6>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>จ่ายตามสัดส่วน <strong>"หุ้น"</strong></li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>คนมีหุ้นมาก ได้มาก</li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>เป็นผลตอบแทนจากการลงทุน</li>
                                        <li><i class="bi bi-check-circle-fill text-success me-2"></i>มาจากกำไรสุทธิของสหกรณ์</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-danger h-100">
                                <div class="card-header bg-danger text-white">
                                    <h6 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>เฉลี่ยคืน (Patronage Refund)</h6>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-danger me-2"></i>จ่ายตาม <strong>"ยอดซื้อน้ำมัน"</strong></li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-danger me-2"></i>คนซื้อเยอะ ได้มาก</li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-danger me-2"></i>เป็นผลตอบแทนจากการใช้บริการ</li>
                                        <li><i class="bi bi-check-circle-fill text-danger me-2"></i>มาจากส่วนแบ่งกำไรที่จัดสรร</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ปันผล -->
                <div class="mb-5">
                    <h5 class="fw-bold text-primary mb-3">
                        <i class="bi bi-calculator me-2"></i>สูตรการคำนวณปันผล
                    </h5>
                    <div class="formula-box">
                        <strong class="text-primary">① จำนวนปันผลทั้งหมด</strong><br>
                        = กำไรสุทธิ × (อัตราปันผล ÷ 100)<br><br>
                        
                        <strong class="text-primary">② ปันผลต่อหุ้น</strong><br>
                        = จำนวนปันผลทั้งหมด ÷ จำนวนหุ้นทั้งหมด<br><br>
                        
                        <strong class="text-primary">③ ปันผลที่สมาชิกได้รับ</strong><br>
                        = ปันผลต่อหุ้น × จำนวนหุ้นของสมาชิก
                    </div>
                    
                    <div class="example-box">
                        <h6 class="text-success fw-bold mb-3">✅ ตัวอย่างการคำนวณปันผล</h6>
                        <p><strong>สมมติ:</strong></p>
                        <ul>
                            <li>กำไรสุทธิประจำปี = 1,000,000 บาท</li>
                            <li>อัตราปันผล = 20%</li>
                            <li>จำนวนหุ้นทั้งหมด = 5,000 หุ้น</li>
                        </ul>
                        
                        <p class="mt-3"><strong>การคำนวณ:</strong></p>
                        <div class="bg-light p-3 rounded">
                            <p class="mb-2"><span class="badge bg-primary">1</span> จำนวนปันผลทั้งหมด = 1,000,000 × (20 ÷ 100) = <strong class="text-success">200,000 บาท</strong></p>
                            <p class="mb-2"><span class="badge bg-primary">2</span> ปันผลต่อหุ้น = 200,000 ÷ 5,000 = <strong class="text-success">40 บาท/หุ้น</strong></p>
                            <p class="mb-0"><span class="badge bg-primary">3</span> ตัวอย่างสมาชิก:</p>
                            <ul class="mt-2 mb-0">
                                <li>นายสมชาย (100 หุ้น) → 100 × 40 = <strong class="text-success">4,000 บาท</strong></li>
                                <li>นางสมหญิง (250 หุ้น) → 250 × 40 = <strong class="text-success">10,000 บาท</strong></li>
                                <li>นายสมศักดิ์ (50 หุ้น) → 50 × 40 = <strong class="text-success">2,000 บาท</strong></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- เฉลี่ยคืน -->
                <div class="mb-5">
                    <h5 class="fw-bold text-danger mb-3">
                        <i class="bi bi-calculator me-2"></i>สูตรการคำนวณเฉลี่ยคืน
                    </h5>
                    <div class="formula-box">
                        <strong class="text-danger">① จำนวนเฉลี่ยคืนทั้งหมด</strong><br>
                        = กำไรสุทธิ × (อัตราเฉลี่ยคืน ÷ 100)<br><br>
                        
                        <strong class="text-danger">② เฉลี่ยคืนต่อบาท</strong><br>
                        = จำนวนเฉลี่ยคืนทั้งหมด ÷ ยอดซื้อน้ำมันรวมทั้งหมด<br><br>
                        
                        <strong class="text-danger">③ เฉลี่ยคืนที่สมาชิกได้รับ</strong><br>
                        = เฉลี่ยคืนต่อบาท × ยอดซื้อน้ำมันของสมาชิก
                    </div>
                    
                    <div class="example-box">
                        <h6 class="text-danger fw-bold mb-3">✅ ตัวอย่างการคำนวณเฉลี่ยคืน</h6>
                        <p><strong>สมมติ:</strong></p>
                        <ul>
                            <li>กำไรสุทธิประจำปี = 1,000,000 บาท</li>
                            <li>อัตราเฉลี่ยคืน = 30%</li>
                            <li>ยอดซื้อน้ำมันรวมทั้งหมด = 15,000,000 บาท</li>
                        </ul>
                        
                        <p class="mt-3"><strong>การคำนวณ:</strong></p>
                        <div class="bg-light p-3 rounded">
                            <p class="mb-2"><span class="badge bg-danger">1</span> จำนวนเฉลี่ยคืนทั้งหมด = 1,000,000 × (30 ÷ 100) = <strong class="text-danger">300,000 บาท</strong></p>
                            <p class="mb-2"><span class="badge bg-danger">2</span> เฉลี่ยคืนต่อบาท = 300,000 ÷ 15,000,000 = <strong class="text-danger">0.02 บาท/บาท (2%)</strong></p>
                            <p class="mb-0"><span class="badge bg-danger">3</span> ตัวอย่างสมาชิก:</p>
                            <ul class="mt-2 mb-0">
                                <li>นายสมชาย (ซื้อ 50,000 บาท) → 50,000 × 0.02 = <strong class="text-danger">1,000 บาท</strong></li>
                                <li>นางสมหญิง (ซื้อ 200,000 บาท) → 200,000 × 0.02 = <strong class="text-danger">4,000 บาท</strong></li>
                                <li>นายสมศักดิ์ (ซื้อ 100,000 บาท) → 100,000 × 0.02 = <strong class="text-danger">2,000 บาท</strong></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- สรุปรวม -->
                <div>
                    <h5 class="fw-bold text-success mb-3">
                        <i class="bi bi-cash-stack me-2"></i>สรุป: สมาชิกได้รับทั้ง 2 ประเภท
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-bordered comparison-table">
                            <thead>
                                <tr>
                                    <th>สมาชิก</th>
                                    <th class="text-center">หุ้น</th>
                                    <th class="text-end">ปันผล</th>
                                    <th class="text-center">ยอดซื้อ</th>
                                    <th class="text-end">เฉลี่ยคืน</th>
                                    <th class="text-end">รวมทั้งหมด</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>นายสมชาย</strong></td>
                                    <td class="text-center">100 หุ้น</td>
                                    <td class="text-end text-primary">4,000 บาท</td>
                                    <td class="text-center">50,000 บาท</td>
                                    <td class="text-end text-danger">1,000 บาท</td>
                                    <td class="text-end"><strong class="text-success">5,000 บาท</strong></td>
                                </tr>
                                <tr>
                                    <td><strong>นางสมหญิง</strong></td>
                                    <td class="text-center">250 หุ้น</td>
                                    <td class="text-end text-primary">10,000 บาท</td>
                                    <td class="text-center">200,000 บาท</td>
                                    <td class="text-end text-danger">4,000 บาท</td>
                                    <td class="text-end"><strong class="text-success">14,000 บาท</strong></td>
                                </tr>
                                <tr>
                                    <td><strong>นายสมศักดิ์</strong></td>
                                    <td class="text-center">50 หุ้น</td>
                                    <td class="text-end text-primary">2,000 บาท</td>
                                    <td class="text-center">100,000 บาท</td>
                                    <td class="text-end text-danger">2,000 บาท</td>
                                    <td class="text-end"><strong class="text-success">4,000 บาท</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- แถบด้านบน -->
<nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">
            <i class="bi bi-cash-coin me-2"></i><?= htmlspecialchars($site_name) ?>
        </a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white">
                <i class="bi bi-person-circle me-1"></i>
                <?= htmlspecialchars($current_name) ?>
                <small class="opacity-75">(<?= htmlspecialchars($current_role_th) ?>)</small>
            </span>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">
    
    <!-- ปุ่มดูเฉลย -->
    <div class="mb-4">
        <button class="btn btn-lg btn-primary shadow" data-bs-toggle="modal" data-bs-target="#modalExplanation">
            <i class="bi bi-mortarboard me-2"></i>ดูเฉลยวิธีคำนวณปันผลและเฉลี่ยคืน
        </button>
    </div>

    <!-- สถิติภาพรวม -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="card card-stat h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-gradient-dividend text-white me-3">
                            <i class="bi bi-pie-chart-fill"></i>
                        </div>
                        <div>
                            <p class="text-muted mb-1 small">ปันผลจ่ายแล้ว</p>
                            <h4 class="mb-0 fw-bold">฿<?= number_format($total_dividend_paid, 2) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card card-stat h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-gradient-patronage text-white me-3">
                            <i class="bi bi-arrow-repeat"></i>
                        </div>
                        <div>
                            <p class="text-muted mb-1 small">เฉลี่ยคืนจ่ายแล้ว</p>
                            <h4 class="mb-0 fw-bold">฿<?= number_format($total_patronage_paid, 2) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card card-stat h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-gradient-info text-white me-3">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div>
                            <p class="text-muted mb-1 small">จำนวนสมาชิก</p>
                            <h4 class="mb-0 fw-bold"><?= number_format($total_members) ?></h4>
                            <small class="text-muted">หุ้นรวม: <?= number_format($total_shares) ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card card-stat h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-gradient-total text-white me-3">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div>
                            <p class="text-muted mb-1 small">รวมจ่ายทั้งหมด</p>
                            <h4 class="mb-0 fw-bold">฿<?= number_format($total_dividend_paid + $total_patronage_paid, 2) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ตารางสมาชิก -->
    <div class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="section-title mb-0">รายชื่อสมาชิกและสิทธิ์</h3>
            <div class="d-flex gap-2">
                <input type="text" class="form-control" id="searchMember" 
                       placeholder="ค้นหาสมาชิก..." style="width: 250px;">
                <button class="btn btn-success" onclick="exportMembers()">
                    <i class="bi bi-download me-1"></i>Export CSV
                </button>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="membersTable">
                        <thead class="table-light">
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อ-สกุล</th>
                                <th>ประเภท</th>
                                <th class="text-center">จำนวนหุ้น</th>
                                <th class="text-end">ยอดซื้อน้ำมัน</th>
                                <th class="text-end text-primary">ปันผลรับแล้ว</th>
                                <th class="text-end text-danger">เฉลี่ยคืนรับแล้ว</th>
                                <th class="text-end text-success">รวมทั้งหมด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members_dividends as $key => $mem): ?>
                            <tr class="member-row">
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($mem['code']) ?></span></td>
                                <td><strong><?= htmlspecialchars($mem['member_name']) ?></strong></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($mem['type_th']) ?></span></td>
                                <td class="text-center">
                                    <span class="badge bg-primary fs-6"><?= number_format($mem['shares']) ?></span>
                                </td>
                                <td class="text-end">฿<?= number_format($mem['total_purchases'], 2) ?></td>
                                <td class="text-end text-primary fw-bold">฿<?= number_format($mem['total_dividend_received'], 2) ?></td>
                                <td class="text-end text-danger fw-bold">฿<?= number_format($mem['total_patronage_received'], 2) ?></td>
                                <td class="text-end text-success fw-bold fs-5">฿<?= number_format($mem['total_received'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Search members
const searchInput = document.getElementById('searchMember');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const term = this.value.toLowerCase();
        document.querySelectorAll('#membersTable tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(term) ? '' : 'none';
        });
    });
}

// Export to CSV
function exportMembers() {
    const headers = ['รหัส', 'ชื่อ', 'ประเภท', 'หุ้น', 'ยอดซื้อ', 'ปันผล', 'เฉลี่ยคืน', 'รวม'];
    const rows = [headers];
    
    document.querySelectorAll('#membersTable tbody tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        const cells = tr.querySelectorAll('td');
        if (cells.length >= 8) {
            rows.push([
                cells[0].textContent.trim(),
                cells[1].textContent.trim(),
                cells[2].textContent.trim(),
                cells[3].textContent.trim(),
                cells[4].textContent.replace(/[฿,]/g, '').trim(),
                cells[5].textContent.replace(/[฿,]/g, '').trim(),
                cells[6].textContent.replace(/[฿,]/g, '').trim(),
                cells[7].textContent.replace(/[฿,]/g, '').trim()
            ]);
        }
    });
    
    const csv = rows.map(r => r.map(v => `"${(v||'').toString().replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `dividend_patronage_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>
</body>
</html>
