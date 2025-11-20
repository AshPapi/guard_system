<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit;
}

$objectId = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT id, name FROM objects WHERE id = ?");
$stmt->execute([$objectId]);
$object = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$object) {
    header('Location: list.php');
    exit;
}

function buildPlaceholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id FROM posts WHERE object_id = ?");
    $stmt->execute([$objectId]);
    $postIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

    if (!empty($postIds)) {
        $placeholdersPosts = buildPlaceholders($postIds);

        $stmt = $pdo->prepare("SELECT id FROM shifts WHERE post_id IN ($placeholdersPosts)");
        $stmt->execute($postIds);
        $shiftIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

        if (!empty($shiftIds)) {
            $placeholdersShifts = buildPlaceholders($shiftIds);

            $stmt = $pdo->prepare("DELETE FROM shift_guards WHERE shift_id IN ($placeholdersShifts)");
            $stmt->execute($shiftIds);

            $stmt = $pdo->prepare("DELETE FROM shifts WHERE id IN ($placeholdersShifts)");
            $stmt->execute($shiftIds);
        }

        $stmt = $pdo->prepare("SELECT id FROM post_reports WHERE post_id IN ($placeholdersPosts)");
        $stmt->execute($postIds);
        $reportIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

        if (!empty($reportIds)) {
            $placeholdersReports = buildPlaceholders($reportIds);

            $stmt = $pdo->prepare("
                SELECT stored_name 
                FROM post_report_files 
                WHERE report_id IN ($placeholdersReports)
            ");
            $stmt->execute($reportIds);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $uploadDir = realpath(__DIR__ . '/../uploads/reports') ?: (__DIR__ . '/../uploads/reports');
            foreach ($files as $file) {
                $path = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file['stored_name'];
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM post_report_files WHERE report_id IN ($placeholdersReports)");
            $stmt->execute($reportIds);

            $stmt = $pdo->prepare("DELETE FROM post_reports WHERE id IN ($placeholdersReports)");
            $stmt->execute($reportIds);
        }

        $stmt = $pdo->prepare("DELETE FROM posts WHERE id IN ($placeholdersPosts)");
        $stmt->execute($postIds);
    }

    $stmt = $pdo->prepare("DELETE FROM objects WHERE id = ?");
    $stmt->execute([$objectId]);

    $pdo->commit();
    header('Location: list.php?message=object_deleted');
} catch (Exception $e) {
    $pdo->rollBack();
    die("Ошибка при удалении объекта: " . htmlspecialchars($e->getMessage()));
}
