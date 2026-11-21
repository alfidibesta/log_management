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
                         COALESCE(UNIX_TIMESTAMP(MAX(verified_at)),0) t,
                         COALESCE(SUM(exception_state = 'open'),0) e,
                         COALESCE(UNIX_TIMESTAMP(MAX(exception_closed_at)),0) x
                  FROM logs")->fetch();
$cats = (int) db()->query("SELECT COUNT(*) FROM categories")->fetchColumn();

// visitors too: the gate kiosk shows them, and a check-in or check-out has to
// reach the screen without anyone pressing reload
$vis = db()->query("SELECT COUNT(*) c,
                           COALESCE(MAX(id),0) m,
                           COALESCE(UNIX_TIMESTAMP(MAX(time_out)),0) t
                    FROM visitors")->fetch();

$version = implode('-', [$r['c'], $r['m'], $r['v'], $r['t'], $r['e'], $r['x'], $cats,
                         $vis['c'], $vis['m'], $vis['t']]);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['v' => $version, 'pending' => (int) $r['c'] - (int) $r['v']]);
