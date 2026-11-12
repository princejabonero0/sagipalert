<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$pdo = db();

function count_where(PDO $pdo, string $where, array $p = []): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM incidents i WHERE $where");
    $st->execute($p);
    return (int)$st->fetchColumn();
}

if (is_staff($u)) {
    $stats = [
        'Awaiting verification' => count_where($pdo, "i.status = 'Reported'"),
        'Verified, unassigned' => count_where($pdo, "i.status = 'Verified'"),
        'Active responses' => count_where($pdo, "i.status IN ('Assigned','Responding')"),
        'Resolved today' => count_where($pdo, "i.status = 'Resolved' AND DATE(i.resolved_at) = CURDATE()"),
    ];
    $recent = $pdo->query("SELECT i.*, u.full_name AS reporter FROM incidents i JOIN users u ON u.id = i.reporter_id
        WHERE i.status IN ('Reported','Verified','Assigned','Responding')
        ORDER BY FIELD(i.priority,'Critical','High','Medium','Low') , i.created_at DESC LIMIT 10")->fetchAll();
    $recentTitle = 'Open incidents (urgent first)';
    $overviewText = 'Review new reports, verify critical cases, and keep response operations moving.';
} elseif ($u['role'] === 'responder') {
    $stats = [
        'New assignments' => count_where($pdo, "i.status = 'Assigned' AND EXISTS (SELECT 1 FROM assignments a WHERE a.incident_id = i.id AND a.responder_id = ?)", [$u['id']]),
        'Currently responding' => count_where($pdo, "i.status = 'Responding' AND EXISTS (SELECT 1 FROM assignments a WHERE a.incident_id = i.id AND a.responder_id = ?)", [$u['id']]),
        'Resolved (all time)' => count_where($pdo, "i.status IN ('Resolved','Archived') AND EXISTS (SELECT 1 FROM assignments a WHERE a.incident_id = i.id AND a.responder_id = ?)", [$u['id']]),
    ];
    $st = $pdo->prepare("SELECT i.*, u.full_name AS reporter FROM incidents i JOIN users u ON u.id = i.reporter_id
        JOIN assignments a ON a.incident_id = i.id AND a.responder_id = ?
        WHERE i.status IN ('Assigned','Responding') ORDER BY i.created_at DESC LIMIT 10");
    $st->execute([$u['id']]);
    $recent = $st->fetchAll();
    $recentTitle = 'My active assignments';
    $overviewText = 'Track assigned incidents, accept tasks, and post response updates promptly.';
} else {
    $stats = [
        'My reports' => count_where($pdo, 'i.reporter_id = ?', [$u['id']]),
        'In progress' => count_where($pdo, "i.reporter_id = ? AND i.status IN ('Reported','Verified','Assigned','Responding')", [$u['id']]),
        'Resolved' => count_where($pdo, "i.reporter_id = ? AND i.status IN ('Resolved','Archived')", [$u['id']]),
    ];
    $st = $pdo->prepare('SELECT i.*, u.full_name AS reporter FROM incidents i JOIN users u ON u.id = i.reporter_id WHERE i.reporter_id = ? ORDER BY i.created_at DESC LIMIT 10');
    $st->execute([$u['id']]);
    $recent = $st->fetchAll();
    $recentTitle = 'My recent reports';
    $overviewText = 'Follow your submitted reports and see when dispatch or responders are assigned.';
}
$pageTitle = 'Dashboard';
require __DIR__ . '/inc/header.php';
?>
<div class="page-header">
  <div>
    <span class="eyebrow">Operations overview</span>
    <h1>Hello, <?= e(explode(' ', $u['full_name'])[0]) ?></h1>
  </div>
  <?php if ($u['role'] === 'resident'): ?>
    <a class="btn" href="report.php">+ Report an incident</a>
  <?php endif; ?>
</div>
<p class="sub"><?= e($overviewText) ?></p>
<div class="grid g4" style="margin-bottom:20px">
  <?php foreach ($stats as $label => $n): ?>
    <div class="stat"><b><?= $n ?></b><span><?= e($label) ?></span></div>
  <?php endforeach; ?>
</div>
<div class="card">
  <h2><?= e($recentTitle) ?></h2>
  <?php if (!$recent): ?><p class="muted">Nothing to show yet.</p><?php else: ?>
  <div class="table-wrap">
    <table>
      <tr><th>Ref</th><th>Type</th><th>Barangay</th><th>Priority</th><th>Status</th><th>Reported</th></tr>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><a href="incident.php?id=<?= (int)$r['id'] ?>"><?= e($r['reference_no']) ?></a></td>
        <td><?= e($r['incident_type']) ?></td>
        <td><?= e($r['barangay']) ?></td>
        <td><?= $r['priority'] ? badge($r['priority']) : '<span class="muted">—</span>' ?></td>
        <td><?= badge($r['status']) ?></td>
        <td><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
