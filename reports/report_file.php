<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/report_helpers.php';

ensureReportInfrastructure($pdo);

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$fileId) {
    http_response_code(404);
    exit('Файл не найден.');
}

$file = fetchReportFile($pdo, $fileId);
if (!$file) {
    http_response_code(404);
    exit('Файл не найден.');
}

if (($_SESSION['is_head_guard'] ?? false)) {
    $postContext = getHeadGuardPost($pdo, $_SESSION['user_id']);
    $headGuardId = $postContext['head_guard_id'] ?? null;
    if (!$headGuardId || (int)$file['head_guard_id'] !== (int)$headGuardId) {
        http_response_code(403);
        exit('Доступ запрещён.');
    }
}

$path = rtrim(getReportUploadDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Физический файл не найден.');
}

$mime = $file['mime_type'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (int)$file['file_size']);
header('Content-Disposition: attachment; filename="' . rawurlencode($file['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
