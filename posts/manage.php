<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ../distribution.php');
    exit;
}

$postId = (int)$_GET['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shift_id'])) {
    header('Content-Type: application/json');
    
    $shiftId = (int)$_POST['shift_id'];
    
    try {
        $isHeadGuard = $_SESSION['is_head_guard'] ?? false;
        if ($isHeadGuard) {
            $stmt = $pdo->prepare("SELECT p.id FROM shifts s JOIN posts p ON s.post_id = p.id WHERE s.id = ?");
            $stmt->execute([$shiftId]);
            $postFromShift = $stmt->fetch();
            if (!$postFromShift || $postFromShift['id'] != $postId) {
                echo json_encode(['error' => 'Нет прав на удаление']);
                exit;
            }
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

$stmt = $pdo->prepare("
    SELECT p.id, p.post_number, o.name AS object_name, hg.full_name AS head_guard_name
    FROM posts p
    JOIN objects o ON p.object_id = o.id
    LEFT JOIN employees hg ON p.head_guard_id = hg.id
    WHERE p.id = ?
");
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: ../distribution.php');
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['shift_id'])) {
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
            
            if ($startTs < ($now - 60)) {
                throw new Exception('Начало смены не может быть в прошлом.');
            }
            
            if ($endTs <= $startTs) {
                throw new Exception('Окончание должно быть позже начала.');
            }

            $stmt = $pdo->prepare("
                SELECT hire_date, is_active, position
                FROM employees
                WHERE id = ?
            ");
            $stmt->execute([$guardId]);
            $guardMeta = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$guardMeta || !$guardMeta['is_active'] || $guardMeta['position'] !== 'guard') {
                throw new Exception('?????????? ????????? ?????????? ??????????.');
            }

            if (!empty($guardMeta['hire_date'])) {
                $hireDateTs = strtotime($guardMeta['hire_date'] . ' 00:00:00');
                if ($hireDateTs && $startTs < $hireDateTs) {
                    throw new Exception('???? ?????? ????? ?? ????? ???? ?????? ???? ?????? ?? ??????.');
                }
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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление постом №<?= (int)$post['post_number'] ?></title>
    <link rel="stylesheet" href="../assets/styles.css">
    </head>
<body>
    <div class="header">
        <h2>Пост №<?= (int)$post['post_number'] ?> — <?= htmlspecialchars($post['object_name']) ?></h2>
        <a href="../logout.php">Выйти</a>
    </div>
    <div class="nav">
        <a href="../distribution.php">← Назад к распределению</a>
        <a href="../objects/delete_post.php?id=<?= $post['id'] ?>" 
           class="btn btn-danger"
           onclick="return confirm('Удалить пост и все смены?')">Удалить пост</a>
    </div>

    <div class="container">
        <div class="card">
            <h3>Информация о посту</h3>
            <p><strong>Объект:</strong> <?= htmlspecialchars($post['object_name']) ?></p>
            <p><strong>Главный охранник:</strong> <?= htmlspecialchars($post['head_guard_name'] ?? 'Не назначен') ?></p>
        </div>

        <div class="card">
            <h3>Назначить охранника на смену</h3>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <form method="POST" id="assign-form">
                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">

                <div class="form-group">
                    <label>Охранник *</label>
                    <select name="guard_id" required>
                        <option value="">— Выберите охранника —</option>
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

                <button type="submit" class="btn btn-success">Назначить на смену</button>
            </form>
        </div>

        <div class="card">
            <h3>Охранники на посту</h3>
            <?php if ($currentGuards): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Охранник</th>
                            <th>Начало смены</th>
                            <th>Окончание смены</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentGuards as $guard): ?>
                            <tr data-shift-id="<?= $guard['shift_id'] ?>">
                                <td><?= htmlspecialchars($guard['full_name']) ?></td>
                                <td><?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($guard['start_time']))) ?></td>
                                <td><?= $guard['end_time'] ? htmlspecialchars(date('d.m.Y H:i:s', strtotime($guard['end_time']))) : '—' ?></td>
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
            if (start < new Date(now - 60000)) {
                e.preventDefault();
                alert('Начало смены не может быть в прошлом!');
            }
        });
    </script>
</body>
</html>
