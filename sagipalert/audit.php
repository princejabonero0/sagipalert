<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

$action = trim($_GET['action'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;

$where = '1=1';
$p = [];
if ($action !== '') { $where = 'a.action = ?'; $p[] = $action; }

$cnt = db()->prepare("SELECT COUNT(*) FROM audit_logs a WHERE $where");
$cnt->execute($p);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);

$st = db()->prepare("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($p);
$rows = $st->fetchAll();
$actions = db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Trail';
require __DIR__ . '/inc/header.php';
?>
<h1>Audit Trail</h1>
<p class="sub">Record of logins, incident activity, assignments, status changes and administrative actions.</p>
<form method="get" class="toolbar">
  <div><label>Action</label><select name="action"><option value="">All</option>
    <?php foreach ($actions as $a): ?><option <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
  <div><button class="btn">Filter</button></div>
</form>
<div class="card">
<table>
  <tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td style="white-space:nowrap"><?= e(date('M j, Y g:i:s A', strtotime($r['created_at']))) ?></td>
    <td><?= e($r['full_name'] ?? '—') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['details']) ?></td><td><?= e($r['ip_address']) ?></td></tr>
  <?php endforeach; ?>
</table>
<p class="muted">Page <?= $page ?> of <?= $pages ?> (<?= $total ?> entries)
  <?php if ($page > 1): ?> · <a href="?action=<?= urlencode($action) ?>&amp;page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
  <?php if ($page < $pages): ?> · <a href="?action=<?= urlencode($action) ?>&amp;page=<?= $page + 1 ?>">Next</a><?php endif; ?></p>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
