<?php
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/excel.php';
require_can('view_summary');

$pdo = db();

/* ---------- period selection ---------- */
$period = $_GET['period'] ?? 'week';
$from = $to = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) { $from = $_GET['from']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? ''))   { $to = $_GET['to']; }

switch ($period) {
    case 'day':
        $from = $to = date('Y-m-d');
        break;
    case 'month':
        $from = date('Y-m-01');
        $to   = date('Y-m-d');
        break;
    case 'custom':
        $from = $from ?: date('Y-m-d', strtotime('-6 days'));
        $to   = $to ?: date('Y-m-d');
        break;
    case 'week':
    default:
        $period = 'week';
        $from = date('Y-m-d', strtotime('monday this week'));
        $to   = date('Y-m-d');
        break;
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$range = [' AND l.logged_at >= ? AND l.logged_at <= ?', $from . ' 00:00:00', $to . ' 23:59:59'];
$base  = " FROM logs l WHERE l.logged_at >= ? AND l.logged_at <= ?";
$args  = [$from . ' 00:00:00', $to . ' 23:59:59'];

/* ---------- export the period ---------- */
if (($_GET['do'] ?? '') === 'export') {
    $st = $pdo->prepare("SELECT l.*, c.name AS category_name FROM logs l
                         JOIN categories c ON c.id = l.category_id
                         WHERE l.logged_at >= ? AND l.logged_at <= ?
                         ORDER BY l.logged_at, l.id");
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            date('Y-m-d', strtotime($r['logged_at'])),
            date('H:i', strtotime($r['logged_at'])),
            $r['person'], person_type_label($r['person_type']),
            $r['dept'], $r['category_name'], $r['item'],
            (int) $r['qty'], strtoupper($r['type']), $r['note'], $r['gate'],
            $r['verified'] ? 'Verified' : 'Pending',
            $r['verify_remark'] ?? '',
        ];
    }
    xlsx_export(
        ['Date', 'Time', 'Person', 'Type', 'Department', 'Category', 'Item', 'Qty', 'Direction', 'Note', 'Gate', 'Status', 'Remark'],
        $rows,
        "gate-pass-summary-{$period}-{$from}-{$to}.xlsx",
        'Summary'
    );
}

/* ---------- export the period's documents ---------- */
if (($_GET['do'] ?? '') === 'docexport') {
    $st = $pdo->prepare("SELECT d.*, a.name AS approver FROM documents d
                         LEFT JOIN users a ON a.id = d.approved_by
                         WHERE d.taken_at >= ? AND d.taken_at <= ?
                         ORDER BY d.taken_at, d.id");
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            date('Y-m-d', strtotime($r['taken_at'])),
            date('H:i', strtotime($r['taken_at'])),
            $r['person'], person_type_label($r['person_type']), $r['dept'], $r['doc_no'],
            $r['title'], $r['purpose'], (int) $r['copies'], ucfirst($r['status']),
            $r['approver'] ?? '',
            $r['approved_at'] ? date('Y-m-d H:i', strtotime((string) $r['approved_at'])) : '',
            $r['approval_note'], $r['note'],
            (int) $r['photo_bytes'] > 0 ? (int) round((int) $r['photo_bytes'] / 1024) : 0,
        ];
    }
    xlsx_export(
        ['Date', 'Time', 'Person', 'Type', 'Company', 'Document no', 'Document', 'Purpose',
         'Copies', 'Status', 'Approved by', 'Approved at', 'Approval note', 'Note', 'Scan KB'],
        $rows,
        "documents-out-{$period}-{$from}-{$to}.xlsx",
        'Documents out'
    );
}

/* ---------- headline numbers ---------- */
$s = $pdo->prepare("SELECT COUNT(*) entries,
        COALESCE(SUM(type='in'),0)  in_entries,
        COALESCE(SUM(type='out'),0) out_entries,
        COALESCE(SUM(CASE WHEN type='in'  THEN qty END),0) in_qty,
        COALESCE(SUM(CASE WHEN type='out' THEN qty END),0) out_qty,
        COALESCE(SUM(verified=0),0) pending
        $base");
$s->execute($args);
$tot = $s->fetch();

/* ---------- by category (no stock: counts per period) ---------- */
$c = $pdo->prepare("SELECT cat.name,
        COUNT(*) entries,
        COALESCE(SUM(l.type='in'),0)  in_e,
        COALESCE(SUM(l.type='out'),0) out_e,
        COALESCE(SUM(CASE WHEN l.type='in'  THEN l.qty ELSE 0 END),0) in_qty,
        COALESCE(SUM(CASE WHEN l.type='out' THEN l.qty ELSE 0 END),0) out_qty
        FROM logs l JOIN categories cat ON cat.id = l.category_id
        WHERE l.logged_at >= ? AND l.logged_at <= ?
        GROUP BY cat.id, cat.name ORDER BY entries DESC");
$c->execute($args);
$byCat = $c->fetchAll();

/* ---------- by day ---------- */
$d = $pdo->prepare("SELECT DATE(logged_at) day,
        COUNT(*) entries,
        COALESCE(SUM(type='in'),0)  in_e,
        COALESCE(SUM(type='out'),0) out_e,
        COALESCE(SUM(CASE WHEN type='in'  THEN qty ELSE 0 END),0) in_qty,
        COALESCE(SUM(CASE WHEN type='out' THEN qty ELSE 0 END),0) out_qty
        FROM logs WHERE logged_at >= ? AND logged_at <= ?
        GROUP BY day ORDER BY day");
$d->execute($args);
$byDay = $d->fetchAll();

/* ---------- top personnel ---------- */
$p = $pdo->prepare("SELECT person, dept, COUNT(*) entries,
        COALESCE(SUM(type='in'),0) in_e, COALESCE(SUM(type='out'),0) out_e
        FROM logs WHERE logged_at >= ? AND logged_at <= ?
        GROUP BY person, dept ORDER BY entries DESC LIMIT 10");
$p->execute($args);
$byPerson = $p->fetchAll();

/* ---------- documents out, visitors, personnel types (period) ---------- */
$dq = $pdo->prepare("SELECT COUNT(*) total,
        COALESCE(SUM(status='pending'),0)  pending,
        COALESCE(SUM(status='approved'),0) approved,
        COALESCE(SUM(status='rejected'),0) rejected,
        COALESCE(SUM(copies),0)            copies,
        COALESCE(SUM(photo_bytes > 0),0)   scans,
        COALESCE(SUM(photo_bytes),0)       photo_bytes
        FROM documents WHERE taken_at >= ? AND taken_at <= ?");
$dq->execute($args);
$docTot = $dq->fetch();

$dt = $pdo->prepare("SELECT person_type, COUNT(*) c, COALESCE(SUM(copies),0) copies,
        COALESCE(SUM(status='approved'),0) approved, COALESCE(SUM(status='pending'),0) pending
        FROM documents WHERE taken_at >= ? AND taken_at <= ? GROUP BY person_type");
$dt->execute($args);
$docByType = [];
foreach ($dt->fetchAll() as $r) {
    $docByType[$r['person_type']] = $r;
}

$lt = $pdo->prepare("SELECT person_type, COUNT(*) entries,
        COALESCE(SUM(type='out'),0) out_e,
        COALESCE(SUM(CASE WHEN type='out' THEN qty ELSE 0 END),0) out_qty
        FROM logs WHERE logged_at >= ? AND logged_at <= ? GROUP BY person_type");
$lt->execute($args);
$logByType = [];
foreach ($lt->fetchAll() as $r) {
    $logByType[$r['person_type']] = $r;
}

$dp = $pdo->prepare("SELECT person, person_type, dept, COUNT(*) c, COALESCE(SUM(copies),0) copies
        FROM documents WHERE taken_at >= ? AND taken_at <= ?
        GROUP BY person, person_type, dept ORDER BY c DESC LIMIT 10");
$dp->execute($args);
$docByPerson = $dp->fetchAll();

$vq = $pdo->prepare("SELECT COUNT(*) total,
        COALESCE(SUM(time_out IS NULL),0)      onsite,
        COALESCE(SUM(person_type='contractor'),0) contractors,
        COALESCE(SUM(time_out IS NOT NULL),0)  checked_out
        FROM visitors WHERE time_in >= ? AND time_in <= ?");
$vq->execute($args);
$visTot = $vq->fetch();

$presets = [
    'day'    => ['label' => 'Today', 'period' => 'day', 'from' => date('Y-m-d'), 'to' => date('Y-m-d')],
    'week'   => ['label' => 'This week', 'period' => 'week', 'from' => date('Y-m-d', strtotime('monday this week')), 'to' => date('Y-m-d')],
    'month'  => ['label' => 'This month', 'period' => 'month', 'from' => date('Y-m-01'), 'to' => date('Y-m-d')],
];

page_header('Summary', 'summary.php');
?>
<div class="card">
  <form method="get" class="toolbar">
    <span class="section-title" style="margin:0 6px 0 0">Period</span>
    <?php foreach ($presets as $key => $pr): ?>
      <a class="btn <?= $period === $key && $from >= $pr['from'] && $from <= $pr['to'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
         href="?period=<?= e($key) ?>"><?= e($pr['label']) ?></a>
    <?php endforeach; ?>
    <label class="field">From
      <input type="date" name="from" value="<?= e($from) ?>">
    </label>
    <label class="field">To
      <input type="date" name="to" value="<?= e($to) ?>">
    </label>
    <input type="hidden" name="period" value="custom">
    <button class="btn btn-ghost btn-sm" type="submit">Apply period</button>
    <span class="spacer"></span>
    <a class="btn btn-primary btn-sm"
       href="?do=export&period=<?= e($period) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">⬇ Export belongings (.xlsx)</a>
    <a class="btn btn-ghost btn-sm"
       href="?do=docexport&period=<?= e($period) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">⬇ Export documents (.xlsx)</a>
  </form>
  <div class="card-body" style="padding-top:10px">
    <span class="filters-summary">
      Showing <strong><?= e($from) ?></strong> → <strong><?= e($to) ?></strong>
      (<?= count($byDay) ?> day<?= count($byDay) === 1 ? '' : 's' ?> with activity)
    </span>
  </div>
</div>

<div class="grid grid-4">
  <div class="stat"><div class="k">Gate passes</div><div class="v"><?= number_format((int) $tot['entries']) ?></div><div class="s">total records in period</div></div>
  <div class="stat tone-in"><div class="k">IN</div><div class="v"><?= number_format((int) $tot['in_entries']) ?></div><div class="s"><?= number_format((int) $tot['in_qty']) ?> items carried in</div></div>
  <div class="stat tone-out"><div class="k">OUT</div><div class="v"><?= number_format((int) $tot['out_entries']) ?></div><div class="s"><?= number_format((int) $tot['out_qty']) ?> items carried out</div></div>
  <div class="stat"><div class="k">Pending verify</div><div class="v"><?= number_format((int) $tot['pending']) ?></div>    <div class="s"><a href="verify.php">review on Verify</a></div></div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Total by category</h2>
    <span class="filters-summary">counts for this period — no stock is kept</span>
  </div>
  <div class="card-body flush">
    <?php if (!$byCat): ?>
      <div class="empty">No activity in this period.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Category</th>
            <th class="num">Entries</th>
            <th class="num">IN entries</th>
            <th class="num">OUT entries</th>
            <th class="num">Items in</th>
            <th class="num">Items out</th>
            <th class="num">Net items</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($byCat as $r): ?>
          <tr>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td class="num"><?= (int) $r['entries'] ?></td>
            <td class="num"><?= (int) $r['in_e'] ?></td>
            <td class="num"><?= (int) $r['out_e'] ?></td>
            <td class="num"><?= (int) $r['in_qty'] ?></td>
            <td class="num"><?= (int) $r['out_qty'] ?></td>
            <td class="num"><strong><?= (int) $r['in_qty'] - (int) $r['out_qty'] ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-4">
  <div class="stat"><div class="k">Documents out</div><div class="v"><?= number_format((int) $docTot['total']) ?></div><div class="s"><?= number_format((int) $docTot['copies']) ?> copies / pages</div></div>
  <div class="stat tone-in"><div class="k">Approved</div><div class="v"><?= number_format((int) $docTot['approved']) ?></div><div class="s">cleared by a leader</div></div>
  <div class="stat tone-out"><div class="k">Pending approval</div><div class="v"><?= number_format((int) $docTot['pending']) ?></div><div class="s"><a href="documents.php?status=pending">review</a></div></div>
  <div class="stat"><div class="k">Scans stored</div><div class="v"><?= number_format((int) $docTot['scans']) ?></div><div class="s">avg <?= (int) $docTot['scans'] > 0 ? number_format(((int) $docTot['photo_bytes'] / (int) $docTot['scans']) / 1024, 0) : 0 ?> KB each</div></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head">
      <h2>Employees, visitors &amp; contractors</h2>
      <span class="filters-summary">same period</span>
    </div>
    <div class="card-body flush">
      <table>
        <thead>
          <tr>
            <th>Person type</th><th class="num">Gate passes</th><th class="num">Items out</th>
            <th class="num">Documents out</th><th class="num">Approved</th><th class="num">Pending</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach (person_types() as $key => $label): $lg = $logByType[$key] ?? []; $dg = $docByType[$key] ?? []; ?>
          <tr>
            <td><?= person_type_badge($key) ?></td>
            <td class="num"><?= (int) ($lg['entries'] ?? 0) ?></td>
            <td class="num"><?= (int) ($lg['out_qty'] ?? 0) ?></td>
            <td class="num"><strong><?= (int) ($dg['c'] ?? 0) ?></strong></td>
            <td class="num"><?= (int) ($dg['approved'] ?? 0) ?></td>
            <td class="num"><?= (int) ($dg['pending'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Top personnel — documents out</h2><span class="filters-summary">max 10</span></div>
    <div class="card-body flush">
      <?php if (!$docByPerson): ?>
        <div class="empty">No documents taken out in this period.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Personnel</th><th>Type</th><th class="num">Documents</th><th class="num">Copies</th></tr></thead>
          <tbody>
          <?php foreach ($docByPerson as $r): ?>
            <tr>
              <td>
                <?= e($r['person']) ?>
                <?php if ($r['dept'] !== ''): ?><br><span class="muted" style="font-size:12px"><?= e($r['dept']) ?></span><?php endif; ?>
              </td>
              <td><?= person_type_badge($r['person_type']) ?></td>
              <td class="num"><?= (int) $r['c'] ?></td>
              <td class="num"><?= (int) $r['copies'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-4">
  <div class="stat"><div class="k">Visitors &amp; contractors</div><div class="v"><?= number_format((int) $visTot['total']) ?></div><div class="s">checked in this period</div></div>
  <div class="stat"><div class="k">Contractor workers</div><div class="v"><?= number_format((int) $visTot['contractors']) ?></div><div class="s">of those visits</div></div>
  <div class="stat tone-out"><div class="k">Still on site</div><div class="v"><?= number_format((int) $visTot['onsite']) ?></div><div class="s">no check-out yet</div></div>
  <div class="stat"><div class="k">Checked out</div><div class="v"><?= number_format((int) $visTot['checked_out']) ?></div><div class="s"><a href="visitors.php?status=all">visitor register</a></div></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h2>Day by day</h2></div>
    <div class="card-body flush">
      <?php if (!$byDay): ?>
        <div class="empty">No activity in this period.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Date</th><th class="num">Entries</th><th class="num">IN</th><th class="num">OUT</th><th class="num">Net items</th></tr></thead>
          <tbody>
          <?php foreach ($byDay as $r): ?>
            <tr>
              <td class="mono"><?= e(date('D, d M Y', strtotime($r['day']))) ?></td>
              <td class="num"><?= (int) $r['entries'] ?></td>
              <td class="num"><?= (int) $r['in_e'] ?></td>
              <td class="num"><?= (int) $r['out_e'] ?></td>
              <td class="num"><strong><?= (int) $r['in_qty'] - (int) $r['out_qty'] ?></strong></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Top personnel</h2><span class="filters-summary">max 10</span></div>
    <div class="card-body flush">
      <?php if (!$byPerson): ?>
        <div class="empty">No activity in this period.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Personnel</th><th>Department</th><th class="num">Passes</th><th class="num">IN</th><th class="num">OUT</th></tr></thead>
          <tbody>
          <?php foreach ($byPerson as $r): ?>
            <tr>
              <td><?= e($r['person']) ?></td>
              <td class="muted"><?= e($r['dept']) ?></td>
              <td class="num"><?= (int) $r['entries'] ?></td>
              <td class="num"><?= (int) $r['in_e'] ?></td>
              <td class="num"><?= (int) $r['out_e'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php page_footer(); ?>
