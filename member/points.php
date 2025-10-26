<?php
// member/points.php — คะแนนสะสมและของรางวัล (แก้ไข: รองรับกรรมการ/ผู้บริหารที่เป็นสมาชิก)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ====== CSRF ======
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ====== ตรวจสิทธิ์ ======
try {
  if (!isset($_SESSION['user_id'])) { header('Location: /index/login.php'); exit(); }
  $current_name = $_SESSION['full_name'] ?: 'สมาชิกสหกรณ์';
  $current_role = $_SESSION['role'] ?? 'guest';
  
  // [แก้ไข] อนุญาตทุกบทบาทที่ได้รับสถานะสมาชิก
  $allowed_roles = ['member', 'manager', 'committee', 'admin']; 
  if (!in_array($current_role, $allowed_roles)) {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit();
  }
} catch(Throwable $e){
  header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit();
}

// ====== เชื่อมต่อฐานข้อมูล ======
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // expect $pdo

$db_ok = true; $db_err = null;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('ไม่พบตัวแปร $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET NAMES utf8mb4");
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}
} catch (Throwable $e) { $db_ok = false; $db_err = $e->getMessage(); }

// ====== ค่าหน้าเว็บ / สถานี ======
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$site_subtitle = 'ระบบบริหารจัดการปั๊มน้ำมัน';
$station_id = 1;

if ($db_ok) {
  try {
    $rowSt = $pdo->query("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($rowSt) {
      $station_id = (int)$rowSt['setting_value'];
      if (!empty($rowSt['comment'])) $site_name = $rowSt['comment'];
    }
    $sys = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings'")->fetchColumn();
    if ($sys) {
      $sysj = json_decode($sys, true);
      if (!empty($sysj['site_name'])) $site_name = $sysj['site_name'];
      if (!empty($sysj['site_subtitle'])) $site_subtitle = $sysj['site_subtitle'];
    }
  } catch (Throwable $e) { /* ignore */ }
}

// ====== ผู้ใช้ปัจจุบัน ======
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role_th_map = [
  'admin'=>'ผู้ดูแลระบบ', 'manager'=>'ผู้บริหาร',
  'employee'=>'พนักงาน',    'member'=>'สมาชิกสหกรณ์',
  'committee'=>'กรรมการ'
];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ====== โหลดข้อมูลสมาชิกจาก DB ======
$member = null;   // ['id'=>members.id, 'name', 'tier', 'points', 'member_code', 'house_number', 'phone']
if ($db_ok) {
  try {
    // [แก้ไข] ลบ u.role='member' เพื่อให้ดึงข้อมูลได้ทุกบทบาทที่มี record ใน members
    $st = $pdo->prepare("
      SELECT m.id AS id, u.full_name AS name, m.tier, m.points,
             m.member_code, m.house_number, u.phone
      FROM users u
      JOIN members m ON m.user_id = u.id
      WHERE u.id = :uid AND u.is_active=1 AND m.station_id = :st
      LIMIT 1
    ");
    $st->execute([':uid'=>$current_user_id, ':st'=>$station_id]);
    $member = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($member && !empty($member['name'])) $current_name = $member['name'];
  } catch (Throwable $e) { $db_err = $e->getMessage(); }
}
if (!$member) {
  // ถ้าไม่พบสมาชิก ให้แสดงข้อความและหยุดหน้า
  $member = ['id'=>0,'name'=>$current_name,'tier'=>'Bronze','points'=>0,'member_code'=>'-','house_number'=>'-','phone'=>'-'];
}

// ====== โครงรางวัล: พยายามอ่านจาก app_settings.key='reward_catalog' ถ้าไม่มี ใช้ค่า fallback ======
$rewards = [];
if ($db_ok) {
  try {
    $raw = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='reward_catalog'")->fetchColumn();
    if ($raw) {
      $j = json_decode($raw, true);
      if (is_array($j)) {
        foreach ($j as $it) {
          if (!isset($it['id'],$it['title'],$it['need'])) continue;
          $rewards[] = [
            'id'    => (string)$it['id'],
            'title' => (string)$it['title'],
            'need'  => (int)$it['need'],
            'desc'  => (string)($it['desc'] ?? ''),
          ];
        }
      }
    }
  } catch (Throwable $e) { /* ignore and fallback */ }
}
if (empty($rewards)) {
  // fallback เดิม
  $rewards = [
    ['id'=>'RW01', 'title'=>'ส่วนลด 50 บาท','need'=>3000,'desc'=>'แลกส่วนลดเติมน้ำมัน 50 บาท (ใช้ได้กับทุกชนิด)'],
    ['id'=>'RW02', 'title'=>'ล้างรถฟรี 1 ครั้ง','need'=>5000,'desc'=>'ใช้บริการล้างรถมาตรฐานที่สถานีบริการ'],
    ['id'=>'RW03', 'title'=>'คูปองน้ำดื่ม 6 ขวด','need'=>1500,'desc'=>'รับคูปองแลกน้ำดื่มแพ็คเล็ก 6 ขวด'],
    ['id'=>'RW04', 'title'=>'ส่วนลด 100 บาท','need'=>5500,'desc'=>'แลกส่วนลดเติมน้ำมัน 100 บาท (ใช้ได้กับทุกชนิด)'],
  ];
}

// ====== จัดการแลกของรางวัล (POST) ======
$flash_ok = null; $flash_err = null;
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'redeem_reward') {
  // CSRF
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $flash_err = 'Invalid CSRF token.';
  } else {
    $reward_title = trim($_POST['reward_title'] ?? '');
    $points_need  = (int)($_POST['points_need'] ?? 0);
    $member_id    = (int)($member['id'] ?? 0);

    if ($member_id <= 0) {
      $flash_err = 'ไม่พบข้อมูลสมาชิก';
    } elseif ($points_need <= 0) {
      $flash_err = 'จำนวนแต้มไม่ถูกต้อง';
    } else {
      try {
        $pdo->beginTransaction();

        // ล็อกแต้มสมาชิก
        $sel = $pdo->prepare("SELECT points FROM members WHERE id = :mid FOR UPDATE");
        $sel->execute([':mid'=>$member_id]);
        $cur = $sel->fetchColumn();
        if ($cur === false) throw new RuntimeException('ไม่พบสมาชิก');
        $cur = (int)$cur;

        if ($cur < $points_need) {
          throw new RuntimeException('แต้มไม่พอสำหรับการแลก');
        }

        // หักแต้ม
        $upd = $pdo->prepare("UPDATE members SET points = points - :need WHERE id = :mid");
        $upd->execute([':need'=>$points_need, ':mid'=>$member_id]);

        // บันทึก point_transactions (type='redeem', points=จำนวนที่ใช้, employee_user_id=NULL เพราะแลกผ่านพอร์ทัลสมาชิก)
        $ins = $pdo->prepare("
          INSERT INTO point_transactions(member_user_id, type, points, notes, employee_user_id, transaction_date)
          VALUES(:uid, 'redeem', :p, :notes, :emp, NOW())
        ");
        $ins->execute([
          ':uid'   => $current_user_id,
          ':p'     => $points_need,
          ':notes' => 'Redeem: ' . ($reward_title ?: ('Reward ' . $points_need)),
          ':emp'   => null,
        ]);

        $pdo->commit();
        $flash_ok = 'แลกของรางวัลสำเร็จ! หักแต้ม ' . number_format($points_need) . ' แต้ม';

        // รีโหลดแต้มล่าสุด
        $member['points'] = (int)$cur - $points_need;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash_err = 'แลกของรางวัลไม่สำเร็จ: ' . $e->getMessage();
      }
    }
  }
}

// ====== ประวัติการแลกคะแนน (จาก point_transactions.type = 'redeem') ======
$redemption_history = [];
if ($db_ok) {
  try {
    $st = $pdo->prepare("
      SELECT transaction_date, points, notes
      FROM point_transactions
      WHERE member_user_id = :uid AND type = 'redeem'
      ORDER BY transaction_date DESC
      LIMIT 100
    ");
    $st->execute([':uid'=>$current_user_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
      $date = new DateTime($r['transaction_date']);
      // พยายามดึงชื่อรางวัลจาก notes (prefix 'Redeem: ')
      $title = trim(preg_replace('/^Redeem:\s*/i', '', (string)$r['notes']));
      $redemption_history[] = [
        'date'   => $date->format('Y-m-d'),
        'title'  => $title ?: 'แลกของรางวัล',
        'points' => (int)$r['points'],
        'status' => 'ใช้งานแล้ว', // ถ้าในอนาคตมีสถานะจริงให้ดึงจากตารางเฉพาะ
        'code'   => '-',          // โค้ดคูปองสามารถออกเพิ่มภายหลัง
      ];
    }
  } catch (Throwable $e) {
    $db_err = $e->getMessage();
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>คะแนนสะสม | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    .panel{background:var(--surface-glass);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
    .panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
    .ok-badge{background:rgba(32,163,158,.12)!important;color:var(--teal)!important;border:1px solid var(--border)}
    .reward-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
      padding: 1rem; display: flex; flex-direction: column; transition: all .2s ease; }
    .reward-card:hover { transform: translateY(-4px); box-shadow: var(--shadow); }
    .reward-card h5 { font-weight: 700; }
    .reward-card .small-muted { flex-grow: 1; margin-bottom: .75rem; }
    .status-used { background: rgba(104,114,122,.12)!important; color: var(--steel)!important; border:1px solid var(--border); }
    .status-expired { background: rgba(182,109,13,.12)!important; color: var(--amber)!important; border:1px solid var(--border); }
  </style>
</head>
<body>

  <nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
          <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="member_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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
      <div class="side-brand mb-2"><h3><span>Member</span></h3></div>
      <nav class="sidebar-menu">
        <a href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
        <a href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
        <a class="active" href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout mt-auto" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row">
      <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
        <div class="side-brand mb-3"><h3><span>Member</span></h3></div>
        <nav class="sidebar-menu flex-grow-1">
          <a href="member_dashboard.php"><i class="fa-solid fa-id-card"></i>ภาพรวม</a>
          <a href="bills.php"><i class="fa-solid fa-receipt"></i> ประวัติการซื้อ</a>
          <a class="active" href="points.php"><i class="fa-solid fa-star"></i> คะแนนสะสม</a>
          <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
          <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
        </nav>
        <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
      </aside>

      <main class="col-lg-10 p-4">
        <div class="main-header">
          <h2><i class="fa-solid fa-star me-2"></i>คะแนนสะสมและของรางวัล</h2>
        </div>

        <?php if (!$db_ok): ?>
          <div class="alert alert-danger">เชื่อมต่อฐานข้อมูลไม่สำเร็จ: <?= htmlspecialchars($db_err) ?></div>
        <?php endif; ?>
        <?php if ($flash_ok): ?><div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
        <?php if ($flash_err): ?><div class="alert alert-danger"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

        <div class="stat-card mb-4">
          <h5>คะแนนสะสมปัจจุบัน</h5>
          <h3 class="text-primary"><?= number_format((int)$member['points']) ?> <span style="font-size: 1.2rem; font-weight: 500;">แต้ม</span></h3>
          <p class="mb-0 text-muted">ระดับสมาชิก: <?= htmlspecialchars($member['tier'] ?? '-') ?></p>
        </div>

        <div class="panel mb-4">
          <div class="panel-head">
            <h5><i class="fa-solid fa-gift me-2"></i>ของรางวัลที่แลกได้</h5>
          </div>
          <div class="row g-3">
            <?php foreach($rewards as $rw):
              $need = (int)$rw['need'];
              $enough = ((int)$member['points']) >= $need;
            ?>
            <div class="col-12 col-md-6 col-xl-4">
              <div class="reward-card h-100">
                <h5 class="mb-1"><?= htmlspecialchars($rw['title']) ?></h5>
                <div class="small-muted"><?= htmlspecialchars($rw['desc']) ?></div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="badge <?= $enough ? 'ok-badge' : 'bg-warning-subtle text-warning-emphasis' ?>">
                    ต้องใช้ <?= number_format($need) ?> แต้ม
                  </span>

                  <button
                    class="btn btn-sm <?= $enough ? 'btn-primary' : 'btn-outline-secondary' ?>"
                    <?= $enough ? '' : 'disabled' ?>
                    data-bs-toggle="modal"
                    data-bs-target="#redeemModal"
                    data-title="<?= htmlspecialchars($rw['title']) ?>"
                    data-need="<?= (int)$need ?>">
                    <i class="fa-solid fa-ticket me-1"></i> แลก
                  </button>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h5><i class="fa-solid fa-history me-2"></i>ประวัติการแลกคะแนน</h5>
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
                    <td><?= htmlspecialchars($h['date']) ?></td>
                    <td><?= htmlspecialchars($h['title']) ?></td>
                    <td class="text-end text-danger">-<?= number_format((int)$h['points']) ?></td>
                    <td><span class="badge status-used"><?= htmlspecialchars($h['status']) ?></span></td>
                    <td><?= htmlspecialchars($h['code']) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
  </div>

  <footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — ศูนย์สมาชิก</footer>

  <div class="modal fade" id="redeemModal" tabindex="-1" aria-labelledby="redeemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="POST" class="modal-content">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="redeem_reward">
        <input type="hidden" id="mPointsNeedInput" name="points_need" value="">
        <input type="hidden" id="mRewardTitleInput" name="reward_title" value="">

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
      const currentPoints = <?= (int)$member['points'] ?>;

      redeemModal?.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        const title = btn.getAttribute('data-title');
        const need = parseInt(btn.getAttribute('data-need'), 10) || 0;

        const after = Math.max(0, currentPoints - need);

        redeemModal.querySelector('#mRewardTitle').textContent = title;
        redeemModal.querySelector('#mPointsNeed').textContent = '-' + need.toLocaleString('th-TH') + ' แต้ม';
        redeemModal.querySelector('#mPointsAfter').textContent = after.toLocaleString('th-TH') + ' แต้ม';

        // ส่งค่าไปกับฟอร์ม
        redeemModal.querySelector('#mPointsNeedInput').value = String(need);
        redeemModal.querySelector('#mRewardTitleInput').value = title;
      });
    });
  </script>
</body>
</html>