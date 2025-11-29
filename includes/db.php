<?php
$DB_HOST = 'localhost';
$DB_PORT = '2005';
$DB_NAME = 'guard_db';
$DB_USER = 'postgres';
$DB_PASS = '12345678';

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