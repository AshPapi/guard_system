<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/report_helpers.php';

if ($_SESSION['is_head_guard'] ?? false) {
    header('Location: ../dashboard.php');
    exit;
}

ensureReportInfrastructure($pdo);

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$toEnd = date('Y-m-d 23:59:59', strtotime($to));

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            o.name AS object_name,
            o.address,
            p.post_number,
            hg.full_name AS head_guard_name,
            e.full_name AS guard_name,
            s.start_time,
            s.end_time,
            r.text AS remark_text,
            r.created_at AS remark_date
        FROM posts p
        JOIN objects o ON p.object_id = o.id
        LEFT JOIN employees hg ON p.head_guard_id = hg.id
        LEFT JOIN shifts s ON p.id = s.post_id 
            AND s.start_time >= ? AND s.start_time <= ?
        LEFT JOIN shift_guards sg ON s.id = sg.shift_id
        LEFT JOIN employees e ON sg.guard_id = e.id
        LEFT JOIN remarks r ON e.id = r.employee_id 
            AND r.created_at >= s.start_time 
            AND r.created_at <= COALESCE(s.end_time, NOW())
        ORDER BY o.name, p.post_number, hg.full_name, e.full_name, s.start_time
    ");
    $stmt->execute([$from, $toEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="full_report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Объект', 'Адрес', 'Пост', 'Главный охранник', 'Сотрудник', 'Начало смены', 'Окончание смены', 'Замечание', 'Время замечания'], ';', '"', '\\');

    foreach ($rows as $row) {
        $headGuard = $row['head_guard_name'] ?: '—';
        $guard = $row['guard_name'] ?: '—';
        $start = $row['start_time'] ? new DateTime($row['start_time']) : null;
        $end = $row['end_time'] ? new DateTime($row['end_time']) : null;
        $startFormatted = $start ? '"' . $start->format('d.m.Y H:i') . '"' : '—';
        $endFormatted = $end ? '"' . $end->format('d.m.Y H:i') . '"' : '—';
        $remark = $row['remark_text'] ?: '—';
        $remarkDate = $row['remark_date'] ? '"' . date('d.m.Y H:i', strtotime($row['remark_date'])) . '"' : '—';

        fputcsv($output, [
            $row['object_name'],
            $row['address'],
            $row['post_number'],
            $headGuard,
            $guard,
            $startFormatted,
            $endFormatted,
            $remark,
            $remarkDate
        ], ';', '"', '\\');
    }
    fclose($output);
    exit;
}

$stmt = $pdo->prepare("
    SELECT DISTINCT
        o.name AS object_name,
        o.address,
        p.post_number,
        hg.full_name AS head_guard_name,
        e.full_name AS guard_name,
        s.start_time,
        s.end_time,
        r.text AS remark_text,
        r.created_at AS remark_date
    FROM posts p
    JOIN objects o ON p.object_id = o.id
    LEFT JOIN employees hg ON p.head_guard_id = hg.id
    LEFT JOIN shifts s ON p.id = s.post_id 
        AND s.start_time >= ? AND s.start_time <= ?
    LEFT JOIN shift_guards sg ON s.id = sg.shift_id
    LEFT JOIN employees e ON sg.guard_id = e.id
    LEFT JOIN remarks r ON e.id = r.employee_id 
        AND r.created_at >= s.start_time 
        AND r.created_at <= COALESCE(s.end_time, NOW())
    ORDER BY o.name, p.post_number, hg.full_name, e.full_name, s.start_time
");
$stmt->execute([$from, $toEnd]);
$rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$report = [];
foreach ($rawData as $row) {
    $objKey = $row['object_name'] . '|' . $row['address'];
    $postKey = $row['post_number'];

    if (!isset($report[$objKey])) {
        $report[$objKey] = [
            'name' => $row['object_name'],
            'address' => $row['address'],
            'posts' => []
        ];
    }

    if (!isset($report[$objKey]['posts'][$postKey])) {
        $report[$objKey]['posts'][$postKey] = [
            'number' => $row['post_number'],
            'head_guard' => $row['head_guard_name'],
            'guards' => []
        ];
    }

    if ($row['guard_name']) {
        $start = $row['start_time'] ? new DateTime($row['start_time']) : null;
        $end = $row['end_time'] ? new DateTime($row['end_time']) : null;
        $report[$objKey]['posts'][$postKey]['guards'][] = [
            'name' => $row['guard_name'],
            'start' => $start,
            'end' => $end,
            'remark' => $row['remark_text'],
            'remark_date' => $row['remark_date']
        ];
    }
}

$stmt = $pdo->query("
    SELECT 
        p.id AS post_id,
        o.name AS object_name,
        o.address,
        p.post_number,
        MAX(pr.submitted_at) AS last_submitted,
        COUNT(DISTINCT f.id) AS files_count
    FROM post_reports pr
    JOIN posts p ON pr.post_id = p.id
    JOIN objects o ON p.object_id = o.id
    LEFT JOIN post_report_files f ON f.report_id = pr.id
    WHERE pr.status = 'submitted'
    GROUP BY p.id, o.name, o.address, p.post_number
    ORDER BY o.name, p.post_number
");
$postsWithReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сводный отчёт по объектам</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Сводный отчёт по объектам и постам</h2>
        <a href="../dashboard.php">Назад в панель</a>
    </div>

    <div class="container">
        <form method="GET" class="report-filter">
            <div class="form-grid">
                <div class="form-group">
                    <label>Период с</label>
                    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" required>
                </div>
                <div class="form-group">
                    <label>Период по</label>
                    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Обновить</button>
                <a href="?export=csv&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-ghost">Выгрузить CSV</a>
            </div>
        </form>

        <?php if (empty($report)): ?>
            <p class="text-muted">За выбранный период нет смен или замечаний.</p>
        <?php else: ?>
            <?php $objIndex = 1; ?>
            <?php foreach ($report as $object): ?>
                <div class="object-card">
                    <h3><?= $objIndex ?>. Объект: «<?= htmlspecialchars($object['name']) ?>»</h3>
                    <p>Адрес: <?= htmlspecialchars($object['address']) ?></p>

                    <?php foreach ($object['posts'] as $post): ?>
                        <div class="post-item" style="flex-direction: column; align-items: flex-start;">
                            <div class="post-info">
                                <div class="post-number">Пост №<?= (int)$post['number'] ?></div>
                                <div class="head-guard">
                                    Главный охранник: <?= htmlspecialchars($post['head_guard'] ?? '—') ?>
                                </div>
                            </div>
                            <div class="post-guards" style="width:100%;">
                                <?php if (!empty($post['guards'])): ?>
                                    <?php foreach ($post['guards'] as $guard): ?>
                                        <div class="mini-card">
                                            <strong><?= htmlspecialchars($guard['name']) ?></strong><br>
                                            Смена: <?= $guard['start'] ? htmlspecialchars($guard['start']->format('d.m.Y H:i')) : '—' ?>
                                            —
                                            <?= $guard['end'] ? htmlspecialchars($guard['end']->format('d.m.Y H:i')) : '—' ?><br>
                                            <?php if ($guard['remark']): ?>
                                                <span class="text-muted">Замечание: <?= htmlspecialchars($guard['remark']) ?>
                                                    <?php if ($guard['remark_date']): ?>
                                                        (<?= htmlspecialchars(date('d.m.Y H:i', strtotime($guard['remark_date']))) ?>)
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">Нет данных по охранникам на этом посту.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php $objIndex++; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="card" style="margin-top:30px;">
            <h3 class="section-title">Файлы отчётов по постам</h3>
            <?php if (empty($postsWithReports)): ?>
                <p class="text-muted">Загруженных отчётных файлов пока нет.</p>
            <?php else: ?>
                <div class="list-grid">
                    <?php foreach ($postsWithReports as $post): ?>
                        <div class="mini-card">
                            <h4><?= htmlspecialchars($post['object_name']) ?> — пост №<?= (int)$post['post_number'] ?></h4>
                            <p>Адрес: <?= htmlspecialchars($post['address']) ?></p>
                            <p>Файлов: <?= (int)$post['files_count'] ?></p>
                            <p>Последний отчёт: <?= $post['last_submitted'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($post['last_submitted']))) : '—' ?></p>
                            <a class="btn btn-primary" href="post_reports.php?post_id=<?= $post['post_id'] ?>">Открыть карточку</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
