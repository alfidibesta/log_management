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
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
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

/** Sidebar entries — the menu used to access the site. */
function nav_items(): array
{
    $items = [
        ['index.php', 'Dashboard', '▦'],
        ['input.php', 'Input (personnel)', '＋'],
        ['verify.php', 'Verify (personnel)', '✓'],
        ['summary.php', 'Summary', '≡'],
    ];
    if (is_admin()) {
        $items[] = ['log.php', 'Data Log', '☰'];
        $items[] = ['categories.php', 'Categories', '▣'];
        $items[] = ['users.php', 'Users', '◆'];
    }
    return $items;
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
<title><?= e($title) ?> · Factory Log</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><span class="brand-mark">FL</span>
      <div><strong>Factory Log</strong><small>Belongings In / Out</small></div>
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
