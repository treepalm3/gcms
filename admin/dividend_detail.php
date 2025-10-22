<?php
// dividend_detail.php — รายละเอียดงวดปันผล
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php');
    exit();
}

$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$year = (int)($_GET['year'] ?? 0);

if ($year <= 0) {
    header('Location: dividend.php?err=ระบุปีไม่ถูกต้อง');
    exit();
}

try {
    // ดึงข้อมูลงวด
    $stmt = $pdo->prepare("
        SELECT * FROM dividend_periods WHERE year = :year LIMIT 1
    ");
    $stmt->execute([':year' => $year]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$period) {
        header('Location: dividend.php?err=ไม่พบงวดปันผลปี ' . $year);
        exit();
    }
    
    // ดึงรายการจ่าย (ทุกประเภท)
    $payments_stmt = $pdo->prepare("
        SELECT 
            dp.*,
            CASE dp.member_type
                WHEN 'member' THEN m.member_code
                WHEN 'manager' THEN CONCAT('MGR-', LPAD(mg.id, 3, '0'))
                WHEN 'committee' THEN c.committee_code
            END AS code,
            CASE dp.member_type
                WHEN 'member' THEN u1.full_name
                WHEN 'manager' THEN u2.full_name
                WHEN 'committee' THEN u3.full_name
            END AS full_name
        FROM dividend_payments dp
        LEFT JOIN members m ON dp.member_type = 'member' AND dp.member_id = m.id
        LEFT JOIN users u1 ON m.user_id = u1.id
        LEFT JOIN managers mg ON dp.member_type = 'manager' AND dp.member_id = mg.id
        LEFT JOIN users u2 ON mg.user_id = u2.id
        LEFT JOIN committees c ON dp.member_type = 'committee' AND dp.member_id = c.id
        LEFT JOIN users u3 ON c.user_id = u3.id
        WHERE dp.period_id = :pid
        ORDER BY dp.member_type, code
    ");
    $payments_stmt->execute([':pid' => $period['id']]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // สรุปแยกตามประเภท
    $summary = [
        'member' => ['count' => 0, 'shares' => 0, 'amount' => 0],
        'manager' => ['count' => 0, 'shares' => 0, 'amount' => 0],
        'committee' => ['count' => 0, 'shares' => 0, 'amount' => 0]
    ];
    
    foreach ($payments as $p) {
        $type = $p['member_type'];
        if (isset($summary[$type])) {
            $summary[$type]['count']++;
            $summary[$type]['shares'] += (int)$p['shares_at_time'];
            $summary[$type]['amount'] += (float)$p['dividend_amount'];
        }
    }
    
} catch (Exception $e) {
    header('Location: dividend.php?err=' . urlencode($e->getMessage()));
    exit();
}

$site_name = 'ระบบบริหารจัดการปันผล';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายละเอียดปันผลปี <?= $year ?> | <?= htmlspecialchars($site_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-file-earmark-text me-2"></i>รายละเอียดงวดปันผลปี <?= $year ?></h2>
        <a href="dividend.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>

    <!-- ข้อมูลงวด -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">ข้อมูลงวด</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><th>ปี:</th><td><?= $period['year'] ?></td></tr>
                        <tr><th>ชื่องวด:</th><td><?= htmlspecialchars($period['period_name']) ?></td></tr>
                        <tr><th>กำไรสุทธิ:</th><td>฿<?= number_format($period['total_profit'], 2) ?></td></tr>
                        <tr><th>อัตราปันผล:</th><td><?= number_format($period['dividend_rate'], 2) ?>%</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><th>หุ้นรวม:</th><td><?= number_format($period['total_shares_at_time']) ?> หุ้น</td></tr>
                        <tr><th>ปันผลรวม:</th><td class="text-success fw-bold">฿<?= number_format($period['total_dividend_amount'], 2) ?></td></tr>
                        <tr><th>ปันผล/หุ้น:</th><td>฿<?= number_format($period['dividend_per_share'] ?? 0, 4) ?></td></tr>
                        <tr><th>สถานะ:</th><td><span class="badge bg-<?= $period['status'] === 'paid' ? 'success' : ($period['status'] === 'approved' ? 'warning' : 'secondary') ?>"><?= $period['status'] ?></span></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- สรุปแยกประเภท -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body">
                    <h6>สมาชิก</h6>
                    <p class="mb-0">
                        <?= $summary['member']['count'] ?> คน | 
                        <?= $summary['member']['shares'] ?> หุ้น<br>
                        <strong class="text-success">฿<?= number_format($summary['member']['amount'], 2) ?></strong>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body">
                    <h6>ผู้บริหาร</h6>
                    <p class="mb-0">
                        <?= $summary['manager']['count'] ?> คน | 
                        <?= $summary['manager']['shares'] ?> หุ้น<br>
                        <strong class="text-warning">฿<?= number_format($summary['manager']['amount'], 2) ?></strong>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body">
                    <h6>กรรมการ</h6>
                    <p class="mb-0">
                        <?= $summary['committee']['count'] ?> คน | 
                        <?= $summary['committee']['shares'] ?> หุ้น<br>
                        <strong class="text-info">฿<?= number_format($summary['committee']['amount'], 2) ?></strong>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- รายการจ่าย -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">รายการจ่ายปันผล (<?= count($payments) ?> รายการ)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>รหัส</th>
                            <th>ชื่อ</th>
                            <th>ประเภท</th>
                            <th class="text-center">หุ้น</th>
                            <th class="text-end">ปันผล</th>
                            <th class="text-center">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $i => $p): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars($p['code']) ?></strong></td>
                            <td><?= htmlspecialchars($p['full_name']) ?></td>
                            <td>
                                <span class="badge bg-<?= $p['member_type'] === 'member' ? 'primary' : ($p['member_type'] === 'manager' ? 'warning' : 'info') ?>">
                                    <?= ['member' => 'สมาชิก', 'manager' => 'ผู้บริหาร', 'committee' => 'กรรมการ'][$p['member_type']] ?? $p['member_type'] ?>
                                </span>
                            </td>
                            <td class="text-center"><?= number_format($p['shares_at_time']) ?></td>
                            <td class="text-end text-success fw-bold">฿<?= number_format($p['dividend_amount'], 2) ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?= $p['payment_status'] === 'paid' ? 'success' : 'secondary' ?>">
                                    <?= $p['payment_status'] ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>