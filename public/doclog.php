<?php
/**
 * Document Log — the full document take-out list (admin).
 *
 * Read-only report with filters and Excel export; approving/rejecting and
 * recording happen on documents.php.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/documents.php';
require_once __DIR__ . '/../src/excel.php';
require_can('manage_data');

$pdo = db();

$_SESSION['doc_return'] = (string) ($_SERVER['QUERY_STRING'] ?? '');

$status     = in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? (string) $_GET['status'] : '';
$from       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? (string) $_GET['from'] : '';
$to         = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? (string) $_GET['to'] : '';
$personType = in_array($_GET['person_type'] ?? '', ['personnel', 'visitor', 'contractor'], true) ? (string) $_GET['person_type'] : '';
$q          = trim((string) ($_GET['q'] ?? ''));

/* ---------- export to Excel ---------- */
if (($_GET['do'] ?? '') === 'export') {
    [$sql, $args] = doc_filter_sql($status, $q, $from, $to, $personType);
    $sql .= ' ORDER BY d.taken_at, d.id LIMIT 20000';
    $st = $pdo->prepare($sql);
    $st->execute($args);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            date('Y-m-d', strtotime($r['taken_at'])),
            date('H:i', strtotime($r['taken_at'])),
            $r['person'],
            person_type_label($r['person_type']),
            $r['dept'],
            $r['doc_no'],
            $r['title'],
            $r['purpose'],
            (int) $r['copies'],
            ucfirst($r['status']),
            $r['approver'] ?? '',
            $r['approved_at'] ? date('Y-m-d H:i', strtotime((string) $r['approved_at'])) : '',
            $r['approval_note'],
            $r['note'],
            (int) $r['photo_bytes'] > 0 ? (int) round((int) $r['photo_bytes'] / 1024) : 0,
        ];
    }
    xlsx_export(
        ['Date', 'Time', 'Person', 'Type', 'Company', 'Document no', 'Document', 'Purpose',
         'Copies', 'Status', 'Approved by', 'Approved at', 'Approval note', 'Note', 'Scan KB'],
        $rows,
        'documents-out-' . date('Y-m-d') . '.xlsx',
        'Documents out'
    );
}

/* ---------- list data ---------- */
$rows     = fetch_documents($status, $q, $from, $to, $personType, 300);
$ret      = (string) ($_SERVER['QUERY_STRING'] ?? '');
$filtered = ($status !== '' || $from !== '' || $to !== '' || $personType !== '' || $q !== '');

$counts = $pdo->query("SELECT status, COUNT(*) c FROM documents GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$total  = array_sum(array_map('intval', $counts));
$photos = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(photo_bytes),0) b
                       FROM documents WHERE photo_bytes > 0")->fetch();
$photoN  = (int) ($photos['n'] ?? 0);
$photoMb = ((int) ($photos['b'] ?? 0)) / 1048576;

$exportQuery = http_build_query(array_filter([
    'do' => 'export', 'status' => $status, 'from' => $from, 'to' => $to,
    'person_type' => $personType, 'q' => $q,
]));

page_header('Document Log', 'doclog.php');
?>
<div class="grid grid-4">
  <div class="stat"><div class="k">Showing</div><div class="v"><?= count($rows) ?></div><div class="s">rows<?= $filtered ? ' (filtered)' : '' ?></div></div>
  <div class="stat tone-out"><div class="k">Pending approval</div><div class="v"><?= number_format((int) ($counts['pending'] ?? 0)) ?></div><div class="s">waiting for a leader</div></div>
  <div class="stat tone-in"><div class="k">Approved</div><div class="v"><?= number_format((int) ($counts['approved'] ?? 0)) ?></div><div class="s">cleared to leave</div></div>
  <div class="stat"><div class="k">Scans stored</div><div class="v"><?= number_format($photoN) ?></div><div class="s"><?= number_format($photoMb, 1) ?> MB total — compressed</div></div>
</div>

<div class="card">
  <form method="get" class="toolbar">
    <label class="field">Approval
      <select name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="pending"  <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
      </select>
    </label>
    <label class="field">Person type
      <select name="person_type" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach (person_types() as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $personType === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">From
      <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()">
    </label>
    <label class="field">To
      <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()">
    </label>
    <label class="field grow">Search
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="person, document, number, company">
    </label>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="doclog.php">Reset</a><?php endif; ?>
    <span class="spacer"></span>
    <a class="btn btn-primary btn-sm" href="?<?= e($exportQuery) ?>">⬇ Export current filter</a>
  </form>

  <div class="card-body flush">
    <?php render_document_list($rows, false, '', false); ?>
  </div>
</div>

<p class="hint">Total document take-outs on record: <strong><?= number_format($total) ?></strong>.
  Approve or reject on the <a href="documents.php">Documents out</a> page.</p>
<?php page_footer(); ?>
