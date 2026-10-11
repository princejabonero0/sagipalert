<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('dispatcher', 'admin');
$pdo = db();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $from)) $from = date('Y-m-01');
if (!DateTime::createFromFormat('Y-m-d', $to)) $to = date('Y-m-d');
$p = [$from . ' 00:00:00', $to . ' 23:59:59'];

if (($_GET['export'] ?? '') === 'csv') {
    audit('report_export', "Incident CSV $from to $to");
    $st = $pdo->prepare("SELECT i.reference_no, i.incident_type, i.barangay, i.address, i.incident_datetime, i.status, i.priority, i.severity,
        r.full_name AS reporter, i.created_at, i.verified_at, i.resolved_at, i.outcome
        FROM incidents i JOIN users r ON r.id = i.reporter_id WHERE i.created_at BETWEEN ? AND ? ORDER BY i.created_at");
    $st->execute($p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sagipalert_incidents_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reference', 'Type', 'Barangay', 'Address', 'Occurred', 'Status', 'Priority', 'Severity', 'Reporter', 'Reported at', 'Verified at', 'Resolved at', 'Outcome']);
    foreach ($st->fetchAll() as $r) {
        // Prefix cells that could be read as spreadsheet formulas.
        $r = array_map(fn($c) => is_string($c) && preg_match('/^[=+\-@]/', $c) ? "'" . $c : $c, array_values($r));
        fputcsv($out, $r);
    }
    exit;
}

function grouped(PDO $pdo, string $col, array $p): array {
    $st = $pdo->prepare("SELECT COALESCE($col,'Unclassified') AS k, COUNT(*) AS n FROM incidents WHERE created_at BETWEEN ? AND ? GROUP BY k ORDER BY n DESC");
    $st->execute($p);
    return $st->fetchAll();
}
$total = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE created_at BETWEEN ? AND ?');
$total->execute($p);
$total = (int)$total->fetchColumn();

$avg = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, verified_at)) AS verify_min,
    AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) AS resolve_min FROM incidents WHERE created_at BETWEEN ? AND ?");
$avg->execute($p);
$avg = $avg->fetch();

$sections = [
    'By status' => grouped($pdo, 'status', $p),
    'By incident type' => grouped($pdo, 'incident_type', $p),
    'By barangay' => grouped($pdo, 'barangay', $p),
    'By priority' => grouped($pdo, 'priority', $p),
];
function mins($m): string {
    if ($m === null) return '—';
    $m = (int)round($m);
    return $m >= 60 ? floor($m / 60) . ' h ' . ($m % 60) . ' min' : $m . ' min';
}
$pageTitle = 'Reports';
require __DIR__ . '/inc/header.php';
?>
<h1>Incident Reports</h1>
<p class="sub">Summary of incidents reported within a date range.</p>
<form method="get" class="toolbar">
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div><button class="btn">Apply</button>
    <a class="btn btn-alt" href="?from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;export=csv">Export CSV</a>
    <button type="button" class="btn btn-alt" onclick="window.print()">Print</button></div>
</form>
<div class="grid g4" style="margin-bottom:20px">
  <div class="stat"><b><?= $total ?></b><span>Total incidents</span></div>
  <div class="stat"><b><?= e(mins($avg['verify_min'])) ?></b><span>Avg. time to verify</span></div>
  <div class="stat"><b><?= e(mins($avg['resolve_min'])) ?></b><span>Avg. time to resolve</span></div>
</div>
<div class="grid g2">
<?php foreach ($sections as $title => $rows): ?>
  <div class="card"><h2><?= e($title) ?></h2>
  <?php if (!$rows): ?><p class="muted">No data.</p><?php else: ?>
    <table><?php foreach ($rows as $r): ?><tr><td><?= e($r['k']) ?></td><td style="width:60px"><b><?= (int)$r['n'] ?></b></td></tr><?php endforeach; ?></table>
  <?php endif; ?></div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
