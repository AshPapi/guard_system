<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$isHeadGuard = false;
$stmt = $pdo->prepare("
    SELECT 1 
    FROM employees e 
    WHERE e.user_id = ? AND e.position = 'head_guard' AND e.is_active = true
");
$stmt->execute([$_SESSION['user_id']]);
$isHeadGuard = (bool)$stmt->fetch();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель управления</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h1>
            <?php if ($isHeadGuard): ?>
                Панель главного охранника
            <?php else: ?>
                Панель администратора
            <?php endif; ?>
        </h1>
        <a href="logout.php">Выйти</a>
    </div>

    <div class="container">
        <div class="card">
            <h2 class="section-title">Выберите действие</h2>
            <div class="menu">
                <?php if ($isHeadGuard): ?>
                    <a href="distribution_head.php">Распределение</a>
                    <a href="reports_head.php">Отчёты</a>
                <?php else: ?>
                    <a href="employees/list.php">Сотрудники</a>
                    <a href="objects/list.php">Объекты и посты</a>
                    <a href="distribution.php">Распределение</a>
                    <a href="reports/dismissed.php">Отчёты</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
