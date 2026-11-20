<?php
/**
 * One record and its audit trail.
 *
 * Every log entry, document take-out and visitor is linked to this page from
 * its list. It shows the record as it stands plus every change made to it:
 * who, when, what the values were before and after, and why. A deleted record
 * still has its history here, so a removal never erases the evidence.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/audit.php';
require_login();

$entity = (string) ($_GET['entity'] ?? 'log');
$id     = (int) ($_GET['id'] ?? 0);

$gate = [
    'log'      => ['view_verify', 'view_data'],
    'document' => ['view_documents', 'view_data'],
    'visitor'  => ['view_visitors', 'view_data'],
    'user'     => ['manage_users'],
];

if (!isset($gate[$entity]) || $id <= 0) {
    http_response_code(404);
    echo '<p style="padding:32px;font:14px system-ui">404 — no such record. <a href="index.php">Back</a></p>';
    exit;
}

$allowed = false;
foreach ($gate[$entity] as $capability) {
    if (can($capability)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    echo '<p style="padding:32px;font:14px system-ui">403 — your role may not read this history. <a href="index.php">Back</a></p>';
    exit;
}

$snapshot = audit_snapshot($entity, $id);
$trail    = audit_for($entity, $id, 300);

if (!$snapshot && !$trail) {
    http_response_code(404);
    echo '<p style="padding:32px;font:14px system-ui">404 — no such record. <a href="index.php">Back</a></p>';
    exit;
}

$title = audit_entity_label($entity) . ' #' . $id;

page_header($title, '');
?>
<div class="card">
  <div class="card-head">
    <h2><?= e($title) ?></h2>
    <a class="btn btn-ghost btn-sm" href="<?= e(audit_back_link($entity)) ?>">← Back</a>
  </div>
  <div class="card-body">
    <?php if ($snapshot): ?>
      <dl class="facts">
        <?php foreach ($snapshot as $label => $value): ?>
          <div>
            <dt><?= e((string) $label) ?></dt>
            <dd><?= $value !== '' ? e($value) : '<span class="muted">—</span>' ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    <?php else: ?>
      <p class="hint" style="margin:0">
        This <?= e(strtolower(audit_entity_label($entity))) ?> is no longer in the live tables.
        Its history is kept below, so the deletion itself stays on record.
      </p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Audit trail</h2>
    <span class="filters-summary">
      <?= count($trail) ?> change<?= count($trail) === 1 ? '' : 's' ?> — this history is never edited or deleted<?php
        if (count($trail) >= 300) { echo ' (showing the first 300)'; } ?>
    </span>
  </div>
  <div class="card-body flush">
    <?php render_audit_trail($trail, 'Nothing has changed this record since it was created.'); ?>
  </div>
</div>
<?php page_footer(); ?>
