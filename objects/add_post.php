<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['object_id']) || !is_numeric($_GET['object_id'])) {
    header('Location: list.php');
    exit;
}

$objectId = (int)$_GET['object_id'];
$stmt = $pdo->prepare("SELECT name FROM objects WHERE id = ?");
$stmt->execute([$objectId]);
$object = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$object) {
    header('Location: list.php');
    exit;
}

$error = '';
$success = '';
$createdPostNumber = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headGuardId = (int)($_POST['head_guard_id'] ?? 0);
    if (!$headGuardId) {
        $error = 'Выберите старшего охранника.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM posts WHERE head_guard_id = ?");
            $stmt->execute([$headGuardId]);
            if ($stmt->fetch()) {
                throw new Exception('Этот старший уже закреплён за другим постом.');
            }

            $stmt = $pdo->prepare("SELECT COALESCE(MAX(post_number), 0) + 1 FROM posts WHERE object_id = ?");
            $stmt->execute([$objectId]);
            $nextNumber = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO posts (object_id, post_number, head_guard_id) VALUES (?, ?, ?)");
            $stmt->execute([$objectId, $nextNumber, $headGuardId]);

            $success = "Пост №{$nextNumber} создан.";
            $createdPostNumber = $nextNumber;
        } catch (Exception $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавление поста</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Добавление поста</h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="../dashboard.php">На главную</a>
        <a href="list.php">Список объектов</a>
        <a href="view.php?id=<?= $objectId ?>">Карточка объекта</a>
    </div>

    <div class="container">
        <div class="card" style="margin-bottom:20px;">
            <h3 class="section-title">Объект</h3>
            <p><strong>Название:</strong> <?= htmlspecialchars($object['name']) ?></p>
        </div>

        <div class="form-card">
            <?php if ($error): ?>
                <div class="notification error" style="display:block;"><?= htmlspecialchars($error) ?></div>
            <?php elseif ($success): ?>
                <div class="notification success" style="display:block;"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="form-actions" style="margin-top:8px;">
                    <a href="view.php?id=<?= $objectId ?>" class="btn btn-primary">Вернуться к объекту</a>
                    <a href="add_post.php?object_id=<?= $objectId ?>" class="btn btn-ghost">Добавить ещё</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Старший охранник (свободные)</label>
                        <select name="head_guard_id" required>
                            <option value="">Выберите сотрудника</option>
                            <?php
                            $headGuards = $pdo->query("
                                SELECT id, full_name 
                                FROM employees 
                                WHERE position = 'head_guard' 
                                  AND is_active = true
                                  AND id NOT IN (SELECT head_guard_id FROM posts WHERE head_guard_id IS NOT NULL)
                                ORDER BY full_name
                            ");
                            while ($hg = $headGuards->fetch(PDO::FETCH_ASSOC)): ?>
                                <option value="<?= (int)$hg['id'] ?>"><?= htmlspecialchars($hg['full_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Создать пост</button>
                        <a href="view.php?id=<?= $objectId ?>" class="btn btn-ghost">Отмена</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
