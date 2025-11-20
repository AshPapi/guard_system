<?php

const REPORT_ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png'];
const REPORT_MAX_FILE_SIZE = 10485760;

function getReportUploadDir(): string
{
    return __DIR__ . '/../uploads/reports';
}

function ensureReportUploadDir(): string
{
    $dir = getReportUploadDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return realpath($dir) ?: $dir;
}

function ensureReportInfrastructure(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS post_reports (
            id SERIAL PRIMARY KEY,
            post_id INT NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            head_guard_id INT REFERENCES employees(id) ON DELETE SET NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            summary TEXT NOT NULL,
            actions TEXT,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            admin_comment TEXT,
            created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
            submitted_at TIMESTAMP WITHOUT TIME ZONE
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS post_report_files (
            id SERIAL PRIMARY KEY,
            report_id INT NOT NULL REFERENCES post_reports(id) ON DELETE CASCADE,
            stored_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120),
            file_size BIGINT NOT NULL DEFAULT 0,
            uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
        )
    ");
}

function getHeadGuardPost(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT 
            p.id AS post_id,
            p.post_number,
            o.name AS object_name,
            o.address,
            e.id AS head_guard_id,
            e.full_name AS head_guard_name
        FROM posts p
        JOIN objects o ON p.object_id = o.id
        JOIN employees e ON p.head_guard_id = e.id
        WHERE e.user_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function handleReportFilesUpload(PDO $pdo, int $reportId, array $fileBag, string $uploadDir, array &$errors): void
{
    if (!isset($fileBag['name']) || !is_array($fileBag['name'])) {
        return;
    }

    foreach ($fileBag['name'] as $index => $originalName) {
        if (($fileBag['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $errorCode = $fileBag['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_OK) {
            $errors[] = "Не удалось загрузить файл \"{$originalName}\" (код {$errorCode}).";
            continue;
        }

        $size = (int)($fileBag['size'][$index] ?? 0);
        if ($size <= 0 || $size > REPORT_MAX_FILE_SIZE) {
            $errors[] = "Файл \"{$originalName}\" превышает допустимый размер (макс. 10 МБ).";
            continue;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension && !in_array($extension, REPORT_ALLOWED_EXTENSIONS, true)) {
            $errors[] = "Файл \"{$originalName}\" имеет недопустимое расширение.";
            continue;
        }

        $storedName = uniqid("report_{$reportId}_", true) . ($extension ? ".{$extension}" : '');
        $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($fileBag['tmp_name'][$index], $targetPath)) {
            $errors[] = "Не удалось сохранить файл \"{$originalName}\".";
            continue;
        }

        $mime = null;
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($targetPath) ?: null;
        } elseif (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($targetPath) ?: null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO post_report_files (report_id, stored_name, original_name, mime_type, file_size)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$reportId, $storedName, $originalName, $mime, $size]);
    }
}

function fetchReportFilesForReports(PDO $pdo, array $reportIds): array
{
    $reportIds = array_values(array_unique(array_map('intval', $reportIds)));
    if (empty($reportIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, report_id, stored_name, original_name, file_size, uploaded_at
        FROM post_report_files
        WHERE report_id IN ($placeholders)
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute($reportIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $result[$row['report_id']][] = $row;
    }
    return $result;
}

function fetchReportFile(PDO $pdo, int $fileId): ?array
{
    $stmt = $pdo->prepare("
        SELECT f.*, pr.post_id, pr.head_guard_id, pr.status
        FROM post_report_files f
        JOIN post_reports pr ON f.report_id = pr.id
        WHERE f.id = ?
    ");
    $stmt->execute([$fileId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function deleteReportFile(PDO $pdo, int $fileId, string $uploadDir): bool
{
    $file = fetchReportFile($pdo, $fileId);
    if (!$file) {
        return false;
    }

    $stmt = $pdo->prepare("DELETE FROM post_report_files WHERE id = ?");
    $stmt->execute([$fileId]);

    $path = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file['stored_name'];
    if (is_file($path)) {
        unlink($path);
    }

    return true;
}

function countReportFiles(PDO $pdo, int $reportId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_report_files WHERE report_id = ?");
    $stmt->execute([$reportId]);
    return (int)$stmt->fetchColumn();
}

function ensureHeadGuardOwnsReport(PDO $pdo, int $reportId, int $headGuardId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM post_reports
        WHERE id = ? AND head_guard_id = ?
    ");
    $stmt->execute([$reportId, $headGuardId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getReportStatusLabel(string $status): string
{
    $map = [
        'draft' => 'Черновик',
        'submitted' => 'Отправлено админу',
        'processed' => 'Проверено',
    ];
    return $map[$status] ?? 'Неизвестно';
}

function humanFileSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' МБ';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' КБ';
    }
    return $bytes . ' Б';
}
