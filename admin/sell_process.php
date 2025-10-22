<?php
// sell_process.php — บันทึกการขาย
session_start();
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
  http_response_code(400);
  exit('Invalid CSRF token');
}

$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) { $dbFile = __DIR__ . '/config/db.php'; }
require_once $dbFile;

function toFuelEnum(string $v): string {
  $v = strtolower(trim($v));
  if ($v === 'g95') return 'gasohol95';
  if ($v === 'g91') return 'gasohol91';
  if ($v === 'e20') return 'E20';
  if ($v === 'diesel' || $v === 'benzine') return $v;
  return 'diesel';
}

try {
  $fuel      = toFuelEnum($_POST['fuel'] ?? 'diesel');
  $price     = (float)($_POST['price'] ?? 0);
  $liters    = (float)($_POST['liters'] ?? 0);
  $amount    = (float)($_POST['amount'] ?? 0);
  $discount  = (float)($_POST['discount'] ?? 0);
  $pay       = $_POST['pay'] ?? 'cash'; // cash|qr|credit|transfer
  if (!in_array($pay, ['cash','qr','credit','transfer'], true)) $pay = 'cash';

  $memberCode = trim($_POST['member_id'] ?? '');
  $center_id = $_SESSION['center_id'] ?? 1;

  if ($liters <= 0 && $price > 0 && $amount > 0) {
    $liters = round($amount / $price, 2);
  }
  if ($price <= 0 || $liters <= 0) {
    throw new RuntimeException('ข้อมูลไม่ครบ: ราคา/ลิตร และ จำนวนลิตร ต้องมากกว่า 0');
  }

  // map member_code → users.id (ผ่าน members.user_id)
  $member_user_id = null;
  if ($memberCode !== '') {
    $stm = $pdo->prepare("
      SELECT u.id FROM members m
      JOIN users u ON u.id = m.user_id
      WHERE m.member_code = :code LIMIT 1
    ");
    $stm->execute([':code' => $memberCode]);
    $member_user_id = $stm->fetchColumn() ?: null;
  }

  $amount_before_discount = $price * $liters;
  $subtotal = max($amount_before_discount - $discount, 0);
  $vat = round($subtotal * 0.07, 2);
  $grand = $subtotal + $vat;

  $pdo->beginTransaction();

  $st1 = $pdo->prepare("
    INSERT INTO sales (sold_at, member_id, center_id, subtotal, discount, vat, grand_total, pay_method)
    VALUES (NOW(), :mid, :cid, :sub, :disc, :vat, :grand, :pay)
  ");
  $st1->execute([
    ':mid'   => $member_user_id,
    ':cid'   => $center_id,
    ':sub'   => $subtotal,
    ':disc'  => $discount,
    ':vat'   => $vat,
    ':grand' => $grand,
    ':pay'   => $pay,
  ]);
  $sale_id = (int)$pdo->lastInsertId();

  $st2 = $pdo->prepare("
    INSERT INTO sales_items (sale_id, fuel_type, liters, unit_price)
    VALUES (:sid, :fuel, :liters, :price)
  ");
  $st2->execute([
    ':sid'    => $sale_id,
    ':fuel'   => $fuel,
    ':liters' => $liters,
    ':price'  => $price,
  ]);

  $pdo->commit();

  header('Location: sell.php?ok=1&sale_id=' . $sale_id);
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: sell.php?err=' . urlencode($e->getMessage()));
  exit;
}
