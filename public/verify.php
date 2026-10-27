<?php
/**
 * Verify — the personnel workflow page.
 *
 * Every crossing is recorded on the Input page; here the record is checked
 * against the person's opposite-direction record when they exit or return.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/render.php';
require_login();

$pdo = db();

/* ---------- verify / unverify ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verify', 'unverify'], true)) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action']) === 'verify') {
        $remark = mb_substr(trim((string) ($_POST['remark'] ?? '')), 0, 255);
        $pdo->prepare("UPDATE logs SET verified = 1, verified_by = ?, verified_at = NOW(), verify_remark = ? WHERE id = ?")
            ->execute([current_user()['id'] ?? null, $remark, $id]);
        flash($remark !== ''
            ? 'Record verified with remark: “' . $remark . '”.'
            : 'Record checked against the crossing record and verified.');
    } else {
        $pdo->prepare("UPDATE logs SET verified = 0, verified_by = NULL, verified_at = NULL, verify_remark = '' WHERE id = ?")
            ->execute([$id]);
        flash('Verification cleared.');
    }
    header('Location: ' . verify_return_target());
    exit;
}

/* ---------- delete (admin only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    require_admin();
    $pdo->prepare("DELETE FROM logs WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
    flash('Entry deleted.');
    header('Location: ' . verify_return_target());
    exit;
}

/* ---------- queue ---------- */
$q = trim((string) ($_GET['q'] ?? ''));
$rows = fetch_verify_rows($q);

$byPerson = [];
foreach ($rows as $r) {
    $byPerson[$r['person']] = true;
}
$checkedToday = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE verified = 1 AND verified_at >= CURDATE()")->fetchColumn();
$pending = count($rows);

$_SESSION['verify_return'] = (string) ($_SERVER['QUERY_STRING'] ?? '');

page_header('Verify Personnel Belongings', 'verify.php');
?>
<div class="grid grid-4">
  <div class="stat"><div class="k">To verify</div><div class="v"><?= number_format($pending) ?></div><div class="s">records awaiting check</div></div>
  <div class="stat"><div class="k">Personnel involved</div><div class="v"><?= number_format(count($byPerson)) ?></div><div class="s">with open records</div></div>
  <div class="stat tone-in"><div class="k">Verified today</div><div class="v"><?= number_format($checkedToday) ?></div><div class="s">checked at the gate</div></div>
  <div class="stat"><div class="k">Live</div><div class="v" style="font-size:15px;line-height:34px">● auto-update</div><div class="s">new entries appear here</div></div>
</div>

<div class="card">
  <form method="get" class="toolbar">
    <label class="field grow">Find person or item
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="name, department, item">
    </label>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="verify.php">Reset</a><?php endif; ?>
    <span class="spacer"></span>
    <span class="filters-summary">
      Check each record against the opposite crossing (IN ↔ OUT) when the person exits or returns.
      Optional <strong>remark</strong> covers e.g. “Belongings not returned” — pick it from the dropdown or type your own.
    </span>
  </form>

  <div class="card-body flush" data-live="fragment.php?part=verify<?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>">
    <?php render_verify_list($rows, true); ?>
  </div>
</div>

<p class="hint">This page updates itself: entries recorded on the <a href="input.php">Input</a> page
   appear here automatically within a few seconds.</p>
<?php page_footer(); ?>
