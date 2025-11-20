<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit;
}

$postId = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT object_id FROM posts WHERE id = ?");
$stmt->execute([$postId]);
$objectId = $stmt->fetchColumn();

if (!$objectId) {
    header('Location: list.php');
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
    $stmt->execute([$postId]);

    header("Location: view.php?id=$objectId&message=post_deleted");
} catch (Exception $e) {
    die("Ошибка при удалении поста: " . htmlspecialchars($e->getMessage()));
}
?>