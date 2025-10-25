<?php
// admin/setting_save.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Bangkok');

$response = ['ok' => false, 'error' => 'Invalid request'];

// --- การเชื่อมต่อและความปลอดภัย ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    $response['error'] = 'Access Denied';
    echo json_encode($response);
    exit;
}

require_once __DIR__ . '/../config/db.php'; 
if (!isset($pdo)) {
    $response['error'] = 'Database connection failed';
    echo json_encode($response);
    exit;
}

// --- Helper Function (คัดลอกมาจาก setting.php) ---
function save_settings(PDO $pdo, string $key, array $data): bool {
    // โหลดข้อมูลเก่าก่อน
    $st_load = $pdo->prepare("SELECT json_value FROM app_settings WHERE `key`=:k LIMIT 1");
    $st_load->execute([':k'=>$key]);
    $old_data = json_decode($st_load->fetchColumn() ?: '{}', true);
    if (!is_array($old_data)) $old_data = [];
    
    // ผสานข้อมูลเก่ากับข้อมูลใหม่ (ข้อมูลใหม่ทับเก่า)
    $merged_data = array_replace_recursive($old_data, $data);
    
    $json = json_encode($merged_data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare("
        INSERT INTO app_settings (`key`, json_value) VALUES (:k, CAST(:v AS JSON))
        ON DUPLICATE KEY UPDATE json_value = CAST(:v AS JSON)
    ");
    return $st->execute([':k'=>$key, ':v'=>$json]);
}

// --- ประมวลผล ---
try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data) || !isset($data['key']) || !isset($data['settings'])) {
        throw new Exception('Invalid data structure');
    }

    $key_to_save = $data['key'];
    $settings_to_save = $data['settings'];
    
    // ตรวจสอบ key ที่อนุญาต
    $allowed_keys = ['system_settings', 'notification_settings', 'security_settings'];
    if (!in_array($key_to_save, $allowed_keys)) {
        throw new Exception('Invalid settings key');
    }

    // แปลงค่า checkbox ที่ส่งมาจาก JS ( 'true'/'on' -> true )
    foreach ($settings_to_save as $k => &$v) {
        if ($v === 'on' || $v === 'true' || $v === true) {
            $v = true;
        } elseif ($v === 'off' || $v === 'false' || $v === false) {
            $v = false;
        }
        // แปลงค่าตัวเลข
        if (is_numeric($v)) {
            $v = $v + 0; // (int) or (float)
        }
    }

    if (save_settings($pdo, $key_to_save, $settings_to_save)) {
        $response['ok'] = true;
        $response['message'] = 'บันทึกการตั้งค่า ' . $key_to_save . ' สำเร็จ';
    } else {
        $response['error'] = 'ไม่สามารถบันทึกข้อมูลลงฐานข้อมูลได้';
    }

} catch (Throwable $e) {
    error_log("Setting Save Error: " . $e->getMessage());
    $response['error'] = 'Database query failed: ' . $e->getMessage();
}

echo json_encode($response);
?>

