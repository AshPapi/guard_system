<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_POST['shift_id']) || !is_numeric($_POST['shift_id'])) {
    die('Неверный ID смены');
}

$shiftId = (int)$_POST['shift_id'];

try {
    $stmt = $pdo->prepare("
        UPDATE shifts 
        SET end_time = NOW(), status = 'completed'
        WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([$shiftId]);

    echo json_encode(['success' => 'Смена завершена!']);
} catch (Exception $e) {
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}
?>