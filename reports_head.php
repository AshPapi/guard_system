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

$headTextLimit = 250;
if (!defined('HEAD_TEXT_MAX_LENGTH')) {
    define('HEAD_TEXT_MAX_LENGTH', $headTextLimit);
}

$headRemarkLimit = 250;
if (!defined('HEAD_REMARK_MAX_LENGTH')) {
    define('HEAD_REMARK_MAX_LENGTH', $headRemarkLimit);
}

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

function fetchShiftReportRows(PDO $pdo, int $postId, string $from, string $toEnd): array
{
    $stmt = $pdo->prepare("
        SELECT
            e.id AS guard_id,
            e.full_name AS guard_name,
            s.start_time,
            s.end_time,
            s.description AS shift_description,
            CASE WHEN s.description IS NOT NULL THEN s.start_time ELSE NULL END AS shift_comment_date
        FROM shifts s
        LEFT JOIN shift_guards sg ON s.id = sg.shift_id
        LEFT JOIN employees e ON sg.guard_id = e.id
        WHERE s.post_id = ?
          AND s.start_time >= ?
          AND s.start_time <= ?
        ORDER BY s.start_time DESC
    ");
    $stmt->execute([$postId, $from, $toEnd]);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($shifts as &$shift) {
        $guardId = $shift['guard_id'];
        $startTime = $shift['start_time'];
        $endTime = $shift['end_time'] ?: date('Y-m-d H:i:s');
        
        $remarkStmt = $pdo->prepare("
            SELECT text, created_at
            FROM remarks 
            WHERE employee_id = ?
              AND created_at >= ?
              AND created_at <= ?
            ORDER BY created_at DESC
        ");
        $remarkStmt->execute([$guardId, $startTime, $endTime]);
        $allRemarks = $remarkStmt->fetchAll(PDO::FETCH_ASSOC);

        $remarksText = [];
        foreach ($allRemarks as $remark) {
            $remarksText[] = date('d.m.Y H:i', strtotime($remark['created_at'])) . ': ' . $remark['text'];
        }
        
        $shift['all_remarks_text'] = $remarksText ? implode('; ', $remarksText) : '—';
        $shift['remarks_count'] = count($allRemarks);
    }
    
    return $shifts;
}

function fetchPostGuards(PDO $pdo, int $postId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT e.id, e.full_name
        FROM shift_guards sg
        JOIN shifts s ON sg.shift_id = s.id
        JOIN employees e ON sg.guard_id = e.id
        WHERE s.post_id = ?
          AND e.position = 'guard'
          AND e.is_active = true
        ORDER BY e.full_name
    ");
    $stmt->execute([$postId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchPostRemarks(PDO $pdo, int $postId, string $from, string $toEnd): array
{
    $stmt = $pdo->prepare("
        SELECT r.id, r.employee_id, e.full_name AS guard_name, r.text, r.created_at
        FROM remarks r
        JOIN employees e ON r.employee_id = e.id
        WHERE EXISTS (
            SELECT 1
            FROM shift_guards sg
            JOIN shifts s ON sg.shift_id = s.id
            WHERE sg.guard_id = r.employee_id
              AND s.post_id = ?
        )
          AND r.created_at >= ?
          AND r.created_at <= ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$postId, $from, $toEnd]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = fetchShiftReportRows($pdo, $postId, $from, $toEnd);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $postContext['object_name'] . '_post' . $postContext['post_number'] . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv(
    $output,
    [
        'Объект',
        'Пост', 
        'Старший охранник',
        'Охранник',
        'Начало смены',
        'Конец смены',
        'Все замечания', 
        'Количество замечаний', 
        'Комментарий смены',
        'Дата комментария смены'
    ],
    ';',
    '"',
    '\\'
);


    foreach ($rows as $row) {
    $guard = $row['guard_name'] ?: '—';
    $start = $row['start_time'] ? new DateTime($row['start_time']) : null;
    $end = $row['end_time'] ? new DateTime($row['end_time']) : null;
    $startFormatted = $start ? $start->format('d.m.Y H:i') : '—';
    $endFormatted = $end ? $end->format('d.m.Y H:i') : '—';
    
    $allRemarks = $row['all_remarks_text'] ?? '—';
    $remarksCount = $row['remarks_count'] ?? 0;
    
    $description = $row['shift_description'] ?: '—';
    $commentDate = $row['shift_comment_date'] ? date('d.m.Y H:i', strtotime($row['shift_comment_date'])) : '—';

    fputcsv($output, [
        $postContext['object_name'],
        '№ ' . $postContext['post_number'],
        $headGuardName,
        $guard,
        $startFormatted,
        $endFormatted,
        $allRemarks, 
        $remarksCount, 
        $description,
        $commentDate
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

$remarkForm = [
    'guard_id' => '',
    'remark_text' => ''
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
            $summaryLength = function_exists('mb_strlen') ? mb_strlen($summary, 'UTF-8') : strlen($summary);
            if ($summaryLength > HEAD_TEXT_MAX_LENGTH) {
                $errors[] = 'Текст сводки не должен превышать ' . HEAD_TEXT_MAX_LENGTH . ' символов.';
            }
            if ($actionsPlan !== '') {
                $actionsLen = function_exists('mb_strlen') ? mb_strlen($actionsPlan, 'UTF-8') : strlen($actionsPlan);
                if ($actionsLen > HEAD_TEXT_MAX_LENGTH) {
                    $errors[] = 'Текст плана действий не должен превышать ' . HEAD_TEXT_MAX_LENGTH . ' символов.';
                }
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
            $summaryLength = function_exists('mb_strlen') ? mb_strlen($summary, 'UTF-8') : strlen($summary);
            if ($summaryLength > HEAD_TEXT_MAX_LENGTH) {
                $errors[] = 'Текст сводки не должен превышать ' . HEAD_TEXT_MAX_LENGTH . ' символов.';
            }
            if ($actionsPlan !== '') {
                $actionsLen = function_exists('mb_strlen') ? mb_strlen($actionsPlan, 'UTF-8') : strlen($actionsPlan);
                if ($actionsLen > HEAD_TEXT_MAX_LENGTH) {
                    $errors[] = 'Текст плана действий не должен превышать ' . HEAD_TEXT_MAX_LENGTH . ' символов.';
                }
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
        case 'add_remark':
            $guardId = (int)($_POST['guard_id'] ?? 0);
            $remarkText = trim($_POST['remark_text'] ?? '');
            $remarkForm = [
                'guard_id' => $guardId ?: '',
                'remark_text' => $remarkText
            ];

            $errors = [];
            if ($guardId <= 0) {
                $errors[] = 'Необходимо выбрать охранника, кому добавляется замечание.';
            }
            if ($remarkText === '') {
                $errors[] = 'Текст замечания не может быть пустым.';
            }
            $remarkLength = function_exists('mb_strlen') ? mb_strlen($remarkText, "UTF-8") : strlen($remarkText);
            if ($remarkLength > HEAD_REMARK_MAX_LENGTH) {
                $errors[] = 'Замечание не должно превышать ' . HEAD_REMARK_MAX_LENGTH . ' символов.';
            }
            if (!$errors) {
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM employees
                    WHERE id = ? AND position = 'guard' AND is_active = true
                ");
                $stmt->execute([$guardId]);
                if (!$stmt->fetchColumn()) {
                    $errors[] = 'Выбранный охранник не существует или не активен.';
                }
            }

            if (!$errors) {
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM shift_guards sg
                    JOIN shifts s ON sg.shift_id = s.id
                    WHERE sg.guard_id = ? AND s.post_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$guardId, $postId]);
                if (!$stmt->fetchColumn()) {
                    $errors[] = 'Этот охранник не работал на вашем посту.';
                }
            }

            if ($errors) {
                $feedback['error'] = implode(' ', $errors);
                break;
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO remarks (employee_id, text) VALUES (?, ?)");
                $stmt->execute([$guardId, $remarkText]);
            } catch (Exception $e) {
                $feedback['error'] = 'Не удалось добавить замечание: ' . $e->getMessage();
                break;
            }

            $remarkForm = [
                'guard_id' => '',
                'remark_text' => ''
            ];

            redirectBackWithFlash('Замечание добавлено.');
            break;

    }
}


$shifts = fetchShiftReportRows($pdo, $postId, $from, $toEnd);
$postGuards = fetchPostGuards($pdo, $postId);
$postRemarks = fetchPostRemarks($pdo, $postId, $from, $toEnd);

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
                        <textarea name="summary" rows="4" maxlength="<?= HEAD_TEXT_MAX_LENGTH ?>" required><?= htmlspecialchars($createForm['summary']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Принятые меры / план действий</label>
                        <textarea name="actions_plan" rows="3" maxlength="<?= HEAD_TEXT_MAX_LENGTH ?>"><?= htmlspecialchars($createForm['actions_plan']) ?></textarea>
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
            <h3 class="section-title">Замечания по охране</h3>
            <?php if (empty($postGuards)): ?>
                <p class="text-muted">Нет активных охранников, прикреплённых к посту.</p>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="add_remark">
                    <div class="form-group">
                        <label>Охранник *</label>
                        <select name="guard_id" required>
                            <option value="">Выберите охранника из списка</option>
                            <?php foreach ($postGuards as $guard): ?>
                                <option value="<?= $guard['id'] ?>" <?= (string)$guard['id'] === (string)$remarkForm['guard_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($guard['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Текст замечания *</label>
                        <textarea name="remark_text" rows="3" maxlength="<?= HEAD_REMARK_MAX_LENGTH ?>" required><?= htmlspecialchars($remarkForm['remark_text']) ?></textarea>

                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Добавить замечание</button>
                    </div>
                </form>
            <?php endif; ?>
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
                                            <textarea name="summary" rows="3" maxlength="<?= HEAD_TEXT_MAX_LENGTH ?>" required><?= htmlspecialchars($report['summary']) ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label>Принятые меры</label>
                                            <textarea name="actions_plan" rows="3" maxlength="<?= HEAD_TEXT_MAX_LENGTH ?>"><?= htmlspecialchars($report['actions'] ?? '') ?></textarea>
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
            <h3 class="section-title">Отчёт по сменам (для проверки перед формированием CSV)</h3>
            <?php if ($shifts): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Объект</th>
                            <th>Пост</th>
                            <th>Главный охранник</th>
                            <th>Охранник</th>
                            <th>Начало смены</th>
                            <th>Конец смены</th>
                            <th>Дата и замечания</th> 
                            <th>Кол-во замечаний</th> 
                            <th>Комментарий смены</th>
                            <th>Дата комментария</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shifts as $shift): ?>
                            <tr>
                                <td data-label="Объект"><?= htmlspecialchars($postContext['object_name']) ?></td>
                                <td data-label="Пост">№ <?= (int)$postContext['post_number'] ?></td>
                                <td data-label="Главный охранник"><?= htmlspecialchars($headGuardName) ?></td>
                                <td data-label="Охранник"><?= htmlspecialchars($shift['guard_name'] ?? '—') ?></td>
                                <td data-label="Начало смены"><?= $shift['start_time'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($shift['start_time']))) : '—' ?></td>
                                <td data-label="Конец смены"><?= $shift['end_time'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($shift['end_time']))) : '—' ?></td>
                                <td data-label="Все замечания"><?= nl2br(htmlspecialchars($shift['all_remarks_text'] ?? '—')) ?></td>
                                <td data-label="Кол-во замечаний"><?= htmlspecialchars($shift['remarks_count'] ?? 0) ?></td>
                                <td data-label="Комментарий смены"><?= htmlspecialchars($shift['shift_description'] ?? '—') ?></td>
                                <td data-label="Дата комментария"><?= $shift['shift_comment_date'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($shift['shift_comment_date']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Нет данных по сменам за выбранный период.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
    const textLimit = <?= HEAD_TEXT_MAX_LENGTH ?>;
    document.querySelectorAll('textarea[name="summary"], textarea[name="actions_plan"]').forEach((el) => {
        el.setAttribute('maxlength', textLimit);
        el.addEventListener('input', () => {
            if (el.value.length > textLimit) {
                el.value = el.value.slice(0, textLimit);
            }
        });
    });

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
