<?php
/**
 * Gate-desk kiosk: today's visitors, who is on site and what still needs
 * verifying — on one full-screen page with no sidebar, refreshing itself.
 *
 * Opening it also switches the site into kiosk mode: the three gate pages
 * (Visitors, Record entry, Documents out) render with this same top bar and no
 * sidebar, so the desk can work the shift without the admin menu. "Exit kiosk"
 * puts the normal site back.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/kiosk.php';
require_can('view_kiosk');

if (isset($_GET['exit'])) {
    kiosk_exit();
    header('Location: index.php');
    exit;
}

kiosk_enter();

$user  = current_user();
$clock = kiosk_clock(); // the database's clock, the one the records are stamped with

$links = [];
if (can('view_visitors')) { $links[] = ['visitors.php', 'Visitors']; }
if (can('view_input') && can('record')) { $links[] = ['input.php', '+ Record entry']; }
if (can('view_documents')) { $links[] = ['documents.php', 'Documents out']; }
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
    <?php foreach ($links as [$href, $label]): ?>
      <a class="btn btn-ghost btn-sm" href="<?= e($href) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a class="btn btn-ghost btn-sm" href="kiosk.php?exit=1">Exit kiosk</a>
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
