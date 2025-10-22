<?php
// committee/member_history.php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'committee') {
  header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit;
}

$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // $pdo

$code = trim($_GET['code'] ?? '');
if ($code === '') { die('ไม่พบรหัสสมาชิก'); }

// ดึงชื่อไซต์
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  $st = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`='system_settings' LIMIT 1");
  $st->execute();
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $sys = json_decode($r['json_value'] ?? '', true) ?: [];
    $site_name = $sys['site_name'] ?? $site_name;
  }
} catch(Throwable $e){}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// หา member_id จาก member_code
$member = null;
try {
  $st = $pdo->prepare("
    SELECT m.id, m.member_code, m.shares, u.full_name
    FROM members m
    JOIN users u ON u.id = m.user_id
    WHERE m.member_code = :code
    LIMIT 1
  ");
  $st->execute([':code'=>$code]);
  $member = $st->fetch(PDO::FETCH_ASSOC);
  if (!$member) { die('ไม่พบบัญชีสมาชิกตามรหัสที่ระบุ'); }
} catch(Throwable $e){
  die('เกิดข้อผิดพลาดในการค้นหาสมาชิก');
}

// ดึงประวัติปันผลสมาชิก
$history = [];
try {
  $sql = "
    SELECT 
      dp.period_id,
      dp.shares_at_time,
      dp.dividend_amount,
      dp.payment_status,
      dp.paid_at,
      d.period_code,
      d.year,
      d.period_name,
      d.dividend_rate,
      d.payment_date
    FROM dividend_payments dp
    JOIN dividend_periods d ON d.id = dp.period_id
    WHERE dp.member_id = :mid
    ORDER BY d.year DESC, d.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':mid'=>(int)$member['id']]);
  $history = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ /* ปล่อยว่างให้เป็นรายการว่าง */ }

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ประวัติสมาชิก <?= h($member['member_code']) ?> | <?= h($site_name) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="p-3">
  <div class="container">
    <div class="mb-3">
      <a href="member.php" class="btn btn-outline-secondary ">&larr; กลับหน้าสมาชิก</a>
    </div>

    <h3 class="mb-1">ประวัติการรับปันผล</h3>
    <div class="text-muted mb-3">
      รหัสสมาชิก: <b><?= h($member['member_code']) ?></b> — ชื่อ: <b><?= h($member['full_name']) ?></b>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>งวด</th>
            <th class="text-center">หุ้น ณ งวด</th>
            <th class="text-end">อัตรา (%)</th>
            <th class="text-end">ปันผล (บาท)</th>
            <th class="text-center">สถานะ</th>
            <th class="text-center">วันที่จ่าย</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($history)): ?>
            <tr><td colspan="6" class="text-center text-muted">ยังไม่มีประวัติ</td></tr>
          <?php else: foreach($history as $row): ?>
            <tr>
              <td><?= h($row['period_code']) ?> (<?= h($row['year']) ?> <?= h($row['period_name']) ?>)</td>
              <td class="text-center"><?= number_format((int)($row['shares_at_time'] ?? 0)) ?></td>
              <td class="text-end"><?= number_format((float)$row['dividend_rate'], 2) ?></td>
              <td class="text-end">฿<?= number_format((float)$row['dividend_amount'], 2) ?></td>
              <td class="text-center">
                <span class="badge <?= ($row['payment_status']==='paid'?'bg-success':'bg-warning text-dark') ?>">
                  <?= $row['payment_status']==='paid' ? 'จ่ายแล้ว' : 'ค้างจ่าย' ?>
                </span>
              </td>
              <td class="text-center">
                <?= !empty($row['payment_date']) ? date('d/m/Y', strtotime($row['payment_date'])) : '-' ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
