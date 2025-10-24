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

/* ===== [แก้ไข] ดึง "รายการขาย" (ผูกกับช่วงวันที่ส่วนกลาง) ===== */
$sales_rows = []; $sales_total=0.0; $sales_count=0;
try {
  $sw = "WHERE 1=1"; $sp=[];
  if ($has_sales_station) { $sw .= " AND s.station_id=:sid"; $sp[':sid']=$stationId; }

  // [!!!] ผูกกับตัวกรองวันที่ส่วนกลาง
  if ($rangeFromStr) { $sw.=" AND DATE(s.sale_date) >= :f"; $sp[':f']=$rangeFromStr; }
  if ($rangeToStr)   { $sw.=" AND DATE(s.sale_date) <= :t"; $sp[':t']=$rangeToStr; }
  
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

  // [!!!] ยอดรวมจะถูกกรองตาม $sw (ซึ่งตอนนี้มีวันที่แล้ว)
  $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) s, COUNT(*) c FROM sales s $sw");
  $st->execute($sp);
  [$sales_total, $sales_count] = $st->fetch(PDO::FETCH_NUM) ?: [0,0];
} catch (Throwable $e) {
  $sales_rows = []; $sales_total = 0; $sales_count = 0;
}

/* ===== [แก้ไข] ดึง "รายการจ่าย" (ผูกกับช่วงวันที่ส่วนกลาง) ===== */
$pay_rows = []; $pay_total=0.0; $pay_count=0;
try {
  if (method_exists($pdo,'setAttribute')) { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }

  // 1) รับเข้าคลัง (Receives)
  $paramsR = [];
  $whereR = "WHERE 1=1";
  // [!!!] ผูกกับตัวกรองวันที่ส่วนกลาง
  if ($rangeFromStr) { $whereR.=" AND DATE(fr.received_date) >= :f"; $paramsR[':f']=$rangeFromStr; }
  if ($rangeToStr)   { $whereR.=" AND DATE(fr.received_date) <= :t"; $paramsR[':t']=$rangeToStr; }
  
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
    $whereR
    ORDER BY fr.received_date DESC, fr.id DESC
  ");
  $qR->execute($paramsR);
  $rcvRows = $qR->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // [!!!] รวมยอดรับเข้าคลัง (ตามช่วงวันที่)
  $sumR = $pdo->prepare("
    SELECT COALESCE(SUM(COALESCE(fr.cost,0)*COALESCE(fr.amount,0)),0)
    FROM fuel_receives fr $whereR
  ");
  $sumR->execute($paramsR);
  $pay_total += (float)$sumR->fetchColumn();

  // 2) รับเข้าถัง (Lots)
  $paramsL = [':sid'=>$stationId];
  $whereL = "WHERE l.station_id = :sid";
  // [!!!] ผูกกับตัวกรองวันที่ส่วนกลาง
  if ($rangeFromStr) { $whereL.=" AND DATE(l.received_at) >= :f"; $paramsL[':f']=$rangeFromStr; }
  if ($rangeToStr)   { $whereL.=" AND DATE(l.received_at) <= :t"; $paramsL[':t']=$rangeToStr; }

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
    $whereL
    ORDER BY l.received_at DESC, l.id DESC
  ");
  $qL->execute($paramsL);
  $lotRows = $qL->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // [!!!] รวมยอดเข้าถัง (ตามช่วงวันที่)
  $sumL = $pdo->prepare("
    SELECT COALESCE(SUM(
      COALESCE(initial_total_cost,
               (COALESCE(corrected_liters, observed_liters) * (COALESCE(unit_cost,0)+COALESCE(tax_per_liter,0))) + COALESCE(other_costs,0)
      )
    ),0)
    FROM fuel_lots l
    $whereL
  ");
  $sumL->execute($paramsL);
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
  error_log("Payment rows error: " . $e->getMessage());
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


/* ===== [!!!] ลบส่วนการคำนวณปันผลรายปี (Yearly Dividend) ออก ===== */
// ...
// โค้ดส่วนปันผล (ประมาณ 140 บรรทัด) ถูกลบออกจากหน้านี้
// เพื่อเพิ่มความเร็วในการโหลดและลดความสับสน
// ...


// **DEBUG: แสดงข้อมูลเพื่อตรวจสอบ**
if (isset($_GET['debug'])) {
  echo '<div class="alert alert-warning mt-4">';
  echo '<h5>🔍 ข้อมูล Debug (เฉพาะโหมด ?debug=1)</h5>';
  echo '<table class="table table-sm">';
  echo '<tr><th>ตัวแปร</th><th>ค่า</th></tr>';
  echo '<tr><td>ช่วงเวลา</td><td>' . htmlspecialchars($rangeFromStr ?? 'N/A') . ' ถึง ' . htmlspecialchars($rangeToStr ?? 'N/A') . '</td></tr>';
  echo '<tr><td>รายได้ (ช่วง)</td><td>฿' . nf($total_income) . '</td></tr>';
  echo '<tr><td>ค่าใช้จ่าย (ช่วง)</td><td>฿' . nf($total_expense) . '</td></tr>';
  echo '<tr><td>กำไรสุทธิ (ช่วง)</td><td>฿' . nf($net_profit) . '</td></tr>';
  echo '<tr><td>ยอดขาย (ช่วง)</td><td>฿' . nf($sales_total) . ' (' . (int)$sales_count . ' บิล)</td></tr>';
  echo '<tr><td>ยอดจ่าย (ช่วง)</td><td>฿' . nf($pay_total) . ' (' . (int)$pay_count . ' บิล)</td></tr>';
  echo '<tr><td>มี v_sales_gross_profit</td><td>' . ($has_gpv ? 'YES' : 'NO') . '</td></tr>';
  echo '<tr><td>มี financial_transactions</td><td>' . ($has_ft ? 'YES' : 'NO') . '</td></tr>';
  echo '</table>';
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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>การเงินและบัญชี | <?= htmlspecialchars($site_name) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />

  <style>
    :root{
      --header-h: 56px;
      --sticky-bg: #ffffffcc;
      --radius: 12px;
    }
    body{ font-family: "Noto Sans Thai","Prompt",system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif; }
    .navbar{ position: sticky; top: 0; z-index: 1030; }
    /* Sticky filter bar */
    .filter-bar{
      position: sticky; top: var(--header-h); z-index: 1020;
      background: var(--sticky-bg); backdrop-filter: saturate(180%) blur(8px);
      border-bottom: 1px solid #e9ecef; padding: .75rem .75rem 1rem .75rem; margin-left: -0.75rem; margin-right:-0.75rem;
    }
    @media (min-width:992px){ .filter-bar{ top: 0; border-radius: var(--radius); margin: 0 0 1rem 0; } }

    /* Cards grid */
    .stats-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:1rem }
    @media (max-width: 992px){ .stats-grid{ grid-template-columns:1fr } }
    .stat-card{ background:#fff;border:1px solid #e9ecef;border-radius:var(--radius);padding:1rem }
    .muted{ color:#6c757d }

    /* Panels & tables */
    .panel{ background:#fff;border:1px solid #e9ecef;border-radius:var(--radius);padding:1rem }
    .table-sticky thead th{ position: sticky; top: 0; z-index: 1; background: #fff; box-shadow: inset 0 -1px 0 #e9ecef; }
    .table thead th{ white-space: nowrap; }
    .table-hover>tbody>tr:hover{ background:#f7fbff; }
    .income-row { border-left: 4px solid #198754; }
    .expense-row{ border-left: 4px solid #dc3545; }
    .amount-income{ color:#198754; font-weight:600; }
    .amount-expense{ color:#dc3545; font-weight:600; }
    .transaction-type{ padding:4px 8px; border-radius:12px; font-size:.8rem; font-weight:500; }
    .type-income{ background:#d1edff; color:#0969da; } 
    .type-expense{ background:#ffebe9; color:#cf222e; }

    /* Compact mode (remembered) */
    .compact .table td, .compact .table th{ padding:.45rem .5rem; font-size:.92rem }
    .compact .form-control, .compact .form-select, .compact .btn{ padding:.375rem .5rem; font-size:.92rem }
    .compact .btn-group>.btn{ padding:.375rem .5rem }

    /* Chips */
    .chip.btn{ border-radius: 999px; }
    .chip.btn.active{ background:#0d6efd; color:#fff; border-color:#0d6efd; }

    /* Floating action button (mobile) */
    .fab{ position: fixed; right: 16px; bottom: 16px; z-index: 1050; border-radius: 999px; padding:.85rem 1rem; box-shadow: 0 10px 20px rgba(13,110,253,.25) }
    @media (min-width: 992px){ .fab{ display:none } }

    /* Utilities */
    .shadow-sm-up:hover{ box-shadow: 0 8px 24px rgba(0,0,0,.06) }
    .offcanvas .sidebar-menu a.active{ background: rgba(255,255,255,.15); border-radius: 8px; }
    .table-note{ font-size:.85rem }

    /* Small visual polish */
    .chart-container{ position:relative; height:320px; width:100% }
    @media (max-width: 576px){ .chart-container{ height:260px } }
  </style>
</head>
<body class="">
<!-- Navbar -->
<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-label="เมนู">
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

<!-- Offcanvas -->
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
    <main class="col-lg-10 p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="mb-0"><i class="fa-solid fa-wallet me-2"></i>การเงินและบัญชี</h2>

        <!-- View switches -->
        <div class="d-none d-md-flex align-items-center gap-2">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="toggleCompact">
            <label class="form-check-label" for="toggleCompact">โหมดกะทัดรัด</label>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalSummary">
            <i class="bi bi-calculator me-1"></i>สรุปช่วง
          </button>
        </div>
      </div>

      <!-- Sticky Date Range Filter -->
      <div class="filter-bar rounded">
        <form method="GET" action="" class="row g-2 align-items-end">
          <div class="col-6 col-md-3 col-lg-2">
            <label for="gp_from" class="form-label small">จากวันที่</label>
            <input type="date" class="form-control form-control-sm" name="gp_from" id="gp_from" value="<?= htmlspecialchars($rangeFromStr ?? '') ?>">
          </div>
          <div class="col-6 col-md-3 col-lg-2">
            <label for="gp_to" class="form-label small">ถึงวันที่</label>
            <input type="date" class="form-control form-control-sm" name="gp_to" id="gp_to" value="<?= htmlspecialchars($rangeToStr ?? '') ?>">
          </div>
          <div class="col-12 col-md-5 col-lg-6">
            <label class="form-label small d-none d-md-block">ช่วงด่วน</label>
            <div class="d-flex flex-wrap gap-2">
              <a href="?gp_quick=7d" class="btn btn-sm btn-outline-secondary chip <?= $quick==='7d'?'active':'' ?>">7 วัน</a>
              <a href="?gp_quick=30d" class="btn btn-sm btn-outline-secondary chip <?= $quick==='30d'?'active':'' ?>">30 วัน</a>
              <a href="?gp_quick=this_month" class="btn btn-sm btn-outline-secondary chip <?= $quick==='this_month'?'active':'' ?>">เดือนนี้</a>
              <a href="?gp_quick=last_month" class="btn btn-sm btn-outline-secondary chip <?= $quick==='last_month'?'active':'' ?>">เดือนก่อน</a>
              <a href="?gp_quick=this_year" class="btn btn-sm btn-outline-secondary chip <?= $quick==='this_year'?'active':'' ?>">ปีนี้</a>
              <a href="?gp_quick=all" class="btn btn-sm btn-outline-secondary chip <?= $quick==='all'?'active':'' ?>">ทั้งหมด</a>
            </div>
          </div>
          <div class="col-12 col-md-1 col-lg-2 d-grid">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>กรอง</button>
          </div>
        </form>
      </div>

      <!-- Summary cards -->
      <div class="stats-grid mt-3">
        <div class="stat-card shadow-sm-up">
          <h6 class="mb-1"><i class="bi bi-currency-dollar me-2"></i>รายได้รวม (ช่วง)</h6>
          <h3 class="text-success mb-1">฿<?= nf($total_income) ?></h3>
          <div class="table-note muted">รวมรายการ: <?= (int)$total_transactions ?></div>
        </div>
        <div class="stat-card shadow-sm-up">
          <h6 class="mb-1"><i class="bi bi-arrow-down-circle-fill me-2"></i>ค่าใช้จ่ายรวม (ช่วง)</h6>
          <h3 class="text-danger mb-1">฿<?= nf($total_expense) ?></h3>
          <div class="table-note muted">ปรับอัตโนมัติเมื่อกรอง</div>
        </div>
        <div class="stat-card shadow-sm-up">
          <h6 class="mb-1"><i class="bi bi-wallet2 me-2"></i>กำไรสุทธิ (ช่วง)</h6>
          <h3 class="<?= ($net_profit>=0?'text-success':'text-danger') ?> mb-1">฿<?= nf($net_profit) ?></h3>
          <div class="table-note muted">อัปเดตตามช่วงด้านบน</div>
        </div>
      </div>

      <!-- Charts -->
      <div class="row">
        <div class="col-md-6 mb-4">
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="mb-0"><i class="bi bi-pie-chart me-1"></i> สัดส่วนรายได้-ค่าใช้จ่าย</h6>
            </div>
            <div class="chart-container"><canvas id="pieChart" aria-label="สัดส่วนรายได้ค่าใช้จ่าย"></canvas></div>
          </div>
        </div>
        <div class="col-md-6 mb-4">
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="mb-0"><i class="bi bi-graph-up me-1"></i> แนวโน้มการเงิน (ช่วง)</h6>
            </div>
            <div class="chart-container"><canvas id="lineChart" aria-label="กราฟแนวโน้มรายวัน"></canvas></div>
          </div>
        </div>
      </div>

      <?php if ($has_gpv): ?>
      <div class="mb-4">
        <div class="panel">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0"><i class="bi bi-bar-chart-steps me-1"></i> กำไรขั้นต้นรายวัน (Gross Profit)</h6>
          </div>
          <div class="chart-container"><canvas id="gpBarChart" aria-label="กำไรขั้นต้น"></canvas></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Tabs -->
      <div class="mt-3">
        <ul class="nav nav-tabs mb-3" id="inventoryTab" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tx-panel" type="button" role="tab">
              <i class="fa-solid fa-oil-can me-2"></i>รายการการเงิน
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sales-panel" type="button" role="tab">
              <i class="fa-solid fa-tags me-2"></i>รายการขาย
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pay-panel" type="button" role="tab">
              <i class="fa-solid fa-gas-pump me-2"></i>รายการจ่าย
            </button>
          </li>
        </ul>

        <div class="tab-content">
          <!-- TAB: รายการการเงิน -->
          <div class="tab-pane fade show active" id="tx-panel" role="tabpanel" aria-labelledby="tab-tx">
            <!-- Toolbar -->
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="input-group" style="max-width:320px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="search" id="txnSearch" class="form-control" placeholder="ค้นหา: รหัส/รายละเอียด/อ้างอิง" aria-label="ค้นหารายการการเงิน">
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
                <button class="btn btn-outline-secondary" id="btnTxnShowAll" title="ล้างตัวกรอง" aria-label="ล้างตัวกรอง"><i class="bi bi-arrow-clockwise"></i></button>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <div class="btn-group">
                  <button class="btn btn-outline-secondary" id="btnExportData" title="ส่งออก CSV"><i class="bi bi-filetype-csv me-1"></i>Export</button>
                  <button class="btn btn-outline-secondary" id="btnPrint" title="พิมพ์"><i class="bi bi-printer me-1"></i>พิมพ์</button>
                  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalSummary"><i class="bi bi-calculator me-1"></i>สรุป</button>
                </div>
                <?php if ($has_ft): ?>
                <button class="btn btn-success d-none d-lg-inline-flex" data-bs-toggle="modal" data-bs-target="#modalAddTransaction">
                  <i class="bi bi-plus-circle me-1"></i> เพิ่มรายการ
                </button>
                <?php endif; ?>
              </div>
            </div>

            <div class="panel">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="fa-solid fa-list-ul me-1"></i> รายการการเงิน (ตารางหลัก)</h6>
                <?php if (!$has_ft): ?><span class="badge text-bg-secondary">โหมดอ่านอย่างเดียว (รวมจากยอดขาย/รับน้ำมัน)</span><?php endif; ?>
              </div>
              <div class="table-responsive table-sticky">
                <table class="table table-hover align-middle mb-0" id="txnTable">
                  <thead>
                    <tr>
                      <th>วันที่</th>
                      <th>รหัส</th>
                      <th>ประเภท</th>
                      <th>รายละเอียด</th>
                      <th class="text-end">จำนวนเงิน</th>
                      <th class="d-none d-xl-table-cell col-created-by">ผู้บันทึก</th>
                      <th class="text-end col-receipt">ใบเสร็จ</th>
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
                        $rtype = 'transaction'; $rcode = $m[1];
                        $receiptUrl = 'txn_receipt.php?id=' . urlencode($rcode);
                      } elseif (preg_match('/^FT-/', $id)) {
                        $rtype = 'transaction'; $rcode = $id;
                        $receiptUrl = 'txn_receipt.php?code=' . urlencode($rcode);
                      }
                    ?>
                    <tr class="<?= $isIncome ? 'income-row' : 'expense-row' ?>"
                        data-id="<?= htmlspecialchars($tx['id']) ?>"
                        data-date="<?= htmlspecialchars(date('Y-m-d H:i', strtotime($tx['date']))) ?>"
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
                      <td class="d-none d-xl-table-cell col-created-by"><?= htmlspecialchars($tx['created_by']) ?></td>
                      <td class="text-end col-receipt">
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
              <div class="mt-2 table-note muted"><i class="bi bi-info-circle me-1"></i>เลื่อนตารางแนวนอนได้บนจอเล็ก หัวตารางจะติดอยู่ด้านบน</div>
            </div>
          </div>

          <!-- TAB: รายการขาย -->
          <div class="tab-pane fade" id="sales-panel" role="tabpanel" aria-labelledby="tab-sales">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
              <div class="d-flex flex-wrap gap-2">
                <div class="input-group" style="max-width:320px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="search" id="salesSearch" class="form-control" placeholder="ค้นหา: รหัส/รายละเอียด/ผู้บันทึก">
                </div>
                <input type="date" id="salesDate" class="form-control" style="max-width:160px;">
                <button id="salesShowAll" class="btn btn-outline-secondary me-1">แสดงทั้งหมด</button>
              </div>
            </div>
            <div class="panel">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-cash-coin me-1"></i> รายการขาย</h6>
                <span class="muted">รวม <?= (int)$sales_count ?> รายการ | ยอดขายรวม ฿<?= nf($sales_total) ?></span>
              </div>
              <div class="table-responsive table-sticky">
                <table class="table table-striped align-middle mb-0" id="salesTable">
                  <thead><tr>
                    <th>วันที่</th>
                    <th>รหัสขาย</th>
                    <th>รายละเอียด</th>
                    <th class="text-end">จำนวนเงิน</th>
                    <th class="d-none d-lg-table-cell col-created-by">ผู้บันทึก</th>
                    <th class="text-end col-receipt">ใบเสร็จ</th>
                  </tr></thead>
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
                      data-date="<?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['date']))) ?>"
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
          <div class="tab-pane fade" id="pay-panel" role="tabpanel" aria-labelledby="tab-pay">
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
            <div class="panel">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-credit-card-2-back me-1"></i> รายการจ่าย: รับเข้าคลัง/เข้าถัง</h6>
                <span class="muted">รวม <?= (int)$pay_count ?> รายการ | ยอดจ่ายรวม ฿<?= nf($pay_total) ?></span>
              </div>
              <div class="table-responsive table-sticky">
                <table class="table table-striped align-middle mb-0" id="payTable">
                  <thead><tr>
                    <th>วันที่</th>
                    <th>ประเภท</th>
                    <th>รหัส</th>
                    <th>รายละเอียด</th>
                    <th class="text-end">จำนวนเงิน</th>
                    <th class="d-none d-lg-table-cell col-created-by">ผู้บันทึก</th>
                    <th class="text-end col-receipt">ใบเสร็จ</th>
                  </tr></thead>
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
                      data-date="<?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['date']))) ?>"
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

        </div><!-- /tab-content -->
      </div><!-- /Tabs -->

      <footer class="footer mt-4">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการการเงินและบัญชี</footer>
    </main>
  </div>
</div>

<?php if ($has_ft): ?>
<!-- FAB (Mobile) -->
<button class="btn btn-success fab" data-bs-toggle="modal" data-bs-target="#modalAddTransaction">
  <i class="bi bi-plus-lg me-1"></i> เพิ่มรายการ
</button>
<?php endif; ?>

<!-- ========== Modals (ใช้ชุดเดิมของคุณได้เลย) ========== -->
<?= /* ใช้โมดัลเดิมทั้งชุดของไฟล์คุณ (Add/Edit/Summary/Delete) ไม่มีอะไรต้องแก้ */ '' ?>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="liveToast" class="toast align-items-center text-bg-dark border-0" role="status" aria-live="polite">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">ดำเนินการสำเร็จ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="ปิด"></button>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function(){
  const canEdit = <?= $has_ft ? 'true' : 'false' ?>;

  /* ===== Compact mode (remember) ===== */
  const compactKey = 'finance.compact';
  const root = document.body;
  const toggleCompact = document.getElementById('toggleCompact');
  if (localStorage.getItem(compactKey) === '1') {
    root.classList.add('compact');
    if (toggleCompact) toggleCompact.checked = true;
  }
  toggleCompact?.addEventListener('change', (e)=>{
    if (e.target.checked) { root.classList.add('compact'); localStorage.setItem(compactKey,'1'); }
    else { root.classList.remove('compact'); localStorage.removeItem(compactKey); }
  });

  /* ===== Charts ===== */
  const pieCtx = document.getElementById('pieChart')?.getContext('2d');
  const lineCtx = document.getElementById('lineChart')?.getContext('2d');
  const gpCtx = document.getElementById('gpBarChart')?.getContext('2d');

  if (pieCtx) new Chart(pieCtx, {
    type: 'doughnut',
    data: { labels: ['รายได้','ค่าใช้จ่าย'], datasets: [{ data: [<?= json_encode(round($total_income,2)) ?>, <?= json_encode(round($total_expense,2)) ?>], borderWidth: 0 }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } }, animation:{ duration: 300 } }
  });
  if (lineCtx) new Chart(lineCtx, {
    type: 'line',
    data: { labels: <?= json_encode($labels) ?>,
      datasets: [
        { label: 'รายได้', data: <?= json_encode($seriesIncome) ?>, tension:.35, fill:true, pointRadius:2 },
        { label: 'ค่าใช้จ่าย', data: <?= json_encode($seriesExpense) ?>, tension:.35, fill:true, pointRadius:2 }
      ]},
    options: { responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true } }, plugins:{ legend:{ position:'bottom' } }, animation:{ duration: 250 } }
  });
  if (gpCtx) new Chart(gpCtx, {
    type: 'bar',
    data: { labels: <?= json_encode($gp_labels, JSON_UNESCAPED_UNICODE) ?>, datasets: [{ label: 'กำไรขั้นต้น', data: <?= json_encode($gp_series) ?> }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true } }, animation:{ duration: 250 } }
  });

  /* ===== ใบเสร็จ (ทุกตาราง) ===== */
  const receiptRoutes = {
    sale:   code => `sales_receipt.php?code=${encodeURIComponent(code)}`,
    receive:id   => `receive_view.php?id=${encodeURIComponent(id)}`,
    lot:    code => `lot_view.php?code=${encodeURIComponent(code)}`,
    transaction: token => /^\d+$/.test(String(token))
      ? `txn_receipt.php?id=${encodeURIComponent(token)}`
      : `txn_receipt.php?code=${encodeURIComponent(token)}`
  };
  document.querySelectorAll('.btnReceipt').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const tr = btn.closest('tr');
      const direct = tr?.dataset?.receiptUrl?.trim();
      const type   = tr?.dataset?.receiptType;
      const code   = tr?.dataset?.receiptCode;
      let url = direct || (type && code && receiptRoutes[type] ? receiptRoutes[type](code) : '');
      if (!url) return alert('ยังไม่มีลิงก์ใบเสร็จสำหรับรายการนี้');
      window.open(url, '_blank');
    });
  });

  /* ===== ฟิลเตอร์ตารางหลัก (debounce) ===== */
  const q = document.getElementById('txnSearch');
  const fType = document.getElementById('filterType');
  const fCat  = document.getElementById('filterCategory');
  const fDate = document.getElementById('filterDate');
  const tbody = document.querySelector('#txnTable tbody');

  const debounce = (fn, wait=250)=>{ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args),wait); }; };
  const normalize = s => (s||'').toString().toLowerCase();

  function applyFilters(){
    const text = normalize(q?.value || '');
    const type = fType?.value || '';
    const cat  = fCat?.value || '';
    const date = fDate?.value || '';
    tbody?.querySelectorAll('tr').forEach(tr=>{
      const d = tr.dataset || {};
      let ok = true;
      if (type && d.type !== type) ok = false;
      if (cat  && d.category !== cat) ok = false;
      if (date) {
        const rowDate = (d.date || '').slice(0,10);
        if (rowDate !== date) ok = false;
      }
      if (text) {
        const blob = (d.id+' '+(d.category||'')+' '+(d.description||'')+' '+(d.reference||'')+' '+(d.createdBy||'')+' '+tr.textContent).toLowerCase();
        if (!blob.includes(text)) ok = false;
      }
      tr.style.display = ok? '' : 'none';
    });
  }
  [q,fType,fCat,fDate].forEach(el=>el && el.addEventListener('input', debounce(applyFilters, 200)));
  document.getElementById('btnTxnShowAll')?.addEventListener('click', ()=>{
    if (q) q.value = '';
    if (fType) fType.value = '';
    if (fCat) fCat.value = '';
    if (fDate) fDate.value = '';
    applyFilters();
  });
  applyFilters();

  /* ===== ฟิลเตอร์สำหรับตาราง “ขาย” และ “จ่าย” ===== */
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
          const blob = [ds.code, ds.description, ds.createdBy, ds.origin, ds.amount, tr.textContent].join(' ').toLowerCase();
          ok = blob.includes(text);
        }
        tr.style.display = ok ? '' : 'none';
      });
    }
    q && q.addEventListener('input', debounce(apply, 200));
    d && d.addEventListener('input', apply);
    resetBtn && resetBtn.addEventListener('click', ()=>{ if (q) q.value = ''; if (d) d.value = ''; apply(); });
    apply();
  }
  wireSimpleTable({ tableId:'salesTable', searchId:'salesSearch', dateId:'salesDate', resetId:'salesShowAll' });
  wireSimpleTable({ tableId:'payTable',   searchId:'paySearch',   dateId:'payDate',   resetId:'payShowAll'   });

  /* ===== CSV / Print ===== */
  function exportCSV(){
    const rows = [['วันที่','รหัส','ประเภท','หมวดหมู่','รายละเอียด','จำนวนเงิน','อ้างอิง','ผู้บันทึก']];
    document.querySelectorAll('#txnTable tbody tr').forEach(tr=>{
      if (tr.style.display==='none') return;
      const d = tr.dataset;
      const dateObj = new Date(d.date);
      const dd = String(dateObj.getDate()).padStart(2,'0')+'/'+String(dateObj.getMonth()+1).padStart(2,'0')+'/'+dateObj.getFullYear();
      const amt = parseFloat(d.amount||'0');
      rows.push([dd, d.id, d.type==='income'?'รายได้':'ค่าใช้จ่าย', d.category||'', d.description||'', amt.toFixed(2), d.reference||'', d.createdBy||'']);
    });
    const csv = rows.map(r=>r.map(x=>`"${String(x??'').replace(/"/g,'""')}"`).join(',')).join('\r\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'finance_export.csv'; a.click(); URL.revokeObjectURL(a.href);
  }
  document.getElementById('btnExportData')?.addEventListener('click', exportCSV);
  document.getElementById('btnPrint')?.addEventListener('click', ()=>window.print());

  /* ===== Toast messages from URL ===== */
  const toastEl = document.getElementById('liveToast'); const toastMsg = document.getElementById('toastMsg');
  const toast = toastEl ? new bootstrap.Toast(toastEl, {delay:2000}) : null;
  function showToast(msg){ if(!toast) return alert(msg); toastMsg.textContent=msg; toast.show(); }
  const urlParams = new URLSearchParams(window.location.search);
  const okMsg = urlParams.get('ok'); const errMsg = urlParams.get('err');
  if (okMsg) { showToast(okMsg); window.history.replaceState({}, document.title, window.location.pathname + window.location.hash); }
  if (errMsg) { showToast(errMsg); window.history.replaceState({}, document.title, window.location.pathname + window.location.hash); }

  /* ===== Modal handlers (ใช้ของเดิม) ===== */
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
})();
</script>
</body>
</html>
