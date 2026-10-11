<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT i.*, r.full_name AS reporter, r.phone AS reporter_phone, v.full_name AS verifier
    FROM incidents i JOIN users r ON r.id = i.reporter_id LEFT JOIN users v ON v.id = i.verified_by WHERE i.id = ?');
$st->execute([$id]);
$inc = $st->fetch();
if (!$inc || !can_view_incident($u, $inc)) {
    http_response_code(404);
    exit('Incident not found or you do not have access to it.');
}

$staff = is_staff($u);
$isResponder = $u['role'] === 'responder';
$isOwner = $u['role'] === 'resident' && (int)$inc['reporter_id'] === (int)$u['id'];
$open = in_array($inc['status'], ['Verified', 'Assigned', 'Responding'], true);
$back = 'incident.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $uid = (int)$u['id'];

    if ($staff && $action === 'verify' && $inc['status'] === 'Reported') {
        $prio = $_POST['priority'] ?? '';
        $sev = $_POST['severity'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        if (!in_array($prio, ['Low', 'Medium', 'High', 'Critical'], true) || !in_array($sev, ['Minor', 'Moderate', 'Major', 'Catastrophic'], true)) {
            flash('Select a priority and severity.', 'error'); redirect($back);
        }
        $pdo->prepare('UPDATE incidents SET priority=?, severity=?, verified_by=?, verified_at=NOW(), verification_notes=? WHERE id=?')
            ->execute([$prio, $sev, $uid, $notes ?: null, $id]);
        set_status($id, 'Verified', $uid);
        notify((int)$inc['reporter_id'], "Your report {$inc['reference_no']} was verified (priority: $prio).", $id);
        flash('Incident verified and classified.');
        redirect($back);
    }

    if ($staff && $action === 'reject' && $inc['status'] === 'Reported') {
        $notes = trim($_POST['notes'] ?? '');
        if ($notes === '') { flash('Please enter a reason for rejecting the report.', 'error'); redirect($back); }
        $pdo->prepare('UPDATE incidents SET verified_by=?, verified_at=NOW(), verification_notes=? WHERE id=?')->execute([$uid, $notes, $id]);
        set_status($id, 'Rejected', $uid);
        notify((int)$inc['reporter_id'], "Your report {$inc['reference_no']} could not be verified: $notes", $id);
        flash('Report rejected.');
        redirect($back);
    }

    if ($staff && $action === 'assign' && $open) {
        $rid = (int)($_POST['responder_id'] ?? 0);
        $instr = trim($_POST['instructions'] ?? '');
        $chk = $pdo->prepare("SELECT full_name FROM users WHERE id = ? AND role = 'responder' AND is_active = 1");
        $chk->execute([$rid]);
        $rname = $chk->fetchColumn();
        if (!$rname) { flash('Choose a valid responder.', 'error'); redirect($back); }
        $dup = $pdo->prepare('SELECT 1 FROM assignments WHERE incident_id = ? AND responder_id = ?');
        $dup->execute([$id, $rid]);
        if ($dup->fetchColumn()) { flash('That responder is already assigned.', 'error'); redirect($back); }
        $pdo->prepare('INSERT INTO assignments (incident_id, responder_id, assigned_by, instructions) VALUES (?,?,?,?)')
            ->execute([$id, $rid, $uid, $instr ?: null]);
        audit('assign_responder', "Incident #$id assigned to $rname", $uid);
        if ($inc['status'] === 'Verified') set_status($id, 'Assigned', $uid);
        notify($rid, "You were assigned to {$inc['incident_type']} incident {$inc['reference_no']} in {$inc['barangay']}.", $id);
        notify((int)$inc['reporter_id'], "A responder has been assigned to your report {$inc['reference_no']}.", $id);
        flash("Assigned $rname.");
        redirect($back);
    }

    if ($isResponder && $action === 'accept' && in_array($inc['status'], ['Assigned', 'Responding'], true)) {
        $pdo->prepare('UPDATE assignments SET accepted_at = COALESCE(accepted_at, NOW()) WHERE incident_id = ? AND responder_id = ?')->execute([$id, $uid]);
        if ($inc['status'] === 'Assigned') {
            set_status($id, 'Responding', $uid);
            notify((int)$inc['reporter_id'], "Responders are on the way for your report {$inc['reference_no']}.", $id);
            notify_role(['dispatcher'], "{$u['full_name']} is responding to {$inc['reference_no']}.", $id);
        }
        audit('accept_assignment', "Incident #$id", $uid);
        flash('Assignment accepted. Status is now Responding.');
        redirect($back);
    }

    if (($staff || $isResponder) && $action === 'update' && in_array($inc['status'], ['Assigned', 'Responding', 'Verified'], true)) {
        $note = trim($_POST['note'] ?? '');
        if ($note === '') { flash('Enter an update note.', 'error'); redirect($back); }
        $pdo->prepare('INSERT INTO response_updates (incident_id, user_id, note) VALUES (?,?,?)')->execute([$id, $uid, $note]);
        audit('response_update', "Incident #$id", $uid);
        notify_role(['dispatcher'], "Update on {$inc['reference_no']} from {$u['full_name']}.", $id);
        flash('Update posted.');
        redirect($back);
    }

    if (($staff || $isResponder) && $action === 'resolve' && in_array($inc['status'], ['Assigned', 'Responding'], true)) {
        $outcome = trim($_POST['outcome'] ?? '');
        if (mb_strlen($outcome) < 5) { flash('Describe the outcome before resolving.', 'error'); redirect($back); }
        $pdo->prepare('UPDATE incidents SET outcome = ?, resolved_at = NOW() WHERE id = ?')->execute([$outcome, $id]);
        $pdo->prepare('UPDATE assignments SET completed_at = NOW() WHERE incident_id = ? AND completed_at IS NULL')->execute([$id]);
        set_status($id, 'Resolved', $uid);
        notify((int)$inc['reporter_id'], "Your report {$inc['reference_no']} has been resolved.", $id);
        notify_role(['dispatcher'], "{$inc['reference_no']} was marked Resolved by {$u['full_name']}.", $id);
        flash('Incident marked as Resolved.');
        redirect($back);
    }

    if ($staff && $action === 'archive' && $inc['status'] === 'Resolved') {
        set_status($id, 'Archived', $uid);
        flash('Incident archived.');
        redirect($back);
    }

    if ($action === 'upload') {
        $allowedUpload = $staff || $isResponder || ($isOwner && in_array($inc['status'], ['Reported', 'Verified', 'Assigned', 'Responding'], true));
        if ($allowedUpload) {
            $type = ($staff || $isResponder) && ($_POST['doc_type'] ?? '') === 'Official Document' ? 'Official Document' : 'Evidence';
            $err = store_upload($_FILES['file'] ?? ['error' => UPLOAD_ERR_NO_FILE], $id, $uid, $type);
            flash($err ?: 'File uploaded.', $err ? 'error' : 'success');
            redirect($back);
        }
    }

    http_response_code(400);
    exit('That action is not allowed for this incident right now.');
}

// Data for display
$assignments = $pdo->prepare('SELECT a.*, u.full_name, u.responder_unit, b.full_name AS assigner FROM assignments a
    JOIN users u ON u.id = a.responder_id JOIN users b ON b.id = a.assigned_by WHERE a.incident_id = ? ORDER BY a.assigned_at');
$assignments->execute([$id]);
$assignments = $assignments->fetchAll();

$updates = $pdo->prepare('SELECT ru.*, u.full_name, u.role FROM response_updates ru JOIN users u ON u.id = ru.user_id WHERE ru.incident_id = ? ORDER BY ru.created_at');
$updates->execute([$id]);
$updates = $updates->fetchAll();

$history = $pdo->prepare('SELECT h.*, u.full_name FROM status_history h JOIN users u ON u.id = h.changed_by WHERE h.incident_id = ? ORDER BY h.changed_at');
$history->execute([$id]);
$history = $history->fetchAll();

$docs = $pdo->prepare('SELECT d.*, u.full_name FROM documents d JOIN users u ON u.id = d.uploaded_by WHERE d.incident_id = ? ORDER BY d.uploaded_at');
$docs->execute([$id]);
$docs = $docs->fetchAll();

$responders = $staff && $open ? $pdo->query("SELECT id, full_name, responder_unit FROM users WHERE role = 'responder' AND is_active = 1 ORDER BY full_name")->fetchAll() : [];
$myAssignment = null;
foreach ($assignments as $a) if ($isResponder && (int)$a['responder_id'] === (int)$u['id']) $myAssignment = $a;

$pageTitle = $inc['reference_no'];
require __DIR__ . '/inc/header.php';
?>
<p><a href="incidents.php">&larr; Back to list</a></p>
<h1><?= e($inc['incident_type']) ?> <small class="muted">· <?= e($inc['reference_no']) ?></small></h1>
<p class="sub"><?= badge($inc['status']) ?> <?= $inc['priority'] ? badge($inc['priority']) . ' priority' : '' ?> <?= $inc['severity'] ? '· ' . e($inc['severity']) . ' severity' : '' ?></p>

<div class="grid g2">
  <div class="card">
    <h2>Incident details</h2>
    <dl class="kv">
      <dt>Description</dt><dd><?= nl2br(e($inc['description'])) ?></dd>
      <dt>Barangay</dt><dd><?= e($inc['barangay']) ?></dd>
      <dt>Address</dt><dd><?= e($inc['address']) ?></dd>
      <?php if ($inc['latitude'] !== null): ?>
      <dt>Coordinates</dt><dd><?= e($inc['latitude']) ?>, <?= e($inc['longitude']) ?>
        · <a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= e($inc['latitude']) ?>&amp;mlon=<?= e($inc['longitude']) ?>#map=17/<?= e($inc['latitude']) ?>/<?= e($inc['longitude']) ?>">View map</a></dd>
      <?php endif; ?>
      <dt>Occurred</dt><dd><?= e(date('M j, Y g:i A', strtotime($inc['incident_datetime']))) ?></dd>
      <dt>Reported by</dt><dd><?= e($inc['reporter']) ?><?= $staff && $inc['reporter_phone'] ? ' · ' . e($inc['reporter_phone']) : '' ?></dd>
      <dt>Reported at</dt><dd><?= e(date('M j, Y g:i A', strtotime($inc['created_at']))) ?></dd>
      <?php if ($inc['verified_at']): ?>
      <dt>Verified by</dt><dd><?= e($inc['verifier']) ?> · <?= e(date('M j, g:i A', strtotime($inc['verified_at']))) ?></dd>
      <?php if ($inc['verification_notes']): ?><dt>Notes</dt><dd><?= nl2br(e($inc['verification_notes'])) ?></dd><?php endif; ?>
      <?php endif; ?>
      <?php if ($inc['outcome']): ?><dt>Outcome</dt><dd><?= nl2br(e($inc['outcome'])) ?></dd><?php endif; ?>
    </dl>
  </div>

  <div class="card">
    <h2>Status history</h2>
    <ul class="timeline">
      <?php foreach ($history as $h): ?>
      <li><?= badge($h['new_status']) ?> <small><?= e($h['full_name']) ?> · <?= e(date('M j, g:i A', strtotime($h['changed_at']))) ?></small></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<?php if ($staff && $inc['status'] === 'Reported'): ?>
<div class="card noprint">
  <h2>Verify and classify</h2>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row">
      <div><label>Priority</label><select name="priority"><option value="">Select…</option><option>Low</option><option>Medium</option><option>High</option><option>Critical</option></select></div>
      <div><label>Severity</label><select name="severity"><option value="">Select…</option><option>Minor</option><option>Moderate</option><option>Major</option><option>Catastrophic</option></select></div>
    </div>
    <label>Verification notes / reason if rejecting</label>
    <textarea name="notes"></textarea>
    <p>
      <button class="btn btn-ok" name="action" value="verify">Verify report</button>
      <button class="btn btn-alt" name="action" value="reject" onclick="return confirm('Reject this report?')">Reject</button>
    </p>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Assigned responders</h2>
  <?php if (!$assignments): ?><p class="muted">No responders assigned yet.</p><?php else: ?>
  <table>
    <tr><th>Responder</th><th>Unit</th><th>Assigned</th><th>Accepted</th><th>Instructions</th></tr>
    <?php foreach ($assignments as $a): ?>
    <tr><td><?= e($a['full_name']) ?></td><td><?= e($a['responder_unit'] ?: '—') ?></td>
      <td><?= e(date('M j, g:i A', strtotime($a['assigned_at']))) ?><br><small class="muted">by <?= e($a['assigner']) ?></small></td>
      <td><?= $a['accepted_at'] ? e(date('M j, g:i A', strtotime($a['accepted_at']))) : '<span class="muted">Pending</span>' ?></td>
      <td><?= e($a['instructions'] ?: '—') ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if ($staff && $open): ?>
  <form method="post" class="noprint" style="margin-top:16px">
    <?= csrf_field() ?><input type="hidden" name="action" value="assign">
    <div class="row">
      <div><label>Dispatch responder</label>
        <select name="responder_id" required><option value="">Select responder…</option>
          <?php foreach ($responders as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['full_name'] . ($r['responder_unit'] ? ' (' . $r['responder_unit'] . ')' : '')) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Instructions (optional)</label><input name="instructions"></div>
    </div>
    <p><button class="btn">Assign responder</button></p>
  </form>
  <?php endif; ?>

  <?php if ($myAssignment && in_array($inc['status'], ['Assigned', 'Responding'], true) && !$myAssignment['accepted_at']): ?>
  <form method="post" class="noprint"><?= csrf_field() ?><p><button class="btn btn-ok" name="action" value="accept">Accept assignment &amp; start responding</button></p></form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Response updates</h2>
  <?php if (!$updates): ?><p class="muted">No updates yet.</p><?php else: ?>
  <ul class="timeline">
    <?php foreach ($updates as $up): ?>
    <li><?= nl2br(e($up['note'])) ?><small><?= e($up['full_name']) ?> (<?= e($up['role']) ?>) · <?= e(date('M j, g:i A', strtotime($up['created_at']))) ?></small></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if (($staff || $isResponder) && in_array($inc['status'], ['Verified', 'Assigned', 'Responding'], true)): ?>
  <form method="post" class="noprint">
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <label>Post an update</label><textarea name="note" placeholder="On scene, patient transported, fire contained…"></textarea>
    <p><button class="btn">Post update</button></p>
  </form>
  <?php endif; ?>
</div>

<?php if (($staff || $isResponder) && in_array($inc['status'], ['Assigned', 'Responding'], true)): ?>
<div class="card noprint">
  <h2>Resolve incident</h2>
  <form method="post" onsubmit="return confirm('Mark this incident as Resolved?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="resolve">
    <label>Outcome / summary of response</label><textarea name="outcome" required></textarea>
    <p><button class="btn btn-ok">Mark as Resolved</button></p>
  </form>
</div>
<?php endif; ?>

<?php if ($staff && $inc['status'] === 'Resolved'): ?>
<form method="post" class="noprint"><?= csrf_field() ?><p><button class="btn btn-alt" name="action" value="archive">Archive incident</button></p></form>
<?php endif; ?>

<div class="card">
  <h2>Evidence &amp; documents</h2>
  <?php if (!$docs): ?><p class="muted">No files attached.</p><?php else: ?>
  <table>
    <tr><th>File</th><th>Type</th><th>Uploaded by</th><th>Date</th></tr>
    <?php foreach ($docs as $d): ?>
    <tr><td><a href="file.php?id=<?= (int)$d['id'] ?>" target="_blank"><?= e($d['original_name']) ?></a> <small class="muted">(<?= e(round($d['size_bytes'] / 1024)) ?> KB)</small></td>
      <td><?= e($d['doc_type']) ?></td><td><?= e($d['full_name']) ?></td><td><?= e(date('M j, g:i A', strtotime($d['uploaded_at']))) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if ($staff || $isResponder || ($isOwner && !in_array($inc['status'], ['Resolved', 'Archived', 'Rejected'], true))): ?>
  <form method="post" enctype="multipart/form-data" class="noprint" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <div class="row">
      <div><label>Add file (JPG, PNG, PDF, max 5 MB)</label><input type="file" name="file" required accept=".jpg,.jpeg,.png,.pdf"></div>
      <?php if ($staff || $isResponder): ?>
      <div><label>Document type</label><select name="doc_type"><option>Evidence</option><option>Official Document</option></select></div>
      <?php endif; ?>
    </div>
    <p><button class="btn btn-alt">Upload</button></p>
  </form>
  <?php endif; ?>
</div>
<p class="noprint"><button class="btn btn-alt" onclick="window.print()">Print incident record</button></p>
<?php require __DIR__ . '/inc/footer.php'; ?>
