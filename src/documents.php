<?php
declare(strict_types=1);

/**
 * Document take-out requests (dokumen keluar).
 *
 * Any signed-in user records a document leaving the site with a fast camera
 * scan or a photo of the paper. The picture is shrunk in the browser
 * (assets/document-scan.js) and re-compressed here with GD, so a stored scan
 * stays around 100-200 KB. Each request then waits for a leader — or an
 * admin — to approve or reject it.
 */

/** Every stored scan should end up at or below this size. */
const DOC_PHOTO_TARGET_BYTES = 200 * 1024;
/** Longest edge kept when re-encoding: phone photos are downscaled to this. */
const DOC_PHOTO_MAX_DIM = 1600;
/** Hard cap for the un-compressed fallback upload path. */
const DOC_PHOTO_MAX_INPUT = 8 * 1024 * 1024;
/** Scans older than this can be removed by an admin; the record stays. */
const DOC_PHOTO_KEEP_DAYS = 90;
/** JPEG quality ladder — the first step that fits the target wins. */
const DOC_PHOTO_QUALITIES = [78, 66, 55, 45, 38];

/**
 * Optional photo of the request: a browser-compressed data URL (photo_data)
 * or a plain file upload (photo), turned into a small JPEG.
 *
 * @return array{blob:string,mime:string,bytes:int}|null null when no photo was sent
 * @throws RuntimeException when the image is unreadable or much too large
 */
function doc_photo_from_request(): ?array
{
    $bytes = doc_photo_bytes_from_request();
    if ($bytes === null) {
        return null;
    }
    [$blob, $mime] = doc_shrink_image($bytes);
    return ['blob' => $blob, 'mime' => $mime, 'bytes' => strlen($blob)];
}

/** Raw image bytes from the request, or null when no photo was attached. */
function doc_photo_bytes_from_request(): ?string
{
    // 1) Browser-compressed data URL (the normal path, tiny payload).
    $data = trim((string) ($_POST['photo_data'] ?? ''));
    if ($data !== '') {
        if (strlen($data) > (int) (DOC_PHOTO_MAX_INPUT * 1.4)) {
            throw new RuntimeException('That scan is too large — take the photo again.');
        }
        if (!preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $data, $m)) {
            throw new RuntimeException('That scan could not be read — take the photo again.');
        }
        $bin = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);
        if ($bin === false || $bin === '') {
            throw new RuntimeException('That scan could not be read — take the photo again.');
        }
        return $bin;
    }

    // 2) Plain upload — no JavaScript, or a desktop file picker.
    $file = $_FILES['photo'] ?? null;
    if (!is_array($file)) {
        return null;
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed (error ' . $err . ') — try again.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Photo upload failed — try again.');
    }
    if ((int) ($file['size'] ?? 0) > DOC_PHOTO_MAX_INPUT) {
        throw new RuntimeException('Photo is larger than 8 MB — take it again.');
    }
    $bytes = file_get_contents($tmp);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Photo upload failed — try again.');
    }
    return $bytes;
}

/**
 * Downscale + re-encode an image to a small JPEG (GD).
 *
 * @return array{0:string,1:string} [bytes, mime]
 * @throws RuntimeException
 */
function doc_shrink_image(string $bytes): array
{
    $info = @getimagesizefromstring($bytes);
    if (!$info || (int) $info[0] < 1 || (int) $info[1] < 1) {
        throw new RuntimeException('That file is not a readable image — take the photo again (JPG/PNG).');
    }
    $w0   = (int) $info[0];
    $h0   = (int) $info[1];
    $mime = (string) ($info['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Only JPG, PNG or WEBP photos are accepted.');
    }

    $canEncode = function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    $src       = $canEncode ? @imagecreatefromstring($bytes) : false;
    if (!$src) {
        // No GD (or a GD build without JPEG): keep the upload only when it is
        // already small — this site must never store a multi-megabyte photo.
        if ($mime === 'image/jpeg' && strlen($bytes) <= (int) (DOC_PHOTO_TARGET_BYTES * 3)) {
            return [$bytes, $mime];
        }
        throw new RuntimeException('This server cannot compress photos — enable PHP\'s GD extension (with JPEG), or upload a photo under 600 KB.');
    }

    $best  = null;
    $scale = min(1.0, DOC_PHOTO_MAX_DIM / max($w0, $h0));

    for ($pass = 0; $pass < 7; $pass++) {
        $nw = max(1, (int) round($w0 * $scale));
        $nh = max(1, (int) round($h0 * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        if (!$dst) {
            break;
        }
        // flatten PNG/WebP transparency onto white so JPEG looks right
        imagefilledrectangle($dst, 0, 0, $nw, $nh, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w0, $h0);

        $passBest = null;
        foreach (DOC_PHOTO_QUALITIES as $q) {
            ob_start();
            imagejpeg($dst, null, $q);
            $out = (string) ob_get_clean();
            if ($out === '') {
                continue;
            }
            if ($passBest === null || strlen($out) < strlen($passBest)) {
                $passBest = $out;
            }
            if (strlen($out) <= DOC_PHOTO_TARGET_BYTES) {
                $passBest = $out;
                break;
            }
        }
        imagedestroy($dst);

        if ($passBest !== null && ($best === null || strlen($passBest) < strlen($best))) {
            $best = $passBest;
        }
        if ($passBest !== null && strlen($passBest) <= DOC_PHOTO_TARGET_BYTES) {
            break;
        }
        if ($nw <= 360 && $nh <= 360) {
            break;
        }
        $scale *= 0.72;
    }
    imagedestroy($src);

    if ($best === null || $best === '') {
        throw new RuntimeException('The photo could not be compressed — take it again.');
    }
    // keep the original when it is already smaller than our re-encode
    if ($mime === 'image/jpeg' && strlen($bytes) < strlen($best)) {
        return [$bytes, 'image/jpeg'];
    }
    return [$best, 'image/jpeg'];
}

/* ---------------------------------------------------------------- queries */

/** @return array{0:string,1:array} WHERE clause + args shared by list, count and export */
function doc_where(string $status, string $q, string $from = '', string $to = '', string $personType = ''): array
{
    $sql  = ' WHERE 1';
    $args = [];
    if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $sql   .= ' AND d.status = ?';
        $args[] = $status;
    }
    if ($from !== '') {
        $sql   .= ' AND d.taken_at >= ?';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $sql   .= ' AND d.taken_at <= ?';
        $args[] = $to . ' 23:59:59';
    }
    if (in_array($personType, ['personnel', 'visitor', 'contractor'], true)) {
        $sql   .= ' AND d.person_type = ?';
        $args[] = $personType;
    }
    if ($q !== '') {
        $sql .= ' AND (d.person LIKE ? OR d.title LIKE ? OR d.doc_no LIKE ? OR d.purpose LIKE ? OR d.dept LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    return [$sql, $args];
}

/** @return array{0:string,1:array} sql + args for the document list (and Excel export) */
function doc_filter_sql(string $status, string $q, string $from = '', string $to = '', string $personType = ''): array
{
    [$where, $args] = doc_where($status, $q, $from, $to, $personType);
    $sql = "SELECT d.id, d.person, d.person_type, d.dept, d.doc_no, d.title, d.purpose, d.copies,
                   d.taken_at, d.note, d.photo_bytes, d.photo_mime, d.status,
                   d.approval_note, d.approved_at, d.user_id,
                   u.name AS requester, a.name AS approver
            FROM documents d
            LEFT JOIN users u ON u.id = d.user_id
            LEFT JOIN users a ON a.id = d.approved_by" . $where;
    return [$sql, $args];
}

/** How many documents match the filters (used for paging). */
function doc_count(string $status, string $q, string $from = '', string $to = '', string $personType = ''): int
{
    [$where, $args] = doc_where($status, $q, $from, $to, $personType);
    $st = db()->prepare('SELECT COUNT(*) FROM documents d' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

function fetch_documents(string $status, string $q, string $from = '', string $to = '', string $personType = '', int $limit = 25, int $offset = 0): array
{
    [$sql, $args] = doc_filter_sql($status, $q, $from, $to, $personType);
    $sql .= " ORDER BY d.status = 'pending' DESC, d.taken_at DESC, d.id DESC LIMIT "
          . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Link to another page of the document list, keeping the filters. */
function doc_page_url(int $page): string
{
    $qs = array_filter([
        'status' => (string) ($_GET['status'] ?? ''),
        'q'      => (string) ($_GET['q'] ?? ''),
        'page'   => $page > 1 ? (string) $page : '',
    ], static fn (string $v): bool => $v !== '');
    return 'documents.php' . ($qs ? '?' . http_build_query($qs) : '');
}

/** Where to go back to after an action, keeping the list filters. */
function doc_return_target(): string
{
    $qs = (string) ($_POST['ret'] ?? $_SESSION['doc_return'] ?? '');
    return 'documents.php' . ($qs !== '' ? '?' . $qs : '');
}

/* ---------------------------------------------------------------- renders */

/** Document take-out table: scan thumbnail, status and approval actions. */
function render_document_list(array $rows, bool $canApprove, string $ret = '', bool $actions = true): void
{
    if (!$rows) {
        echo '<div class="empty">No document take-outs match these filters. Use the form above to record one.</div>';
        return;
    }
    $uid = (int) (current_user()['id'] ?? 0);
    ?>
    <table>
      <thead>
        <tr>
          <th>Scan / photo</th><th>Taken</th><th>Personnel</th><th>Document</th>
          <th class="num">Copies</th><th>Approval</th><th>Recorded by</th><?php if ($actions): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int) $r['id']; $own = (int) $r['user_id'] === $uid; ?>
        <tr>
          <td>
            <?php if ((int) $r['photo_bytes'] > 0): ?>
              <a class="doc-photo-link" href="document_photo.php?id=<?= $id ?>" target="_blank" rel="noopener"
                 title="Open the scan — <?= number_format((int) $r['photo_bytes']) ?> bytes">
                <img class="doc-photo" src="document_photo.php?id=<?= $id ?>" loading="lazy"
                     alt="Scan of <?= e($r['title']) ?>">
              </a>
            <?php else: ?>
              <span class="doc-photo doc-photo-none">no photo</span>
            <?php endif; ?>
          </td>
          <td class="mono"><a href="history.php?entity=document&amp;id=<?= $id ?>"
                 title="Open this take-out and its audit trail"><?= e(date('d M · H:i', strtotime($r['taken_at']))) ?></a></td>
          <td>
            <strong><?= e($r['person']) ?></strong>
            <?= person_type_badge($r['person_type'] ?? 'personnel') ?>
            <?php if ($r['dept'] !== ''): ?>
              <br><span class="muted" style="font-size:12px"><?= e($r['dept']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <strong><?= e($r['title']) ?></strong>
            <?php if ($r['doc_no'] !== ''): ?>
              <span class="muted mono">· <?= e($r['doc_no']) ?></span>
            <?php endif; ?>
            <?php if ($r['purpose'] !== ''): ?>
              <br><span class="muted" style="font-size:12px"><?= e($r['purpose']) ?></span>
            <?php endif; ?>
            <?php if ($r['note'] !== ''): ?>
              <br><span class="muted" style="font-size:12px"><?= e($r['note']) ?></span>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int) $r['copies'] ?></td>
          <td>
            <?php if ($r['status'] === 'approved'): ?>
              <span class="badge badge-in">✓ approved</span>
            <?php elseif ($r['status'] === 'rejected'): ?>
              <span class="badge badge-danger">✕ rejected</span>
            <?php else: ?>
              <span class="badge badge-out">pending approval</span>
            <?php endif; ?>
            <?php if (($r['approver'] ?? '') !== '' && $r['approved_at']): ?>
              <br><span class="muted" style="font-size:11.5px">
                <?= e($r['approver']) ?> · <?= e(date('d M H:i', strtotime((string) $r['approved_at']))) ?>
              </span>
            <?php endif; ?>
            <?php if ($r['approval_note'] !== ''): ?>
              <br><span class="muted" style="font-size:11.5px">“<?= e($r['approval_note']) ?>”</span>
            <?php endif; ?>
            <?php if ((int) $r['photo_bytes'] > 0): ?>
              <br><span class="muted" style="font-size:11px"><?= number_format(((int) $r['photo_bytes']) / 1024, 0) ?> KB saved</span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= e($r['requester'] ?? '—') ?></td>
          <?php if ($actions): ?>
          <td class="row-actions">
            <?php if ($canApprove && $r['status'] === 'pending' && !($own && !is_admin())): ?>
              <form method="post" action="documents.php" class="doc-approve">
                <?= csrf_field() ?>
                <input type="hidden" name="ret" value="<?= e($ret) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input class="remark-input" type="text" name="note" maxlength="255"
                       placeholder="Approval note (optional)" aria-label="Approval note">
                <button class="btn btn-primary btn-xs" type="submit" name="action" value="approve">✓ Approve</button>
                <button class="btn btn-danger btn-xs" type="submit" name="action" value="reject">Reject</button>
              </form>
            <?php elseif ($canApprove && $r['status'] !== 'pending'): ?>
              <form method="post" action="documents.php" class="doc-approve">
                <?= csrf_field() ?>
                <input type="hidden" name="ret" value="<?= e($ret) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn btn-ghost btn-xs" type="submit" name="action" value="reset">Undo</button>
              </form>
            <?php elseif ($r['status'] === 'pending' && $own): ?>
              <span class="muted" style="font-size:11.5px">waiting for a leader</span>
            <?php endif; ?>
            <?php if (is_admin()): ?>
              <form method="post" action="documents.php" onsubmit="return confirm('Delete this document take-out?')">
                <?= csrf_field() ?>
                <input type="hidden" name="ret" value="<?= e($ret) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn btn-danger btn-xs" type="submit" name="action" value="delete">Delete</button>
              </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}
