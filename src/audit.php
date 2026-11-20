<?php
declare(strict_types=1);

/**
 * Audit trail — who changed what, when and why.
 *
 * A gate log can be used as evidence, so every action that changes a log,
 * document or visitor record (and every account / role-capability change) is
 * written to `audit_log` with the operator, the changed fields before and
 * after, a reason and a timestamp. Rows are only ever inserted: nothing in the
 * app edits or deletes them.
 *
 * Values are stored as human-readable labels (category name, person name, …)
 * and the operator's name/username is copied into the row, so the history
 * stays readable after a rename, a role change or a deleted account.
 */

require_once __DIR__ . '/helpers.php';

/** @return array<string, string> audit entity key => label */
function audit_entity_labels(): array
{
    return [
        'log'       => 'Log entry',
        'document'  => 'Document take-out',
        'visitor'   => 'Visitor',
        'user'      => 'User account',
        'role_caps' => 'Role capabilities',
    ];
}

function audit_entity_label(string $entity): string
{
    return audit_entity_labels()[$entity] ?? ucfirst($entity);
}

/** Plain-English label for the action that was recorded. */
function audit_action_label(string $action): string
{
    return [
        'create'             => 'Recorded',
        'verify'             => 'Verified',
        'unverify'           => 'Verification cleared',
        'delete'             => 'Deleted',
        'import'             => 'Imported',
        'approve'            => 'Approved',
        'reject'             => 'Rejected',
        'reset'              => 'Approval undone',
        'purge_photos'       => 'Old scans removed',
        'checkin'            => 'Checked in',
        'checkout'           => 'Checked out',
        'back'               => 'Check-out undone',
        'user_create'        => 'User created',
        'role'               => 'Role changed',
        'toggle'             => 'Account status changed',
        'permissions'        => 'Capabilities changed',
        'permissions_reset'  => 'Capabilities reset',
    ][$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/** Badge tone for an action: in = confirmed, out = attention, danger = removed. */
function audit_action_tone(string $action): string
{
    if (in_array($action, ['verify', 'approve', 'create', 'checkin', 'user_create'], true)) {
        return 'in';
    }
    if (in_array($action, ['delete', 'reject', 'unverify', 'permissions_reset'], true)) {
        return 'danger';
    }
    if (in_array($action, ['reset', 'back', 'purge_photos', 'toggle'], true)) {
        return 'out';
    }
    return 'accent';
}

/**
 * A record as readable field => value pairs, used as the before/after
 * snapshot. Returns null when the record does not exist (any more).
 *
 * Passwords are never part of a snapshot.
 *
 * @return array<string, string>|null
 */
function audit_snapshot(string $entity, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $pdo = db();

    if ($entity === 'log') {
        $st = $pdo->prepare("SELECT l.*, c.name AS category_name,
                                    u.name AS recorder, v.name AS verifier
                             FROM logs l
                             JOIN categories c ON c.id = l.category_id
                             LEFT JOIN users u ON u.id = l.user_id
                             LEFT JOIN users v ON v.id = l.verified_by
                             WHERE l.id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        return [
            'Person'        => (string) $r['person'],
            'Type'          => person_type_label((string) $r['person_type']),
            'Department'    => (string) $r['dept'],
            'Category'      => (string) $r['category_name'],
            'Item'          => (string) $r['item'],
            'Qty'           => (string) (int) $r['qty'],
            'Direction'     => strtoupper((string) $r['type']),
            'Gate'          => (string) $r['gate'],
            'Note'          => (string) $r['note'],
            'Logged at'     => (string) $r['logged_at'],
            'Recorded by'   => (string) ($r['recorder'] ?? '—'),
            'Status'        => $r['verified'] ? 'Verified' : 'Pending',
            'Verified by'   => (string) ($r['verifier'] ?? '—'),
            'Verified at'   => (string) ($r['verified_at'] ?? '—'),
            'Verify remark' => (string) ($r['verify_remark'] ?? ''),
        ];
    }

    if ($entity === 'document') {
        $st = $pdo->prepare("SELECT d.*, u.name AS requester, a.name AS approver
                             FROM documents d
                             LEFT JOIN users u ON u.id = d.user_id
                             LEFT JOIN users a ON a.id = d.approved_by
                             WHERE d.id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $bytes = (int) $r['photo_bytes'];
        return [
            'Person'        => (string) $r['person'],
            'Type'          => person_type_label((string) $r['person_type']),
            'Department'    => (string) $r['dept'],
            'Document no'   => (string) $r['doc_no'],
            'Document'      => (string) $r['title'],
            'Purpose'       => (string) $r['purpose'],
            'Copies'        => (string) (int) $r['copies'],
            'Taken at'      => (string) $r['taken_at'],
            'Note'          => (string) $r['note'],
            'Status'        => ucfirst((string) $r['status']),
            'Approved by'   => (string) ($r['approver'] ?? '—'),
            'Approved at'   => (string) ($r['approved_at'] ?? '—'),
            'Approval note' => (string) $r['approval_note'],
            'Scan'          => $bytes > 0 ? number_format($bytes / 1024, 0) . ' KB' : 'none',
            'Recorded by'   => (string) ($r['requester'] ?? '—'),
        ];
    }

    if ($entity === 'visitor') {
        $st = $pdo->prepare("SELECT v.*, u.name AS registrar
                             FROM visitors v
                             LEFT JOIN users u ON u.id = v.user_id
                             WHERE v.id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        return [
            'Name'        => (string) $r['name'],
            'Type'        => person_type_label((string) $r['person_type']),
            'Company'     => (string) $r['company'],
            'ID card'     => (string) $r['id_no'],
            'Phone'       => (string) $r['phone'],
            'Host'        => (string) $r['host'],
            'Purpose'     => (string) $r['purpose'],
            'Badge'       => (string) $r['badge_no'],
            'Checked in'  => (string) $r['time_in'],
            'Checked out' => $r['time_out'] !== null ? (string) $r['time_out'] : 'on site',
            'Note'        => (string) $r['note'],
            'Recorded by' => (string) ($r['registrar'] ?? '—'),
        ];
    }

    if ($entity === 'user') {
        $st = $pdo->prepare("SELECT id, username, name, role, active FROM users WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        return [
            'Username' => (string) $r['username'],
            'Name'     => (string) $r['name'],
            'Role'     => role_label((string) $r['role']),
            'Status'   => $r['active'] ? 'active' : 'disabled',
        ];
    }

    return null;
}

/**
 * Write one audit row.
 *
 * Only fields that actually changed are stored, split into a before map and an
 * after map. A no-op save (same values) writes nothing and returns false.
 *
 * @param array<string, string> $before snapshot before the change
 * @param array<string, string> $after  snapshot after the change
 */
function audit_record(
    string $entity,
    ?int $entityId,
    string $action,
    array $before = [],
    array $after = [],
    string $reason = '',
    string $target = ''
): bool {
    $old = [];
    $new = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
        $bv = $before[$key] ?? null;
        $av = $after[$key] ?? null;
        $same = $bv === null ? $av === null : ($av !== null && (string) $bv === (string) $av);
        if ($same) {
            continue;
        }
        if ($bv !== null) {
            $old[(string) $key] = (string) $bv;
        }
        if ($av !== null) {
            $new[(string) $key] = (string) $av;
        }
    }
    if (!$old && !$new && ($before || $after)) {
        return false; // nothing changed — do not clutter the trail
    }

    $user     = current_user();
    $target   = $target !== '' ? $target : audit_entity_label($entity)
              . ($entityId !== null && $entityId > 0 ? ' #' . $entityId : '');
    $flags    = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    try {
        db()->prepare("INSERT INTO audit_log
                (entity, entity_id, target, action, reason, before_json, after_json,
                 user_id, username, user_name, ip, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $entity,
                $entityId !== null && $entityId > 0 ? $entityId : null,
                mb_substr($target, 0, 160),
                $action,
                mb_substr(trim($reason), 0, 255),
                $old ? json_encode($old, $flags) : null,
                $new ? json_encode($new, $flags) : null,
                isset($user['id']) ? (int) $user['id'] : null,
                (string) ($user['username'] ?? ''),
                (string) ($user['name'] ?? ''),
                mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                date('Y-m-d H:i:s'),
            ]);
    } catch (Throwable $e) {
        // Recording history must never break the action the user asked for.
        error_log('audit_log write failed: ' . $e->getMessage());
        return false;
    }

    return true;
}

/**
 * Audit rows for one record, oldest first (a narrative).
 *
 * @return array<int, array<string, mixed>>
 */
function audit_for(string $entity, ?int $entityId, int $limit = 300): array
{
    $sql  = 'SELECT * FROM audit_log WHERE entity = ?';
    $args = [$entity];
    if ($entityId !== null && $entityId > 0) {
        $sql   .= ' AND entity_id = ?';
        $args[] = $entityId;
    } else {
        $sql .= ' AND entity_id IS NULL';
    }
    $sql .= ' ORDER BY created_at ASC, id ASC LIMIT ' . max(1, min(500, $limit));
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Most recent audit rows across a set of entities (newest first). */
function audit_feed(array $entities, int $limit = 40): array
{
    $entities = array_values(array_intersect(array_keys(audit_entity_labels()), $entities));
    if (!$entities) {
        return [];
    }
    $in = implode(',', array_fill(0, count($entities), '?'));
    $st = db()->prepare("SELECT * FROM audit_log WHERE entity IN ($in)
                         ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute($entities);
    return $st->fetchAll();
}

/** Where the "back" button on a record's history page should go. */
function audit_back_link(string $entity): string
{
    return [
        'log'       => can('manage_data') ? 'log.php' : 'verify.php',
        'document'  => 'documents.php',
        'visitor'   => 'visitors.php',
        'user'      => 'users.php',
        'role_caps' => 'users.php',
    ][$entity] ?? 'index.php';
}

/**
 * The audit table: when, who, what, why and the changed fields.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function render_audit_trail(array $rows, string $empty = 'No changes recorded yet.', bool $showTarget = false): void
{
    if (!$rows) {
        echo '<div class="empty">' . e($empty) . '</div>';
        return;
    }
    ?>
    <ol class="trail">
    <?php foreach ($rows as $r):
        $before = json_decode((string) ($r['before_json'] ?? ''), true);
        $after  = json_decode((string) ($r['after_json'] ?? ''), true);
        $before = is_array($before) ? $before : [];
        $after  = is_array($after) ? $after : [];
        $keys   = array_unique(array_merge(array_keys($before), array_keys($after)));
        $who    = (string) ($r['user_name'] !== '' ? $r['user_name'] : $r['username']);
    ?>
      <li class="trail-item">
        <div class="trail-head">
          <span class="badge badge-<?= e(audit_action_tone((string) $r['action'])) ?>">
            <?= e(audit_action_label((string) $r['action'])) ?>
          </span>
          <span class="trail-by">
            <strong><?= e($who !== '' ? $who : '—') ?></strong>
            <?php if ($r['user_name'] !== '' && $r['username'] !== ''): ?>
              <span class="muted mono trail-login">(<?= e($r['username']) ?>)</span>
            <?php endif; ?>
          </span>
          <span class="trail-when mono"><?= e(date('Y-m-d H:i', strtotime((string) $r['created_at']))) ?></span>
          <?php if ($showTarget && (string) $r['target'] !== ''): ?>
            <span class="trail-target"><?= e((string) $r['target']) ?></span>
          <?php endif; ?>
          <?php if (($r['ip'] ?? '') !== ''): ?>
            <span class="trail-ip mono"><?= e((string) $r['ip']) ?></span>
          <?php endif; ?>
        </div>
        <?php if ((string) $r['reason'] !== ''): ?>
          <p class="trail-reason"><?= e((string) $r['reason']) ?></p>
        <?php endif; ?>
        <?php if ($keys): ?>
          <dl class="trail-diff">
            <?php foreach ($keys as $k):
                $was = isset($before[$k]) ? (string) $before[$k] : '';
                $now = isset($after[$k]) ? (string) $after[$k] : '';
            ?>
              <div class="ad-row">
                <dt class="ad-key"><?= e((string) $k) ?></dt>
                <dd class="ad-val">
                  <span class="ad-before"><?= $was !== '' ? e($was) : '<span class="muted">—</span>' ?></span>
                  <span class="ad-arrow">→</span>
                  <span class="ad-after"><?= $now !== '' ? e($now) : '<span class="muted">—</span>' ?></span>
                </dd>
              </div>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ol>
    <?php
}
