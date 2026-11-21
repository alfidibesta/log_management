<?php
/**
 * Verify — the personnel workflow page.
 *
 * Every crossing is recorded on the Input page; here the record is checked
 * against the person's opposite-direction record when they exit or return.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/render.php';
require_once __DIR__ . '/../src/audit.php';
require_once __DIR__ . '/../src/exceptions.php';
require_can('view_verify');

$pdo = db();

/* ---------- verify / unverify ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verify', 'unverify'], true)) {
    require_can('verify');
    verify_csrf();
    $id     = (int) ($_POST['id'] ?? 0);
    // Snapshot first: the audit trail shows the values as they were.
    $before = audit_snapshot('log', $id);
    if (($_POST['action']) === 'verify') {
        $remark = mb_substr(trim((string) ($_POST['remark'] ?? '')), 0, 255);

        // A remark that says the belongings did not come back is not just a
        // note — it stands the crossing up as an open case in the register.
        $state   = exception_opens($remark) ? 'open' : 'none';
        $curSt   = $pdo->prepare('SELECT exception_state FROM logs WHERE id = ?');
        $curSt->execute([$id]);
        if ((string) $curSt->fetchColumn() === 'closed') {
            $state = 'closed'; // already settled; re-verifying must not reopen it
        }
        $sets = 'verified = 1, verified_by = ?, verified_at = NOW(), verify_remark = ?, exception_state = ?';
        $args = [current_user()['id'] ?? null, $remark, $state, $id];
        if ($state === 'none') {
            $sets .= ", exception_closed_at = NULL, exception_closed_by = NULL, exception_note = ''";
        }
        $pdo->prepare("UPDATE logs SET $sets WHERE id = ?")->execute($args);
        audit_record('log', $id, 'verify', $before ?? [], audit_snapshot('log', $id) ?? [], $remark);
        flash(t('Record verified') . ($remark !== '' ? ': “' . $remark . '”' : '') . '.');
    } else {
        $pdo->prepare("UPDATE logs
                          SET verified = 0, verified_by = NULL, verified_at = NULL, verify_remark = '',
                              exception_state = 'none', exception_closed_at = NULL,
                              exception_closed_by = NULL, exception_note = ''
                        WHERE id = ?")
            ->execute([$id]);
        audit_record('log', $id, 'unverify', $before ?? [], audit_snapshot('log', $id) ?? [],
            (string) ($_POST['reason'] ?? ''));
        flash(t('Verification cleared.'));
    }
    header('Location: ' . verify_return_target());
    exit;
}

/* ---------- delete (admin only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    require_admin();
    $id     = (int) ($_POST['id'] ?? 0);
    $before = audit_snapshot('log', $id);
    $pdo->prepare("DELETE FROM logs WHERE id = ?")->execute([$id]);
    audit_record('log', $id, 'delete', $before ?? [], [], 'Entry deleted from the verify queue');
    flash(t('Entry deleted.'));
    header('Location: ' . verify_return_target());
    exit;
}

/* ---------- exception register: close / reopen a case ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['close_exception', 'reopen_exception'], true)) {
    require_can('verify');
    verify_csrf();
    $id   = (int) ($_POST['id'] ?? 0);
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
    $ok   = ($_POST['action'] === 'close_exception')
        ? exception_close($id, $note)
        : exception_reopen($id, $note);
    flash($ok
        ? t(($_POST['action'] === 'close_exception')
            ? 'Exception closed — it no longer counts as an open case.'
            : 'Exception reopened.')
        : 'That case had already changed — reload the page.', $ok ? 'ok' : 'err');
    header('Location: ' . verify_return_target());
    exit;
}

/* ---------- queue ---------- */
$q = trim((string) ($_GET['q'] ?? ''));
$rows = fetch_verify_rows($q);

$openExceptions   = fetch_exceptions('open', $q, 200);
$closedExceptions = fetch_exceptions('closed', $q, 10);
$openExceptionCount = exception_open_count();

$byPerson = [];
foreach ($rows as $r) {
    $byPerson[$r['person']] = true;
}
$checkedToday = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE verified = 1 AND verified_at >= CURDATE()")->fetchColumn();
$pending = count($rows);

$_SESSION['verify_return'] = (string) ($_SERVER['QUERY_STRING'] ?? '');

page_header(t('Verify queue'), 'verify.php');
?>
<div class="card">
  <div class="card-body"><?php flow_steps('verify'); ?></div>
</div>
<div class="grid grid-4">
  <div class="stat"><div class="k"><?= te('To verify') ?></div><div class="v"><?= number_format($pending) ?></div><div class="s"><?= te('records awaiting check') ?></div></div>
  <div class="stat"><div class="k"><?= te('Personnel involved') ?></div><div class="v"><?= number_format(count($byPerson)) ?></div><div class="s"><?= te('with open records') ?></div></div>
  <div class="stat tone-in"><div class="k"><?= te('Verified today') ?></div><div class="v"><?= number_format($checkedToday) ?></div><div class="s"><?= te('checked at the gate') ?></div></div>
  <div class="stat"><div class="k"><?= te('Live') ?></div><div class="v" style="font-size:15px;line-height:34px">● <?= te('auto-update') ?></div><div class="s"><?= te('new entries appear here') ?></div></div>
</div>

<div class="card" id="exceptions">
  <div class="card-head">
    <h2><?= te('Exception register') ?></h2>
    <span class="badge badge-<?= $openExceptionCount > 0 ? 'danger' : 'neutral' ?>">
      <?= number_format($openExceptionCount) ?> <?= te('Open cases') ?></span>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <p class="hint" style="margin:0"><?= te('Belongings that went out and have not come back.') ?>
      <?php if (can('verify')): ?>Close a case once it is settled;
        the remark and the record itself are kept either way.<?php endif; ?>
      <?php if (can('view_data')): ?>
        · <a href="log.php?exceptions=any"><?= te('Data Log') ?></a>
      <?php endif; ?></p>
  </div>
  <div class="card-body flush">
    <?php render_exception_table($openExceptions, can('verify')); ?>
  </div>
  <?php if ($closedExceptions): ?>
    <div class="card-head"><h2 style="font-size:13px"><?= te('Closed') ?> · <?= te('Closed by') ?></h2></div>
    <div class="card-body flush">
      <?php render_exception_table($closedExceptions, can('verify')); ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <form method="get" class="toolbar">
    <label class="field grow"><?= te('Find person or item') ?>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= te('name, department, item') ?>">
    </label>
    <button class="btn btn-ghost" type="submit"><?= te('Filter') ?></button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="verify.php"><?= te('Reset') ?></a><?php endif; ?>
    <span class="spacer"></span>
    <span class="filters-summary">
      Check each record against the opposite crossing (IN ↔ OUT) when the person exits or returns.
      Optional <strong>remark</strong> covers e.g. “<?= te('Belongings not returned') ?>” — pick it from the dropdown or type your own.
    </span>
  </form>

  <div class="card-body flush" data-live="fragment.php?part=verify<?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>">
    <?php render_verify_list($rows, can('verify')); ?>
  </div>
</div>

<p class="hint">This page updates itself: entries recorded on the <a href="input.php">Input</a> page
   appear here automatically within a few seconds.</p>
<?php page_footer(); ?>
