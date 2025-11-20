<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ../objects/list.php');
    exit;
}

$postId = (int)$_GET['id'];

$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.post_number,
        p.object_id,
        o.name AS object_name,
        hg.full_name AS head_guard_name
    FROM posts p
    JOIN objects o ON p.object_id = o.id
    LEFT JOIN employees hg ON p.head_guard_id = hg.id
    WHERE p.id = ?
");
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: ../objects/list.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        e.full_name,
        s.start_time,
        s.end_time
    FROM shifts s
    JOIN shift_guards sg ON s.id = sg.shift_id
    JOIN employees e ON sg.guard_id = e.id
    WHERE s.post_id = ? AND s.status = 'active'
    ORDER BY s.start_time
");
$stmt->execute([$postId]);
$rawGuards = $stmt->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime();
$currentGuards = [];
foreach ($rawGuards as $guard) {
    $start = new DateTime($guard['start_time']);
    $end = $guard['end_time'] ? new DateTime($guard['end_time']) : null;

    if ($end && $now > $end) {
        $status = 'completed';
    } elseif ($now >= $start && (!$end || $now <= $end)) {
        $status = 'active';
    } else {
        $status = 'planned';
    }

    $currentGuards[] = [
        'full_name' => $guard['full_name'],
        'start_time' => $guard['start_time'],
        'end_time' => $guard['end_time'],
        'status' => $status,
    ];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Пост №<?= (int)$post['post_number'] ?> — <?= htmlspecialchars($post['object_name']) ?></title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <div class="header">
        <h2>Пост №<?= (int)$post['post_number'] ?> — <?= htmlspecialchars($post['object_name']) ?></h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="../objects/list.php">← Список объектов</a>
        <a href="../objects/view.php?id=<?= $post['object_id'] ?>">Карточка объекта</a>
    </div>

    <div class="container">
        <div class="action-toolbar">
            <a href="../objects/delete_post.php?id=<?= $post['id'] ?>" 
               class="btn btn-danger"
               onclick="return confirm('Удалить пост из объекта?')">Удалить пост</a>
        </div>

        <div class="card">
            <h3>Информация о посте</h3>
            <p><strong>Объект:</strong> <?= htmlspecialchars($post['object_name']) ?></p>
            <p><strong>Главный охранник:</strong> <?= htmlspecialchars($post['head_guard_name'] ?? 'нет данных') ?></p>
        </div>

        <div class="card">
            <h3>Охранники на посту</h3>
            <?php if ($currentGuards): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Охранник</th>
                            <th>Начало смены</th>
                            <th>Окончание смены</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentGuards as $guard): ?>
                            <tr>
                                <td><?= htmlspecialchars($guard['full_name']) ?></td>
                                <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($guard['start_time']))) ?></td>
                                <td><?= $guard['end_time'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($guard['end_time']))) : '—' ?></td>
                                <td>
                                    <?php if ($guard['status'] === 'active'): ?>
                                        <span class="status-active">В работе</span>
                                    <?php elseif ($guard['status'] === 'planned'): ?>
                                        <span class="status-pending">Предстоящая</span>
                                    <?php else: ?>
                                        <span class="status-completed">Завершена</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Нет активных смен на этом посту.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
