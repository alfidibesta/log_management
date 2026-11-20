<?php
/**
 * Visitors & contractors — the register at the gate.
 *
 * Reception checks a visitor or contractor worker in (company, ID card,
 * host, badge). The gate officer then records their belongings on Input and
 * their documents on Documents out using the same name, and reception checks
 * them out here when they leave.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/visitors.php';
require_once __DIR__ . '/../src/audit.php';
require_can('view_visitors');

$pdo = db();

/* ---------- check in / check out / delete ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_can('record_visitor');
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'checkin');
    $uid    = (int) (current_user()['id'] ?? 0);

    if ($action === 'checkin') {
        $name    = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $type    = ($_POST['person_type'] ?? '') === 'contractor' ? 'contractor' : 'visitor';
        $company = mb_substr(trim((string) ($_POST['company'] ?? '')), 0, 120);
        $idNo    = mb_substr(trim((string) ($_POST['id_no'] ?? '')), 0, 60);
        $phone   = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40);
        $host    = mb_substr(trim((string) ($_POST['host'] ?? '')), 0, 120);
        $purpose = mb_substr(trim((string) ($_POST['purpose'] ?? '')), 0, 180);
        $badge   = mb_substr(trim((string) ($_POST['badge_no'] ?? '')), 0, 40);
        $note    = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        /* Stamped by the server — the form has no editable time field. */
        $when    = date('Y-m-d H:i:s');

        $err = [];
        if ($name === '')    { $err[] = 'Visitor name is required.'; }
        if ($company === '') { $err[] = 'Company / origin is required.'; }

        if ($name !== '') {
            $dup = $pdo->prepare("SELECT time_in FROM visitors WHERE time_out IS NULL AND name = ? LIMIT 1");
            $dup->execute([$name]);
            $open = $dup->fetch();
            if ($open) {
                $err[] = $name . ' is already on site (since '
                       . date('d M H:i', strtotime((string) $open['time_in']))
                       . ') — check them out first.';
            }
        }

        if ($err) {
            flash(implode(' ', $err), 'err');
        } else {
            $st = $pdo->prepare("INSERT INTO visitors
                    (name, person_type, company, id_no, phone, host, purpose, badge_no, time_in, note, user_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$name, $type, $company, $idNo, $phone, $host, $purpose, $badge, $when, $note, $uid ?: null]);
            $newId = (int) $pdo->lastInsertId();
            audit_record('visitor', $newId, 'checkin', [], audit_snapshot('visitor', $newId) ?? [], $purpose);
            flash(person_type_label($type) . ' ' . $name . ' checked in at '
                . date('H:i', strtotime($when)) . ' — log their belongings and documents while they are on site.');
        }
        header('Location: ' . visitor_return_target());
        exit;
    }

    if ($action === 'checkout') {
        $id     = (int) ($_POST['id'] ?? 0);
        $before = audit_snapshot('visitor', $id);
        $pdo->prepare("UPDATE visitors SET time_out = NOW() WHERE id = ? AND time_out IS NULL")
            ->execute([$id]);
        audit_record('visitor', $id, 'checkout', $before ?? [], audit_snapshot('visitor', $id) ?? []);
        flash('Visitor checked out.');
        header('Location: ' . visitor_return_target());
        exit;
    }

    if ($action === 'back') {
        $id     = (int) ($_POST['id'] ?? 0);
        $before = audit_snapshot('visitor', $id);
        $pdo->prepare("UPDATE visitors SET time_out = NULL WHERE id = ?")->execute([$id]);
        audit_record('visitor', $id, 'back', $before ?? [], audit_snapshot('visitor', $id) ?? []);
        flash('Check-out undone — the visitor is on site again.');
        header('Location: ' . visitor_return_target());
        exit;
    }

    if ($action === 'delete') {
        require_admin();
        $id     = (int) ($_POST['id'] ?? 0);
        $before = audit_snapshot('visitor', $id);
        $pdo->prepare("DELETE FROM visitors WHERE id = ?")->execute([$id]);
        audit_record('visitor', $id, 'delete', $before ?? [], [], 'Visitor record deleted');
        flash('Visitor record deleted.');
        header('Location: ' . visitor_return_target());
        exit;
    }
}

/* ---------- list + filters ---------- */
$status = in_array($_GET['status'] ?? '', ['in', 'out', 'all'], true) ? (string) $_GET['status'] : 'in';
$type   = in_array($_GET['person_type'] ?? '', ['visitor', 'contractor'], true) ? (string) $_GET['person_type'] : '';
$q      = trim((string) ($_GET['q'] ?? ''));
$rows   = fetch_visitors($status === 'all' ? '' : $status, $q, $type);
$ret    = (string) ($_SERVER['QUERY_STRING'] ?? '');

$_SESSION['visitor_return'] = $ret;

// one pass over the register for all four headline numbers
$c = $pdo->query("SELECT
        COALESCE(SUM(time_out IS NULL), 0) AS onsite,
        COALESCE(SUM(time_out IS NULL AND person_type = 'contractor'), 0) AS contractors,
        COALESCE(SUM(DATE(time_in) = CURDATE()), 0) AS in_today,
        COALESCE(SUM(time_out IS NOT NULL AND DATE(time_out) = CURDATE()), 0) AS out_today
      FROM visitors")->fetch();
$onsite      = (int) $c['onsite'];
$contractors = (int) $c['contractors'];
$inToday     = (int) $c['in_today'];
$outToday    = (int) $c['out_today'];

page_header('Visitors & Contractors', 'visitors.php');
?>
<div class="grid grid-4">
  <div class="stat tone-out">
    <div class="k">On site now</div>
    <div class="v"><?= number_format($onsite) ?></div>
    <div class="s">visitors + contractors</div>
  </div>
  <div class="stat">
    <div class="k">Contractors on site</div>
    <div class="v"><?= number_format($contractors) ?></div>
    <div class="s">working crews</div>
  </div>
  <div class="stat tone-in">
    <div class="k">Checked in today</div>
    <div class="v"><?= number_format($inToday) ?></div>
    <div class="s">badges issued</div>
  </div>
  <div class="stat">
    <div class="k">Checked out today</div>
    <div class="v"><?= number_format($outToday) ?></div>
    <div class="s">left the site</div>
  </div>
</div>

<?php if (can('record_visitor')): ?>
<div class="card">
  <div class="card-head">
    <h2>Check in a visitor or contractor</h2>
    <span class="filters-summary">One row per visit — check out when they leave.</span>
  </div>
  <div class="card-body">
    <form method="post" class="grid" style="gap:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="checkin">
      <input type="hidden" name="ret" value="<?= e($ret) ?>">

      <div class="grid grid-form">
        <label class="field grow">Visitor name *
          <input type="text" name="name" required autofocus maxlength="120" placeholder="Full name as on the ID card">
        </label>
        <label class="field">Type
          <select name="person_type">
            <option value="visitor">Visitor</option>
            <option value="contractor">Contractor worker</option>
          </select>
        </label>
        <label class="field grow">Company / origin *
          <input type="text" name="company" required maxlength="120" placeholder="e.g. PT Sinar Teknik">
        </label>
        <label class="field">Badge no.
          <input type="text" name="badge_no" maxlength="40" placeholder="e.g. V-014">
        </label>
      </div>

      <div class="grid grid-form">
        <label class="field">ID card no. (KTP / SIM)
          <input type="text" name="id_no" maxlength="60" placeholder="e.g. 3174…">
        </label>
        <label class="field">Phone
          <input type="text" name="phone" maxlength="40" placeholder="e.g. 0812…">
        </label>
        <label class="field grow">Host / person visited
          <input type="text" name="host" maxlength="120" placeholder="e.g. Andi Wijaya (Maintenance)">
        </label>
        <label class="field">Time in
          <div class="field-auto"><?= date('d M Y, H:i') ?> <span class="muted">· automatic</span></div>
        </label>
      </div>

      <div class="grid grid-form">
        <label class="field grow">Purpose of visit
          <input type="text" name="purpose" maxlength="180" placeholder="e.g. Service chiller unit">
        </label>
        <label class="field grow">Note <span class="muted">(optional)</span>
          <input type="text" name="note" maxlength="255" placeholder="Anything the gate should know">
        </label>
        <div class="field" style="justify-content:flex-end">
          <button class="btn btn-primary" type="submit">Check in</button>
        </div>
      </div>

      <p class="hint">
        After check-in, use the <strong>Belongings</strong> / <strong>Documents</strong> links on the row
        to record what they bring in or take out. Those records are tagged
        <span class="badge badge-accent">Visitor</span> /
        <span class="badge badge-out">Contractor</span>, so the Summary separates them from employees.
      </p>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-body">
    <p class="muted" style="margin:0">Your role can see the register but not check visitors in or out. '
      Ask an admin for <strong>Check visitors in / out</strong> if the desk needs it.</p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <form method="get" class="toolbar">
    <label class="field">Status
      <select name="status" onchange="this.form.submit()">
        <option value="in"  <?= $status === 'in' ? 'selected' : '' ?>>On site</option>
        <option value="out" <?= $status === 'out' ? 'selected' : '' ?>>Checked out</option>
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
      </select>
    </label>
    <label class="field">Type
      <select name="person_type" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="visitor" <?= $type === 'visitor' ? 'selected' : '' ?>>Visitors</option>
        <option value="contractor" <?= $type === 'contractor' ? 'selected' : '' ?>>Contractors</option>
      </select>
    </label>
    <label class="field grow">Search
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="name, company, host, badge, ID card">
    </label>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($q !== '' || $type !== '' || $status !== 'in'): ?>
      <a class="btn btn-ghost" href="visitors.php">Reset</a>
    <?php endif; ?>
    <span class="spacer"></span>
    <span class="filters-summary"><?= count($rows) ?> shown</span>
  </form>

  <div class="card-body flush">
    <?php render_visitor_list($rows, $ret, can('record_visitor')); ?>
  </div>
</div>
<?php page_footer(); ?>
