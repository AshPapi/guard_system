<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

function formatPhoneDisplay(string $digits): string {
    if (!preg_match('/^7\d{10}$/', $digits)) {
        return '';
    }
    return "+7 (" . substr($digits, 1, 3) . ") " . substr($digits, 4, 3) . '-' . substr($digits, 7, 2) . '-' . substr($digits, 9, 2);
}

$error = '';
$success = '';
$lastPhoneDigits = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $position = $_POST['position'] ?? '';
    $phoneDigits = $_POST['phone'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $hireDate = $_POST['hire_date'] ?? '';
    $lastPhoneDigits = $phoneDigits;

    if (!$fullName || !preg_match('/^[А-ЯЁа-яё\'a-z\s\-]+$/iu', $fullName) || count(explode(' ', $fullName)) < 2) {
        $error = 'ФИО должно состоять минимум из двух слов и содержать только буквы.';
    } elseif (!preg_match('/^7\d{10}$/', $phoneDigits)) {
        $error = 'Введите корректный номер телефона. Допустим только формат +7XXXXXXXXXX.';
    } elseif (!$hireDate || strtotime($hireDate) === false || strtotime($hireDate) > time() || strtotime($hireDate) < strtotime('-1 month')) {
        $error = 'Дата приёма может быть только в пределах последних 30 дней и не позднее сегодняшней.';
    } else {
        try {
            $pdo->beginTransaction();

            $photoFilename = null;
            $uploadDir = __DIR__ . '/../uploads/employees';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $uploadDir = realpath($uploadDir) ?: $uploadDir;

            if (!empty($_FILES['photo']['name'])) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    throw new Exception('Фото должно быть в формате JPG или PNG.');
                }
                if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла.');
                }
                $photoFilename = 'emp_' . time() . '.' . $ext;
                $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $photoFilename;
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                    throw new Exception('Не удалось сохранить файл.');
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO employees (full_name, position, phone, photo_filename, description, hire_date)
                VALUES (?, ?, ?, ?, ?, ?) RETURNING id
            ");
            $stmt->execute([$fullName, $position, $phoneDigits, $photoFilename, $description, $hireDate]);
            $employeeId = (int)$stmt->fetchColumn();

            if ($position === 'head_guard') {
                $login = trim($_POST['login'] ?? '');
                $password = $_POST['password'] ?? '';
                if (!$login || !$password || strlen($password) < 6) {
                    throw new Exception('Для главного охранника требуется логин и пароль (мин. 6 символов).');
                }
                $stmt = $pdo->prepare("INSERT INTO users (login, password) VALUES (?, ?)");
                $stmt->execute([$login, $password]);

                $stmt = $pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?");
                $stmt->execute([$pdo->lastInsertId(), $employeeId]);
            }

            $pdo->commit();
            $success = 'Сотрудник успешно добавлен.';
            $_POST = [];
            $lastPhoneDigits = '';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$phoneInputValue = formatPhoneDisplay($lastPhoneDigits);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавить сотрудника</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Добавить сотрудника</h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="list.php">← Назад к списку</a>
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
                    <input type="text" name="full_name" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Должность *</label>
                    <select name="position" id="position-select" required>
                        <option value="">— Выберите должность —</option>
                        <option value="guard" <?= ($_POST['position'] ?? '') === 'guard' ? 'selected' : '' ?>>Охранник</option>
                        <option value="head_guard" <?= ($_POST['position'] ?? '') === 'head_guard' ? 'selected' : '' ?>>Главный охранник</option>
                    </select>
                </div>

                <div id="login-fields" style="display: <?= ($_POST['position'] ?? '') === 'head_guard' ? 'block' : 'none' ?>;">
                    <div class="form-group">
                        <label>Логин *</label>
                        <input type="text" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Пароль (мин. 6 символов) *</label>
                        <input type="password" name="password">
                    </div>
                </div>

                <div class="form-group">
                    <label>Телефон *</label>
                    <input type="tel" id="phone-input" placeholder="+7 (123) 456-78-90" value="<?= htmlspecialchars($phoneInputValue) ?>" required>
                </div>

                <div class="form-group">
                    <label>Фото (jpg/png)</label>
                    <input type="file" name="photo" accept="image/jpeg,image/png">
                </div>

                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Дата приёма *</label>
                    <input type="date" name="hire_date" value="<?= htmlspecialchars($_POST['hire_date'] ?? date('Y-m-d')) ?>"
                           min="<?= date('Y-m-d', strtotime('-1 month')) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const positionSelect = document.getElementById('position-select');
    const loginFields = document.getElementById('login-fields');
    const phoneInput = document.getElementById('phone-input');

    positionSelect.addEventListener('change', () => {
        loginFields.style.display = positionSelect.value === 'head_guard' ? 'block' : 'none';
    });

    phoneInput.addEventListener('input', (e) => {
        let digits = e.target.value.replace(/\D/g, '');
        if (!digits.startsWith('7')) {
            digits = digits.replace(/^8/, '7');
        }
        digits = digits.substring(0, 11);
        let formatted = '';
        if (digits) formatted = '+7 ';
        if (digits.length > 1) formatted += '(' + digits.substring(1, 4);
        if (digits.length >= 4) formatted += ') ' + digits.substring(4, 7);
        if (digits.length >= 7) formatted += '-' + digits.substring(7, 9);
        if (digits.length >= 9) formatted += '-' + digits.substring(9, 11);
        e.target.value = formatted;
    });

    document.getElementById('employeeForm').addEventListener('submit', (e) => {
        const digits = phoneInput.value.replace(/\D/g, '');
        if (!/^7\d{10}$/.test(digits)) {
            alert('Введите корректный номер телефона.');
            e.preventDefault();
            return;
        }
        const prevHidden = document.querySelector('#employeeForm input[name=\"phone\"]');
        if (prevHidden) {
            prevHidden.remove();
        }
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'phone';
        hidden.value = digits;
        document.getElementById('employeeForm').appendChild(hidden);
    });
</script>
</body>
</html>
