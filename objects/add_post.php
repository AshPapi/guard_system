<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['object_id']) || !is_numeric($_GET['object_id'])) {
    header('Location: list.php');
    exit;
}

$object_id = (int)$_GET['object_id'];
$stmt = $pdo->prepare("SELECT name FROM objects WHERE id = ?");
$stmt->execute([$object_id]);
$object = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$object) {
    header('Location: list.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headGuardId = (int)($_POST['head_guard_id'] ?? 0);
    if (!$headGuardId) {
        $error = 'Выберите главного охранника.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM posts WHERE head_guard_id = ?");
            $stmt->execute([$headGuardId]);
            if ($stmt->fetch()) {
                throw new Exception('Этот главный охранник уже закреплён за другим постом.');
            }

            $stmt = $pdo->prepare("SELECT COALESCE(MAX(post_number), 0) + 1 FROM posts WHERE object_id = ?");
            $stmt->execute([$object_id]);
            $next_number = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO posts (object_id, post_number, head_guard_id) VALUES (?, ?, ?)");
            $stmt->execute([$object_id, $next_number, $headGuardId]);

            $success = "Пост №{$next_number} создан!";
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
    <title>Добавить пост</title>
    <link rel="stylesheet" href="../assets/styles.css">
    </head>
<body>
    <div class="container">
        <h2>Добавить пост</h2>
        <div class="info">
            <p>Объект: <strong><?= htmlspecialchars($object['name']) ?></strong></p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
            <a href="view.php?id=<?= $object_id ?>" class="btn">Перейти к объекту</a>
        <?php else: ?>
            <form method="POST">
                <label>Главный охранник (обязательно)</label>
                <select name="head_guard_id" required>
                    <option value="">— Выберите —</option>
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
                <button type="submit" class="btn">Создать пост</button>
                <a href="view.php?id=<?= $object_id ?>" style="margin-left: 10px; text-decoration: none; display: inline-block; padding: 10px 16px; background: #6c757d; color: white; border-radius: 4px;">Отмена</a>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>