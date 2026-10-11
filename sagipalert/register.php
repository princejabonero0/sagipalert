<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) redirect('dashboard.php');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $errors[] = 'Phone number format is invalid.';
    if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) $errors[] = 'Password must be at least 8 characters and contain letters and numbers.';
    if ($pass !== $pass2) $errors[] = 'Passwords do not match.';
    if (!$errors) {
        $st = db()->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) $errors[] = 'That email is already registered.';
    }
    if (!$errors) {
        db()->prepare('INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?,?,?,?,"resident")')
            ->execute([$name, $email, $phone ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
        $id = (int)db()->lastInsertId();
        audit('register', "New resident account $email", $id);
        flash('Account created. You can now log in.');
        redirect('login.php');
    }
}
$pageTitle = 'Register';
require __DIR__ . '/inc/header.php';
?>
<div class="card auth">
  <h1>Create resident account</h1>
  <p class="sub">Responder and dispatcher accounts are created by the system administrator.</p>
  <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Full name</label><input name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">
    <label>Email</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
    <label>Mobile number (optional)</label><input name="phone" value="<?= e($_POST['phone'] ?? '') ?>">
    <label>Password</label><input type="password" name="password" required minlength="8">
    <label>Confirm password</label><input type="password" name="password2" required minlength="8">
    <p><button class="btn" type="submit">Register</button> <a href="login.php">Back to login</a></p>
  </form>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
