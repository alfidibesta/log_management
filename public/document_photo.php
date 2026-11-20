<?php
/**
 * Serves the compressed scan/photo of one document take-out.
 * Signed-in users only — the image is private and never in the web root.
 */
require_once __DIR__ . '/../src/helpers.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare("SELECT photo, photo_mime, photo_bytes FROM documents WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch();

if (!$row || !isset($row['photo']) || $row['photo'] === null || $row['photo'] === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No photo for this document.');
}

$body = (string) $row['photo'];
$etag = '"' . md5($id . '-' . strlen($body) . '-' . (string) $row['photo_bytes']) . '"';

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    header('ETag: ' . $etag);
    http_response_code(304);
    exit;
}

header('Content-Type: ' . ($row['photo_mime'] !== '' ? $row['photo_mime'] : 'image/jpeg'));
header('Content-Length: ' . strlen($body));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('ETag: ' . $etag);
echo $body;
