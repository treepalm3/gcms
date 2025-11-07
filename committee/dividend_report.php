<?php
// committee/dividend_report.php — [แก้ไข] แก้ไข SQL Error 'Unknown column m.member_type'
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== Guard & DB ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit();
}
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ===== Role check ===== */
try {
  $current_name = $_SESSION['full_name'] ?? 'กรรมการ';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'committee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}

/* ===== Helpers ===== */
function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
function d($s, $fmt = 'd/m/Y') {
    if (empty($s) || strpos($s, '0000-00-00') === 0) return '-';
    $t = strtotime($s);
    return $t ? date($fmt, $t) : '-';
}

/* ===== Site settings ===== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $site_name = $r['comment'] ?: $site_name;
  }
} catch (Throwable $e) {}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',  'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

/* ==============================================
 *
 * ดึงข้อมูลสรุปสำหรับรายงาน
 *
 * ============================================== */
$error_message = null;
$report_data = []; // ['member' => [...], 'manager' => [...], 'committee' => [...]]
$dividend_period = null;
$rebate_period = null;
$chart_dividend_labels = [];
$chart_dividend_values = [];
$chart_rebate_labels = [];
$chart_rebate_values = [];

// กำหนดประเภทสมาชิกที่เราสนใจ
$member_types_of_interest = ['member', 'manager', 'committee', 'admin'];
foreach ($member_types_of_interest as $type) {
    $report_data[$type] = [
        'name' => $role_th_map[$type] ?? ucfirst($type),
        'count' => 0,
        'shares' => 0,
        'dividend_amount' => 0,
        'rebate_amount' => 0
    ];
}

try {
    // 1. หางวดปันผลล่าสุดที่อนุมัติหรือจ่ายแล้ว
    $stmt_div_period = $pdo->query("
        SELECT * FROM dividend_periods 
        WHERE status IN ('approved', 'paid') 
        ORDER BY year DESC, id DESC LIMIT 1
    ");
    $dividend_period = $stmt_div_period->fetch(PDO::FETCH_ASSOC);

    // 2. หางวดเฉลี่ยคืนล่าสุดที่อนุมัติหรือจ่ายแล้ว
    $stmt_reb_period = $pdo->query("
        SELECT * FROM rebate_periods 
        WHERE status IN ('approved', 'paid') 
        ORDER BY year DESC, id DESC LIMIT 1
    ");
    $rebate_period = $stmt_reb_period->fetch(PDO::FETCH_ASSOC);

    // 3. สรุปยอดปันผล (ตามหุ้น) แยกตามประเภทสมาชิก
    if ($dividend_period) {
        $div_id = (int)$dividend_period['id'];
        
        // [แก้ไข Query] Join 'members' (m) ด้วย 'dp.member_id' และ Join 'users' (u) ด้วย 'm.user_id'
        // ลบเงื่อนไข 'dp.member_type = m.member_type' ที่ผิด
        $sql_div = "
            SELECT 
                u.role AS member_type,
                COUNT(DISTINCT m.id) AS member_count,
                SUM(dp.shares_at_time) AS total_shares,
                SUM(dp.dividend_amount) AS total_dividend
            FROM dividend_payments dp
            JOIN members m ON dp.member_id = m.id
            JOIN users u ON m.user_id = u.id
            WHERE dp.period_id = :period_id
            GROUP BY u.role
        ";
        $stmt = $pdo->prepare($sql_div);
        $stmt->execute([':period_id' => $div_id]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $type = $row['member_type'];
            if (isset($report_data[$type])) {
                $report_data[$type]['count'] += (int)$row['member_count'];
                $report_data[$type]['shares'] = (int)$row['total_shares']; 
                $report_data[$type]['dividend_amount'] = (float)$row['total_dividend'];
                
                $chart_dividend_labels[] = $report_data[$type]['name'];
                $chart_dividend_values[] = $row['total_dividend'];
            }
        }
    }

    // 4. สรุปยอดเฉลี่ยคืน (ตามยอดซื้อ) แยกตามประเภทสมาชิก
    if ($rebate_period) {
        $reb_id = (int)$rebate_period['id'];

        // [แก้ไข Query] Join 'members' (m) ด้วย 'rp.member_id' และ Join 'users' (u) ด้วย 'm.user_id'
        // ลบเงื่อนไข 'rp.member_type = m.member_type' ที่ผิด
        $sql_reb = "
            SELECT 
                u.role AS member_type,
                COUNT(DISTINCT m.id) AS member_count,
                SUM(rp.rebate_amount) AS total_rebate
            FROM rebate_payments rp
            JOIN members m ON rp.member_id = m.id
            JOIN users u ON m.user_id = u.id
            WHERE rp.period_id = :period_id
            GROUP BY u.role
        ";
        $stmt = $pdo->prepare($sql_reb);
        $stmt->execute([':period_id' => $reb_id]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $type = $row['member_type'];
            if (isset($report_data[$type])) {
                if ($report_data[$type]['count'] === 0) {
                     $report_data[$type]['count'] = (int)$row['member_count'];
                }
                $report_data[$type]['rebate_amount'] = (float)$row['total_rebate'];
                
                $chart_rebate_labels[] = $report_data[$type]['name'];
                $chart_rebate_values[] = $row['total_rebate'];
            }
        }
    }

} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูลรายงาน: " . $e->getMessage();
}

// ลบประเภทสมาชิกที่ไม่มีข้อมูลเลยออกจากรายงาน
$report_data = array_filter($report_data, function($item) {
    return $item['count'] > 0 || $item['dividend_amount'] > 0 || $item['rebate_amount'] > 0;
});

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>รายงานสรุปปันผล | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    .stat-card h5 { font-size: 1rem; color: var(--bs-secondary-color); margin-bottom: 0.5rem; }
    .stat-card h3 { font-weight: 700; }
    .chart-container {
        height: 350px;
        position: relative;
    }
    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .container-fluid { padding: 0 !important; }
        .stat-card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
        main { padding: 0 !important; }
    }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary no-print">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
      <a class="navbar-brand" href="committee_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
    </div>
    <div class="d-flex align-items-center gap-3 ms-auto">
      <div class="nav-identity text-end d-none d-sm-block">
        <div class="nav-name"><?= htmlspecialchars($current_name) ?></div>
        <div class="nav-sub"><?= htmlspecialchars($current_role_th) ?></div>
      </div>
      <a href="profile.php" class="avatar-circle text-decoration-none"><?= htmlspecialchars($avatar_text) ?></a>
    </div>
  </div>
</nav>

<div class="offcanvas offcanvas-start no-print" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
    <nav class="sidebar-menu">
      <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
      <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
      <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
    </nav>
    <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4 no-print">
      <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a class="active" href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
      <div class="main-header mb-4 no-print">
        <h2 class="mb-0"><i class="bi bi-pie-chart-fill me-2"></i> รายงานสรุปการจัดสรร (แยกตามประเภท)</h2>
        <div class="d-flex gap-2">
            <a href="dividend.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กลับหน้ารวม</a>
            <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์รายงาน</button>
        </div>
      </div>

      <?php if ($error_message): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
      <?php endif; ?>

      <div class="row g-3 mb-4">
          <div class="col-md-6">
              <div class="stat-card h-100">
                  <h5 class="mb-3"><i class="fa-solid fa-gift me-2 text-success"></i>งวดปันผล (หุ้น) ที่ใช้สรุป</h5>
                  <?php if ($dividend_period): ?>
                      <h6 class="mb-1"><?= htmlspecialchars($dividend_period['period_name']) ?> (ปี <?= htmlspecialchars($dividend_period['year']) ?>)</h6>
                      <p class="mb-1">ยอดปันผลรวม: <strong class="text-success fs-5">฿<?= nf($dividend_period['total_dividend_amount']) ?></strong></p>
                      <p class="mb-0 text-muted">สถานะ: <?= htmlspecialchars($dividend_period['status']) ?> | วันที่จ่าย: <?= d($dividend_period['payment_date']) ?></p>
                  <?php else: ?>
                      <p class="text-muted mb-0">ไม่พบข้อมูลงวดปันผลที่อนุมัติหรือจ่ายแล้ว</p>
                  <?php endif; ?>
              </div>
          </div>
          <div class="col-md-6">
              <div class="stat-card h-100">
                  <h5 class="mb-3"><i class="bi bi-arrow-repeat me-2 text-info"></i>งวดเฉลี่ยคืน (ซื้อ) ที่ใช้สรุป</h5>
                  <?php if ($rebate_period): ?>
                      <h6 class="mb-1"><?= htmlspecialchars($rebate_period['period_name']) ?> (ปี <?= htmlspecialchars($rebate_period['year']) ?>)</h6>
                      <p class="mb-1">งบประมาณรวม: <strong class="text-info fs-5">฿<?= nf($rebate_period['total_rebate_budget']) ?></strong></p>
                      <p class="mb-0 text-muted">สถานะ: <?= htmlspecialchars($rebate_period['status']) ?> | วันที่จ่าย: <?= d($rebate_period['payment_date']) ?></p>
                  <?php else: ?>
                      <p class="text-muted mb-0">ไม่พบข้อมูลงวดเฉลี่ยคืนที่อนุมัติหรือจ่ายแล้ว</p>
                  <?php endif; ?>
              </div>
          </div>
      </div>
      
      <div class="stat-card mb-4">
          <h5 class="mb-3">ตารางสรุปการจัดสรร (แยกตามประเภทสมาชิก)</h5>
          <div class="table-responsive">
              <table class="table table-bordered table-striped align-middle">
                  <thead class="table-light text-center">
                      <tr>
                          <th rowspan="2" class="align-middle">ประเภทสมาชิก</th>
                          <th rowspan="2" class="align-middle">จำนวน (คน)</th>
                          <th colspan="2">ปันผล (หุ้น)</th>
                          <th colspan="1">เฉลี่ยคืน (ซื้อ)</th>
                      </tr>
                      <tr>
                          <th>จำนวนหุ้น</th>
                          <th>ยอดปันผล (บาท)</th>
                          <th>ยอดเฉลี่ยคืน (บาท)</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php 
                      $total_count = 0; $total_shares = 0; $total_div = 0; $total_reb = 0;
                      if (empty($report_data)): ?>
                          <tr><td colspan="5" class="text-center text-muted">ไม่พบข้อมูลการจัดสรร</td></tr>
                      <?php else:
                          foreach ($report_data as $type => $data):
                              $total_count += $data['count'];
                              $total_shares += $data['shares'];
                              $total_div += $data['dividend_amount'];
                              $total_reb += $data['rebate_amount'];
                      ?>
                      <tr>
                          <td class="fw-bold"><?= htmlspecialchars($data['name']) ?></td>
                          <td class="text-center"><?= nf($data['count'], 0) ?></td>
                          <td class="text-end"><?= nf($data['shares'], 0) ?></td>
                          <td class="text-end text-success"><?= nf($data['dividend_amount'], 2) ?></td>
                          <td class="text-end text-info"><?= nf($data['rebate_amount'], 2) ?></td>
                      </tr>
                      <?php endforeach; endif; ?>
                  </tbody>
                  <tfoot class="table-dark">
                      <tr>
                          <td class="text-end fw-bold">รวมทั้งหมด</td>
                          <td class="text-center fw-bold"><?= nf($total_count, 0) ?></td>
                          <td class="text-end fw-bold"><?= nf($total_shares, 0) ?></td>
                          <td class="text-end fw-bold"><?= nf($total_div, 2) ?></td>
                          <td class="text-end fw-bold"><?= nf($total_reb, 2) ?></td>
                      </tr>
                  </tfoot>
              </table>
          </div>
      </div>

      <div class="row g-4">
          <div class="col-md-6">
              <div class="stat-card h-100">
                  <h5 class="mb-3 text-center">สัดส่วนปันผล (หุ้น)</h5>
                  <div class="chart-container">
                      <canvas id="dividendPieChart"></canvas>
                  </div>
              </div>
          </div>
          <div class="col-md-6">
              <div class="stat-card h-100">
                  <h5 class="mb-3 text-center">สัดส่วนเฉลี่ยคืน (ซื้อ)</h5>
                  <div class="chart-container">
                      <canvas id="rebatePieChart"></canvas>
                  </div>
              </div>
          </div>
      </div>

    </main>
  </div>
</div>

<footer class="footer no-print">
  <span class="text-muted">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const chartColors = ['#0d6efd', '#6c757d', '#198754', '#dc3545', '#ffc107', '#0dcaf0'];

    // --- Chart Config ---
    Chart.defaults.font.family = "'Prompt', sans-serif";
    Chart.defaults.plugins.legend.position = 'bottom';
    Chart.defaults.plugins.tooltip.backgroundColor = '#212529';
    Chart.defaults.plugins.tooltip.titleFont.weight = 'bold';
    Chart.defaults.plugins.tooltip.callbacks.label = function(context) {
        let label = context.label || '';
        if (label) {
            label += ': ';
        }
        if (context.parsed !== null) {
            label += new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB' }).format(context.parsed);
        }
        return label;
    };
    Chart.defaults.plugins.tooltip.callbacks.footer = function(tooltipItems) {
        let sum = 0;
        tooltipItems.forEach(function(tooltipItem) {
            sum += tooltipItem.parsed;
        });
        
        let total = tooltipItems[0].chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
        if (total > 0) {
            const percentage = (sum / total * 100).toFixed(2) + '%';
            return 'คิดเป็น: ' + percentage;
        }
        return '';
    };

    // --- กราฟปันผล (Dividend Pie) ---
    const divCtx = document.getElementById('dividendPieChart')?.getContext('2d');
    const divLabels = <?= json_encode($chart_dividend_labels, JSON_UNESCAPED_UNICODE) ?>;
    const divValues = <?= json_encode($chart_dividend_values) ?>;

    if (divCtx && divValues.length > 0) {
        new Chart(divCtx, {
            type: 'doughnut',
            data: {
                labels: divLabels,
                datasets: [{
                    label: 'ยอดปันผล',
                    data: divValues,
                    backgroundColor: chartColors,
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: { display: true, text: 'สัดส่วนปันผล (หุ้น) ตามประเภทสมาชิก' }
                }
            }
        });
    } else if (divCtx) {
        divCtx.canvas.parentNode.innerHTML = '<div class="alert alert-light text-center border h-100 d-flex align-items-center justify-content-center">ไม่มีข้อมูลปันผลสำหรับแสดงกราฟ</div>';
    }

    // --- กราฟเฉลี่ยคืน (Rebate Pie) ---
    const rebCtx = document.getElementById('rebatePieChart')?.getContext('2d');
    const rebLabels = <?= json_encode($chart_rebate_labels, JSON_UNESCAPED_UNICODE) ?>;
    const rebValues = <?= json_encode($chart_rebate_values) ?>;
    
    if (rebCtx && rebValues.length > 0) {
        new Chart(rebCtx, {
            type: 'doughnut',
            data: {
                labels: rebLabels,
                datasets: [{
                    label: 'ยอดเฉลี่ยคืน',
                    data: rebValues,
                    backgroundColor: ['#0dcaf0', '#6c757d', '#198754', '#dc3545', '#ffc107', '#0d6efd'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: { display: true, text: 'สัดส่วนเฉลี่ยคืน (ยอดซื้อ) ตามประเภทสมาชิก' }
                }
            }
        });
    } else if (rebCtx) {
        rebCtx.canvas.parentNode.innerHTML = '<div class="alert alert-light text-center border h-100 d-flex align-items-center justify-content-center">ไม่มีข้อมูลเฉลี่ยคืนสำหรับแสดงกราฟ</div>';
    }
});
</script>
</body>
</html>