<?php
// admin_dashboard.php — [แก้ไข] แดชบอร์ดสำหรับผู้ดูแลระบบ (เพิ่มการ์ดกำไรคงเหลือ + ปุ่ม)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอินและสิทธิ์ =====
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
    exit();
}

// ===== เชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์ =====
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

// ===== Helpers =====
function nf($n, $d = 2) { return number_format((float)$n, $d, '.', ','); }
function d($s, $fmt = 'd/m/Y') { $t = strtotime($s); return $t ? date($fmt, $t) : '-'; }
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb");
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

// ===== โหลด system settings =====
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$station_id = 1;
try {
    $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $station_id = (int)$r['setting_value'];
        $site_name = $r['comment'] ?: $site_name;
    }
} catch (Throwable $e) {}


/* ==============================================
 *
 * ดึงข้อมูลสถิติทั้งหมด (Queries)
 *
 * ============================================== */

$stats = [
    'today_revenue' => 0,
    'today_cogs' => 0,
    'today_profit' => 0,
    'today_bills' => 0,
    'today_liters' => 0,
    'total_members' => 0,
    'total_shares' => 0,
    'potential_profit' => 0 // [เพิ่ม] กำไรคงเหลือ
];
$bar_labels = []; $bar_values = [];
$pie_labels = []; $pie_values = [];
$error_message = null;

try {
    $today_str = date('Y-m-d');
    
    // --- 1. สถิติวันนี้ (จาก v_sales_gross_profit) ---
    $stmt_today_profit = $pdo->prepare("
        SELECT 
            COALESCE(SUM(v.total_amount), 0) AS revenue,
            COALESCE(SUM(v.cogs), 0) AS cogs,
            COALESCE(SUM(v.total_amount - v.cogs), 0) AS profit,
            COUNT(v.sale_id) AS bills
        FROM v_sales_gross_profit v
        JOIN sales s ON v.sale_id = s.id
        WHERE s.station_id = :sid AND DATE(v.sale_date) = :today
    ");
    $stmt_today_profit->execute([':sid' => $station_id, ':today' => $today_str]);
    $profit_data = $stmt_today_profit->fetch(PDO::FETCH_ASSOC);
    
    $stats['today_revenue'] = (float)($profit_data['revenue'] ?? 0);
    $stats['today_cogs'] = (float)($profit_data['cogs'] ?? 0);
    $stats['today_profit'] = (float)($profit_data['profit'] ?? 0);
    $stats['today_bills'] = (int)($profit_data['bills'] ?? 0);

    // ดึงยอดลิตร (แยกต่างหาก)
    $stmt_today_liters = $pdo->prepare("
        SELECT COALESCE(SUM(si.liters), 0) AS liters
        FROM sales s
        JOIN sales_items si ON s.id = si.sale_id
        WHERE s.station_id = :sid AND DATE(s.sale_date) = :today
    ");
    $stmt_today_liters->execute([':sid' => $station_id, ':today' => $today_str]);
    $stats['today_liters'] = (float)$stmt_today_liters->fetchColumn();


    // --- 2. สมาชิกและหุ้น (รวมทุกประเภท) ---
    $stmt_members = $pdo->query("
        SELECT 
            SUM(total_users) as total_members,
            SUM(total_shares) as total_shares
        FROM (
            (SELECT COUNT(id) as total_users, COALESCE(SUM(shares), 0) as total_shares FROM members WHERE is_active = 1)
            UNION ALL
            (SELECT COUNT(id) as total_users, COALESCE(SUM(shares), 0) as total_shares FROM managers)
            UNION ALL
            (SELECT COUNT(id) as total_users, COALESCE(SUM(shares), 0) as total_shares FROM committees)
        ) as all_shareholders
    ");
    $member_data = $stmt_members->fetch(PDO::FETCH_ASSOC);
    $stats['total_members'] = (int)($member_data['total_members'] ?? 0);
    $stats['total_shares'] = (int)($member_data['total_shares'] ?? 0);

    // --- 3. [เพิ่ม] ดึงกำไรที่ยังคงเหลือในถัง (จาก v_fuel_lots_current) ---
    try {
        $stmt_profit_remain = $pdo->prepare("
            SELECT
                SUM((v.remaining_liters_calc * fp.price) - v.remaining_value) AS potential_profit
            FROM
                v_fuel_lots_current v
            JOIN
                fuel_prices fp ON v.fuel_id = fp.fuel_id AND v.station_id = fp.station_id
            WHERE
                v.station_id = :sid AND v.remaining_liters_calc > 0.01
        ");
        $stmt_profit_remain->execute([':sid' => $station_id]);
        $stats['potential_profit'] = (float)$stmt_profit_remain->fetchColumn();
    } catch (Throwable $e) {
        error_log("Could not query v_fuel_lots_current: " . $e->getMessage());
        $stats['potential_profit'] = 0.0;
    }

    // --- 4. กราฟแท่ง (ยอดขายลิตร 6 เดือนล่าสุด) ---
    $month6_start = date('Y-m-01', strtotime('-5 months'));
    $stmt_bar = $pdo->prepare("
        SELECT 
            DATE_FORMAT(s.sale_date, '%Y-%m') AS ym, 
            COALESCE(SUM(si.liters), 0) AS liters
        FROM sales s
        JOIN sales_items si ON si.sale_id = s.id
        WHERE s.station_id = :sid AND s.sale_date >= :start_date
        GROUP BY ym
        ORDER BY ym ASC
        LIMIT 6
    ");
    $stmt_bar->execute([':sid' => $station_id, ':start_date' => $month6_start]);
    foreach ($stmt_bar->fetchAll(PDO::FETCH_ASSOC) as $r) { 
        $bar_labels[] = $r['ym']; 
        $bar_values[] = (float)$r['liters']; 
    }

    // --- 5. กราฟวงกลม (สัดส่วนน้ำมัน 30 วัน) ---
    $day30_start = date('Y-m-d', strtotime('-29 days'));
    $stmt_pie = $pdo->prepare("
        SELECT 
            fp.fuel_name, 
            COALESCE(SUM(si.liters), 0) AS total_liters
        FROM sales_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN fuel_prices fp ON si.fuel_id = fp.fuel_id AND s.station_id = fp.station_id
        WHERE s.station_id = :sid
          AND s.sale_date >= :start_date
        GROUP BY fp.fuel_name
        HAVING total_liters > 0
        ORDER BY total_liters DESC
    ");
    $stmt_pie->execute([':sid' => $station_id, ':start_date' => $day30_start]);
     foreach ($stmt_pie->fetchAll(PDO::FETCH_ASSOC) as $r) { 
        $pie_labels[] = $r['fuel_name']; 
        $pie_values[] = (float)$r['total_liters']; 
    }

} catch (Throwable $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    error_log("Dashboard Error: " . $e->getMessage());
}

$role_th_map = ['admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($site_name) ?> - แดชบอร์ด</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
    <style>
        /* [แก้ไข] ปรับ Grid ให้รองรับ 4 ช่อง */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); /* ปรับ minmax */
            gap: 1.5rem;
        }
        .stat-card {
            background: #fff;
            border-radius: var(--bs-card-border-radius);
            padding: 1.5rem;
            box-shadow: var(--bs-box-shadow-sm);
            border: 1px solid var(--bs-border-color-translucent);
        }
        .stat-card h5 {
            font-size: 1rem;
            color: var(--bs-secondary-color);
            margin-bottom: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .stat-card h3 {
            font-weight: 700;
        }
        .chart-wrap {
            position: relative;
            height: 350px;
            width: 100%;
        }
    </style>
</head>

<body>
  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="#"><?= htmlspecialchars($site_name) ?></a>
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

  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu">
        <a href="admin_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="admin_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
          <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
          <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
          <a href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
          <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
          <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
          <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>

      <main class="col-lg-10 p-4 fade-in">
        <div class="main-header mb-4">
            <h2><i class="fa-solid fa-border-all"></i> ภาพรวมแดชบอร์ด</h2>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
          <div class="stat-card">
            <h5><i class="bi bi-cash-coin text-success"></i> สรุปยอดขายวันนี้</h5>
            <h3 class="text-success">฿<?= nf($stats['today_revenue'], 2) ?></h3>
            <p class="text-muted mb-0">
                <?= nf($stats['today_liters'], 2) ?> ลิตร (<?= nf($stats['today_bills'], 0) ?> บิล)
            </p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-graph-up-arrow text-primary"></i> สรุปกำไรวันนี้</h5>
            <h3 class="text-primary">฿<?= nf($stats['today_profit'], 2) ?></h3>
            <p class="text-muted mb-0">
                ต้นทุน: ฿<?= nf($stats['today_cogs'], 2) ?>
            </p>
          </div>
          <div class="stat-card d-flex flex-column">
            <h5><i class="bi bi-box-seam text-warning"></i> กำไรคงเหลือในถัง</h5>
            <h3 class="text-warning">฿<?= nf($stats['potential_profit'], 2) ?></h3>
            <a href="profit_report.php" class="btn btn-sm btn-outline-warning mt-2 stretched-link p-0" style="max-width: 120px;">
                ดูรายละเอียด <i class="bi bi-arrow-right-short"></i>
            </a>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-people-fill text-secondary"></i> สมาชิก/หุ้น</h5>
            <h3 class="text-secondary mb-0"><?= nf($stats['total_members'], 0) ?> <small>คน</small></h3>
            <p class="text-muted mb-0">
                รวม <?= nf($stats['total_shares'], 0) ?> หุ้น
            </p>
          </div>
        </div>


        <div class="row g-4 mt-4">
          <div class="col-12 col-lg-6">
            <div class="stat-card h-100">
              <h5 class="card-title mb-2">
                <i class="bi bi-bar-chart text-primary"></i>
                ยอดขายน้ำมัน (ลิตร/เดือน) 6 เดือนล่าสุด
              </h5>
              <div class="chart-wrap"><canvas id="barChart"></canvas></div>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <h5 class="card-title mb-2">
                  <i class="bi bi-pie-chart text-info"></i>
                  สัดส่วนยอดขาย (ลิตร) 30 วันล่าสุด
                </h5>
                <div class="chart-wrap"><canvas id="pieChart"></canvas></div>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <footer class="footer mt-auto py-3 bg-light">
    <div class="container text-center">
        <span class="text-muted">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?></span>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    function nf(number, decimals = 0) {
        const num = parseFloat(number) || 0;
        return num.toLocaleString('th-TH', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }

    const barLabels = <?= json_encode($bar_labels, JSON_UNESCAPED_UNICODE) ?>;
    const barValues = <?= json_encode($bar_values, JSON_UNESCAPED_UNICODE) ?>;
    const pieLabels = <?= json_encode($pie_labels, JSON_UNESCAPED_UNICODE) ?>;
    const pieValues = <?= json_encode($pie_values, JSON_UNESCAPED_UNICODE) ?>;

    Chart.defaults.font.family = "'Prompt', sans-serif";
    Chart.defaults.plugins.legend.position = 'bottom';
    Chart.defaults.plugins.tooltip.backgroundColor = '#212529';
    Chart.defaults.plugins.tooltip.titleFont.weight = '600';
    Chart.defaults.plugins.tooltip.bodyFont.weight = '500';

    // Bar
    const barCtx = document.getElementById('barChart')?.getContext('2d');
    if (barCtx && barValues.length > 0) {
        new Chart(barCtx, {
          type: 'bar',
          data: {
            labels: barLabels,
            datasets: [{
              label: 'ลิตร',
              data: barValues,
              backgroundColor: 'rgba(13, 110, 253, 0.7)', // Primary
              borderColor: 'rgba(13, 110, 253, 1)',
              borderWidth: 1,
              borderRadius: 5,
              maxBarThickness: 50
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => `${nf(context.raw)} ลิตร`
                    }
                }
            },
            scales: {
              x: { ticks: { color: '#6c757d' }, grid: { display: false } },
              y: { ticks: { color: '#6c757d', callback: (v) => nf(v) }, grid: { color: '#e9ecef' }, beginAtZero: true }
            }
          }
        });
    } else if (barCtx) {
        barCtx.canvas.parentNode.innerHTML = '<div class="alert alert-light text-center h-100 d-flex align-items-center justify-content-center">ไม่มีข้อมูลยอดขายรายเดือน</div>';
    }

    // Pie/Doughnut
    const pieCtx = document.getElementById('pieChart')?.getContext('2d');
    if (pieCtx && pieValues.length > 0) {
        new Chart(pieCtx, {
          type: 'doughnut',
          data: {
            labels: pieLabels,
            datasets: [{
              data: pieValues,
              backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#0dcaf0', '#dc3545', '#6f42c1'],
              borderColor: '#ffffff',
              borderWidth: 2
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (context) => ` ${context.label}: ${nf(context.raw, 2)} ลิตร`
                    }
                }
            }
          }
        });
    } else if (pieCtx) {
        pieCtx.canvas.parentNode.innerHTML = '<div class="alert alert-light text-center h-100 d-flex align-items-center justify-content-center">ไม่มีข้อมูลสัดส่วนน้ำมัน</div>';
    }
  </script>
</body>
</html>