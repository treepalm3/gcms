<?php
// employee/list_sell.php
// รายการขาย (MySQL 8.4+)
// - liters แสดง 2 ตำแหน่ง
// - กรองช่วงวันที่ index-friendly
// - จับคู่ชนิดน้ำมัน: ใช้ fuel_id ก่อน แล้วค่อย fallback ตามชื่อ
// - ผู้ขาย/พนักงาน: ใช้ COALESCE(s.created_by, s.employee_user_id, fm.user_id)
// - อนุญาตแก้ไขได้ครั้งเดียว และ sync fuel_moves.liters/occurred_at ให้ตรงบิล
// - ระหว่างแก้ไข ถ้า sales.created_by ยังว่าง จะเติมเป็นผู้ใช้ปัจจุบัน

session_start();
date_default_timezone_set('Asia/Bangkok');

// ====== Login check ======
if (!isset($_SESSION['user_id'])) {
    header('Location: /index/login.php');
    exit();
}

// ====== DB connect ======
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // expect $pdo (PDO)

// ====== Basic user context ======
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_name    = $_SESSION['full_name'] ?? 'พนักงาน';
$current_role    = $_SESSION['role'] ?? 'guest';
if ($current_role !== 'employee') {
    header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    exit();
}
$role_th_map = ['admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

// ====== CSRF ======
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ====== PDO ready / health ======
$db_ok = false; $db_error = null;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('ไม่พบตัวแปร $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET NAMES utf8mb4");
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}
  $pdo->query("SELECT 1");
  $db_ok = true;
} catch (Throwable $e) {
  $db_error = $e->getMessage();
}

// ====== helpers ======
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:col');
    $st->execute([':db'=>$db, ':tb'=>$table, ':col'=>$col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

// ====== Site / Station ======
$site_name  = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$station_id = 1;
if ($db_ok) {
  try {
    $srow = $pdo->query("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($srow) {
      $station_id = (int)$srow['setting_value'];
      if (!empty($srow['comment'])) $site_name = $srow['comment'];
    }
    $cfg = $pdo->query("SELECT json_value FROM app_settings WHERE `key`='system_settings'")->fetchColumn();
    if ($cfg) {
      $sys = json_decode($cfg, true);
      if (!empty($sys['site_name'])) $site_name = $sys['site_name'];
    }
  } catch (Throwable $e) { /* ignore */ }
}

// ====== Schema flags ======
$HAS_PAYMENT_METHOD = $db_ok ? column_exists($pdo, 'sales', 'payment_method') : false;
$HAS_IS_EDITED      = $db_ok ? column_exists($pdo, 'sales', 'is_edited')      : false;

// ====== Fuel list for dropdown ======
$fuel_names = [];
if ($db_ok) {
  try {
    $q = $pdo->prepare("SELECT fuel_name FROM fuel_prices WHERE station_id = :st ORDER BY display_order, fuel_id");
    $q->execute([':st'=>$station_id]);
    $fuel_names = $q->fetchAll(PDO::FETCH_COLUMN);
  } catch (Throwable $e) { /* ignore */ }
}

// ====== Handle EDIT (POST) ======
$edit_error = null;
if ($db_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit_save') {
  // CSRF
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $edit_error = 'Session ไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่';
  } else {
    $sale_id   = (int)($_POST['sale_id'] ?? 0);
    $fuel_name = trim($_POST['fuel_name'] ?? '');
    $liters    = round((float)($_POST['liters'] ?? 0), 2); // DECIMAL(10,2)
    $pm_in     = $_POST['payment_method'] ?? null;
    $sale_dt   = $_POST['sale_date'] ?? '';

    if ($sale_id <= 0)              $edit_error = 'ไม่พบเลขที่บิล';
    elseif ($fuel_name === '')      $edit_error = 'กรุณาระบุชนิดน้ำมัน';
    elseif (!is_finite($liters) || $liters <= 0) $edit_error = 'ปริมาณลิตรต้องมากกว่า 0';
    elseif ($HAS_PAYMENT_METHOD && $pm_in!==null && !in_array($pm_in, ['','cash','qr','transfer','card'], true)) {
      $edit_error = 'วิธีชำระเงินไม่ถูกต้อง';
    }

    if (!$edit_error && $HAS_IS_EDITED) {
      $chk = $pdo->prepare("SELECT is_edited FROM sales WHERE id = :sid");
      $chk->execute([':sid'=>$sale_id]);
      if ((int)$chk->fetchColumn() === 1) {
        $edit_error = 'บิลนี้ถูกแก้ไขไปแล้ว ไม่สามารถแก้ไขได้อีก';
      }
    }

    if (!$edit_error) {
      try {
        $pdo->beginTransaction();

        // อ่าน sales_items แถวแรกของบิล + ราคาเดิม (ล็อค)
        $rowItem = $pdo->prepare("SELECT id, price_per_liter FROM sales_items WHERE sale_id = :sid ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $rowItem->execute([':sid'=>$sale_id]);
        $item = $rowItem->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new RuntimeException('ไม่พบรายการสินค้าในบิล');

        $item_id = (int)$item['id'];
        $price   = (float)$item['price_per_liter'];
        if ($price <= 0) throw new RuntimeException('ไม่พบราคาเดิมของบิล');

        $gross      = round($liters * $price, 2);
        $net        = $gross; // ไม่มีส่วนลดในฟอร์มแก้ไข
        $sale_date  = $sale_dt ? date('Y-m-d H:i:s', strtotime($sale_dt)) : date('Y-m-d H:i:s');

        // อัปเดตรายการ (ไม่แตะราคา/ลิตร)
        $updItem = $pdo->prepare("UPDATE sales_items SET fuel_type = :fuel, liters = :liters WHERE id = :iid AND sale_id = :sid");
        $updItem->execute([':fuel'=>$fuel_name, ':liters'=>$liters, ':iid'=>$item_id, ':sid'=>$sale_id]);

        // อัปเดตหัวบิล
        $sqlSale = "
          UPDATE sales
             SET total_amount = :gross,
                 net_amount   = :net,
                 sale_date    = :sdt
                 ".($HAS_PAYMENT_METHOD ? ", payment_method = :pm " : " ")."
                 ".($HAS_IS_EDITED ? ", is_edited = 1 " : " ")."
           WHERE id = :sid
        ";
        $params = [':gross'=>$gross, ':net'=>$net, ':sdt'=>$sale_date, ':sid'=>$sale_id];
        if ($HAS_PAYMENT_METHOD) $params[':pm'] = ($pm_in===''?null:$pm_in);
        $pdo->prepare($sqlSale)->execute($params);

        // ถ้า created_by ยังว่าง ให้บันทึกเป็นผู้ใช้ปัจจุบัน
        $pdo->prepare("UPDATE sales SET created_by = COALESCE(created_by, :uid) WHERE id = :sid")
            ->execute([':uid' => $current_user_id, ':sid' => $sale_id]);

        // sync fuel_moves สำหรับบิลนี้ (ถ้ามีแถว sale_out จะถูกอัปเดต)
        $pdo->prepare("
          UPDATE fuel_moves
             SET liters = :liters,
                 occurred_at = :sdt
           WHERE sale_id = :sid AND is_sale_out = 1
        ")->execute([':liters'=>$liters, ':sdt'=>$sale_date, ':sid'=>$sale_id]);

        $pdo->commit();
        header("Location: list_sell.php?edited=1#sale-$sale_id");
        exit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $edit_error = 'บันทึกไม่สำเร็จ: '.$e->getMessage();
      }
    }
  }
}

// ====== Query params (GET) ======
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$payment   = trim($_GET['payment'] ?? '');
$fuel      = trim($_GET['fuel'] ?? '');
$limit_in  = (int)($_GET['limit'] ?? 500);
$limit     = max(1, min(2000, $limit_in ?: 500)); // 1..2000

// ====== Fetch Sales ======
$sales = [];
if ($db_ok) {
  try {
    $where  = ["s.station_id = :st"];
    $params = [':st'=>$station_id];

    if ($date_from !== '') { $where[] = "s.sale_date >= :from"; $params[':from'] = $date_from.' 00:00:00'; }
    if ($date_to   !== '') { $where[] = "s.sale_date <= :to";   $params[':to']   = $date_to.' 23:59:59'; }
    if ($fuel      !== '') { $where[] = "COALESCE(fp1.fuel_name, fp2.fuel_name, si.fuel_type) = :fuel"; $params[':fuel'] = $fuel; }
    if ($HAS_PAYMENT_METHOD && $payment !== '') { $where[] = "s.payment_method = :pm"; $params[':pm'] = $payment; }

    $whereSql = 'WHERE '.implode(' AND ', $where);

    $sql = "
      SELECT
        s.id, s.station_id, s.sale_code, s.total_amount, s.net_amount, s.sale_date,
        ".($HAS_PAYMENT_METHOD ? "s.payment_method" : "NULL AS payment_method").",
        ".($HAS_IS_EDITED ? "s.is_edited" : "NULL AS is_edited").",
        si.liters, si.price_per_liter,
        COALESCE(fp1.fuel_name, fp2.fuel_name, si.fuel_type) AS fuel_type,
        emp_user.full_name AS employee_name
      FROM sales s

      /* รายการแรกของแต่ละบิล */
      LEFT JOIN sales_items si
             ON si.sale_id = s.id
            AND si.id = (SELECT MIN(si2.id) FROM sales_items si2 WHERE si2.sale_id = s.id)

      /* จับคู่ด้วย fuel_id ก่อน (index: fuel_prices.idx_station_fuel) */
      LEFT JOIN fuel_prices fp1
             ON fp1.station_id = s.station_id
            AND fp1.fuel_id    = si.fuel_id

      /* ถ้าไม่มี fuel_id ให้ fallback ด้วยชื่อ (index: fuel_prices.uq_fuel_name_station) */
      LEFT JOIN fuel_prices fp2
             ON fp2.station_id = s.station_id
            AND si.fuel_id IS NULL
            AND fp2.fuel_name  = si.fuel_type

      /* ผู้ขาย: เลือกแถว sale_out ที่ใหม่สุดต่อบิล (ใช้อินเด็กซ์ is_sale_out) */
      LEFT JOIN (
        SELECT sale_id, MAX(id) AS fm_id
        FROM fuel_moves
        WHERE is_sale_out = 1
        GROUP BY sale_id
      ) fm_pick ON fm_pick.sale_id = s.id
      LEFT JOIN fuel_moves fm ON fm.id = fm_pick.fm_id

      /* ชื่อพนักงาน: เติม created_by/employee_user_id/fuel_moves.user_id */
      LEFT JOIN users emp_user ON emp_user.id = COALESCE(s.created_by, s.employee_user_id, fm.user_id)

      $whereSql
      ORDER BY s.sale_date DESC, s.id DESC
      LIMIT :lim
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $db_error = 'ดึงข้อมูลล้มเหลว: '.$e->getMessage();
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>รายการขาย | <?= htmlspecialchars($site_name) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>.receipt-btn{padding:.25rem .5rem;font-size:.8rem}</style>
</head>
<body>
<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
      <a class="navbar-brand" href="profile.php"><?= htmlspecialchars($site_name) ?></a>
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
    <div class="side-brand mb-2"><h3><span>Employee</span></h3></div>
    <nav class="sidebar-menu">
      <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
      <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
      <a class="active" href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
      <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
      <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
      <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
    </nav>
    <a class="logout mt-auto" href="../index.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Employee</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="employee_dashboard.php"><i class="fa-solid fa-chart-simple"></i> แดชบอร์ด</a>
        <a href="sell.php"><i class="bi bi-cash-coin"></i> ขายน้ำมัน</a>
        <a class="active" href="list_sell.php"><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</a>
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i> คลังสต๊อก</a>
        <a href="member_points.php"><i class="bi bi-star-fill"></i> แต้มสมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket me-1"></i> ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
      <div class="main-header"><h2><i class="fa-solid fa-list-ul me-1"></i> รายการขาย</h2></div>

      <?php if (isset($_GET['edited'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
          <i class="bi bi-check-circle-fill me-1"></i>บันทึกการแก้ไขเรียบร้อย
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <?php if (!$db_ok): ?>
        <div class="alert alert-danger alert-dismissible fade show">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>เชื่อมต่อฐานข้อมูลไม่สำเร็จ: <?= htmlspecialchars($db_error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php elseif (!empty($edit_error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
          <?= htmlspecialchars($edit_error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <form class="card card-body mb-3" method="get">
        <div class="row g-2 align-items-end">
          <div class="col-sm-6 col-md-4 col-lg-3">
            <label class="form-label mb-1">ตั้งแต่วันที่</label>
            <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
          </div>
          <div class="col-sm-6 col-md-4 col-lg-3">
            <label class="form-label mb-1">ถึงวันที่</label>
            <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
          </div>
          <div class="col-sm-6 col-md-4 col-lg-2">
            <label class="form-label mb-1">วิธีชำระ</label>
            <select class="form-select" name="payment">
              <option value="">ทั้งหมด</option>
              <option value="cash"     <?= $payment==='cash'?'selected':'' ?>>เงินสด</option>
              <option value="qr"       <?= $payment==='qr'?'selected':'' ?>>QR</option>
              <option value="transfer" <?= $payment==='transfer'?'selected':'' ?>>โอนเงิน</option>
              <option value="card"     <?= $payment==='card'?'selected':'' ?>>บัตรเครดิต</option>
            </select>
          </div>
          <div class="col-sm-6 col-md-4 col-lg-2">
            <label class="form-label mb-1">ชนิดน้ำมัน</label>
            <select class="form-select" name="fuel">
              <option value="">ทั้งหมด</option>
              <?php foreach ($fuel_names as $fn): ?>
                <option value="<?= htmlspecialchars($fn) ?>" <?= $fuel===$fn?'selected':'' ?>><?= htmlspecialchars($fn) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button class="btn btn-primary"><i class="bi bi-search"></i> ค้นหา</button>
          </div>
          <div class="col-auto">
            <a href="list_sell.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> ล้าง</a>
          </div>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
          <thead>
            <tr class="table-light">
              <th>ใบเสร็จ</th>
              <th>พนักงาน</th>
              <th>ชนิดน้ำมัน</th>
              <th class="text-end">ลิตร</th>
              <th class="text-end">ราคา/ลิตร</th>
              <th class="text-end">ยอดรวม</th>
              <th class="text-end">ยอดสุทธิ</th>
              <th>วิธีชำระ</th>
              <th>วันที่ขาย</th>
              <th class="text-center">จัดการ</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($sales)): ?>
            <tr><td colspan="10" class="text-center text-muted">ไม่พบข้อมูลการขายตามเงื่อนไข</td></tr>
          <?php else:
            $pay_badge = [
              'cash'=>['l'=>'เงินสด','c'=>'success'],
              'qr'=>['l'=>'QR','c'=>'info'],
              'transfer'=>['l'=>'โอนเงิน','c'=>'primary'],
              'card'=>['l'=>'บัตรเครดิต','c'=>'warning'],
            ];
            foreach ($sales as $sale):
              $pm_key  = strtolower((string)($sale['payment_method'] ?? ''));
              $pm      = $pay_badge[$pm_key] ?? ['l'=>'ไม่ระบุ','c'=>'secondary'];
              $canEdit = empty($sale['is_edited']);
              $payload = [
                'id'               => (int)$sale['id'],
                'sale_code'        => $sale['sale_code'],
                'fuel_type'        => $sale['fuel_type'] ?? '',
                'liters'           => (float)($sale['liters'] ?? 0),
                'price_per_liter'  => (float)($sale['price_per_liter'] ?? 0),
                'total_amount'     => (float)($sale['total_amount'] ?? 0),
                'net_amount'       => (float)($sale['net_amount'] ?? 0),
                'payment_method'   => $sale['payment_method'] ?? '',
                'sale_date'        => $sale['sale_date'],
                'employee_name'    => $sale['employee_name'] ?? null,
              ];
              // [!! แก้ไข !!]
              $receipt_url = 'sales_receipt.php?code=' . urlencode($sale['sale_code']);
            ?>
              <tr id="sale-<?= (int)$sale['id'] ?>"
                    data-receipt-type="sale"
                    data-receipt-code="<?= htmlspecialchars($sale['sale_code']) ?>"
                    data-receipt-url="<?= htmlspecialchars($receipt_url) ?>">
              
              <td><strong><?= htmlspecialchars($sale['sale_code']) ?></strong></td>
              <td><?= htmlspecialchars($sale['employee_name'] ?: 'ไม่ระบุ') ?></td>
              <td><?= htmlspecialchars($sale['fuel_type'] ?? '-') ?></td>
              <td class="text-end"><?= number_format((float)$sale['liters'], 2) ?></td>
              <td class="text-end"><?= number_format((float)$sale['price_per_liter'], 2) ?></td>
              <td class="text-end"><?= number_format((float)$sale['total_amount'], 2) ?></td>
              <td class="text-end fw-bold"><?= number_format((float)$sale['net_amount'], 2) ?></td>
              <td><span class="badge bg-<?= $pm['c'] ?>-subtle text-<?= $pm['c'] ?>-emphasis"><?= htmlspecialchars($pm['l']) ?></span></td>
              <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($sale['sale_date']))) ?></td>
              <td class="text-center">
                <div class="btn-group">
                  <button class="btn btn-outline-secondary btn-sm receipt-btn btnReceipt"
                          title="พิมพ์ใบเสร็จ">
                    <i class="bi bi-printer"></i> พิมพ์
                  </button>
                  <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-outline-warning btn-sm"
                            data-sale='<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'
                            onclick="openEditModal(this)" title="แก้ไขได้ครั้งเดียว">
                      <i class="bi bi-pencil"></i>
                    </button>
                  <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="แก้ไขแล้ว">
                      <i class="bi bi-check-circle"></i>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </main>
  </div>
</div>

<div class="modal fade" id="editSaleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" id="editSaleForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="edit_save">
      <input type="hidden" name="sale_id" id="editSaleId">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i> แก้ไขบิลขาย (แก้ได้ครั้งเดียว)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle-fill"></i> การแก้ไขจะถูกบันทึกและไม่สามารถแก้ไขซ้ำได้</div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">ชนิดน้ำมัน</label>
            <select class="form-select" name="fuel_name" id="editFuelName" required>
              <?php foreach($fuel_names as $fname): ?>
                <option value="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($fname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">ปริมาณ (ลิตร)</label>
            <input type="number" step="0.01" min="0" class="form-control" name="liters" id="editLiters" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">ราคา/ลิตร (บาท)</label>
            <input type="text" class="form-control" id="editPrice" readonly>
            <div class="form-text">ราคานี้ถูกล็อคตามบิลเดิม</div>
          </div>

          <?php if ($HAS_PAYMENT_METHOD): ?>
          <div class="col-md-6">
            <label class="form-label">วิธีชำระ</label>
            <select class="form-select" name="payment_method" id="editPayment">
              <option value="">ไม่ระบุ</option>
              <option value="cash">เงินสด</option>
              <option value="qr">QR</option>
              <option value="transfer">โอนเงิน</option>
              <option value="card">บัตรเครดิต</option>
            </select>
          </div>
          <?php endif; ?>

          <div class="col-md-6">
            <label class="form-label">วันที่บิล</label>
            <input type="datetime-local" class="form-control" name="sale_date" id="editSaleDate">
          </div>
        </div>

        <hr>
        <div class="d-flex justify-content-end">
          <h5 class="me-3">ยอดสุทธิ: <strong class="text-primary" id="sumNet">0.00</strong> บาท</h5>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save2 me-1"></i> บันทึกการแก้ไข</button>
      </div>
    </form>
  </div></div>
</div>

<footer class="footer">© <?= date('Y'); ?> <?= htmlspecialchars($site_name) ?></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== [ใหม่] ตรรกะการเปิดลิงก์ใบเสร็จ (เหมือน committee/finance.php) =====
const receiptRoutes = {
  sale: code => `sales_receipt.php?code=${encodeURIComponent(code)}`
  // หน้านี้ต้องการแค่ 'sale'
};

document.body.addEventListener('click', function(e) {
    // ใช้ .receipt-btn (คลาสเดิม) หรือ .btnReceipt (คลาสใหม่) ก็ได้
    const receiptBtn = e.target.closest('.receipt-btn, .btnReceipt'); 
    
    if (receiptBtn) {
        const tr = receiptBtn.closest('tr');
        if (!tr) return; 

        const type = tr.dataset.receiptType; // 'sale'
        const code = tr.dataset.receiptCode;
        let url = (tr.dataset.receiptUrl || '').trim();
        
        // ตรรกะสำรอง (เผื่อ data-receipt-url ไม่ได้ตั้งค่า)
        if (!url && type && code && receiptRoutes[type]) {
           url = receiptRoutes[type](code);
        }
        
        if (url) {
            window.open(url, '_blank'); // เปิดในแท็บใหม่
        } else {
            console.warn('ไม่พบ URL ใบเสร็จสำหรับแถวนี้', tr);
            alert('ไม่พบ URL ใบเสร็จ');
        }
    }
});

// ===== Edit Modal logic =====
const editModal = new bootstrap.Modal(document.getElementById('editSaleModal'));

function toInputDT(dstr){
  const d = new Date(dstr);
  if (isNaN(d)) return '';
  const p = n => String(n).padStart(2,'0');
  return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

function openEditModal(btn){
  const sale = JSON.parse(btn.getAttribute('data-sale') || '{}');
  document.getElementById('editSaleId').value = sale.id || '';
  document.getElementById('editFuelName').value = sale.fuel_type || '';
  document.getElementById('editLiters').value = (parseFloat(sale.liters||0)).toFixed(2);
  document.getElementById('editPrice').value  = (parseFloat(sale.price_per_liter||0)).toFixed(2);
  const paySel = document.getElementById('editPayment');
  if (paySel) paySel.value = sale.payment_method || '';
  document.getElementById('editSaleDate').value = toInputDT(sale.sale_date || '');

  recalcEditSummary();
  editModal.show();
}

function recalcEditSummary(){
  const L = parseFloat(document.getElementById('editLiters').value || '0') || 0;
  const P = parseFloat(document.getElementById('editPrice').value  || '0') || 0;
  const net = (L*P);
  document.getElementById('sumNet').textContent = net.toLocaleString('th-TH',{minimumFractionDigits:2, maximumFractionDigits:2});
}
['editLiters','editFuelName'].forEach(id=>{
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', recalcEditSummary);
});
</script>
</body>
</html>