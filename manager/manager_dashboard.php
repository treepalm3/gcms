<?php
// manager_dashboard.php
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบสิทธิ์: เฉพาะ manager
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
  exit();
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ========== เชื่อมฐานข้อมูล ========== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo

/* ========== helper ========== */
function table_exists(PDO $pdo, string $table): bool {
  $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = :db AND table_name = :tb
  ");
  $stmt->execute([':db'=>$db, ':tb'=>$table]);
  return (int)$stmt->fetchColumn() > 0;
}
function nf($n, $d=0){ return number_format((float)$n, $d); } // format number

/* ========== โหลดค่าพื้นฐานจาก DB ========== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
try {
  // 1) อ่านจาก app_settings.system_settings (JSON) ก่อน
  $st = $pdo->query("
    SELECT
      JSON_UNQUOTE(JSON_EXTRACT(json_value,'$.site_name'))     AS site_name,
      JSON_UNQUOTE(JSON_EXTRACT(json_value,'$.site_subtitle')) AS site_subtitle
    FROM app_settings WHERE `key`='system_settings' LIMIT 1
  ");
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $site_name     = $r['site_name']     ?: $site_name;
    $site_subtitle = $r['site_subtitle'] ?: $site_subtitle;
  } else {
    // 2) fallback: settings (ใช้ comment เป็นข้อความชื่อ)
    $st2 = $pdo->prepare("SELECT comment FROM settings WHERE setting_name='site_name' LIMIT 1");
    if ($st2->execute() && ($r2 = $st2->fetch(PDO::FETCH_ASSOC))) {
      $site_name = $r2['comment'] ?: $site_name;
    }
  }
} catch(Throwable $e){ /* ใช้ค่า default */ }

/* ========== KPI หลัก (มี view ค่อยใช้ ไม่มีก็ fallback) ========== */
$kpi = ['total_members'=>0, 'total_activities'=>0, 'total_centers'=>0];
try {
  if (table_exists($pdo, 'vw_kpi_landing')) {
    $st = $pdo->query("SELECT total_members, total_activities, total_centers FROM vw_kpi_landing");
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $kpi = $r; }
  } else {
    $kpi['total_members'] = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
  }
} catch(Throwable $e){}

/* ========== สมาชิกใหม่เดือนนี้ (จาก members.joined_date) ========== */
$members_new_month = 0;
try {
  $st = $pdo->query("
    SELECT COUNT(*) FROM members
    WHERE joined_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND joined_date <  DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
  ");
  $members_new_month = (int)$st->fetchColumn();
} catch(Throwable $e){}

/* ========== ข้อมูลผู้ใช้จาก session (แสดงมุมขวาบน) ========== */
try {
  $current_name = $_SESSION['full_name'] ?: 'ผู้บริหาร';
  $current_role = $_SESSION['role'];
  if($current_role!=='manager'){
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
  }
} catch(Throwable $e){
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ');
  exit();
}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

/* ========== สรุปยอดขาย/ลิตร/กราฟ ==========
   ถ้ามี tables: sales + sales_items → ดึงค่าน้ำมันจริง
   ถ้าไม่มี → แสดง fallback: กราฟสมาชิก/โพสต์แทน
================================================ */
$has_sales = table_exists($pdo,'sales') && table_exists($pdo,'sales_items');

/* --- ค่าในกล่องสถิติ 3 ใบด้านบน --- */
$today_revenue  = null; // บาท
$today_liters   = null; // ลิตร
$members_total  = (int)$kpi['total_members'];

if ($has_sales) {
  // รายได้วันนี้ & ลิตรวันนี้ (ใช้ sale_date ตาม schema)
  $st = $pdo->query("
    SELECT
      COALESCE(SUM(si.line_amount),0) AS revenue_today,
      COALESCE(SUM(si.liters),0)      AS liters_today
    FROM sales s
    JOIN sales_items si ON si.sale_id = s.id
    WHERE DATE(s.sale_date) = CURDATE()
  ");
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $today_revenue = (float)$r['revenue_today'];
    $today_liters  = (float)$r['liters_today'];
  }
}

/* --- Weekly summary --- */
$week_revenue = null;  // รายได้ 7 วันล่าสุด
$week_liters  = null;  // ลิตร 7 วันล่าสุด
$week_orders  = null;  // จำนวนบิล 7 วันล่าสุด
$growth_pct   = null;  // %เติบโต รายได้เทียบ 7 วันก่อนหน้า

if ($has_sales) {
  // 7 วันล่าสุด
  $st = $pdo->query("
    SELECT
      COALESCE(SUM(si.line_amount),0) AS rev7,
      COALESCE(SUM(si.liters),0)      AS lit7,
      COUNT(DISTINCT s.id)            AS cnt7
    FROM sales s
    JOIN sales_items si ON si.sale_id = s.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  ");
  $r = $st->fetch(PDO::FETCH_ASSOC);
  $week_revenue = (float)$r['rev7'];
  $week_liters  = (float)$r['lit7'];
  $week_orders  = (int)$r['cnt7'];

  // 7 วันก่อนหน้า
  $st = $pdo->query("
    SELECT COALESCE(SUM(si.line_amount),0) AS rev_prev7
    FROM sales s
    JOIN sales_items si ON si.sale_id = s.id
    WHERE s.sale_date <  DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
  ");
  $rev_prev7 = (float)$st->fetchColumn();
  $growth_pct = ($rev_prev7 > 0) ? (($week_revenue - $rev_prev7) / $rev_prev7 * 100.0) : null;
}

/* --- ข้อมูลกราฟ --- */
$bar_labels = [];  $bar_values = [];           // แท่ง: ถ้ามี sales → ลิตร 6 เดือน, ถ้าไม่มี → สมาชิกใหม่ 6 เดือน
$pie_labels = [];  $pie_values = [];           // โดนัท: ถ้ามี sales → สัดส่วนลิตรตามชนิด, ถ้าไม่มี → สัดส่วนโพสต์ตาม type

if ($has_sales) {
  // Bar: ลิตรต่อเดือน 6 เดือนล่าสุด
  $st = $pdo->query("
    SELECT DATE_FORMAT(s.sale_date, '%Y-%m') ym, COALESCE(SUM(si.liters),0) liters
    FROM sales s
    JOIN sales_items si ON si.sale_id = s.id
    WHERE s.sale_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY ym
    ORDER BY ym
  ");
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $bar_labels[] = $r['ym'];
    $bar_values[] = (float)$r['liters'];
  }

  // Pie: ยอดลิตร 30 วัน แยกชนิดเชื้อเพลิง
  $st = $pdo->query("
    SELECT si.fuel_type, COALESCE(SUM(si.liters),0) liters
    FROM sales s
    JOIN sales_items si ON si.sale_id = s.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY si.fuel_type
    ORDER BY liters DESC
  ");
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $pie_labels[] = $r['fuel_type'];
    $pie_values[] = (float)$r['liters'];
  }

} else {
  // Fallback Bar: สมาชิกใหม่รายเดือน (6 เดือน)
  $st = $pdo->query("
    SELECT DATE_FORMAT(joined_date, '%Y-%m') ym, COUNT(*) cnt
    FROM members
    WHERE joined_date IS NOT NULL
      AND joined_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY ym
    ORDER BY ym
  ");
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $bar_labels[] = $r['ym'];
    $bar_values[] = (int)$r['cnt'];
  }

  // Fallback Pie: สัดส่วนโพสต์ตามประเภท (เผยแพร่)
  $st = $pdo->query("
    SELECT type, COUNT(*) cnt
    FROM posts
    WHERE status='published'
    GROUP BY type
    ORDER BY cnt DESC
  ");
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $pie_labels[] = $r['type'];
    $pie_values[] = (int)$r['cnt'];
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($site_name) ?> - แดชบอร์ดผู้บริหาร</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
</head>

<body>
  <!-- Navbar -->
  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"
                aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
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

  <!-- Offcanvas Sidebar -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2">
        <h3><span>Manager</span></h3>
      </div>
      <nav class="sidebar-menu">
        <a href="manager_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <div class="col-md-3 col-lg-2 d-none d-md-flex flex-column sidebar py-4">
        <div class="side-brand mb-3">
          <h3><span>Manager</span></h3>
        </div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="manager_dashboard.php" class="active"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
          <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
          <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
          <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
          <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
          <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
        </nav>

        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </div>

      <!-- Content -->
      <main class="col-md-9 col-lg-10 p-4 fade-in">
        <div class="main-header">
          <h2><i class="fa-solid fa-border-all"></i> ภาพรวม</h2>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
          <div class="stat-card">
            <h5><i class="bi bi-currency-dollar"></i> รายได้วันนี้</h5>
            <h3 class="text-success">
              <?= $has_sales ? '฿'.nf($today_revenue,2) : '—' ?>
            </h3>
            <p class="<?= $has_sales ? 'text-success' : 'text-muted' ?> mb-0">
              <?= $has_sales ? 'จากตารางยอดขาย' : 'ยังไม่พบตารางยอดขาย (sales/sales_items)' ?>
            </p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-fuel-pump-fill"></i> ยอดขายน้ำมัน (วันนี้)</h5>
            <h3 class="text-primary">
              <?= $has_sales ? nf($today_liters,2).' ลิตร' : '—' ?>
            </h3>
            <p class="<?= $has_sales ? 'text-success' : 'text-muted' ?> mb-0">
              <?= $has_sales ? 'สรุปแบบ real-time' : 'เปิดใช้เมื่อมีตารางยอดขาย' ?>
            </p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-people-fill"></i> สมาชิกทั้งหมด</h5>
            <h3 class="text-info"><?= nf($members_total) ?> คน</h3>
            <p class="text-muted mb-0">สมาชิกใหม่เดือนนี้: <?= nf($members_new_month) ?> คน</p>
          </div>
        </div>

        <!-- Weekly Summary -->
        <div class="row mt-5">
          <div class="col-12">
            <div class="stat-card">
              <h5><i class="bi bi-graph-up"></i> สรุปประจำสัปดาห์</h5>
              <div class="row text-center">
                <div class="col-md-3">
                  <h6 class="text-muted">รายได้รวม (7 วัน)</h6>
                  <h4 class="text-success"><?= $has_sales ? '฿'.nf($week_revenue,2) : '—' ?></h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">น้ำมันขาย (7 วัน)</h6>
                  <h4 class="text-primary"><?= $has_sales ? nf($week_liters,2).' ลิตร' : '—' ?></h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">บิลเฉลี่ย/วัน</h6>
                  <h4 class="text-info">
                    <?= $has_sales ? nf(($week_orders/7.0),0).' บิล' : '—' ?>
                  </h4>
                </div>
                <div class="col-md-3">
                  <h6 class="text-muted">% เติบโต</h6>
                  <h4 class="text-warning">
                    <?= $has_sales && $growth_pct!==null ? ( ($growth_pct>=0?'+':'').nf($growth_pct,1).'%' ) : '—' ?>
                  </h4>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts -->
        <div class="row g-4 mt-4 row-charts">
          <div class="col-12 col-lg-6">
            <div class="stat-card h-100">
              <h5 class="mb-2">
                <i class="bi bi-bar-chart"></i>
                <?= $has_sales ? 'กราฟลิตรน้ำมัน (6 เดือน)' : 'สมาชิกใหม่รายเดือน (6 เดือน)' ?>
              </h5>
              <div class="chart-wrap"><canvas id="barChart"></canvas></div>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="stat-card h-100">
              <h5 class="mb-2">
                <i class="bi bi-pie-chart"></i>
                <?= $has_sales ? 'สัดส่วนลิตรตามชนิดเชื้อเพลิง (30 วัน)' : 'สัดส่วนโพสต์ตามประเภท' ?>
              </h5>
              <div class="chart-wrap"><canvas id="pieChart"></canvas></div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> - <?= htmlspecialchars($site_subtitle) ?></footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  const barLabels = <?= json_encode($bar_labels, JSON_UNESCAPED_UNICODE) ?>;
  const barValues = <?= json_encode($bar_values, JSON_UNESCAPED_UNICODE) ?>;
  const pieLabels = <?= json_encode($pie_labels, JSON_UNESCAPED_UNICODE) ?>;
  const pieValues = <?= json_encode($pie_values, JSON_UNESCAPED_UNICODE) ?>;

  // Bar
  new Chart(document.getElementById('barChart').getContext('2d'), {
    type: 'bar',
    data: {
      labels: barLabels,
      datasets: [{
        label: 'จำนวน',
        data: barValues,
        backgroundColor: '#20A39E',
        borderColor: '#36535E',
        borderWidth: 2,
        borderRadius: 6,
        maxBarThickness: 34
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { color: '#36535E' } } },
      scales: {
        x: { ticks: { color: '#68727A' }, grid: { color: '#E9E1D3' } },
        y: { ticks: { color: '#68727A' }, grid: { color: '#E9E1D3' }, beginAtZero: true }
      }
    }
  });

  // Pie/Doughnut
  new Chart(document.getElementById('pieChart').getContext('2d'), {
    type: 'doughnut',
    data: {
      labels: pieLabels,
      datasets: [{
        data: pieValues,
        backgroundColor: ['#CCA43B','#20A39E','#B66D0D','#513F32','#212845','#A1C181','#E56B6F','#6D597A'],
        borderColor: '#ffffff',
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '62%',
      plugins: { legend: { position: 'bottom', labels: { color: '#36535E' } } }
    }
  });
  </script>
</body>
</html>
