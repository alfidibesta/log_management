<?php
declare(strict_types=1);

/**
 * Visitor / contractor register.
 *
 * Reception checks a visitor or contractor worker in here (name, company,
 * ID card, host, badge). While they are on site the gate officer records
 * their belongings and documents against the same name, and reception checks
 * them out when they leave.
 */

/** @return array{0:string,1:array} sql + args for the visitor list */
function visitor_filter_sql(string $status, string $q, string $type = ''): array
{
    $sql  = "SELECT v.*, u.name AS registrar
             FROM visitors v
             LEFT JOIN users u ON u.id = v.user_id
             WHERE 1";
    $args = [];
    if ($status === 'in') {
        $sql .= ' AND v.time_out IS NULL';
    } elseif ($status === 'out') {
        $sql .= ' AND v.time_out IS NOT NULL';
    }
    if (in_array($type, ['visitor', 'contractor'], true)) {
        $sql   .= ' AND v.person_type = ?';
        $args[] = $type;
    }
    if ($q !== '') {
        $sql .= ' AND (v.name LIKE ? OR v.company LIKE ? OR v.host LIKE ? OR v.badge_no LIKE ? OR v.id_no LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    return [$sql, $args];
}

function fetch_visitors(string $status, string $q, string $type = '', int $limit = 200): array
{
    [$sql, $args] = visitor_filter_sql($status, $q, $type);
    $sql .= ' ORDER BY (v.time_out IS NULL) DESC, v.time_in DESC, v.id DESC LIMIT ' . max(1, min(500, $limit));
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Visitors / contractors currently on site — the input forms offer these names. */
function onsite_visitors(): array
{
    return db()->query("SELECT id, name, person_type, company, badge_no, time_in
                        FROM visitors WHERE time_out IS NULL
                        ORDER BY time_in DESC LIMIT 100")->fetchAll();
}

/** Where to go back to after an action, keeping the list filters. */
function visitor_return_target(): string
{
    $qs = (string) ($_POST['ret'] ?? $_SESSION['visitor_return'] ?? '');
    return 'visitors.php' . ($qs !== '' ? '?' . $qs : '');
}

/** Visitor table with check-out / undo actions and quick links to the log forms. */
function render_visitor_list(array $rows, string $ret = '', bool $canRecord = true): void
{
    if (!$rows) {
        echo '<div class="empty">No visitors match this filter. Check one in with the form above.</div>';
        return;
    }
    ?>
    <table>
      <thead>
        <tr>
          <th>Visitor</th><th>Type</th><th>Company</th><th>ID / phone</th>
          <th>Host / purpose</th><th>In</th><th>Out</th><th>Recorded by</th>
          <th>Log for them</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $v): $id = (int) $v['id']; $onsite = $v['time_out'] === null; ?>
        <tr>
          <td>
            <strong><a href="history.php?entity=visitor&amp;id=<?= $id ?>"
                       title="Open this visitor and its audit trail"><?= e($v['name']) ?></a></strong>
            <?php if ($v['badge_no'] !== ''): ?>
              <span class="badge badge-neutral"><?= e($v['badge_no']) ?></span>
            <?php endif; ?>
            <?php if ($v['note'] !== ''): ?>
              <br><span class="muted" style="font-size:11.5px"><?= e($v['note']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= person_type_badge($v['person_type']) ?></td>
          <td><?= e($v['company']) ?></td>
          <td class="mono">
            <?= $v['id_no'] !== '' ? e($v['id_no']) : '—' ?>
            <?php if ($v['phone'] !== ''): ?><br><span class="muted"><?= e($v['phone']) ?></span><?php endif; ?>
          </td>
          <td>
            <?= $v['host'] !== '' ? 'host: ' . e($v['host']) : '' ?>
            <?php if ($v['purpose'] !== ''): ?>
              <br><span class="muted" style="font-size:12px"><?= e($v['purpose']) ?></span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= e(date('d M · H:i', strtotime($v['time_in']))) ?></td>
          <td>
            <?php if ($onsite): ?>
              <span class="badge badge-out">on site</span>
            <?php else: ?>
              <span class="mono"><?= e(date('d M · H:i', strtotime((string) $v['time_out']))) ?></span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= e($v['registrar'] ?? '—') ?></td>
          <td class="row-actions" style="justify-content:flex-start">
            <?php if ($canRecord): ?>
              <a class="btn btn-ghost btn-xs" href="input.php?person=<?= rawurlencode($v['name']) ?>">Belongings</a>
              <a class="btn btn-ghost btn-xs"
                 href="documents.php?person=<?= rawurlencode($v['name']) ?>&amp;type=<?= e($v['person_type']) ?>">Documents</a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <?php if ($canRecord): ?>
            <form method="post" action="visitors.php">
              <?= csrf_field() ?>
              <input type="hidden" name="ret" value="<?= e($ret) ?>">
              <input type="hidden" name="id" value="<?= $id ?>">
              <?php if ($onsite): ?>
                <button class="btn btn-primary btn-xs" type="submit" name="action" value="checkout">Check out</button>
              <?php else: ?>
                <button class="btn btn-ghost btn-xs" type="submit" name="action" value="back">Undo checkout</button>
              <?php endif; ?>
            </form>
            <?php endif; ?>
            <?php if (is_admin()): ?>
              <form method="post" action="visitors.php" onsubmit="return confirm('Delete this visitor record?')">
                <?= csrf_field() ?>
                <input type="hidden" name="ret" value="<?= e($ret) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn btn-danger btn-xs" type="submit" name="action" value="delete">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}
