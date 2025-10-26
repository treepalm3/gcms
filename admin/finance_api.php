<?php
// /api/finance_api.php
header('Content-Type: application/json; charset=utf-8');
session_start();

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

// CSRF Protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']);
        exit;
    }
}

// เชื่อมต่อฐานข้อมูล
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database config not found']);
    exit;
}

require_once $dbFile;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? '';
$user_id = (int)$_SESSION['user_id'];

// ดึง station_id
$stationId = 1;
try {
    $sid = $pdo->query("SELECT setting_value FROM settings WHERE setting_name='station_id' LIMIT 1")->fetchColumn();
    if ($sid !== false) $stationId = (int)$sid;
} catch (Throwable $e) {}

// ตรวจสอบว่ามีตาราง financial_transactions หรือไม่
$has_ft = false;
try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:db AND table_name=:tb');
    $st->execute([':db'=>$db, ':tb'=>'financial_transactions']);
    $has_ft = (int)$st->fetchColumn() > 0;
} catch (Throwable $e) {}

if (!$has_ft) {
    echo json_encode(['ok' => false, 'error' => 'ตาราง financial_transactions ไม่มีในระบบ']);
    exit;
}

try {
    switch ($action) {
        case 'create':
            // เพิ่มรายการใหม่
            $transaction_code = trim($_POST['transaction_code'] ?? '');
            $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
            $type = $_POST['type'] ?? '';
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $reference_id = trim($_POST['reference_id'] ?? '');

            // Validate
            if (empty($transaction_code)) {
                throw new Exception('กรุณาระบุรหัสรายการ');
            }
            if (!in_array($type, ['income', 'expense'])) {
                throw new Exception('ประเภทไม่ถูกต้อง');
            }
            if (empty($category)) {
                throw new Exception('กรุณาระบุหมวดหมู่');
            }
            if (empty($description)) {
                throw new Exception('กรุณาระบุรายละเอียด');
            }
            if ($amount <= 0) {
                throw new Exception('จำนวนเงินต้องมากกว่า 0');
            }

            // ตรวจสอบรหัสซ้ำ
            $check = $pdo->prepare("SELECT COUNT(*) FROM financial_transactions WHERE transaction_code = ?");
            $check->execute([$transaction_code]);
            if ($check->fetchColumn() > 0) {
                throw new Exception('รหัสรายการนี้มีอยู่แล้ว');
            }

            // Insert
            $sql = "INSERT INTO financial_transactions 
                    (station_id, transaction_code, transaction_date, type, category, description, amount, reference_id, user_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $stationId,
                $transaction_code,
                $transaction_date,
                $type,
                $category,
                $description,
                $amount,
                $reference_id ?: null,
                $user_id
            ]);

            echo json_encode([
                'ok' => true,
                'message' => 'บันทึกสำเร็จ',
                'id' => $pdo->lastInsertId()
            ]);
            break;

        case 'update':
            // แก้ไขรายการ
            $transaction_code = trim($_POST['transaction_code'] ?? '');
            $transaction_date = $_POST['transaction_date'] ?? '';
            $type = $_POST['type'] ?? '';
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $reference_id = trim($_POST['reference_id'] ?? '');

            if (empty($transaction_code)) {
                throw new Exception('ไม่พบรหัสรายการ');
            }

            // Validate
            if (!in_array($type, ['income', 'expense'])) {
                throw new Exception('ประเภทไม่ถูกต้อง');
            }
            if ($amount <= 0) {
                throw new Exception('จำนวนเงินต้องมากกว่า 0');
            }

            $sql = "UPDATE financial_transactions 
                    SET transaction_date = ?, type = ?, category = ?, description = ?, amount = ?, reference_id = ?, updated_at = NOW()
                    WHERE transaction_code = ? AND station_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $transaction_date,
                $type,
                $category,
                $description,
                $amount,
                $reference_id ?: null,
                $transaction_code,
                $stationId
            ]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('ไม่พบรายการที่ต้องการแก้ไข');
            }

            echo json_encode(['ok' => true, 'message' => 'แก้ไขสำเร็จ']);
            break;

        case 'delete':
            // ลบรายการ
            $transaction_code = trim($_POST['transaction_code'] ?? '');

            if (empty($transaction_code)) {
                throw new Exception('ไม่พบรหัสรายการ');
            }

            $sql = "DELETE FROM financial_transactions WHERE transaction_code = ? AND station_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$transaction_code, $stationId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('ไม่พบรายการที่ต้องการลบ');
            }

            echo json_encode(['ok' => true, 'message' => 'ลบสำเร็จ']);
            break;

        default:
            throw new Exception('Action ไม่ถูกต้อง');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}