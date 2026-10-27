<?php
/**
 * Tiny JSON endpoint polled by assets/live.js.
 * The version changes whenever any record is added, verified, unverified or
 * deleted (or a category changes), so open views refresh themselves.
 */
require_once __DIR__ . '/../src/helpers.php';
require_login();

$r = db()->query("SELECT COUNT(*) c,
                         COALESCE(MAX(id),0) m,
                         COALESCE(SUM(verified),0) v,
                         COALESCE(UNIX_TIMESTAMP(MAX(verified_at)),0) t
                  FROM logs")->fetch();
$cats = (int) db()->query("SELECT COUNT(*) FROM categories")->fetchColumn();

$version = implode('-', [$r['c'], $r['m'], $r['v'], $r['t'], $cats]);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['v' => $version, 'pending' => (int) $r['c'] - (int) $r['v']]);
