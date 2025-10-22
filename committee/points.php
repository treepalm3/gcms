<?php
// committee/points.php — คะแนนสะสมและของรางวัล (เชื่อม DB จริง + เลือกสมาชิก + แลกจริง)
session_start();
date_default_timezone_set('Asia/Bangkok');

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ===== AuthZ ===== */
try {
  if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit();
  }
  $current_name = $_SESSION['full_name'] ?? 'กรรมการ';
  $current_role = $_SESSION['role'] ?? 'guest';
  if ($current_role !== 'committee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
} catch (Throwable $e) {
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}

/* ===== DB connect ===== */
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ไม่พบตัวแปร $pdo'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ===== Helpers: ตรวจตาราง/คอลัมน์ และเลือกคอลัมน์ที่มีจริง ===== */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $like = str_replace(['\\','_','%'], ['\\\\','\\_','\\%'], $table);
    $quoted = $pdo->quote($like);
    $stmt = $pdo->query("SHOW FULL TABLES LIKE $quoted");
    return (bool)($stmt ? $stmt->fetchColumn() : false);
  } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $tableEsc = str_replace('`','``',$table);
    $like = str_replace(['\\','_','%'], ['\\\\','\\_','\\%'], $col);
    $quoted = $pdo->quote($like);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE $quoted");
    return (bool)($stmt ? $stmt->fetchColumn() : false);
  } catch (Throwable $e) { return false; }
}
function first_col(PDO $pdo, string $table, array $cands): ?string {
  foreach ($cands as $c) { if (column_exists($pdo,$table,$c)) return $c; }
  return null;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== Site settings (เหมือนฝั่ง admin) ===== */
$site_name = 'สหกรณ์ปั๊มน้ำบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
try {
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $sys = json_decode($r['json_value'] ?? '', true) ?: [];
    if (!empty($sys['site_name']))     $site_name = $sys['site_name'];
    if (!empty($sys['site_subtitle'])) $site_subtitle = $sys['site_subtitle'];
  } else {
    $s2 = $pdo->query("SELECT site_name FROM settings WHERE id=1");
    if ($r2 = $s2->fetch()) $site_name = $r2['site_name'] ?: $site_name;
  }
} catch (Throwable $e) {}

$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name ?? 'ก', 0, 1, 'UTF-8');

/* ===== เลือกสมาชิก (สำหรับดู/แลก) ===== */
$members_for_select = [];
$selected_member_id = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
try {
  $members_for_select = $pdo->query("
    SELECT m.id AS member_id, m.member_code, u.full_name, m.points, m.tier
    FROM members m
    JOIN users u ON m.user_id = u.id
    WHERE u.role = 'member'
    ORDER BY u.full_name ASC
  ")->fetchAll(PDO::FETCH_ASSOC);
  if ($selected_member_id <= 0 && !empty($members_for_select)) {
    $selected_member_id = (int)$members_for_select[0]['member_id'];
  }
} catch (Throwable $e) {}

/* ===== ข้อมูลสมาชิกที่เลือก ===== */
$member = null;
try {
  $st = $pdo->prepare("
    SELECT m.id AS member_id, m.member_code, u.full_name, m.tier, m.points
    FROM members m
    JOIN users u ON m.user_id = u.id
    WHERE m.id = ?
    LIMIT 1
  ");
  $st->execute([$selected_member_id]);
  $member = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$member) {
  // fallback ว่างเพื่อให้หน้า render ได้
  $member = ['member_id'=>0,'member_code'=>'-','full_name'=>'(ไม่พบสมาชิก)','tier'=>'-','points'=>0];
}

/* ===== Rewards: auto-detect ตาราง/คอลัมน์ ===== */
$rewards = [];
try {
  $rewardTable = null;
  foreach (['rewards','reward_catalog','reward_items'] as $t) {
    if (table_exists($pdo,$t)) { $rewardTable = $t; break; }
  }
  if ($rewardTable) {
    $idCol     = first_col($pdo,$rewardTable,['id','reward_id']);
    $nameCol   = first_col($pdo,$rewardTable,['title','name','reward_name']);
    $needCol   = first_col($pdo,$rewardTable,['points_required','points_need','need_points','required_points','points']);
    $descCol   = first_col($pdo,$rewardTable,['description','desc','detail','notes']);
    $activeCol = first_col($pdo,$rewardTable,['is_active','active','enabled','status']);
    $whereAct  = $activeCol ? "WHERE `$activeCol` IN ('1',1,'active','enabled','yes','Y',true)" : "";

    $sql = "SELECT `$idCol` AS rid, `$nameCol` AS rname, `$needCol` AS rneed, ".($descCol ? "`$descCol`" : "NULL")." AS rdesc
            FROM `$rewardTable` $whereAct ORDER BY rneed ASC, rname ASC";
    $rewards = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }

  // ถ้าไม่มีตารางรางวัล → ใช้ตัวอย่างสั้น ๆ
  if (empty($rewards)) {
    $rewards = [
      ['rid'=>'RW01','rname'=>'ส่วนลด 50 บาท','rneed'=>3000,'rdesc'=>'ส่วนลดเติมน้ำมัน 50 บาท (ทุกชนิด)'],
      ['rid'=>'RW02','rname'=>'ล้างรถฟรี 1 ครั้ง','rneed'=>5000,'rdesc'=>'ล้างรถมาตรฐานที่สถานี'],
      ['rid'=>'RW03','rname'=>'คูปองน้ำดื่ม 6 ขวด','rneed'=>1500,'rdesc'=>'แลกน้ำดื่มแพ็คเล็ก 6 ขวด'],
      ['rid'=>'RW04','rname'=>'ส่วนลด 100 บาท','rneed'=>5500,'rdesc'=>'ส่วนลดเติมน้ำมัน 100 บาท (ทุกชนิด)'],
    ];
  }
} catch (Throwable $e) {}

/* ===== Redemption history ของสมาชิก ===== */
$redemption_history = [];
try {
  $redeemTable = null;
  foreach (['reward_redemptions','point_redemptions','rewards_redeem','redemptions'] as $t) {
    if (table_exists($pdo,$t)) { $redeemTable = $t; break; }
  }

  if ($redeemTable) {
    // เดาชื่อคอลัมน์
    $ridCol     = first_col($pdo,$redeemTable,['id','redeem_id']);
    $memberCol  = first_col($pdo,$redeemTable,['member_id','user_id','member_code']);
    $rewardIdCol= first_col($pdo,$redeemTable,['reward_id','reward','item_id']);
    $pointsCol  = first_col($pdo,$redeemTable,['points_spent','points_used','points','used_points']);
    $statusCol  = first_col($pdo,$redeemTable,['status','state']);
    $codeCol    = first_col($pdo,$redeemTable,['code','redeem_code','reference','ref']);
    $dateCol    = first_col($pdo,$redeemTable,['created_at','redeemed_at','created','date']);

    // join กับตาราง rewards ถ้ามี
    $join = '';
    $nameExpr = ':fallback';
    if (!empty($rewardTable) && $rewardIdCol) {
      $rId = first_col($pdo,$rewardTable,['id','reward_id']);
      $rName = first_col($pdo,$rewardTable,['title','name','reward_name']);
      if ($rId && $rName) {
        $join = "LEFT JOIN `$rewardTable` rw ON rw.`$rId` = r.`$rewardIdCol`";
        $nameExpr = "rw.`$rName`";
      }
    }

    // where member
    $where = '1=1'; $ref = null;
    if ($memberCol==='member_id') { $where = "r.`$memberCol`=:ref"; $ref = $member['member_id']; }
    elseif ($memberCol==='user_id') { // ดึง user_id ของสมาชิก
      $uid = $pdo->prepare("SELECT u.id FROM members m JOIN users u ON m.user_id=u.id WHERE m.id=?");
      $uid->execute([$member['member_id']]);
      $ref = (int)$uid->fetchColumn();
      $where = "r.`$memberCol`=:ref";
    } elseif ($memberCol==='member_code') { $where = "r.`$memberCol`=:ref"; $ref = $member['member_code']; }

    $sql = "SELECT ".($dateCol ? "r.`$dateCol`" : "NOW()")." AS rdate,
                   ".($pointsCol ? "r.`$pointsCol`" : "0")." AS pts,
                   ".($statusCol ? "r.`$statusCol`" : "'used'")." AS rstatus,
                   ".($codeCol ? "r.`$codeCol`" : "NULL")." AS rcode,
                   $nameExpr AS rtitle
            FROM `$redeemTable` r
            $join
            WHERE $where
            ORDER BY rdate DESC
            LIMIT 30";
    $st = $pdo->prepare($sql);
    $st->execute([':ref'=>$ref, ':fallback'=>'(รายการรางวัล)']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $h) {
      $redemption_history[] = [
        'date'  => $h['rdate'] ? date('Y-m-d', strtotime($h['rdate'])) : '',
        'title' => $h['rtitle'] ?? '(รายการรางวัล)',
        'points'=> (int)$h['pts'],
        'status'=> ($h['rstatus'] === 'expired' ? 'หมดอายุ' : 'ใช้งานแล้ว'),
        'code'  => $h['rcode'] ?? '-',
      ];
    }
  } else {
    // ถ้าไม่มีตารางใน DB จะไม่แสดง error แต่ปล่อยว่าง
    $redemption_history = [];
  }
} catch (Throwable $e) {
  // ปล่อยเงียบไว้ เพื่อให้หน้า render ได้
}

/* ===== UI helpers ===== */
$okMsg  = $_GET['ok']  ?? null;
$errMsg = $_GET['err'] ?? null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>คะแนนสะสม | <?= h($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    .panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
    .panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
    .ok-badge{background:rgba(32,163,158,.12)!important;color:var(--teal)!important;border:1px solid var(--border)}
    .reward-card {
      background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
      padding: 1rem; display: flex; flex-direction: column; transition: all .2s ease;
    }
    .reward-card:hover { transform: translateY(-4px); box-shadow: var(--shadow); }
    .reward-card h5 { font-weight: 700; }
    .reward-card .small-muted { flex-grow: 1; margin-bottom: .75rem; }
    .status-used { background: rgba(104,114,122,.12)!important; color: var(--steel)!important; border:1px solid var(--border); }
    .status-expired { background: rgba(182,109,13,.12)!important; color: var(--amber)!important; border:1px solid var(--border); }
  </style>
</head>
<body>

  <!-- App Bar -->
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

  <!-- Offcanvas Sidebar -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="offcanvasLabel"><?= h($site_name) ?></h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>
    </div>
    <div class="offcanvas-body sidebar">
      <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu">
        <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
        <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="points.php" class="active"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar Desktop -->
      <aside class="col-md-3 col-lg-2 d-none d-md-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
          <a href="report.php"><i class="fa-solid fa-chart-line"></i>รายงาน</a>
          <a href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="points.php" class="active"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
          <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>

      <!-- Content -->
      <main class="col-lg-10 p-4">
        <div class="main-header d-flex flex-wrap align-items-center justify-content-between gap-2">
          <h2 class="mb-0"><i class="fa-solid fa-star me-2"></i>คะแนนสะสมและของรางวัล</h2>

          <!-- เลือกสมาชิก -->
          <form method="get" class="d-flex align-items-center gap-2">
            <label class="text-nowrap small text-muted">สมาชิก:</label>
            <select name="member_id" class="form-select form-select-sm" onchange="this.form.submit()" required>
              <?php foreach($members_for_select as $m): ?>
                <option value="<?= (int)$m['member_id'] ?>" <?= ((int)$m['member_id']===(int)$selected_member_id?'selected':'') ?>>
                  <?= h($m['full_name']) ?> (<?= h($m['member_code']) ?>) — <?= number_format((int)$m['points']) ?> แต้ม
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>

        <?php if ($okMsg || $errMsg): ?>
          <div class="alert <?= $errMsg ? 'alert-danger' : 'alert-success' ?> mt-3">
            <?= h($errMsg ?: $okMsg) ?>
          </div>
        <?php endif; ?>

        <!-- Current Points -->
        <div class="stat-card mb-4">
          <h5>คะแนนสะสมปัจจุบัน: <span class="text-primary"><?= h($member['full_name']) ?></span> (<?= h($member['member_code']) ?>)</h5>
          <h3 class="text-primary"><?= number_format((int)$member['points']) ?> <span style="font-size: 1.2rem; font-weight: 500;">แต้ม</span></h3>
          <p class="mb-0 text-muted">ระดับสมาชิก: <?= h($member['tier']) ?></p>
        </div>

        <!-- Rewards -->
        <div class="panel mb-4">
          <div class="panel-head">
            <h5 class="mb-0"><i class="fa-solid fa-gift me-2"></i>ของรางวัลที่แลกได้</h5>
          </div>
          <div class="row g-3">
            <?php if (empty($rewards)): ?>
              <div class="col-12"><div class="alert alert-warning mb-0">ยังไม่มีรายการของรางวัลในระบบ</div></div>
            <?php else: foreach($rewards as $rw):
              $need = (int)$rw['rneed']; $enough = ((int)$member['points'] >= $need);
            ?>
            <div class="col-12 col-md-6 col-xl-4">
              <div class="reward-card h-100">
                <h5 class="mb-1"><?= h($rw['rname']) ?></h5>
                <div class="small-muted"><?= h($rw['rdesc'] ?? '') ?></div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="badge <?= $enough ? 'ok-badge' : 'bg-warning-subtle text-warning-emphasis' ?>">
                    ต้องใช้ <?= number_format($need) ?> แต้ม
                  </span>
                  <button class="btn btn-sm <?= $enough ? 'btn-primary' : 'btn-outline-secondary' ?>"
                          <?= $enough ? '' : 'disabled' ?>
                          data-bs-toggle="modal" data-bs-target="#redeemModal"
                          data-reward-id="<?= h($rw['rid']) ?>"
                          data-reward-title="<?= h($rw['rname']) ?>"
                          data-need="<?= (int)$need ?>">
                    <i class="fa-solid fa-ticket me-1"></i> แลก
                  </button>
                </div>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <!-- Redemption History -->
        <div class="panel">
          <div class="panel-head">
            <h5 class="mb-0"><i class="fa-solid fa-history me-2"></i>ประวัติการแลกคะแนน</h5>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead><tr>
                <th>วันที่</th><th>รายการ</th><th class="text-end">ใช้แต้ม</th><th>สถานะ</th><th>รหัสอ้างอิง</th>
              </tr></thead>
              <tbody>
                <?php if(empty($redemption_history)): ?>
                  <tr><td colspan="5" class="text-center text-muted">ยังไม่มีประวัติการแลก</td></tr>
                <?php else: foreach($redemption_history as $h): ?>
                  <tr>
                    <td><?= h($h['date']) ?></td>
                    <td><?= h($h['title']) ?></td>
                    <td class="text-end text-danger">-<?= number_format((int)$h['points']) ?></td>
                    <td>
                      <?php $cls = ($h['status']==='ใช้งานแล้ว')?'status-used':'status-expired'; ?>
                      <span class="badge <?= $cls ?>"><?= h($h['status']) ?></span>
                    </td>
                    <td><?= h($h['code']) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= h($site_name) ?> — ศูนย์สมาชิก</footer>

  <!-- Redeem Modal -->
  <div class="modal fade" id="redeemModal" tabindex="-1" aria-labelledby="redeemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="post" action="points_redeem.php">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="member_id" value="<?= (int)$member['member_id'] ?>">
        <input type="hidden" name="reward_id" id="mRewardId" value="">
        <input type="hidden" name="points_need" id="mPointsNeedRaw" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="redeemModalLabel"><i class="fa-solid fa-ticket me-2"></i>ยืนยันการแลกของรางวัล</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>คุณต้องการแลกของรางวัล:</p>
          <h5 class="text-primary" id="mRewardTitle"></h5>
          <hr>
          <div class="d-flex justify-content-between"><span>คะแนนปัจจุบัน:</span> <span id="mCurrentPoints"><?= number_format((int)$member['points']) ?> แต้ม</span></div>
          <div class="d-flex justify-content-between text-danger"><span>ใช้คะแนน:</span> <span id="mPointsNeed"></span></div>
          <div class="d-flex justify-content-between fw-bold"><span>คะแนนคงเหลือ:</span> <span id="mPointsAfter"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn btn-primary">ยืนยันการแลก</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const redeemModal = document.getElementById('redeemModal');
      if (!redeemModal) return;

      redeemModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const rid   = button.getAttribute('data-reward-id');
        const title = button.getAttribute('data-reward-title');
        const need  = parseInt(button.getAttribute('data-need') || '0', 10);
        const current = <?= (int)$member['points'] ?>;
        const after = current - need;

        redeemModal.querySelector('#mRewardId').value    = rid;
        redeemModal.querySelector('#mPointsNeedRaw').value = isNaN(need) ? 0 : need;

        redeemModal.querySelector('#mRewardTitle').textContent = title;
        redeemModal.querySelector('#mPointsNeed').textContent  = '-' + need.toLocaleString('th-TH') + ' แต้ม';
        redeemModal.querySelector('#mPointsAfter').textContent = after.toLocaleString('th-TH') + ' แต้ม';
      });
    });
  </script>
</body>
</html>
