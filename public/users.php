<?php
require_once __DIR__ . '/../src/helpers.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $selfId = (int) (current_user()['id'] ?? 0);

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $name     = trim((string) ($_POST['name'] ?? ''));
        $role     = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'personnel';
        $pass     = (string) ($_POST['password'] ?? '');

        if ($username === '' || $name === '') {
            flash('Username and name are required.', 'err');
        } elseif (strlen($pass) < 6) {
            flash('Password must be at least 6 characters.', 'err');
        } else {
            try {
                $st = $pdo->prepare("INSERT INTO users (username, name, password_hash, role) VALUES (?,?,?,?)");
                $st->execute([$username, $name, password_hash($pass, PASSWORD_DEFAULT), $role]);
                flash("User {$username} created.");
            } catch (PDOException $e) {
                flash('That username is already taken.', 'err');
            }
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'role') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === $selfId) {
            flash('You cannot change your own role.', 'err');
        } else {
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'personnel';
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
            flash('Role updated.');
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === $selfId) {
            flash('You cannot disable your own account.', 'err');
        } else {
            $pdo->prepare("UPDATE users SET active = 1 - active WHERE id = ?")->execute([$id]);
            flash('Account status changed.');
        }
        header('Location: users.php');
        exit;
    }
}

$users = $pdo->query("SELECT u.*,
        (SELECT COUNT(*) FROM logs l WHERE l.user_id = u.id) AS entries
      FROM users u ORDER BY u.role DESC, u.username")->fetchAll();

page_header('Users', 'users.php');
?>

<div class="card">
  <div class="card-head">
    <h2>Add user</h2>
    <span class="filters-summary">admin: everything · personnel: input &amp; verify the gate log</span>
  </div>
  <div class="card-body">
    <form method="post" class="grid grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label class="field">Username
        <input type="text" name="username" required maxlength="64" placeholder="e.g. operator1">
      </label>
      <label class="field grow">Full name
        <input type="text" name="name" required maxlength="120" placeholder="e.g. Siti Aminah">
      </label>
      <label class="field">Role
        <select name="role">
          <option value="personnel">Personnel</option>
          <option value="admin">Admin</option>
        </select>
      </label>
      <label class="field">Password
        <input type="password" name="password" required minlength="6" placeholder="min 6 chars">
      </label>
      <div class="field" style="justify-content:flex-end">
        <button class="btn btn-primary" type="submit">Create user</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Accounts &amp; authority</h2>
    <span class="filters-summary"><?= count($users) ?> users</span>
  </div>
  <div class="card-body flush">
    <table>
      <thead>
        <tr>
          <th>User</th><th>Username</th><th>Role</th><th class="num">Entries</th>
          <th>Status</th><th>Created</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php $selfId = (int) (current_user()['id'] ?? 0); foreach ($users as $u): ?>
        <tr>
          <td>
            <span class="avatar" style="display:inline-grid;vertical-align:middle;margin-right:7px">
              <?= e(strtoupper(substr($u['name'], 0, 1))) ?></span>
            <?= e($u['name']) ?>
            <?= (int) $u['id'] === $selfId ? '<span class="badge badge-accent">you</span>' : '' ?>
          </td>
          <td class="mono"><?= e($u['username']) ?></td>
          <td>
            <?php if ((int) $u['id'] === $selfId): ?>
              <span class="badge badge-accent">admin</span>
            <?php else: ?>
              <form method="post" style="display:flex;gap:6px;align-items:center">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="role">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <select name="role" onchange="this.form.submit()" style="width:auto;padding:4px 6px;font-size:12px">
                  <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                  <option value="personnel" <?= $u['role'] === 'personnel' ? 'selected' : '' ?>>personnel</option>
                </select>
              </form>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int) $u['entries'] ?></td>
          <td>
            <?php if ($u['active']): ?>
              <span class="badge badge-in">active</span>
            <?php else: ?>
              <span class="badge badge-neutral">disabled</span>
            <?php endif; ?>
          </td>
          <td class="muted mono"><?= e(date('Y-m-d', strtotime($u['created_at']))) ?></td>
          <td class="row-actions">
            <?php if ((int) $u['id'] !== $selfId): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn <?= $u['active'] ? 'btn-danger' : 'btn-ghost' ?> btn-xs" type="submit">
                  <?= $u['active'] ? 'Disable' : 'Enable' ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
