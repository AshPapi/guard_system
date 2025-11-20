<?php
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login && $password) {
        require_once __DIR__ . '/includes/db.php';

        $stmt = $pdo->prepare("SELECT id, login, password FROM users WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['password'] === $password) {
            $isHeadGuard = false;
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM employees 
                WHERE user_id = ? AND position = 'head_guard' AND is_active = true
            ");
            $stmt->execute([$user['id']]);
            $isHeadGuard = (bool)$stmt->fetch();

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['login'] = $user['login'];
            $_SESSION['is_head_guard'] = $isHeadGuard;

            header('Location: /dashboard.php');
            exit;
        }
    }
    $error = 'Неверный логин или пароль.';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход в систему</title>
    <link rel="stylesheet" href="assets/styles.css">
    </head>
<body>
<div class="page-wrapper">
    <div class="login-wrapper form-card">
        <h2 class="section-title" style="text-align:center;">Авторизация</h2>
        <?php if ($error): ?>
            <div class="notification error" style="display:block;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Логин</label>
                <input type="text" name="login" placeholder="Логин" required>
            </div>
            <div class="form-group">
                <label>Пароль</label>
                <input type="password" name="password" placeholder="Пароль" required>
            </div>
            <div class="form-actions" style="justify-content:center;">
                <button type="submit" class="btn btn-primary">Войти</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>