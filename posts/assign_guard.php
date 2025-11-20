<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: distribution.php');
    exit;
}

$postId = (int)($_POST['post_id'] ?? 0);
$guardId = (int)($_POST['guard_id'] ?? 0);
$startTime = $_POST['start_time'] ?? '';
$endTime = $_POST['end_time'] ?? '';

if (!$postId || !$guardId || !$startTime || !$endTime) {
    die('Все поля обязательны');
}

$startTs = strtotime($startTime);
$endTs = strtotime($endTime);

if ($startTs === false || $endTs === false) {
    die('Неверный формат даты');
}

if ($endTs <= $startTs) {
    die('Окончание смены должно быть позже начала');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT 1 FROM shifts s
        JOIN shift_guards sg ON s.id = sg.shift_id
        WHERE sg.guard_id = ?
          AND s.status = 'active'
          AND s.start_time < ?
          AND s.end_time > ?
    ");
    $stmt->execute([$guardId, date('Y-m-d H:i:s', $endTs), date('Y-m-d H:i:s', $startTs)]);
    
    if ($stmt->fetch()) {
        throw new Exception('Охранник уже работает в этот период. Выберите другое время.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO shifts (post_id, start_time, end_time, status) 
        VALUES (?, ?, ?, 'active') 
        RETURNING id
    ");
    $stmt->execute([$postId, date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)]);
    $shiftId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO shift_guards (shift_id, guard_id) VALUES (?, ?)");
    $stmt->execute([$shiftId, $guardId]);

    $pdo->commit();
    header("Location: view.php?id=$postId&success=1");
} catch (Exception $e) {
    $pdo->rollback();
    header("Location: view.php?id=$postId&error=" . urlencode($e->getMessage()));
    exit;
}
?>