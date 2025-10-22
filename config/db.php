<?php
$DB_HOST = getenv('DB_HOST') ?: 'mysql';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'cooperative';
$DB_USER = getenv('DB_USER') ?: 'coop';
$DB_PASS = getenv('DB_PASS') ?: '2323';

if (!extension_loaded('pdo_mysql')) {
  http_response_code(500);
  exit('pdo_mysql extension is NOT loaded');
}

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

$retries = 10;
while ($retries--) {
  try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    break;
  } catch (PDOException $e) {
    if ($retries === 0) {
      http_response_code(500);
      exit('DB connect failed: ' . $e->getMessage());
    }
    sleep(1);
  }
}
