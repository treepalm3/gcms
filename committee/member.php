<?php
// committee/member.php
session_start();
date_default_timezone_set('Asia/Bangkok');

// ===== ตรวจสอบการล็อกอินและสิทธิ์ =====
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'committee') {
  header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
  exit();
}

// ===== เชื่อมต่อฐานข้อมูล =====
$dbFile = __DIR__ . '/../config/db.php';
require_once $dbFile; // expected $pdo (PDO)

// ===== โหลดค่าพื้นฐาน (ชื่อไซต์จาก app_settings.system_settings) =====
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $sys = json_decode($r['json_value'] ?? '', true) ?: [];
    $site_name = $sys['site_name'] ?? $site_name;
  }
} catch (Throwable $e) {}

$current_name = $_SESSION['full_name'] ?? 'กรรมการ';
$current_role = $_SESSION['role'] ?? 'committee';

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน', 'member'=>'สมาชิกสหกรณ์', 'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name ?: 'ก', 0, 1, 'UTF-8');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ===== ดึงข้อมูลสมาชิกจากฐานข้อมูลจริง =====
$members = [];
$tiers = [];
try {
  // ดึงรายการสมาชิกที่ยังใช้งาน (ตัดรายการที่ลบ/ปิด)
  $stmt = $pdo->query("
    SELECT
      m.member_code  AS id,
      u.full_name    AS name,
      u.phone,
      m.tier,
      m.points,
      m.joined_date  AS joined,
      m.shares,
      m.house_number,
      m.address
    FROM members m
    JOIN users u ON m.user_id = u.id
    WHERE u.role = 'member'
      AND m.is_active = 1
      AND m.deleted_at IS NULL
    ORDER BY m.joined_date DESC, m.id DESC
  ");
  $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // ดึงรายการระดับสมาชิกสำหรับตัวกรอง
  $tier_stmt = $pdo->query("
    SELECT DISTINCT tier
    FROM members
    WHERE tier IS NOT NULL AND tier <> ''
    ORDER BY FIELD(tier,'Diamond','Platinum','Gold','Silver','Bronze'), tier
  ");
  $tiers = $tier_stmt->fetchAll(PDO::FETCH_COLUMN);
  if (empty($tiers)) {
    $tiers = ['Diamond','Platinum','Gold','Silver','Bronze'];
  }
} catch (Throwable $e) {
  // หากผิดพลาด แสดง 1 แถวอธิบายข้อผิดพลาดแทน
  $members = [[
    'id'=>'M-ERR',
    'name'=>'เกิดข้อผิดพลาดในการโหลดข้อมูล',
    'phone'=>'-',
    'tier'=>'-',
    'points'=>0,
    'joined'=>null,
    'shares'=>0,
    'house_number'=>'',
    'address'=>$e->getMessage()
  ]];
  $tiers = ['Diamond','Platinum','Gold','Silver','Bronze'];
}

// คำนวณสถิติหัวหน้าเพจ
$total = count($members);
$thisMonth = date('Y-m');
$newThisMonth = 0;
$totalPoints = 0;
$totalShares = 0;
foreach ($members as $m) {
  $totalPoints += (int)($m['points'] ?? 0);
  $totalShares += (int)($m['shares'] ?? 0);
  if (!empty($m['joined']) && substr($m['joined'], 0, 7) === $thisMonth) {
    $newThisMonth++;
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ข้อมูลสมาชิก - <?= h($site_name) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .badge.bg-warning-subtle{background:rgba(255,193,7,.12)!important;color:#b08900!important;border:1px solid var(--border)}
    .tier-gold { color: #d4af37; font-weight: 600; }
    .tier-silver { color: #9ca3af; font-weight: 600; }
    .tier-bronze { color: #cd7f32; font-weight: 600; }
    .tier-platinum { color:#60a5fa; font-weight: 700; }
    .tier-diamond  { color:#22d3ee; font-weight: 700; text-shadow: 0 0 6px rgba(34,211,238,.2); }
    .text-truncate-200 { max-width: 200px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
      <a class="navbar-brand" href="committee_dashboard.php"><?= h($site_name) ?></a>
    </div>
    <div class="d-flex align-items-center gap-3 ms-auto">
      <div class="nav-identity text-end d-none d-sm-block">
        <div class="nav-name"><?= h($current_name) ?></div>
        <div class="nav-sub"><?= h($current_role_th) ?></div>
      </div>
      <a href="profile.php" class="avatar-circle text-decoration-none"><?= h($avatar_text) ?></a>
    </div>
  </div>
</nav>

<!-- Offcanvas Sidebar (Mobile) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="offcanvasLabel"><?= h($site_name) ?></h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
    <nav class="sidebar-menu">
      <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="member.php" class="active"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
    </nav>
    <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar Desktop -->
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="member.php" class="active"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <!-- Main -->
    <main class="col-lg-10 p-4">
      <div class="main-header">
        <h2><i class="bi bi-people-fill"></i> ข้อมูลสมาชิก</h2>
      </div>

      <!-- แผงสถิติ -->
      <div class="stats-grid">
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

      <!-- แถบค้นหา/ตัวกรอง/ส่งออก -->
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center my-4">
        <div class="d-flex flex-wrap gap-2">
          <div class="input-group" style="max-width:320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" id="memSearch" class="form-control" placeholder="ค้นหา: รหัส/ชื่อ/เบอร์/ที่อยู่">
          </div>
          <select id="filterTier" class="form-select" style="width: auto;">
            <option value="">ทุกระดับ</option>
            <?php foreach($tiers as $t): ?>
              <option value="<?= h($t) ?>"><?= h($t) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="input-group" style="max-width:220px;">
            <span class="input-group-text"><i class="bi bi-123"></i></span>
            <input type="number" id="minPoint" class="form-control" placeholder="คะแนนขั้นต่ำ" min="0" step="100">
          </div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-primary" id="btnExport"><i class="bi bi-filetype-csv me-1"></i> ส่งออก CSV</button>
        </div>
      </div>

      <!-- ตารางสมาชิก -->
      <div class="stat-card">
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
                <th class="text-end">การทำรายการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($members as $m):
                $initial = mb_substr($m['name'] ?? '-', 0, 1, 'UTF-8');
                $tierLower = strtolower((string)($m['tier'] ?? ''));
                $tierClass = 'tier-' . $tierLower; // gold/silver/bronze/platinum/diamond
              ?>
              <tr
                data-id="<?= h($m['id']) ?>"
                data-name="<?= h($m['name']) ?>"
                data-phone="<?= h($m['phone']) ?>"
                data-tier="<?= h($m['tier']) ?>"
                data-points="<?= h($m['points']) ?>"
                data-joined="<?= h($m['joined']) ?>"
                data-shares="<?= h($m['shares'] ?? '') ?>"
                data-house-number="<?= h($m['house_number'] ?? '') ?>"
                data-address="<?= h($m['address'] ?? '') ?>"
              >
                <td><b><?= h($m['id']) ?></b></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center"
                         style="width:36px;height:36px;background:linear-gradient(135deg,var(--gold),var(--amber));color:#1b1b1b;font-weight:800;">
                      <?= h($initial) ?>
                    </div>
                    <div>
                      <div class="fw-semibold"><?= h($m['name']) ?></div>
                      <div class="text-muted small d-md-none"><?= h($m['phone'] ?: '-') ?></div>
                    </div>
                  </div>
                </td>
                <td class="d-none d-md-table-cell"><?= h($m['phone'] ?: '-') ?></td>
                <td><span class="<?= h($tierClass) ?>"><?= h($m['tier'] ?: '-') ?></span></td>
                <td class="text-end"><?= number_format((int)$m['points']) ?></td>
                <td class="d-none d-lg-table-cell text-center">
                  <?php if (!empty($m['shares'])): ?>
                    <span class="badge bg-warning-subtle"><?= number_format((int)$m['shares']) ?> หุ้น</span>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td class="d-none d-xl-table-cell"><?= h($m['house_number'] ?: '-') ?></td>
                <td class="d-none d-xxl-table-cell">
                  <span class="text-truncate-200" title="<?= h($m['address'] ?? '') ?>">
                    <?= h(($m['address'] ?? '') !== '' ? $m['address'] : '-') ?>
                  </span>
                </td>
                <td class="text-end d-none d-lg-table-cell">
                  <?= !empty($m['joined']) ? date('d/m/Y', strtotime($m['joined'])) : '-' ?>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary"
                     href="member_history.php?code=<?= urlencode($m['id']) ?>">
                    <i class="bi bi-clock-history me-1"></i> ประวัติ
                  </a>
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

<footer class="footer">© <?= date('Y') ?> <?= h($site_name) ?></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const $  = (s, p=document)=>p.querySelector(s);
  const $$ = (s, p=document)=>Array.from(p.querySelectorAll(s));

  // ===== กรองตาราง =====
  const memSearch  = $('#memSearch');
  const filterTier = $('#filterTier');
  const minPoint   = $('#minPoint');

  function normalize(s){ return (s||'').toString().toLowerCase().trim(); }

  function applyFilter(){
    const k    = normalize(memSearch.value);
    const tier = filterTier.value;
    const minP = parseInt(minPoint.value || '0', 10);

    $$('#memTable tbody tr').forEach(tr=>{
      const stext = normalize(
        `${tr.dataset.id} ${tr.dataset.name} ${tr.dataset.phone} ${tr.dataset.shares} ${tr.dataset.houseNumber} ${tr.dataset.address}`
      );
      const okK = !k || stext.includes(k);
      const okT = !tier || tr.dataset.tier === tier;
      const pts = parseInt(tr.dataset.points || '0', 10);
      const okP = isNaN(minP) ? true : (pts >= minP);
      tr.style.display = (okK && okT && okP) ? '' : 'none';
    });
  }
  memSearch.addEventListener('input', applyFilter);
  filterTier.addEventListener('change', applyFilter);
  minPoint.addEventListener('input', applyFilter);

  // ===== พิมพ์ & ส่งออก CSV =====
  $('#btnPrint').addEventListener('click', ()=> window.print());

  $('#btnExport').addEventListener('click', ()=>{
    const rows = [['MemberID','Name','Phone','Tier','Points','Shares','HouseNumber','Address','Joined']];
    $$('#memTable tbody tr').forEach(tr=>{
      if (tr.style.display === 'none') return;
      rows.push([
        tr.dataset.id,
        tr.dataset.name,
        tr.dataset.phone,
        tr.dataset.tier,
        tr.dataset.points,
        tr.dataset.shares,
        tr.dataset.houseNumber,
        tr.dataset.address,
        tr.dataset.joined || ''
      ]);
    });
    const csv = rows.map(r => r.map(v => `"${(v||'').replaceAll('"','""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'members.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });
</script>
</body>
</html>
