<?php
// admin/manager.php - Single Manager Management (1 manager only)
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ---------- Auth ---------- */
if (!isset($_SESSION['user_id'])) {
  header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน');
  exit();
}

/* ---------- DB ---------- */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo');
}

/* ---------- Role ---------- */
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

/* ---------- Helpers ---------- */
function table_exists(PDO $pdo, string $table): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
  $st->execute([':db'=>$db, ':tb'=>$table]);
  return (int)$st->fetchColumn() > 0;
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:c');
  $st->execute([':db'=>$db, ':tb'=>$table, ':c'=>$col]);
  return (int)$st->fetchColumn() > 0;
}
function get_setting(PDO $pdo, string $name, $default=null){
  try{
    if (!table_exists($pdo,'settings')) return $default;
    if (column_exists($pdo,'settings','setting_name') && column_exists($pdo,'settings','setting_value')){
      $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_name=:n LIMIT 1');
      $st->execute([':n'=>$name]);
      $v = $st->fetchColumn();
      return $v!==false ? $v : $default;
    }
  }catch(Throwable $e){}
  return $default;
}

/* ---------- Defaults ---------- */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$station_id = 1;
$manager = null;           // รายเดียว
$alerts  = [];
$recent_activities = [];

try {
  /* Settings */
  if (table_exists($pdo,'settings')) {
    $st = $pdo->prepare("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
    $st->execute();
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $station_id = (int)($row['setting_value'] ?? 1);
      if (!empty($row['comment'])) { $site_name = $row['comment']; }
    }
  }

  /* Managers: ดึงเพียง 1 รายล่าสุด */
  if (!table_exists($pdo,'managers') || !table_exists($pdo,'users')) {
    $alerts[] = ['type'=>'info','icon'=>'fa-info-circle','message'=>'ยังไม่มีตาราง managers หรือ users ในฐานข้อมูล'];
  } else {
    $has_u_is_active     = column_exists($pdo,'users','is_active');
    $has_u_last_login_at = column_exists($pdo,'users','last_login_at');

    $mgr_optional_cols = [
      'hire_date'          => null,
      'salary'             => null,
      'commission_rate'    => null,
      'performance_score'  => 0,
      'access_level'       => 'readonly',
      'total_managed_sales'=> 0,
      'team_size'          => 0,
      'shares'             => null,
      'house_number'       => null,
      'address'            => null,
      'updated_at'         => null,
    ];
    $mgr_has = [];
    foreach ($mgr_optional_cols as $c => $def) {
      $mgr_has[$c] = column_exists($pdo,'managers',$c);
    }

    $select = [];
    $select[] = "m.id AS manager_id";
    $select[] = "m.user_id";
    $select[] = "u.full_name";
    $select[] = "u.email";
    $select[] = "u.phone";
    $select[] = $has_u_is_active ? "CASE WHEN u.is_active=1 THEN 'active' ELSE 'inactive' END AS user_status" : "'active' AS user_status";
    $select[] = $has_u_last_login_at ? "u.last_login_at AS last_login" : "NULL AS last_login";

    $select[] = "m.salary";
    $select[] = "m.performance_score";
    $select[] = "m.access_level";
    $select[] = "m.shares";
    $select[] = "m.house_number";
    $select[] = "m.address";
    foreach ($mgr_optional_cols as $c => $def) {
      if ($mgr_has[$c]) {
        $select[] = "m.$c";
      } else {
        $alias = $c;
        if (is_null($def))         $select[] = "NULL AS $alias";
        elseif (is_numeric($def))  $select[] = (0 + $def) . " AS $alias";
        else                       $select[] = $pdo->quote($def) . " AS $alias";
      }
    }
    $select[] = column_exists($pdo,'managers','created_at') ? "m.created_at" : "NOW() AS created_at";

    $sql = "
    SELECT ".implode(",\n         ", $select)."
    FROM managers m
    LEFT JOIN users u ON m.user_id = u.id
    ORDER BY m.created_at DESC, m.id DESC
    LIMIT 1
  ";
    $stmt = $pdo->query($sql);
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($row) {
      $row['full_name'] = $row['full_name'] ?? 'ไม่ระบุชื่อ';
      $row['email']     = $row['email']     ?? '-';
      if (empty($row['access_level'])) $row['access_level'] = 'readonly';
      $row['performance_score']   = (float)($row['performance_score'] ?? 0);
      $row['total_managed_sales'] = (float)($row['total_managed_sales'] ?? 0);
      $row['team_size']           = (int)  ($row['team_size'] ?? 0);
      $manager = $row;
    }
  }

  /* Stats (จากรายเดียว) */
  $total_managers  = $manager ? 1 : 0;
  $active_managers = ($manager && ($manager['user_status'] ?? '') === 'active') ? 1 : 0;
  $avg_performance = $manager ? (float)$manager['performance_score'] : 0.0;
  $total_team_size = $manager ? (int)$manager['team_size'] : 0;

  /* Recent activities: เฉพาะผู้บริหารรายนี้ */
  if ($manager && table_exists($pdo,'activity_logs')) {
    $activity_stmt = $pdo->prepare("
      SELECT al.log_date AS date, :name AS manager, al.action
      FROM activity_logs al
      WHERE al.user_id = :uid
      ORDER BY al.log_date DESC
      LIMIT 5
    ");
    $activity_stmt->execute([
      ':uid'  => (int)$manager['user_id'],
      ':name' => (string)$manager['full_name'],
    ]);    
    $recent_activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  /* Alerts */
  if ($manager) {
    if (($manager['performance_score'] ?? 0) < 60) {
      $alerts[] = [
        'type' => 'warning',
        'icon' => 'fa-exclamation-triangle',
        'message' => "ผู้จัดการ {$manager['full_name']} มีคะแนนประเมินต่ำ (".number_format((float)$manager['performance_score'],1)."%)"
      ];
    }
    if (!empty($manager['last_login'])) {
      $ts = strtotime($manager['last_login']);
      if ($ts && $ts < strtotime('-7 days')) {
        $alerts[] = [
          'type' => 'info',
          'icon' => 'fa-clock',
          'message' => "ผู้จัดการ {$manager['full_name']} ไม่ได้เข้าสู่ระบบมานานกว่า 7 วัน"
        ];
      }
    }
  } else {
    $alerts[] = ['type'=>'info','icon'=>'fa-info-circle','message'=>'ยังไม่มีข้อมูลผู้บริหาร กรุณากำหนดผู้บริหาร'];
  }

} catch (Throwable $e) {
  error_log("Manager page error: " . $e->getMessage());
  $manager = null;
  $total_managers = 0; $active_managers = 0; $avg_performance = 0.0; $total_team_size = 0;
  $recent_activities = [];
  $alerts[] = ['type' => 'danger', 'icon' => 'fa-server', 'message' => 'ไม่สามารถโหลดข้อมูลผู้บริหารได้: ' . $e->getMessage()];
}

/* Flash (?ok / ?err) */
if (isset($_GET['ok']) && $_GET['ok']!=='') {
  $alerts[] = ['type'=>'success','icon'=>'fa-check-circle','message'=>$_GET['ok']];
}
if (isset($_GET['err']) && $_GET['err']!=='') {
  $alerts[] = ['type'=>'danger','icon'=>'fa-triangle-exclamation','message'=>$_GET['err']];
}

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
<title>จัดการผู้บริหาร | <?= htmlspecialchars($site_name) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
<link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
<style>
  .manager-card { border: 1px solid #e9ecef; border-radius: 12px; transition: all .3s ease; background: #fff; overflow: hidden; }
  .manager-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,.1); transform: translateY(-2px); border-color: #856404; }
  .performance-badge { padding: 4px 8px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
  .performance-excellent { background: #d1edff; color: #0d6efd; }
  .performance-good { background: #d4edda; color: #155724; }
  .performance-average { background: #fff3cd; color: #856404; }
  .performance-poor { background: #f8d7da; color: #721c24; }
  .access-level { padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 500; }
  .access-full { background: #e7f3ff; color: #0066cc; }
  .access-limited { background: #fff4e6; color: #cc6600; }
  .access-readonly { background: #f0f0f0; color: #666; }
  .alert-banner { border-radius: 8px; border: none; padding: 12px 16px; margin-bottom: 8px; }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"><span class="navbar-toggler-icon"></span></button>
      <a class="navbar-brand fw-800" href="admin_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
    <nav class="sidebar-menu">
      <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="manager.php" class="active"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
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
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Admin</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php" class="active"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <!-- Main -->
    <main class="col-lg-10 p-4">
      <div class="main-header d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-shield-lock-fill"></i> จัดการผู้บริหาร</h2>
        <div class="d-flex gap-2">
          <?php if ($manager): ?>
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#managerModal" data-mode="edit">
              <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไขข้อมูล
            </button>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#managerModal" data-mode="replace">
              <i class="fa-solid fa-user-switch me-1"></i> เปลี่ยนผู้บริหาร
            </button>
          <?php else: ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#managerModal" data-mode="create">
              <i class="fa-solid fa-plus me-1"></i> กำหนดผู้บริหาร
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Alerts -->
      <div class="alerts-section mb-4" <?= empty($alerts) ? 'style="display:none"' : '' ?>>
        <?php foreach ($alerts as $alert): ?>
          <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> alert-banner">
            <i class="fa-solid <?= htmlspecialchars($alert['icon']) ?> me-2"></i><?= htmlspecialchars($alert['message']) ?>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Stats -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="stat-card">
            <h6><i class="fa-solid fa-user-tie me-2"></i>ผู้บริหาร</h6>
            <h3><?= $total_managers ? '1 คน' : '0 คน' ?></h3>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <h6><i class="fa-solid fa-user-check me-2"></i>สถานะใช้งาน</h6>
            <h3><?= $active_managers ? 'Active' : 'Inactive' ?></h3>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <h6><i class="fa-solid fa-chart-line me-2"></i>คะแนนประเมิน</h6>
            <h3><?= number_format($avg_performance, 1) ?>%</h3>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <h6><i class="fa-solid fa-people-group me-2"></i>ขนาดทีม</h6>
            <h3><?= number_format($total_team_size) ?> คน</h3>
          </div>
        </div>
      </div>

      <!-- Profile Panel (Single) -->
      <div class="panel">
        <div class="panel-head d-flex justify-content-between align-items-center mb-3">
          <h5><i class="fa-solid fa-id-card-clip me-2"></i>โปรไฟล์ผู้บริหาร</h5>
        </div>

        <?php if ($manager): 
          $performance = (float)($manager['performance_score'] ?? 0);
          $perf_class = $performance >= 90 ? 'excellent' : ($performance >= 75 ? 'good' : ($performance >= 60 ? 'average' : 'poor'));
          $access_level = $manager['access_level'] ?? 'readonly';
          $avatar_initial = mb_substr($manager['full_name'] ?? 'ม', 0, 1, 'UTF-8');
        ?>
        <div class="manager-card">
          <div class="card-body p-3">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 64px; height: 64px;background:linear-gradient(135deg,var(--gold),var(--amber));color:#1b1b1b;font-weight:800;">
                <?= htmlspecialchars($avatar_initial) ?>
              </div>
              <div class="flex-grow-1">
                <h5 class="mb-1"><?= htmlspecialchars($manager['full_name']) ?></h5>
                <div class="mt-1">
                  <span class="access-level access-<?= htmlspecialchars($access_level) ?>">
                    <?= ['full'=>'เต็มสิทธิ์','limited'=>'จำกัดสิทธิ์','readonly'=>'อ่านอย่างเดียว'][$access_level] ?? 'ไม่ระบุ' ?>
                  </span>
                </div>
              </div>
            </div>

            <div class="row text-center mb-3">
              <div class="col-6">
                <small class="text-muted">คะแนนประเมิน</small><br>
                <span class="performance-badge performance-<?= $perf_class ?>">
                  <?= number_format((float)($manager['performance_score'] ?? 0), 1) ?>%
                </span>
              </div>
              <div class="col-6">
                <small class="text-muted">เงินเดือน</small><br>
                <strong><?= isset($manager['salary']) ? '฿'.number_format((float)$manager['salary'],2) : '-' ?></strong>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <small class="text-muted d-block">อีเมล</small>
                <div><?= htmlspecialchars($manager['email'] ?? '-') ?></div>
              </div>
              <div class="col-md-6">
                <small class="text-muted d-block">โทรศัพท์</small>
                <div><?= htmlspecialchars($manager['phone'] ?? '-') ?></div>
              </div>
              <div class="col-md-4">
                <small class="text-muted d-block">วันที่เริ่มงาน</small>
                <div><?= !empty($manager['hire_date']) ? date('d/m/Y', strtotime($manager['hire_date'])) : 'ไม่ระบุ' ?></div>
              </div>
              <div class="col-md-4">
                <small class="text-muted d-block">เงินเดือน</small>
                <div><?= isset($manager['salary']) ? '฿'.number_format((float)$manager['salary'],2) : '-' ?></div>
              </div>
              <div class="col-md-4">
                <small class="text-muted d-block">อัตราคอมมิชชั่น</small>
                <div><?= isset($manager['commission_rate']) ? number_format((float)$manager['commission_rate'],2).' %' : '-' ?></div>
              </div>
              <?php if (!empty($manager['shares'])): ?>
              <div class="col-md-4">
                <small class="text-muted d-block">จำนวนหุ้น</small>
                <div><span class="badge bg-info text-dark"><?= number_format((int)$manager['shares']) ?> หุ้น</span></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($manager['house_number'])): ?>
              <div class="col-md-4">
                <small class="text-muted d-block">บ้านเลขที่</small>
                <div><?= htmlspecialchars($manager['house_number']) ?></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($manager['address'])): ?>
              <div class="col-12">
                <small class="text-muted d-block">ที่อยู่</small>
                <div><?= htmlspecialchars($manager['address']) ?></div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php else: ?>
          <div class="text-center text-muted py-5">
            <i class="fa-solid fa-user-lock fa-2x mb-3"></i>
            <div>ยังไม่กำหนดผู้บริหาร</div>
            <button class="btn btn-success mt-3" data-bs-toggle="modal" data-bs-target="#managerModal" data-mode="create">
              <i class="fa-solid fa-plus me-1"></i> กำหนดผู้บริหาร
            </button>
          </div>
        <?php endif; ?>
      </div>

      <!-- Activities -->
      <div class="panel mt-4">
        <div class="panel-head mb-3">
          <h5><i class="fa-solid fa-clock me-2"></i>กิจกรรมล่าสุดของผู้บริหาร</h5>
        </div>
        <?php if (!empty($recent_activities)): ?>
          <?php foreach ($recent_activities as $activity): ?>
            <div class="border-start border-primary ps-3 mb-3">
              <div class="d-flex justify-content-between">
                <div>
                  <strong><?= htmlspecialchars($activity['manager']) ?></strong>
                  <div class="small text-muted"><?= htmlspecialchars($activity['action']) ?></div>
                </div>
                <small class="text-muted"><?= date('d/m/Y', strtotime($activity['date'])) ?></small>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="text-muted">ยังไม่มีกิจกรรม</div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Modal: เพิ่ม/แก้ไข/เปลี่ยนผู้บริหาร (ฟอร์มเดียว) -->
<div class="modal fade" id="managerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fa-solid fa-user-gear me-2"></i>
          <span id="managerModalTitle"><?= $manager ? 'แก้ไขข้อมูลผู้บริหาร' : 'กำหนดผู้บริหาร' ?></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="manager_create.php" method="POST">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="current_manager_id" value="<?= $manager ? (int)$manager['manager_id'] : 0 ?>">
          <input type="hidden" name="mode" id="managerFormMode" value="<?= $manager ? 'edit' : 'create' ?>">

          <?php
            $val = fn($k,$d='') => htmlspecialchars($manager[$k] ?? $d);
            $sel = fn($a,$v)    => $a===$v ? 'selected' : '';
          ?>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">ชื่อ-นามสกุล</label>
              <input name="full_name" class="form-control" required value="<?= $val('full_name') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">อีเมล</label>
              <input type="email" name="email" class="form-control" required value="<?= $val('email') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">โทรศัพท์</label>
              <input name="phone" class="form-control" value="<?= $val('phone') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label">ระดับสิทธิ์การเข้าถึง</label>
              <?php $acc = $manager['access_level'] ?? 'readonly'; ?>
              <select name="access_level" class="form-select" required>
                <option value="readonly" <?= $sel($acc,'readonly') ?>>อ่านอย่างเดียว</option>
                <option value="limited"  <?= $sel($acc,'limited')  ?>>จำกัดสิทธิ์</option>
                <option value="full"     <?= $sel($acc,'full')     ?>>เต็มสิทธิ์</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">เงินเดือน</label>
              <input type="number" name="salary" class="form-control" step="0.01" min="0" value="<?= isset($manager['salary']) ? htmlspecialchars((string)$manager['salary']) : '' ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">คะแนนประเมิน (%)</label>
              <input type="number" name="performance_score" class="form-control" step="0.01" min="0" max="100" value="<?= isset($manager['performance_score']) ? htmlspecialchars((string)$manager['performance_score']) : '' ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label">จำนวนหุ้น</label>
              <input type="number" name="shares" class="form-control" min="0" value="<?= isset($manager['shares']) ? (int)$manager['shares'] : '' ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">บ้านเลขที่ครัวเรือน</label>
              <input type="text" name="house_number" class="form-control" value="<?= $val('house_number') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">ที่อยู่</label>
              <textarea name="address" class="form-control" rows="2"><?= $manager ? htmlspecialchars($manager['address'] ?? '') : '' ?></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <?php if ($manager): ?>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            <button type="submit" class="btn btn-warning">บันทึกการแก้ไข</button>
          <?php else: ?>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-success">บันทึก</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<footer class="footer">© <?= date('Y'); ?> <?= htmlspecialchars($site_name) ?></footer>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
  <div id="toastBox" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">บันทึกสำเร็จ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const toastEl = document.getElementById('toastBox');
  const toastMsg = document.getElementById('toastMsg');
  let bsToast = toastEl ? new bootstrap.Toast(toastEl, { delay: 2500 }) : null;

  function showToast(message, ok=true){
    if (!toastEl) return alert(message);
    toastEl.classList.toggle('text-bg-success', ok);
    toastEl.classList.toggle('text-bg-danger', !ok);
    toastMsg.textContent = message;
    bsToast.show();
  }

  // กำหนดหัวข้อ/โหมดโมดัลตามปุ่มที่กด
  const managerModal = document.getElementById('managerModal');
  managerModal?.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    const mode = btn?.getAttribute('data-mode') || 'create';
    const title = document.getElementById('managerModalTitle');
    const modeInput = document.getElementById('managerFormMode');

    if (mode === 'create') {
      title.textContent = 'กำหนดผู้บริหาร';
      modeInput.value = 'create';
    } else if (mode === 'replace') {
      title.textContent = 'เปลี่ยนผู้บริหาร';
      modeInput.value = 'replace';
    } else {
      title.textContent = 'แก้ไขข้อมูลผู้บริหาร';
      modeInput.value = 'edit';
    }
  });

  // สำหรับปุ่มต่าง ๆ ที่อาจเพิ่มในอนาคต
  window.notify = (msg, ok=true) => showToast(msg, ok);
});
</script>

</body>
</html>
