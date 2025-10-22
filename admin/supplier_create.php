<?php
// supplier_create.php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: inventory.php?err=Method ไม่ถูกต้อง'); exit;
}
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: /index/login.php?err=สิทธิ์ไม่พอ'); exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  header('Location: inventory.php?err=CSRF ไม่ถูกต้อง'); exit;
}

require_once __DIR__ . '/../config/db.php';

$supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
$contact       = trim((string)($_POST['contact_person'] ?? ''));
$phone         = trim((string)($_POST['phone'] ?? ''));
$email         = trim((string)($_POST['email'] ?? ''));
$fuel_types    = trim((string)($_POST['fuel_types'] ?? ''));

if ($supplier_name === '') {
  header('Location: inventory.php?err=กรุณากรอกชื่อบริษัท'); exit;
}

try {
  $st = $pdo->prepare("
    INSERT INTO suppliers (supplier_name, contact_person, phone, email, fuel_types, rating)
    VALUES (?,?,?,?,?,0)
  ");
  $st->execute([$supplier_name, $contact, $phone, $email, $fuel_types]);

  header('Location: inventory.php?ok=เพิ่มซัพพลายเออร์สำเร็จ');
} catch (Throwable $e) {
  header('Location: inventory.php?err=ไม่สามารถเพิ่มซัพพลายเออร์ได้');
}
