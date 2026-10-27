/* Auto-refresh: keep every open view in sync.
   Each container carrying data-live is re-fetched when state.php reports a
   new version, so an entry made on the Input page shows up on the Verify
   page (and vice versa) within a few seconds, without a manual reload. */
(function () {
  var lastVersion = null;

  function liveUrl(el) {
    var url = el.getAttribute('data-live') || '';
    // the Input page panel follows whatever name is being typed
    if (el.hasAttribute('data-live-person')) {
      var input = document.querySelector('input[name="person"]');
      var person = input ? input.value : '';
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'person=' + encodeURIComponent(person);
    }
    return url;
  }

  function refreshFragments() {
    var nodes = document.querySelectorAll('[data-live]');
    for (var i = 0; i < nodes.length; i++) {
      (function (el) {
        fetch(liveUrl(el), { credentials: 'same-origin', headers: { 'X-Requested-With': 'live' } })
          .then(function (res) { return res.ok ? res.text() : null; })
          .then(function (html) {
            if (html !== null && el.innerHTML !== html) el.innerHTML = html;
          })
          .catch(function () {});
      })(nodes[i]);
    }
  }

  function tick() {
    fetch('state.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (!data) return;
        if (lastVersion !== null && data.v !== lastVersion) refreshFragments();
        lastVersion = data.v;
        document.dispatchEvent(new CustomEvent('live:state', { detail: data }));
      })
      .catch(function () {});
  }

  setInterval(tick, 2500);
  tick();
})();
