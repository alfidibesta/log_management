<?php
require_once __DIR__ . '/../src/helpers.php';

$hasUsers = has_any_users();
$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));

    if (!$hasUsers) {
        // First run: create the founding admin account.
        $name     = trim((string) ($_POST['name'] ?? ''));
        $pass     = (string) ($_POST['password'] ?? '');
        $pass2    = (string) ($_POST['password2'] ?? '');
        if ($username === '' || $name === '') {
            $error = 'Username and name are required.';
        } elseif (strlen($username) < 3 || strlen($username) > 64) {
            $error = 'Username must be 3-64 characters.';
        } elseif (strlen($pass) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($pass !== $pass2) {
            $error = 'Passwords do not match.';
        } else {
            $st = db()->prepare("INSERT INTO users (username, name, password_hash, role) VALUES (?,?,?,'admin')");
            $st->execute([$username, $name, password_hash($pass, PASSWORD_DEFAULT)]);
            flash('Admin account created. Please sign in.');
            header('Location: login.php');
            exit;
        }
    } else {
        $st = db()->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
        $st->execute([$username]);
        $user = $st->fetch();
        if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            session_regenerate_id(true);
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            header('Location: index.php');
            exit;
        }
        $error = 'Invalid username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $hasUsers ? 'Sign in' : 'Create admin' ?> · Factory Log</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="auth">
  <div class="auth-card">
    <span class="brand-mark">FL</span>
    <h1>Factory Log</h1>
    <p class="sub"><?= $hasUsers
        ? 'Sign in to record belongings in / out.'
        : 'First run — create the admin account to open the site.' ?></p>

    <?php if ($error): ?><div class="toast toast-err"><?= e($error) ?></div><?php endif; ?>
    <?php foreach (take_flashes() as $f): ?>
      <div class="toast toast-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <?php if (!$hasUsers): ?>
        <label class="field">Your name
          <input type="text" name="name" required placeholder="e.g. Site Admin">
        </label>
      <?php endif; ?>
      <label class="field">Username
        <input type="text" name="username" required autofocus value="<?= e($username) ?>"
               placeholder="<?= $hasUsers ? 'your username' : 'e.g. admin' ?>">
      </label>
      <label class="field">Password
        <input type="password" name="password" required placeholder="••••••••">
      </label>
      <?php if (!$hasUsers): ?>
        <label class="field">Repeat password
          <input type="password" name="password2" required placeholder="••••••••">
        </label>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit">
        <?= $hasUsers ? 'Sign in' : 'Create admin &amp; sign in' ?>
      </button>
    </form>

    <div class="auth-foot">
      <?= $hasUsers
          ? 'Accounts are managed by the administrator.'
          : 'Roles: <span class="badge badge-accent">admin</span> full control · <span class="badge badge-neutral">personnel</span> input &amp; verify' ?>
    </div>
  </div>
</div>
</body>
</html>
