<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/reports/report_helpers.php';

if (!($_SESSION['is_head_guard'] ?? false)) {
    header('Location: dashboard.php');
    exit;
}

ensureReportInfrastructure($pdo);
$uploadDir = ensureReportUploadDir();

$postContext = getHeadGuardPost($pdo, $_SESSION['user_id']);
if (!$postContext) {
    die('Для пользователя не найден пост главного охранника.');
}

$postId = (int)$postContext['post_id'];
$headGuardId = (int)$postContext['head_guard_id'];
$headGuardName = $postContext['head_guard_name'] ?? 'Главный охранник';

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$toEnd = date('Y-m-d 23:59:59', strtotime($to));

function fetchHeadGuardNotes(PDO $pdo, int $postId, string $from, string $toEnd): array
{
    $stmt = $pdo->prepare("
        SELECT start_time, end_time, description
        FROM shifts
        WHERE post_id = ? AND description IS NOT NULL
          AND start_time >= ? AND start_time <= ?
        ORDER BY start_time ASC
    ");
    $stmt->execute([$postId, $from, $toEnd]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("
        SELECT 
            e.full_name AS guard_name,
            s.start_time,
            s.end_time,
            r.text AS remark_text,
            r.created_at AS remark_date,
            s.description AS shift_description
        FROM shifts s
        LEFT JOIN shift_guards sg ON s.id = sg.shift_id
        LEFT JOIN employees e ON sg.guard_id = e.id
        LEFT JOIN remarks r ON e.id = r.employee_id 
            AND r.created_at >= s.start_time 
            AND r.created_at <= COALESCE(s.end_time, NOW())
        WHERE s.post_id = ? 
          AND s.start_time >= ? 
          AND s.start_time <= ?
        ORDER BY s.start_time DESC
    ");
    $stmt->execute([$postId, $from, $toEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headGuardNotes = fetchHeadGuardNotes($pdo, $postId, $from, $toEnd);
    foreach ($headGuardNotes as $note) {
        $rows[] = [
            'guard_name' => $headGuardName,
            'start_time' => $note['start_time'],
            'end_time' => $note['end_time'],
            'remark_text' => '',
            'remark_date' => null,
            'shift_description' => $note['description'] ?? ''
        ];
    }
    usort($rows, function ($a, $b) {
        return strcmp($b['start_time'] ?? '', $a['start_time'] ?? '');
    });

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $postContext['object_name'] . '_post' . $postContext['post_number'] . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Сотрудник', 'Начало смены', 'Окончание смены', 'Замечание', 'Время замечания', 'Комментарий смены'], ';', '"', '\\');

    foreach ($rows as $row) {
        $guard = $row['guard_name'] ?: (!empty($row['shift_description']) ? $headGuardName : '—');
        $start = $row['start_time'] ? new DateTime($row['start_time']) : null;
        $end = $row['end_time'] ? new DateTime($row['end_time']) : null;
        $startFormatted = $start ? '"' . $start->format('d.m.Y H:i') . '"' : '—';
        $endFormatted = $end ? '"' . $end->format('d.m.Y H:i') . '"' : '—';
        $remark = $row['remark_text'] ?: '—';
        $remarkDate = $row['remark_date'] ? '"' . date('d.m.Y H:i', strtotime($row['remark_date'])) . '"' : '—';
        $description = $row['shift_description'] ?: '—';

        fputcsv($output, [
            $guard,
            $startFormatted,
            $endFormatted,
            $remark,
            $remarkDate,
            $description
        ], ';', '"', '\\');
    }
    fclose($output);
    exit;
}

function redirectBackWithFlash(?string $success = null, ?string $error = null): void
{
    if ($success) {
        $_SESSION['report_success'] = $success;
    }
    if ($error) {
        $_SESSION['report_error'] = $error;
    }
    $target = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: {$target}");
    exit;
}

$feedback = [
    'error' => $_SESSION['report_error'] ?? '',
    'success' => $_SESSION['report_success'] ?? ''
];
unset($_SESSION['report_error'], $_SESSION['report_success']);

$createForm = [
    'period_start' => date('Y-m-01'),
    'period_end' => date('Y-m-d'),
    'summary' => '',
    'actions_plan' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_report':
            $periodStart = trim($_POST['period_start'] ?? '');
            $periodEnd = trim($_POST['period_end'] ?? '');
            $summary = trim($_POST['summary'] ?? '');
            $actionsPlan = trim($_POST['actions_plan'] ?? '');

            $createForm = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary' => $summary,
                'actions_plan' => $actionsPlan
            ];

            $errors = [];
            $startTs = $periodStart ? strtotime($periodStart) : false;
            $endTs = $periodEnd ? strtotime($periodEnd) : false;
            if (!$startTs || !$endTs) {
                $errors[] = 'Укажите корректный период.';
            } elseif ($startTs > $endTs) {
                $errors[] = 'Дата начала периода не может быть позже окончания.';
            }
            if ($summary === '') {
                $errors[] = 'Необходимо заполнить сводку.';
            }

            if ($errors) {
                $feedback['error'] = implode(' ', $errors);
                break;
            }

            $stmt = $pdo->prepare("
                INSERT INTO post_reports (post_id, head_guard_id, period_start, period_end, summary, actions)
                VALUES (?, ?, ?, ?, ?, ?) RETURNING id
            ");
            $stmt->execute([$postId, $headGuardId, $periodStart, $periodEnd, $summary, $actionsPlan ?: null]);
            $reportId = (int)$stmt->fetchColumn();

            $fileErrors = [];
            handleReportFilesUpload($pdo, $reportId, $_FILES['report_files'] ?? [], $uploadDir, $fileErrors);

            redirectBackWithFlash(
                'Черновик отчёта сохранён.',
                $fileErrors ? implode(' ', $fileErrors) : null
            );
            break;

        case 'update_report':
            $reportId = (int)($_POST['report_id'] ?? 0);
            $report = ensureHeadGuardOwnsReport($pdo, $reportId, $headGuardId);
            if (!$report) {
                $feedback['error'] = 'Отчёт не найден или доступ запрещён.';
                break;
            }
            if ($report['status'] !== 'draft') {
                $feedback['error'] = 'Отправленный отчёт нельзя редактировать.';
                break;
            }

            $periodStart = trim($_POST['period_start'] ?? '');
            $periodEnd = trim($_POST['period_end'] ?? '');
            $summary = trim($_POST['summary'] ?? '');
            $actionsPlan = trim($_POST['actions_plan'] ?? '');

            $errors = [];
            $startTs = $periodStart ? strtotime($periodStart) : false;
            $endTs = $periodEnd ? strtotime($periodEnd) : false;
            if (!$startTs || !$endTs) {
                $errors[] = 'Укажите корректный период.';
            } elseif ($startTs > $endTs) {
                $errors[] = 'Дата начала периода не может быть позже окончания.';
            }
            if ($summary === '') {
                $errors[] = 'Необходимо заполнить сводку.';
            }

            if ($errors) {
                $feedback['error'] = implode(' ', $errors);
                break;
            }

            $stmt = $pdo->prepare("
                UPDATE post_reports
                SET period_start = ?, period_end = ?, summary = ?, actions = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$periodStart, $periodEnd, $summary, $actionsPlan ?: null, $reportId]);

            $fileErrors = [];
            handleReportFilesUpload($pdo, $reportId, $_FILES['report_files'] ?? [], $uploadDir, $fileErrors);

            redirectBackWithFlash(
                'Черновик отчёта обновлён.',
                $fileErrors ? implode(' ', $fileErrors) : null
            );
            break;

        case 'submit_report':
            $reportId = (int)($_POST['report_id'] ?? 0);
            $report = ensureHeadGuardOwnsReport($pdo, $reportId, $headGuardId);
            if (!$report) {
                $feedback['error'] = 'Отчёт не найден или доступ запрещён.';
                break;
            }
            if ($report['status'] !== 'draft') {
                $feedback['error'] = 'Отчёт уже отправлен админу.';
                break;
            }
            if (!empty($_FILES['report_files']['name'][0] ?? '')) {
                $fileErrors = [];
                handleReportFilesUpload($pdo, $reportId, $_FILES['report_files'], $uploadDir, $fileErrors);
                if ($fileErrors) {
                    $feedback['error'] = implode(' ', $fileErrors);
                    break;
                }
            }
            if (countReportFiles($pdo, $reportId) === 0) {
                $feedback['error'] = 'Прикрепите хотя бы один файл перед отправкой админу.';
                break;
            }

            $stmt = $pdo->prepare("
                UPDATE post_reports
                SET status = 'submitted', submitted_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reportId]);

            redirectBackWithFlash('Отчёт отправлен администратору.');
            break;

        case 'delete_report':
            $reportId = (int)($_POST['report_id'] ?? 0);
            $report = ensureHeadGuardOwnsReport($pdo, $reportId, $headGuardId);
            if (!$report) {
                $feedback['error'] = 'Отчёт не найден или доступ запрещён.';
                break;
            }
            if ($report['status'] !== 'draft') {
                $feedback['error'] = 'Нельзя удалить отчёт, который уже отправлен.';
                break;
            }
            $stmt = $pdo->prepare("SELECT id FROM post_report_files WHERE report_id = ?");
            $stmt->execute([$reportId]);
            $fileIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($fileIds as $fileId) {
                deleteReportFile($pdo, (int)$fileId, $uploadDir);
            }
            $stmt = $pdo->prepare("DELETE FROM post_reports WHERE id = ?");
            $stmt->execute([$reportId]);
            redirectBackWithFlash('Черновик удалён.');
            break;

        case 'delete_file':
            $fileId = (int)($_POST['file_id'] ?? 0);
            $file = fetchReportFile($pdo, $fileId);
            if (!$file || (int)$file['head_guard_id'] !== $headGuardId) {
                $feedback['error'] = 'Файл не найден или доступ запрещён.';
                break;
            }
            if ($file['status'] !== 'draft') {
                $feedback['error'] = 'Нельзя удалять вложения у отправленного отчёта.';
                break;
            }

            deleteReportFile($pdo, $fileId, $uploadDir);
            redirectBackWithFlash('Файл удалён.');
            break;
    }
}

$stmt = $pdo->prepare("
    SELECT 
        e.full_name AS guard_name,
        s.start_time,
        s.end_time,
        r.text AS remark_text,
        r.created_at AS remark_date,
        s.description AS shift_description
    FROM shifts s
    LEFT JOIN shift_guards sg ON s.id = sg.shift_id
    LEFT JOIN employees e ON sg.guard_id = e.id
    LEFT JOIN remarks r ON e.id = r.employee_id 
        AND r.created_at >= s.start_time 
        AND r.created_at <= COALESCE(s.end_time, NOW())
    WHERE s.post_id = ? 
      AND s.start_time >= ? 
      AND s.start_time <= ?
    ORDER BY s.start_time DESC
");
$stmt->execute([$postId, $from, $toEnd]);
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT *
    FROM post_reports
    WHERE post_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$postId]);
$postReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filesMap = fetchReportFilesForReports($pdo, array_column($postReports, 'id'));
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчётность по посту <?= htmlspecialchars($postContext['object_name']) ?> – пост №<?= (int)$postContext['post_number'] ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Отчётность поста: <?= htmlspecialchars($postContext['object_name']) ?> (№<?= (int)$postContext['post_number'] ?>)</h2>
        <a href="dashboard.php">Назад в панель</a>
    </div>
    <div class="nav">
        <a href="distribution_head.php">Распределение</a>
    </div>

    <div class="container">
        <?php if ($feedback['error']): ?>
            <div class="notification error" style="display:block;"><?= htmlspecialchars($feedback['error']) ?></div>
        <?php endif; ?>
        <?php if ($feedback['success']): ?>
            <div class="notification success" style="display:block;"><?= htmlspecialchars($feedback['success']) ?></div>
        <?php endif; ?>

        <div class="split">
            <div class="form-card">
                <h3 class="section-title">Создать отчёт</h3>
                <form method="POST" enctype="multipart/form-data" id="head-report-form">
                    <input type="hidden" name="action" value="create_report">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Период с *</label>
                            <input type="date" name="period_start" value="<?= htmlspecialchars($createForm['period_start']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Период по *</label>
                            <input type="date" name="period_end" value="<?= htmlspecialchars($createForm['period_end']) ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Сводка по посту *</label>
                        <textarea name="summary" rows="4" required><?= htmlspecialchars($createForm['summary']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Принятые меры / план действий</label>
                        <textarea name="actions_plan" rows="3"><?= htmlspecialchars($createForm['actions_plan']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Файлы отчёта</label>
                        <input type="file" name="report_files[]" multiple>
                        <small>Поддерживаются: pdf, doc(x), xls(x), csv, txt, jpg, png. До 10 МБ.</small>
                    </div>
                    <p class="text-muted">
                        Сначала создайте CSV за выбранный период, проверьте данные и прикрепите файл к отчёту перед отправкой админу.
                    </p>
                    <div class="form-actions">
                        <button type="button" class="btn btn-ghost" id="download-report-csv">Скачать CSV</button>
                        <button type="submit" class="btn btn-success">Сохранить черновик</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3 class="section-title">Информация по посту</h3>
                <p><strong>Объект:</strong> <?= htmlspecialchars($postContext['object_name']) ?></p>
                <p><strong>Адрес:</strong> <?= htmlspecialchars($postContext['address']) ?></p>
                <p><strong>Номер поста:</strong> <?= (int)$postContext['post_number'] ?></p>
                <p><strong>Главный охранник:</strong> вы</p>
            </div>
        </div>

        <div class="card">
            <h3 class="section-title">Мои отчёты</h3>
            <?php if (empty($postReports)): ?>
                <p class="text-muted">Черновики и отправленные отчёты пока отсутствуют.</p>
            <?php else: ?>
                <div class="report-list">
                    <?php foreach ($postReports as $report): ?>
                        <?php $files = $filesMap[$report['id']] ?? []; ?>
                        <div class="mini-card">
                            <div class="report-header">
                                <span class="tag"><?= htmlspecialchars(getReportStatusLabel($report['status'])) ?></span>
                                <span>Создан: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($report['created_at']))) ?></span>
                                <?php if (!empty($report['submitted_at'])): ?>
                                    <span>Отправлен: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($report['submitted_at']))) ?></span>
                                <?php endif; ?>
                            </div>
                            <div><strong>Период:</strong> <?= htmlspecialchars(date('d.m.Y', strtotime($report['period_start']))) ?> — <?= htmlspecialchars(date('d.m.Y', strtotime($report['period_end']))) ?></div>
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
                                                <a href="reports/report_file.php?id=<?= $file['id'] ?>" target="_blank">
                                                    <?= htmlspecialchars($file['original_name']) ?>
                                                </a>
                                                <small><?= humanFileSize((int)$file['file_size']) ?></small>
                                                <?php if ($report['status'] === 'draft'): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить файл из отчёта?');">
                                                        <input type="hidden" name="action" value="delete_file">
                                                        <input type="hidden" name="file_id" value="<?= $file['id'] ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding:4px 10px;">Удалить</button>
                                                    </form>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p>Файлы не прикреплены.</p>
                                <?php endif; ?>
                            </div>

                            <?php if ($report['status'] === 'draft'): ?>
                                <details>
                                    <summary>Редактировать черновик</summary>
                                    <form method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="action" value="update_report">
                                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label>Период с *</label>
                                                <input type="date" name="period_start" value="<?= htmlspecialchars($report['period_start']) ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label>Период по *</label>
                                                <input type="date" name="period_end" value="<?= htmlspecialchars($report['period_end']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label>Сводка *</label>
                                            <textarea name="summary" rows="3" required><?= htmlspecialchars($report['summary']) ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label>Принятые меры</label>
                                            <textarea name="actions_plan" rows="3"><?= htmlspecialchars($report['actions'] ?? '') ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label>Добавить файлы</label>
                                            <input type="file" name="report_files[]" multiple>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                                        </div>
                                    </form>
                                </details>

                                <form method="POST" enctype="multipart/form-data"
                                      onsubmit="return confirm('Отправить отчёт админу? После отправки редактирование будет закрыто.');">
                                    <input type="hidden" name="action" value="submit_report">
                                    <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                    <div class="form-group">
                                        <label>Прикрепить файлы перед отправкой</label>
                                        <input type="file" name="report_files[]" multiple>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-success">Отправить админу</button>
                                    </div>
                                </form>

                                <form method="POST" onsubmit="return confirm('Удалить этот черновик отчёта без возможности восстановления?');">
                                    <input type="hidden" name="action" value="delete_report">
                                    <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-danger">Удалить черновик</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-card">
            <h3 class="section-title">Журнал смен (только охранники поста)</h3>
            <?php if ($shifts): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Сотрудник</th>
                            <th>Начало смены</th>
                            <th>Окончание смены</th>
                            <th>Замечание</th>
                            <th>Время замечания</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shifts as $shift): ?>
                            <tr>
                                <td data-label="Сотрудник"><?= htmlspecialchars($shift['guard_name'] ?? '—') ?></td>
                                <td data-label="Начало"><?= $shift['start_time'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($shift['start_time']))) : '—' ?></td>
                                <td data-label="Окончание"><?= $shift['end_time'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($shift['end_time']))) : '—' ?></td>
                                <td data-label="Замечание"><?= htmlspecialchars($shift['remark_text'] ?? '—') ?></td>
                                <td data-label="Время замечания"><?= $shift['remark_date'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($shift['remark_date']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>За выбранный период смен не найдено.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
    const downloadBtn = document.getElementById('download-report-csv');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', () => {
            const form = document.getElementById('head-report-form');
            if (!form) return;
            const fromInput = form.querySelector('input[name="period_start"]');
            const toInput = form.querySelector('input[name="period_end"]');
            if (!fromInput.value || !toInput.value) {
                alert('Укажите период отчёта, чтобы сформировать CSV.');
                return;
            }
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('export', 'csv');
            url.searchParams.set('from', fromInput.value);
            url.searchParams.set('to', toInput.value);
            window.open(url.toString(), '_blank');
        });
    }
</script>
</body>
</html>
