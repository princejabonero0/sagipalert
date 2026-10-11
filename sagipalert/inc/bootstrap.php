<?php
// Core helpers: config, DB, session, CSRF, auth, RBAC, audit, notifications, uploads.
$CONFIG = require __DIR__ . '/../config.php';
date_default_timezone_set($CONFIG['timezone']);

session_name('sagipalert');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

function cfg(string $key) { global $CONFIG; return $CONFIG[$key]; }

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed. Check config.php and run setup.php first.');
        }
    }
    return $pdo;
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function redirect(string $url): void { header('Location: ' . $url); exit; }

function flash(?string $msg = null, string $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = ['m' => $msg, 't' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---- CSRF ----
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('Invalid or expired form token. Go back, refresh the page and try again.');
    }
}

// ---- Auth / RBAC ----
function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
            $st->execute([$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
            if (!$user) { unset($_SESSION['uid']); }
        }
    }
    return $user;
}
function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}
function require_role(string ...$roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied: your role cannot open this page.');
    }
    return $u;
}
function is_staff(array $u): bool { return in_array($u['role'], ['dispatcher', 'admin'], true); }

// ---- Audit trail ----
function audit(string $action, string $details = '', ?int $uid = null): void {
    $uid = $uid ?? ($_SESSION['uid'] ?? null);
    $st = db()->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?,?,?,?)');
    $st->execute([$uid, $action, mb_substr($details, 0, 500), $_SERVER['REMOTE_ADDR'] ?? null]);
}

// ---- Notifications ----
function notify(int $userId, string $message, ?int $incidentId = null): void {
    $st = db()->prepare('INSERT INTO notifications (user_id, incident_id, message) VALUES (?,?,?)');
    $st->execute([$userId, $incidentId, mb_substr($message, 0, 255)]);
}
function notify_role(array $roles, string $message, ?int $incidentId = null): void {
    $in = implode(',', array_fill(0, count($roles), '?'));
    $st = db()->prepare("SELECT id FROM users WHERE role IN ($in) AND is_active = 1");
    $st->execute($roles);
    foreach ($st->fetchAll() as $r) notify((int)$r['id'], $message, $incidentId);
}
function unread_count(int $uid): int {
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

// ---- Incident helpers ----
const STATUSES = ['Reported', 'Verified', 'Assigned', 'Responding', 'Resolved', 'Archived', 'Rejected'];

function set_status(int $incidentId, string $new, int $byUser): void {
    $st = db()->prepare('SELECT status FROM incidents WHERE id = ?');
    $st->execute([$incidentId]);
    $old = $st->fetchColumn();
    if ($old === $new) return;
    db()->prepare('UPDATE incidents SET status = ? WHERE id = ?')->execute([$new, $incidentId]);
    db()->prepare('INSERT INTO status_history (incident_id, old_status, new_status, changed_by) VALUES (?,?,?,?)')
        ->execute([$incidentId, $old ?: null, $new, $byUser]);
    audit('status_change', "Incident #$incidentId: $old -> $new", $byUser);
}

function can_view_incident(array $u, array $inc): bool {
    if (is_staff($u)) return true;
    if ($u['role'] === 'resident') return (int)$inc['reporter_id'] === (int)$u['id'];
    if ($u['role'] === 'responder') {
        $st = db()->prepare('SELECT 1 FROM assignments WHERE incident_id = ? AND responder_id = ?');
        $st->execute([$inc['id'], $u['id']]);
        return (bool)$st->fetchColumn();
    }
    return false;
}

function badge(string $text): string {
    return '<span class="badge b-' . strtolower(preg_replace('/[^A-Za-z]/', '', $text)) . '">' . e($text) . '</span>';
}

// ---- Secure uploads (stored outside web access via .htaccess; served only through file.php) ----
function store_upload(array $file, int $incidentId, int $userId, string $docType): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload failed (error code ' . (int)$file['error'] . ').';
    if ($file['size'] > cfg('max_upload_bytes')) return 'File is too large (max 5 MB).';
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, cfg('allowed_ext'), true)) return 'Only JPG, PNG and PDF files are allowed.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) return 'File content type is not allowed.';
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dir = __DIR__ . '/../storage/evidence';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return 'Could not save the uploaded file.';
    db()->prepare('INSERT INTO documents (incident_id, uploaded_by, doc_type, original_name, stored_name, mime_type, size_bytes) VALUES (?,?,?,?,?,?,?)')
        ->execute([$incidentId, $userId, $docType, mb_substr(basename($file['name']), 0, 255), $stored, $mime, (int)$file['size']]);
    audit('document_upload', "Incident #$incidentId: " . basename($file['name']), $userId);
    return null;
}
