<?php
require_once __DIR__ . '/../src/helpers.php';
require_login();

$pdo = db();

$inToday   = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE type='in'  AND DATE(logged_at)=CURDATE()")->fetchColumn();
$outToday  = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE type='out' AND DATE(logged_at)=CURDATE()")->fetchColumn();
$pending   = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE verified=0")->fetchColumn();
$totalRows = (int) $pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();

// totals by category (all time) — no stock, only counts
$cats = $pdo->query("SELECT cat.name,
        COUNT(*) entries,
        COALESCE(SUM(l.type='in'),0)  in_e,
        COALESCE(SUM(l.type='out'),0) out_e,
        COALESCE(SUM(CASE WHEN l.type='in' THEN l.qty ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN l.type='out' THEN l.qty ELSE 0 END),0) net_qty
      FROM logs l JOIN categories cat ON cat.id = l.category_id
      GROUP BY cat.id, cat.name ORDER BY entries DESC")->fetchAll();

// last 7 days of gate passes
$rows = $pdo->query("SELECT DATE(logged_at) d, type, COUNT(*) c
                     FROM logs WHERE logged_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                     GROUP BY d, type")->fetchAll();
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $key = date('Y-m-d', strtotime("-$i days"));
    $days[$key] = ['in' => 0, 'out' => 0, 'label' => date('d M', strtotime($key))];
}
foreach ($rows as $r) {
    if (isset($days[$r['d']])) {
        $days[$r['d']][$r['type']] = (int) $r['c'];
    }
}
$max = 1;
foreach ($days as $d) {
    $max = max($max, $d['in'], $d['out']);
}

$recent = $pdo->query("SELECT l.*, c.name AS category_name, u.name AS recorder
                       FROM logs l
                       JOIN categories c ON c.id = l.category_id
                       LEFT JOIN users u ON u.id = l.user_id
                       ORDER BY l.logged_at DESC, l.id DESC LIMIT 8")->fetchAll();

page_header('Dashboard', 'index.php');
?>
<div class="grid grid-4">
  <div class="stat tone-in">
    <div class="k">IN today</div>
    <div class="v"><?= number_format($inToday) ?></div>
    <div class="s">personnel entering</div>
  </div>
  <div class="stat tone-out">
    <div class="k">OUT today</div>
    <div class="v"><?= number_format($outToday) ?></div>
    <div class="s">personnel leaving</div>
  </div>
  <div class="stat">
    <div class="k">Pending verify</div>
    <div class="v"><?= number_format($pending) ?></div>
    <div class="s"><a href="verify.php">verify now</a></div>
  </div>
  <div class="stat">
    <div class="k">Total records</div>
    <div class="v"><?= number_format($totalRows) ?></div>
    <div class="s"><a href="summary.php">see summary</a></div>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head">
      <h2>Last 7 days — gate passes</h2>
      <div class="legend">
        <span><i style="background:#12805c"></i>IN</span>
        <span><i style="background:#f0b357"></i>OUT</span>
      </div>
    </div>
    <div class="card-body">
      <div class="chart">
        <?php foreach ($days as $d): ?>
          <div class="bar-group">
            <div class="bars">
              <span class="bar bar-in" title="IN <?= (int) $d['in'] ?>"
                    style="height:<?= (int) round($d['in'] / $max * 100) ?>%"></span>
              <span class="bar bar-out" title="OUT <?= (int) $d['out'] ?>"
                    style="height:<?= (int) round($d['out'] / $max * 100) ?>%"></span>
            </div>
            <span class="day"><?= e($d['label']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Total by category</h2>
      <a class="btn btn-ghost btn-sm" href="summary.php">Full summary</a>
    </div>
    <div class="card-body flush">
      <?php if (!$cats): ?>
        <div class="empty">No records yet.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>Category</th><th class="num">Entries</th><th class="num">IN</th><th class="num">OUT</th><th class="num">Net items</th></tr>
          </thead>
          <tbody>
          <?php foreach ($cats as $r): ?>
            <tr>
              <td><?= e($r['name']) ?></td>
              <td class="num"><?= (int) $r['entries'] ?></td>
              <td class="num"><?= (int) $r['in_e'] ?></td>
              <td class="num"><?= (int) $r['out_e'] ?></td>
              <td class="num"><strong><?= (int) $r['net_qty'] ?></strong></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Recent gate activity</h2>
    <a class="btn btn-ghost btn-sm" href="verify.php">Open verify queue</a>
  </div>
  <div class="card-body flush">
    <?php if (!$recent): ?>
      <div class="empty">No entries yet — record the first one on the <a href="input.php">Input</a> page.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Date / time</th><th>Personnel</th><th>Category</th><th>Belongings / items</th>
            <th class="num">Qty</th><th>Dir</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="mono"><?= e(date('d M · H:i', strtotime($r['logged_at']))) ?></td>
            <td>
              <strong><?= e($r['person']) ?></strong>
              <span class="muted"><?= $r['dept'] !== '' ? '· ' . e($r['dept']) : '' ?></span>
            </td>
            <td class="muted"><?= e($r['category_name']) ?></td>
            <td><?= e($r['item']) ?></td>
            <td class="num"><?= (int) $r['qty'] ?></td>
            <td><span class="badge badge-<?= $r['type'] === 'in' ? 'in' : 'out' ?>"><?= $r['type'] === 'in' ? 'IN' : 'OUT' ?></span></td>
            <td>
              <?php if ($r['verified']): ?>
                <span class="badge badge-in">✓ verified</span>
              <?php else: ?>
                <span class="badge badge-out">pending</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php page_footer(); ?>
