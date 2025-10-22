<?php
// admin/finance.php — จัดการการเงินและบัญชี (ผูกตัวกรองช่วงวันที่เดียว ควบคุมทั้งหน้า)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) { header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit(); }

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile; // ต้องมี $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ'); }

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
  $current_name = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
  $current_role = $_SESSION['role'] ?? '';
  if ($current_role !== 'admin') { header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); exit(); }
} catch (Throwable $e) { header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit(); }
 
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* ===== Helpers ===== */
if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>$table]);
    return (int)$st->fetchColumn() > 0;
  }
}
if (!function_exists('column_exists')) {
  function column_exists(PDO $pdo, string $table, string $col): bool {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:col');
    $st->execute([':db'=>$db, ':tb'=>$table, ':col'=>$col]);
    return (int)$st->fetchColumn() > 0;
  }
}
function nf($n, $d=2){ return number_format((float)$n, $d, '.', ','); }
function ymd($s){ $t=strtotime($s); return $t? date('Y-m-d',$t) : null; }

/* ===== ค่าพื้นฐาน ===== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
try {
  $st = $pdo->query("SELECT site_name FROM settings WHERE id=1");
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) $site_name = $r['site_name'] ?: $site_name;
} catch (Throwable $e) {}
 
$stationId = 1;
try {
  if (table_exists($pdo,'settings')) {
    $sid = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1")->fetchColumn();
    if ($sid !== false) $stationId = (int)$sid;
  }
} catch (Throwable $e) {}
 
/* ===== กำหนดช่วงวันที่ "ส่วนกลางของทั้งหน้า" ===== */
$quick = $_GET['gp_quick'] ?? ''; // today|yesterday|7d|30d|this_month|last_month|this_year|all
$in_from = ymd($_GET['gp_from'] ?? '');
$in_to   = ymd($_GET['gp_to']   ?? '');

$today = new DateTime('today');
$from = null; $to = null;

if ($in_from && $in_to) {
    $from = new DateTime($in_from);
    $to = new DateTime($in_to);
} else {
    switch ($quick) {
      case 'today':      $from=$today; $to=clone $today; break;
      case 'yesterday':  $from=(clone $today)->modify('-1 day'); $to=(clone $today)->modify('-1 day'); break;
      case '30d':        $from=(clone $today)->modify('-29 day'); $to=$today; break;
      case 'this_month': $from=new DateTime(date('Y-m-01')); $to=$today; break;
      case 'last_month': $from=new DateTime(date('Y-m-01', strtotime('first day of last month'))); $to=new DateTime(date('Y-m-t', strtotime('last day of last month'))); break;
      case 'this_year':  $from=new DateTime(date('Y-01-01')); $to=$today; break;
      case 'all':        $from=null; $to=null; break;
      default:           $from=(clone $today)->modify('-6 day'); $to=$today; $quick = '7d'; break; // Default 7 วัน
    }
}
if ($from && $to && $to < $from) { $tmp=$from; $from=$to; $to=$tmp; }
if ($from && $to) { // จำกัดช่วงสูงสุด ~1 ปี
  $diffDays = (int)$from->diff($to)->format('%a');
  if ($diffDays > 366) { $from=(clone $to)->modify('-366 day'); }
}
$rangeFromStr = $from ? $from->format('Y-m-d') : null;
$rangeToStr   = $to   ? $to->format('Y-m-d')   : null;
 
/* ===== ธงโหมด ===== */
$has_ft  = table_exists($pdo,'financial_transactions');
$has_gpv = table_exists($pdo,'v_sales_gross_profit');
if ($has_gpv) {
  try {
    $test = $pdo->query("SELECT COUNT(*) FROM v_sales_gross_profit LIMIT 1")->fetchColumn();
    if ($test === false) {
      $has_gpv = false;
      error_log("v_sales_gross_profit exists but no data");
    }
  } catch (Throwable $e) {
    $has_gpv = false;
    error_log("v_sales_gross_profit error: " . $e->getMessage());
  }
}
$ft_has_station   = $has_ft && column_exists($pdo,'financial_transactions','station_id');
$has_sales_station= column_exists($pdo,'sales','station_id');
$has_fr_station   = column_exists($pdo,'fuel_receives','station_id');
$has_lot_station  = column_exists($pdo,'fuel_lots','station_id');
$has_tank_station = column_exists($pdo,'fuel_tanks','station_id');

/* ===== ดึงธุรกรรม (FT หรือ UNION) + หมวดหมู่ + สรุป (กรองตามช่วง) ===== */
$transactions = [];
$categories   = ['income'=>[],'expense'=>[]];
$total_income = 0.0; $total_expense = 0.0; $net_profit = 0.0; $total_transactions = 0;

try {
  if ($has_ft) {
    // เงื่อนไขช่วง + สถานี (ถ้ามีคอลัมน์)
    $w = 'WHERE 1=1'; $p=[];
    if ($ft_has_station) { $w .= " AND ft.station_id = :sid"; $p[':sid'] = $stationId; }
    if ($rangeFromStr) { $w.=" AND DATE(ft.transaction_date) >= :f"; $p[':f']=$rangeFromStr; }
    if ($rangeToStr)   { $w.=" AND DATE(ft.transaction_date) <= :t"; $p[':t']=$rangeToStr; }

    $stmt = $pdo->prepare("
      SELECT COALESCE(ft.transaction_code, CONCAT('FT-', ft.id)) AS id,
             ft.transaction_date AS date, ft.type, ft.category, ft.description,
             ft.amount, ft.reference_id AS reference, COALESCE(u.full_name,'-') AS created_by
      FROM financial_transactions ft
      LEFT JOIN users u ON u.id = ft.user_id
      $w
      ORDER BY ft.transaction_date DESC, ft.id DESC
      LIMIT 500
    ");
    $stmt->execute($p);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $catSql = "SELECT type, GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR '||') cats
               FROM financial_transactions ".($ft_has_station?" WHERE station_id=:sid ":"")."
               GROUP BY type";
    $catStmt = $pdo->prepare($catSql);
    if ($ft_has_station) $catStmt->execute([':sid'=>$stationId]); else $catStmt->execute();
    $cats = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $categories['income']  = isset($cats['income'])  ? explode('||',$cats['income'])  : [];
    $categories['expense'] = isset($cats['expense']) ? explode('||',$cats['expense']) : [];

    $sumSql = "SELECT COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) ti,
                      COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) te,
                      COUNT(*) cnt
               FROM financial_transactions ft $w";
    $sum = $pdo->prepare($sumSql);
    $sum->execute($p);
    $s = $sum->fetch(PDO::FETCH_ASSOC);
    $total_income = (float)$s['ti'];
    $total_expense = (float)$s['te'];
    $total_transactions = (int)$s['cnt'];
    $net_profit = $total_income - $total_expense;

  } else {
    // ====== UNION แบบยืดหยุ่นเมื่อไม่มี financial_transactions ======
    $parts = [];
    $paramsU = [];

    // SALES
    $salesWhere = "WHERE 1=1";
    if ($has_sales_station) { $salesWhere .= " AND s.station_id = :sidS"; $paramsU[':sidS'] = $stationId; }
    $parts[] = "
      SELECT
        CONCAT('SALE-', s.sale_code)           AS id,
        s.sale_date                             AS date,
        'income'                                AS type,
        'ขายน้ำมัน'                            AS category,
        CONCAT('ขายเชื้อเพลิง (', COALESCE(s.payment_method,''), ')') AS description,
        s.total_amount                          AS amount,
        s.sale_code                             AS reference,
        COALESCE(u.full_name,'-')               AS created_by
      FROM sales s
      LEFT JOIN (
        SELECT sale_id, MIN(user_id) AS user_id
        FROM fuel_moves
        WHERE type='sale_out' AND sale_id IS NOT NULL
        GROUP BY sale_id
      ) fm ON fm.sale_id = s.id
      LEFT JOIN users u ON u.id = fm.user_id
      $salesWhere
    ";

    // RECEIVES (เข้าคลัง)
    if ($has_fr_station) {
      $whereRcv = "WHERE fr.station_id = :sidR";
      $paramsU[':sidR'] = $stationId;
    } else {
      // ไม่มี station ใน fr → อิง fuel_prices ของสถานี
      $whereRcv = "WHERE EXISTS (SELECT 1 FROM fuel_prices fp2 WHERE fp2.fuel_id=fr.fuel_id AND fp2.station_id=:sidR)";
      $paramsU[':sidR'] = $stationId;
    }
    $parts[] = "
      SELECT
        CONCAT('RCV-', fr.id)                   AS id,
        fr.received_date                        AS date,
        'expense'                               AS type,
        'ซื้อน้ำมันเข้าคลัง'                   AS category,
        CONCAT('รับเข้าคลัง ', COALESCE(fp.fuel_name,''), COALESCE(CONCAT(' จาก ', s2.supplier_name), '')) AS description,
        (COALESCE(fr.cost,0) * fr.amount)       AS amount,
        fr.id                                   AS reference,
        COALESCE(u2.full_name,'-')              AS created_by
      FROM fuel_receives fr
      LEFT JOIN fuel_prices fp ON fp.fuel_id = fr.fuel_id AND fp.station_id = :sidR
      LEFT JOIN suppliers s2 ON s2.supplier_id = fr.supplier_id
      LEFT JOIN users u2 ON u2.id = fr.created_by
      $whereRcv
    ";

    // LOTS (เข้าถัง)
    if ($has_lot_station) {
      $lotFrom = "FROM fuel_lots l LEFT JOIN users u3 ON u3.id = l.created_by WHERE l.station_id = :sidL";
      $paramsU[':sidL'] = $stationId;
      $lotSelect = "l.received_at AS date, l.lot_code AS code, l.initial_total_cost AS amount, COALESCE(u3.full_name,'-') AS created_by";
      $lotDesc = "CONCAT('รับเข้าถัง ', tcode)"; // tcode จะเป็น NULL ในเคสไม่ join ถัง
      // ดึง code ถังด้วย subquery (หลีกเลี่ยง join ที่ต้องพึ่ง station_id อีกชั้น)
      $parts[] = "
        SELECT
          CONCAT('LOT-', l.lot_code)            AS id,
          l.received_at                         AS date,
          'expense'                             AS type,
          'ซื้อน้ำมันเข้าถัง'                  AS category,
          CONCAT('รับเข้าถัง ', COALESCE((SELECT t.code FROM fuel_tanks t WHERE t.id = l.tank_id LIMIT 1), '')) AS description,
          l.initial_total_cost                  AS amount,
          l.receive_id                          AS reference,
          COALESCE(u3.full_name,'-')            AS created_by
        FROM fuel_lots l
        LEFT JOIN users u3 ON u3.id = l.created_by
        WHERE l.station_id = :sidL
      ";
    } elseif ($has_tank_station) {
      // กรองตามสถานีผ่าน fuel_tanks
      $paramsU[':sidL'] = $stationId;
      $parts[] = "
        SELECT
          CONCAT('LOT-', l.lot_code)            AS id,
          l.received_at                         AS date,
          'expense'                             AS type,
          'ซื้อน้ำมันเข้าถัง'                  AS category,
          CONCAT('รับเข้าถัง ', t.code)        AS description,
          l.initial_total_cost                  AS amount,
          l.receive_id                          AS reference,
          COALESCE(u3.full_name,'-')            AS created_by
        FROM fuel_lots l
        JOIN fuel_tanks t ON t.id = l.tank_id
        LEFT JOIN users u3 ON u3.id = l.created_by
        WHERE t.station_id = :sidL
      ";
    } else {
      // ไม่มีคอลัมน์สถานีใน lots/tanks ก็แสดงทั้งหมด
      $parts[] = "
        SELECT
          CONCAT('LOT-', l.lot_code)            AS id,
          l.received_at                         AS date,
          'expense'                             AS type,
          'ซื้อน้ำมันเข้าถัง'                  AS category,
          CONCAT('รับเข้าถัง ', '')            AS description,
          l.initial_total_cost                  AS amount,
          l.receive_id                          AS reference,
          COALESCE(u3.full_name,'-')            AS created_by
        FROM fuel_lots l
        LEFT JOIN users u3 ON u3.id = l.created_by
      ";
    }

    $unionSQL = implode("\nUNION ALL\n", $parts);

    // WHERE วันที่ (outer)
    $w = 'WHERE 1=1'; $p = $paramsU; // Start with UNION params
    if ($rangeFromStr) { $w.=" AND DATE(x.date) >= :f"; $p[':f']=$rangeFromStr; }
    if ($rangeToStr)   { $w.=" AND DATE(x.date) <= :t"; $p[':t']=$rangeToStr; }

    $stmt = $pdo->prepare("SELECT * FROM ( $unionSQL ) x $w ORDER BY x.date DESC, x.id DESC LIMIT 500");
    $stmt->execute($p);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $catStmt = $pdo->prepare("
      SELECT type, GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR '||') cats
      FROM ( $unionSQL ) c
      GROUP BY type
    ");
    $catStmt->execute($paramsU);
    $cats = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $categories['income']  = isset($cats['income'])  ? explode('||',$cats['income'])  : ['ขายน้ำมัน'];
    $categories['expense'] = isset($cats['expense']) ? explode('||',$cats['expense']) : ['ซื้อน้ำมันเข้าคลัง','ซื้อน้ำมันเข้าถัง'];

    $sumOuter = $pdo->prepare("
      SELECT
        COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) ti,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) te,
        COUNT(*) cnt
      FROM ( $unionSQL ) s
      $w
    ");
    $sumOuter->execute($p);
    $s = $sumOuter->fetch(PDO::FETCH_ASSOC);
    $total_income = (float)$s['ti'];
    $total_expense = (float)$s['te'];
    $total_transactions = (int)$s['cnt'];
    $net_profit = $total_income - $total_expense;
  }
} catch (Throwable $e) {
  $transactions = [[
    'id'=>'DB-ERR','date'=>date('Y-m-d H:i:s'),'type'=>'expense','category'=>'Error',
    'description'=>'ไม่สามารถโหลดข้อมูลได้: '.$e->getMessage(),'amount'=>0,'reference'=>'','created_by'=>'System'
  ]];
  $categories = ['income'=>['ขายน้ำมัน'],'expense'=>['Error']];
}

/* ===== ดึง "รายการขาย" แยกต่างหาก ===== */
$sales_rows = []; $sales_total=0.0; $sales_count=0;
try {
  $sw = "WHERE 1=1"; $sp=[];
  if ($has_sales_station) { $sw .= " AND s.station_id=:sid"; $sp[':sid']=$stationId; }

  // ❌ ลบ 3 บรรทัดเงื่อนไขวันที่เดิมทิ้งไป
  // if ($rangeFromStr && $rangeToStr) { ... }
  // elseif ($rangeFromStr)            { ... }
  // elseif ($rangeToStr)              { ... }

  $ss = $pdo->prepare("
    SELECT s.sale_date AS date, s.sale_code AS code, s.total_amount AS amount,
           CONCAT('ขายเชื้อเพลิง (', COALESCE(s.payment_method,''), ')') AS description,
           COALESCE(u.full_name,'-') AS created_by
    FROM sales s
    LEFT JOIN (
      SELECT sale_id, MIN(user_id) AS user_id
      FROM fuel_moves
      WHERE type='sale_out' AND sale_id IS NOT NULL
      GROUP BY sale_id
    ) fm ON fm.sale_id = s.id
    LEFT JOIN users u ON u.id = fm.user_id
    $sw
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT 5000
  ");
  $ss->execute($sp);
  $sales_rows = $ss->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // รวมทุกบิลทั้งหมด (ไม่ผูกช่วง)
  $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) s, COUNT(*) c FROM sales s $sw");
  $st->execute($sp);
  [$sales_total, $sales_count] = $st->fetch(PDO::FETCH_NUM) ?: [0,0];
} catch (Throwable $e) {
  $sales_rows = []; $sales_total = 0; $sales_count = 0;
}

/* ===== ดึง "รายการจ่าย" (ทั้งหมด) — แยก 2 คิวรีแล้วรวม ===== */
$pay_rows = []; $pay_total=0.0; $pay_count=0;
try {
  if (method_exists($pdo,'setAttribute')) { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }

  // 1) รับเข้าคลัง (ไม่มี station_id → ไม่กรองสถานี)
  $qR = $pdo->prepare("
    SELECT
      'RCV' AS origin,
      fr.received_date AS date,
      CAST(fr.id AS CHAR) AS code,
      CONCAT('รับเข้าคลัง ', COALESCE(fp.fuel_name,''), COALESCE(CONCAT(' จาก ', s2.supplier_name), '')) AS description,
      (COALESCE(fr.cost,0) * COALESCE(fr.amount,0)) AS amount,
      COALESCE(u2.full_name,'-') AS created_by
    FROM fuel_receives fr
    LEFT JOIN (SELECT fuel_id, MAX(fuel_name) fuel_name FROM fuel_prices GROUP BY fuel_id) fp ON fp.fuel_id = fr.fuel_id
    LEFT JOIN suppliers s2 ON s2.supplier_id = fr.supplier_id
    LEFT JOIN users u2 ON u2.id = fr.created_by
    ORDER BY fr.received_date DESC, fr.id DESC
    -- LIMIT 5000  -- ถ้าข้อมูลเยอะมาก ค่อยเปิดบรรทัดนี้
  ");
  $qR->execute();
  $rcvRows = $qR->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // รวมยอดรับเข้าคลัง (ทั้งหมด)
  $sumR = $pdo->query("
    SELECT COALESCE(SUM(COALESCE(fr.cost,0)*COALESCE(fr.amount,0)),0)
    FROM fuel_receives fr
  ")->fetchColumn();
  $pay_total += (float)$sumR;

  // 2) รับเข้าถัง (มี station_id → กรองสถานี)
  $qL = $pdo->prepare("
    SELECT
      'LOT' AS origin,
      l.received_at AS date,
      l.lot_code AS code,
      CONCAT('รับเข้าถัง ', COALESCE(t.code,'')) AS description,
      COALESCE(l.initial_total_cost,
               (COALESCE(l.corrected_liters, l.observed_liters) * (COALESCE(l.unit_cost,0)+COALESCE(l.tax_per_liter,0))) + COALESCE(l.other_costs,0)
      ) AS amount,
      COALESCE(u3.full_name,'-') AS created_by
    FROM fuel_lots l
    LEFT JOIN fuel_tanks t ON t.id = l.tank_id
    LEFT JOIN users u3 ON u3.id = l.created_by
    WHERE l.station_id = :sid
    ORDER BY l.received_at DESC, l.id DESC
    -- LIMIT 5000
  ");
  $qL->execute([':sid'=>$stationId]);
  $lotRows = $qL->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // รวมยอดเข้าถัง (ทั้งหมด เฉพาะสถานีนี้)
  $sumL = $pdo->prepare("
    SELECT COALESCE(SUM(
      COALESCE(initial_total_cost,
               (COALESCE(corrected_liters, observed_liters) * (COALESCE(unit_cost,0)+COALESCE(tax_per_liter,0))) + COALESCE(other_costs,0)
      )
    ),0)
    FROM fuel_lots
    WHERE station_id = :sid
  ");
  $sumL->execute([':sid'=>$stationId]);
  $pay_total += (float)$sumL->fetchColumn();

  // รวม + เรียง
  $pay_rows = array_merge($rcvRows, $lotRows);
  usort($pay_rows, function($a,$b){
    $ka = ($a['date'] ?? '').'|'.($a['code'] ?? '');
    $kb = ($b['date'] ?? '').'|'.($b['code'] ?? '');
    return strcmp($kb, $ka); // DESC
  });
  $pay_count = count($pay_rows);

} catch (Throwable $e) {
  $pay_rows=[]; $pay_total=0; $pay_count=0;
}

/* ===== กราฟแนวโน้ม (รายวัน) ===== */
$labels = []; $seriesIncome = []; $seriesExpense = [];
try {
  if ($rangeFromStr && $rangeToStr) { $start = new DateTime($rangeFromStr); $end = new DateTime($rangeToStr); }
  else { $start = (new DateTime('today'))->modify('-6 day'); $end = new DateTime('today'); }
  $days=[]; $cursor=clone $start; 
  while ($cursor <= $end) { $key=$cursor->format('Y-m-d'); $days[$key]=['income'=>0.0,'expense'=>0.0]; $cursor->modify('+1 day'); }

  if ($has_ft) {
    $q = $pdo->prepare("
      SELECT DATE(transaction_date) d,
             COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) inc,
             COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) exp
      FROM financial_transactions
      ".($ft_has_station?" WHERE station_id=:sid ":" WHERE 1=1 ")."
        ".($rangeFromStr?" AND DATE(transaction_date) >= :f":"")."
        ".($rangeToStr  ?" AND DATE(transaction_date) <= :t":"")."
      GROUP BY DATE(transaction_date)
      ORDER BY DATE(transaction_date)
    ");
    $p_graph=[]; if($ft_has_station) $p_graph[':sid']=$stationId; if($rangeFromStr) $p_graph[':f']=$rangeFromStr; if($rangeToStr) $p_graph[':t']=$rangeToStr;
    $q->execute($p_graph);
    while ($r=$q->fetch(PDO::FETCH_ASSOC)) { $d=$r['d']; if(isset($days[$d])) { $days[$d]['income']=(float)$r['inc']; $days[$d]['expense']=(float)$r['exp']; } }
  } else {
    $stInc = $pdo->prepare("
      SELECT DATE(sale_date) d, SUM(total_amount) v
      FROM sales
      ".($has_sales_station?" WHERE station_id=:sid ":" WHERE 1=1 ")."
        ".($rangeFromStr?" AND DATE(sale_date) >= :f":"")."
        ".($rangeToStr  ?" AND DATE(sale_date) <= :t":"")."
      GROUP BY DATE(sale_date)
    ");
    $p_graph=[]; if($has_sales_station) $p_graph[':sid']=$stationId; if($rangeFromStr) $p_graph[':f']=$rangeFromStr; if($rangeToStr) $p_graph[':t']=$rangeToStr;
    $stInc->execute($p_graph);
    while ($r=$stInc->fetch(PDO::FETCH_ASSOC)) { $d=$r['d']; if(isset($days[$d])) $days[$d]['income']=(float)$r['v']; }

    $stExpR = $pdo->prepare("
      SELECT DATE(fr.received_date) d, SUM(COALESCE(fr.cost,0)*fr.amount) v
      FROM fuel_receives fr
      ".($has_fr_station? " WHERE fr.station_id=:sid " : " WHERE EXISTS (SELECT 1 FROM fuel_prices fp2 WHERE fp2.fuel_id=fr.fuel_id AND fp2.station_id=:sid) ")."
        ".($rangeFromStr?" AND DATE(fr.received_date) >= :f":"")."
        ".($rangeToStr  ?" AND DATE(fr.received_date) <= :t":"")."
      GROUP BY DATE(fr.received_date)
    ");
    $p_graph=[":sid"=>$stationId]; if($rangeFromStr) $p_graph[':f']=$rangeFromStr; if($rangeToStr) $p_graph[':t']=$rangeToStr;
    $stExpR->execute($p_graph);
    while ($r=$stExpR->fetch(PDO::FETCH_ASSOC)) { $d=$r['d']; if(isset($days[$d])) $days[$d]['expense'] += (float)$r['v']; }

    if (table_exists($pdo,'fuel_lots')) {
      if ($has_lot_station) {
        $stExpL = $pdo->prepare("
          SELECT DATE(received_at) d, SUM(initial_total_cost) v
          FROM fuel_lots
          WHERE station_id=:sid
            ".($rangeFromStr?" AND DATE(received_at) >= :f":"")."
            ".($rangeToStr  ?" AND DATE(received_at) <= :t":"")."
          GROUP BY DATE(received_at)
        ");
      } elseif ($has_tank_station) {
        $stExpL = $pdo->prepare("
          SELECT DATE(l.received_at) d, SUM(l.initial_total_cost) v
          FROM fuel_lots l
          JOIN fuel_tanks t ON t.id = l.tank_id
          WHERE t.station_id=:sid
            ".($rangeFromStr?" AND DATE(l.received_at) >= :f":"")."
            ".($rangeToStr  ?" AND DATE(l.received_at) <= :t":"")."
          GROUP BY DATE(l.received_at)
        ");
      } else {
        $stExpL = $pdo->prepare("
          SELECT DATE(received_at) d, SUM(initial_total_cost) v
          FROM fuel_lots
          WHERE 1=1
            ".($rangeFromStr?" AND DATE(received_at) >= :f":"")."
            ".($rangeToStr  ?" AND DATE(received_at) <= :t":"")."
          GROUP BY DATE(received_at)
        ");
      }
      $p_graph=[":sid"=>$stationId]; if($rangeFromStr) $p_graph[':f']=$rangeFromStr; if($rangeToStr) $p_graph[':t']=$rangeToStr;
      if (!$has_lot_station && !$has_tank_station) { unset($p_graph[':sid']); }
      $stExpL->execute($p_graph);
      while ($r=$stExpL->fetch(PDO::FETCH_ASSOC)) { $d=$r['d']; if(isset($days[$d])) $days[$d]['expense'] += (float)$r['v']; }
    }
  }
  foreach ($days as $d=>$v) { $labels[] = (new DateTime($d))->format('d/m'); $seriesIncome[] = round($v['income'],2); $seriesExpense[] = round($v['expense'],2); }
} catch (Throwable $e) {}

/* ===== กำไรขั้นต้น (Gross Profit) — ผูกช่วงเดียวกัน (ถ้ามี view) ===== */
$gp_labels = []; $gp_series = [];
if ($has_gpv) {
  try {
    $wd = ''; $pp=[':sid'=>$stationId];
    if ($rangeFromStr) { $wd.=" AND DATE(v.sale_date) >= :f"; $pp[':f']=$rangeFromStr; }
    if ($rangeToStr)   { $wd.=" AND DATE(v.sale_date) <= :t"; $pp[':t']=$rangeToStr; }

    $grp = $pdo->prepare("
      SELECT DATE(v.sale_date) d, COALESCE(SUM(v.total_amount - COALESCE(v.cogs,0)),0) gp
      FROM v_sales_gross_profit v
      JOIN sales s ON s.id = v.sale_id
      WHERE ".($has_sales_station? "s.station_id = :sid" : "1=1")." $wd
      GROUP BY DATE(v.sale_date)
      ORDER BY DATE(v.sale_date)
    "); $grp->execute($pp);
    $map = $grp->fetchAll(PDO::FETCH_KEY_PAIR);

    $sD = $rangeFromStr ? new DateTime($rangeFromStr) : (new DateTime('today'))->modify('-6 day');
    $eD = $rangeToStr   ? new DateTime($rangeToStr)   : new DateTime('today');
    $c = clone $sD;
    while ($c <= $eD) {
      $d = $c->format('Y-m-d');
      $gp_labels[] = $c->format('d/m');
      $gp_series[] = round($map[$d] ?? 0, 2);
      $c->modify('+1 day');
    }
  } catch (Throwable $e) { $has_gpv = false; error_log("GPV error: ".$e->getMessage()); }
}

/* ===== ยอดขายและกำไรรายปี (สำหรับปันผล) ===== */
$current_year = date('Y');
$yearly_sales = 0.0;
$yearly_cogs = 0.0;
$yearly_gross_profit = 0.0;
$yearly_other_income = 0.0; // <-- เพิ่มตัวแปร "รายได้อื่น"
$yearly_expenses = 0.0;
$yearly_net_profit = 0.0;
$yearly_available_for_dividend = 0.0;
$sql_yearly_sales = ''; // for debug
$sql_yearly_cogs = ''; // for debug
$sql_yearly_exp = ''; // for debug

try {
  $year_start = $current_year . '-01-01';
  $year_end = $current_year . '-12-31';
  
  // เตรียมพารามิเตอร์สำหรับ query
  $params_ys = [':start' => $year_start, ':end' => $year_end];
  if ($has_sales_station || $ft_has_station) { // <-- เพิ่มเงื่อนไขเผื่อใช้ sid
      $params_ys[':sid'] = $stationId;
  }

  // 1️⃣ ยอดขายรวม (จากตาราง sales)
  $sql_yearly_sales = "
    SELECT COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE ".($has_sales_station ? "station_id = :sid AND " : "")."
          DATE(sale_date) BETWEEN :start AND :end
  ";
  $stmt_ys = $pdo->prepare($sql_yearly_sales);
  $stmt_ys->execute($params_ys);
  $yearly_sales = (float)$stmt_ys->fetchColumn();

  // 2️⃣ ต้นทุนขาย (COGS) (จากตาราง sales)
  if ($has_gpv) {
    $sql_yearly_cogs = "
      SELECT COALESCE(SUM(v.cogs), 0) AS total
      FROM v_sales_gross_profit v
      JOIN sales s ON s.id = v.sale_id
      WHERE ".($has_sales_station ? "s.station_id = :sid AND " : "")."
            DATE(s.sale_date) BETWEEN :start AND :end
    ";
    $stmt_yc = $pdo->prepare($sql_yearly_cogs);
    $stmt_yc->execute($params_ys);
    $yearly_cogs = (float)$stmt_yc->fetchColumn();
  } else {
    $yearly_cogs = $yearly_sales * 0.85; // ประมาณการ (ถ้าไม่มี View)
  }
  
  $yearly_gross_profit = $yearly_sales - $yearly_cogs; // กำไรขั้นต้น (จากการขายน้ำมัน)

  // 3️⃣ ดึง "รายได้อื่น" และ "ค่าใช้จ่ายดำเนินงาน" จาก financial_transactions
  if ($has_ft) {
    // แก้ไข query ให้ดึงทั้ง income และ expense
    $sql_ft = "
      SELECT 
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS other_income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS other_expense
      FROM financial_transactions
      WHERE ".($ft_has_station ? "station_id = :sid AND " : "")."
            DATE(transaction_date) BETWEEN :start AND :end
    ";
    $stmt_ft = $pdo->prepare($sql_ft);
    $stmt_ft->execute($params_ys); // ใช้พารามิเตอร์เดียวกับ $params_ys
    $ft_results = $stmt_ft->fetch(PDO::FETCH_ASSOC);

    if ($ft_results) {
        $yearly_other_income = (float)$ft_results['other_income'];
        $yearly_expenses = (float)$ft_results['other_expense'];
    }
  } else {
    $yearly_other_income = 0.0;
    $yearly_expenses = 0.0;
  }

  // 4️⃣ กำไรสุทธิ (คำนวณใหม่)
  // กำไรสุทธิ = (กำไรขั้นต้นจากน้ำมัน) + (รายได้อื่น) - (ค่าใช้จ่ายดำเนินงาน)
  $yearly_net_profit = $yearly_gross_profit + $yearly_other_income - $yearly_expenses;
  
  // 5️⃣ วงเงินปันผล (ตามกฎหมายสหกรณ์)
  if ($yearly_net_profit > 0) {
    $reserve_fund = $yearly_net_profit * 0.10;
    $welfare_fund = $yearly_net_profit * 0.05;
    $yearly_available_for_dividend = $yearly_net_profit * 0.85;
  } else {
    $reserve_fund = 0;
    $welfare_fund = 0;
    $yearly_available_for_dividend = 0;
  }
  
} catch (Throwable $e) {
  error_log("Yearly calculation error: " . $e->getMessage());
  $yearly_sales = $yearly_cogs = $yearly_gross_profit = 0;
  $yearly_other_income = $yearly_expenses = $yearly_net_profit = $yearly_available_for_dividend = 0;
}
/* (สิ้นสุดส่วนที่แก้ไข) */


// 6️⃣ คำนวณปันผลต่อหุ้น
$total_shares = 0;
$dividend_per_share = 0;
try {
  // *** แก้ไข: นับหุ้นจากทุกตารางที่มีหุ้น ***
    $total_shares = 0;
    // 6.1) สมาชิก
    $member_shares_stmt = $pdo->query("SELECT COALESCE(SUM(shares), 0) FROM members WHERE is_active = 1");
    $total_shares += (int)$member_shares_stmt->fetchColumn();

    // 6.2) ผู้บริหาร
    try {
        $manager_shares_stmt = $pdo->query("SELECT COALESCE(SUM(shares), 0) FROM managers");
        $total_shares += (int)$manager_shares_stmt->fetchColumn();
    } catch (Throwable $e) { error_log("Manager shares error: " . $e->getMessage()); }

    // 6.3) กรรมการ
    try {
        $committee_shares_stmt = $pdo->query("SELECT COALESCE(SUM(shares), 0) FROM committees");
        $total_shares += (int)$committee_shares_stmt->fetchColumn();
    } catch (Throwable $e) { error_log("Committee shares error: " . $e->getMessage()); }
  
  if ($total_shares > 0 && $yearly_available_for_dividend > 0) {
    $dividend_per_share = $yearly_available_for_dividend / $total_shares;
  }
} catch (Throwable $e) {
  error_log("Shares calculation error: " . $e->getMessage());
}


// **DEBUG: แสดงข้อมูลเพื่อตรวจสอบ**
if (isset($_GET['debug'])) {
  echo '<div class="alert alert-warning mt-4">';
  echo '<h5>🔍 ข้อมูล Debug (เฉพาะโหมด ?debug=1)</h5>';
  echo '<table class="table table-sm">';
  echo '<tr><th>ตัวแปร</th><th>ค่า</th></tr>';
  echo '<tr><td>ช่วงเวลา</td><td>' . $year_start . ' ถึง ' . $year_end . '</td></tr>';
  echo '<tr><td>ยอดขาย</td><td>฿' . nf($yearly_sales) . '</td></tr>';
  echo '<tr><td>ต้นทุนขาย (COGS)</td><td>฿' . nf($yearly_cogs) . '</td></tr>';
  echo '<tr><td>กำไรขั้นต้น</td><td>฿' . nf($yearly_gross_profit) . '</td></tr>';
  echo '<tr><td>ค่าใช้จ่าย</td><td>฿' . nf($yearly_expenses) . '</td></tr>';
  echo '<tr><td>กำไรสุทธิ</td><td>฿' . nf($yearly_net_profit) . '</td></tr>';
  echo '<tr><td>มี v_sales_gross_profit</td><td>' . ($has_gpv ? 'YES' : 'NO') . '</td></tr>';
  echo '<tr><td>มี financial_transactions</td><td>' . ($has_ft ? 'YES' : 'NO') . '</td></tr>';
  echo '<tr><td>จำนวนหุ้นทั้งหมด</td><td>' . number_format($total_shares) . '</td></tr>';
  echo '</table>';
  
  // แสดง Query ที่ใช้
  echo '<h6>SQL Queries:</h6>';
  echo '<pre style="font-size:11px;">';
  echo "Sales Query:\n" . $sql_yearly_sales . "\n\n";
  if ($has_gpv) {
    echo "COGS Query:\n" . $sql_yearly_cogs . "\n\n";
  }
  if ($has_ft) {
    echo "Expenses Query:\n" . $sql_yearly_exp . "\n";
  } else {
    echo "Receives Query:\n" . $sql_rcv . "\n\n";
    echo "Lots Query:\n" . $sql_lot . "\n";
  }
  echo '</pre>';
  echo '</div>';
}
$role_th_map = ['admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>การเงินและบัญชี | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    .income-row { border-left: 4px solid #198754; }
    .expense-row{ border-left: 4px solid #dc3545; }
    .amount-income{ color:#198754; font-weight:600; } .amount-expense{ color:#dc3545; font-weight:600; }
    .transaction-type{ padding:4px 8px; border-radius:12px; font-size:.8rem; font-weight:500; }
    .type-income{ background:#d1edff; color:#0969da; } .type-expense{ background:#ffebe9; color:#cf222e; }
    .stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:1.5rem}
    @media (max-width:992px){.stats-grid{grid-template-columns:1fr}}
    .panel{background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:16px}
    .chart-container{position:relative;height:300px;width:100%}
    .muted{color:#6c757d}
    .alert-info .bg-white {
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
  }
  .alert-info h4, .alert-info h5 {
    font-weight: 600;
  }
  .text-muted.small {
    font-size: 0.85rem;
  }
  .filter-bar {
    background-color: #f8f9fa;
    padding: 1rem;
    border-radius: 0.5rem;
    border: 1px solid #dee2e6;
  }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar"><span class="navbar-toggler-icon"></span></button>
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

<!-- Offcanvas -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title"><?= htmlspecialchars($site_name) ?></h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body sidebar">
    <div class="side-brand mb-2"><h3><span>Admin</span></h3></div>
    <nav class="sidebar-menu">
      <a href="admin_dashboard.php"><i class="fa-solid fa-border-all"></i>ภาพรวม</a>
      <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
      <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
      <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
      <a href="employee.php"><i class="bi bi-person-badge-fill"></i>พนักงาน</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a class="active" href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
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
        <a href="inventory.php"><i class="bi bi-fuel-pump-fill"></i>จัดการน้ำมัน</a>
        <a href="manager.php"><i class="bi bi-shield-lock-fill"></i> ผู้บริหาร</a>
        <a href="committee.php"><i class="fas fa-users-cog"></i> กรรมการ</a>
        <a href="employee.php"><i class="bi bi-person-badge-fill"></i> พนักงาน</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a class="active" href="finance.php"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="report.php"><i class="fa-solid fa-chart-line"></i> รายงาน</a>
        <a href="setting.php"><i class="bi bi-gear-fill"></i> ตั้งค่า</a>
      </nav>
      <a class="logout" href="/index/logout.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <!-- Content -->
    <main class="col-lg-10 p-4">
      <div class="main-header ">
        <h2 class="mb-0"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</h2>
      </div>

      <!-- Date Range Filter -->
      <div class="filter-bar mb-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-md-3 col-lg-2">
                <label for="gp_from" class="form-label small">จากวันที่</label>
                <input type="date" class="form-control form-control-sm" name="gp_from" id="gp_from" value="<?= htmlspecialchars($rangeFromStr ?? '') ?>">
            </div>
            <div class="col-md-3 col-lg-2">
                <label for="gp_to" class="form-label small">ถึงวันที่</label>
                <input type="date" class="form-control form-control-sm" name="gp_to" id="gp_to" value="<?= htmlspecialchars($rangeToStr ?? '') ?>">
            </div>
            <div class="col-md-4 col-lg-6">
                <label class="form-label small d-none d-md-block">&nbsp;</label>
                <div class="btn-group w-100" role="group">
                    <a href="?gp_quick=7d" class="btn btn-sm btn-outline-secondary <?= $quick === '7d' ? 'active' : '' ?>">7 วัน</a>
                    <a href="?gp_quick=30d" class="btn btn-sm btn-outline-secondary <?= $quick === '30d' ? 'active' : '' ?>">30 วัน</a>
                    <a href="?gp_quick=this_month" class="btn btn-sm btn-outline-secondary <?= $quick === 'this_month' ? 'active' : '' ?>">เดือนนี้</a>
                    <a href="?gp_quick=last_month" class="btn btn-sm btn-outline-secondary <?= $quick === 'last_month' ? 'active' : '' ?>">เดือนก่อน</a>
                    <a href="?gp_quick=this_year" class="btn btn-sm btn-outline-secondary <?= $quick === 'this_year' ? 'active' : '' ?>">ปีนี้</a>
                </div>
            </div>
            <div class="col-md-2 col-lg-2"><button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i> กรอง</button></div>
        </form>
      </div>

      <!-- แผงสรุปรายปี (สำหรับปันผล) -->
      <div class="alert alert-info mt-4" role="alert">
        <div class="d-flex align-items-center mb-3">
          <i class="bi bi-calendar-check fs-4 me-3"></i>
          <div>
            <h5 class="mb-0">สรุปผลประกอบการปี <?= $current_year ?> (สำหรับพิจารณาปันผล)</h5>
            <small class="text-muted">ข้อมูล 1 มกราคม - 31 ธันวาคม <?= $current_year ?></small>
          </div>
        </div>
        
        <div class="row g-3">
          <div class="col-md-3">
            <div class="bg-white rounded p-3">
              <div class="text-muted small">ยอดขายรวม</div>
              <h4 class="mb-0 text-primary">฿<?= nf($yearly_sales) ?></h4>
            </div>
          </div>
          <div class="col-md-3">
            <div class="bg-white rounded p-3">
              <div class="text-muted small">กำไรขั้นต้น (ขาย - COGS)</div>
              <h4 class="mb-0 text-success">฿<?= nf($yearly_gross_profit) ?></h4>
            </div>
          </div>
          <div class="col-md-3">
            <div class="bg-white rounded p-3">
              <div class="text-muted small">ค่าใช้จ่ายดำเนินงาน</div>
              <h4 class="mb-0 text-danger">฿<?= nf($yearly_expenses) ?></h4>
            </div>
          </div>
          <div class="col-md-3">
            <div class="bg-white rounded p-3">
              <div class="text-muted small">กำไรสุทธิ</div>
              <h4 class="mb-0 <?= $yearly_net_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                ฿<?= nf($yearly_net_profit) ?>
              </h4>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3">
          <div class="col-md-4">
            <div class="bg-light rounded p-3">
              <div class="text-muted small mb-1">
                <i class="bi bi-piggy-bank me-1"></i>ทุนสำรอง (10%)
              </div>
              <h5 class="mb-0">฿<?= nf($yearly_net_profit * 0.10) ?></h5>
            </div>
          </div>
          <div class="col-md-4">
            <div class="bg-light rounded p-3">
              <div class="text-muted small mb-1">
                <i class="bi bi-heart me-1"></i>กองทุนสวัสดิการ (5%)
              </div>
              <h5 class="mb-0">฿<?= nf($yearly_net_profit * 0.05) ?></h5>
            </div>
          </div>
          <div class="col-md-4">
            <div class="bg-success bg-opacity-10 rounded p-3 border border-success">
              <div class="text-success small mb-1 fw-semibold">
                <i class="bi bi-gift me-1"></i>วงเงินปันผลได้ (85%)
              </div>
              <h4 class="mb-0 text-success">฿<?= nf($yearly_available_for_dividend) ?></h4>
            </div>
          </div>
        </div>

        <?php if ($total_shares > 0 && $yearly_available_for_dividend > 0): ?>
        <div class="mt-3 p-3 bg-white rounded border border-primary">
          <div class="row align-items-center">
            <div class="col-md-6">
              <div class="text-muted small">จำนวนหุ้นทั้งหมด</div>
              <h5 class="mb-0"><?= number_format($total_shares) ?> หุ้น</h5>
            </div>
            <div class="col-md-6">
              <div class="text-primary small fw-semibold">ปันผลต่อหุ้น (โดยประมาณ)</div>
              <h4 class="mb-0 text-primary">฿<?= nf($dividend_per_share, 2) ?> / หุ้น</h4>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="mt-3 d-flex gap-2">
          <a href="dividend.php" class="btn btn-primary">
            <i class="bi bi-gift me-2"></i>ไปหน้าจัดการปันผล
          </a>
          <button class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-2"></i>พิมพ์รายงาน
          </button>
        </div>
      </div>

      <?php if ($yearly_sales == 0): ?>
        <div class="alert alert-warning mt-3">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>ไม่พบข้อมูลยอดขาย:</strong> ยังไม่มีข้อมูลการขายในปี <?= $current_year ?> 
          หรือระบบยังไม่ได้บันทึกข้อมูล
        </div>
      <?php endif; ?>
      
       <!-- Summary cards (ช่วงที่เลือก) -->
       <div class="stats-grid mt-4">
        <div class="stat-card">
          <h6><i class="bi bi-currency-dollar me-2"></i>รายได้รวม (ช่วง)</h6>
          <h3 class="text-success">฿<?= nf($total_income) ?></h3>
        </div>
        <div class="stat-card">
          <h6><i class="bi bi-arrow-down-circle-fill me-2"></i>ค่าใช้จ่ายรวม (ช่วง)</h6>
          <h3 class="text-danger">฿<?= nf($total_expense) ?></h3>
        </div>
        <div class="stat-card">
          <h6><i class="bi bi-wallet2 me-2"></i>กำไรสุทธิ (ช่วง)</h6>
          <h3 class="<?= ($net_profit>=0?'text-success':'text-danger') ?>">฿<?= nf($net_profit) ?></h3>
          <p class="mb-0 muted"><?= (int)$total_transactions ?> รายการ</p>
        </div>
      </div>

      <!-- Charts (คำนวณจากช่วงเดียวกัน) -->
      <div class="row ">
        <div class="col-md-6 mb-4">
          <div class="panel">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-1"></i> สัดส่วนรายได้-ค่าใช้จ่าย (ช่วง)</h6>
            <div class="chart-container"><canvas id="pieChart"></canvas></div>
          </div>
        </div>
        <div class="col-md-6 mb-4">
          <div class="panel">
            <h6 class="mb-3"><i class="bi bi-graph-up me-1"></i> แนวโน้มการเงิน (ตามช่วงที่เลือก)</h6>
            <div class="chart-container"><canvas id="lineChart"></canvas></div>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="mt-4">
        <ul class="nav nav-tabs mb-3" id="inventoryTab" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stock-price-panel" type="button" role="tab">
              <i class="fa-solid fa-oil-can me-2"></i>รายการการเงิน
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#price-panel" type="button" role="tab">
              <i class="fa-solid fa-tags me-2"></i>รายการขาย
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#receive-panel" type="button" role="tab">
              <i class="fa-solid fa-gas-pump me-2"></i>รายการจ่าย
            </button>
          </li>
        </ul>

        <div class="tab-content" id="inventoryTabContent">
          <!-- TAB: รายการการเงิน (หลัก) -->
          <div class="tab-pane fade show active" id="stock-price-panel" role="tabpanel">
            <!-- Toolbar ฟิลเตอร์ -->
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="input-group" style="max-width:320px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="search" id="txnSearch" class="form-control" placeholder="ค้นหา: รหัส/รายละเอียด/อ้างอิง">
                </div>
                <select id="filterType" class="form-select" style="width:auto;">
                    <option value="">ทุกประเภท</option>
                    <option value="income">รายได้</option>
                    <option value="expense">ค่าใช้จ่าย</option>
                </select>
                <select id="filterCategory" class="form-select" style="width:auto;">
                    <option value="">ทุกหมวดหมู่</option>
                    <?php
                      $allCats = array_unique(array_merge($categories['income'],$categories['expense']));
                      sort($allCats, SORT_NATURAL | SORT_FLAG_CASE);
                      foreach($allCats as $c) echo '<option value="'.htmlspecialchars($c).'">'.htmlspecialchars($c).'</option>';
                    ?>
                </select>
                <input type="date" id="filterDate" class="form-control" style="width:auto;">
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary" id="btnTxnShowAll"><i class="bi bi-arrow-clockwise"></i></button>
                <?php if ($has_ft): ?>
              <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAddTransaction"><i class="bi bi-plus-circle me-1"></i> เพิ่มรายการ</button>
              <?php endif; ?>
              </div>
            </div>
            <div class="panel">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="fa-solid fa-list-ul me-1"></i> รายการการเงิน (ตารางหลัก)</h6>
                <?php if (!$has_ft): ?><span class="badge text-bg-secondary">โหมดอ่านอย่างเดียว (รวมจากยอดขาย/รับน้ำมัน)</span><?php endif; ?>
              </div>
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="txnTable">
                  <thead>
                    <tr>
                      <th>วันที่</th><th>รหัส</th><th>ประเภท</th><th>รายละเอียด</th>
                      <th class="text-end">จำนวนเงิน</th>
                      <th class="d-none d-xl-table-cell">ผู้บันทึก</th>
                      <th class="text-end">ใบเสร็จ</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($transactions as $tx): 
                      $isIncome = ($tx['type']==='income');
                      $id   = (string)$tx['id'];
                      $ref  = (string)($tx['reference'] ?? '');
                      $rtype = ''; $rcode = ''; $receiptUrl = '';

                      if (preg_match('/^SALE-(.+)$/', $id, $m)) {
                        $rtype = 'sale'; $rcode = $ref ?: $m[1];
                        $receiptUrl = 'sales_receipt.php?code=' . urlencode($rcode);
                      } elseif (preg_match('/^RCV-(\d+)/', $id, $m)) {
                        $rtype = 'receive'; $rcode = $ref ?: $m[1];
                        $receiptUrl = 'receive_view.php?id=' . urlencode($rcode);
                      } elseif (preg_match('/^LOT-(.+)$/', $id, $m)) {
                        $rtype = 'lot'; $rcode = $ref ?: $m[1];
                        $receiptUrl = 'lot_view.php?code=' . urlencode($rcode);
                      } elseif (preg_match('/^FT-(\d+)$/', $id, $m)) {
                        // FT-ตามด้วยตัวเลขล้วน -> ส่งเป็น id
                        $rtype = 'transaction';
                        $rcode = $m[1];
                        $receiptUrl = 'txn_receipt.php?id=' . urlencode($rcode);
                      } elseif (preg_match('/^FT-/', $id)) {
                        // โค้ดรูปแบบอื่น -> ส่งเป็น code
                        $rtype = 'transaction';
                        $rcode = $id;
                        $receiptUrl = 'txn_receipt.php?code=' . urlencode($rcode);
                      }                                           
                    ?>
                    <tr class="<?= $isIncome ? 'income-row' : 'expense-row' ?>"
                        data-id="<?= htmlspecialchars($tx['id']) ?>"
                        data-date="<?= htmlspecialchars(date('Y-m-d', strtotime($tx['date']))) ?>"
                        data-type="<?= htmlspecialchars($tx['type']) ?>"
                        data-category="<?= htmlspecialchars($tx['category'] ?? '') ?>"
                        data-description="<?= htmlspecialchars($tx['description']) ?>"
                        data-amount="<?= htmlspecialchars($tx['amount']) ?>"
                        data-created-by="<?= htmlspecialchars($tx['created_by']) ?>"
                        data-reference="<?= htmlspecialchars($tx['reference'] ?? '') ?>"
                        data-receipt-type="<?= htmlspecialchars($rtype) ?>"
                        data-receipt-code="<?= htmlspecialchars($rcode) ?>"
                        data-receipt-url="<?= htmlspecialchars($receiptUrl) ?>">
                      <td><?= htmlspecialchars(date('d/m/Y', strtotime($tx['date']))) ?></td>
                      <td><b><?= htmlspecialchars($tx['id']) ?></b></td>
                      <td><span class="transaction-type <?= $isIncome ? 'type-income' : 'type-expense' ?>"><?= $isIncome ? 'รายได้' : 'ค่าใช้จ่าย' ?></span></td>
                      <td><?= htmlspecialchars($tx['description']) ?></td>
                      <td class="text-end"><span class="<?= $isIncome ? 'amount-income' : 'amount-expense' ?>"><?= $isIncome ? '+' : '-' ?>฿<?= nf($tx['amount']) ?></span></td>
                      <td class="d-none d-xl-table-cell"><?= htmlspecialchars($tx['created_by']) ?></td>
                      <td class="text-end">
                        <div class="btn-group">
                          <button class="btn btn-sm btn-outline-secondary btnReceipt" title="ดูใบเสร็จ"><i class="bi bi-receipt"></i></button>
                          <?php if ($has_ft): ?>
                            <button class="btn btn-sm btn-outline-primary btnEdit" title="แก้ไข"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-outline-danger btnDel" title="ลบ"><i class="bi bi-trash"></i></button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- TAB: รายการขาย -->
          <div class="tab-pane fade" id="price-panel" role="tabpanel">
            <!-- Toolbar ฟิลเตอร์ -->
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
              <div class="d-flex flex-wrap gap-2">
                <div class="input-group" style="max-width:320px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="search" id="salesSearch" class="form-control" placeholder="ค้นหา: รหัส/รายละเอียด/ผู้บันทึก">
                </div>
                <input type="date" id="salesDate" class="form-control" style="max-width:160px;">
                <button id="salesShowAll" class="btn btn-outline-secondary me-1" >แสดงทั้งหมด</button>
              </div>
            </div>
            <div class="panel mt-4">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-cash-coin me-1"></i> รายการขาย</h6>
                <span class="muted">รวม <?= (int)$sales_count ?> รายการ | ยอดขายรวม ฿<?= nf($sales_total) ?></span>
              </div>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0" id="salesTable">
                  <thead><tr><th>วันที่</th><th>รหัสขาย</th><th>รายละเอียด</th><th class="text-end">จำนวนเงิน</th><th class="d-none d-lg-table-cell">ผู้บันทึก</th><th class="text-end">ใบเสร็จ</th></tr></thead>
                  <tbody>
                    <?php foreach($sales_rows as $r): ?>
                      <tr
                        data-receipt-type="sale"
                        data-receipt-code="<?= htmlspecialchars($r['code']) ?>"
                        data-receipt-url="sales_receipt.php?code=<?= urlencode($r['code']) ?>"
                        data-code="<?= htmlspecialchars($r['code']) ?>"
                        data-description="<?= htmlspecialchars($r['description']) ?>"
                        data-amount="<?= htmlspecialchars($r['amount']) ?>"
                        data-created-by="<?= htmlspecialchars($r['created_by']) ?>"
                        data-date="<?= htmlspecialchars(date('Y-m-d', strtotime($r['date']))) ?>"
                      >
                        <td><?= htmlspecialchars($r['date']) ?></td>
                        <td><b><?= htmlspecialchars($r['code']) ?></b></td>
                        <td><?= htmlspecialchars($r['description']) ?></td>
                        <td class="text-end"><span class="amount-income">+฿<?= nf($r['amount']) ?></span></td>
                        <td class="d-none d-lg-table-cell"><?= htmlspecialchars($r['created_by']) ?></td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-outline-secondary btnReceipt">
                            <i class="bi bi-receipt"></i>
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sales_rows)): ?>
                      <tr><td colspan="6" class="text-center text-muted">ไม่มีข้อมูลในช่วงที่เลือก</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- TAB: รายการจ่าย -->
          <div class="tab-pane fade" id="receive-panel" role="tabpanel">
            <!-- Toolbar ฟิลเตอร์ -->
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
              <div class="d-flex flex-wrap gap-2">
                <div class="input-group" style="max-width:320px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="search" id="paySearch" class="form-control" placeholder="ค้นหา: รหัส/รายละเอียด/ผู้บันทึก">
                </div>
                <input type="date" id="payDate" class="form-control" style="max-width:160px;">
                <button id="payShowAll" class="btn btn-outline-secondary me-1">แสดงทั้งหมด</button>
              </div>
            </div>
            <div class="panel mt-4">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-credit-card-2-back me-1"></i> รายการจ่าย: รับเข้าคลัง/เข้าถัง</h6>
                <span class="muted">รวม <?= (int)$pay_count ?> รายการ | ยอดจ่ายรวม ฿<?= nf($pay_total) ?></span>
              </div>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0" id="payTable">
                  <thead><tr><th>วันที่</th><th>ประเภท</th><th>รหัส</th><th>รายละเอียด</th><th class="text-end">จำนวนเงิน</th><th class="d-none d-lg-table-cell">ผู้บันทึก</th><th class="text-end">ใบเสร็จ</th></tr></thead>
                  <tbody>
                    <?php foreach($pay_rows as $r):
                      $rtype = ($r['origin']==='LOT' ? 'lot' : 'receive');
                      $rurl  = $rtype==='lot'
                        ? ('lot_view.php?code='.urlencode($r['code']))
                        : ('receive_view.php?id='.urlencode($r['code']));
                    ?>
                      <tr
                        data-receipt-type="<?= $rtype ?>"
                        data-receipt-code="<?= htmlspecialchars($r['code']) ?>"
                        data-receipt-url="<?= htmlspecialchars($rurl) ?>"
                        data-origin="<?= $r['origin']==='LOT' ? 'เข้าถัง' : 'เข้าคลัง' ?>"
                        data-code="<?= htmlspecialchars($r['code']) ?>"
                        data-description="<?= htmlspecialchars($r['description']) ?>"
                        data-amount="<?= htmlspecialchars($r['amount']) ?>"
                        data-created-by="<?= htmlspecialchars($r['created_by']) ?>"
                        data-date="<?= htmlspecialchars(date('Y-m-d', strtotime($r['date']))) ?>"
                      >
                        <td><?= htmlspecialchars($r['date']) ?></td>
                        <td><?= $r['origin']==='LOT' ? 'เข้าถัง' : 'เข้าคลัง' ?></td>
                        <td><b><?= htmlspecialchars($r['code']) ?></b></td>
                        <td><?= htmlspecialchars($r['description']) ?></td>
                        <td class="text-end"><span class="amount-expense">-฿<?= nf($r['amount']) ?></span></td>
                        <td class="d-none d-lg-table-cell"><?= htmlspecialchars($r['created_by']) ?></td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-outline-secondary btnReceipt">
                            <i class="bi bi-receipt"></i>
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                    <?php if (empty($pay_rows)): ?>
                      <tr><td colspan="7" class="text-center text-muted">ไม่มีข้อมูลในช่วงที่เลือก</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div><!-- /.tab-content -->
      </div><!-- /.mt-4 Tabs -->

    </main>
  </div>
</div>

<footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการการเงินและบัญชี</footer>

<!-- Modals -->
<div class="modal fade" id="modalAddTransaction" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="formAddTransaction" method="post" action="finance_create.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>เพิ่มรายการการเงิน</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <?php if (!$has_ft): ?><div class="alert alert-warning">โหมดอ่านอย่างเดียว ไม่สามารถเพิ่มรายการได้</div><?php endif; ?>
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label" for="addTransactionCode">รหัสรายการ (ไม่บังคับ)</label>
            <input type="text" class="form-control" name="transaction_code" id="addTransactionCode" placeholder="เว้นว่างเพื่อสร้างอัตโนมัติ" <?= $has_ft?'':'disabled' ?>>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="addTransactionDate">วันที่และเวลา</label>
            <input type="datetime-local" class="form-control" name="transaction_date" id="addTransactionDate" value="<?= date('Y-m-d\TH:i') ?>" required <?= $has_ft?'':'disabled' ?>>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="addType">ประเภท</label>
            <select name="type" id="addType" class="form-select" required <?= $has_ft?'':'disabled' ?>>
                <option value="">เลือกประเภท...</option>
                <option value="income">รายได้ (Income)</option>
                <option value="expense">ค่าใช้จ่าย (Expense)</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="addCategory">หมวดหมู่</label>
            <input type="text" class="form-control" name="category" id="addCategory" required list="categoryList" placeholder="เช่น เงินลงทุน, เงินเดือน, ค่าไฟ" <?= $has_ft?'':'disabled' ?>>
            <datalist id="categoryList">
                <option value="เงินลงทุน">
                <option value="รายได้อื่น">
                <option value="เงินเดือน">
                <option value="ค่าสาธารณูปโภค">
                <option value="ต้นทุนซื้อน้ำมัน">
                <?php
                  // ดึงหมวดหมู่ที่มีอยู่จาก PHP (คำนวณไว้แล้ว)
                  $allCatsModal = array_unique(array_merge($categories['income'],$categories['expense']));
                  foreach($allCatsModal as $c) echo '<option value="'.htmlspecialchars($c).'">';
                ?>
            </datalist>
          </div>
          <div class="col-12">
            <label class="form-label" for="addDescription">รายละเอียด</label>
            <input type="text" class="form-control" name="description" id="addDescription" required <?= $has_ft?'':'disabled' ?>>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="addAmount">จำนวนเงิน (บาท)</label>
            <input type="number" class="form-control" name="amount" id="addAmount" step="0.01" min="0" required <?= $has_ft?'':'disabled' ?>>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="addReference">อ้างอิง (ถ้ามี)</label>
            <input type="text" class="form-control" name="reference_id" id="addReference" <?= $has_ft?'':'disabled' ?>>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary" type="submit" <?= $has_ft?'':'disabled' ?>><i class="bi bi-save2 me-1"></i> บันทึก</button><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalEditTransaction" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="formEditTransaction" method="post" action="finance_edit.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขรายการการเงิน</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <?php if (!$has_ft): ?><div class="alert alert-warning">โหมดอ่านอย่างเดียว ไม่สามารถแก้ไขได้</div><?php endif; ?>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label">รหัสรายการ</label><input type="text" class="form-control" name="transaction_code" id="editTransactionCode" readonly></div>
          <div class="col-sm-6"><label class="form-label">วันที่</label><input type="datetime-local" class="form-control" name="transaction_date" id="editDate" required <?= $has_ft?'':'disabled' ?>></div>
          <div class="col-sm-6"><label class="form-label">ประเภท</label><select name="type" id="editType" class="form-select" required <?= $has_ft?'':'disabled' ?>><option value="income">รายได้</option><option value="expense">ค่าใช้จ่าย</option></select></div>
          <div class="col-sm-6"><label class="form-label">หมวดหมู่</label><input type="text" class="form-control" name="category" id="editCategory" required list="categoryList" <?= $has_ft?'':'disabled' ?>></div>
          <div class="col-12"><label class="form-label">รายละเอียด</label><input type="text" class="form-control" name="description" id="editDescription" required <?= $has_ft?'':'disabled' ?>></div>
          <div class="col-sm-6"><label class="form-label">จำนวนเงิน (บาท)</label><input type="number" class="form-control" name="amount" id="editAmount" step="0.01" min="0" required <?= $has_ft?'':'disabled' ?>></div>
          <div class="col-sm-6"><label class="form-label">อ้างอิง (ถ้ามี)</label><input type="text" class="form-control" name="reference_id" id="editReference" <?= $has_ft?'':'disabled' ?>></div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary" type="submit" <?= $has_ft?'':'disabled' ?>><i class="bi bi-save2 me-1"></i> บันทึก</button><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalSummary" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-calculator me-2"></i>สรุปยอด (ช่วงที่เลือก)</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row text-center">
          <div class="col-4"><h6 class="text-success">รายได้รวม</h6><h4 class="text-success">฿<?= nf($total_income) ?></h4></div>
          <div class="col-4"><h6 class="text-danger">ค่าใช้จ่ายรวม</h6><h4 class="text-danger">฿<?= nf($total_expense) ?></h4></div>
          <div class="col-4"><h6 class="<?= $net_profit>=0?'text-success':'text-danger' ?>">กำไรสุทธิ</h6><h4 class="<?= $net_profit>=0?'text-success':'text-danger' ?>">฿<?= nf($net_profit) ?></h4></div>
        </div>
        <hr><p class="muted mb-0"><i class="bi bi-info-circle me-1"></i>ข้อมูลคำนวณตามช่วงวันที่ที่เลือก</p>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalDeleteTransaction" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="deleteForm" method="POST" action="finance_delete.php">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="transaction_code" id="deleteFormCode">
      <div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>ลบรายการการเงิน</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">ต้องการลบรายการ <b id="delTxnId"></b> ใช่หรือไม่?<br><small class="muted">การดำเนินการนี้ไม่สามารถยกเลิกได้</small></div>
      <div class="modal-footer"><button class="btn btn-danger" type="submit"><i class="bi bi-check2-circle me-1"></i> ยืนยันลบ</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button></div>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="liveToast" class="toast align-items-center text-bg-dark border-0" role="status" aria-live="polite">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">ดำเนินการสำเร็จ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function(){
  const canEdit = <?= $has_ft ? 'true' : 'false' ?>;

    // Charts
  const pieCtx = document.getElementById('pieChart')?.getContext('2d');
  const lineCtx = document.getElementById('lineChart')?.getContext('2d');
  if (pieCtx) new Chart(pieCtx, { type: 'doughnut', data: { labels: ['รายได้','ค่าใช้จ่าย'], datasets: [{ data: [<?= json_encode(round($total_income,2)) ?>, <?= json_encode(round($total_expense,2)) ?>],  borderWidth: 1 }] }, options: { responsive:true, maintainAspectRatio: false, plugins:{ legend:{ position:'bottom' } } } });
  if (lineCtx) new Chart(lineCtx, { type: 'line', data: { labels: <?= json_encode($labels) ?>, datasets: [ { label: 'รายได้', data: <?= json_encode($seriesIncome) ?>, tension:.3, fill:true }, { label: 'ค่าใช้จ่าย', data: <?= json_encode($seriesExpense) ?>,  tension:.3, fill:true } ]}, options: { responsive:true, maintainAspectRatio: false, scales:{ y:{ beginAtZero:true } } } });

  // เส้นทางใบเสร็จ
  const receiptRoutes = {
    sale:   code => `sales_receipt.php?code=${encodeURIComponent(code)}`,
    receive:id   => `receive_view.php?id=${encodeURIComponent(id)}`,
    lot:    code => `lot_view.php?code=${encodeURIComponent(code)}`,
    transaction: token => /^\d+$/.test(String(token))
    ? `txn_receipt.php?id=${encodeURIComponent(token)}`
    : `txn_receipt.php?code=${encodeURIComponent(token)}`
  };

  // ปุ่มใบเสร็จ (ทุกตาราง)
  document.querySelectorAll('.btnReceipt').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const tr = btn.closest('tr');
      const direct = tr?.dataset?.receiptUrl;
      const type   = tr?.dataset?.receiptType;
      const code   = tr?.dataset?.receiptCode;
      let url = (direct && direct.trim()) ? direct.trim() : '';
      if (!url && type && code && receiptRoutes[type]) url = receiptRoutes[type](code);
      if (!url) return alert('ยังไม่มีลิงก์ใบเสร็จสำหรับรายการนี้');
      window.open(url, '_blank');
    });
  });

  // ===== ฟิลเตอร์ฝั่งไคลเอนต์สำหรับตารางหลัก =====
  const q = document.getElementById('txnSearch');
  const fType = document.getElementById('filterType');
  const fCat  = document.getElementById('filterCategory');
  const fDate = document.getElementById('filterDate');
  const tbody = document.querySelector('#txnTable tbody');
  
  function normalize(s){ return (s||'').toString().toLowerCase(); }
  function applyFilters(){
    const text = q ? normalize(q.value) : '';
    const type = fType ? fType.value : '';
    const cat  = fCat ? fCat.value : '';
    const date = fDate ? fDate.value : '';
  
  document.getElementById('btnTxnShowAll')?.addEventListener('click', ()=>{
  if (q) q.value = '';
  if (fType) fType.value = '';
  if (fCat) fCat.value = '';
  if (fDate) fDate.value = '';
  applyFilters();
  });
  
    tbody.querySelectorAll('tr').forEach(tr=>{
      const d = tr.dataset; let ok = true;
      if (type && d.type !== type) ok = false;
      if (cat  && d.category !== cat) ok = false;
      if (date) {
        const rowDate = String(d.date || '').slice(0,10);
        if (rowDate !== date) ok = false;
      }
      if (text) {
        const blob = (d.id+' '+(d.category||'')+' '+(d.description||'')+' '+(d.reference||'')+' '+(d.createdBy||'')).toLowerCase();
        if (!blob.includes(text)) ok = false;
      }
      tr.style.display = ok? '' : 'none';
    });
  }
  [q,fType,fCat,fDate].forEach(el=>el && el.addEventListener('input', applyFilters));
  if (tbody) applyFilters();

  // CSV / Print / Report
  function exportCSV(){
    const rows = [['วันที่','รหัส','ประเภท','หมวดหมู่','รายละเอียด','จำนวนเงิน','อ้างอิง','ผู้บันทึก']];
    tbody.querySelectorAll('tr').forEach(tr=>{
      if (tr.style.display==='none') return;
      const d = tr.dataset; const date = new Date(d.date);
      const dd = String(date.getDate()).padStart(2,'0')+'/'+String(date.getMonth()+1).padStart(2,'0')+'/'+date.getFullYear();
      const amt = parseFloat(d.amount||'0');
      rows.push([dd, d.id, d.type==='income'?'รายได้':'ค่าใช้จ่าย', d.category||'', d.description||'', amt.toFixed(2), d.reference||'', d.createdBy||'']);
    });
    const csv = rows.map(r=>r.map(x=>`"${String(x).replace(/"/g,'""')}"`).join(',')).join('\r\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'finance_export.csv'; a.click(); URL.revokeObjectURL(a.href);
  }
  document.getElementById('btnExportCSV')?.addEventListener('click', exportCSV);
  document.getElementById('btnExportData')?.addEventListener('click', exportCSV);
  document.getElementById('btnPrint')?.addEventListener('click', ()=>window.print());
  document.getElementById('btnGenerateReport')?.addEventListener('click', ()=>alert('ช่วงวันที่ของรายงาน = ช่วงที่เลือกบนหน้านี้ — ถ้าต้องการ PDF บอกได้เลย!'));

   // ===== ฟิลเตอร์สำหรับ "รายการขาย" และ "รายการจ่าย" =====
   function wireSimpleTable({tableId, searchId, dateId, resetId}) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const q = document.getElementById(searchId);
  const d = document.getElementById(dateId);
  const resetBtn = document.getElementById(resetId);

  const norm = s => (s||'').toString().toLowerCase();
  const toYmd = raw => String(raw || '').slice(0,10);

  function apply(){
    const text = norm(q?.value || '');
    const date = d?.value || '';
    tbody.querySelectorAll('tr').forEach(tr=>{
      const ds = tr.dataset || {};
      let ok = true;

      if (date) ok = toYmd(ds.date) === date;

      if (ok && text) {
        const blob = [
          ds.code, ds.description, ds.createdBy, ds.origin, ds.amount,
          tr.textContent
        ].join(' ').toLowerCase();
        ok = blob.includes(text);
      }
      tr.style.display = ok ? '' : 'none';
    });
  }

  q && q.addEventListener('input', apply);
  d && d.addEventListener('input', apply);
  resetBtn && resetBtn.addEventListener('click', ()=>{
    if (q) q.value = '';
    if (d) d.value = '';
    apply();
  });

  apply();
}

  // ผูกใช้งานกับ 2 ตาราง
  wireSimpleTable({ tableId:'salesTable', searchId:'salesSearch', dateId:'salesDate', resetId:'salesShowAll' });
  wireSimpleTable({ tableId:'payTable',   searchId:'paySearch',   dateId:'payDate',   resetId:'payShowAll'   });


  // Toast (ถ้าต้องใช้)
  const toastEl = document.getElementById('liveToast'); const toastMsg = document.getElementById('toastMsg');
  const toast = toastEl ? new bootstrap.Toast(toastEl, {delay:2000}) : null;
  function showToast(msg){ if(!toast) return alert(msg); toastMsg.textContent=msg; toast.show(); }

  // Modal Handlers
  if (canEdit) {
    document.querySelectorAll('#txnTable .btnEdit').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const tr = btn.closest('tr'); const d = tr.dataset;
        const dt = new Date(d.date);
        const dtString = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0') + 'T' + String(dt.getHours()).padStart(2,'0') + ':' + String(dt.getMinutes()).padStart(2,'0');
        
        document.getElementById('editTransactionCode').value = d.id;
        document.getElementById('editDate').value = dtString;
        document.getElementById('editType').value = d.type;
        document.getElementById('editCategory').value = d.category || '';
        document.getElementById('editDescription').value = d.description || '';
        document.getElementById('editAmount').value = parseFloat(d.amount||'0').toFixed(2);
        document.getElementById('editReference').value = d.reference || '';
        new bootstrap.Modal(document.getElementById('modalEditTransaction')).show();
      });
    });

    document.querySelectorAll('#txnTable .btnDel').forEach(btn=>{
      btn.addEventListener('click', ()=>{ 
        const tr=btn.closest('tr'); 
        const code = tr.dataset.id;
        document.getElementById('delTxnId').textContent = code; 
        document.getElementById('deleteFormCode').value = code;
        new bootstrap.Modal(document.getElementById('modalDeleteTransaction')).show(); 
      });
    });
  }

  // กราฟ Gross Profit (ถ้ามี)
  const gpCanvas = document.getElementById('gpBarChart');
  if (gpCanvas) {
    const gpLabels = <?= json_encode($gp_labels, JSON_UNESCAPED_UNICODE) ?>;
    const gpSeries = <?= json_encode($gp_series) ?>;
    new Chart(gpCanvas, {
      type: 'bar',
      data: { labels: gpLabels, datasets: [{ label: 'กำไรขั้นต้น', data: gpSeries }] },
      options: { responsive:true, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true } } }
    });
  }

  // Handle toast messages from URL
  const urlParams = new URLSearchParams(window.location.search);
  const okMsg = urlParams.get('ok');
  const errMsg = urlParams.get('err');
  if (okMsg) { showToast(okMsg); window.history.replaceState({}, document.title, window.location.pathname + window.location.hash); }
  if (errMsg) { showToast(errMsg, false); window.history.replaceState({}, document.title, window.location.pathname + window.location.hash); }

})();
</script>
</body>
</html>
