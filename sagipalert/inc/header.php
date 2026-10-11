<?php
// Usage: $pageTitle = '...'; require 'inc/header.php';  (page must call require_login() first if protected)
$u = current_user();
$flash = flash();
$self = basename($_SERVER['SCRIPT_NAME']);
function nav_link(string $file, string $label, string $self): string {
    return '<a href="' . $file . '"' . ($self === $file ? ' class="active"' : '') . '>' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? 'Page') . ' · ' . cfg('app_name')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><span class="logo">&#9888;</span> SagipAlert</a>
  <?php if ($u): ?>
  <nav>
    <?= nav_link('dashboard.php', 'Dashboard', $self) ?>
    <?php if ($u['role'] === 'resident'): ?>
      <?= nav_link('report.php', 'Report Incident', $self) ?>
    <?php endif; ?>
    <?= nav_link('incidents.php', $u['role'] === 'resident' ? 'My Reports' : ($u['role'] === 'responder' ? 'My Assignments' : 'Incidents'), $self) ?>
    <?php if (is_staff($u)): ?>
      <?= nav_link('reports.php', 'Reports', $self) ?>
    <?php endif; ?>
    <?php if ($u['role'] === 'admin'): ?>
      <?= nav_link('users.php', 'Users', $self) ?>
      <?= nav_link('audit.php', 'Audit Trail', $self) ?>
    <?php endif; ?>
    <?php $n = unread_count((int)$u['id']); ?>
    <?= nav_link('notifications.php', 'Notifications' . ($n ? ' <span class="pill">' . $n . '</span>' : ''), $self) ?>
  </nav>
  <div class="userbox">
    <span><?= e($u['full_name']) ?> <small>(<?= e(ucfirst($u['role'])) ?>)</small></span>
    <a href="logout.php" class="btn btn-sm btn-ghost">Log out</a>
  </div>
  <?php endif; ?>
</header>
<main class="container">
<?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['t']) ?>"><?= e($flash['m']) ?></div>
<?php endif; ?>
