<?php
// committee/finance.php — [ปรับปรุง] ดูการเงิน (Index-Friendly, Robust Error)
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id'])) { header('Location: /index/login.php?err=โปรดเข้าสู่ระบบก่อน'); exit(); }

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
require_once $dbFile; // $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ดึงข้อมูลผู้ใช้และตรวจสอบสิทธิ์
try {
    $current_name = $_SESSION['full_name'] ?? 'กรรมการ';
    $current_role = $_SESSION['role'] ?? '';
    if ($current_role !== 'committee') {
        header('Location: /index/login.php?err=คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        exit();
    }
} catch (Throwable $e) { header('Location: /index/login.php?err=เกิดข้อผิดพลาดของระบบ'); exit(); }

/* ===== Helpers ===== */
if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    try {
      $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
      $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
      $st->execute([':db'=>$db, ':tb'=>$table]);
      return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('column_exists')) {
  function column_exists(PDO $pdo, string $table, string $col): bool {
    try {
      $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
      $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:db AND table_name=:tb AND column_name=:col');
      $st->execute([':db'=>$db, ':tb'=>$table, ':col'=>$col]);
      return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
  }
}
function nf($n, $d=2){ return number_format((float)$n, $d, '.', ','); }
function ymd($s){ $t=strtotime($s); return $t? date('Y-m-d',$t) : null; }
function d($s, $fmt = 'd/m/Y') { 
    if (empty($s)) return '-';
    $t = strtotime($s); 
    return $t ? date($fmt, $t) : '-'; 
}

/* ===== โหลดค่าพื้นฐาน ===== */
$site_name = 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง';
$stationId = 1;
try {
  // [แก้ไข] ใช้ query มาตรฐาน
  $st = $pdo->query("SELECT setting_value, comment FROM settings WHERE setting_name='station_id' LIMIT 1");
  if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $stationId = (int)$r['setting_value'];
      $site_name = $r['comment'] ?: $site_name;
  }
} catch (Throwable $e) {}

$role_th_map = ['admin'=>'ผู้ดูแลระบบ','manager'=>'ผู้บริหาร','employee'=>'พนักงาน','member'=>'สมาชิกสหกรณ์','committee'=>'กรรมการ'];
$current_role_th = $role_th_map[$current_role] ?? 'ผู้ใช้งาน';
$avatar_text = mb_substr($current_name, 0, 1, 'UTF-8');

/* ===== กำหนดช่วงวันที่ "ส่วนกลางของทั้งหน้า" ===== */
$quick = $_GET['gp_quick'] ?? '';
$in_from = ymd($_GET['gp_from'] ?? '');
$in_to   = ymd($_GET['gp_to']   ?? '');

$today = new DateTime('today');
$from = null; $to = null;

// [แก้ไข] ใช้ตรรกะเดียวกับ Manager
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
if ($from && $to) { 
  $diffDays = (int)$from->diff($to)->format('%a');
  if ($diffDays > 366) { $from=(clone $to)->modify('-366 day'); }
}
$rangeFromStr = $from ? $from->format('Y-m-d') : null;
$rangeToStr   = $to   ? $to->format('Y-m-d')   : null;
// [เพิ่ม] สำหรับ Query ที่เป็นมิตรกับ Index (column < 'YYYY-MM-DD' + 1 day)
$rangeToPlusOneStr = $to ? (clone $to)->modify('+1 day')->format('Y-m-d') : null; 


/* ===== [เพิ่ม] กำหนดค่าเริ่มต้นตัวแปร ===== */
$error_message = null;
$transactions = []; $transactions_display = [];
$categories   = ['income'=>[],'expense'=>[]];
$total_income = 0.0; $total_expense = 0.0; $net_profit = 0.0; $total_transactions_all = 0;
$total_pages_fin = 1; $page_fin = 1; $fin_from_i = 0; $fin_to_i = 0;

$sales_rows = []; $sales_rows_display = [];
$sales_total = 0.0; $sales_count = 0; $total_sales_all = 0;
$total_pages_sales = 1; $page_sales = 1; $sales_from_i = 0; $sales_to_i = 0;

$labels = []; $seriesIncome = []; $seriesExpense = [];
$gp_labels = []; $gp_series = [];

$base_qs = $_GET;
unset($base_qs['page_fin'], $base_qs['page_sales']);


// [เพิ่ม] try-catch ครอบทั้งหมด
try {

    /* ===== ธงโหมด ===== */
    $has_ft  = table_exists($pdo,'financial_transactions');
    $has_gpv = table_exists($pdo,'v_sales_gross_profit');
    $ft_has_station   = $has_ft && column_exists($pdo,'financial_transactions','station_id');
    $has_sales_station= column_exists($pdo,'sales','station_id');
    $has_fr_station   = column_exists($pdo,'fuel_receives','station_id');
    $has_lot_station  = column_exists($pdo,'fuel_lots','station_id');
    $has_tank_station = column_exists($pdo,'fuel_tanks','station_id');

    /* ===== ดึงธุรกรรม (FT หรือ UNION) + หมวดหมู่ + สรุป (กรองตามช่วง) ===== */
    try {
      if ($has_ft) {
        $w = 'WHERE 1=1'; $p=[];
        if ($ft_has_station) { $w .= " AND ft.station_id = :sid"; $p[':sid'] = $stationId; }
        // [แก้ไข] ใช้ Query ที่เป็นมิตรกับ Index
        if ($rangeFromStr) { $w.=" AND ft.transaction_date >= :f"; $p[':f']=$rangeFromStr; }
        if ($rangeToPlusOneStr)   { $w.=" AND ft.transaction_date < :t_plus_one"; $p[':t_plus_one']=$rangeToPlusOneStr; }

        $stmt = $pdo->prepare("
          SELECT COALESCE(ft.transaction_code, CONCAT('FT-', ft.id)) AS id,
                 ft.transaction_date AS date, ft.type, ft.category, ft.description,
                 ft.amount, ft.reference_id AS reference, COALESCE(u.full_name,'-') AS created_by
          FROM financial_transactions ft
          LEFT JOIN users u ON u.id = ft.user_id
          $w
          ORDER BY ft.transaction_date DESC, ft.id DESC
          LIMIT 1000 -- จำกัดการดึงข้อมูล
        ");
        $stmt->execute($p);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total_transactions_all = count($transactions); // [เพิ่ม] นับจากที่ดึงมา

        $catSql = "SELECT type, GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR '||') cats
                   FROM financial_transactions ".($ft_has_station?" WHERE station_id=:sid ":"")."
                   GROUP BY type";
        $catStmt = $pdo->prepare($catSql);
        if ($ft_has_station) $catStmt->execute([':sid'=>$stationId]); else $catStmt->execute();
        $cats = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $categories['income']  = isset($cats['income'])  ? explode('||',$cats['income'])  : [];
        $categories['expense'] = isset($cats['expense']) ? explode('||',$cats['expense']) : [];

        // [แก้ไข] ใช้ $w เดิม
        $sumSql = "SELECT COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) ti,
                          COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) te
                   FROM financial_transactions ft $w";
        $sum = $pdo->prepare($sumSql);
        $sum->execute($p);
        $s = $sum->fetch(PDO::FETCH_ASSOC);
        $total_income = (float)$s['ti'];
        $total_expense = (float)$s['te'];
        $net_profit = $total_income - $total_expense;

      } else {
        // ====== UNION แบบยืดหยุ่นเมื่อไม่มี financial_transactions ======
        $parts = []; $paramsU = [];
        // (ตรรกะ UNION ... $parts[] ... $paramsU ... เหมือนเดิม)
        // SALES
        $salesWhere = "WHERE 1=1";
        if ($has_sales_station) { $salesWhere .= " AND s.station_id = :sidS"; $paramsU[':sidS'] = $stationId; }
        $parts[] = "
          SELECT CONCAT('SALE-', s.sale_code) AS id, s.sale_date AS date, 'income' AS type, 'ขายน้ำมัน' AS category,
                 CONCAT('ขายเชื้อเพลิง (', COALESCE(s.payment_method,''), ')') AS description, s.total_amount AS amount,
                 s.sale_code AS reference, COALESCE(u.full_name,'-') AS created_by
          FROM sales s
          LEFT JOIN users u ON u.id = s.created_by -- [ปรับ] join created_by
          $salesWhere
        ";
        // RECEIVES (เข้าคลัง)
        if ($has_fr_station) {
          $whereRcv = "WHERE fr.station_id = :sidR"; $paramsU[':sidR'] = $stationId;
        } else {
          $whereRcv = "WHERE EXISTS (SELECT 1 FROM fuel_prices fp2 WHERE fp2.fuel_id=fr.fuel_id AND fp2.station_id=:sidR)"; $paramsU[':sidR'] = $stationId;
        }
        $parts[] = "
          SELECT CONCAT('RCV-', fr.id) AS id, fr.received_date AS date, 'expense' AS type, 'ซื้อน้ำมันเข้าคลัง' AS category,
                 CONCAT('รับเข้าคลัง ', COALESCE(fp.fuel_name,''), COALESCE(CONCAT(' จาก ', s2.supplier_name), '')) AS description,
                 (COALESCE(fr.cost,0) * fr.amount) AS amount, fr.id AS reference, COALESCE(u2.full_name,'-') AS created_by
          FROM fuel_receives fr
          LEFT JOIN fuel_prices fp ON fp.fuel_id = fr.fuel_id AND fp.station_id = :sidR
          LEFT JOIN suppliers s2 ON s2.supplier_id = fr.supplier_id
          LEFT JOIN users u2 ON u2.id = fr.created_by
          $whereRcv
        ";
        // LOTS (เข้าถัง)
        if ($has_lot_station) {
          $paramsU[':sidL'] = $stationId;
          $parts[] = "
            SELECT CONCAT('LOT-', l.lot_code) AS id, l.received_at AS date, 'expense' AS type, 'ซื้อน้ำมันเข้าถัง' AS category,
                   CONCAT('รับเข้าถัง ', COALESCE((SELECT t.code FROM fuel_tanks t WHERE t.id = l.tank_id LIMIT 1), '')) AS description,
                   l.initial_total_cost AS amount, l.receive_id AS reference, COALESCE(u3.full_name,'-') AS created_by
            FROM fuel_lots l LEFT JOIN users u3 ON u3.id = l.created_by
            WHERE l.station_id = :sidL
          ";
        } elseif ($has_tank_station) {
          $paramsU[':sidL'] = $stationId;
          $parts[] = "
            SELECT CONCAT('LOT-', l.lot_code) AS id, l.received_at AS date, 'expense' AS type, 'ซื้อน้ำมันเข้าถัง' AS category,
                   CONCAT('รับเข้าถัง ', t.code) AS description, l.initial_total_cost AS amount, l.receive_id AS reference,
                   COALESCE(u3.full_name,'-') AS created_by
            FROM fuel_lots l JOIN fuel_tanks t ON t.id = l.tank_id LEFT JOIN users u3 ON u3.id = l.created_by
            WHERE t.station_id = :sidL
          ";
        }
        // (จบส่วน UNION)

        $unionSQL = implode("\nUNION ALL\n", $parts);

        // [แก้ไข] ใช้ Query ที่เป็นมิตรกับ Index
        $w = 'WHERE 1=1'; $p = $paramsU;
        if ($rangeFromStr) { $w.=" AND x.date >= :f"; $p[':f']=$rangeFromStr; }
        if ($rangeToPlusOneStr)   { $w.=" AND x.date < :t_plus_one"; $p[':t_plus_one']=$rangeToPlusOneStr; }

        $stmt = $pdo->prepare("SELECT * FROM ( $unionSQL ) x $w ORDER BY x.date DESC, x.id DESC LIMIT 1000");
        $stmt->execute($p);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total_transactions_all = count($transactions); // [เพิ่ม]

        $catStmt = $pdo->prepare("SELECT type, GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR '||') cats FROM ( $unionSQL ) c GROUP BY type");
        $catStmt->execute($paramsU);
        $cats = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $categories['income']  = isset($cats['income'])  ? explode('||',$cats['income'])  : ['ขายน้ำมัน'];
        $categories['expense'] = isset($cats['expense']) ? explode('||',$cats['expense']) : ['ซื้อน้ำมันเข้าคลัง','ซื้อน้ำมันเข้าถัง'];

        $sumOuter = $pdo->prepare("
          SELECT COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) ti,
                 COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) te
          FROM ( $unionSQL ) s
          $w
        ");
        $sumOuter->execute($p);
        $s = $sumOuter->fetch(PDO::FETCH_ASSOC);
        $total_income = (float)$s['ti'];
        $total_expense = (float)$s['te'];
        $net_profit = $total_income - $total_expense;
      }
    } catch (Throwable $e) {
      $error_message = ($error_message ? $error_message . ' | ' : '') . "ไม่สามารถโหลดข้อมูลธุรกรรมได้: " . $e->getMessage();
      $transactions = []; $categories = ['income'=>[],'expense'=>[]];
    }

    /* ===== [เพิ่ม] Pagination - รายการการเงิน ===== */
    $per_fin = 7;
    $page_fin = max(1, (int)($_GET['page_fin'] ?? 1));
    $offset_fin = ($page_fin - 1) * $per_fin;
    $transactions_display = array_slice($transactions, $offset_fin, $per_fin);
    $total_pages_fin = max(1, (int)ceil($total_transactions_all / $per_fin));
    $fin_from_i = $total_transactions_all ? $offset_fin + 1 : 0;
    $fin_to_i   = min($offset_fin + $per_fin, $total_transactions_all);


    /* ===== ดึง "รายการขาย" แยกต่างหาก (ไม่กรองวันที่) ===== */
    try {
      $sw = "WHERE 1=1"; $sp=[];
      if ($has_sales_station) { $sw .= " AND s.station_id=:sid"; $sp[':sid']=$stationId; }

      // [หมายเหตุ] หน้านี้ตั้งใจแสดง 'รายการขาย' ทั้งหมด (ไม่กรองตามช่วงวันที่)
      $ss = $pdo->prepare("
        SELECT s.sale_date AS date, s.sale_code AS code, s.total_amount AS amount,
               CONCAT('ขายเชื้อเพลิง (', COALESCE(s.payment_method,''), ')') AS description,
               COALESCE(u.full_name,'-') AS created_by
        FROM sales s
        LEFT JOIN users u ON u.id = s.created_by
        $sw
        ORDER BY s.sale_date DESC, s.id DESC
        LIMIT 5000
      ");
      $ss->execute($sp);
      $sales_rows = $ss->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $total_sales_all = count($sales_rows); // [แก้ไข]

      $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) s, COUNT(*) c FROM sales s $sw");
      $st->execute($sp);
      [$sales_total, $sales_count_total] = $st->fetch(PDO::FETCH_NUM) ?: [0,0];
      // [แก้ไข] ใช้ $total_sales_all หรือ $sales_count_total
      $total_sales_all = $sales_count_total;

    } catch (Throwable $e) {
      $error_message = ($error_message ? $error_message . ' | ' : '') . "ไม่สามารถโหลด 'sales': " . $e->getMessage();
    }
    
    /* ===== [เพิ่ม] Pagination - รายการขาย ===== */
    $per_sales = 7;
    $page_sales = max(1, (int)($_GET['page_sales'] ?? 1));
    $offset_sales = ($page_sales - 1) * $per_sales;
    $sales_rows_display = array_slice($sales_rows, $offset_sales, $per_sales);
    $total_pages_sales = max(1, (int)ceil($total_sales_all / $per_sales));
    $sales_from_i = $total_sales_all ? $offset_sales + 1 : 0;
    $sales_to_i   = min($offset_sales + $per_sales, $total_sales_all);


    /* ===== [ลบ] บล็อก "รายการจ่าย" (pay_rows) ที่ซ้ำซ้อนออกไป ===== */
    // ... (ลบโค้ด 80 บรรทัด) ...


    /* ===== กราฟแนวโน้ม (รายวัน) ===== */
    try {
      if ($rangeFromStr && $rangeToStr) { $start = new DateTime($rangeFromStr); $end = new DateTime($rangeToStr); }
      else { $start = (new DateTime('today'))->modify('-6 day'); $end = new DateTime('today'); }
      $days=[]; $cursor=clone $start; 
      while ($cursor <= $end) { $key=$cursor->format('Y-m-d'); $days[$key]=['income'=>0.0,'expense'=>0.0]; $cursor->modify('+1 day'); }

      if ($has_ft) {
        $q_w = ($ft_has_station?" WHERE station_id=:sid ":" WHERE 1=1 "); $p=[];
        if($ft_has_station) $p[':sid']=$stationId;
        // [แก้ไข] ใช้ Query ที่เป็นมิตรกับ Index
        if ($rangeFromStr) { $q_w .=" AND transaction_date >= :f"; $p[':f']=$rangeFromStr; }
        if ($rangeToPlusOneStr)   { $q_w .=" AND transaction_date < :t_plus_one"; $p[':t_plus_one']=$rangeToPlusOneStr; }
        
        $q = $pdo->prepare("
          SELECT DATE(transaction_date) d,
                 SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) inc,
                 SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) exp
          FROM financial_transactions
          $q_w
          GROUP BY DATE(transaction_date) ORDER BY DATE(transaction_date)
        ");
        $q->execute($p);
        while ($r=$q->fetch(PDO::FETCH_ASSOC)) { $d=$r['d']; if(isset($days[$d])) { $days[$d]['income']=(float)$r['inc']; $days[$d]['expense']=(float)$r['exp']; } }
      
      } else {
        // [แก้ไข] ใช้ Query ที่เป็นมิตรกับ Index (ทุก query ย่อย)
        $q_w_s = ($has_sales_station?" WHERE station_id=:sid ":" WHERE 1=1 "); $p_s=[];
        if($has_sales_station) $p_s[':sid']=$stationId;
        if ($rangeFromStr) { $q_w_s .=" AND sale_date >= :f"; $p_s[':f']=$rangeFromStr; }
        if ($rangeToPlusOneStr)   { $q_w_s .=" AND sale_date < :t_plus_one"; $p_s[':t_plus_one']=$rangeToPlusOneStr; }
        $stInc = $pdo->prepare("SELECT DATE(sale_date) d, SUM(total_amount) v FROM sales $q_w_s GROUP BY DATE(sale_date)");
        $stInc->execute($p_s);
        while ($r=$stInc->fetch(PDO::FETCH_ASSOC)) { $d=$r['d']; if(isset($days[$d])) $days[$d]['income']=(float)$r['v']; }

        $q_w_r = ($has_fr_station? " WHERE fr.station_id=:sid " : " WHERE EXISTS (SELECT 1 FROM fuel_prices fp2 WHERE fp2.fuel_id=fr.fuel_id AND fp2.station_id=:sid) ");
        $p_r=[":sid"=>$stationId];
        if ($rangeFromStr) { $q_w_r .=" AND fr.received_date >= :f"; $p_r[':f']=$rangeFromStr; }
        if ($rangeToPlusOneStr)   { $q_w_r .=" AND fr.received_date < :t_plus_one"; $p_r[':t_plus_one']=$rangeToPlusOneStr; }
        $stExpR = $pdo->prepare("SELECT DATE(fr.received_date) d, SUM(COALESCE(fr.cost,0)*fr.amount) v FROM fuel_receives fr $q_w_r GROUP BY DATE(fr.received_date)");
        $stExpR->execute($p_r);
        while ($r=$stExpR->fetch(PDO::FETCH_ASSOC)) { $d=$r['d']; if(isset($days[$d])) $days[$d]['expense'] += (float)$r['v']; }

        if (table_exists($pdo,'fuel_lots')) {
          $q_w_l = ""; $p_l = [":sid"=>$stationId];
          if ($has_lot_station) { $q_w_l = " WHERE station_id=:sid "; }
          elseif ($has_tank_station) { $q_w_l = " JOIN fuel_tanks t ON t.id = l.tank_id WHERE t.station_id=:sid "; }
          else { $q_w_l = " WHERE 1=1 "; unset($p_l[":sid"]); }

          if ($rangeFromStr) { $q_w_l .=" AND l.received_at >= :f"; $p_l[':f']=$rangeFromStr; }
          if ($rangeToPlusOneStr)   { $q_w_l .=" AND l.received_at < :t_plus_one"; $p_l[':t_plus_one']=$rangeToPlusOneStr; }

          $stExpL = $pdo->prepare("SELECT DATE(l.received_at) d, SUM(l.initial_total_cost) v FROM fuel_lots l $q_w_l GROUP BY DATE(l.received_at)");
          $stExpL->execute($p_l);
          while ($r=$stExpL->fetch(PDO::FETCH_ASSOC)) { $d=$r['d']; if(isset($days[$d])) $days[$d]['expense'] += (float)$r['v']; }
        }
      }
      foreach ($days as $d=>$v) { $labels[] = (new DateTime($d))->format('d/m'); $seriesIncome[] = round($v['income'],2); $seriesExpense[] = round($v['expense'],2); }
    } catch (Throwable $e) {
      $error_message = ($error_message ? $error_message . ' | ' : '') . "เกิดข้อผิดพลาด (Graph): " . $e->getMessage();
    }

    /* ===== กำไรขั้นต้น (Gross Profit) — ผูกช่วงเดียวกัน (ถ้ามี view) ===== */
    if ($has_gpv) {
      try {
        $wd = ''; $pp=[':sid'=>$stationId];
        // [แก้ไข] ใช้ Query ที่เป็นมิตรกับ Index
        if ($rangeFromStr) { $wd.=" AND v.sale_date >= :f"; $pp[':f']=$rangeFromStr; }
        if ($rangeToPlusOneStr)   { $wd.=" AND v.sale_date < :t_plus_one"; $pp[':t_plus_one']=$rangeToPlusOneStr; }

        $grp = $pdo->prepare("
          SELECT DATE(v.sale_date) d, COALESCE(SUM(v.total_amount - COALESCE(v.cogs,0)),0) gp
          FROM v_sales_gross_profit v
          JOIN sales s ON s.id = v.sale_id
          WHERE ".($has_sales_station? "s.station_id = :sid" : "1=1")." $wd
          GROUP BY DATE(v.sale_date) ORDER BY DATE(v.sale_date)
        "); $grp->execute($pp);
        $map = $grp->fetchAll(PDO::FETCH_KEY_PAIR); // [แก้ไข] 

        $sD = $rangeFromStr ? new DateTime($rangeFromStr) : (new DateTime('today'))->modify('-6 day');
        $eD = $rangeToStr   ? new DateTime($rangeToStr)   : new DateTime('today');
        $c = clone $sD;
        while ($c <= $eD) {
          $d = $c->format('Y-m-d');
          $gp_labels[] = $c->format('d/m');
          $gp_series[] = round($map[$d] ?? 0, 2);
          $c->modify('+1 day');
        }
      } catch (Throwable $e) { 
          $has_gpv = false; 
          $error_message = ($error_message ? $error_message . ' | ' : '') . "เกิดข้อผิดพลาด (GPV): " . $e->getMessage();
      }
    }

} catch (Throwable $e) {
    // [เพิ่ม] Catch-all
    $error_message = "เกิดข้อผิดพลาดรุนแรงในการโหลดหน้า: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>การเงินและบัญชี - <?= htmlspecialchars($site_name) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/admin_dashboard.css" />
  <style>
    /* [ปรับปรุง] ลบ .stats-grid และ .panel (มีใน admin_dashboard.css แล้ว) */
    .income-row { border-left: 4px solid #198754; }
    .expense-row{ border-left: 4px solid #dc3545; }
    .amount-income{ color:#198754; font-weight:600; } .amount-expense{ color:#dc3545; font-weight:600; }
    .transaction-type{ padding:4px 8px; border-radius:12px; font-size:.8rem; font-weight:500; }
    .type-income{ background:#d1edff; color:#0969da; } .type-expense{ background:#ffebe9; color:#cf222e; }
    .chart-container{position:relative;height:300px;width:100%}
    .muted{color:#6c757d}
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
      <a class="navbar-brand" href="committee_dashboard.php"><?= htmlspecialchars($site_name) ?></a>
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
    <div class="side-brand mb-2"><h3><span>Committee</span></h3></div>
    <nav class="sidebar-menu">
      <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
      <a href="finance.php" class="active"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
      <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
      <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
      <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
    </nav>
    <a class="logout mt-auto" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">
    <aside class="col-lg-2 d-none d-lg-flex flex-column sidebar py-4">
      <div class="side-brand mb-3"><h3><span>Committee</span></h3></div>
      <nav class="sidebar-menu flex-grow-1">
        <a href="committee_dashboard.php"><i class="fa-solid fa-border-all"></i>แดชบอร์ด</a>
        <a href="finance.php" class="active"><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</a>
        <a href="dividend.php"><i class="fa-solid fa-gift"></i> ปันผล</a>
        <a href="member.php"><i class="bi bi-people-fill"></i> สมาชิก</a>
        <a href="profile.php"><i class="fa-solid fa-user-gear"></i> โปรไฟล์</a>
      </nav>
      <a class="logout" href="../index.php"><i class="fa-solid fa-right-from-bracket"></i>ออกจากระบบ</a>
    </aside>

    <main class="col-lg-10 p-4">
      <div class="main-header">
        <h2><i class="fa-solid fa-wallet"></i> การเงินและบัญชี</h2>
        </div>
      
      <?php if (!empty($error_message)): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <strong>เกิดข้อผิดพลาด:</strong> <?= htmlspecialchars($error_message) ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
      <?php endif; ?>

      <div class="stat-card mb-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-md">
                <label for="gp_from" class="form-label small fw-bold">จากวันที่</label>
                <input type="date" class="form-control" name="gp_from" id="gp_from" value="<?= htmlspecialchars($rangeFromStr ?? '') ?>">
            </div>
            <div class="col-md">
                <label for="gp_to" class="form-label small fw-bold">ถึงวันที่</label>
                <input type="date" class="form-control" name="gp_to" id="gp_to" value="<?= htmlspecialchars($rangeToStr ?? '') ?>">
            </div>
            <div class="col-md-auto">
                <label class="form-label small d-none d-md-block">&nbsp;</label>
                <div class="btn-group w-100" role="group">
                    <a href="?gp_quick=7d" class="btn btn-outline-secondary <?= $quick === '7d' ? 'active' : '' ?>">7 วัน</a>
                    <a href="?gp_quick=30d" class="btn btn-outline-secondary <?= $quick === '30d' ? 'active' : '' ?>">30 วัน</a>
                    <a href="?gp_quick=this_month" class="btn btn-outline-secondary <?= $quick === 'this_month' ? 'active' : '' ?>">เดือนนี้</a>
                    <a href="?gp_quick=last_month" class="btn btn-outline-secondary <?= $quick === 'last_month' ? 'active' : '' ?>">เดือนก่อน</a>
                </div>
            </div>
            <div class="col-md-auto">
                <label class="form-label small d-none d-md-block">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> กรอง</button>
            </div>
        </form>
      </div>

      <div class="stats-grid my-4">
        <div class="stat-card text-center">
          <h5><i class="bi bi-arrow-down-circle-fill me-2 text-success"></i>รายได้รวม (ช่วง)</h5>
          <h3 class="text-success mb-0">฿<?= nf($total_income) ?></h3>
        </div>
        <div class="stat-card text-center">
          <h5><i class="bi bi-arrow-up-circle-fill me-2 text-warning"></i>ค่าใช้จ่ายรวม (ช่วง)</h5>
          <h3 class="text-warning mb-0">฿<?= nf($total_expense) ?></h3>
        </div>
        <div class="stat-card text-center">
          <h5><i class="bi bi-wallet2 me-2 text-primary"></i>กำไรสุทธิ (ช่วง)</h5>
          <h3 class="<?= ($net_profit>=0?'text-primary':'text-warning') ?> mb-0">฿<?= nf($net_profit) ?></h3>
          <small class="text-muted mt-1"><?= (int)$total_transactions_all ?> รายการ</small>
        </div>
      </div>

      <div class="stat-card mb-4">
        <h5 class="mb-3"><i class="bi bi-bar-chart-line-fill me-2"></i>สรุปภาพรวม (ตามช่วงที่เลือก)</h5>
        <div class="row g-3">
          <div class="col-lg-4 col-md-6">
            <h6 class="mb-3 text-center"><i class="bi bi-pie-chart me-1"></i> สัดส่วนรายได้-ค่าใช้จ่าย</h6>
            <div class="chart-container" style="height: 250px;"><canvas id="pieChart"></canvas></div>
          </div>
          <div class="col-lg-4 col-md-6">
            <h6 class="mb-3 text-center"><i class="bi bi-graph-up me-1"></i> แนวโน้มการเงิน</h6>
            <div class="chart-container" style="height: 250px;"><canvas id="lineChart"></canvas></div>
          </div>
          <div class="col-lg-4 col-md-12">
            <h6 class="mb-3 text-center"><i class="bi bi-cash-coin me-1"></i> แนวโน้มกำไรขั้นต้น (GP)</h6>
            <div class="chart-container" style="height: 250px;"><canvas id="gpBarChart"></canvas></div>
          </div>
        </div>
      </div>

      <ul class="nav nav-tabs mb-3" id="inventoryTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#financial-panel" type="button" role="tab">
            <i class="fa-solid fa-list-ul me-2"></i>รายการการเงิน (<?= (int)$total_transactions_all ?>)
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sales-panel" type="button" role="tab">
            <i class="bi bi-receipt-cutoff me-2"></i>รายการขาย (<?= (int)$total_sales_all ?>)
          </button>
        </li>
      </ul>

      <div class="tab-content" id="inventoryTabContent">
        <div class="tab-pane fade show active" id="financial-panel" role="tabpanel">
            <div class="stat-card">
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
                            $coreCategories = ['ค่าสาธารณูปโภค', 'เงินลงทุน', 'รายได้อื่น', 'จ่ายเงินรายวัน'];
                            $excludedCategories = ['เงินเดือน'];
                            $existingCats = array_unique(array_merge($categories['income'],$categories['expense']));
                            $finalCats = array_unique(array_merge($coreCategories, $existingCats));
                            $finalCats = array_filter($finalCats, function($cat) use ($excludedCategories) {
                                return !in_array($cat, $excludedCategories);
                            });
                            sort($finalCats, SORT_NATURAL | SORT_FLAG_CASE);
                            foreach($finalCats as $c) {
                                echo '<option value="'.htmlspecialchars($c).'">'.htmlspecialchars($c).'</option>';
                            }
                          ?>
                      </datalist>
                  </select>
                  <button class="btn btn-outline-secondary" id="btnTxnShowAll" title="ล้างตัวกรอง"><i class="bi bi-arrow-clockwise"></i></button>
                  </div>
                </div>
                
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0" id="txnTable">
                    <thead class="table-light">
                    <tr>
                        <th>วันที่</th><th>รหัส</th><th>ประเภท</th><th>รายละเอียด</th>
                        <th class="text-end">จำนวนเงิน</th>
                        <th class="d-none d-lg-table-cell">ผู้บันทึก</th> <th class="text-end">ใบเสร็จ</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($transactions_display)): // [แก้ไข] ใช้ $transactions_display ?>
                        <tr><td colspan="7" class="text-center text-muted p-4">ไม่พบข้อมูลใน่ชวงวันที่ที่เลือก</td></tr>
                      <?php endif; ?>
                      <?php foreach($transactions_display as $tx): // [แก้ไข] ใช้ $transactions_display
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
                          $rtype = 'transaction'; $rcode = $m[1]; $receiptUrl = 'txn_receipt.php?id=' . urlencode($rcode);
                        } elseif (preg_match('/^FT-/', $id)) {
                          $rtype = 'transaction'; $rcode = $id; $receiptUrl = 'txn_receipt.php?code=' . urlencode($rcode);
                        }
                      ?>
                      <tr data-id="<?= htmlspecialchars($tx['id']) ?>"
                          data-type="<?= htmlspecialchars($tx['type']) ?>"
                          data-category="<?= htmlspecialchars($tx['category'] ?? '') ?>"
                          data-description="<?= htmlspecialchars($tx['description']) ?>"
                          data-amount="<?= htmlspecialchars($tx['amount']) ?>"
                          data-created-by="<?= htmlspecialchars($tx['created_by']) ?>"
                          data-reference="<?= htmlspecialchars($tx['reference'] ?? '') ?>"
                          data-receipt-type="<?= htmlspecialchars($rtype) ?>"
                          data-receipt-code="<?= htmlspecialchars($rcode) ?>"
                          data-receipt-url="<?= htmlspecialchars($receiptUrl) ?>">
                        <td class="ps-3"><?= d($tx['date'], 'd/m/Y H:i') // [แก้ไข] ใช้ d() helper ?></td>
                        <td><b><?= htmlspecialchars($tx['id']) ?></b></td>
                        <td><span class="transaction-type <?= $isIncome ? 'type-income' : 'type-expense' ?>"><?= $isIncome ? 'รายได้' : 'ค่าใช้จ่าย' ?></span></td>
                        <td>
                          <?= htmlspecialchars($tx['description']) ?>
                          <small class="d-block text-muted"><?= htmlspecialchars($tx['category'] ?? '') ?></small>
                        </td>
                        </td>
                        <td class="text-end"><span class="<?= $isIncome ? 'text-success' : 'text-warning' ?> fw-bold"><?= $isIncome ? '+' : '-' ?>฿<?= nf($tx['amount']) ?></span></td>
                        <td class="d-none d-lg-table-cell"><?= htmlspecialchars($tx['created_by']) ?></td> <td class="text-end pe-3">
                          <button class="btn btn-sm btn-outline-secondary btnReceipt" title="ดูใบเสร็จ"><i class="bi bi-receipt"></i></button>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              
              <?php if ($total_pages_fin > 1):
                $qs_prev = http_build_query(array_merge($base_qs, ['page_fin'=>$page_fin-1]));
                $qs_next = http_build_query(array_merge($base_qs, ['page_fin'=>$page_fin+1]));
              ?>
                <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <small class="text-muted">แสดง <?= $fin_from_i ?>–<?= $fin_to_i ?> จาก <?= (int)$total_transactions_all ?> รายการ</small>
                  <div class="btn-group">
                    <a class="btn btn-sm btn-outline-secondary <?= $page_fin<=1?'disabled':'' ?>" href="?<?= htmlspecialchars($qs_prev) ?>#financial-panel">ก่อนหน้า</a>
                    <a class="btn btn-sm btn-outline-primary  <?= $page_fin>=$total_pages_fin?'disabled':'' ?>" href="?<?= htmlspecialchars($qs_next) ?>#financial-panel">ถัดไป</a>
                  </div>
                </div>
              <?php endif; ?>
            </div>
        </div>

        <div class="tab-pane fade" id="sales-panel" role="tabpanel">
            <div class="stat-card">
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                  <div class="d-flex flex-wrap gap-2 align-items-center">
                    <div class="input-group" style="max-width:320px;">
                      <span class="input-group-text"><i class="bi bi-search"></i></span>
                      <input type="search" id="salesSearch" class="form-control" placeholder="ค้นหา: รหัสขาย/รายละเอียด/ผู้บันทึก">
                    </div>
                    <button class="btn btn-outline-secondary" id="salesShowAll" title="ล้างตัวกรอง"><i class="bi bi-arrow-clockwise"></i></button>
                  </div>
                  <span class="text-muted ms-auto">รวม <?= (int)$total_sales_all ?> รายการ | ยอดขาย ฿<?= nf($sales_total) ?></span>
                </div>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0" id="salesTable">
                    <thead class="table-light"><tr><th>วันที่</th><th>รหัสขาย</th><th>รายละเอียด</th><th class="text-end">จำนวนเงิน</th><th class="d-none d-lg-table-cell">ผู้บันทึก</th><th class="text-end">ใบเสร็จ</th></tr></thead>
                    <tbody>
                      <?php if (empty($sales_rows_display)): // [แก้ไข] ?>
                        <tr><td colspan="6" class="text-center text-muted p-4">ไม่พบข้อมูล</td></tr>
                      <?php endif; ?>
                      <?php foreach($sales_rows_display as $r): // [แก้ไข] ?>
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
                          <td class="ps-3"><?= d($r['date'], 'd/m/Y H:i') // [แก้ไข] ?></td>
                          <td><b><?= htmlspecialchars($r['code']) ?></b></td>
                          <td><?= htmlspecialchars($r['description']) ?></td>
                          <td class="text-end"><span class="text-success fw-bold">+฿<?= nf($r['amount']) ?></span></td>
                          <td class="d-none d-lg-table-cell"><?= htmlspecialchars($r['created_by']) ?></td>
                          <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-secondary btnReceipt" title="ดูใบเสร็จ">
                              <i class="bi bi-receipt"></i>
                            </button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php if ($total_pages_sales > 1):
                $qs_prev = http_build_query(array_merge($base_qs, ['page_sales'=>$page_sales-1]));
                $qs_next = http_build_query(array_merge($base_qs, ['page_sales'=>$page_sales+1]));
              ?>
                <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <small class="text-muted">แสดง <?= $sales_from_i ?>–<?= $sales_to_i ?> จาก <?= (int)$total_sales_all ?> รายการ</small>
                  <div class="btn-group">
                    <a class="btn btn-sm btn-outline-secondary <?= $page_sales<=1?'disabled':'' ?>" href="?<?= htmlspecialchars($qs_prev) ?>#sales-panel">ก่อนหน้า</a>
                    <a class="btn btn-sm btn-outline-primary  <?= $page_sales>=$total_pages_sales?'disabled':'' ?>" href="?<?= htmlspecialchars($qs_next) ?>#sales-panel">ถัดไป</a>
                  </div>
                </div>
              <?php endif; ?>
            </div>
        </div>

    </div></main>
  </div>
</div>

<footer class="footer">© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — จัดการการเงินและบัญชี</footer>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="liveToast" class="toast align-items-center text-bg-dark border-0" role="status" aria-live="polite">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">ดำเนินการสำเร็จ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  // [แก้ไข] เป็น false เสมอสำหรับกรรมการ
  const canEdit = false;

  function nf(number, decimals = 0) {
      const num = parseFloat(number) || 0;
      return num.toLocaleString('th-TH', {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals
      });
  }
  function humanMoney(v){
    const n = Number(v||0);
    if (Math.abs(n) >= 1e6) return (n/1e6).toFixed(1)+'ล.';
    if (Math.abs(n) >= 1e3) return (n/1e3).toFixed(1)+'พ.';
    return nf(n, 0);
  }
  
  const colors = {
      primary: getComputedStyle(document.documentElement).getPropertyValue('--teal').trim() || '#36535E',
      success: getComputedStyle(document.documentElement).getPropertyValue('--mint').trim() || '#20A39E',
      warning: getComputedStyle(document.documentElement).getPropertyValue('--amber').trim() || '#B66D0D',
      gold: getComputedStyle(document.documentElement).getPropertyValue('--gold').trim() || '#CCA43B',
      navy: getComputedStyle(document.documentElement).getPropertyValue('--navy').trim() || '#212845',
      steel: getComputedStyle(document.documentElement).getPropertyValue('--steel').trim() || '#68727A'
  };

  Chart.defaults.color = colors.steel;
  Chart.defaults.font.family = "'Prompt', sans-serif";
  Chart.defaults.plugins.legend.position = 'bottom';
  Chart.defaults.plugins.tooltip.backgroundColor = colors.navy;
  Chart.defaults.plugins.tooltip.titleFont.weight = 'bold';
  Chart.defaults.plugins.tooltip.bodyFont.weight = '500';

  const pieCtx = document.getElementById('pieChart')?.getContext('2d');
  const lineCtx = document.getElementById('lineChart')?.getContext('2d');

  if (pieCtx) {
    new Chart(pieCtx, {
      type: 'doughnut',
      data: {
        labels: ['รายได้','ค่าใช้จ่าย'],
        datasets: [{
          data: [<?= json_encode(round($total_income,2)) ?>, <?= json_encode(round($total_expense,2)) ?>],
          backgroundColor: [colors.success, colors.warning],
          borderColor: '#ffffff',
          borderWidth: 2
        }]
      },
      options: { responsive:true, maintainAspectRatio: false, cutout: '60%' }
    });
  }

  if (lineCtx) {
    new Chart(lineCtx, {
      type: 'line',
      data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
          { label: 'รายได้', data: <?= json_encode($seriesIncome) ?>, tension:.3, fill:false, borderColor: colors.success, borderWidth: 2, pointBackgroundColor: colors.success },
          { label: 'ค่าใช้จ่าย', data: <?= json_encode($seriesExpense) ?>, tension:.3, fill:false, borderColor: colors.warning, borderWidth: 2, pointBackgroundColor: colors.warning }
        ]
      },
      options: { 
          responsive:true, 
          maintainAspectRatio: false, 
          scales:{ y:{ beginAtZero:true, ticks: { callback: (v)=> '฿'+humanMoney(v) } } } 
      }
    });
  }

  const gpCanvas = document.getElementById('gpBarChart');
  if (gpCanvas && <?= $has_gpv ? 'true' : 'false' ?>) { // [แก้ไข] ตรวจสอบ $has_gpv
    const gpLabels = <?= json_encode($gp_labels, JSON_UNESCAPED_UNICODE) ?>;
    const gpSeries = <?= json_encode($gp_series) ?>;
    new Chart(gpCanvas, {
      type: 'bar',
      data: {
        labels: gpLabels,
        datasets: [{
          label: 'กำไรขั้นต้น',
          data: gpSeries,
          backgroundColor: colors.primary + 'B3',
          borderColor: colors.primary,
          borderWidth: 1,
          borderRadius: 4
        }]
      },
      options: { 
          responsive:true, 
          maintainAspectRatio: false, 
          scales:{ y:{ beginAtZero:true, ticks: { callback: (v)=> '฿'+humanMoney(v) } } }
      }
    });
  }

  const receiptRoutes = {
    sale:   code => `sales_receipt.php?code=${encodeURIComponent(code)}`,
    receive:id   => `receive_view.php?id=${encodeURIComponent(id)}`,
    lot:    code => `lot_view.php?code=${encodeURIComponent(code)}`,
    transaction: token => /^\d+$/.test(String(token))
    ? `txn_receipt.php?id=${encodeURIComponent(token)}`
    : `txn_receipt.php?code=${encodeURIComponent(token)}`
  };

  document.body.addEventListener('click', function(e) {
      const receiptBtn = e.target.closest('.btnReceipt');
      if (receiptBtn) {
          const tr = receiptBtn.closest('tr');
          const type = tr?.dataset?.receiptType;
          const code = tr?.dataset?.receiptCode;
          let url = (tr?.dataset?.receiptUrl || '').trim();
          
          if (!url && type && code && receiptRoutes[type]) url = receiptRoutes[type](code);
          if (!url) return showToast('ยังไม่มีลิงก์ใบเสร็จสำหรับรายการนี้', false);
          window.open(url, '_blank');
      }
  });


  const q = document.getElementById('txnSearch');
  const fType = document.getElementById('filterType');
  const fCat  = document.getElementById('filterCategory');
  const tbody = document.querySelector('#txnTable tbody');

  function normalize(s){ return (s||'').toString().toLowerCase(); }

  function applyFilters(){
    if (!tbody) return; // [เพิ่ม] ป้องกัน error
    const text = q ? normalize(q.value) : '';
    const type = fType ? fType.value : '';
    const cat  = fCat ? fCat.value : '';

    tbody.querySelectorAll('tr').forEach(tr=>{
      const d = tr.dataset; let ok = true;
      if (type && d.type !== type) ok = false;
      if (cat  && d.category !== cat) ok = false;

      if (ok && text) {
        const blob = (d.id+' '+(d.category||'')+' '+(d.description||'')+' '+(d.reference||'')).toLowerCase();
        if (!blob.includes(text)) ok = false;
      }
      tr.style.display = ok? '' : 'none';
    });
  }

  [q,fType,fCat].forEach(el=>el && el.addEventListener('input', applyFilters));

  document.getElementById('btnTxnShowAll')?.addEventListener('click', ()=>{
    if (q) q.value = '';
    if (fType) fType.value = '';
    if (fCat) fCat.value = '';
    applyFilters();
  });

  if (tbody) applyFilters();

   function wireSimpleTable({tableId, searchId, resetId}) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const q = document.getElementById(searchId);
    const resetBtn = document.getElementById(resetId);
    if (!tbody || !q || !resetBtn) return;

    const norm = s => (s||'').toString().toLowerCase();

    function apply(){
      const text = norm(q?.value || '');
      tbody.querySelectorAll('tr').forEach(tr=>{
        const ds = tr.dataset || {};
        let ok = true;
        if (ok && text) {
          const blob = [
            ds.code, ds.description, ds.createdBy, ds.amount,
            tr.textContent
          ].join(' ').toLowerCase();
          ok = blob.includes(text);
        }
        tr.style.display = ok ? '' : 'none';
      });
    }
    q.addEventListener('input', apply);
    resetBtn.addEventListener('click', ()=>{ q.value = ''; apply(); });
    apply();
  }

  // [เพิ่ม] เปิดใช้งานการค้นหาแท็บ Sales
  wireSimpleTable({ tableId:'salesTable', searchId:'salesSearch', resetId:'salesShowAll' });
  
  const toastEl = document.getElementById('liveToast'); const toastMsg = document.getElementById('toastMsg');
  const toast = toastEl ? new bootstrap.Toast(toastEl, {delay:2000}) : null;
  function showToast(msg, isSuccess = true){
    if(!toast) return alert(msg);
    toastEl.className = `toast align-items-center border-0 ${isSuccess ? 'text-bg-success' : 'text-bg-danger'}`;
    toastMsg.textContent=msg;
    toast.show();
  }

  const urlParams = new URLSearchParams(window.location.search);
  const okMsg = urlParams.get('ok');
  const errMsg = urlParams.get('err');
  if (okMsg) { showToast(okMsg, true); window.history.replaceState({}, document.title, window.location.pathname + window.location.hash); }
  if (errMsg) { showToast(errMsg, false); window.history.replaceState({}, document.title, window.location.pathname + window.location.hash); }

  const params = new URLSearchParams(location.search);
  const tab = params.get('tab');
  const map = { financial:'#financial-panel', sales:'#sales-panel' };
  if (tab && map[tab]) {
    const trigger = document.querySelector(`[data-bs-target="${map[tab]}"]`);
    if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
  } else {
    const hash = window.location.hash || '#financial-panel';
    const tabTrigger = document.querySelector(`button[data-bs-target="${hash}"]`);
    if (tabTrigger) {
        bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
    }
  }
   document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(tabEl => {
      tabEl.addEventListener('shown.bs.tab', event => {
          history.pushState(null, null, event.target.dataset.bsTarget);
      })
  });

  // [ลบ] Modal Add listener

})();
</script>
</body>
</html>