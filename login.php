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
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $error = 'Введите логин и пароль.';
    } else {
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

        $error = 'Неверный логин или пароль.';
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход в систему</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-badge">Центр управления постами</div>
    <h1>Вход в систему</h1>
    <p class="auth-subtitle">Укажите учетные данные, чтобы продолжить работу.</p>

    <?php if ($error): ?>
        <div class="notification error" style="display:block;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form" autocomplete="on">
        <label for="login">Логин</label>
        <input
            id="login"
            type="text"
            name="login"
            value="<?= htmlspecialchars($login) ?>"
            placeholder="Введите логин"
            autocomplete="username"
            required
        >

        <label for="password">Пароль</label>
        <input
            id="password"
            type="password"
            name="password"
            placeholder="Введите пароль"
            autocomplete="current-password"
            required
        >

        <button type="submit" class="btn btn-primary btn-auth">Продолжить</button>
    </form>
</div>
</body>
</html>
