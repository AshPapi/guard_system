<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$stmt = $pdo->query("
    SELECT id, full_name, position, phone, is_active, 
           (SELECT COUNT(*) FROM shifts s 
            JOIN shift_guards sg ON s.id = sg.shift_id 
            WHERE sg.guard_id = employees.id) AS total_shifts
    FROM employees
    ORDER BY is_active DESC, full_name
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сотрудники</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Сотрудники</h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="../dashboard.php">← Назад в панель</a>
        <a href="add.php" class="add-btn">+ Добавить сотрудника</a>
    </div>
    <div class="container">
        <?php if (empty($employees)): ?>
            <div class="empty-state">
                <p>Сотрудников пока нет.</p>
                <p>Добавьте первого, чтобы начать вести графики смен.</p>
            </div>
        <?php else: ?>
            <div class="list-grid">
                <?php foreach ($employees as $emp): ?>
                    <div class="mini-card">
                        <h3><?= htmlspecialchars($emp['full_name']) ?></h3>
                        <p class="text-muted"><?= $emp['position'] === 'head_guard' ? 'Главный охранник' : 'Охранник' ?></p>
                        <p>
                            <strong>Телефон:</strong>
                            <?= htmlspecialchars(formatPhone($emp['phone'])) ?>
                        </p>
                        <p>
                            <strong>Смены:</strong>
                            <?php if ($emp['position'] === 'head_guard'): ?>
                                Постоянно закреплён
                            <?php else: ?>
                                <?= (int)$emp['total_shifts'] ?>
                            <?php endif; ?>
                        </p>
                        <p>
                            <strong>Статус:</strong>
                            <?php if ($emp['is_active']): ?>
                                <span class="status-active">Работает</span>
                            <?php else: ?>
                                <span class="status-inactive">Уволен</span>
                            <?php endif; ?>
                        </p>
                        <div class="card-actions">
                            <a class="btn btn-primary" href="view.php?id=<?= $emp['id'] ?>">Открыть карточку</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    function formatPhone($digits) {
        if (!preg_match('/^7(\d{3})(\d{3})(\d{2})(\d{2})$/', $digits, $matches)) {
            return $digits;
        }
        return "+7 ({$matches[1]}) {$matches[2]}-{$matches[3]}-{$matches[4]}";
    }
    ?>
</div>
</body>
</html>
