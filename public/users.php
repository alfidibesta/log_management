<?php
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/audit.php';
require_can('manage_users');

$pdo = db();

/** Keep at least one active admin so the site can always be managed. */
function active_admin_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $selfId = (int) (current_user()['id'] ?? 0);

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $name     = trim((string) ($_POST['name'] ?? ''));
        $role     = in_array($_POST['role'] ?? '', ['admin', 'personnel', 'leader'], true) ? (string) $_POST['role'] : 'personnel';
        $pass     = (string) ($_POST['password'] ?? '');

        if ($username === '' || $name === '') {
            flash('Username and name are required.', 'err');
        } elseif (strlen($pass) < 6) {
            flash('Password must be at least 6 characters.', 'err');
        } else {
            try {
                $st = $pdo->prepare("INSERT INTO users (username, name, password_hash, role) VALUES (?,?,?,?)");
                $st->execute([$username, $name, password_hash($pass, PASSWORD_DEFAULT), $role]);
                $newId = (int) $pdo->lastInsertId();
                audit_record('user', $newId, 'user_create', [], audit_snapshot('user', $newId) ?? [],
                    '', 'User ' . $username);
                flash("User {$username} created.");
            } catch (PDOException $e) {
                flash('That username is already taken.', 'err');
            }
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'permissions') {
        $known = array_keys(capability_labels());
        $caps  = ['leader' => [], 'personnel' => []];
        foreach (['leader', 'personnel'] as $role) {
            foreach ((array) ($_POST['caps'][$role] ?? []) as $cap) {
                if (in_array($cap, $known, true)) {
                    $caps[$role][] = $cap;
                }
            }
        }
        $before = caps_snapshot(role_capabilities());
        setting_set('role_caps', json_encode($caps));
        audit_record('role_caps', null, 'permissions', $before, caps_snapshot($caps),
            (string) ($_POST['reason'] ?? ''), 'Role capabilities');
        flash('Role permissions saved — they apply on the next request.');
        header('Location: users.php');
        exit;
    }

    if ($action === 'permissions_reset') {
        $before = caps_snapshot(role_capabilities());
        $pdo->prepare("DELETE FROM settings WHERE k = 'role_caps'")->execute();
        audit_record('role_caps', null, 'permissions_reset', $before,
            caps_snapshot(role_capability_defaults()), (string) ($_POST['reason'] ?? ''),
            'Role capabilities');
        flash('Role permissions restored to the defaults.');
        header('Location: users.php');
        exit;
    }

    if ($action === 'role') {
        $id   = (int) ($_POST['id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['admin', 'personnel', 'leader'], true) ? (string) $_POST['role'] : 'personnel';
        $st   = $pdo->prepare("SELECT role, active FROM users WHERE id = ?");
        $st->execute([$id]);
        $target = $st->fetch();

        if ($id === $selfId) {
            flash('You cannot change your own role.', 'err');
        } elseif (!$target) {
            flash('That account no longer exists.', 'err');
        } elseif ($target['role'] === 'admin' && (int) $target['active'] === 1
                  && $role !== 'admin' && active_admin_count($pdo) <= 1) {
            flash('This is the last active admin — make another account an admin first.', 'err');
        } else {
            $before = audit_snapshot('user', $id);
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
            audit_record('user', $id, 'role', $before ?? [], audit_snapshot('user', $id) ?? []);
            flash('Role updated to ' . role_label($role) . '.');
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT role, active FROM users WHERE id = ?");
        $st->execute([$id]);
        $target = $st->fetch();

        if ($id === $selfId) {
            flash('You cannot disable your own account.', 'err');
        } elseif (!$target) {
            flash('That account no longer exists.', 'err');
        } elseif ($target['role'] === 'admin' && (int) $target['active'] === 1 && active_admin_count($pdo) <= 1) {
            flash('This is the last active admin — make another account an admin first.', 'err');
        } else {
            $before = audit_snapshot('user', $id);
            $pdo->prepare("UPDATE users SET active = 1 - active WHERE id = ?")->execute([$id]);
            audit_record('user', $id, 'toggle', $before ?? [], audit_snapshot('user', $id) ?? []);
            flash('Account status changed.');
        }
        header('Location: users.php');
        exit;
    }
}

$users = $pdo->query("SELECT u.*,
        (SELECT COUNT(*) FROM logs l WHERE l.user_id = u.id) AS entries
      FROM users u ORDER BY u.role DESC, u.username")->fetchAll();

$recentAudit = audit_feed(['user', 'role_caps'], 30);

page_header('Users & roles', 'users.php');
?>

<div class="card">
  <div class="card-head">
    <h2>Add user</h2>
    <span class="filters-summary">admin: everything · leader: approves documents · personnel: input &amp; verify the gate log</span>
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
          <option value="leader">Leader — approves documents</option>
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
    <h2>Roles &amp; access</h2>
    <span class="filters-summary">What each role may do — enforced on every page and POST</span>
  </div>
  <div class="card-body flush">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="permissions">
      <table class="roles">
        <thead>
          <tr><th>Capability</th><th>Admin</th><th>Leader</th><th>Personnel</th></tr>
        </thead>
        <tbody>
        <?php foreach (capability_labels() as $cap => $label): ?>
          <tr>
            <td><?= e($label) ?></td>
            <td><span class="badge badge-in">✓</span></td>
            <?php foreach (['leader', 'personnel'] as $role): ?>
              <td>
                <input type="checkbox" name="caps[<?= $role ?>][]" value="<?= e($cap) ?>"
                       aria-label="<?= e($label) ?> for <?= e(role_label($role)) ?>"
                       <?= in_array($cap, role_capabilities()[$role], true) ? 'checked' : '' ?>>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="perm-actions">
        <span class="filters-summary" style="flex:1;min-width:200px">
          Admin always keeps full control, so these two roles can be changed freely.
          The last active admin still cannot be demoted or disabled.
        </span>
        <label class="field" style="flex:1;min-width:200px">
          Reason <span class="muted">(optional — kept in the audit trail)</span>
          <input type="text" name="reason" maxlength="255" placeholder="e.g. Gate officers now verify documents">
        </label>
        <button class="btn btn-ghost btn-sm" type="submit" name="action" value="permissions_reset"
                onclick="return confirm('Restore the default permissions for leader and personnel?')">
          Restore defaults
        </button>
        <button class="btn btn-primary btn-sm" type="submit">Save permissions</button>
      </div>
    </form>
    <p class="muted" style="margin:0;padding:10px 14px;font-size:12px">
      Changes apply on the next request for everyone, including signed-in users.
      New accounts are created below.
    </p>
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
                  <option value="leader" <?= $u['role'] === 'leader' ? 'selected' : '' ?>>leader</option>
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

<div class="card">
  <div class="card-head">
    <h2>Audit trail — accounts &amp; capabilities</h2>
    <span class="filters-summary">Every account and role change, with who made it and what changed</span>
  </div>
  <div class="card-body flush">
    <?php render_audit_trail($recentAudit, 'No account or capability changes yet.', true); ?>
  </div>
</div>
<?php page_footer(); ?>
