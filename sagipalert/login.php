<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $error = 'Too many failed attempts. Try again in a few minutes.';
    } elseif ($user && $user['is_active'] && password_verify($pass, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$user['id']]);
        audit('login', 'User logged in', (int)$user['id']);
        redirect('dashboard.php');
    } else {
        if ($user) {
            $fails = (int)$user['failed_logins'] + 1;
            $lock = $fails >= 5 ? date('Y-m-d H:i:s', time() + 600) : null;
            db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
                ->execute([$lock ? 0 : $fails, $lock, $user['id']]);
        }
        audit('login_failed', 'Failed login for ' . $email, $user ? (int)$user['id'] : null);
        $error = 'Invalid email or password, or the account is disabled.';
    }
}
$pageTitle = 'Log in';
require __DIR__ . '/inc/header.php';
?>
<div class="card auth">
  <h1>Welcome to SagipAlert</h1>
  <p class="sub">Log in to report or manage emergency incidents in Minglanilla, Cebu.</p>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
    <label for="password">Password</label>
    <input id="password" type="password" name="password" required>
    <p><button class="btn" type="submit">Log in</button></p>
  </form>
  <p class="muted">New resident? <a href="register.php">Create an account</a></p>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
