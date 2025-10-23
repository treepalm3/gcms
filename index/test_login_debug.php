<?php
// ไฟล์: test_login_debug.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Login System Debug Test</h1>";
echo "<hr>";

// Test 1: เชื่อมต่อฐานข้อมูล
echo "<h2>📡 Test 1: Database Connection</h2>";
try {
    require_once __DIR__ . '/../config/db.php';
    echo "✅ เชื่อมต่อฐานข้อมูลสำเร็จ<br>";
    echo "📊 Database Info: " . $pdo->getAttribute(PDO::ATTR_SERVER_INFO) . "<br><br>";
} catch (Exception $e) {
    echo "❌ เชื่อมต่อฐานข้อมูลไม่ได้: " . $e->getMessage() . "<br><br>";
    exit();
}

// Test 2: ตรวจสอบตาราง settings
echo "<h2>⚙️ Test 2: Settings Table</h2>";
try {
    // เช็คว่าตารางมีอยู่หรือไม่
    $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
    if ($stmt->rowCount() == 0) {
        echo "❌ ไม่พบตาราง 'settings'<br>";
        echo "📝 สร้างตาราง settings:<br>";
        echo "<code>CREATE TABLE settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_name VARCHAR(100), setting_value TEXT, comment TEXT);</code><br><br>";
    } else {
        echo "✅ พบตาราง 'settings'<br>";
        
        // เช็คโครงสร้างตาราง
        $stmt = $pdo->query("DESCRIBE settings");
        echo "📋 โครงสร้างตาราง:<br>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $stmt->fetch()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // เช็คข้อมูลใน settings
        $stmt = $pdo->query("SELECT * FROM settings");
        echo "📄 ข้อมูลใน settings:<br>";
        if ($stmt->rowCount() == 0) {
            echo "❌ ไม่มีข้อมูลในตาราง settings<br>";
            echo "📝 เพิ่มข้อมูล:<br>";
            echo "<code>INSERT INTO settings (setting_name, setting_value, comment) VALUES ('station_id', '1', 'ID ของสถานีหลัก');</code><br>";
        } else {
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            echo "<tr><th>ID</th><th>Setting Name</th><th>Setting Value</th><th>Comment</th></tr>";
            while ($row = $stmt->fetch()) {
                echo "<tr>";
                echo "<td>{$row['id']}</td>";
                echo "<td>{$row['setting_name']}</td>";
                echo "<td>{$row['setting_value']}</td>";
                echo "<td>{$row['comment']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // ทดสอบดึง station_id
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name = 'station_id' LIMIT 1");
        $stmt->execute();
        $setting = $stmt->fetch();
        
        if ($setting) {
            echo "✅ พบ station_id = {$setting['setting_value']}<br><br>";
        } else {
            echo "❌ ไม่พบ setting_name = 'station_id'<br><br>";
        }
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br><br>";
}

// Test 3: ตรวจสอบตาราง users
echo "<h2>👤 Test 3: Users Table</h2>";
try {
    $stmt = $pdo->query("SELECT id, username, email, full_name, is_active, last_login_at FROM users");
    echo "📊 ข้อมูลผู้ใช้:<br>";
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Full Name</th><th>Active</th><th>Last Login</th></tr>";
    while ($row = $stmt->fetch()) {
        $activeStatus = $row['is_active'] == 1 ? '✅ Active' : '❌ Inactive';
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['username']}</td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>{$row['full_name']}</td>";
        echo "<td>{$activeStatus}</td>";
        echo "<td>{$row['last_login_at']}</td>";
        echo "</tr>";
    }
    echo "</table><br>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br><br>";
}

// Test 4: ตรวจสอบตารางสมาชิก
echo "<h2>👥 Test 4: Member Tables</h2>";
$memberTables = ['admins', 'managers', 'employees', 'committees', 'members'];

foreach ($memberTables as $table) {
    try {
        echo "<h3>📋 ตาราง: {$table}</h3>";
        
        // เช็คว่าตารางมีอยู่หรือไม่
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() == 0) {
            echo "❌ ไม่พบตาราง '{$table}'<br>";
            echo "📝 สร้างตาราง:<br>";
            echo "<code>CREATE TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, station_id INT DEFAULT 1);</code><br>";
            continue;
        }
        
        // เช็คโครงสร้าง
        $stmt = $pdo->query("DESCRIBE {$table}");
        echo "🏗️ โครงสร้าง: ";
        $columns = [];
        while ($row = $stmt->fetch()) {
            $columns[] = $row['Field'];
        }
        echo implode(', ', $columns) . "<br>";
        
        // เช็คข้อมูล
        $stmt = $pdo->query("SELECT * FROM {$table}");
        $count = $stmt->rowCount();
        echo "📊 จำนวนข้อมูล: {$count} รายการ<br>";
        
        if ($count > 0) {
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            $firstRow = true;
            while ($row = $stmt->fetch()) {
                if ($firstRow) {
                    echo "<tr>";
                    foreach (array_keys($row) as $key) {
                        if (is_string($key)) {
                            echo "<th>{$key}</th>";
                        }
                    }
                    echo "</tr>";
                    $firstRow = false;
                }
                echo "<tr>";
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        echo "<td>{$value}</td>";
                    }
                }
                echo "</tr>";
            }
            echo "</table>";
        }
        echo "<br>";
        
    } catch (Exception $e) {
        echo "❌ Error in {$table}: " . $e->getMessage() . "<br><br>";
    }
}

// Test 5: ทดสอบการ login
echo "<h2>🔐 Test 5: Login Simulation</h2>";

// ข้อมูลทดสอบ
$testLogins = [
    ['username' => 'admin', 'password' => 'password', 'role' => 'admin'],
    ['username' => 'manager1', 'password' => 'password', 'role' => 'manager'],
    ['username' => 'emp1', 'password' => 'password', 'role' => 'employee'],
    ['username' => 'member1', 'password' => 'password', 'role' => 'member']
];

foreach ($testLogins as $testLogin) {
    echo "<h3>🧪 ทดสอบ: {$testLogin['username']} ({$testLogin['role']})</h3>";
    
    try {
        // ค้นหา user
        $stmt = $pdo->prepare("SELECT id, username, email, password_hash, is_active FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $testLogin['username']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo "❌ ไม่พบผู้ใช้ {$testLogin['username']}<br>";
            continue;
        }
        
        echo "✅ พบผู้ใช้: {$user['username']} (ID: {$user['id']})<br>";
        echo "📊 สถานะ: " . ($user['is_active'] == 1 ? 'Active' : 'Inactive') . "<br>";
        
        // ทดสอบรหัsผ่าน
        if (password_verify($testLogin['password'], $user['password_hash'])) {
            echo "✅ รหัสผ่านถูกต้อง<br>";
        } else {
            echo "❌ รหัสผ่านไม่ถูกต้อง<br>";
            echo "🔍 Hash: " . substr($user['password_hash'], 0, 50) . "...<br>";
            continue;
        }
        
        // ทดสอบการเป็นสมาชิก
        $role = $testLogin['role'];
        $tableName = ($role == 'admin') ? 'admins' : 
                    (($role == 'manager') ? 'managers' : 
                    (($role == 'employee') ? 'employees' : 
                    (($role == 'committee') ? 'committees' : 'members')));
        
        // เช็คว่าตารางมี station_id หรือไม่
        $stmt = $pdo->query("SHOW COLUMNS FROM {$tableName} LIKE 'station_id'");
        $hasStationId = $stmt->rowCount() > 0;
        
        if ($hasStationId) {
            $stmt = $pdo->prepare("SELECT id FROM {$tableName} WHERE user_id = :user_id AND station_id = 1 LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT id FROM {$tableName} WHERE user_id = :user_id LIMIT 1");
        }
        
        $stmt->execute([':user_id' => $user['id']]);
        $memberRecord = $stmt->fetch();
        
        if ($memberRecord) {
            echo "✅ เป็นสมาชิก {$role}<br>";
            echo "🎯 <strong>Login นี้ควรจะสำเร็จ!</strong><br>";
        } else {
            echo "❌ ไม่เป็นสมาชิก {$role}<br>";
            echo "📝 เพิ่มข้อมูล: <code>INSERT INTO {$tableName} (user_id, station_id) VALUES ({$user['id']}, 1);</code><br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "<br>";
    }
    
    echo "<br>";
}

// Test 6: สร้าง SQL แก้ไข
echo "<h2>🛠️ Test 6: Fix SQL Commands</h2>";
echo "<h3>📝 คำสั่ง SQL สำหรับแก้ไขปัญหา:</h3>";
echo "<textarea rows='20' cols='100' style='font-family: monospace;'>";

echo "-- 1. เพิ่มข้อมูล settings\n";
echo "INSERT INTO settings (setting_name, setting_value, comment) VALUES ('station_id', '1', 'ID ของสถานีหลัก') ON DUPLICATE KEY UPDATE setting_value = '1';\n\n";

echo "-- 2. สร้างตารางสมาชิกหากไม่มี\n";
foreach ($memberTables as $table) {
    echo "CREATE TABLE IF NOT EXISTS {$table} (\n";
    echo "    id INT AUTO_INCREMENT PRIMARY KEY,\n";
    echo "    user_id INT NOT NULL,\n";
    echo "    station_id INT DEFAULT 1,\n";
    echo "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n";
    echo ");\n\n";
}

echo "-- 3. เพิ่มข้อมูลสมาชิก\n";
echo "INSERT INTO admins (user_id, station_id) VALUES (1, 1) ON DUPLICATE KEY UPDATE station_id = 1;\n";
echo "INSERT INTO managers (user_id, station_id) VALUES (2, 1) ON DUPLICATE KEY UPDATE station_id = 1;\n";
echo "INSERT INTO employees (user_id, station_id) VALUES (3, 1) ON DUPLICATE KEY UPDATE station_id = 1;\n";
echo "INSERT INTO members (user_id, station_id) VALUES (4, 1) ON DUPLICATE KEY UPDATE station_id = 1;\n\n";

echo "-- 4. อัปเดตรหัสผ่านเป็น 'password'\n";
$hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
echo "UPDATE users SET password_hash = '{$hash}' WHERE username IN ('admin', 'manager1', 'emp1', 'member1');\n\n";

echo "-- 5. เปิดสถานะผู้ใช้\n";
echo "UPDATE users SET is_active = 1 WHERE username IN ('admin', 'manager1', 'emp1', 'member1');\n";

echo "</textarea>";

echo "<p><strong>📋 วิธีใช้:</strong></p>";
echo "<ol>";
echo "<li>คัดลอก SQL ข้างบนไปรันใน phpMyAdmin หรือ MySQL client</li>";
echo "<li>Refresh หน้านี้เพื่อเช็คผลลัพธ์</li>";
echo "<li>ทดสอบ login อีกครั้ง</li>";
echo "</ol>";

echo "<hr>";
echo "<p>🔄 <a href='{$_SERVER['PHP_SELF']}'>Refresh Test</a> | ";
echo "🏠 <a href='login.php'>กลับไปหน้า Login</a></p>";
?>