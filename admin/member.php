<?php
// member.php — จัดการสมาชิกสหกรณ์ (ติดต่อฐานข้อมูลจริง + ฟอร์มจริงสำหรับเพิ่ม/แก้ไข/ลบ)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
    exit();
}

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { 
    $dbFile = __DIR__ . '/config/db.php'; 
}
require_once $dbFile; // ต้องมีตัวแปร $pdo

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
  $current_name = $_SESSION['full_name'] ?: 'ผู้ดูแลระบบ';
  $current_role = $_SESSION['role'];
  if($current_role !== 'admin'){
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

// ดึงข้อมูลสมาชิกจากฐานข้อมูล
$members = [];
$tiers = [];
try {
    // ดึงข้อมูลสมาชิกทั้งหมด (เพิ่ม u.id เป็น user_id)
    $stmt = $pdo->query("
    SELECT
        u.id AS user_id,
        m.member_code AS id,
        u.full_name AS name,
        u.phone,
        m.tier,
        m.points,
        m.joined_date AS joined,
        m.shares,
        m.house_number,
        m.address
    FROM members m
    JOIN users u ON m.user_id = u.id
    WHERE m.is_active = 1
    ORDER BY m.joined_date DESC
");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ดึงระดับสมาชิกทั้งหมดสำหรับ dropdown filter
    $tier_stmt = $pdo->query("
    SELECT DISTINCT tier 
    FROM members 
    WHERE is_active = 1 AND tier IS NOT NULL AND tier != '' 
    ORDER BY tier
");
    $tiers = $tier_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tiers)) {
      $tiers = ['Diamond','Platinum','Gold','Silver','Bronze']; // Fallback
    }
    try {
      $col = $pdo->query("
          SELECT COLUMN_TYPE
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'members'
            AND COLUMN_NAME = 'tier'
      ")->fetchColumn();
  
      $tiers = [];
      if ($col && preg_match_all("/'([^']+)'/", $col, $m)) {
          $tiers = $m[1]; // ได้ ['Bronze','Silver','Gold','Platinum','Diamond'] ตามสคีมา
      }
      if (empty($tiers)) {
          // fallback (เผื่อ info schema ใช้ไม่ได้)
          $tiers = ['Bronze','Silver','Gold','Platinum','Diamond'];
      }
  } catch (Throwable $e) {
      $tiers = ['Bronze','Silver','Gold','Platinum','Diamond'];
  }

} catch (Throwable $e) {
    // ตรวจสอบข้อผิดพลาด
    error_log($e->getMessage());
    error_log($e->getTraceAsString());
    // Fallback data in case of error, to prevent page from breaking
    $members = [
      [
        'user_id'=>0,
        'id'=>'M-ERR', 'name'=>'เกิดข้อผิดพลาดในการโหลดข้อมูล ', 'phone'=>'', 'tier'=>'',
        'points'=>0, 'joined'=>date('Y-m-d'), 'shares'=>0, 'house_number'=>'', 'address'=>''
      ]
    ];
    $tiers = ['Gold', 'Silver', 'Bronze'];
}


// ดึงข้อมูลจากฐานข้อมูล
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
    // ชื่อเว็บไซต์
    $st = $pdo->query("SELECT site_name FROM settings WHERE id=1");
    if ($r = $st->fetch()) {
        $site_name = $r['site_name'] ?: $site_name;
    }
} catch (Throwable $e) {}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
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
  <title>สมาชิก | สหกรณ์ปั๊มน้ำมัน</title>

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

  <!-- Global theme -->
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .badge.bg-warning-subtle{background:rgba(255,193,7,.1)!important; color: #ffc107!important;}
    .tier-gold { color: #ffd700; font-weight: 600; }
    .tier-silver { color: #c0c0c0; font-weight: 600; }
    .tier-bronze { color: #cd7f32; font-weight: 600; }
    .tier-platinum { color:#e5e4e2; font-weight:600; }
    .tier-diamond  { color:#00bcd4; font-weight:600; }
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
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= htmlspecialchars($site_name) ?></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2">
        <h3><span>Admin</span></h3>
      </div>
      <nav class="sidebar-menu">
        <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a class="active" href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
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
          <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
          <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
          <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
          <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
          <a href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
          <a class="active" href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
          <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
        </nav>
        <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>

      <!-- Content -->
      <main class="col-lg-10 p-4">
        <div class="main-header">
          <h2><i class="bi bi-people-fill"></i> จัดการสมาชิก</h2>
        </div>

        <!-- Summary cards -->
        <div class="stats-grid">
          <?php
            $total = count($members);
            $thisMonth = date('Y-m');
            $newThisMonth = count(array_filter($members, fn($m)=> substr((string)$m['joined'],0,7)===$thisMonth));
            $totalPoints = array_reduce($members, fn($c,$m)=>$c+(int)$m['points'], 0);
            $totalShares = array_sum(array_map(fn($v)=> (int)$v, array_column($members, 'shares')));
          ?>
          <div class="stat-card">
            <h5><i class="bi bi-people-fill"></i> สมาชิกทั้งหมด</h5>
            <h3 class="text-primary"><?= number_format($total) ?> คน</h3>
            <p class="mb-0 text-muted">เพิ่มใหม่เดือนนี้ <?= number_format($newThisMonth) ?> คน</p>
          </div>
          <div class="stat-card">
            <h5><i class="bi bi-stars"></i> คะแนนสะสมรวม</h5>
            <h3 class="text-success"><?= number_format($totalPoints) ?></h3>
            <p class="mb-0 text-muted">รวมทุกระดับสมาชิก</p>
          </div>
          <div class="stat-card">
            <h5><i class="fa-solid fa-chart-pie"></i> หุ้นสมาชิกรวม</h5>
            <h3 class="text-warning"><?= number_format($totalShares) ?> หุ้น</h3>
            <p class="mb-0 text-muted">จากสมาชิกทั้งหมด</p>
          </div>
        </div>

        <!-- Toolbar -->
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
          <div class="d-flex flex-wrap gap-2">
            <div class="input-group" style="max-width:320px;">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="search" id="memSearch" class="form-control" placeholder="ค้นหา: รหัส/ชื่อ/เบอร์/ที่อยู่">
            </div>
            <select id="filterTier" class="form-select">
              <option value="">ทุกระดับ</option>
              <?php foreach($tiers as $t) echo '<option value="'.htmlspecialchars($t).'">'.htmlspecialchars($t).'</option>'; ?>
            </select>
            <div class="input-group" style="max-width:220px;">
              <span class="input-group-text"><i class="bi bi-123"></i></span>
              <input type="number" id="minPoint" class="form-control" placeholder="คะแนนขั้นต่ำ" min="0" step="100">
            </div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" id="btnExport"><i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV</button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd"><i class="bi bi-person-plus-fill me-1"></i> เพิ่มสมาชิก</button>
          </div>
        </div>

        <!-- Table -->
        <div class="panel">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="memTable">
              <thead>
                <tr>
                  <th>รหัส</th>
                  <th>สมาชิก</th>
                  <th class="d-none d-md-table-cell">เบอร์โทร</th>
                  <th>ระดับ</th>
                  <th class="text-end">คะแนน</th>
                  <th class="d-none d-lg-table-cell text-center">หุ้น</th>
                  <th class="d-none d-xl-table-cell">บ้านเลขที่</th>
                  <th class="d-none d-xxl-table-cell">ที่อยู่</th>
                  <th class="text-end d-none d-lg-table-cell">สมัครเมื่อ</th>
                  <th class="text-end">จัดการ</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($members as $m):
                  $initial = mb_substr((string)$m['name'],0,1,'UTF-8');
                  $tierVal = (string)($m['tier'] ?? '');
                  $tierClass = $tierVal !== '' ? 'tier-' . strtolower($tierVal) : '';
                ?>
                <tr
                  data-user-id="<?= htmlspecialchars((string)$m['user_id']) ?>"
                  data-id="<?= htmlspecialchars((string)$m['id']) ?>"
                  data-name="<?= htmlspecialchars((string)$m['name']) ?>"
                  data-phone="<?= htmlspecialchars((string)$m['phone']) ?>"
                  data-tier="<?= htmlspecialchars($tierVal) ?>"
                  data-points="<?= htmlspecialchars((string)$m['points']) ?>"
                  data-joined="<?= htmlspecialchars((string)$m['joined']) ?>"
                  data-shares="<?= htmlspecialchars((string)($m['shares'] ?? '')) ?>"
                  data-house-number="<?= htmlspecialchars((string)($m['house_number'] ?? '')) ?>"
                  data-address="<?= htmlspecialchars((string)($m['address'] ?? '')) ?>"
                >
                  <td><b><?= htmlspecialchars((string)$m['id']) ?></b></td>
                  <td>
                      <div class="d-flex align-items-center gap-2">
                          <div class="rounded-circle d-inline-flex align-items-center justify-content-center"
                              style="width:36px;height:36px;background:linear-gradient(135deg,var(--gold),var(--amber));color:#1b1b1b;font-weight:800;">
                              <?= htmlspecialchars(mb_substr($m['name'], 0, 1, 'UTF-8')) ?>
                          </div>
                          <div>
                              <div class="fw-semibold"><?= htmlspecialchars($m['name'] ?? 'ชื่อไม่พบ') ?></div>
                              <div class="text-muted small d-md-none"><?= htmlspecialchars($m['phone']) ?></div>
                          </div>
                      </div>
                  </td>
                  <td class="d-none d-md-table-cell"><?= htmlspecialchars((string)$m['phone']) ?></td>
                  <td><span class="<?= $tierClass ?>"><?= htmlspecialchars($tierVal) ?></span></td>
                  <td class="text-end"><?= number_format((int)$m['points']) ?></td>
                  <td class="d-none d-lg-table-cell text-center">
                    <?php if (!empty($m['shares'])): ?>
                      <span class="badge bg-warning-subtle"><?= number_format((int)$m['shares']) ?> หุ้น</span>
                    <?php else: ?>
                      <span class="text-muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="d-none d-xl-table-cell"><?= htmlspecialchars((string)($m['house_number'] ?? '-')) ?></td>
                  <td class="d-none d-xxl-table-cell">
                    <span class="text-truncate d-inline-block" style="max-width: 200px;" title="<?= htmlspecialchars((string)($m['address'] ?? '')) ?>">
                      <?= htmlspecialchars($m['address'] ? (string)$m['address'] : '-') ?>
                    </span>
                  </td>
                  <td class="text-end d-none d-lg-table-cell"><?= htmlspecialchars((string)$m['joined']) ?></td>
                  <td class="text-end">
                    <div class="btn-group">
                      <button class="btn btn-sm btn-outline-primary btnEdit" type="button"><i class="bi bi-pencil-square"></i></button>
                      <button class="btn btn-sm btn-outline-danger btnDel" type="button"><i class="bi bi-trash"></i></button>
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
  <footer class="footer">© <?= date('Y') ?> สหกรณ์ปั๊มน้ำมัน — จัดการสมาชิก</footer>

  <!-- ===== Modals ===== -->
  <!-- Add -->
  <div class="modal fade" id="modalAdd" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <form class="modal-content" id="formAdd" method="post" action="member_create.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>เพิ่มสมาชิกใหม่</h5>
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

            <!-- Member Info -->
            <div class="col-12 mt-4"><h6 class="text-primary border-bottom pb-2 mb-3">ข้อมูลสมาชิก</h6></div>
            <div class="col-sm-6">
              <label class="form-label">รหัสสมาชิก</label>
              <input type="text" name="member_code" class="form-control" required placeholder="เช่น M-001">
            </div>
            <div class="col-sm-6">
              <label class="form-label">เบอร์โทร</label>
              <input type="tel" name="phone" class="form-control" placeholder="08x-xxx-xxxx">
            </div>
            <div class="col-sm-4">
              <label class="form-label">ระดับ</label>
              <select name="tier" class="form-select">
                <?php
                  $defaultTier = 'Bronze';
                  foreach ($tiers as $t) {
                      $sel = ($t === $defaultTier) ? ' selected' : '';
                      echo '<option value="'.htmlspecialchars($t).'"'.$sel.'>'.htmlspecialchars($t).'</option>';
                  }
                ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label">จำนวนหุ้น</label>
              <input type="number" name="shares" min="0" step="1" class="form-control" placeholder="0">
            </div>
            <div class="col-sm-4">
              <label class="form-label">คะแนนเริ่มต้น</label>
              <input type="number" name="points" min="0" step="10" class="form-control" value="0">
            </div>
            <div class="col-sm-6">
              <label class="form-label">วันที่สมัคร</label>
              <input type="date" name="joined_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label">บ้านเลขที่ครัวเรือน</label>
              <input type="text" name="house_number" class="form-control" placeholder="เลขที่บ้าน/หมู่บ้าน/อาคาร">
            </div>
            <div class="col-12">
              <label class="form-label">ที่อยู่</label>
              <textarea name="address" class="form-control" rows="2" placeholder="ที่อยู่เต็ม เช่น บ้านเลขที่ ซอย ถนน แขวง เขต จังหวัด รหัสไปรษณีย์"></textarea>
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

  <!-- Edit (ฟอร์มจริง) -->
  <div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <form class="modal-content" id="formEdit" method="post" action="member_edit.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขสมาชิก</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label">รหัสสมาชิก</label>
              <input type="text" class="form-control" id="editId" name="member_code" readonly>
            </div>
            <div class="col-sm-6">
              <label class="form-label">เบอร์โทร</label>
              <input type="tel" class="form-control" id="editPhone" name="phone">
            </div>
            <div class="col-12">
              <label class="form-label">ชื่อ-สกุล</label>
              <input type="text" class="form-control" id="editName" name="full_name" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label">ระดับ</label>
              <select id="editTier" name="tier" class="form-select">
                <?php foreach($tiers as $t) echo '<option value="'.htmlspecialchars($t).'">'.htmlspecialchars($t).'</option>'; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label">จำนวนหุ้น</label>
              <input type="number" min="0" step="1" class="form-control" id="editShares" name="shares">
            </div>
            <div class="col-sm-4">
              <label class="form-label">คะแนน</label>
              <input type="number" min="0" step="10" class="form-control" id="editPoints" name="points">
            </div>
            <div class="col-sm-6">
              <label class="form-label">วันที่สมัคร</label>
              <input type="date" class="form-control" id="editJoined" name="joined_date">
            </div>
            <div class="col-sm-6">
              <label class="form-label">บ้านเลขที่ครัวเรือน</label>
              <input type="text" class="form-control" id="editHouseNumber" name="house_number">
            </div>
            <div class="col-12">
              <label class="form-label">ที่อยู่</label>
              <textarea class="form-control" id="editAddress" name="address" rows="2"></textarea>
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

  <!-- Delete confirm (ฟอร์มจริง) -->
  <div class="modal fade" id="modalDel" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="formDelete" method="post" action="member_delete.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="user_id" id="deleteUserId">
        <div class="modal-header">
          <h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>ลบสมาชิก</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
        </div>
        <div class="modal-body">
          ต้องการลบสมาชิก <b id="delName"></b> (<span id="delId"></span>) ใช่หรือไม่?
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

  <!-- Toast -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
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

    // แสดง toast จาก server-side query string
    const urlParams = new URLSearchParams(window.location.search);
    const okMsg = urlParams.get('ok');
    const errMsg = urlParams.get('err');
    if (okMsg) {
        toast(okMsg, true);
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    if (errMsg) {
        toast(errMsg, false);
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // กรองตาราง
    const memSearch  = $('#memSearch');
    const filterTier = $('#filterTier');
    const minPoint   = $('#minPoint');

    function normalize(s){ return (s||'').toString().toLowerCase().trim(); }

    function applyFilter(){
      const k = normalize(memSearch.value);
      const tier = filterTier.value;
      const minP = parseInt(minPoint.value || '0', 10);

      $$('#memTable tbody tr').forEach(tr=>{
        const searchText = normalize(`${tr.dataset.id} ${tr.dataset.name} ${tr.dataset.phone} ${tr.dataset.shares} ${tr.dataset.houseNumber} ${tr.dataset.address}`);
        const okK = !k || searchText.includes(k);
        const okT = !tier || tr.dataset.tier === tier;
        const okP = isNaN(minP) ? true : (parseInt(tr.dataset.points||'0',10) >= minP);
        tr.style.display = (okK && okT && okP) ? '' : 'none';
      });
    }
    memSearch.addEventListener('input', applyFilter);
    filterTier.addEventListener('change', applyFilter);
    minPoint.addEventListener('input', applyFilter);

    // พิมพ์ & ส่งออก
    $('#btnPrint').addEventListener('click', ()=> window.print());
    $('#btnExport').addEventListener('click', ()=>{
      const rows = [['MemberID','Name','Phone','Tier','Points','Shares','HouseNumber','Address','Joined']];
      $$('#memTable tbody tr').forEach(tr=>{
        if(tr.style.display==='none') return;
        rows.push([
          tr.dataset.id, tr.dataset.name, tr.dataset.phone, tr.dataset.tier,
          tr.dataset.points, tr.dataset.shares, tr.dataset.houseNumber, 
          tr.dataset.address, tr.dataset.joined
        ]);
      });
      const csv = rows.map(r=>r.map(v=>`"${(v||'').replaceAll('"','""')}"`).join(',')).join('\n');
      const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'members.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    });

    // เปิดโมดัลแก้ไข (ส่งฟอร์มเข้าฝั่ง server จริง)
    function openEdit(tr){
      $('#editUserId').value     = tr.dataset.userId;
      $('#editId').value         = tr.dataset.id;       // member_code (readonly)
      $('#editName').value       = tr.dataset.name;
      $('#editPhone').value      = tr.dataset.phone;
      $('#editTier').value       = tr.dataset.tier || '';
      $('#editPoints').value     = tr.dataset.points || 0;
      $('#editJoined').value     = tr.dataset.joined || '';
      $('#editShares').value     = tr.dataset.shares || '';
      $('#editHouseNumber').value= tr.dataset.houseNumber || '';
      $('#editAddress').value    = tr.dataset.address || '';
      new bootstrap.Modal('#modalEdit').show();
    }

    // เปิดโมดัลลบ (ส่งฟอร์มเข้าฝั่ง server จริง)
    function openDelete(tr){
      $('#deleteUserId').value = tr.dataset.userId;
      $('#delId').textContent  = tr.dataset.id;
      $('#delName').textContent= tr.dataset.name;
      new bootstrap.Modal('#modalDel').show();
    }

    function attachRowHandlers(tr){
      tr.querySelector('.btnEdit')?.addEventListener('click', ()=> openEdit(tr));
      tr.querySelector('.btnDel')?.addEventListener('click', ()=> openDelete(tr));
    }
    $$('#memTable tbody tr').forEach(attachRowHandlers);
  </script>
</body>
</html>
