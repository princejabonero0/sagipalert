<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$fStatus = $_GET['status'] ?? '';
$fType = $_GET['type'] ?? '';
$fBrgy = $_GET['barangay'] ?? '';
$q = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];
$join = '';
if ($u['role'] === 'resident') { $where[] = 'i.reporter_id = ?'; $params[] = $u['id']; }
if ($u['role'] === 'responder') { $join = 'JOIN assignments a ON a.incident_id = i.id AND a.responder_id = ' . (int)$u['id']; }
if (in_array($fStatus, STATUSES, true)) { $where[] = 'i.status = ?'; $params[] = $fStatus; }
if (in_array($fType, cfg('incident_types'), true)) { $where[] = 'i.incident_type = ?'; $params[] = $fType; }
if (in_array($fBrgy, cfg('barangays'), true)) { $where[] = 'i.barangay = ?'; $params[] = $fBrgy; }
if ($q !== '') {
    $where[] = '(i.reference_no LIKE ? OR i.description LIKE ? OR i.address LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql = "SELECT i.*, r.full_name AS reporter FROM incidents i JOIN users r ON r.id = i.reporter_id $join
        WHERE " . implode(' AND ', $where) . ' ORDER BY i.created_at DESC LIMIT 200';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'Incidents';
require __DIR__ . '/inc/header.php';
?>
<h1><?= $u['role'] === 'resident' ? 'My Reports' : ($u['role'] === 'responder' ? 'My Assignments' : 'Incidents') ?></h1>
<p class="sub">Search and filter incident records.</p>
<form method="get" class="toolbar">
  <div><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Ref, description, address"></div>
  <div><label>Status</label><select name="status"><option value="">All</option>
    <?php foreach (STATUSES as $s): ?><option <?= $fStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
  <div><label>Type</label><select name="type"><option value="">All</option>
    <?php foreach (cfg('incident_types') as $s): ?><option <?= $fType === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
  <div><label>Barangay</label><select name="barangay"><option value="">All</option>
    <?php foreach (cfg('barangays') as $s): ?><option <?= $fBrgy === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
  <div><button class="btn" type="submit">Filter</button> <a class="btn btn-alt" href="incidents.php">Reset</a></div>
</form>
<div class="card">
<?php if (!$rows): ?><p class="muted">No incidents match.</p><?php else: ?>
<table>
  <tr><th>Ref</th><th>Type</th><th>Location</th><th>Priority</th><th>Status</th><?php if ($u['role'] !== 'resident'): ?><th>Reporter</th><?php endif; ?><th>Reported</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a href="incident.php?id=<?= (int)$r['id'] ?>"><?= e($r['reference_no']) ?></a></td>
    <td><?= e($r['incident_type']) ?></td>
    <td><?= e($r['barangay']) ?><br><small class="muted"><?= e($r['address']) ?></small></td>
    <td><?= $r['priority'] ? badge($r['priority']) : '<span class="muted">—</span>' ?></td>
    <td><?= badge($r['status']) ?></td>
    <?php if ($u['role'] !== 'resident'): ?><td><?= e($r['reporter']) ?></td><?php endif; ?>
    <td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
