<?php
// admin/employee.php — จัดการพนักงาน (เชื่อมต่อฐานข้อมูลจริง + รองรับเงินเดือนถ้ามีคอลัมน์)
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== ตรวจสอบการล็อกอิน ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=' . urlencode('โปรดเข้าสู่ระบบก่อน'));
  exit();
}

/* ===== เชื่อมต่อฐานข้อมูล ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมีตัวแปร $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบ $pdo ใน config/db.php');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

/* ===== ตรวจสิทธิ์แอดมิน (ยืดหยุ่น: จาก session.role หรือ ตาราง admins) ===== */
try {
  $current_user_id = (int)($_SESSION['user_id'] ?? 0);
  $current_name    = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
  $current_role    = $_SESSION['role'] ?? '';
  $is_admin = ($current_role === 'admin');

  if (!$is_admin) {
    $st = $pdo->prepare("SELECT 1 FROM admins WHERE user_id = ? LIMIT 1");
    $st->execute([$current_user_id]);
    $is_admin = (bool)$st->fetchColumn();
  }
  if (!$is_admin) {
    header('Location: /index/login.php?err=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'));
    exit();
  }
  $current_role = 'admin';
} catch (Throwable $e) {
  header('Location: /index/login.php?err=' . urlencode('เกิดข้อผิดพลาดของระบบ'));
  exit();
}

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ===== Helpers ===== */
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

/* ===== ค่า station/site_name จาก settings ===== */
$station_id = 1;
$site_name  = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  if (table_exists($pdo,'settings')) {
    $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $station_id = (int)($row['setting_value'] ?? 1);
      if (!empty($row['comment'])) { $site_name = $row['comment']; }
    }
  }
} catch (Throwable $e) { /* ใช้ค่า default */ }

/* ===== ดึงข้อมูลพนักงานจากฐานข้อมูลจริง =====
   employees: id, user_id, station_id, emp_code, position, joined_date, created_at
   users: id, username, email, full_name, phone, role, ...
*/
$employees = [];
$has_salary = column_exists($pdo, 'employees', 'salary'); // ในสคีมาที่ให้มายังไม่มีคอลัมน์นี้
try {
  $select_salary = "e.salary";
  $sql = "
    SELECT
      u.id AS user_id,
      e.emp_code AS id,
      COALESCE(u.full_name, '-') AS name,
      COALESCE(u.phone, '') AS phone,
      e.position AS position,
      DATE_FORMAT(e.joined_date, '%Y-%m-%d') AS joined,
      $select_salary,
      e.address AS address
    FROM employees e
    LEFT JOIN users u ON u.id = e.user_id
    WHERE e.station_id = :station_id
    ORDER BY e.joined_date DESC, e.emp_code ASC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(['station_id' => $station_id]);
  $employees = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $employees = [[
    'user_id'=>0,
    'id'=>'E-ERR',
    'name'=>'โหลดข้อมูลล้มเหลว',
    'phone'=>'',
    'position'=>'',
    'joined'=>date('Y-m-d'),
    'salary'=>null,
    'address'=>$e->getMessage()
  ]];
}

/* ===== UI helpers ===== */
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

/* ===== สถิติเร็ว ๆ ===== */
$total = count($employees);
$joinedThisYear = count(array_filter($employees, fn($e)=> !empty($e['joined']) && substr($e['joined'],0,4)===date('Y')));
$total_salary = $has_salary ? array_sum(array_map(fn($e)=> (float)($e['salary'] ?? 0), $employees)) : 0.0;
$avg_salary   = ($has_salary && $total>0) ? $total_salary / $total : 0.0;

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>พนักงาน | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .badge.bg-info-subtle{background:rgba(13,202,240,.1)!important;color:#0dcaf0!important}
    .currency { white-space: nowrap; }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
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
      <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a class="active" href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
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
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a class="active" href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket me-1"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
      <div class="main-header">
        <h2><i class="bi bi-person-badge-fill"></i> จัดการพนักงาน</h2>
      </div>

      <div class="stats-grid">
        <div class="stat-card">
          <h5><i class="bi bi-people-fill"></i> พนักงานทั้งหมด</h5>
          <h3 class="text-primary"><?= number_format($total) ?> คน</h3>
          <p class="mb-0 text-muted">รวมทุกตำแหน่ง</p>
        </div>
        <div class="stat-card">
          <h5><i class="bi bi-calendar-plus"></i> เข้าร่วมปีนี้</h5>
          <h3 class="text-success"><?= number_format($joinedThisYear) ?> คน</h3>
          <p class="mb-0 text-muted">อัปเดตจากวันที่เริ่มงาน</p>
        </div>
        <?php if ($has_salary): ?>
        <div class="stat-card">
          <h5><i class="fa-solid fa-sack-dollar"></i> เงินเดือนรวม (ประมาณ)</h5>
          <h3 class="text-info">฿<?= number_format($total_salary, 2) ?></h3>
          <p class="mb-0 text-muted">เฉลี่ย ฿<?= number_format($avg_salary, 2) ?>/คน</p>
        </div>
        <?php endif; ?>
      </div>

      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div class="input-group" style="max-width:320px;">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="search" id="empSearch" class="form-control" placeholder="ค้นหา: ชื่อ/รหัส/เบอร์/ตำแหน่ง/ที่อยู่/เงินเดือน">
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-primary" id="btnExport"><i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV</button>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd"><i class="bi bi-person-plus-fill me-1"></i> เพิ่มพนักงาน</button>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head mb-2">
          <h5 class="mb-0"><i class="fa-solid fa-list me-2"></i>รายชื่อพนักงาน</h5>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="empTable">
            <thead>
              <tr>
                <th>รหัส</th>
                <th>พนักงาน</th>
                <th class="d-none d-md-table-cell">ตำแหน่ง</th>
                <th class="d-none d-md-table-cell">เบอร์โทร</th>
                <?php if ($has_salary): ?>
                  <th class="d-none d-lg-table-cell text-end">เงินเดือน</th>
                <?php endif; ?>
                <th class="text-end d-none d-lg-table-cell">เริ่มงาน</th>
                <th class="text-end">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($employees as $e):
                $initial = mb_substr($e['name'] ?? '',0,1,'UTF-8') ?: 'พ';
                $salary  = $has_salary ? (float)($e['salary'] ?? 0) : null;
              ?>
              <tr
                data-user-id="<?= (int)$e['user_id'] ?>"
                data-id="<?= htmlspecialchars($e['id']) ?>"
                data-name="<?= htmlspecialchars($e['name']) ?>"
                data-phone="<?= htmlspecialchars($e['phone']) ?>"
                data-joined="<?= htmlspecialchars($e['joined']) ?>"
                data-address="<?= htmlspecialchars($e['address'] ?? '') ?>"
                data-position="<?= htmlspecialchars($e['position'] ?? 'พนักงานปั๊ม') ?>"
                <?php if ($has_salary): ?> data-salary="<?= htmlspecialchars($e['salary'] ?? '') ?>" <?php endif; ?>
              >
                <td><b><?= htmlspecialchars($e['id']) ?></b></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center"
                         style="width:36px;height:36px;background:linear-gradient(135deg,var(--gold),var(--amber));color:#1b1b1b;font-weight:800;">
                      <?= htmlspecialchars($initial) ?>
                    </div>
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($e['name']) ?></div>
                      <div class="text-muted small d-md-none"><?= htmlspecialchars($e['phone']) ?></div>
                    </div>
                  </div>
                </td>
                <td class="d-none d-md-table-cell"><?= htmlspecialchars($e['position'] ?? '-') ?></td>
                <td class="d-none d-md-table-cell"><?= htmlspecialchars($e['phone']) ?: '-' ?></td>
                <?php if ($has_salary): ?>
                <td class="d-none d-lg-table-cell text-end">
                  <?= $salary !== null ? ('฿<span class="currency">'.number_format($salary,2).'</span>') : '-' ?>
                </td>
                <?php endif; ?>
                <td class="text-end d-none d-lg-table-cell"><?= htmlspecialchars($e['joined'] ?: '-') ?></td>
                <td class="text-end">
                  <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-outline-primary btnEdit"><i class="bi bi-pencil-square"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger btnDel"><i class="bi bi-trash"></i></button>
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

<footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการพนักงาน</footer>

<!-- ===== Modals ===== -->
<!-- Add -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="formAdd" method="post" action="employee_create.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>เพิ่มพนักงาน</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12"><h6 class="text-primary border-bottom pb-2 mb-3">ข้อมูลสำหรับเข้าสู่ระบบ</h6></div>
          <div class="col-sm-6">
            <label class="form-label">ชื่อผู้ใช้ (Username)</label>
            <input type="text" name="username" class="form-control" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">รหัสผ่าน</label>
            <input type="password" name="password" class="form-control" required minlength="8">
          </div>
          <div class="col-sm-6">
            <label class="form-label">ชื่อ-สกุล</label>
            <input type="text" name="full_name" class="form-control" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">อีเมล</label>
            <input type="email" name="email" class="form-control" placeholder="example@email.com">
          </div>
          <div class="col-sm-6">
            <label class="form-label">เบอร์โทร</label>
            <input type="tel" name="phone" class="form-control" placeholder="08x-xxx-xxxx">
          </div>

          <div class="col-12 mt-4"><h6 class="text-primary border-bottom pb-2 mb-3">ข้อมูลพนักงาน</h6></div>
          <div class="col-sm-6">
            <label class="form-label">รหัสพนักงาน</label>
            <input type="text" name="employee_code" class="form-control" required placeholder="เช่น E-0100">
          </div>
          <div class="col-sm-6">
            <label class="form-label">ตำแหน่ง</label>
            <input type="text" name="position" class="form-control" value="พนักงานปั๊ม">
          </div>
          <div class="col-sm-6">
            <label class="form-label">วันที่เริ่มงาน</label>
            <input type="date" name="joined_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <?php if ($has_salary): ?>
          <div class="col-sm-6">
            <label class="form-label">เงินเดือน (บาท)</label>
            <input type="number" name="salary" class="form-control" step="0.01" min="0" placeholder="เช่น 15000">
          </div>
          <?php endif; ?>
          <div class="col-12">
            <label class="form-label">ที่อยู่</label>
            <textarea name="address" class="form-control" rows="2" placeholder="ที่อยู่เต็ม (เก็บใน users หรือที่อื่น หากต้องการ)"></textarea>
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

<!-- Edit -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="formEdit" method="post" action="employee_edit.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="user_id" id="editUserId">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขพนักงาน</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
        <div class="col-12">
            <label class="form-label">ชื่อ-สกุลพนักงาน</label>
            <input type="text" name="full_name" class="form-control" id="editName" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">รหัสพนักงาน</label>
            <input type="text" name="employee_code" class="form-control" id="editId" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label">ตำแหน่ง</label>
            <input type="text" name="position" class="form-control" id="editPosition" placeholder="พนักงานปั๊ม">
          </div>
          <div class="col-sm-6">
            <label class="form-label">เบอร์โทร</label>
            <input type="tel" name="phone" class="form-control" id="editPhone">
          </div>
          <div class="col-sm-6">
            <label class="form-label">วันที่เริ่มงาน</label>
            <input type="date" name="joined_date" class="form-control" id="editJoined">
          </div>
          <?php if ($has_salary): ?>
          <div class="col-sm-6">
            <label class="form-label">เงินเดือน (บาท)</label>
            <input type="number" name="salary" class="form-control" id="editSalary" step="0.01" min="0">
          </div>
          <?php endif; ?>
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
      <form id="formDelete" method="post" action="employee_delete.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="user_id" id="deleteUserId">
        <div class="modal-header">
          <h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>ลบพนักงาน</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
        </div>
        <div class="modal-body">
          ต้องการลบพนักงาน <b id="delName"></b> (<span id="delId"></span>) ใช่หรือไม่?
          <div class="text-danger mt-2">การดำเนินการนี้ไม่สามารถย้อนกลับได้</div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="deleteUserToo" name="delete_user" value="1">
          <label class="form-check-label" for="deleteUserToo">
            ลบบัญชีผู้ใช้นี้ออกจากระบบด้วย (จะลบข้อมูลบทบาทที่เกี่ยวข้องแบบพ่วงด้วย)
          </label>
        </div>
        <div class="modal-footer">
          <button class="btn btn-danger" type="submit"><i class="bi bi-check2-circle me-1"></i> ลบ</button>
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="liveToast" class="toast align-items-center border-0" role="status" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">บันทึกเรียบร้อย</div>
      <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="ปิด"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $=(s,p=document)=>p.querySelector(s);
const $$=(s,p=document)=>[...p.querySelectorAll(s)];
const toast=(msg,isOk=true)=>{
  const t=$('#liveToast'); const b=t.querySelector('.toast-body');
  t.classList.remove('text-bg-success','text-bg-danger');
  t.classList.add(isOk?'text-bg-success':'text-bg-danger');
  b.textContent=msg||'บันทึกเรียบร้อย';
  bootstrap.Toast.getOrCreateInstance(t,{delay:2200}).show();
};

// server messages (?ok / ?err)
const urlParams=new URLSearchParams(window.location.search);
const okMsg=urlParams.get('ok'); const errMsg=urlParams.get('err');
if (okMsg){ toast(okMsg,true); history.replaceState({},document.title,location.pathname); }
if (errMsg){ toast(errMsg,false); history.replaceState({},document.title,location.pathname); }

// filter
const empSearch=$('#empSearch');
function normalize(s){return (s||'').toString().toLowerCase().trim();}
function applyFilter(){
  const k=normalize(empSearch.value);
  $$('#empTable tbody tr').forEach(tr=>{
    const searchText=normalize([
      tr.dataset.id, tr.dataset.name, tr.dataset.phone,
      tr.dataset.position, tr.dataset.address, tr.dataset.salary, tr.dataset.joined
    ].join(' '));
    tr.style.display=(!k||searchText.includes(k))?'':'none';
  });
}
empSearch.addEventListener('input',applyFilter);

// print & export
$('#btnPrint').addEventListener('click',()=>window.print());
$('#btnExport').addEventListener('click',()=>{
  const headers=['ID','Name','Position','Phone',<?= $has_salary ? "'Salary'," : "" ?>'Joined','Address'];
  const rows=[headers];
  $$('#empTable tbody tr').forEach(tr=>{
    if (tr.style.display==='none') return;
    const r=[
      tr.dataset.id, tr.dataset.name, tr.dataset.position,
      tr.dataset.phone, <?= $has_salary ? "tr.dataset.salary," : "" ?> tr.dataset.joined, tr.dataset.address
    ];
    rows.push(r);
  });
  const csv=rows.map(r=>r.map(v=>`\"${(v||'').toString().replaceAll('\"','\"\"')}\"`).join(',')).join('\n');
  const blob=new Blob([csv],{type:'text/csv;charset=utf-8;'}); const a=document.createElement('a');
  a.href=URL.createObjectURL(blob); a.download='employees.csv'; a.click(); URL.revokeObjectURL(a.href);
});

function setVal(sel, val){
  const el = document.querySelector(sel);
  if (el) el.value = (val ?? '');
}

function openEdit(tr){
  setVal('#editUserId', tr.dataset.userId);
  setVal('#editId', tr.dataset.id);
  setVal('#editName', tr.dataset.name);
  setVal('#editPosition', tr.dataset.position || 'พนักงานปั๊ม');
  setVal('#editPhone', tr.dataset.phone);
  setVal('#editJoined', tr.dataset.joined);
  setVal('#editAddress', tr.dataset.address);

  // 👇 เพิ่มบรรทัดนี้ให้เงินเดือนขึ้น
  setVal('#editSalary', tr.dataset.salary);

  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEdit')).show();
}

function openDelete(tr){
  document.querySelector('#deleteUserId').value = tr.dataset.userId;
  document.querySelector('#delId').textContent = tr.dataset.id;
  document.querySelector('#delName').textContent = tr.dataset.name;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDel')).show();
}

function attachRowHandlers(tr){
  tr.querySelector('.btnEdit')?.addEventListener('click',()=>openEdit(tr));
  tr.querySelector('.btnDel')?.addEventListener('click',()=>openDelete(tr));
}
$$('#empTable tbody tr').forEach(attachRowHandlers);
</script>
</body>
</html>