<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$stmt = $pdo->query("
    SELECT 
        o.id AS object_id,
        o.name AS object_name,
        p.id AS post_id,
        p.post_number,
        hg.full_name AS head_guard_name
    FROM objects o
    LEFT JOIN posts p ON o.id = p.object_id
    LEFT JOIN employees hg ON p.head_guard_id = hg.id
    ORDER BY o.name, p.post_number
");
$rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$objects = [];
foreach ($rawData as $row) {
    $objId = $row['object_id'];
    if (!isset($objects[$objId])) {
        $objects[$objId] = [
            'name' => $row['object_name'],
            'posts' => []
        ];
    }
    if ($row['post_id']) {
        $objects[$objId]['posts'][] = [
            'id' => $row['post_id'],
            'number' => $row['post_number'],
            'head_guard' => $row['head_guard_name']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Распределение</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Распределение</h2>
        <a href="logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">← Назад в панель</a>
    </div>

    <div class="container">
        <?php if (empty($objects)): ?>
            <div class="card">
                <p class="text-muted">Объектов пока нет.</p>
            </div>
        <?php else: ?>
            <?php foreach ($objects as $object): ?>
                <div class="card">
                    <h3 class="object-title"><?= htmlspecialchars($object['name']) ?></h3>
                    <?php if (empty($object['posts'])): ?>
                        <p class="text-muted">Посты ещё не созданы.</p>
                    <?php else: ?>
                        <?php foreach ($object['posts'] as $post): ?>
                            <div class="post-item">
                                <div class="post-info">
                                    <div class="post-number">Пост №<?= (int)$post['number'] ?></div>
                                    <div class="head-guard">Главный охранник: <?= htmlspecialchars($post['head_guard'] ?? '—') ?></div>
                                </div>
                                <a href="posts/manage.php?id=<?= $post['id'] ?>" class="post-link">Управлять</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
