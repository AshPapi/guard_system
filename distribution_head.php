<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if (!($_SESSION['is_head_guard'] ?? false)) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.id, p.post_number, o.name AS object_name
    FROM posts p
    JOIN objects o ON p.object_id = o.id
    JOIN employees e ON p.head_guard_id = e.id
    WHERE e.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    die('Вы не закреплены за постом.');
}

$postId = $post['id'];

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_description') {
    $description = trim($_POST['description'] ?? '');
    
    if (!$description) {
        $error = 'Описание не может быть пустым.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id FROM shifts 
                WHERE post_id = ? AND DATE(start_time) = CURRENT_DATE AND description IS NOT NULL
            ");
            $stmt->execute([$postId]);
            $existingShift = $stmt->fetch();
            
            if ($existingShift) {
                $stmt = $pdo->prepare("
                    UPDATE shifts 
                    SET description = COALESCE(description, '') || E'\n\n' || ? 
                    WHERE id = ?
                ");
                $stmt->execute([$description, $existingShift['id']]);
            } else {
                $start = date('Y-m-d 00:00:00');
                $end = date('Y-m-d 23:59:59');
                
                $stmt = $pdo->prepare("
                    INSERT INTO shifts (post_id, start_time, end_time, status, description) 
                    VALUES (?, ?, ?, 'active', ?)
                ");
                $stmt->execute([$postId, $start, $end, $description]);
            }
            
            $success = 'Описание сохранено!';
        } catch (Exception $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_guard') {
    $guardId = (int)($_POST['guard_id'] ?? 0);
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';

    if (!$guardId || !$startTime || !$endTime) {
        $error = 'Все поля обязательны.';
    } else {
        try {
            $startTs = strtotime($startTime);
            $endTs = strtotime($endTime);
            $now = time();
            
            if ($startTs < $now) {
                throw new Exception('Начало смены не может быть в прошлом.');
            }
            
            if ($endTs <= $startTs) {
                throw new Exception('Окончание должно быть позже начала.');
            }

            $stmt = $pdo->prepare("
                SELECT 1 FROM shifts s
                JOIN shift_guards sg ON s.id = sg.shift_id
                WHERE sg.guard_id = ? AND s.status = 'active'
                  AND s.start_time < ? AND s.end_time > ?
            ");
            $stmt->execute([$guardId, date('Y-m-d H:i:s', $endTs), date('Y-m-d H:i:s', $startTs)]);
            if ($stmt->fetch()) {
                throw new Exception('Охранник уже работает в этот период.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO shifts (post_id, start_time, end_time, status) 
                VALUES (?, ?, ?, 'active') RETURNING id
            ");
            $stmt->execute([$postId, date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)]);
            $shiftId = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO shift_guards (shift_id, guard_id) VALUES (?, ?)");
            $stmt->execute([$shiftId, $guardId]);

            $success = 'Охранник успешно назначен!';
        } catch (Exception $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shift_id'])) {
    header('Content-Type: application/json');
    
    $shiftId = (int)$_POST['shift_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM shifts WHERE id = ? AND post_id = ?");
        $stmt->execute([$shiftId, $postId]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'Смена не найдена']);
            exit;
        }
        
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM shift_guards WHERE shift_id = ?");
        $stmt->execute([$shiftId]);
        $stmt = $pdo->prepare("DELETE FROM shifts WHERE id = ?");
        $stmt->execute([$shiftId]);
        $pdo->commit();
        
        echo json_encode(['success' => 'Смена удалена']);
    } catch (Exception $e) {
        $pdo->rollback();
        echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
    }
    exit;
}

$guards = $pdo->query("
    SELECT id, full_name 
    FROM employees 
    WHERE position = 'guard' 
      AND is_active = true
      AND id NOT IN (
          SELECT guard_id FROM shift_guards sg
          JOIN shifts s ON sg.shift_id = s.id
          WHERE s.status = 'active'
      )
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT e.full_name, s.start_time, s.end_time, s.id AS shift_id
    FROM shifts s
    JOIN shift_guards sg ON s.id = sg.shift_id
    JOIN employees e ON sg.guard_id = e.id
    WHERE s.post_id = ? AND s.status = 'active'
    ORDER BY s.start_time
");
$stmt->execute([$postId]);
$rawGuards = $stmt->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime();
$currentGuards = [];
foreach ($rawGuards as $guard) {
    $start = new DateTime($guard['start_time']);
    $end = $guard['end_time'] ? new DateTime($guard['end_time']) : null;
    if ($end && $now > $end) {
        $status = 'Завершена';
    } elseif ($now >= $start && (!$end || $now <= $end)) {
        $status = 'Смена идёт';
    } else {
        $status = 'Скоро начнётся';
    }
    $currentGuards[] = [
        'full_name' => $guard['full_name'],
        'start_time' => $guard['start_time'],
        'end_time' => $guard['end_time'],
        'status' => $status,
        'shift_id' => $guard['shift_id']
    ];
}

$stmt = $pdo->prepare("
    SELECT description, start_time 
    FROM shifts 
    WHERE post_id = ? AND description IS NOT NULL 
    ORDER BY start_time DESC
");
$stmt->execute([$postId]);
$descriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мой пост: <?= htmlspecialchars($post['object_name']) ?> — Пост №<?= $post['post_number'] ?></title>
    <link rel="stylesheet" href="assets/styles.css">
    </head>
<body>
    <div class="header">
        <h2>Мой пост: <?= htmlspecialchars($post['object_name']) ?> — Пост №<?= $post['post_number'] ?></h2>
        <a href="logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">← Назад</a>
        <a href="reports_head.php" class="btn">Отчёты</a>
    </div>

    <div class="container">
        <div class="card">
            <h3>Добавить описание смены</h3>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <form method="POST" id="description-form">
                <input type="hidden" name="action" value="add_description">
                <div class="form-group">
                    <label>Описание события *</label>
                    <textarea name="description" required 
                              placeholder="Опишите произошедшее на смене..."><?= 
                        htmlspecialchars($_POST['description'] ?? '') 
                    ?></textarea>
                </div>
                <button type="submit" class="btn btn-success">Сохранить описание</button>
            </form>
        </div>

        <div class="card">
            <h3>Описания смен</h3>
            <?php if ($descriptions): ?>
                <?php foreach ($descriptions as $desc): ?>
                    <div class="remark-item">
                        <div><?= nl2br(htmlspecialchars($desc['description'])) ?></div>
                        <div class="remark-date">
                            Смена от: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($desc['start_time']))) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Описаний смен нет.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Назначить охранника на смену</h3>
            <form method="POST" id="assign-form">
                <input type="hidden" name="action" value="assign_guard">

                <div class="form-group">
                    <label>Охранник *</label>
                    <select name="guard_id" required>
                        <option value="">— Выберите —</option>
                        <?php foreach ($guards as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Начало смены *</label>
                    <input type="datetime-local" name="start_time" 
                           min="<?= date('Y-m-d\TH:i') ?>" 
                           value="<?= date('Y-m-d\TH:i') ?>" 
                           required>
                </div>

                <div class="form-group">
                    <label>Окончание смены *</label>
                    <input type="datetime-local" name="end_time" required>
                </div>

                <button type="submit" class="btn btn-success">Назначить</button>
            </form>
        </div>

        <div class="card">
            <h3>Охранники на моём посту</h3>
            <?php if ($currentGuards): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Охранник</th>
                            <th>Начало</th>
                            <th>Окончание</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentGuards as $guard): ?>
                            <tr data-shift-id="<?= $guard['shift_id'] ?>">
                                <td><?= htmlspecialchars($guard['full_name']) ?></td>
                                <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($guard['start_time']))) ?></td>
                                <td><?= $guard['end_time'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($guard['end_time']))) : '—' ?></td>
                                <td>
                                    <?php if ($guard['status'] === 'Смена идёт'): ?>
                                        <span class="status-active">Смена идёт</span>
                                    <?php elseif ($guard['status'] === 'Скоро начнётся'): ?>
                                        <span class="status-pending">Скоро начнётся</span>
                                    <?php else: ?>
                                        <span class="status-completed">Завершена</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" 
                                            class="btn btn-danger delete-shift-btn" 
                                            data-shift-id="<?= $guard['shift_id'] ?>"
                                            style="padding:4px 8px;font-size:0.9em;">
                                        Удалить
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Нет назначенных охранников.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.querySelectorAll('.delete-shift-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const shiftId = this.dataset.shiftId;
                if (!confirm('Удалить смену?')) return;
                
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'shift_id=' + encodeURIComponent(shiftId)
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        this.closest('tr').remove();
                    } else {
                        alert(result.error || 'Ошибка');
                    }
                } catch (e) {
                    alert('Ошибка соединения');
                }
            });
        });

        document.getElementById('assign-form').addEventListener('submit', function(e) {
            const start = new Date(this.start_time.value);
            const now = new Date();
            if (start < now) {
                e.preventDefault();
                alert('Начало смены не может быть в прошлом!');
            }
        });
    </script>
</body>
</html>