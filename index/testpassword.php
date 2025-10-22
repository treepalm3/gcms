<?php

$test_password = "password";
echo $test_password;

$stored_hash = "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi";
echo $stored_hash;

if (password_verify($test_password, $stored_hash)) {
    echo "<br>รหัสผ่านถูกต้อง!";
}

?>