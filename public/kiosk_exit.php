<?php
/**
 * Leaving the gate kiosk.
 *
 * The desk screen is meant to stay open on the wall, so the way back into the
 * normal site is a PIN rather than a click: whoever passes the screen cannot
 * wander into the admin menu. A wrong PIN returns to the page it came from and
 * leaves kiosk mode untouched.
 */
require_once __DIR__ . '/../src/helpers.php';
require_login();

// Nothing to leave: answer a stray POST (a stale tab, a reload) with the site
// instead of a PIN prompt and a "Left the kiosk" toast for a mode you are not in.
if (!kiosk_mode() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php');
    exit;
}
verify_csrf();

if (!kiosk_exit_allowed((string) ($_POST['pin'] ?? ''))) {
    flash('Wrong PIN — the gate kiosk stays open.', 'err');
    header('Location: ' . kiosk_exit_return());
    exit;
}

kiosk_exit();
flash('Left the gate kiosk.');
header('Location: index.php');
exit;
