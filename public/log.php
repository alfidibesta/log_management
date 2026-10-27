<?php
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/render.php';
require_once __DIR__ . '/../src/excel.php';
require_admin(); // the Data Log (incl. Excel import/export) is admin territory

$pdo = db();

// remember filters so verify/delete redirects come back to the same view
$_SESSION['log_return'] = (string) ($_SERVER['QUERY_STRING'] ?? '');

$IMPORT_HEADERS = ['Date', 'Time', 'Person', 'Department', 'Category', 'Item', 'Qty', 'Direction', 'Note', 'Gate', 'Remark'];

/* ---------- import an Excel file ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    verify_csrf();
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        flash('Upload failed — please choose an .xlsx file.', 'err');
        header('Location: log.php');
        exit;
    }
    if (!class_exists('ZipArchive')) {
        flash('This PHP build has no zip extension, required to read .xlsx files.', 'err');
        header('Location: log.php');
        exit;
    }

    $parsed = xlsx_read($file['tmp_name']);
    if ($parsed['errors']) {
        flash($parsed['errors'][0], 'err');
        header('Location: log.php');
        exit;
    }

    $cols = array_map('strtolower', array_values($parsed['headers']));
    $need = ['date', 'person', 'category', 'item', 'direction'];
    foreach ($need as $n) {
        if (!in_array($n, $cols, true)) {
            flash('Missing required column: ' . ucfirst($n) . '. Expected: ' . implode(', ', $IMPORT_HEADERS), 'err');
            header('Location: log.php');
            exit;
        }
    }

    $catIds = [];
    foreach ($pdo->query("SELECT id, name FROM categories")->fetchAll() as $c) {
        $catIds[strtolower($c['name'])] = (int) $c['id'];
    }

    $imported = 0;
    $errors   = [];
    $ins      = $pdo->prepare("INSERT INTO logs
        (person, dept, category_id, item, qty, type, gate, note, logged_at, user_id, verified, verified_by, verified_at, verify_remark)
        VALUES (?,?,?,?,?,?,?,?,?,?,1,?,NOW(),?)");
    $uid = current_user()['id'] ?? null;

    $pdo->beginTransaction();
    foreach ($parsed['rows'] as $i => $r) {
        $line = $i + 2; // spreadsheet row number
        $get  = static function (string $key) use ($r) {
            foreach ($r as $k => $v) {
                if (strtolower(trim((string) $k)) === $key) {
                    return is_string($v) ? trim($v) : $v;
                }
            }
            return '';
        };

        $person = (string) $get('person');
        $item   = (string) $get('item');
        $catName = (string) $get('category');
        $dir    = strtolower((string) $get('direction'));
        $date   = xlsx_to_datetime($get('date'));
        $time   = (string) $get('time');

        if ($date && $time && !is_numeric($get('date'))) {
            $t = strtotime($time);
            if ($t) {
                $date = date('Y-m-d ') . date('H:i:s', $t);
            }
        }

        if ($person === '' || $item === '') {
            $errors[] = "Row {$line}: person / item is empty.";
            continue;
        }
        if ($dir === 'in' || $dir === 'masuk')  { $dir = 'in'; }
        elseif ($dir === 'out' || $dir === 'keluar') { $dir = 'out'; }
        else {
            $errors[] = "Row {$line}: direction must be IN or OUT.";
            continue;
        }
        if ($date === null) {
            $errors[] = "Row {$line}: unreadable date.";
            continue;
        }
        $catId = $catIds[strtolower($catName)] ?? 0;
        if ($catId <= 0) {
            $errors[] = "Row {$line}: unknown category \"{$catName}\".";
            continue;
        }

        $ins->execute([
            $person,
            (string) $get('department'),
            $catId,
            $item,
            max(1, (int) ($get('qty') ?: 1)),
            $dir,
            (string) ($get('gate') ?: 'Main Gate'),
            (string) $get('note'),
            $date,
            $uid,
            $uid,
            mb_substr(trim((string) $get('remark')), 0, 255),
        ]);
        $imported++;
    }
    $pdo->commit();

    $msg = "Imported {$imported} row" . ($imported === 1 ? '' : 's') . '.';
    if ($errors) {
        $shown = array_slice($errors, 0, 4);
        $msg  .= ' Skipped ' . count($errors) . ': ' . implode(' | ', $shown)
               . (count($errors) > 4 ? ' …' : '');
        flash($msg, 'err');
    } else {
        flash($msg);
    }
    header('Location: log.php');
    exit;
}

/* ---------- delete (admin only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    require_admin();
    $pdo->prepare("DELETE FROM logs WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
    flash('Entry deleted.');
    header('Location: ' . after_redirect_target());
    exit;
}

/* ---------- filters (shared by table + export) ---------- */
$type   = ($_GET['type'] ?? '') === 'in' ? 'in' : (($_GET['type'] ?? '') === 'out' ? 'out' : '');
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$status = ($_GET['status'] ?? '') === '1' ? '1' : (($_GET['status'] ?? '') === '0' ? '0' : '');
$catId  = (int) ($_GET['category_id'] ?? 0);
$q      = trim((string) ($_GET['q'] ?? ''));

/* ---------- export to Excel ---------- */
if (($_GET['do'] ?? '') === 'export') {
    [$sql, $args] = log_filter_sql($type, $from, $to, $status, $catId, $q);
    $sql .= " ORDER BY l.logged_at, l.id LIMIT 20000";
    $st = $pdo->prepare($sql);
    $st->execute($args);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            date('Y-m-d', strtotime($r['logged_at'])),
            date('H:i', strtotime($r['logged_at'])),
            $r['person'],
            $r['dept'],
            $r['category_name'],
            $r['item'],
            (int) $r['qty'],
            strtoupper($r['type']),
            $r['note'],
            $r['gate'],
            $r['verified'] ? 'Verified' : 'Pending',
            $r['verify_remark'] ?? '',
        ];
    }
    xlsx_export(
        ['Date', 'Time', 'Person', 'Department', 'Category', 'Item', 'Qty', 'Direction', 'Note', 'Gate', 'Status', 'Verified by', 'Remark'],
        $rows,
        'gate-pass-log-' . date('Y-m-d') . '.xlsx',
        'Gate Pass Log'
    );
}

/* ---------- table data ---------- */
[$sql, $args] = log_filter_sql($type, $from, $to, $status, $catId, $q);
$sql .= " ORDER BY l.logged_at DESC, l.id DESC LIMIT 300";
$st = $pdo->prepare($sql);
$st->execute($args);
$logs = $st->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$pending    = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE verified = 0")->fetchColumn();
$filtered   = ($type !== '' || $from !== '' || $to !== '' || $status !== '' || $catId > 0 || $q !== '');

page_header('Data Log', 'log.php');
?>
<div class="grid grid-4">
  <div class="stat"><div class="k">Showing</div><div class="v"><?= count($logs) ?></div><div class="s">entries<?= $filtered ? ' (filtered)' : '' ?></div></div>
  <div class="stat"><div class="k">Pending verify</div><div class="v"><?= number_format($pending) ?></div><div class="s">awaiting gate officer</div></div>
  <div class="stat"><div class="k">Verified</div><div class="v"><?= number_format((int) $pdo->query("SELECT COUNT(*) FROM logs WHERE verified=1")->fetchColumn()) ?></div><div class="s">confirmed records</div></div>
  <div class="stat"><div class="k">Total records</div><div class="v"><?= number_format((int) $pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn()) ?></div><div class="s">all time</div></div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Import from Excel (.xlsx)</h2>
    <a class="btn btn-ghost btn-sm" href="?do=export&<?= e(http_build_query(array_filter([
        'type' => $type, 'from' => $from, 'to' => $to, 'status' => $status,
        'category_id' => $catId ?: null, 'q' => $q,
    ]))) ?>">⬇ Export current filter</a>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="form-row">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">
      <label class="field grow">Spreadsheet
        <input type="file" name="file" accept=".xlsx,.xls" required>
      </label>
      <button class="btn btn-primary" type="submit">Import rows</button>
      <a class="btn btn-ghost" href="?do=export">⬇ Template (empty export)</a>
    </form>
    <p class="hint" style="margin-top:10px">
      Columns: <span class="kbd">Date</span> <span class="kbd">Time</span> <span class="kbd">Person</span>
      <span class="kbd">Department</span> <span class="kbd">Category</span> <span class="kbd">Item</span>
      <span class="kbd">Qty</span> <span class="kbd">Direction</span> <span class="kbd">Note</span>
      <span class="kbd">Gate</span> — exported rows keep their filters; imported rows are marked verified.
    </p>
  </div>
</div>

<div class="card">
  <form method="get" class="toolbar">
    <input type="hidden" name="do" value="">
    <label class="field">Direction
      <select name="type" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="in" <?= $type === 'in' ? 'selected' : '' ?>>IN</option>
        <option value="out" <?= $type === 'out' ? 'selected' : '' ?>>OUT</option>
      </select>
    </label>
    <label class="field">Category
      <select name="category_id" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $catId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">Status
      <select name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Pending</option>
        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Verified</option>
      </select>
    </label>
    <label class="field">From
      <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()">
    </label>
    <label class="field">To
      <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()">
    </label>
    <label class="field grow">Search
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="person, item, department">
    </label>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="log.php">Reset</a><?php endif; ?>
    <span class="spacer"></span>
    <span class="filters-summary"><?= count($logs) ?> shown</span>
  </form>

  <div class="card-body flush" data-live="fragment.php?part=log&<?= e(http_build_query(array_filter([
      'type' => $type, 'from' => $from, 'to' => $to, 'status' => $status,
      'category_id' => $catId ?: null, 'q' => $q,
  ]))) ?>">
    <?php render_log_table($logs, is_admin()); ?>
  </div>
</div>
<?php page_footer(); ?>
