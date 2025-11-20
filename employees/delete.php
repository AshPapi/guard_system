<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit;
}

$id = (int)$_GET['id'];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE shifts 
        SET end_time = NOW(), status = 'completed'
        WHERE id IN (
            SELECT shift_id FROM shift_guards WHERE guard_id = ?
        ) AND status = 'active' AND end_time IS NULL
    ");
    $stmt->execute([$id]);

    $stmt = $pdo->prepare("
        UPDATE employees 
        SET is_active = false, dismissal_date = CURRENT_DATE 
        WHERE id = ?
    ");
    $stmt->execute([$id]);

    $pdo->commit();
    header('Location: list.php?message=dismissed');
} catch (Exception $e) {
    $pdo->rollBack();
    die("Ошибка при увольнении: " . htmlspecialchars($e->getMessage()));
}
?>