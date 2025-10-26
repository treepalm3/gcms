<?php
// admin/committee.php — จัดการกรรมการ (รองรับแผนก แยกจากตำแหน่ง)
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ---------- Auth ---------- */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน'));
  exit();
}

/* ---------- DB ---------- */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องประกาศ $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Role ---------- */
try {
  $current_name = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'admin') {
    header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
    exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=' . urlencode('เกิดข้อผิดพลาดของระบบ'));
  exit();
}

/* ---------- CSRF (สำหรับฟอร์มภายในหน้านี้) ---------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ================= helpers ================= */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:c');
    $st->execute([':db'=>$db, ':tb'=>$table, ':c'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function dept_label(?string $dept, array $map): string {
  $d = trim((string)($dept ?? ''));
  return $map[$d] ?? ($d !== '' ? $d : 'ไม่ระบุแผนก');
}

/* ================= basic data ================= */
$site_name  = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$station_id = 1;
$committees = [];
$departments = [];
$alerts = [];
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
$debug_sql = null;
$debug_exception = null;
$debug_pdo_error = null;

/* ----- โหลด site_name/สถานี จาก settings (ยืดหยุ่นกับสคีมา) ----- */
try {
  if (table_exists($pdo,'settings')) {
    // ใช้บรรทัดเดียวกับหน้า manager.php: อ่าน station_id + comment เป็นชื่อหน่วยงาน
    $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $station_id = (int)($row['setting_value'] ?? 1);
      if (!empty($row['comment'])) { $site_name = $row['comment']; }
    }
  }
} catch (Throwable $e) { /* ใช้ค่า default ต่อไป */ }

/* ----- ตรวจสอบสคีมา committees มี department หรือไม่ ----- */
$has_comm_department = column_exists($pdo,'committees','department');

/* ----- ดึงข้อมูลกรรมการ ----- */
try {
  if (!table_exists($pdo,'committees') || !table_exists($pdo,'users')) {
    $alerts[] = ['type'=>'info','icon'=>'fa-info-circle','message'=>'ยังไม่มีตาราง committees หรือ users ในฐานข้อมูล'];
    $committees = [];
  } else {
    $selectDept = $has_comm_department ? "c.department AS department," : "NULL AS department,";
    $sql = "
      SELECT
          c.committee_code AS id,
          c.user_id,
          {$selectDept}
          u.full_name AS name,
          u.phone,
          c.position,
          c.joined_date AS joined_date,
          c.shares,
          c.house_number,
          c.address
      FROM committees c
      JOIN users u ON c.user_id = u.id
      WHERE u.role = 'committee'
      ORDER BY c.joined_date DESC
    ";
    $debug_sql = $sql;

    $stmt = $pdo->query($sql);
    $committees = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  $debug_exception = $e;
  $debug_pdo_error = $pdo->errorInfo();
  // Fallback 1 แถวเพื่อให้หน้าไม่พัง
  $committees = [[
      'id' => 'C-ERR',
      'user_id' => 0,
      'department' => 'error',
      'name' => 'เกิดข้อผิดพลาดในการโหลดข้อมูล',
      'phone' => '',
      'position' => 'Error',
      'joined_date' => date('Y-m-d'),
      'shares' => 0,
      'house_number' => '',
      'address' => $e->getMessage(),
  ]];
  error_log("Committee fetch error: " . $e->getMessage());
}

/* ----- กำหนดรายการแผนกพื้นฐาน + เติมจากข้อมูลจริง ----- */
$departments = [
  'operations'       => 'งานปฏิบัติการ',
  'finance'          => 'การเงินและบัญชี',
  'maintenance'      => 'บำรุงรักษา',
  'customer_service' => 'บริการลูกค้า',
  'hr'               => 'ทรัพยากรบุคคล',
  'marketing'        => 'การตลาด',
  'safety'           => 'ความปลอดภัย'
];
foreach ($committees as $c) {
  $key = (string)($c['department'] ?? '');
  if ($key !== '' && !array_key_exists($key, $departments)) {
    $departments[$key] = $key; // แสดง key ตรงๆ หากไม่อยู่ในรายการพื้นฐาน
  }
}

/* ----- Flash message จาก redirect (?ok / ?err) ----- */
if (isset($_GET['ok']) && $_GET['ok']!=='') {
  $alerts[] = ['type'=>'success','icon'=>'fa-check-circle','message'=>$_GET['ok']];
}
if (isset($_GET['err']) && $_GET['err']!=='') {
  $alerts[] = ['type'=>'danger','icon'=>'fa-triangle-exclamation','message'=>$_GET['err']];
}

/* ----- Mapping ชื่อบทบาท ----- */
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',  'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>กรรมการ | <?= htmlspecialchars($site_name) ?></title>

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

  <!-- Global theme -->
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .badge.bg-info-subtle{background:rgba(13,202,240,.1)!important; color:#0dcaf0!important;}
    .badge.bg-secondary-subtle{background:rgba(108,117,125,.1)!important; color:#6c757d!important;}
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button"
              data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"
              aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
      <a class="navbar-brand" href="admin_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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
    <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
    <nav class="sidebar-menu">
      <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
      <a class="active" href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="finance.php"><i class="bi bi-wallet2"></i> การเงินและบัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่าระบบ</a>
    </nav>
    <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a class="active" href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket me-1"></i>ออกจากระบบ</a>
    </aside>

    <!-- Content -->
    <main class="col-lg-10 p-4">
      <div class="main-header d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-users-cog"></i> จัดการกรรมการ</h2>
      </div>

      <!-- Alerts -->
      <div class="alerts-section mb-4" <?= empty($alerts) ? 'style="display:none"' : '' ?>>
        <?php foreach ($alerts as $alert): ?>
          <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> border-0">
            <i class="fa-solid <?= htmlspecialchars($alert['icon']) ?> me-2"></i><?= htmlspecialchars($alert['message']) ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($DEBUG && ($debug_exception || empty($committees))): ?>
      <div class="alert alert-danger mt-3" role="alert" style="white-space: pre-wrap;">
        <b>เกิดข้อผิดพลาดในการดึงข้อมูลกรรมการ</b>
        <?php if ($debug_exception): ?>
          <?php $ei = $debug_pdo_error ?? []; $sqlstate = $ei[0] ?? ''; $driver = $ei[2] ?? ''; ?>
          <hr class="my-2"/>
          <div><b>Message:</b> <?= htmlspecialchars($debug_exception->getMessage()) ?></div>
          <?php if (!empty($sqlstate)): ?><div><b>SQLSTATE:</b> <?= htmlspecialchars($sqlstate) ?></div><?php endif; ?>
          <?php if (!empty($driver)): ?><div><b>Driver error:</b> <?= htmlspecialchars($driver) ?></div><?php endif; ?>
          <div><b>File:</b> <?= htmlspecialchars($debug_exception->getFile()) ?> : <?= (int)$debug_exception->getLine() ?></div>
          <?php if ($debug_sql): ?>
            <hr class="my-2"/>
            <div><b>SQL:</b></div>
            <code style="display:block; white-space:pre-wrap;"><?= htmlspecialchars($debug_sql) ?></code>
          <?php endif; ?>
        <?php else: ?>
          <div>ไม่พบข้อมูลกรรมการ (ไม่มีแถวในผลลัพธ์)</div>
        <?php endif; ?>
        <div class="mt-2 text-muted small">
          * กล่องนี้จะแสดงเฉพาะเมื่อเปิดโหมดดีบักด้วยพารามิเตอร์ <code>?debug=1</code>
        </div>
      </div>
      <?php endif; ?>

      <!-- Summary cards -->
      <div class="stats-grid">
        <?php
          $total = count($committees);
          $joinedThisYear = count(array_filter($committees, fn($c)=> substr((string)($c['joined_date'] ?? ''),0,4)===date('Y')));
          $totalShares = array_sum(array_map(fn($c)=> (int)($c['shares'] ?? 0), $committees));
        ?>
        <div class="stat-card">
          <h5><i class="bi bi-people-fill"></i> กรรมการทั้งหมด</h5>
          <h3 class="text-primary"><?= number_format($total) ?> คน</h3>
          <p class="mb-0 text-muted">รวมทุกตำแหน่ง</p>
        </div>
        <div class="stat-card">
          <h5><i class="bi bi-calendar-plus"></i> เข้าร่วมปีนี้</h5>
          <h3 class="text-success"><?= number_format($joinedThisYear) ?> คน</h3>
          <p class="mb-0 text-muted">อัปเดตอัตโนมัติจากวันที่เริ่มงาน</p>
        </div>
        <div class="stat-card">
          <h5><i class="fa-solid fa-chart-pie"></i> หุ้นรวม</h5>
          <h3 class="text-info"><?= number_format($totalShares) ?> หุ้น</h3>
          <p class="mb-0 text-muted">จากกรรมการทั้งหมด</p>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div class="d-flex flex-wrap gap-2">
          <div class="input-group" style="max-width:280px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" id="committeeSearch" class="form-control" placeholder="ค้นหา: ชื่อ/รหัส/ตำแหน่ง/แผนก/ที่อยู่">
          </div>
          <select id="deptFilter" class="form-select" style="width:auto;">
            <option value="">ทุกแผนก</option>
            <?php foreach($departments as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-primary" id="btnExport"><i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV</button>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd"><i class="bi bi-person-plus-fill me-1"></i> เพิ่มกรรมการ</button>
        </div>
      </div>

      <!-- Table -->
      <div class="panel">
        <div class="panel-head mb-2">
          <h5 class="mb-0"><i class="fa-solid fa-list me-2"></i>รายชื่อกรรมการ</h5>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="committeeTable">
            <thead>
              <tr>
                <th>รหัส</th>
                <th>กรรมการ</th>
                <th class="d-none d-md-table-cell">ตำแหน่ง</th>
                <th class="d-none d-md-table-cell">แผนก</th>
                <th class="d-none d-lg-table-cell text-center">หุ้น</th>
                <th class="d-none d-lg-table-cell">บ้านเลขที่</th>
                <th class="d-none d-xl-table-cell">ที่อยู่</th>
                <th class="text-end d-none d-lg-table-cell">เริ่มงาน</th>
                <th class="text-end">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($committees as $c):
                $initial = mb_substr($c['name'] ?? '', 0, 1, 'UTF-8') ?: 'ก';
                $deptName = dept_label($c['department'] ?? '', $departments);
              ?>
              <tr
                data-user-id="<?= htmlspecialchars((string)($c['user_id'] ?? 0)) ?>"
                data-id="<?= htmlspecialchars((string)($c['id'] ?? '')) ?>"
                data-name="<?= htmlspecialchars((string)($c['name'] ?? '')) ?>"
                data-phone="<?= htmlspecialchars((string)($c['phone'] ?? '')) ?>"
                data-position="<?= htmlspecialchars((string)($c['position'] ?? '')) ?>"
                data-department="<?= htmlspecialchars((string)($c['department'] ?? '')) ?>"
                data-joined="<?= htmlspecialchars((string)($c['joined_date'] ?? '')) ?>"
                data-shares="<?= htmlspecialchars((string)($c['shares'] ?? '')) ?>"
                data-house-number="<?= htmlspecialchars((string)($c['house_number'] ?? '')) ?>"
                data-address="<?= htmlspecialchars((string)($c['address'] ?? '')) ?>"
              >
                <td><b><?= htmlspecialchars((string)($c['id'] ?? '')) ?></b></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center"
                         style="width:36px;height:36px;background:linear-gradient(135deg,var(--gold),var(--amber));color:#1b1b1b;font-weight:800;">
                      <?= htmlspecialchars($initial) ?>
                    </div>
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($c['name'] ?? 'ไม่มีข้อมูล') ?></div>
                      <div class="text-muted small d-md-none"><?= htmlspecialchars($c['phone'] ?? '') ?></div>
                    </div>
                  </div>
                </td>
                <td class="d-none d-md-table-cell">
                  <?= !empty($c['position'])
                        ? '<span class="badge bg-secondary-subtle">'.htmlspecialchars((string)$c['position']).'</span>'
                        : '<span class="text-muted">-</span>'; ?>
                </td>
                <td class="d-none d-md-table-cell">
                  <?= $deptName !== '' && $deptName!=='ไม่ระบุแผนก'
                        ? '<span class="badge bg-info-subtle">'.htmlspecialchars($deptName).'</span>'
                        : '<span class="text-muted">-</span>'; ?>
                </td>
                <td class="d-none d-lg-table-cell text-center">
                  <?= !empty($c['shares'])
                        ? '<span class="badge bg-info-subtle">'.number_format((int)$c['shares']).' หุ้น</span>'
                        : '<span class="text-muted">-</span>'; ?>
                </td>
                <td class="d-none d-lg-table-cell"><?= htmlspecialchars((string)($c['house_number'] ?? '-')) ?></td>
                <td class="d-none d-xl-table-cell">
                  <span class="text-truncate d-inline-block" style="max-width: 220px;" title="<?= htmlspecialchars((string)($c['address'] ?? '')) ?>">
                    <?= htmlspecialchars((string)($c['address'] ?: '-')) ?>
                  </span>
                </td>
                <td class="text-end d-none d-lg-table-cell"><?= htmlspecialchars((string)($c['joined_date'] ?? '')) ?></td>
                <td class="text-end">
                  <div class="btn-group">
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
    </main>
  </div>
</div>

<!-- Footer -->
<footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการกรรมการ</footer>

<!-- ===== Modals ===== -->
<!-- Add -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="formAdd" method="post" action="committee_create.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>เพิ่มกรรมการใหม่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <!-- User Account Info -->
          <div class="col-12"><h6 class="text-primary border-bottom pb-2 mb-3">ข้อมูลสำหรับเข้าสู่ระบบ</h6></div>
          <div class="col-sm-6">
            <label class="form-label">ชื่อผู้ใช้ (Username)</label>
            <input type="text" name="username" class="form-control" required placeholder="สำหรับ Login">
          </div>
          <div class="col-sm-6">
            <label class="form-label">รหัสผ่าน</label>
            <input type="password" name="password" class="form-control" required minlength="8" placeholder="อย่างน้อย 8 ตัวอักษร">
          </div>
          <div class="col-sm-6">
            <label class="form-label">ชื่อ-สกุล</label>
            <input type="text" name="full_name" class="form-control" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">อีเมล</label>
            <input type="email" name="email" class="form-control" placeholder="example@email.com">
          </div>

          <!-- Committee Info -->
          <div class="col-12 mt-4"><h6 class="text-primary border-bottom pb-2 mb-3">ข้อมูลกรรมการ</h6></div>
          <div class="col-sm-6">
            <label class="form-label">รหัสกรรมการ</label>
            <input type="text" name="committee_code" class="form-control" required placeholder="เช่น C-001">
          </div>
          <div class="col-sm-6">
            <label class="form-label">เบอร์โทร</label>
            <input type="tel" name="phone" class="form-control" placeholder="08x-xxx-xxxx">
          </div>
          <div class="col-sm-6">
            <label class="form-label">ตำแหน่ง</label>
            <input type="text" name="position" class="form-control" placeholder="เช่น ประธาน, เหรัญญิก">
          </div>
          <div class="col-sm-6">
            <label class="form-label">แผนก</label>
            <select name="department" class="form-select">
              <option value="">ไม่ระบุ</option>
              <?php foreach($departments as $k=>$v): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label">วันที่เริ่มงาน</label>
            <input type="date" name="joined_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label">จำนวนหุ้น</label>
            <input type="number" name="shares" class="form-control" min="0" placeholder="0">
          </div>
          <div class="col-sm-6">
            <label class="form-label">บ้านเลขที่ครัวเรือน</label>
            <input type="text" name="house_number" class="form-control" placeholder="เลขที่บ้าน/หมู่บ้าน">
          </div>
          <div class="col-12">
            <label class="form-label">ที่อยู่</label>
            <textarea name="address" class="form-control" rows="2" placeholder="ที่อยู่เต็ม"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" type="submit"><i class="bi bi-save2 me-1"></i> บันทึก</button>
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ปิด</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="formEdit" method="post" action="committee_edit.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="user_id" id="editUserId">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขกรรมการ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label">รหัสกรรมการ</label>
            <input type="text" name="committee_code" class="form-control" id="editId" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">เบอร์โทร</label>
            <input type="tel" name="phone" class="form-control" id="editPhone">
          </div>
          <div class="col-12">
            <label class="form-label">ชื่อ-สกุล</label>
            <input type="text" name="full_name" class="form-control" id="editName" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">ตำแหน่ง</label>
            <input type="text" name="position" class="form-control" id="editPosition">
          </div>
          <div class="col-sm-6">
            <label class="form-label">แผนก</label>
            <select name="department" class="form-select" id="editDepartment">
              <option value="">ไม่ระบุ</option>
              <?php foreach($departments as $k=>$v): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label">วันที่เริ่มงาน</label>
            <input type="date" name="joined_date" class="form-control" id="editJoined">
          </div>
          <div class="col-sm-6">
            <label class="form-label">จำนวนหุ้น</label>
            <input type="number" name="shares" class="form-control" id="editShares" min="0">
          </div>
          <div class="col-sm-6">
            <label class="form-label">บ้านเลขที่ครัวเรือน</label>
            <input type="text" name="house_number" class="form-control" id="editHouseNumber">
          </div>
          <div class="col-12">
            <label class="form-label">ที่อยู่</label>
            <textarea name="address" class="form-control" id="editAddress" rows="2"></textarea>
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

<!-- Delete confirm -->
<div class="modal fade" id="modalDel" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="formDelete" method="post" action="committee_delete.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="user_id" id="deleteUserId">
        <div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>ลบกรรมการ</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
        </div>
        <div class="modal-body">
          ต้องการลบกรรมการ <b id="delName"></b> (<span id="delId"></span>) ใช่หรือไม่?
          <div class="text-danger mt-2">การดำเนินการนี้ไม่สามารถย้อนกลับได้</div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="deleteUserToo" name="delete_user" value="1">
          <label class="form-check-label" for="deleteUserToo">
            ลบบัญชีผู้ใช้นี้ออกจากระบบด้วย (จะลบข้อมูลบทบาทที่เกี่ยวข้องแบบพ่วงด้วย)
          </label>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i> ยืนยันการลบ</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
  <div id="liveToast" class="toast align-items-center border-0" role="status" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">บันทึกเรียบร้อย</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="ปิด"></button>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const $ = (s, p=document)=>p.querySelector(s);
  const $$ = (s, p=document)=>[...p.querySelectorAll(s)];
  const toast = (msg, isSuccess = true)=>{
    const t = $('#liveToast');
    const tBody = t.querySelector('.toast-body');
    t.classList.remove('text-bg-success', 'text-bg-danger', 'text-bg-dark');
    t.classList.add(isSuccess ? 'text-bg-success' : 'text-bg-danger');
    tBody.textContent = msg || 'ดำเนินการเรียบร้อย';
    bootstrap.Toast.getOrCreateInstance(t, { delay: 3000 }).show();
  };

  // Show toast for server-side messages
  const urlParams = new URLSearchParams(window.location.search);
  const okMsg = urlParams.get('ok');
  const errMsg = urlParams.get('err');
  if (okMsg) { toast(okMsg, true);  window.history.replaceState({}, document.title, window.location.pathname); }
  if (errMsg) { toast(errMsg, false); window.history.replaceState({}, document.title, window.location.pathname); }

  // ค้นหา + กรองแผนก
  const committeeSearch = $('#committeeSearch');
  const deptFilter = $('#deptFilter');

  function normalize(s){ return (s||'').toString().toLowerCase().trim(); }
  function applyFilter(){
    const k = normalize(committeeSearch.value);
    const dept = normalize(deptFilter.value);
    $$('#committeeTable tbody tr').forEach(tr=>{
      const searchText = normalize(`${tr.dataset.id} ${tr.dataset.name} ${tr.dataset.phone} ${tr.dataset.position} ${tr.dataset.department} ${tr.dataset.shares} ${tr.dataset.houseNumber} ${tr.dataset.address}`);
      const matchSearch = (!k || searchText.includes(k));
      const matchDept = (!dept || normalize(tr.dataset.department) === dept);
      tr.style.display = (matchSearch && matchDept) ? '' : 'none';
    });
  }
  committeeSearch.addEventListener('input', applyFilter);
  deptFilter.addEventListener('change', applyFilter);

  // พิมพ์ & ส่งออก
  $('#btnPrint').addEventListener('click', ()=> window.print());
  $('#btnExport').addEventListener('click', ()=>{
    const rows = [['ID','Name','Phone','Position','Department','Shares','HouseNumber','Address','Joined']];
    $$('#committeeTable tbody tr').forEach(tr=>{
      if(tr.style.display==='none') return;
      rows.push([
        tr.dataset.id, tr.dataset.name, tr.dataset.phone, tr.dataset.position,
        tr.dataset.department, tr.dataset.shares, tr.dataset.houseNumber, tr.dataset.address, tr.dataset.joined
      ]);
    });
    const csv = rows.map(r=>r.map(v=>`"${(v||'').replaceAll('"','""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'committees.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // แก้ไข
  function openEdit(tr){
    $('#editUserId').value = tr.dataset.userId || '';
    $('#editId').value = tr.dataset.id || '';
    $('#editName').value = tr.dataset.name || '';
    $('#editPhone').value = tr.dataset.phone || '';
    $('#editPosition').value = tr.dataset.position || '';
    $('#editDepartment').value = tr.dataset.department || '';
    $('#editJoined').value = tr.dataset.joined || '';
    $('#editShares').value = tr.dataset.shares || '';
    $('#editHouseNumber').value = tr.dataset.houseNumber || '';
    $('#editAddress').value = tr.dataset.address || '';
    new bootstrap.Modal('#modalEdit').show();
  }

  // ลบ
  function openDelete(tr){
    $('#deleteUserId').value = tr.dataset.userId || '';
    $('#delId').textContent = tr.dataset.id || '';
    $('#delName').textContent = tr.dataset.name || '';
    new bootstrap.Modal('#modalDel').show();
  }

  function attachRowHandlers(tr){
    tr.querySelector('.btnEdit')?.addEventListener('click', ()=> openEdit(tr));
    tr.querySelector('.btnDel')?.addEventListener('click', ()=> openDelete(tr));
  }
  $$('#committeeTable tbody tr').forEach(attachRowHandlers);
</script>
</body>
</html>
