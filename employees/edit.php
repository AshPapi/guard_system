<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$error = '';
$success = '';

function formatPhone($digits) {
    if (!preg_match('/^7(\d{3})(\d{3})(\d{2})(\d{2})$/', $digits, $matches)) {
        return $digits;
    }
    return "+7 ({$matches[1]}) {$matches[2]}-{$matches[3]}-{$matches[4]}";
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit;
}

$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: list.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $position = $_POST['position'] ?? '';
    $phone_digits = $_POST['phone'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if (!$full_name || !preg_match('/^[а-яёa-z\s\-]+$/iu', $full_name) || count(explode(' ', $full_name)) < 2) {
        $error = 'ФИО должно содержать минимум имя и фамилию.';
    } elseif (!preg_match('/^7\d{10}$/', $phone_digits)) {
        $error = 'Неверный формат телефона.';
    } else {
        try {
            $photo_filename = $employee['photo_filename'];

            if (!empty($_FILES['photo']['name'])) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    if ($employee['photo_filename']) {
                        $oldPath = __DIR__ . "/../uploads/employees/" . $employee['photo_filename'];
                        if (file_exists($oldPath)) unlink($oldPath);
                    }
                    $photo_filename = 'emp_' . time() . '.' . $ext;
                    $target = __DIR__ . '/../uploads/employees/' . $photo_filename;
                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                        throw new Exception('Не удалось сохранить фото.');
                    }
                }
            }

            $stmt = $pdo->prepare("
                UPDATE employees 
                SET full_name = ?, position = ?, phone = ?, photo_filename = ?, description = ?
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $position, $phone_digits, $photo_filename, $description, $id]);

            $success = 'Данные сотрудника обновлены!';

        } catch (Exception $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$full_name = htmlspecialchars($employee['full_name']);
$position = $employee['position'];
$phone_display = formatPhone($employee['phone']);
$description = htmlspecialchars($employee['description']);
$photo_filename = $employee['photo_filename'];
$hire_date = $employee['hire_date'];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Редактировать сотрудника</title>
    <link rel="stylesheet" href="../assets/styles.css">
    </head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Редактировать сотрудника</h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="../dashboard.php">← Назад в панель</a>
        <a href="list.php">Список сотрудников</a>
        <a href="view.php?id=<?= $id ?>">Карточка сотрудника</a>
    </div>

    <div class="container">
        <div class="form-card">
        <?php if ($error): ?>
            <div class="notification error" style="display:block;"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($success): ?>
            <div class="notification success" style="display:block;"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="employeeForm">
            <div class="form-group">
                <label>ФИО *</label>
                <input type="text" name="full_name" value="<?= $full_name ?>" required>
            </div>
            <div class="form-group">
                <label>Должность *</label>
                <select name="position" required>
                    <option value="guard" <?= $position === 'guard' ? 'selected' : '' ?>>Охранник</option>
                    <option value="head_guard" <?= $position === 'head_guard' ? 'selected' : '' ?>>Главный охранник</option>
                </select>
            </div>

            <div class="form-group">
                <label>Телефон *</label>
                <input type="tel" id="phone" value="<?= $phone_display ?>" placeholder="+7 (123) 456-78-90" required>
            </div>

            <div class="form-group">
                <label>Фото (jpg, png)</label>
                <?php if ($photo_filename): ?>
                    <div class="photo-preview">
                        <img src="/uploads/employees/<?= urlencode($photo_filename) ?>" alt="Фото">
                    </div>
                <?php endif; ?>
                <input type="file" name="photo" accept="image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label>Описание</label>
                <textarea name="description"><?= $description ?></textarea>
            </div>

            <div class="form-group">
                <label>Дата приёма (нельзя изменить)</label>
                <input type="text" value="<?= htmlspecialchars($hire_date) ?>" disabled>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Сохранить</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">Карточка</a>
                <a href="list.php" class="btn btn-ghost">Список</a>
            </div>
        </form>
    </div>

    <script>
        const phoneInput = document.getElementById('phone');
        phoneInput.addEventListener('input', function (e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            if (value && value[0] !== '7') {
                if (value[0] === '8') value = '7' + value.substring(1);
                else value = '7' + value;
            }
            let formatted = '';
            if (value) formatted = '+7 ';
            if (value.length > 1) formatted += '(' + value.substring(1, 4);
            if (value.length >= 4) formatted += ') ' + value.substring(4, 7);
            if (value.length >= 7) formatted += '-' + value.substring(7, 9);
            if (value.length >= 9) formatted += '-' + value.substring(9, 11);
            e.target.value = formatted;
        });

        document.getElementById('employeeForm').addEventListener('submit', function(e) {
            const digits = phoneInput.value.replace(/\D/g, '');
            if (!/^7\d{10}$/.test(digits)) {
                alert('Пожалуйста, введите корректный номер телефона.');
                e.preventDefault();
                return;
            }
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'phone';
            hiddenInput.value = digits;
            this.appendChild(hiddenInput);
        });
    </script>
</div>
</div>
</div>
</body>
</html>
