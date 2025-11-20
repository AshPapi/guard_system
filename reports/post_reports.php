<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/report_helpers.php';

ensureReportInfrastructure($pdo);

if ($_SESSION['is_head_guard'] ?? false) {
    header('Location: ../dashboard.php');
    exit;
}

$postId = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
if (!$postId) {
    header('Location: dismissed.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.post_number,
        o.name AS object_name,
        o.address,
        hg.full_name AS head_guard_name
    FROM posts p
    JOIN objects o ON p.object_id = o.id
    LEFT JOIN employees hg ON p.head_guard_id = hg.id
    WHERE p.id = ?
");
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: dismissed.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT pr.*, hg.full_name AS head_guard_name
    FROM post_reports pr
    LEFT JOIN employees hg ON pr.head_guard_id = hg.id
    WHERE pr.post_id = ? AND pr.status = 'submitted'
    ORDER BY COALESCE(pr.submitted_at, pr.created_at) DESC
");
$stmt->execute([$postId]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filesMap = fetchReportFilesForReports($pdo, array_column($reports, 'id'));
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Карточка поста №<?= (int)$post['post_number'] ?> – отчёты</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Карточка поста №<?= (int)$post['post_number'] ?></h2>
        <a href="dismissed.php">Назад к отчётам</a>
    </div>
    <div class="container">
        <div class="card">
            <h3 class="section-title">Информация</h3>
            <p><strong>Объект:</strong> <?= htmlspecialchars($post['object_name']) ?></p>
            <p><strong>Адрес:</strong> <?= htmlspecialchars($post['address']) ?></p>
            <p><strong>Главный охранник:</strong> <?= htmlspecialchars($post['head_guard_name'] ?? '—') ?></p>
        </div>

        <div class="card">
            <h3 class="section-title">Отправленные отчёты</h3>
            <?php if (empty($reports)): ?>
                <p class="text-muted">По этому посту ещё не загружены отчёты.</p>
            <?php else: ?>
                <div class="report-list">
                    <?php foreach ($reports as $report): ?>
                        <?php $files = $filesMap[$report['id']] ?? []; ?>
                        <div class="mini-card">
                            <div class="report-header">
                                <span class="tag"><?= htmlspecialchars(getReportStatusLabel($report['status'])) ?></span>
                                <span>Отправлен: <?= $report['submitted_at'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($report['submitted_at']))) : '—' ?></span>
                            </div>
                            <div><strong>Период:</strong> <?= htmlspecialchars(date('d.m.Y', strtotime($report['period_start']))) ?> — <?= htmlspecialchars(date('d.m.Y', strtotime($report['period_end']))) ?></div>
                            <?php if (!empty($report['head_guard_name'])): ?>
                                <div><strong>Главный охранник:</strong> <?= htmlspecialchars($report['head_guard_name']) ?></div>
                            <?php endif; ?>
                            <div><strong>Сводка:</strong><br><?= nl2br(htmlspecialchars($report['summary'])) ?></div>
                            <?php if (!empty($report['actions'])): ?>
                                <div><strong>Меры:</strong><br><?= nl2br(htmlspecialchars($report['actions'])) ?></div>
                            <?php endif; ?>

                            <div class="report-files">
                                <strong>Вложения:</strong>
                                <?php if ($files): ?>
                                    <ul class="attachment-list">
                                        <?php foreach ($files as $file): ?>
                                            <li>
                                                <a href="report_file.php?id=<?= $file['id'] ?>" target="_blank">
                                                    <?= htmlspecialchars($file['original_name']) ?>
                                                </a>
                                                <small><?= humanFileSize((int)$file['file_size']) ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted">Нет приложенных файлов.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
