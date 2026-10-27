<?php
declare(strict_types=1);

/**
 * Shared queries + HTML render functions.
 *
 * The same functions are used by the full pages and by fragment.php, so the
 * auto-refresh swap produces byte-identical markup.
 */

/* ---------------------------------------------------------------- queries */

/** @return array{0:string,1:array} sql + args for the data log */
function log_filter_sql(string $type, string $from, string $to, string $status, int $catId, string $q): array
{
    $sql  = "SELECT l.*, c.name AS category_name,
                    u.name AS recorder, v.name AS verifier
             FROM logs l
             JOIN categories c ON c.id = l.category_id
             LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN users v ON v.id = l.verified_by
             WHERE 1";
    $args = [];
    if ($type !== '')   { $sql .= " AND l.type = ?"; $args[] = $type; }
    if ($from !== '')   { $sql .= " AND l.logged_at >= ?"; $args[] = $from . ' 00:00:00'; }
    if ($to !== '')     { $sql .= " AND l.logged_at <= ?"; $args[] = $to . ' 23:59:59'; }
    if ($status !== '') { $sql .= " AND l.verified = ?"; $args[] = (int) $status; }
    if ($catId > 0)     { $sql .= " AND l.category_id = ?"; $args[] = $catId; }
    if ($q !== '') {
        $sql .= " AND (l.person LIKE ? OR l.item LIKE ? OR l.dept LIKE ?)";
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    return [$sql, $args];
}

/** Pending records (verification queue) for the personnel workflow. */
function fetch_verify_rows(string $q): array
{
    $db   = db();
    $sql  = "SELECT l.*, c.name AS category_name, u.name AS recorder
             FROM logs l
             JOIN categories c ON c.id = l.category_id
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.verified = 0";
    $args = [];
    if ($q !== '') {
        $sql .= " AND (l.person LIKE ? OR l.item LIKE ? OR l.dept LIKE ?)";
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    $sql .= " ORDER BY l.person, l.logged_at DESC, l.id DESC LIMIT 300";
    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    if (!$rows) {
        return [];
    }

    // verified counterpart records (the IN/OUT record to check against)
    $persons = array_values(array_unique(array_column($rows, 'person')));
    $in      = implode(',', array_fill(0, count($persons), '?'));
    $refSt   = $db->prepare("SELECT * FROM logs WHERE verified = 1 AND person IN ($in)
                             ORDER BY logged_at DESC, id DESC LIMIT 100");
    $refSt->execute($persons);
    $byPerson = [];
    foreach ($refSt->fetchAll() as $r) {
        $byPerson[$r['person']][] = $r;
    }

    foreach ($rows as &$r) {
        $r['ref'] = null;
        foreach ($byPerson[$r['person']] ?? [] as $cand) {
            if ($cand['type'] !== $r['type']) {
                $r['ref'] = $cand;
                break;
            }
        }
    }
    unset($r);

    return $rows;
}

/** Open (pending) records for one person — shown while recording a crossing. */
function fetch_open_records(string $person): array
{
    if (trim($person) === '') {
        return [];
    }
    $st = db()->prepare("SELECT l.*, c.name AS category_name
                         FROM logs l JOIN categories c ON c.id = l.category_id
                         WHERE l.verified = 0 AND l.person LIKE ?
                         ORDER BY l.logged_at DESC, l.id DESC LIMIT 20");
    $st->execute(['%' . trim($person) . '%']);
    return $st->fetchAll();
}

/* ---------------------------------------------------------------- renders */

/** Data-log table body (or empty state). */
function render_log_table(array $logs, bool $admin): void
{
    if (!$logs) {
        echo '<div class="empty">No entries match these filters. Record one on the <a href="input.php">Input</a> page.</div>';
        return;
    }
    ?>
    <table>
      <thead>
        <tr>
          <th>Date / time</th><th>Personnel</th><th>Category</th><th>Belongings / items</th>
          <th class="num">Qty</th><th>Dir</th><th>Gate</th><th>Status</th><th>Recorded by</th><?php if ($admin): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $r): ?>
        <tr>
          <td class="mono"><?= e(date('Y-m-d H:i', strtotime($r['logged_at']))) ?></td>
          <td>
            <strong><?= e($r['person']) ?></strong>
            <span class="muted"><?= $r['dept'] !== '' ? '· ' . e($r['dept']) : '' ?></span>
          </td>
          <td><span class="badge badge-neutral"><?= e($r['category_name']) ?></span></td>
          <td><?= e($r['item']) ?><?php if (($r['note'] ?? '') !== ''): ?><br><span class="muted" style="font-size:12px"><?= e($r['note']) ?></span><?php endif; ?></td>
          <td class="num"><?= (int) $r['qty'] ?></td>
          <td><span class="badge badge-<?= $r['type'] === 'in' ? 'in' : 'out' ?>"><?= $r['type'] === 'in' ? 'IN' : 'OUT' ?></span></td>
          <td class="muted"><?= e($r['gate']) ?></td>
          <td>
            <?php if ($r['verified']): ?>
              <span class="badge badge-in" title="by <?= e($r['verifier'] ?? '') ?> at <?= e($r['verified_at'] ?? '') ?>">✓ verified</span>
              <?php if (($r['verify_remark'] ?? '') !== ''): ?>
                <br><span class="muted" style="font-size:12px"><?= e($r['verify_remark']) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge badge-out">pending</span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= e($r['recorder'] ?? '—') ?></td>
          <?php if ($admin): ?>
            <td class="row-actions">
              <form method="post" action="log.php" onsubmit="return confirm('Delete this entry?')">
                <?= csrf_field() ?>
                <input type="hidden" name="ret" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-danger btn-xs" type="submit">Delete</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

/** Verification queue, grouped by person, with the counterpart record. */
function render_verify_list(array $rows, bool $canVerify): void
{
    if (!$rows) {
        echo '<div class="empty">Nothing to verify — every record has been checked. 🎉</div>';
        return;
    }

    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['person']][] = $r;
    }

    // quick remarks (e.g. belongings taken out but not returned yet)
    echo '<datalist id="verify-remarks">'
       . '<option value="Belongings not returned"></option>'
       . '<option value="Partially returned"></option>'
       . '<option value="Returned as recorded"></option>'
       . '<option value="Item missing / damaged"></option>'
       . '</datalist>';

    foreach ($grouped as $person => $list) {
        $dept = $list[0]['dept'];
        echo '<div class="vgroup">';
        echo '<div class="vgroup-head"><strong>' . e($person) . '</strong>'
           . '<span class="muted">' . e($dept) . '</span>'
           . '<span class="badge badge-out">' . count($list) . ' pending</span></div>';

        echo '<table><thead><tr>'
           . '<th>Recorded</th><th>Dir</th><th>Belongings / items</th><th>Category</th>'
           . '<th class="num">Qty</th><th>Check against (opposite crossing)</th>'
           . ($canVerify ? '<th class="col-remark">Remark / verify</th>' : '')
           . '</tr></thead><tbody>';

        foreach ($list as $r) {
            $ref = $r['ref'];
            ?>
            <tr>
              <td class="mono"><?= e(date('d M · H:i', strtotime($r['logged_at']))) ?></td>
              <td><span class="badge badge-<?= $r['type'] === 'in' ? 'in' : 'out' ?>">
                    <?= $r['type'] === 'in' ? 'IN' : 'OUT' ?></span></td>
              <td>
                <strong><?= e($r['item']) ?></strong>
                <?php if (($r['note'] ?? '') !== ''): ?>
                  <br><span class="muted" style="font-size:12px"><?= e($r['note']) ?></span>
                <?php endif; ?>
                <?php if (!$ref): ?><br><span class="muted" style="font-size:11.5px">no opposite record yet</span><?php endif; ?>
              </td>
              <td class="muted"><?= e($r['category_name']) ?></td>
              <td class="num"><?= (int) $r['qty'] ?></td>
              <td>
                <?php if ($ref): ?>
                  <span class="badge badge-<?= $ref['type'] === 'in' ? 'in' : 'out' ?>">
                    <?= $ref['type'] === 'in' ? 'IN' : 'OUT' ?></span>
                  <span class="mono"><?= e(date('d M H:i', strtotime($ref['logged_at']))) ?></span>
                  — <?= e($ref['item']) ?> (<?= (int) $ref['qty'] ?>)
                  <?php if (($ref['verify_remark'] ?? '') !== ''): ?>
                    <br><span class="muted" style="font-size:11.5px"><?= e($ref['verify_remark']) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <?php if ($canVerify): ?>
                <td class="row-actions">
                  <form method="post" action="verify.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="ret" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                    <input type="hidden" name="action" value="verify">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input class="remark-input" type="text" name="remark" list="verify-remarks" maxlength="255"
                           value="<?= e($r['verify_remark'] ?? '') ?>" placeholder="Remark (optional)"
                           aria-label="Remark" title="Optional — e.g. Belongings not returned">
                    <button class="btn btn-primary btn-xs" type="submit">✓ Verify</button>
                  </form>
                  <?php if (is_admin()): ?>
                    <form method="post" action="verify.php" onsubmit="return confirm('Delete this entry?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="ret" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button class="btn btn-danger btn-xs" type="submit">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
            <?php
        }
        echo '</tbody></table></div>';
    }
}

/** Open-records panel for the Input page. */
function render_open_records(array $rows, string $person): void
{
    if (trim($person) === '') {
        echo '<div class="empty">Type a personnel name to show their open (unverified) records.</div>';
        return;
    }
    if (!$rows) {
        echo '<div class="empty">No open records for “' . e($person) . '”.</div>';
        return;
    }
    echo '<table><thead><tr><th>Recorded</th><th>Dir</th><th>Belongings / items</th>'
       . '<th>Category</th><th class="num">Qty</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>'
           . '<td class="mono">' . e(date('d M · H:i', strtotime($r['logged_at']))) . '</td>'
           . '<td><span class="badge badge-' . ($r['type'] === 'in' ? 'in' : 'out') . '">'
           . ($r['type'] === 'in' ? 'IN' : 'OUT') . '</span></td>'
           . '<td>' . e($r['item']) . '</td>'
           . '<td class="muted">' . e($r['category_name']) . '</td>'
           . '<td class="num">' . (int) $r['qty'] . '</td>'
           . '</tr>';
    }
    echo '</tbody></table>';
}
