/* The automatic date & time boxes on the gate forms (Record entry, Documents
   out, Visitors). Each one carries the database's epoch, so the display keeps
   counting seconds like the kiosk header instead of freezing at page load.
   It is read in UTC on purpose: the server's timestamps carry no timezone, and
   the browser's own timezone may differ from the gate desk's clock. */
(function () {
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function p(n) { return (n < 10 ? '0' : '') + n; }

  function tick(el) {
    var base = parseInt(el.getAttribute('data-server'), 10) * 1000;
    var face = el.querySelector('.clock-face');
    if (!base || !face) return;
    if (!el.__started) el.__started = Date.now();
    var d = new Date(base + (Date.now() - el.__started));
    face.textContent = p(d.getUTCDate()) + ' ' + MONTHS[d.getUTCMonth()] + ' ' + d.getUTCFullYear()
      + ', ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds());
  }

  function run() {
    var nodes = document.querySelectorAll('.auto-clock[data-server]');
    for (var i = 0; i < nodes.length; i++) tick(nodes[i]);
  }

  run();
  setInterval(run, 1000);
})();
