<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '' || $address === '') {
        $error = 'Название и адрес обязательны.';
    } else {
        try {
            $photoFilename = null;
            $uploadDir = __DIR__ . '/../uploads/objects';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $uploadDir = realpath($uploadDir) ?: $uploadDir;

            if (!empty($_FILES['photo']['name'])) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    throw new Exception('Допустимы только изображения JPG или PNG.');
                }
                if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла.');
                }
                $photoFilename = 'obj_' . time() . '.' . $ext;
                $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $photoFilename;
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                    throw new Exception('Не удалось сохранить файл.');
                }
            }

            $stmt = $pdo->prepare("INSERT INTO objects (name, address, description, photo_filename) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $address, $description, $photoFilename]);

            $success = 'Объект успешно добавлен.';
            $_POST = [];
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
    <title>Добавление объекта</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Добавление объекта</h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="../dashboard.php">На главную</a>
        <a href="list.php">К списку объектов</a>
    </div>

    <div class="container">
        <div class="form-card">
            <?php if ($error): ?>
                <div class="notification error" style="display:block;"><?= htmlspecialchars($error) ?></div>
            <?php elseif ($success): ?>
                <div class="notification success" style="display:block;"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Название *</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Адрес *</label>
                        <input type="text" name="address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Фотография (jpg, png)</label>
                    <input type="file" name="photo" accept="image/jpeg,image/png">
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description" rows="4"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Добавить объект</button>
                    <a href="list.php" class="btn btn-ghost">Вернуться к списку</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
