<?php
require_once __DIR__ . '/../config/db.php';
echo "Connected. DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn();
