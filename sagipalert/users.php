<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_role('admin');
$pdo = db();
$roles = ['resident', 'dispatcher', 'responder', 'admin'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? '';
        $unit = trim($_POST['responder_unit'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        if (mb_strlen($name) < 2) $errors[] = 'Enter a full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
        if (!in_array($role, $roles, true)) $errors[] = 'Choose a role.';
        if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) $errors[] = 'Password needs 8+ characters with letters and numbers.';
        if (!$errors) {
            $chk = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
            $chk->execute([$email]);
            if ($chk->fetchColumn()) $errors[] = 'Email already exists.';
        }
        if (!$errors) {
            $pdo->prepare('INSERT INTO users (full_name, email, role, password_hash, responder_unit) VALUES (?,?,?,?,?)')
                ->execute([$name, $email, $role, password_hash($pass, PASSWORD_DEFAULT), $role === 'responder' ? ($unit ?: null) : null]);
            audit('user_create', "Created $role account $email");
            flash('User created.');
            redirect('users.php');
        }
    } else {
        $target = (int)($_POST['user_id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$target]);
        $t = $st->fetch();
        if ($t) {
            if ($action === 'role' && in_array($_POST['role'] ?? '', $roles, true)) {
                if ($target === (int)$me['id']) { flash('You cannot change your own role.', 'error'); redirect('users.php'); }
                $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$_POST['role'], $target]);
                audit('user_role', "{$t['email']}: {$t['role']} -> {$_POST['role']}");
                flash('Role updated.');
            } elseif ($action === 'toggle') {
                if ($target === (int)$me['id']) { flash('You cannot disable your own account.', 'error'); redirect('users.php'); }
                $new = $t['is_active'] ? 0 : 1;
                $pdo->prepare('UPDATE users SET is_active = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$new, $target]);
                audit('user_toggle', ($new ? 'Enabled ' : 'Disabled ') . $t['email']);
                flash($new ? 'Account enabled.' : 'Account disabled.');
            } elseif ($action === 'reset') {
                $pw = (string)($_POST['new_password'] ?? '');
                if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
                    flash('New password needs 8+ characters with letters and numbers.', 'error'); redirect('users.php');
                }
                $pdo->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $target]);
                audit('user_password_reset', "Reset password for {$t['email']}");
                flash('Password reset.');
            }
        }
        redirect('users.php');
    }
}
$users = $pdo->query('SELECT * FROM users ORDER BY role, full_name')->fetchAll();
$pageTitle = 'Users';
require __DIR__ . '/inc/header.php';
?>
<h1>User Management</h1>
<p class="sub">Create staff accounts, assign roles and control access.</p>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" class="card">
  <h2>Add user</h2>
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="row">
    <div><label>Full name</label><input name="full_name" required></div>
    <div><label>Email</label><input type="email" name="email" required></div>
    <div><label>Role</label><select name="role"><?php foreach ($roles as $r): ?><option value="<?= $r ?>"><?= ucfirst($r) ?></option><?php endforeach; ?></select></div>
    <div><label>Responder unit (if responder)</label><input name="responder_unit" placeholder="e.g. BFP, MDRRMO, Barangay Tanod"></div>
    <div><label>Temporary password</label><input type="password" name="password" required></div>
  </div>
  <p><button class="btn">Create user</button></p>
</form>

<div class="card">
<table>
  <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr>
  <?php foreach ($users as $x): ?>
  <tr>
    <td><?= e($x['full_name']) ?><?= $x['responder_unit'] ? '<br><small class="muted">' . e($x['responder_unit']) . '</small>' : '' ?></td>
    <td><?= e($x['email']) ?></td>
    <td>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
        <select name="role" onchange="this.form.submit()" style="width:auto" <?= (int)$x['id'] === (int)$me['id'] ? 'disabled' : '' ?>>
          <?php foreach ($roles as $r): ?><option value="<?= $r ?>" <?= $x['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?>
        </select></form>
    </td>
    <td><?= $x['is_active'] ? badge('Resolved') . ' Active' : badge('Rejected') . ' Disabled' ?></td>
    <td>
      <?php if ((int)$x['id'] !== (int)$me['id']): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>">
        <button class="btn btn-alt btn-sm"><?= $x['is_active'] ? 'Disable' : 'Enable' ?></button></form>
      <?php endif; ?>
      <form method="post" class="inline" onsubmit="var p=prompt('New password for <?= e(addslashes($x['full_name'])) ?> (8+ chars, letters and numbers):');if(!p)return false;this.new_password.value=p;return true;">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="new_password">
        <button class="btn btn-alt btn-sm">Reset password</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
