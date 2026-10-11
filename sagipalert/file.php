<?php
// Controlled document access: files are only served to users allowed to view the related incident.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$st = db()->prepare('SELECT d.*, i.reporter_id, i.id AS iid FROM documents d JOIN incidents i ON i.id = d.incident_id WHERE d.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$d = $st->fetch();
if (!$d || !can_view_incident($u, ['id' => $d['iid'], 'reporter_id' => $d['reporter_id']])) {
    http_response_code(404);
    exit('File not found.');
}
$path = __DIR__ . '/storage/evidence/' . basename($d['stored_name']);
if (!is_file($path)) { http_response_code(404); exit('File missing from storage.'); }

audit('document_view', "Document #{$d['id']} ({$d['original_name']})");
header('Content-Type: ' . $d['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $d['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
