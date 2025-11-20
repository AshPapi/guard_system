<?php
$DB_HOST = 'localhost';
$DB_PORT = '****';
$DB_NAME = '****';
$DB_USER = '****';
$DB_PASS = '****';

try {
    $pdo = new PDO(
        "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("Ошибка БД: " . htmlspecialchars($e->getMessage()));

}
