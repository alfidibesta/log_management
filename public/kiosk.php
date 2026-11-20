<?php
/**
 * Gate-desk kiosk: today's visitors, who is on site and what still needs
 * verifying — on one full-screen page with no sidebar, refreshing itself.
 *
 * Leave it open on the gate desk (press F11 for full screen). It is also the
 * shift-handover screen: the three numbers and the waiting list are the whole
 * handover, and "as of" tells you how fresh it is.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/kiosk.php';
require_login();

$user  = current_user();
$clock = kiosk_clock(); // the database's clock, the one the records are stamped with
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gate desk · Access Factory Management</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="kiosk">

<header class="kiosk-top">
  <div class="kiosk-brand">
    <span class="brand-mark">AFM</span>
    <div>
      <strong>Gate desk</strong>
      <small><?= e(date('l, d M Y')) ?> · <?= e($user['name'] ?? '') ?></small>
    </div>
  </div>

  <div class="kiosk-clock" aria-live="off">
    <span id="kiosk-time" data-server="<?= (int) $clock['epoch'] ?>"><?= e(kiosk_time_of($clock['wall'])) ?></span>
  </div>

  <nav class="kiosk-nav">
    <a class="btn btn-ghost btn-sm" href="visitors.php">Visitors</a>
    <a class="btn btn-ghost btn-sm" href="verify.php">Verify queue</a>
    <a class="btn btn-ghost btn-sm" href="input.php">+ Record entry</a>
    <a class="btn btn-ghost btn-sm" href="index.php">Exit kiosk</a>
  </nav>
</header>

<div class="kiosk-body" data-live="fragment.php?part=kiosk">
  <?php render_kiosk_board(); ?>
</div>

<footer class="kiosk-foot">
  <span>Refreshes automatically · press F11 for full screen</span>
  <span>Access Factory Management — gate log</span>
</footer>

<script src="assets/live.js" defer></script>
<script>
/* The wall clock only: the numbers below refresh themselves through live.js.
   It ticks from the server's clock and is read in UTC, so it always matches
   the times in the lists below — the browser's own timezone may differ. */
(function () {
  var el = document.getElementById('kiosk-time');
  if (!el) return;
  var base  = parseInt(el.getAttribute('data-server'), 10) * 1000;
  var start = Date.now();
  function tick() {
    var d = new Date(base + (Date.now() - start));
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    el.textContent = p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds());
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
</body>
</html>
