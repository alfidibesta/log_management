<?php
/**
 * HTML fragments for auto-refresh.
 * The page polls state.php and, when data changes, re-fetches these fragments
 * and swaps them into the containers marked with data-live.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/render.php';
require_login();

$part = $_GET['part'] ?? '';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

switch ($part) {
    case 'open':
        $person = trim((string) ($_GET['person'] ?? ''));
        render_open_records(fetch_open_records($person), $person);
        break;

    case 'verify':
        $q = trim((string) ($_GET['q'] ?? ''));
        render_verify_list(fetch_verify_rows($q), true);
        break;

    case 'log':
        require_admin();
        $type   = ($_GET['type'] ?? '') === 'in' ? 'in' : (($_GET['type'] ?? '') === 'out' ? 'out' : '');
        $from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
        $to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
        $status = ($_GET['status'] ?? '') === '1' ? '1' : (($_GET['status'] ?? '') === '0' ? '0' : '');
        $catId  = (int) ($_GET['category_id'] ?? 0);
        $q      = trim((string) ($_GET['q'] ?? ''));

        [$sql, $args] = log_filter_sql($type, $from, $to, $status, $catId, $q);
        $sql .= " ORDER BY l.logged_at DESC, l.id DESC LIMIT 300";
        $st = db()->prepare($sql);
        $st->execute($args);
        render_log_table($st->fetchAll(), is_admin());
        break;

    default:
        http_response_code(400);
        echo 'unknown fragment';
}
