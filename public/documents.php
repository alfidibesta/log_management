<?php
/**
 * Documents out — documents leaving the site.
 *
 * Any signed-in user records a document with a fast scan or a photo (the
 * browser compresses it, the server keeps it small). A leader (or an admin)
 * then approves or rejects the request.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/documents.php';
require_once __DIR__ . '/../src/visitors.php';
require_once __DIR__ . '/../src/audit.php';
require_can('record');

$pdo = db();

/* ---------- record / approve / reject / delete ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'create');
    $uid    = (int) (current_user()['id'] ?? 0);

    if ($action === 'create') {
        $person     = mb_substr(trim((string) ($_POST['person'] ?? '')), 0, 120);
        $personType = in_array($_POST['person_type'] ?? '', ['personnel', 'visitor', 'contractor'], true)
            ? (string) $_POST['person_type'] : 'personnel';
        $dept    = mb_substr(trim((string) ($_POST['dept'] ?? '')), 0, 80);
        $title   = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160);
        $docNo   = mb_substr(trim((string) ($_POST['doc_no'] ?? '')), 0, 80);
        $purpose = mb_substr(trim((string) ($_POST['purpose'] ?? '')), 0, 180);
        $copies  = max(1, min(9999, (int) ($_POST['copies'] ?? 1)));
        $note    = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        /* Stamped by the server — the form has no editable time field. */
        $when    = date('Y-m-d H:i:s');

        $err   = [];
        $photo = null;
        if ($person === '') { $err[] = 'Who is taking the document out?'; }
        if ($title === '')  { $err[] = 'Describe the document (name or number).'; }

        try {
            $photo = doc_photo_from_request();
        } catch (RuntimeException $ex) {
            $err[] = $ex->getMessage();
        }

        if ($err) {
            flash(implode(' ', $err), 'err');
        } else {
            $st = $pdo->prepare("INSERT INTO documents
                    (person, person_type, dept, doc_no, title, purpose, copies, taken_at, note,
                     photo, photo_mime, photo_bytes, user_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $person, $personType, $dept, $docNo, $title, $purpose, $copies, $when, $note,
                $photo['blob'] ?? null,
                $photo['mime'] ?? '',
                $photo['bytes'] ?? 0,
                $uid ?: null,
            ]);
            $newId = (int) $pdo->lastInsertId();
            audit_record('document', $newId, 'create', [], audit_snapshot('document', $newId) ?? []);
            flash('Document take-out recorded — waiting for leader approval.'
                . ($photo ? ' Scan saved at ' . number_format($photo['bytes'] / 1024, 0) . ' KB.' : ''));
            header('Location: documents.php');
            exit;
        }
        header('Location: ' . doc_return_target());
        exit;
    }

    if ($action === 'approve' || $action === 'reject') {
        if (!can_approve_documents()) {
            http_response_code(403);
            exit('403 — leader approval required.');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT id, person, user_id FROM documents WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();

        if (!$row) {
            flash('That document request no longer exists.', 'err');
        } elseif ((int) $row['user_id'] === $uid && !is_admin()) {
            flash('You cannot approve a document you recorded yourself — ask another leader or an admin.', 'err');
        } else {
            $note   = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
            $before = audit_snapshot('document', $id);
            $pdo->prepare("UPDATE documents
                           SET status = ?, approved_by = ?, approved_at = NOW(), approval_note = ?
                           WHERE id = ?")
                ->execute([$action === 'approve' ? 'approved' : 'rejected', $uid ?: null, $note, $id]);
            audit_record('document', $id, $action, $before ?? [], audit_snapshot('document', $id) ?? [], $note);
            flash(($action === 'approve' ? 'Approved for' : 'Rejected for') . ' ' . $row['person'] . '.');
        }
        header('Location: ' . doc_return_target());
        exit;
    }

    if ($action === 'reset') {
        if (!can_approve_documents()) {
            http_response_code(403);
            exit('403 — leader approval required.');
        }
        $id     = (int) ($_POST['id'] ?? 0);
        $before = audit_snapshot('document', $id);
        $pdo->prepare("UPDATE documents
                       SET status = 'pending', approved_by = NULL, approved_at = NULL, approval_note = ''
                       WHERE id = ?")->execute([$id]);
        audit_record('document', $id, 'reset', $before ?? [], audit_snapshot('document', $id) ?? [],
            (string) ($_POST['note'] ?? ''));
        flash('Approval undone — the request is pending again.');
        header('Location: ' . doc_return_target());
        exit;
    }

    if ($action === 'delete') {
        require_admin();
        $id     = (int) ($_POST['id'] ?? 0);
        $before = audit_snapshot('document', $id);
        $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
        audit_record('document', $id, 'delete', $before ?? [], [], 'Document take-out deleted');
        flash('Document take-out deleted.');
        header('Location: ' . doc_return_target());
        exit;
    }

    if ($action === 'purge_photos') {
        require_can('manage_data');
        $cut = date('Y-m-d H:i:s', strtotime('-' . DOC_PHOTO_KEEP_DAYS . ' days'));
        $st  = $pdo->prepare("UPDATE documents SET photo = NULL, photo_mime = '', photo_bytes = 0
                              WHERE photo_bytes > 0 AND taken_at < ? LIMIT 200");
        $st->execute([$cut]);
        $removed = (int) $st->rowCount();
        audit_record('document', null, 'purge_photos', [], [
            'Scans removed' => (string) $removed,
            'Older than'    => $cut,
        ], (string) ($_POST['reason'] ?? ''), 'Documents · old scans');
        flash($removed . ' scan(s) older than ' . DOC_PHOTO_KEEP_DAYS
            . ' days removed — the document records stay. Run it again if more remain.');
        header('Location: ' . doc_return_target());
        exit;
    }
}

/* ---------- list + filters ---------- */
$status  = in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? (string) $_GET['status'] : '';
$q       = trim((string) ($_GET['q'] ?? ''));
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$total   = doc_count($status, $q);
$pages   = max(1, (int) ceil($total / $perPage));
$page    = min($page, $pages);
$rows    = fetch_documents($status, $q, '', '', '', $perPage, ($page - 1) * $perPage);
$ret     = (string) ($_SERVER['QUERY_STRING'] ?? '');

$_SESSION['doc_return'] = $ret;

$counts = $pdo->query("SELECT status, COUNT(*) c FROM documents GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$photos = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(photo_bytes),0) b
                       FROM documents WHERE photo_bytes > 0")->fetch();
$photoN   = (int) ($photos['n'] ?? 0);
$photoKb  = $photoN > 0 ? ((int) $photos['b'] / $photoN / 1024) : 0;
$photoMb  = (int) ($photos['b'] ?? 0) / 1048576;

$people = $pdo->query("SELECT DISTINCT person FROM documents ORDER BY person LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
$titles = $pdo->query("SELECT DISTINCT title FROM documents ORDER BY title LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);

$onsite    = onsite_visitors();
$people    = array_values(array_unique(array_merge($people, array_column($onsite, 'name'))));
$companies = array_values(array_unique(array_filter(array_column($onsite, 'company'))));
$prefill   = trim((string) ($_GET['person'] ?? ''));
$preType   = in_array($_GET['type'] ?? '', ['personnel', 'visitor', 'contractor'], true) ? (string) $_GET['type'] : 'personnel';

page_header('Documents out', 'documents.php');
?>
<div class="card">
  <div class="card-body"><?php flow_steps('approve'); ?></div>
</div>
<div class="grid grid-4">
  <div class="stat tone-out">
    <div class="k">Waiting approval</div>
    <div class="v"><?= number_format((int) ($counts['pending'] ?? 0)) ?></div>
    <div class="s"><?= can_approve_documents() ? 'approve below' : 'checked by a leader' ?></div>
  </div>
  <div class="stat tone-in">
    <div class="k">Approved</div>
    <div class="v"><?= number_format((int) ($counts['approved'] ?? 0)) ?></div>
    <div class="s">cleared to leave</div>
  </div>
  <div class="stat">
    <div class="k">Rejected</div>
    <div class="v"><?= number_format((int) ($counts['rejected'] ?? 0)) ?></div>
    <div class="s">sent back</div>
  </div>
  <div class="stat">
    <div class="k">Scans stored</div>
    <div class="v"><?= number_format($photoN) ?></div>
    <div class="s"><?= number_format($photoMb, 1) ?> MB total · avg <?= $photoN > 0 ? number_format($photoKb, 0) : 0 ?> KB each</div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Record a document going out</h2>
    <span class="filters-summary">Fast scan or photo — saved small, then approved by a leader.</span>
  </div>
  <div class="card-body">
    <form method="post" class="grid" style="gap:14px" enctype="multipart/form-data" data-doc-form>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="photo_data" id="doc-photo-data">

      <div class="grid grid-form">
        <label class="field grow">Taken by *
          <input type="text" name="person" list="doc-people" required autofocus maxlength="120"
                 value="<?= e($prefill) ?>" placeholder="Full name">
          <datalist id="doc-people">
            <?php foreach ($people as $p): ?><option><?= e($p) ?></option><?php endforeach; ?>
          </datalist>
        </label>
        <label class="field">Person type
          <select name="person_type">
            <?php foreach (person_types() as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $preType === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">Department / company
          <input type="text" name="dept" list="doc-depts" maxlength="80" placeholder="e.g. Maintenance">
          <datalist id="doc-depts">
            <option>Production</option><option>Maintenance</option><option>Warehouse</option>
            <option>Logistics</option><option>Quality Control</option>
            <?php foreach ($companies as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
          </datalist>
        </label>
        <label class="field grow">Document name * 
          <input type="text" name="title" list="doc-titles" required maxlength="160"
                 placeholder="e.g. Drawing A1 / Invoice pack">
          <datalist id="doc-titles">
            <?php foreach ($titles as $t): ?><option><?= e($t) ?></option><?php endforeach; ?>
          </datalist>
        </label>
        <label class="field">Document no. / ref
          <input type="text" name="doc_no" maxlength="80" placeholder="e.g. PO-2026-118">
        </label>
      </div>

      <div class="grid grid-form">
        <label class="field grow">Destination / purpose
          <input type="text" name="purpose" maxlength="180" placeholder="e.g. Taken to customer site">
        </label>
        <label class="field" style="max-width:120px">Copies / pages
          <input type="number" name="copies" value="1" min="1" max="9999">
        </label>
        <label class="field">Taken at
          <div class="field-auto"><?= date('d M Y, H:i') ?> <span class="muted">· automatic</span></div>
        </label>
        <label class="field grow">Note <span class="muted">(optional)</span>
          <input type="text" name="note" maxlength="255" placeholder="Anything the leader should know">
        </label>
      </div>

      <div class="field" data-doc-photo>
        <span>Scan / photo <span class="muted">(optional — shrunk automatically)</span></span>
        <div class="doc-capture">
          <button type="button" class="btn btn-ghost" id="doc-camera">📷 Fast scan (camera)</button>
          <input type="file" name="photo" id="doc-photo-input" accept="image/*" capture
                 aria-label="Scan or photo of the document">
          <input type="file" id="doc-camera-input" accept="image/*" capture="environment" hidden>
        </div>
        <div class="doc-preview" id="doc-preview">
          <img id="doc-preview-img" alt="Selected scan preview">
          <div class="doc-preview-meta">
            <strong id="doc-preview-size"></strong>
            <small>Compressed in the browser before upload.</small>
            <button type="button" class="btn btn-ghost btn-xs" id="doc-remove">Remove photo</button>
          </div>
        </div>
      </div>

      <div class="grid grid-form">
        <p class="hint grow" style="margin:0">
          Every request stays <strong>pending</strong> until a leader (or an admin) approves it.
          The scan is stored small — about 100–200 KB — not as a full-size phone photo.
        </p>
        <div class="field" style="justify-content:flex-end">
          <button class="btn btn-primary" type="submit">Save document</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <form method="get" class="toolbar">
    <label class="field">Approval
      <select name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
      </select>
    </label>
    <label class="field grow">Search
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="person, document, number, purpose">
    </label>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($status !== '' || $q !== ''): ?><a class="btn btn-ghost" href="documents.php">Reset</a><?php endif; ?>
    <span class="spacer"></span>
    <span class="filters-summary">
      <?= can_approve_documents()
          ? 'As a leader you can approve or reject pending requests here.'
          : 'Approval is done by a leader.' ?>
    </span>
  </form>

  <?php if (can('manage_data')): ?>
    <form method="post" style="padding:9px 14px;border-bottom:1px solid var(--line-2);display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="purge_photos">
      <span class="filters-summary">
        <?= number_format($photoMb, 1) ?> MB of scans stored. Old scans can be removed to save space;
        their document records stay. Only scans older than <?= DOC_PHOTO_KEEP_DAYS ?> days are touched.
      </span>
      <span style="flex:1"></span>
      <button class="btn btn-ghost btn-sm" type="submit"
              onclick="return confirm('Remove scans older than <?= DOC_PHOTO_KEEP_DAYS ?> days? The document records stay.')">
        Free up old scans
      </button>
    </form>
  <?php endif; ?>

  <div class="card-body flush">
    <?php render_document_list($rows, can_approve_documents(), $ret); ?>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <span><?= number_format($total) ?> documents · page <?= $page ?> of <?= $pages ?></span>
      <span class="spacer"></span>
      <?php if ($page > 1): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(doc_page_url($page - 1)) ?>">← Newer</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(doc_page_url($page + 1)) ?>">Older →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script src="assets/document-scan.js" defer></script>
<?php page_footer(); ?>
