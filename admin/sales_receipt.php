<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

// [เพิ่ม] เชื่อมต่อฐานข้อมูล
require_once __DIR__ . '/../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ (Sales Receipt)'); }

// ... โค้ดส่วนที่เหลือของ sales_receipt.php สำหรับดึงข้อมูลการขาย ...
// $sale_code = $_GET['code'] ?? '';
// ... Query ข้อมูลจากตาราง sales และ sales_items ...
// $data = ...;
// $items = ...;

// กำหนดค่าก่อน include template
$forceType = 'sale';
require __DIR__ . '/receipt.php'; // เรียกใช้ Template
?>