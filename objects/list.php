<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$stmt = $pdo->query("
    SELECT o.id, o.name, o.address,
           (SELECT COUNT(*) FROM posts WHERE object_id = o.id) AS post_count
    FROM objects o
    ORDER BY o.name
");
$objects = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatPostLabel(int $count): string
{
    $n = $count % 100;
    if ($n >= 11 && $n <= 19) {
        return "{$count} постов";
    }
    $remainder = $count % 10;
    if ($remainder === 1) {
        return "{$count} пост";
    }
    if ($remainder >= 2 && $remainder <= 4) {
        return "{$count} поста";
    }
    return "{$count} постов";
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Объекты</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <div class="header">
        <h2>Объекты</h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="../dashboard.php">← Назад в панель</a>
        <a href="add.php" class="add-btn">+ Добавить объект</a>
    </div>
    <div class="container">
        <?php if (empty($objects)): ?>
            <p>Объектов пока нет.</p>
        <?php else: ?>
            <div class="list-grid">
                <?php foreach ($objects as $obj): ?>
                    <div class="mini-card">
                        <h3><?= htmlspecialchars($obj['name']) ?></h3>
                        <p class="text-muted"><?= htmlspecialchars($obj['address']) ?></p>
                        <p>
                            <span class="tag"><?= htmlspecialchars(formatPostLabel((int)$obj['post_count'])) ?></span>
                        </p>
                        <div class="card-actions">
                            <a class="btn btn-primary" href="view.php?id=<?= $obj['id'] ?>">Открыть карточку</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
