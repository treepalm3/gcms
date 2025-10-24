<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

// [เพิ่ม] เชื่อมต่อฐานข้อมูล
require_once __DIR__ . '/../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ (Receive View)'); }

// ... โค้ดส่วนที่เหลือของ receive_view.php สำหรับดึงข้อมูล fuel_receives ...
// $receive_id = $_GET['id'] ?? '';
// ... Query ข้อมูล ...
// $data = ...;

// กำหนดค่าก่อน include template
$forceType = 'receive';
require __DIR__ . '/receipt.php'; // เรียกใช้ Template
?>