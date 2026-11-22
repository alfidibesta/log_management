/* The Full screen button in the kiosk bar: fills the wall display without the
   officer hunting for F11, and says how to come back out. The label swaps with
   the browser's own full-screen state, so the button never lies. */
(function () {
  var btn = document.querySelector('[data-fullscreen]');
  if (!btn) return;

  var doc = document;

  function sync() {
    var on = !!doc.fullscreenElement;
    btn.textContent = on ? btn.getAttribute('data-exit-full') : btn.getAttribute('data-full');
  }

  btn.addEventListener('click', function () {
    if (doc.fullscreenElement) {
      if (doc.exitFullscreen) doc.exitFullscreen();
    } else if (doc.documentElement.requestFullscreen) {
      doc.documentElement.requestFullscreen();
    }
  });

  doc.addEventListener('fullscreenchange', sync);
  sync();
})();
