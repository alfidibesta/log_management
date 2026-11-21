<?php
declare(strict_types=1);

/**
 * Exception register — belongings that left the gate and did not come back.
 *
 * A verify remark alone is a note: it tells whoever opens that row that
 * something is off, but nothing stands the case up as an open item. This
 * module turns those remarks into a small state machine on the log row:
 *
 *   none   — ordinary record
 *   open   — verified with a remark that says the belongings did not return
 *   closed — someone confirmed the case is settled (returned, written off, …)
 *
 * Opening happens where the remark is written (Verify), closing happens in
 * the register, and both go through the audit trail.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/audit.php';

/** The preset remarks that mean "this crossing is still open". */
function exception_remarks(): array
{
    return ['Belongings not returned', 'Partially returned', 'Item missing / damaged'];
}

/**
 * Does this remark open an exception? The presets match exactly; a typed
 * remark is also caught when it plainly says the same thing, so an officer
 * who writes it in their own words (or in Indonesian) still lands here.
 */
function exception_opens(string $remark): bool
{
    $r = mb_strtolower(trim($remark));
    if ($r === '') {
        return false;
    }
    foreach (exception_remarks() as $preset) {
        if (mb_strtolower($preset) === $r) {
            return true;
        }
    }
    foreach (['not returned', 'belum kembali', 'belum dikembalikan', 'not come back',
              'missing', 'hilang', 'partially', 'sebagian'] as $needle) {
        if (mb_strpos($r, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function exception_state_label(string $state): string
{
    return ['open' => t('Open'), 'closed' => t('Closed')][$state] ?? '';
}

/** Small badge for a log row: only exceptions get one. */
function exception_state_badge(?string $state): string
{
    if ($state === 'open') {
        return '<span class="badge badge-danger">' . te('not returned') . '</span>';
    }
    if ($state === 'closed') {
        return '<span class="badge badge-neutral">' . te('exception closed') . '</span>';
    }
    return '';
}

/**
 * Exception rows, newest first; open cases before closed ones.
 *
 * $status: open (default) · closed · all
 *
 * @return array<int, array<string, mixed>>
 */
function fetch_exceptions(string $status = 'open', string $q = '', int $limit = 200): array
{
    $where = $status === 'closed' ? "l.exception_state = 'closed'"
           : ($status === 'all' ? "l.exception_state IN ('open','closed')"
                                : "l.exception_state = 'open'");

    $sql = "SELECT l.*, c.name AS category_name,
                   u.name AS recorder, v.name AS verifier, k.name AS closer
            FROM logs l
            JOIN categories c ON c.id = l.category_id
            LEFT JOIN users u ON u.id = l.user_id
            LEFT JOIN users v ON v.id = l.verified_by
            LEFT JOIN users k ON k.id = l.exception_closed_by
            WHERE $where";
    $args = [];
    if ($q !== '') {
        $sql .= " AND (l.person LIKE ? OR l.item LIKE ? OR l.dept LIKE ? OR l.verify_remark LIKE ?)";
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY (l.exception_state = 'open') DESC, l.logged_at DESC, l.id DESC LIMIT "
          . max(1, min(1000, $limit));

    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function exception_open_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM logs WHERE exception_state = 'open'")->fetchColumn();
}

/**
 * Close an open case: who closed it, when, and why. The record itself (and
 * its remark) is left alone — only the exception state moves, so the evidence
 * still says what the officer saw at the gate.
 */
function exception_close(int $id, string $note): bool
{
    $before = audit_snapshot('log', $id);
    if ($before === null) {
        return false;
    }
    $st = db()->prepare("UPDATE logs
                            SET exception_state = 'closed',
                                exception_closed_at = NOW(),
                                exception_closed_by = ?,
                                exception_note = ?
                          WHERE id = ? AND exception_state = 'open'");
    $st->execute([current_user()['id'] ?? null, mb_substr(trim($note), 0, 255), $id]);
    if ($st->rowCount() < 1) {
        return false;
    }
    audit_record('log', $id, 'close_exception', $before, audit_snapshot('log', $id) ?? [], $note);
    return true;
}

/**
 * Re-open a case that was closed by mistake (an admin or verifier action).
 */
function exception_reopen(int $id, string $note = ''): bool
{
    $before = audit_snapshot('log', $id);
    if ($before === null) {
        return false;
    }
    $st = db()->prepare("UPDATE logs
                            SET exception_state = 'open',
                                exception_closed_at = NULL,
                                exception_closed_by = NULL,
                                exception_note = ''
                          WHERE id = ? AND exception_state = 'closed'");
    $st->execute([$id]);
    if ($st->rowCount() < 1) {
        return false;
    }
    audit_record('log', $id, 'reopen_exception', $before, audit_snapshot('log', $id) ?? [], $note);
    return true;
}
