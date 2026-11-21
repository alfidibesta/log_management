<?php
declare(strict_types=1);

/**
 * Gate-desk kiosk — today's visitors, who is on site and what still needs
 * verifying, on one full-screen page.
 *
 * Shared by kiosk.php and by fragment.php?part=kiosk, so the auto-refresh swap
 * produces byte-identical markup (the same rule as src/render.php).
 */

require_once __DIR__ . '/visitors.php';

/**
 * The clock the whole board runs on: the database's, the same one that stamps
 * logged_at and time_in. PHP's own clock and the browser's can sit in other
 * timezones, and a gate screen must not disagree with the records it shows.
 *
 * @return array{wall:string, epoch:int}
 */
function kiosk_clock(): array
{
    return desk_clock();
}

/** Wall-clock time from a database timestamp, as H:i:s (no timezone shifting). */
function kiosk_time_of(?string $stamp): string
{
    if ($stamp === null || strlen($stamp) < 19) {
        return '—';
    }
    return substr($stamp, 11, 8);
}

/** How long a record has been waiting, as a short label ("8m", "2h 15m", "3d"). */
function kiosk_age(?string $since, int $nowEpoch): string
{
    if ($since === null || $since === '') {
        return '—';
    }
    $then = strtotime($since . ' UTC');
    if ($then === false) {
        return '—';
    }
    $mins = max(0, (int) floor(($nowEpoch - $then) / 60));
    if ($mins < 60) {
        return $mins . 'm';
    }
    $hours = intdiv($mins, 60);
    if ($hours < 24) {
        return sprintf('%dh %02dm', $hours, $mins % 60);
    }
    return intdiv($hours, 24) . 'd';
}

/**
 * Everything the board shows, in one pass of cheap queries.
 *
 * @return array<string, mixed>
 */
function kiosk_data(): array
{
    $pdo   = db();
    $clock = kiosk_clock();

    // on site now — oldest first, so whoever has been in longest reads first
    $onsite = $pdo->query("SELECT id, name, person_type, company, badge_no, time_in
                           FROM visitors WHERE time_out IS NULL
                           ORDER BY time_in ASC")->fetchAll();

    $visitorsToday = (int) $pdo->query("SELECT COUNT(*) FROM visitors
                                         WHERE DATE(time_in) = CURDATE()")->fetchColumn();

    // who is on site now (any day — someone can still be in from last night)
    // plus everyone who checked in today and has since left
    $rows = $pdo->query("SELECT id, name, person_type, company, badge_no, time_in, time_out
                         FROM visitors
                         WHERE time_out IS NULL OR DATE(time_in) = CURDATE()
                         ORDER BY (time_out IS NOT NULL) ASC, time_in ASC LIMIT 50")->fetchAll();

    // the verify queue: oldest wait first, because that is what a shift
    // handover has to answer for
    $pending = $pdo->query("SELECT l.*, c.name AS category_name
                            FROM logs l JOIN categories c ON c.id = l.category_id
                            WHERE l.verified = 0
                            ORDER BY l.logged_at ASC, l.id ASC LIMIT 12")->fetchAll();
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE verified = 0")->fetchColumn();
    $oldestWait   = $pending ? (string) $pending[0]['logged_at'] : null;

    $inToday  = (int) $pdo->query("SELECT COUNT(*) FROM logs
                                    WHERE type = 'in'  AND DATE(logged_at) = CURDATE()")->fetchColumn();
    $outToday = (int) $pdo->query("SELECT COUNT(*) FROM logs
                                    WHERE type = 'out' AND DATE(logged_at) = CURDATE()")->fetchColumn();

    return [
        'clock'         => $clock,
        'onsite'        => $onsite,
        'onsite_count'  => count($onsite),
        'visitors_today' => $visitorsToday,
        'rows'          => $rows,
        'pending'       => $pending,
        'pending_count' => $pendingCount,
        'oldest_wait'   => $oldestWait,
        'in_today'      => $inToday,
        'out_today'     => $outToday,
    ];
}

/** The board itself: three numbers, today's visitors and the verify queue. */
function render_kiosk_board(): void
{
    $d            = kiosk_data();
    $pendingCount = (int) $d['pending_count'];
    $nowEpoch     = (int) $d['clock']['epoch'];
    $nowWall      = (string) $d['clock']['wall'];

    $pendingTone = $pendingCount === 0 ? 'is-clear' : 'is-pending';
    ?>
    <div class="kiosk-stats">
      <div class="ks is-onsite">
        <div class="k"><?= te('On site now') ?></div>
        <div class="v" id="k-onsite"><?= number_format((int) $d['onsite_count']) ?></div>
        <div class="s">
          <?= te((int) $d['onsite_count'] === 1 ? 'visitor or contractor' : 'visitors and contractors') ?>
          <?php if ((int) $d['visitors_today'] > 0): ?>
            · <?= number_format((int) $d['visitors_today']) ?> <?= te('checked in today') ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="ks <?= e($pendingTone) ?>">
        <div class="k"><?= te('Pending verification') ?></div>
        <div class="v" id="k-pending"><?= number_format($pendingCount) ?></div>
        <div class="s">
          <?php if ($pendingCount === 0): ?>
            <?= te('queue is clear') ?>
          <?php else: ?>
            <?= te('oldest waiting') ?> <?= e(kiosk_age((string) $d['oldest_wait'], $nowEpoch)) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="ks">
        <div class="k"><?= te('Gate passes today') ?></div>
        <div class="v"><?= number_format((int) $d['in_today'] + (int) $d['out_today']) ?></div>
        <div class="s">
          <span style="color:var(--green)"><?= number_format((int) $d['in_today']) ?> in</span>
          · <span style="color:var(--amber)"><?= number_format((int) $d['out_today']) ?> out</span>
        </div>
      </div>
    </div>

    <div class="kiosk-cols">
      <div class="card">
        <div class="card-head">
          <h2><?= te('On site & checked in today') ?></h2>
          <span class="filters-summary">
            <?= te('as of') ?> <?= e(kiosk_time_of($nowWall)) ?> · <?= number_format((int) $d['visitors_today']) ?> <?= te('today') ?>
          </span>
        </div>
        <div class="card-body flush">
          <?php if (!$d['rows']): ?>
            <div class="empty"><?= te('Nobody is on site and no visitor has checked in today.') ?>
              <?php if (can('view_visitors')): ?><?= te('Use Visitors to check someone in.') ?><?php endif; ?></div>
          <?php else: ?>
            <table>
              <thead>
                <tr><th><?= te('Name') ?></th><th><?= te('Type') ?></th><th><?= te('Company') ?></th><th>IN</th><th><?= te('Status') ?></th></tr>
              </thead>
              <tbody>
              <?php foreach ($d['rows'] as $v): ?>
                <tr>
                  <td>
                    <strong><a href="history.php?entity=visitor&amp;id=<?= (int) $v['id'] ?>"><?= e($v['name']) ?></a></strong>
                    <?php if ($v['badge_no'] !== ''): ?>
                      <span class="muted mono">· <?= e($v['badge_no']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?= person_type_badge((string) $v['person_type']) ?></td>
                  <td class="muted"><?= $v['company'] !== '' ? e($v['company']) : '—' ?></td>
                  <td class="mono"><?= e(substr(kiosk_time_of((string) $v['time_in']), 0, 5)) ?></td>
                  <td>
                    <?php if ($v['time_out'] === null): ?>
                      <span class="badge badge-in"><?= te('on site') ?></span>
                    <?php else: ?>
                      <span class="badge badge-neutral">out <?= e(substr(kiosk_time_of((string) $v['time_out']), 0, 5)) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h2><?= te('Waiting to verify') ?></h2>
          <?php if (can('view_verify')): ?>
            <a class="btn btn-ghost btn-sm" href="verify.php"><?= te('Open queue') ?></a>
          <?php endif; ?>
        </div>
        <div class="card-body flush">
          <?php if (!$d['pending']): ?>
            <div class="empty"><?= te('Nothing to verify — every record has been checked.') ?></div>
          <?php else: ?>
            <table>
              <thead>
                <tr><th><?= te('Dir') ?></th><th><?= te('Personnel') ?></th><th><?= te('Belongings') ?></th><th class="num"><?= te('Waiting') ?></th></tr>
              </thead>
              <tbody>
              <?php foreach ($d['pending'] as $r): ?>
                <tr>
                  <td>
                    <span class="badge badge-<?= $r['type'] === 'in' ? 'in' : 'out' ?>">
                      <?= $r['type'] === 'in' ? 'IN' : 'OUT' ?></span>
                  </td>
                  <td>
                    <strong><?= e($r['person']) ?></strong>
                    <span class="muted"><?= $r['dept'] !== '' ? '· ' . e($r['dept']) : '' ?></span>
                  </td>
                  <td>
                    <?= e($r['item']) ?>
                    <span class="muted mono" style="font-size:11.5px">· <?= (int) $r['qty'] ?></span>
                  </td>
                  <td class="num mono"><?= e(kiosk_age((string) $r['logged_at'], $nowEpoch)) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php if ($pendingCount > count($d['pending'])): ?>
              <div class="muted" style="padding:10px 14px;font-size:12px">
                + <?= number_format($pendingCount - count($d['pending'])) ?> more waiting<?php if (can('view_verify')): ?> —
                <a href="verify.php">open the whole queue</a><?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
}
