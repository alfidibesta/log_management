<?php
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/render.php';
require_login();

$pdo = db();

/* ---------- save: this page exists only for input ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $person     = trim((string) ($_POST['person'] ?? ''));
    $dept       = trim((string) ($_POST['dept'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $item       = trim((string) ($_POST['item'] ?? ''));
    $qty        = max(1, (int) ($_POST['qty'] ?? 1));
    $type       = ($_POST['type'] ?? '') === 'in' ? 'in' : 'out';
    $gate       = trim((string) ($_POST['gate'] ?? '')) ?: 'Main Gate';
    $note       = trim((string) ($_POST['note'] ?? ''));
    $when       = (string) ($_POST['logged_at'] ?? '');
    $ts         = $when !== '' ? strtotime($when) : time();
    $when       = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

    $err = [];
    if ($person === '')  { $err[] = 'Personnel name is required.'; }
    if ($categoryId <= 0) { $err[] = 'Please choose a category.'; }
    if ($item === '')    { $err[] = 'Describe the belongings / tools / documents.'; }

    if ($err) {
        flash(implode(' ', $err), 'err');
    } else {
        $st = $pdo->prepare("INSERT INTO logs
                (person, dept, category_id, item, qty, type, gate, note, logged_at, user_id)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$person, $dept, $categoryId, $item, $qty, $type, $gate, $note, $when,
                      current_user()['id'] ?? null]);
        flash(ucfirst($type) . " recorded for {$person} — pending verification.");
        header('Location: input.php');
        exit;
    }
    header('Location: input.php');
    exit;
}

/* ---------- form data ---------- */
$categories = $pdo->query("SELECT id, name FROM categories WHERE active = 1 ORDER BY name")->fetchAll();
$people     = $pdo->query("SELECT DISTINCT person FROM logs ORDER BY person LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
$items      = $pdo->query("SELECT DISTINCT item FROM logs ORDER BY item LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
$person     = trim((string) ($_GET['person'] ?? ''));
$open       = fetch_open_records($person);

page_header('Input Gate Pass', 'input.php');
?>
<div class="card">
  <div class="card-head">
    <h2>Record personnel passing the main gate</h2>
    <span class="filters-summary">IN = entering the factory · OUT = leaving</span>
  </div>
  <div class="card-body">
    <form method="post" class="grid" style="gap:14px">
      <?= csrf_field() ?>

      <div class="grid grid-form">
        <label class="field grow">Personnel name *
          <input type="text" name="person" list="people" required autofocus placeholder="Full name">
          <datalist id="people">
            <?php foreach ($people as $p): ?><option><?= e($p) ?></option><?php endforeach; ?>
          </datalist>
        </label>

        <label class="field">Department / company
          <input type="text" name="dept" list="depts" placeholder="e.g. Maintenance">
          <datalist id="depts">
            <option>Production</option><option>Maintenance</option><option>Warehouse</option>
            <option>Logistics</option><option>Quality Control</option><option>Visitor / Vendor</option>
          </datalist>
        </label>

        <label class="field">Category *
          <select name="category_id" required>
            <option value="">— select —</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">Direction *
          <span class="seg">
            <input type="radio" id="t-in" name="type" value="in" checked>
            <label for="t-in">IN</label>
            <input type="radio" id="t-out" name="type" value="out">
            <label for="t-out">OUT</label>
          </span>
        </label>
      </div>

      <div class="grid grid-form">
        <label class="field grow">Belongings / tools / documents *
          <input type="text" name="item" list="items" required placeholder="e.g. Cordless drill + battery">
          <datalist id="items">
            <?php foreach ($items as $i): ?><option><?= e($i) ?></option><?php endforeach; ?>
          </datalist>
        </label>

        <label class="field" style="max-width:110px">Qty
          <input type="number" name="qty" value="1" min="1" max="9999">
        </label>

        <label class="field">Gate
          <input type="text" name="gate" value="Main Gate">
        </label>

        <label class="field">Date &amp; time
          <input type="datetime-local" name="logged_at" value="<?= date('Y-m-d\TH:i') ?>">
        </label>
      </div>

      <div class="grid grid-form">
        <label class="field grow">Note <span class="muted">(optional)</span>
          <input type="text" name="note" placeholder="PO number, purpose, destination…">
        </label>
        <div class="field" style="justify-content:flex-end">
          <button class="btn btn-primary" type="submit">Save entry</button>
        </div>
      </div>

      <p class="hint">Every crossing is recorded as <strong>pending</strong> and must be checked against
        the opposite record on the <a href="verify.php">Verify</a> page when the person exits or returns.
        The panel below stays in sync with that queue.</p>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Open records for this person</h2>
    <span class="filters-summary">live — updates as records are verified elsewhere</span>
  </div>
  <div class="card-body flush" data-live="fragment.php?part=open" data-live-person>
    <?php render_open_records($open, $person); ?>
  </div>
</div>
<?php page_footer(); ?>
