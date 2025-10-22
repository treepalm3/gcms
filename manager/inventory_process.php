<?php
// inventory.php — จัดการน้ำมันคงคลัง (ธีมเดียวกับหน้าอื่น ๆ)
session_start();
date_default_timezone_set('Asia/Bangkok');

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ===== เชื่อมฐานข้อมูล ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo

/* ===== โหลดค่าพื้นฐาน/ผู้ใช้ ===== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$current_name = 'ผู้ใช้งาน';
$current_role = 'guest';

try {
  $st = $pdo->query("SELECT site_name FROM settings WHERE id=1");
  if ($r = $st->fetch()) {
    $site_name = $r['site_name'] ?: $site_name;
  }

  if (isset($_SESSION['user_id'])) {
    $st = $pdo->prepare("SELECT full_name, role FROM users WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$_SESSION['user_id']]);
    if ($u = $st->fetch()) {
      $current_name = $u['full_name'] ?: $current_name;
      $current_role = $u['role'] ?: $current_role;
    }
  }
} catch (Throwable $e) {}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',  'member'=>'สมาชิกสหกรณ์',
  'guest'=>'ผู้เยี่ยมชม'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name,0,1,'UTF-8');

/* ===== ข้อมูลตัวอย่างถังน้ำมัน (จริงให้ดึงจาก DB) ===== */
$fuel_types = ['ดีเซล B7','แก๊สโซฮอล์ 95','แก๊สโซฮอล์ 91','E20','เบนซิน 95'];
$suppliers  = ['เอสพีออยล์','ไทยออยล์เทรด','พีทีทรานส์','ซัพพลายพาวเวอร์'];

$tanks = [
  ['code'=>'DSL-01','name'=>'ถังดีเซล #1','type'=>'ดีเซล B7','capacity'=>20000,'current'=>8200,'reorder'=>6000,'price'=>32.49,'last_refill'=>'2025-06-10','supplier'=>'เอสพีออยล์'],
  ['code'=>'DSL-02','name'=>'ถังดีเซล #2','type'=>'ดีเซล B7','capacity'=>20000,'current'=>16400,'reorder'=>6000,'price'=>32.49,'last_refill'=>'2025-06-16','supplier'=>'ไทยออยล์เทรด'],
  ['code'=>'G95-01','name'=>'ถัง Gasohol95','type'=>'แก๊สโซฮอล์ 95','capacity'=>12000,'current'=>3450,'reorder'=>3000,'price'=>38.79,'last_refill'=>'2025-06-12','supplier'=>'พีทีทรานส์'],
  ['code'=>'G91-01','name'=>'ถัง Gasohol91','type'=>'แก๊สโซฮอล์ 91','capacity'=>12000,'current'=>9100,'reorder'=>3000,'price'=>37.19,'last_refill'=>'2025-06-15','supplier'=>'พีทีทรานส์'],
  ['code'=>'E20-01','name'=>'ถัง E20','type'=>'E20','capacity'=>10000,'current'=>2400,'reorder'=>2500,'price'=>35.59,'last_refill'=>'2025-06-08','supplier'=>'ซัพพลายพาวเวอร์'],
  ['code'=>'B95-01','name'=>'ถัง เบนซิน95','type'=>'เบนซิน 95','capacity'=>8000,'current'=>5200,'reorder'=>2000,'price'=>44.20,'last_refill'=>'2025-06-13','supplier'=>'ไทยออยล์เทรด'],
];

/* ===== สรุปยอดคงคลัง ===== */
$total_capacity = array_sum(array_column($tanks,'capacity'));
$total_current  = array_sum(array_column($tanks,'current'));
$total_value    = array_sum(array_map(fn($t)=>$t['current']*$t['price'],$tanks));
$below_reorder  = count(array_filter($tanks, fn($t)=>$t['current'] <= $t['reorder']));
$occupancy_pct  = $total_capacity>0 ? ($total_current/$total_capacity*100) : 0;

/* ===== เตรียมข้อมูลกราฟ ===== */
$bar_labels  = array_map(fn($t)=>$t['code'], $tanks);
$bar_values  = array_map(fn($t)=>$t['current'], $tanks);
$pie_by_type = [];
foreach ($tanks as $t) {
  $pie_by_type[$t['type']] = ($pie_by_type[$t['type']] ?? 0) + $t['current'];
}
$pie_labels = array_keys($pie_by_type);
$pie_values = array_values($pie_by_type);

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>จัดการน้ำมัน | <?= htmlspecialchars($site_name) ?></title>

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

  <!-- Global theme -->
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1rem}
    .table>:not(caption)>*>*{background:transparent}
    .progress{height:10px;border-radius:999px}
    .tag{padding:.2rem .5rem;border-radius:999px;font-size:.8rem;border:1px solid var(--border)}
    .tag-type{background:#d1edff;color:#0969da}
    .tag-supplier{background:#e6ffed;color:#1a7f37}
    .chart-wrap{position:relative;height:320px}
    .stat-card small{color:#6c757d}
    .reorder-badge{background:#ffebe9;color:#cf222e}
    #offcanvasSidebar .offcanvas-body{display:flex;flex-direction:column;min-height:0;overflow:hidden}
    #offcanvasSidebar .sidebar-menu{flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

<!-- Offcanvas Sidebar (มือถือ/แท็บเล็ต) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="offcanvasLabel">สหกรณ์ปั๊มน้ำมัน</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2">
      <h3><span>Admin</span></h3>
    </div>

    <nav class="sidebar-menu">
      <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a class="active" href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้จัดการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="finance.php"><i class="bi bi-wallet2"></i> การเงิน/บัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
      <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่าระบบ</a>
    </nav>

    <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a class="active" href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้จัดการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงิน/บัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <!-- Content -->
    <main class="col-lg-10 p-4">
      <div class="main-header d-flex align-items-center justify-content-between">
        <h2>จัดการน้ำมัน/Inventory</h2>
        <div class="d-flex gap-2">
          <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalRefill">
            <i class="bi bi-truck me-1"></i> บันทึกการเติมเข้าถัง
          </button>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd">
            <i class="bi bi-plus-circle me-1"></i> เพิ่มถังน้ำมัน
          </button>
        </div>
      </div>

      <!-- Summary cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <h5><i class="bi bi-droplet-half"></i> ปริมาณคงเหลือรวม</h5>
          <h3 class="text-primary"><?= number_format($total_current) ?> L</h3>
          <small>ความจุรวม: <?= number_format($total_capacity) ?> L (เต็ม <?= number_format($occupancy_pct,1) ?>%)</small>
        </div>
        <div class="stat-card">
          <h5><i class="bi bi-cash-coin"></i> มูลค่าคงคลังโดยประมาณ</h5>
          <h3 class="text-success">฿<?= number_format($total_value,2) ?></h3>
          <small>คำนวณจากราคา/ลิตร ล่าสุด</small>
        </div>
        <div class="stat-card">
          <h5><i class="bi bi-exclamation-triangle-fill"></i> ใกล้จุดสั่งซื้อ</h5>
          <h3 class="<?= $below_reorder>0?'text-danger':'text-success' ?>"><?= $below_reorder ?> ถัง</h3>
          <small>ต่ำกว่าหรือเท่าระดับ Reorder</small>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div class="d-flex flex-wrap gap-2">
          <div class="input-group" style="max-width:320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" id="invSearch" class="form-control" placeholder="ค้นหา: รหัส/ชื่อถัง/ซัพพลายเออร์">
          </div>
          <select id="filterType" class="form-select">
            <option value="">ทุกชนิดน้ำมัน</option>
            <?php foreach($fuel_types as $ft) echo '<option value="'.htmlspecialchars($ft).'">'.htmlspecialchars($ft).'</option>'; ?>
          </select>
          <div class="form-check ms-2">
            <input class="form-check-input" type="checkbox" id="filterLow">
            <label class="form-check-label" for="filterLow"><i class="bi bi-flag-fill text-danger me-1"></i>เฉพาะที่ใกล้หมด</label>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-primary" id="btnExport"><i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV</button>
          <button class="btn btn-outline-secondary" id="btnPrint"><i class="bi bi-printer me-1"></i> พิมพ์</button>
        </div>
      </div>

      <!-- Table -->
      <div class="panel">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="invTable">
            <thead>
              <tr>
                <th>รหัส</th>
                <th>ถังน้ำมัน</th>
                <th>ชนิด</th>
                <th class="text-end">ราคา/ลิตร</th>
                <th class="text-end">คงเหลือ</th>
                <th class="d-none d-lg-table-cell text-end">ความจุ</th>
                <th class="d-none d-xl-table-cell">ซัพพลายเออร์</th>
                <th class="d-none d-xl-table-cell">เติมล่าสุด</th>
                <th class="text-end">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($tanks as $t):
                $pct = $t['capacity']>0 ? ($t['current']/$t['capacity']*100) : 0;
                $barClass = $pct<=25 ? 'bg-danger' : ($pct<=50 ? 'bg-warning' : 'bg-success');
                $isLow = $t['current'] <= $t['reorder'];
              ?>
              <tr
                data-code="<?= htmlspecialchars($t['code']) ?>"
                data-name="<?= htmlspecialchars($t['name']) ?>"
                data-type="<?= htmlspecialchars($t['type']) ?>"
                data-price="<?= htmlspecialchars($t['price']) ?>"
                data-current="<?= htmlspecialchars($t['current']) ?>"
                data-capacity="<?= htmlspecialchars($t['capacity']) ?>"
                data-reorder="<?= htmlspecialchars($t['reorder']) ?>"
                data-supplier="<?= htmlspecialchars($t['supplier']) ?>"
                data-last-refill="<?= htmlspecialchars($t['last_refill']) ?>"
              >
                <td><b><?= htmlspecialchars($t['code']) ?></b></td>
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars($t['name']) ?></div>
                  <div class="small text-muted d-lg-none">
                    <?= number_format($t['current']) ?> / <?= number_format($t['capacity']) ?> L (<?= number_format($pct,0) ?>%)
                  </div>
                </td>
                <td>
                  <span class="tag tag-type"><?= htmlspecialchars($t['type']) ?></span>
                  <?php if($isLow): ?>
                    <span class="badge reorder-badge ms-1">ใกล้หมด</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">฿<?= number_format($t['price'],2) ?></td>
                <td class="text-end" style="min-width:180px;">
                  <div><?= number_format($t['current']) ?> L <span class="text-muted">/ <?= number_format($t['capacity']) ?> L</span></div>
                  <div class="progress mt-1">
                    <div class="progress-bar <?= $barClass ?>" role="progressbar" style="width: <?= number_format($pct,0) ?>%;"></div>
                  </div>
                </td>
                <td class="d-none d-lg-table-cell text-end"><?= number_format($t['capacity']) ?> L</td>
                <td class="d-none d-xl-table-cell">
                  <span class="tag tag-supplier"><?= htmlspecialchars($t['supplier']) ?></span>
                </td>
                <td class="d-none d-xl-table-cell"><?= htmlspecialchars($t['last_refill']) ?></td>
                <td class="text-end">
                  <div class="btn-group">
                    <button class="btn btn-sm btn-outline-secondary btnRefill" title="เติมเข้าถัง"><i class="bi bi-truck"></i></button>
                    <button class="btn btn-sm btn-outline-primary btnEdit"><i class="bi bi-pencil-square"></i></button>
                    <button class="btn btn-sm btn-outline-danger btnDel"><i class="bi bi-trash"></i></button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Charts -->
      <div class="row mt-4 g-4">
        <div class="col-md-6">
          <div class="panel">
            <h6 class="mb-2"><i class="bi bi-bar-chart"></i> ปริมาณคงเหลือรายถัง (ลิตร)</h6>
            <div class="chart-wrap"><canvas id="barChart"></canvas></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="panel">
            <h6 class="mb-2"><i class="bi bi-pie-chart"></i> สัดส่วนคงเหลือแยกตามชนิดน้ำมัน</h6>
            <div class="chart-wrap"><canvas id="pieChart"></canvas></div>
          </div>
        </div>
      </div>

      <!-- Tips -->
      <div class="alert alert-info mt-3 mb-0">
        <i class="bi bi-lightbulb me-1"></i>
        เคล็ดลับ: ใช้เครื่องหมาย <span class="badge reorder-badge">ใกล้หมด</span> เพื่อตรวจถังที่ต้องสั่งซื้อเติมเร่งด่วน
      </div>
    </main>
  </div>
</div>

<!-- Footer -->
<footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการน้ำมัน</footer>

<!-- ===== Modals ===== -->
<!-- Add Tank -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="formAdd" onsubmit="event.preventDefault();">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>เพิ่มถังน้ำมัน</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-sm-4">
            <label class="form-label">รหัสถัง</label>
            <input type="text" class="form-control" id="addCode" placeholder="เช่น DSL-03" required>
          </div>
          <div class="col-sm-8">
            <label class="form-label">ชื่อถัง</label>
            <input type="text" class="form-control" id="addName" placeholder="เช่น ถังดีเซล #3" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">ชนิดน้ำมัน</label>
            <select id="addType" class="form-select" required>
              <?php foreach($fuel_types as $ft) echo '<option value="'.htmlspecialchars($ft).'">'.htmlspecialchars($ft).'</option>'; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">ความจุ (ลิตร)</label>
            <input type="number" class="form-control" id="addCapacity" min="0" step="100" placeholder="เช่น 20000" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Reorder (ลิตร)</label>
            <input type="number" class="form-control" id="addReorder" min="0" step="100" placeholder="เช่น 6000" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label">ราคา/ลิตร (บาท)</label>
            <input type="number" class="form-control" id="addPrice" min="0" step="0.01" value="0" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label">คงเหลือเริ่มต้น (ลิตร)</label>
            <input type="number" class="form-control" id="addCurrent" min="0" step="100" value="0" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label">ซัพพลายเออร์</label>
            <input type="text" class="form-control" id="addSupplier" list="supplierList" placeholder="เลือก/พิมพ์">
            <datalist id="supplierList">
              <?php foreach($suppliers as $s) echo '<option value="'.htmlspecialchars($s).'">'; ?>
            </datalist>
          </div>
          <div class="col-sm-6">
            <label class="form-label">เติมล่าสุด</label>
            <input type="date" class="form-control" id="addLastRefill" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-sm-6 d-flex align-items-end">
            <div class="form-text">* ข้อมูลนี้เป็นเดโม ฝั่งจริงให้บันทึกลงฐานข้อมูล</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" type="submit"><i class="bi bi-save2 me-1"></i> บันทึก</button>
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Tank -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="formEdit" onsubmit="event.preventDefault();">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขถังน้ำมัน</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-sm-4">
            <label class="form-label">รหัสถัง</label>
            <input type="text" class="form-control" id="editCode" readonly>
          </div>
          <div class="col-sm-8">
            <label class="form-label">ชื่อถัง</label>
            <input type="text" class="form-control" id="editName" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">ชนิดน้ำมัน</label>
            <select id="editType" class="form-select" required>
              <?php foreach($fuel_types as $ft) echo '<option value="'.htmlspecialchars($ft).'">'.htmlspecialchars($ft).'</option>'; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">ความจุ (ลิตร)</label>
            <input type="number" class="form-control" id="editCapacity" min="0" step="100" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Reorder (ลิตร)</label>
            <input type="number" class="form-control" id="editReorder" min="0" step="100" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label">ราคา/ลิตร (บาท)</label>
            <input type="number" class="form-control" id="editPrice" min="0" step="0.01" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label">คงเหลือ (ลิตร)</label>
            <input type="number" class="form-control" id="editCurrent" min="0" step="100" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label">ซัพพลายเออร์</label>
            <input type="text" class="form-control" id="editSupplier" list="supplierList">
          </div>
          <div class="col-sm-6">
            <label class="form-label">เติมล่าสุด</label>
            <input type="date" class="form-control" id="editLastRefill">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" type="submit"><i class="bi bi-save2 me-1"></i> บันทึก</button>
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button>
      </div>
    </form>
  </div>
</div>

<!-- Refill -->
<div class="modal fade" id="modalRefill" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="formRefill" onsubmit="event.preventDefault();">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-truck me-2"></i>บันทึกการเติมเข้าถัง</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">เลือกถัง</label>
          <select id="refillTank" class="form-select" required>
            <?php foreach($tanks as $t) echo '<option value="'.htmlspecialchars($t['code']).'">'.htmlspecialchars($t['code'].' — '.$t['name']).'</option>'; ?>
          </select>
        </div>
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label">ปริมาณเติม (ลิตร)</label>
            <input type="number" class="form-control" id="refillQty" min="0" step="100" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">ราคา/ลิตร (บาท)</label>
            <input type="number" class="form-control" id="refillPrice" min="0" step="0.01" placeholder="ไม่ระบุ = ไม่เปลี่ยนราคา">
          </div>
          <div class="col-sm-6">
            <label class="form-label">วันที่เติม</label>
            <input type="date" class="form-control" id="refillDate" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">ซัพพลายเออร์</label>
            <input type="text" class="form-control" id="refillSupplier" list="supplierList">
          </div>
        </div>
        <div class="form-text mt-2">* เดโม: บันทึกเฉพาะบนหน้า ไม่เขียนฐานข้อมูล</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success" type="submit"><i class="bi bi-check2-circle me-1"></i> บันทึก</button>
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete confirm -->
<div class="modal fade" id="modalDel" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>ลบถังน้ำมัน</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        ต้องการลบถัง <b id="delName"></b> (<span id="delCode"></span>) ใช่หรือไม่?
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger" id="btnDelConfirm"><i class="bi bi-check2-circle me-1"></i> ลบ</button>
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="liveToast" class="toast align-items-center text-bg-dark border-0" role="status" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">บันทึกเรียบร้อย</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="ปิด"></button>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $  = (s,p=document)=>p.querySelector(s);
const $$ = (s,p=document)=>[...p.querySelectorAll(s)];

const toast = (msg, ok=true)=>{
  const t = $('#liveToast');
  t.className = `toast align-items-center border-0 ${ok?'text-bg-success':'text-bg-danger'}`;
  t.querySelector('.toast-body').textContent = msg || 'บันทึกเรียบร้อย';
  bootstrap.Toast.getOrCreateInstance(t, { delay: 2000 }).show();
};

/* ===== Filter/Search ===== */
const invSearch  = $('#invSearch');
const filterType = $('#filterType');
const filterLow  = $('#filterLow');

function normalize(s){ return (s||'').toString().toLowerCase().trim(); }

function applyFilter(){
  const k   = normalize(invSearch.value);
  const typ = filterType.value;
  const low = filterLow.checked;

  $$('#invTable tbody tr').forEach(tr=>{
    const txt = normalize(`${tr.dataset.code} ${tr.dataset.name} ${tr.dataset.type} ${tr.dataset.supplier}`);
    const okK = !k || txt.includes(k);
    const okT = !typ || tr.dataset.type === typ;
    const okL = !low || (parseFloat(tr.dataset.current||'0') <= parseFloat(tr.dataset.reorder||'0'));

    tr.style.display = (okK && okT && okL) ? '' : 'none';
  });

  // อัปเดตกราฟตามแถวที่มองเห็น
  refreshChartsFromTable();
}

invSearch.addEventListener('input', applyFilter);
filterType.addEventListener('change', applyFilter);
filterLow.addEventListener('change', applyFilter);

/* ===== Print & Export ===== */
$('#btnPrint').addEventListener('click', ()=>window.print());
$('#btnExport').addEventListener('click', ()=>{
  const rows = [['Code','Name','Type','PricePerLiter','Current(L)','Capacity(L)','Reorder(L)','Supplier','LastRefill']];
  $$('#invTable tbody tr').forEach(tr=>{
    if(tr.style.display==='none') return;
    rows.push([
      tr.dataset.code, tr.dataset.name, tr.dataset.type,
      tr.dataset.price, tr.dataset.current, tr.dataset.capacity,
      tr.dataset.reorder, tr.dataset.supplier, tr.dataset.lastRefill
    ]);
  });
  const csv = rows.map(r=>r.map(v=>`"${(v??'').toString().replaceAll('"','""')}"`).join(',')).join('\n');
  const blob = new Blob([csv],{type:'text/csv;charset=utf-8;'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'inventory_tanks.csv';
  a.click(); URL.revokeObjectURL(a.href);
});

/* ===== CRUD (เดโมฝั่งหน้า) ===== */
let currentRow = null, rowToDelete = null;

function openEdit(tr){
  currentRow = tr;
  $('#editCode').value      = tr.dataset.code;
  $('#editName').value      = tr.dataset.name;
  $('#editType').value      = tr.dataset.type;
  $('#editCapacity').value  = tr.dataset.capacity;
  $('#editReorder').value   = tr.dataset.reorder;
  $('#editPrice').value     = tr.dataset.price;
  $('#editCurrent').value   = tr.dataset.current;
  $('#editSupplier').value  = tr.dataset.supplier;
  $('#editLastRefill').value= tr.dataset.lastRefill || '';
  new bootstrap.Modal('#modalEdit').show();
}

function updateRowVisual(tr){
  // คำนวณ % และปรับ progress
  const cap = parseFloat(tr.dataset.capacity||'0');
  const cur = parseFloat(tr.dataset.current||'0');
  const pct = cap>0 ? Math.round(cur/cap*100) : 0;
  const barClass = pct<=25 ? 'bg-danger' : (pct<=50 ? 'bg-warning' : 'bg-success');
  const isLow = cur <= parseFloat(tr.dataset.reorder||'0');

  // ชื่อถัง (คงเดิม)
  // ชนิด/ป้ายใกล้หมด
  const tdType = tr.querySelector('td:nth-child(3)');
  tdType.innerHTML = `<span class="tag tag-type">${tr.dataset.type}</span>` + (isLow ? ' <span class="badge reorder-badge ms-1">ใกล้หมด</span>' : '');

  // ราคา/ลิตร
  tr.querySelector('td:nth-child(4)').innerHTML = `฿${parseFloat(tr.dataset.price||0).toLocaleString('th-TH',{minimumFractionDigits:2})}`;

  // คงเหลือ+progress
  tr.querySelector('td:nth-child(5)').innerHTML = `
    <div>${cur.toLocaleString('th-TH')} L <span class="text-muted">/ ${cap.toLocaleString('th-TH')} L</span></div>
    <div class="progress mt-1"><div class="progress-bar ${barClass}" role="progressbar" style="width:${pct}%"></div></div>
  `;

  // ความจุ
  const tdCap = tr.querySelector('td:nth-child(6)');
  if (tdCap) tdCap.textContent = `${cap.toLocaleString('th-TH')} L`;

  // ซัพพลายเออร์
  const tdSup = tr.querySelector('td:nth-child(7)');
  if (tdSup) tdSup.innerHTML = `<span class="tag tag-supplier">${tr.dataset.supplier||''}</span>`;

  // เติมล่าสุด
  const tdLast = tr.querySelector('td:nth-child(8)');
  if (tdLast) tdLast.textContent = tr.dataset.lastRefill || '';
}

$('#formAdd').addEventListener('submit', ()=>{
  const code = $('#addCode').value.trim();
  const name = $('#addName').value.trim();
  const type = $('#addType').value;
  const capacity = parseFloat($('#addCapacity').value||'0');
  const reorder  = parseFloat($('#addReorder').value||'0');
  const price    = parseFloat($('#addPrice').value||'0');
  let current    = parseFloat($('#addCurrent').value||'0');
  const supplier = $('#addSupplier').value.trim();
  const lastRefill = $('#addLastRefill').value;

  if(!code || !name || !type || capacity<=0){ toast('กรอกข้อมูลให้ครบถ้วน', false); return; }
  if(current > capacity) current = capacity;

  const tr = document.createElement('tr');
  tr.dataset.code = code; tr.dataset.name = name; tr.dataset.type = type;
  tr.dataset.price = price; tr.dataset.current = current; tr.dataset.capacity = capacity;
  tr.dataset.reorder = reorder; tr.dataset.supplier = supplier; tr.dataset.lastRefill = lastRefill;

  tr.innerHTML = `
    <td><b>${code}</b></td>
    <td><div class="fw-semibold">${name}</div><div class="small text-muted d-lg-none"></div></td>
    <td></td>
    <td class="text-end"></td>
    <td class="text-end"></td>
    <td class="d-none d-lg-table-cell text-end"></td>
    <td class="d-none d-xl-table-cell"></td>
    <td class="d-none d-xl-table-cell"></td>
    <td class="text-end">
      <div class="btn-group">
        <button class="btn btn-sm btn-outline-secondary btnRefill" title="เติมเข้าถัง"><i class="bi bi-truck"></i></button>
        <button class="btn btn-sm btn-outline-primary btnEdit"><i class="bi bi-pencil-square"></i></button>
        <button class="btn btn-sm btn-outline-danger btnDel"><i class="bi bi-trash"></i></button>
      </div>
    </td>
  `;
  $('#invTable tbody').prepend(tr);
  updateRowVisual(tr);
  attachRowHandlers(tr);

  bootstrap.Modal.getInstance($('#modalAdd')).hide();
  $('#formAdd').reset();
  toast('เพิ่มถังใหม่แล้ว');
  applyFilter(); // refresh charts
  recalcSummary();
});

$('#formEdit').addEventListener('submit', ()=>{
  if(!currentRow) return;
  currentRow.dataset.name       = $('#editName').value.trim();
  currentRow.dataset.type       = $('#editType').value;
  currentRow.dataset.capacity   = $('#editCapacity').value;
  currentRow.dataset.reorder    = $('#editReorder').value;
  currentRow.dataset.price      = $('#editPrice').value;
  currentRow.dataset.current    = $('#editCurrent').value;
  currentRow.dataset.supplier   = $('#editSupplier').value.trim();
  currentRow.dataset.lastRefill = $('#editLastRefill').value;

  // ชื่อ
  currentRow.querySelector('td:nth-child(2) .fw-semibold').textContent = currentRow.dataset.name;

  updateRowVisual(currentRow);
  bootstrap.Modal.getInstance($('#modalEdit')).hide();
  toast('บันทึกการแก้ไขแล้ว');
  applyFilter();
  recalcSummary();
});

function openDelete(tr){
  rowToDelete = tr;
  $('#delCode').textContent = tr.dataset.code;
  $('#delName').textContent = tr.dataset.name;
  new bootstrap.Modal('#modalDel').show();
}
$('#btnDelConfirm').addEventListener('click', ()=>{
  if(rowToDelete){ rowToDelete.remove(); rowToDelete=null; }
  bootstrap.Modal.getInstance($('#modalDel')).hide();
  toast('ลบถังแล้ว');
  applyFilter();
  recalcSummary();
});

function openRefill(tr){
  // ตั้งค่า select ไปยังถังที่เลือก
  $('#refillTank').value = tr.dataset.code;
  $('#refillQty').value = '';
  $('#refillPrice').value = '';
  $('#refillSupplier').value = tr.dataset.supplier || '';
  $('#refillDate').value = new Date().toISOString().slice(0,10);
  new bootstrap.Modal('#modalRefill').show();
}
$('#formRefill').addEventListener('submit', ()=>{
  const code = $('#refillTank').value;
  const qty  = parseFloat($('#refillQty').value||'0');
  const price= parseFloat($('#refillPrice').value||'0');
  const date = $('#refillDate').value;
  const sup  = $('#refillSupplier').value.trim();

  if(!code || qty<=0){ toast('กรอกปริมาณเติมให้ถูกต้อง', false); return; }

  const tr = $(`#invTable tbody tr[data-code="${CSS.escape(code)}"]`);
  if(!tr){ toast('ไม่พบถังที่เลือก', false); return; }

  const cap = parseFloat(tr.dataset.capacity||'0');
  let cur   = parseFloat(tr.dataset.current||'0');
  cur += qty;
  if (cur > cap) { cur = cap; toast('ปริมาณเกินความจุ — ปรับเป็นค่าสูงสุดให้แล้ว', false); }
  tr.dataset.current = cur;
  if (!isNaN(price) && price>0) tr.dataset.price = price;
  tr.dataset.lastRefill = date;
  if (sup) tr.dataset.supplier = sup;

  updateRowVisual(tr);
  bootstrap.Modal.getInstance($('#modalRefill')).hide();
  toast('บันทึกการเติมแล้ว');
  applyFilter();
  recalcSummary();
});

function attachRowHandlers(tr){
  tr.querySelector('.btnEdit')?.addEventListener('click', ()=> openEdit(tr));
  tr.querySelector('.btnDel')?.addEventListener('click', ()=> openDelete(tr));
  tr.querySelector('.btnRefill')?.addEventListener('click', ()=> openRefill(tr));
}
$$('#invTable tbody tr').forEach(attachRowHandlers);

/* ===== Summary Recalc ===== */
function recalcSummary(){
  let totalCap=0, totalCur=0, totalVal=0, low=0;
  $$('#invTable tbody tr').forEach(tr=>{
    if(tr.style.display==='none') return; // ใช้เฉพาะรายการที่แสดง? → เปลี่ยนเป็นรวมทั้งหมดหากต้องการ
    const cap = parseFloat(tr.dataset.capacity||'0');
    const cur = parseFloat(tr.dataset.current||'0');
    const price = parseFloat(tr.dataset.price||'0');
    const reorder = parseFloat(tr.dataset.reorder||'0');
    totalCap += cap; totalCur += cur; totalVal += cur*price; if(cur<=reorder) low++;
  });
  const pct = totalCap>0 ? (totalCur/totalCap*100) : 0;

  const cards = document.querySelectorAll('.stats-grid .stat-card');
  if(cards[0]){
    cards[0].querySelector('h3').textContent = `${totalCur.toLocaleString('th-TH')} L`;
    cards[0].querySelector('small').textContent = `ความจุรวม: ${totalCap.toLocaleString('th-TH')} L (เต็ม ${pct.toFixed(1)}%)`;
  }
  if(cards[1]){
    cards[1].querySelector('h3').textContent = `฿${totalVal.toLocaleString('th-TH',{minimumFractionDigits:2})}`;
  }
  if(cards[2]){
    cards[2].querySelector('h3').textContent = `${low} ถัง`;
    cards[2].querySelector('h3').className = low>0 ? 'text-danger' : 'text-success';
  }
}

/* ===== Charts ===== */
let barChart, pieChart;

function buildCharts(initial=true){
  const bLabels = <?= json_encode($bar_labels, JSON_UNESCAPED_UNICODE) ?>;
  const bValues = <?= json_encode($bar_values) ?>;
  const pLabels = <?= json_encode($pie_labels, JSON_UNESCAPED_UNICODE) ?>;
  const pValues = <?= json_encode($pie_values) ?>;

  const bctx = document.getElementById('barChart').getContext('2d');
  const pctx = document.getElementById('pieChart').getContext('2d');

  barChart = new Chart(bctx, {
    type: 'bar',
    data: { labels: bLabels, datasets: [{ label:'ลิตรคงเหลือ', data: bValues, backgroundColor:'#20A39E', borderColor:'#36535E', borderWidth:2, borderRadius:6, maxBarThickness:34 }] },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ labels:{ color:'#36535E' } } },
      scales:{ x:{ ticks:{ color:'#68727A' }, grid:{ color:'#E9E1D3'} },
               y:{ ticks:{ color:'#68727A' }, grid:{ color:'#E9E1D3'}, beginAtZero:true } }
    }
  });

  pieChart = new Chart(pctx, {
    type: 'doughnut',
    data: { labels: pLabels, datasets: [{ data: pValues, backgroundColor:['#CCA43B','#20A39E','#B66D0D','#513F32','#212845','#A1C181','#6D597A','#E56B6F'], borderColor:'#ffffff', borderWidth:2 }] },
    options: { responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{ legend:{ position:'bottom', labels:{ color:'#36535E' } } } }
  });
}
buildCharts();

function refreshChartsFromTable(){
  // อ่านค่าจากแถวที่มองเห็น
  const rows = $$('#invTable tbody tr').filter(tr=>tr.style.display!=='none');
  const labels = rows.map(tr=>tr.dataset.code);
  const values = rows.map(tr=>parseFloat(tr.dataset.current||'0'));

  // pie แยกชนิด
  const byType = new Map();
  rows.forEach(tr=>{
    const t = tr.dataset.type;
    const v = parseFloat(tr.dataset.current||'0');
    byType.set(t, (byType.get(t)||0)+v);
  });
  const pLabels = [...byType.keys()];
  const pValues = [...byType.values()];

  // อัปเดต
  if(barChart){
    barChart.data.labels = labels;
    barChart.data.datasets[0].data = values;
    barChart.update();
  }
  if(pieChart){
    pieChart.data.labels = pLabels;
    pieChart.data.datasets[0].data = pValues;
    pieChart.update();
  }
}

/* ===== Init ===== */
applyFilter(); // also draws charts with filtered view (initial: all)
</script>
</body>
</html>
