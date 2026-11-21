<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// Desk language (English / Indonesian) and the ?lang= switch, before any page
// renders. The labels themselves live in src/lang.php.
require_once __DIR__ . '/lang.php';
ui_lang_handle_request();

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
 * Every permission the site knows, grouped the way the work happens: which
 * pages a role may open, which forms it may submit, which records it may
 * change, and lastly the administrative jobs.
 *
 * Pages and actions are deliberately separate. An officer can be given the
 * Documents out page to watch the queue without being allowed to record a
 * take-out; a supervisor can approve without ever opening the account screen.
 *
 * @return array<string, array{label:string, group:string}>
 */
function capability_catalog(): array
{
    return [
        // pages a role may open
        'view_dashboard'  => ['label' => 'Dashboard', 'group' => 'Pages'],
        'view_kiosk'      => ['label' => 'Gate kiosk (desk screen)', 'group' => 'Pages'],
        'view_visitors'   => ['label' => 'Visitors & contractors', 'group' => 'Pages'],
        'view_input'      => ['label' => 'Record entry', 'group' => 'Pages'],
        'view_verify'     => ['label' => 'Verify queue', 'group' => 'Pages'],
        'view_documents'  => ['label' => 'Documents out', 'group' => 'Pages'],
        'view_summary'    => ['label' => 'Summary', 'group' => 'Pages'],
        'view_data'       => ['label' => 'Data Log, Document Log & Categories', 'group' => 'Pages'],
        'view_users'      => ['label' => 'Users & roles', 'group' => 'Pages'],

        // forms a role may submit
        'record'          => ['label' => 'Record belongings IN / OUT', 'group' => 'Forms'],
        'record_visitor'  => ['label' => 'Check visitors in / out', 'group' => 'Forms'],
        'record_document' => ['label' => 'Record a document going out', 'group' => 'Forms'],

        // records a role may change
        'verify'          => ['label' => 'Verify records (and undo)', 'group' => 'Records'],
        'approve'         => ['label' => 'Approve / reject document take-outs', 'group' => 'Records'],

        // administration
        'manage_data'     => ['label' => 'Delete, import & export records; categories', 'group' => 'Administration'],
        'manage_users'    => ['label' => 'Create accounts, roles & permissions', 'group' => 'Administration'],
    ];
}

/** @return array<string, string> capability key => short label */
function capability_labels(): array
{
    $out = [];
    foreach (capability_catalog() as $key => $meta) {
        $out[$key] = $meta['label'];
    }
    return $out;
}

/** @return array<string, array<string, string>> capability key => label, by group */
function capability_groups(): array
{
    $groups = [];
    foreach (capability_catalog() as $key => $meta) {
        $groups[$meta['group']][$key] = $meta['label'];
    }
    return $groups;
}

/**
 * Capabilities saved by older versions. A saved set only carried the five
 * wide actions, so each one is expanded into the pages and forms it used to
 * unlock; otherwise an upgrade would silently lock people out of pages they
 * have always been able to open.
 *
 * @return array<string, string[]>
 */
function legacy_capabilities(): array
{
    return [
        'record'       => ['view_dashboard', 'view_kiosk', 'view_visitors', 'view_input',
                           'view_documents', 'view_summary', 'record', 'record_visitor',
                           'record_document'],
        'verify'       => ['view_verify', 'verify'],
        'approve'      => ['view_documents', 'approve'],
        'manage_data'  => ['view_data', 'manage_data'],
        'manage_users' => ['view_users', 'manage_users'],
    ];
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

    $caps = role_capability_defaults();

    $saved = json_decode(setting_get('role_caps'), true);
    if (is_array($saved)) {
        $known  = array_keys(capability_catalog());
        $legacy = legacy_capabilities();
        // A matrix saved by the current version is tagged `_v = 2`. Older
        // saves have no tag and also use the plain key "record", so the tag is
        // what decides whether that key means the form or the old wide grant.
        $tagged = (int) ($saved['_v'] ?? 0) >= 2;
        foreach (['leader', 'personnel'] as $role) {
            if (!is_array($saved[$role] ?? null)) {
                continue;
            }
            $list = [];
            foreach ($saved[$role] as $cap) {
                $cap = (string) $cap;
                if (!$tagged && isset($legacy[$cap])) {
                    $list = array_merge($list, $legacy[$cap]);
                } elseif (in_array($cap, $known, true)) {
                    $list[] = $cap;
                }
            }
            $caps[$role] = array_values(array_unique($list));
        }
    }

    return $caps;
}

/** @return array<string, string[]> the shipped capability set, before overrides */
function role_capability_defaults(): array
{
    $pages = ['view_dashboard', 'view_kiosk', 'view_visitors', 'view_input',
              'view_verify', 'view_documents', 'view_summary'];

    return [
        'admin'     => array_keys(capability_catalog()),
        'leader'    => array_merge($pages, ['record', 'record_visitor', 'record_document',
                                            'verify', 'approve']),
        'personnel' => array_merge($pages, ['record', 'record_visitor', 'record_document', 'verify']),
    ];
}

/**
 * Capability set as readable lines, for the audit trail's before/after view.
 *
 * @param array<string, string[]> $caps
 * @return array<string, string>
 */
function caps_snapshot(array $caps): array
{
    $out = [];
    foreach (['leader', 'personnel'] as $role) {
        $labels = [];
        foreach ($caps[$role] ?? [] as $cap) {
            $labels[] = capability_labels()[$cap] ?? $cap;
        }
        $out[role_label($role) . ' capabilities'] = $labels ? implode(', ', $labels) : 'none';
    }
    return $out;
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
        render_no_access($capability);
    }
}

/**
 * The page a role lands on when it asks for something it does not hold: a
 * plain 403 plus every page this account may open instead, so nobody is stuck
 * on a dead end. Called by require_can() and the per-form guards.
 */
function render_no_access(string $capability): void
{
    http_response_code(403);
    $label = capability_labels()[$capability] ?? $capability;
    $pages = [
        'view_dashboard' => 'index.php',
        'view_kiosk'     => 'kiosk.php',
        'view_visitors'  => 'visitors.php',
        'view_input'     => 'input.php',
        'view_verify'    => 'verify.php',
        'view_documents' => 'documents.php',
        'view_summary'   => 'summary.php',
        'view_data'      => 'log.php',
        'view_users'     => 'users.php',
    ];

    page_header('No access', '');
    echo '<div class="card"><div class="card-head"><h2>403 — your role does not include “'
       . e($label) . '”</h2></div><div class="card-body">'
       . '<p class="muted" style="margin:0 0 10px">This is a permission, not an error in the page. '
       . 'An admin can grant it on <strong>Users &amp; roles</strong>; until then these are the pages '
       . 'your account can open:</p>';

    $open = [];
    foreach ($pages as $cap => $href) {
        if (can($cap)) {
            $open[] = '<a class="btn btn-ghost btn-sm" href="' . e($href) . '">'
                    . e(capability_labels()[$cap]) . '</a>';
        }
    }
    echo '<div class="row-actions" style="justify-content:flex-start;gap:8px">'
       . ($open ? implode('', $open) : '<span class="muted">No pages at all — ask an admin to assign a role.</span>')
       . '</div></div></div>';
    page_footer();
    exit;
}

/** True while the gate kiosk is driving the screen (its chrome, no sidebar). */
function kiosk_mode(): bool
{
    return !empty($_SESSION['kiosk']);
}

function kiosk_enter(): void
{
    $_SESSION['kiosk'] = 1;
}

function kiosk_exit(): void
{
    unset($_SESSION['kiosk']);
}

/**
 * The PIN that unlocks leaving the desk screen. The kiosk outlives the person
 * standing at it, so the way back into the admin menu is not a plain click:
 * set KIOSK_EXIT_PIN in config.php (or the environment) for the site.
 */
function kiosk_exit_pin(): string
{
    return defined('KIOSK_EXIT_PIN') ? (string) KIOSK_EXIT_PIN : '4321';
}

function kiosk_exit_allowed(string $pin): bool
{
    $expected = kiosk_exit_pin();
    return $expected !== '' && $pin !== '' && hash_equals($expected, $pin);
}

/**
 * Where a refused exit goes back to: a kiosk page, never a posted URL, so the
 * form cannot be turned into an open redirect.
 */
function kiosk_exit_return(): string
{
    $ret = basename((string) ($_POST['ret'] ?? ''));
    return in_array($ret, ['kiosk.php', 'visitors.php', 'input.php', 'documents.php'], true)
        ? $ret
        : 'kiosk.php';
}

/**
 * The kiosk top bar's two controls: fill the wall display, and the PIN-guarded
 * way out. Rendered by both kiosk bars so the desk screen behaves the same
 * wherever the shift moves.
 */
function kiosk_bar_actions(): void
{
    $here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'kiosk.php'));
    ?>
    <button class="btn btn-ghost btn-sm" type="button" data-fullscreen
            data-full="<?= te('Full screen') ?>" data-exit-full="<?= te('Leave full screen') ?>"><?= te('Full screen') ?></button>
    <form class="kiosk-exit" method="post" action="kiosk_exit.php">
      <?= csrf_field() ?>
      <input type="hidden" name="ret" value="<?= e($here) ?>">
      <input class="pin" type="password" name="pin" inputmode="numeric" maxlength="12"
             autocomplete="off" placeholder="<?= te('PIN') ?>" aria-label="<?= te('Exit kiosk PIN') ?>">
      <button class="btn btn-ghost btn-sm" type="submit"><?= te('Exit kiosk') ?></button>
    </form>
    <?php
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

/**
 * The wall clock every gate screen runs on: the database's own clock, the same
 * one that stamps logged_at, time_in and the verification times. PHP's clock
 * and the browser's can sit in other timezones, and a gate record must never
 * disagree with the lists that show it.
 *
 * @return array{wall:string, epoch:int}
 */
function desk_clock(): array
{
    $wall  = (string) db()->query('SELECT NOW()')->fetchColumn();
    $epoch = strtotime($wall . ' UTC');
    return ['wall' => $wall, 'epoch' => $epoch !== false ? $epoch : time()];
}

/** "Now" in the database's clock — what a form without a time field stores. */
function desk_now(): string
{
    return desk_clock()['wall'];
}

/**
 * The automatic date & time box on a form. It prints the database's time and
 * carries its epoch so assets/clock.js keeps the seconds ticking, exactly like
 * the kiosk header, instead of freezing at page load.
 */
function auto_clock(): void
{
    $clock = desk_clock();
    ?>
    <div class="field-auto auto-clock" data-server="<?= (int) $clock['epoch'] ?>">
      <span class="clock-face"><?= e(gmdate('d M Y, H:i:s', $clock['epoch'])) ?></span>
      <span class="muted">· <?= te('automatic') ?></span>
    </div>
    <?php
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

    $items = [];
    if (can('view_dashboard')) { $items[] = ['index.php', t('Dashboard'), '▦']; }
    if (can('view_kiosk'))     { $items[] = ['kiosk.php', t('Gate kiosk'), '▤']; }
    if (can('view_input'))     { $items[] = ['input.php', t('Record entry'), '＋']; }
    if (can('view_verify')) {
        $items[] = ['verify.php', t('Verify queue') . ($waitingVerify > 0 ? ' (' . $waitingVerify . ')' : ''), '✓'];
    }
    if (can('view_documents')) {
        $items[] = ['documents.php', t('Documents out')
            . (can('approve') && $waitingDocs > 0 ? ' (' . $waitingDocs . ')' : ''), '⇪'];
    }
    if (can('view_visitors')) { $items[] = ['visitors.php', t('Visitors'), '⚑']; }
    if (can('view_summary'))  { $items[] = ['summary.php', t('Summary'), '≡']; }
    $items[] = ['help.php', t('Help'), '?'];
    if (can('view_data')) {
        $items[] = ['log.php', t('Data Log'), '☰'];
        $items[] = ['doclog.php', t('Document Log'), '⇩'];
        $items[] = ['categories.php', t('Categories'), '▣'];
    }
    if (can('view_users')) {
        $items[] = ['users.php', t('Users & roles'), '◆'];
    }
    return $items;
}

/**
 * The two jobs at the gate, as paths rather than numbered steps: an officer
 * starts on whichever page the person in front of them needs, and a document
 * never passes through the belongings queue.
 *
 * Belongings: record the crossing → verify it against the opposite one.
 * Documents out: record the take-out (scan or PDF) → a leader approves it.
 *
 * $current is one of record, verify, document or approve; it only decides
 * which path and stop is highlighted.
 */
function flow_steps(string $current = ''): void
{
    $paths = [
        [
            'title' => t('Belongings IN / OUT'),
            'steps' => [
                ['record', 'input.php', t('Record'), t('Log what is carried')],
                ['verify', 'verify.php', t('Verify'), t('Check IN ↔ OUT')],
            ],
        ],
        [
            'title' => t('Documents out'),
            'steps' => [
                ['document', 'documents.php', t('Record'), t('Title, scan or PDF')],
                ['approve', 'documents.php?status=pending', t('Approve'), t('A leader clears it')],
            ],
        ],
    ];

    echo '<div class="flow">';
    foreach ($paths as $path) {
        $keys     = array_column($path['steps'], 0);
        $isHere   = in_array($current, $keys, true);
        echo '<div class="flow-set' . ($isHere ? ' is-active' : '') . '">'
           . '<div class="flow-set-head">' . e($path['title']) . '</div>'
           . '<div class="flow-path">';
        foreach ($path['steps'] as $i => [$key, $href, $label, $hint]) {
            if ($i > 0) {
                echo '<span class="flow-arrow" aria-hidden="true">→</span>';
            }
            echo '<a class="flow-step' . ($key === $current ? ' is-active' : '') . '" href="' . e($href) . '">'
               . '<span class="flow-tx"><strong>' . e($label) . '</strong>'
               . '<small>' . e($hint) . '</small></span></a>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}

function page_header(string $title, string $active): void
{
    if (kiosk_mode()) {
        kiosk_page_header($title, $active);
        return;
    }

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
      <a class="link-quiet" href="logout.php"><?= te('Sign out') ?></a>
      <?php lang_switcher(); ?>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <h1><?= e($title) ?></h1>
      <div class="topbar-right">
        <span class="pill"><?= date('D, d M Y') ?></span>
        <?php if (can('view_input') && can('record')): ?>
          <a class="btn btn-primary btn-sm" href="input.php">+ <?= te('New entry') ?></a>
        <?php endif; ?>
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
    if (kiosk_mode()) {
        ?>
        </div>
        <footer class="kiosk-foot">
          <span>Gate kiosk · <?= e(current_user()['name'] ?? '') ?></span>
          <span>Access Factory Management — gate log</span>
        </footer>
        <script src="assets/live.js" defer></script>
        <script src="assets/clock.js" defer></script>
        <script src="assets/kiosk.js" defer></script>
        </body>
        </html>
        <?php
        return;
    }
    ?>
    </div>
  </div>
</div>
<script src="assets/live.js" defer></script>
<script src="assets/clock.js" defer></script>
</body>
</html>
<?php
}

/**
 * The kiosk's own chrome for the pages reachable from the desk screen.
 *
 * No sidebar: only the kiosk, the three gate pages and the way out, so the
 * wall screen can never wander into the administration of the site.
 */
function kiosk_page_header(string $title, string $active): void
{
    $u = current_user();

    $links = [];
    if (can('view_kiosk'))     { $links[] = ['kiosk.php', t('Gate desk')]; }
    if (can('view_visitors'))  { $links[] = ['visitors.php', t('Visitors')]; }
    if (can('view_input') && can('record')) { $links[] = ['input.php', '+ ' . t('Record entry')]; }
    if (can('view_documents')) { $links[] = ['documents.php', t('Documents out')]; }
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Gate kiosk · Access Factory Management</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="kiosk kiosk-page">
<header class="kiosk-top">
  <div class="kiosk-brand">
    <span class="brand-mark">AFM</span>
    <div>
      <strong><?= e($title) ?></strong>
      <small><?= e($u['name'] ?? '') ?> · gate kiosk · <?= date('d M Y') ?></small>
    </div>
  </div>
  <nav class="kiosk-nav">
    <?php foreach ($links as [$href, $label]): ?>
      <a class="btn <?= $active === $href ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
         href="<?= e($href) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php lang_switcher('lang-switch-kiosk'); ?>
    <?php kiosk_bar_actions(); ?>
  </nav>
</header>
<div class="kiosk-body kiosk-page-body">
  <?php foreach (take_flashes() as $f): ?>
    <div class="toast toast-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
<?php
}
