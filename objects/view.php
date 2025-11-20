<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit;
}

$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM objects WHERE id = ?");
$stmt->execute([$id]);
$object = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$object) {
    header('Location: list.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM posts WHERE object_id = ? ORDER BY post_number");
$stmt->execute([$id]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($object['name']) ?></title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <div class="header">
        <h2>Объект: <?= htmlspecialchars($object['name']) ?></h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="list.php">← Назад к списку</a>
    </div>

    <div class="container">
        <div class="action-toolbar">
            <a href="edit.php?id=<?= $id ?>" class="btn btn-primary">Редактировать</a>
            <a href="add_post.php?object_id=<?= $id ?>" class="btn btn-ghost">+ Добавить пост</a>
            <a href="delete.php?id=<?= $id ?>" class="btn btn-danger"
               onclick="return confirm('Удалить объект и все связанные посты?')">Удалить объект</a>
        </div>

        <div class="card object-summary">
            <div class="object-info">
                <div class="info-row">
                    <span class="info-label">Адрес:</span>
                    <?= htmlspecialchars($object['address']) ?>
                </div>
                <?php if (!empty($object['description'])): ?>
                    <div class="info-row">
                        <span class="info-label">Описание:</span>
                        <?= nl2br(htmlspecialchars($object['description'])) ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($object['photo_filename'])): ?>
                <div class="object-photo">
                    <img src="/uploads/objects/<?= urlencode($object['photo_filename']) ?>" alt="Фото объекта">
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3 class="section-title">Посты (<?= count($posts) ?>)</h3>
            <?php if ($posts): ?>
                <div class="list-grid">
                    <?php foreach ($posts as $post): ?>
                        <div class="mini-card">
                            <h4>Пост №<?= (int)$post['post_number'] ?></h4>
                            <p class="text-muted">Откройте карточку, чтобы посмотреть смены или удалить пост.</p>
                            <div class="card-actions">
                                <a class="btn btn-primary" href="../posts/view.php?id=<?= $post['id'] ?>">Карточка поста</a>
                                <a class="btn btn-danger"
                                   href="delete_post.php?id=<?= $post['id'] ?>"
                                   onclick="return confirm('Удалить этот пост и связанные смены?')">Удалить</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">Посты ещё не добавлены.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
