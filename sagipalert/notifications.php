<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'read_all') {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$u['id']]);
    }
    redirect('notifications.php');
}
// Opening a single notification marks it read, then goes to the incident.
if (isset($_GET['open'])) {
    $st = db()->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
    $st->execute([(int)$_GET['open'], $u['id']]);
    if ($n = $st->fetch()) {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([$n['id']]);
        if ($n['incident_id']) redirect('incident.php?id=' . (int)$n['incident_id']);
    }
    redirect('notifications.php');
}

$st = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$st->execute([$u['id']]);
$rows = $st->fetchAll();

$pageTitle = 'Notifications';
require __DIR__ . '/inc/header.php';
?>
<h1>Notifications</h1>
<p class="sub">Updates about incidents you reported, are assigned to, or manage.</p>
<form method="post" class="noprint"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><p><button class="btn btn-alt btn-sm">Mark all as read</button></p></form>
<div class="card">
<?php if (!$rows): ?><p class="muted">No notifications.</p><?php else: ?>
<table>
  <?php foreach ($rows as $n): ?>
  <tr class="<?= $n['is_read'] ? '' : 'unread' ?>">
    <td><a href="notifications.php?open=<?= (int)$n['id'] ?>"><?= e($n['message']) ?></a></td>
    <td style="white-space:nowrap"><?= e(date('M j, g:i A', strtotime($n['created_at']))) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
