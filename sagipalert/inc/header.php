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
<meta name="theme-color" content="#11263d">
<title><?= e(($pageTitle ?? 'Page') . ' · ' . cfg('app_name')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php" aria-label="SagipAlert home"><span class="logo" aria-hidden="true">⚑</span><span>SagipAlert</span></a>
    <?php if ($u): ?>
    <button class="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">☰</button>
    <nav class="main-nav" aria-label="Main navigation">
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
    <div class="userbox" aria-label="User actions">
      <div class="user-meta">
        <strong><?= e($u['full_name']) ?></strong>
        <small><?= e(ucfirst($u['role'])) ?></small>
      </div>
      <a href="logout.php" class="btn btn-sm btn-ghost">Log out</a>
    </div>
    <?php endif; ?>
  </div>
</header>
<main class="container">
<?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['t']) ?>"><?= e($flash['m']) ?></div>
<?php endif; ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.querySelector('.main-nav');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', function () {
      const expanded = this.getAttribute('aria-expanded') === 'true';
      this.setAttribute('aria-expanded', String(!expanded));
      nav.classList.toggle('open');
    });
  });
</script>
