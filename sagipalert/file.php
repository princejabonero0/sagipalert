<?php
// Controlled document access: files are only served to users allowed to view the related incident.
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$documentId = (int)($_GET['id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(400);
    exit('Invalid document ID.');
}

$st = db()->prepare('SELECT d.*, i.reporter_id, i.id AS iid FROM documents d JOIN incidents i ON i.id = d.incident_id WHERE d.id = ?');
$st->execute([$documentId]);
$d = $st->fetch();
if (!$d || !can_view_incident($u, ['id' => $d['iid'], 'reporter_id' => $d['reporter_id']])) {
    http_response_code(404);
    exit('File not found.');
}

$storedName = basename($d['stored_name']);
if ($storedName !== $d['stored_name'] || $storedName === '' || preg_match('/[^A-Za-z0-9_.-]/', $storedName)) {
    http_response_code(400);
    exit('Invalid stored file name.');
}

$path = __DIR__ . '/storage/evidence/' . $storedName;
if (!is_file($path)) { http_response_code(404); exit('File missing from storage.'); }

$auditName = str_replace(['"', "\r", "\n"], '', $d['original_name']);
audit('document_view', "Document #{$d['id']} ({$auditName})");
header('Content-Type: ' . $d['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $auditName . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
