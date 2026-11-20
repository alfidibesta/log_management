<?php
/**
 * Serves the attached PDF of one document take-out.
 * Signed-in users only — the file is private and never in the web root.
 *
 * ?dl=1 forces a download; without it the PDF opens in the browser tab.
 */
require_once __DIR__ . '/../src/helpers.php';
require_login();
require_can('view_documents');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare("SELECT file_name, file_mime, file_bytes, file_data FROM documents WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch();

if (!$row || $row['file_data'] === null || $row['file_data'] === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No PDF for this document.');
}

$body = (string) $row['file_data'];
$etag = '"' . md5($id . '-' . strlen($body) . '-' . (string) $row['file_bytes']) . '"';

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    header('ETag: ' . $etag);
    http_response_code(304);
    exit;
}

$name = (string) ($row['file_name'] !== '' ? $row['file_name'] : 'document-' . $id . '.pdf');
$name = (string) preg_replace('/[^\w.\- ]+/u', '_', $name);
$name = $name !== '' ? $name : 'document-' . $id . '.pdf';
$download = isset($_GET['dl']);

header('Content-Type: ' . ($row['file_mime'] !== '' ? $row['file_mime'] : 'application/pdf'));
header('Content-Length: ' . strlen($body));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('ETag: ' . $etag);
echo $body;
