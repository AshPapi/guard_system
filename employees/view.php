<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

function formatPhone(string $digits): string {
    if (!preg_match('/^7(\d{3})(\d{3})(\d{2})(\d{2})$/', $digits, $matches)) {
        return $digits;
    }
    return "+7 ({$matches[1]}) {$matches[2]}-{$matches[3]}-{$matches[4]}";
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit;
}

$employeeId = (int)$_GET['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_remark') {
    header('Content-Type: application/json; charset=UTF-8');

    $stmt = $pdo->prepare("SELECT is_active FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    $empRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empRow || !$empRow['is_active']) {
        echo json_encode(['error' => 'Нельзя добавить заметку для уволенного сотрудника.']);
        exit;
    }

    $text = trim($_POST['text'] ?? '');
    if ($text === '') {
        echo json_encode(['error' => 'Введите текст заметки.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO remarks (employee_id, text) VALUES (?, ?)");
        $stmt->execute([$employeeId, $text]);
        echo json_encode(['success' => 'Заметка сохранена.']);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Ошибка при сохранении заметки: ' . $e->getMessage()]);
    }
    exit;
}

$stmt = $pdo->prepare("SELECT e.*, u.login AS user_login FROM employees e LEFT JOIN users u ON e.user_id = u.id WHERE e.id = ?");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: list.php');
    exit;
}

$isDismissed = !$employee['is_active'];
$dismissalDate = $employee['dismissal_date'] ?? null;

if ($isDismissed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    echo 'Изменения для уволенных сотрудников запрещены.';
    exit;
}

$currentLocation = null;
$upcomingShifts = [];
$shiftHistory = [];
$remarks = [];

if ($employee['position'] === 'head_guard') {
    $stmt = $pdo->prepare("
        SELECT o.name AS object_name, p.post_number
        FROM posts p
        JOIN objects o ON p.object_id = o.id
        WHERE p.head_guard_id = ?
    ");
    $stmt->execute([$employeeId]);
    $currentLocation = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT o.name AS object_name, p.post_number, s.start_time, s.end_time
        FROM shifts s
        JOIN posts p ON s.post_id = p.id
        JOIN objects o ON p.object_id = o.id
        JOIN shift_guards sg ON s.id = sg.shift_id
        WHERE sg.guard_id = ? AND s.start_time >= NOW()
        ORDER BY s.start_time ASC
        LIMIT 3
    ");
    $stmt->execute([$employeeId]);
    $upcomingShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT o.name AS object_name, p.post_number, s.start_time, s.end_time
        FROM shifts s
        JOIN posts p ON s.post_id = p.id
        JOIN objects o ON p.object_id = o.id
        JOIN shift_guards sg ON s.id = sg.shift_id
        WHERE sg.guard_id = ?
        ORDER BY s.start_time DESC
    ");
    $stmt->execute([$employeeId]);
    $historyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now = new DateTime();
    foreach ($historyRows as $shift) {
        $start = new DateTime($shift['start_time']);
        $end = $shift['end_time'] ? new DateTime($shift['end_time']) : null;

        if ($end && $now > $end) {
            $status = 'completed';
        } elseif ($now >= $start && (!$end || $now <= $end)) {
            $status = 'active';
        } else {
            $status = 'planned';
        }

        $shiftHistory[] = [
            'object_name' => $shift['object_name'],
            'post_number' => $shift['post_number'],
            'start_time' => $shift['start_time'],
            'end_time' => $shift['end_time'],
            'status' => $status,
        ];
    }
}

$stmt = $pdo->prepare("SELECT * FROM remarks WHERE employee_id = ? ORDER BY created_at DESC");
$stmt->execute([$employeeId]);
$remarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Карточка сотрудника</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
<div class="page-wrapper">
    <div class="header">
        <h2>Сотрудник: <?= htmlspecialchars($employee['full_name']) ?></h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="list.php">← Назад к списку</a>
    </div>

    <div class="container">
        <?php if (!$isDismissed): ?>
            <div class="action-toolbar">
                <a href="edit.php?id=<?= $employeeId ?>" class="btn btn-primary">Редактировать</a>
                <a href="delete.php?id=<?= $employeeId ?>" class="btn btn-danger"
                   onclick="return confirm('Уволить сотрудника? Данные сохранятся для отчётов.')">Уволить</a>
            </div>
        <?php endif; ?>

        <?php if ($isDismissed): ?>
            <div class="dismissed-banner">
                <strong>Сотрудник уволен</strong>
                <?php if ($dismissalDate): ?>
                    (<?= htmlspecialchars(date('d.m.Y', strtotime($dismissalDate))) ?>)
                <?php endif; ?>
                <br>Редактирование доступно только для активных сотрудников.
            </div>
        <?php endif; ?>

        <div class="card">
            <?php if (!empty($employee['photo_filename'])): ?>
                <div class="photo">
                    <img src="/uploads/employees/<?= urlencode($employee['photo_filename']) ?>" alt="Фото сотрудника">
                </div>
            <?php endif; ?>

            <div class="info-row">
                <span class="info-label">ФИО:</span>
                <?= htmlspecialchars($employee['full_name']) ?>
            </div>
            <div class="info-row">
                <span class="info-label">Должность:</span>
                <?= $employee['position'] === 'head_guard' ? 'Главный охранник' : 'Охранник' ?>
                <?php if ($employee['position'] === 'head_guard' && !empty($employee['user_login'])): ?>
                    (логин: <code><?= htmlspecialchars($employee['user_login']) ?></code>)
                <?php endif; ?>
            </div>
            <div class="info-row">
                <span class="info-label">Телефон:</span>
                <?= htmlspecialchars(formatPhone($employee['phone'])) ?>
            </div>
            <div class="info-row">
                <span class="info-label">Дата приёма:</span>
                <?= htmlspecialchars($employee['hire_date']) ?>
            </div>
            <div class="info-row">
                <span class="info-label">Статус:</span>
                <?php if ($employee['is_active']): ?>
                    <span class="status-active">Работает</span>
                <?php else: ?>
                    <span class="status-inactive">
                        Уволен <?= $employee['dismissal_date'] ? 'с ' . htmlspecialchars($employee['dismissal_date']) : '' ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if (!empty($employee['description'])): ?>
                <div class="info-row">
                    <span class="info-label">Описание:</span>
                    <?= nl2br(htmlspecialchars($employee['description'])) ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($employee['position'] === 'head_guard'): ?>
            <div class="card">
                <h3 class="section-title">Закреплённый объект</h3>
                <?php if ($currentLocation): ?>
                    Пост №<?= (int)$currentLocation['post_number'] ?> на объекте <strong><?= htmlspecialchars($currentLocation['object_name']) ?></strong>.
                <?php else: ?>
                    За главным охранником сейчас не закреплён ни один пост.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <h3 class="section-title">Ближайшие смены</h3>
                <?php if ($upcomingShifts): ?>
                    <table>
                        <thead>
                        <tr>
                            <th>Объект</th>
                            <th>Пост</th>
                            <th>Начало</th>
                            <th>Окончание</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($upcomingShifts as $shift): ?>
                            <tr>
                                <td data-label="Объект"><?= htmlspecialchars($shift['object_name']) ?></td>
                                <td data-label="Пост"><?= (int)$shift['post_number'] ?></td>
                                <td data-label="Начало"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($shift['start_time']))) ?></td>
                                <td data-label="Окончание">
                                    <?= $shift['end_time'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($shift['end_time']))) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">Назначенных смен пока нет.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 class="section-title">История смен</h3>
                <?php if ($shiftHistory): ?>
                    <table>
                        <thead>
                        <tr>
                            <th>Объект</th>
                            <th>Пост</th>
                            <th>Начало</th>
                            <th>Окончание</th>
                            <th>Статус</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($shiftHistory as $shift): ?>
                            <tr>
                                <td data-label="Объект"><?= htmlspecialchars($shift['object_name']) ?></td>
                                <td data-label="Пост"><?= (int)$shift['post_number'] ?></td>
                                <td data-label="Начало"><?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($shift['start_time']))) ?></td>
                                <td data-label="Окончание">
                                    <?= $shift['end_time'] ? htmlspecialchars(date('d.m.Y H:i:s', strtotime($shift['end_time']))) : '—' ?>
                                </td>
                                <td data-label="Статус">
                                    <?php if ($shift['status'] === 'active'): ?>
                                        <span class="status-active">В работе</span>
                                    <?php elseif ($shift['status'] === 'planned'): ?>
                                        <span class="status-pending">Предстоящая</span>
                                    <?php else: ?>
                                        <span class="status-completed">Завершена</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">История смен пока пуста.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card" id="remarks-section">
            <h3 class="section-title">Заметки</h3>
            <?php if ($remarks): ?>
                <?php foreach ($remarks as $remark): ?>
                    <div class="remark-item">
                        <div><?= nl2br(htmlspecialchars($remark['text'])) ?></div>
                        <div class="remark-date"><?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($remark['created_at']))) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">Заметок пока нет.</p>
            <?php endif; ?>

            <?php if (!$isDismissed): ?>
                <form id="remark-form" style="margin-top:15px;">
                    <input type="hidden" name="action" value="add_remark">
                    <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                    <textarea name="text" rows="3" placeholder="Добавьте заметку..." required></textarea>
                    <button type="submit" class="btn btn-primary" style="margin-top:8px;">+ Добавить заметку</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    window.addEventListener('beforeunload', () => {
        sessionStorage.setItem('employeeViewScroll', window.scrollY);
    });
    window.addEventListener('load', () => {
        const saved = sessionStorage.getItem('employeeViewScroll');
        if (saved) {
            window.scrollTo(0, parseInt(saved, 10));
        }
    });

    document.getElementById('remark-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(event.target);
        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const result = await response.json();
            const notif = document.createElement('div');
            notif.className = result.success ? 'notification success' : 'notification error';
            notif.textContent = result.success || result.error || 'Ошибка';
            notif.style.display = 'block';
            document.getElementById('remarks-section').prepend(notif);
            if (result.success) {
                event.target.reset();
                setTimeout(() => window.location.reload(), 1200);
            }
        } catch (err) {
            alert('Ошибка при отправке формы.');
        }
    });
</script>
</body>
</html>
