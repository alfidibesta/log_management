<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function current_user(): ?array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    $id = (int) ($_SESSION['user']['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    // Re-read the account so a role change or a disabled login takes effect
    // on the next request instead of at the next sign-in.
    $st = db()->prepare('SELECT id, username, name, role FROM users WHERE id = ? AND active = 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        unset($_SESSION['user']);
        return null;
    }
    return $user = $row;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function is_leader(): bool
{
    return (current_user()['role'] ?? '') === 'leader';
}

/**
 * What each role may do. Every page and POST goes through can(), so the
 * authority lives in one place instead of scattered role checks.
 *
 * Admins can re-assign the leader/personnel capabilities on Users & roles;
 * the admin row always keeps full control so the site cannot be locked out.
 *
 * @return array<string, string[]>
 */
function role_capabilities(): array
{
    static $caps = null;
    if ($caps !== null) {
        return $caps;
    }

    $caps = [
        'admin'     => ['record', 'verify', 'approve', 'manage_data', 'manage_users'],
        'leader'    => ['record', 'verify', 'approve'],
        'personnel' => ['record', 'verify'],
    ];

    $saved = json_decode(setting_get('role_caps'), true);
    if (is_array($saved)) {
        $known = array_keys(capability_labels());
        foreach (['leader', 'personnel'] as $role) {
            if (is_array($saved[$role] ?? null)) {
                $caps[$role] = array_values(array_intersect($known, $saved[$role]));
            }
        }
    }

    return $caps;
}

/** Small key/value store used for the editable role permissions. */
function setting_get(string $key, string $default = ''): string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$key, $value]);
}

/** @return array<string, string> capability key => plain-English label */
function capability_labels(): array
{
    return [
        'record'       => 'Record gate entries',
        'verify'       => 'Verify the queue',
        'approve'      => 'Approve document take-outs',
        'manage_data'  => 'Data logs, categories & export',
        'manage_users' => 'Users & role assignment',
    ];
}

function role_label(string $role): string
{
    return ['admin' => 'Admin', 'leader' => 'Leader', 'personnel' => 'Personnel'][$role] ?? ucfirst($role);
}

function can(string $capability): bool
{
    $role = (string) (current_user()['role'] ?? '');
    return in_array($capability, role_capabilities()[$role] ?? [], true);
}

function can_approve_documents(): bool
{
    return can('approve');
}

/** Require a signed-in user whose role includes this capability. */
function require_can(string $capability): void
{
    require_login();
    if (!can($capability)) {
        http_response_code(403);
        echo '<p style="padding:32px;font:14px system-ui">403 — your role does not include “'
            . e(capability_labels()[$capability] ?? $capability) . '”. <a href="index.php">Back</a></p>';
        exit;
    }
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        echo '<p style="padding:32px;font:14px system-ui">403 — Admin authority required. <a href="index.php">Back</a></p>';
        exit;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Session expired, please reload the page.');
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Redirect back to the verification queue, keeping its filters. */
function verify_return_target(): string
{
    $qs = (string) ($_POST['ret'] ?? $_SESSION['verify_return'] ?? '');
    return 'verify.php' . ($qs !== '' ? '?' . $qs : '');
}

/** Redirect back to the log list, keeping its filters. */
function after_redirect_target(): string
{
    $qs = (string) ($_POST['ret'] ?? $_SESSION['log_return'] ?? '');
    if (strpos($qs, 'do=export') !== false) {
        $qs = '';
    }
    return 'log.php' . ($qs !== '' ? '?' . $qs : '');
}

function has_any_users(): bool
{
    return (int) db()->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
}

/** Who is crossing the gate: employees, visitors, contractor workers. */
function person_types(): array
{
    return ['personnel' => 'Employee', 'visitor' => 'Visitor', 'contractor' => 'Contractor'];
}

function person_type_label(?string $t): string
{
    return person_types()[$t ?? ''] ?? 'Employee';
}

/** Badge HTML; visitors and contractors are highlighted, employees stay quiet. */
function person_type_badge(?string $t): string
{
    $t   = in_array($t, ['visitor', 'contractor'], true) ? $t : 'personnel';
    $cls = $t === 'visitor' ? 'badge-accent' : ($t === 'contractor' ? 'badge-out' : 'badge-neutral');
    return '<span class="badge ' . $cls . '">' . e(person_type_label($t)) . '</span>';
}

/** Sidebar entries — the menu used to access the site. */
function nav_items(): array
{
    // one query feeds both counters shown in the menu
    $c = db()->query("SELECT (SELECT COUNT(*) FROM documents WHERE status = 'pending') AS docs,
                             (SELECT COUNT(*) FROM logs WHERE verified = 0) AS logs")->fetch();
    $waitingDocs   = (int) ($c['docs'] ?? 0);
    $waitingVerify = (int) ($c['logs'] ?? 0);

    $items = [
        ['index.php', 'Dashboard', '▦'],
        ['input.php', 'Record entry', '＋'],
        ['verify.php', $waitingVerify > 0 ? 'Verify queue (' . $waitingVerify . ')' : 'Verify queue', '✓'],
        ['documents.php', can_approve_documents() && $waitingDocs > 0
            ? 'Documents out (' . $waitingDocs . ')' : 'Documents out', '⇪'],
        ['visitors.php', 'Visitors', '⚑'],
        ['summary.php', 'Summary', '≡'],
    ];
    if (can('manage_data')) {
        $items[] = ['log.php', 'Data Log', '☰'];
        $items[] = ['doclog.php', 'Document Log', '⇩'];
        $items[] = ['categories.php', 'Categories', '▣'];
    }
    if (can('manage_users')) {
        $items[] = ['users.php', 'Users & roles', '◆'];
    }
    return $items;
}

/** The three-step guide: record → verify → approve. */
function flow_steps(string $current = ''): void
{
    $steps = [
        ['record', 'input.php', '1', 'Record', 'Log the crossing'],
        ['verify', 'verify.php', '2', 'Verify', 'Check IN ↔ OUT'],
        ['approve', 'documents.php?status=pending', '3', 'Approve', 'Clear documents out'],
    ];
    echo '<ol class="flow">';
    foreach ($steps as [$key, $href, $number, $label, $hint]) {
        echo '<li class="flow-step' . ($key === $current ? ' is-active' : '') . '">'
           . '<a href="' . e($href) . '"><span class="flow-n">' . $number . '</span>'
           . '<span class="flow-tx"><strong>' . e($label) . '</strong>'
           . '<small>' . e($hint) . '</small></span></a></li>';
    }
    echo '</ol>';
}

function page_header(string $title, string $active): void
{
    $u = current_user();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Access Factory Management</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><span class="brand-mark">AFM</span>
      <div><strong>Access Factory Management</strong><small>Belongings In / Out</small></div>
    </div>
    <nav class="menu">
      <?php foreach (nav_items() as [$href, $label, $icon]): ?>
        <a class="menu-item<?= $active === $href ? ' is-active' : '' ?>" href="<?= e($href) ?>">
          <span class="mi-ico"><?= $icon ?></span><?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <div class="who">
        <span class="avatar"><?= e(strtoupper(substr($u['name'] ?? '?', 0, 1))) ?></span>
        <span class="who-txt">
          <strong><?= e($u['name'] ?? '') ?></strong>
          <small class="role role-<?= e($u['role'] ?? '') ?>"><?= e($u['role'] ?? '') ?></small>
        </span>
      </div>
      <a class="link-quiet" href="logout.php">Sign out</a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <h1><?= e($title) ?></h1>
      <div class="topbar-right">
        <span class="pill"><?= date('D, d M Y') ?></span>
        <a class="btn btn-primary btn-sm" href="input.php">+ New entry</a>
      </div>
    </header>
    <div class="content">
      <?php foreach (take_flashes() as $f): ?>
        <div class="toast toast-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
<?php
}

function page_footer(): void
{
    ?>
    </div>
  </div>
</div>
<script src="assets/live.js" defer></script>
</body>
</html>
<?php
}
