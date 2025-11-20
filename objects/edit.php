<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit;
}

$objectId = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM objects WHERE id = ?");
$stmt->execute([$objectId]);
$object = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$object) {
    header('Location: list.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!$name || !$address) {
        $error = 'Название и адрес обязательны.';
    } else {
        try {
            $photoFilename = $object['photo_filename'];
            $uploadDir = __DIR__ . '/../uploads/objects';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $uploadDir = realpath($uploadDir) ?: $uploadDir;

            if (!empty($_FILES['photo']['name'])) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    throw new Exception('Разрешены только JPG/PNG.');
                }
                if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла.');
                }
                if ($photoFilename) {
                    $oldPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $photoFilename;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }
                $photoFilename = 'obj_' . time() . '.' . $ext;
                $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $photoFilename;
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                    throw new Exception('Не удалось сохранить файл.');
                }
            }

            $stmt = $pdo->prepare("UPDATE objects SET name = ?, address = ?, description = ?, photo_filename = ? WHERE id = ?");
            $stmt->execute([$name, $address, $description, $photoFilename, $objectId]);

            $success = 'Объект обновлён.';
            $object['name'] = $name;
            $object['address'] = $address;
            $object['description'] = $description;
            $object['photo_filename'] = $photoFilename;
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
    <title>Редактировать объект</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <div class="container">
        <h2>Редактировать объект</h2>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Название *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($object['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Адрес *</label>
                <input type="text" name="address" value="<?= htmlspecialchars($object['address']) ?>" required>
            </div>
            <div class="form-group">
                <label>Фото (jpg, png)</label>
                <?php if ($object['photo_filename']): ?>
                    <div class="photo-preview">
                        <img src="/uploads/objects/<?= urlencode($object['photo_filename']) ?>" alt="Фото объекта">
                    </div>
                <?php endif; ?>
                <input type="file" name="photo" accept="image/jpeg,image/png">
            </div>
            <div class="form-group">
                <label>Описание</label>
                <textarea name="description"><?= htmlspecialchars($object['description']) ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Сохранить</button>
                <a href="view.php?id=<?= $objectId ?>" class="btn btn-ghost">Назад</a>
            </div>
        </form>
    </div>
</body>
</html>
