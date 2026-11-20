/* Document scan: shrink a photo in the browser before it is uploaded.
   A 4 MB phone photo becomes a ~150 KB JPEG, and the small data URL is what
   the form actually sends (the original file input is cleared afterwards).
   Without JavaScript the plain file input is submitted and PHP compresses it
   with GD, so the feature still works. */
(function () {
  var form = document.querySelector('[data-doc-form]');
  if (!form) return;

  var fileInput  = document.getElementById('doc-photo-input');
  var cameraInput = document.getElementById('doc-camera-input');
  var cameraBtn  = document.getElementById('doc-camera');
  var dataInput  = document.getElementById('doc-photo-data');
  var preview    = document.getElementById('doc-preview');
  var previewImg = document.getElementById('doc-preview-img');
  var sizeLabel  = document.getElementById('doc-preview-size');
  var removeBtn  = document.getElementById('doc-remove');

  if (!dataInput) return;

  var MAX_EDGE = 1600;
  var TARGET = 200 * 1024;
  var QUALITIES = [0.78, 0.66, 0.55, 0.45, 0.38];
  var busy = false;

  function dataBytes(url) {
    var comma = url.indexOf(',');
    return comma < 0 ? 0 : Math.round((url.length - comma - 1) * 0.75);
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('unreadable')); };
      img.src = url;
    });
  }

  function encode(img) {
    var w = img.naturalWidth || img.width;
    var h = img.naturalHeight || img.height;
    var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
    var url = '';

    for (var pass = 0; pass < 6; pass++) {
      var cw = Math.max(1, Math.round(w * scale));
      var ch = Math.max(1, Math.round(h * scale));
      var canvas = document.createElement('canvas');
      canvas.width = cw;
      canvas.height = ch;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, cw, ch);
      ctx.drawImage(img, 0, 0, cw, ch);

      for (var i = 0; i < QUALITIES.length; i++) {
        url = canvas.toDataURL('image/jpeg', QUALITIES[i]);
        if (dataBytes(url) <= TARGET) return url;
      }
      if (cw <= 400 && ch <= 400) break;
      scale *= 0.72;
    }
    return url;
  }

  function clear() {
    dataInput.value = '';
    previewImg.removeAttribute('src');
    preview.classList.remove('has-photo');
    sizeLabel.textContent = '';
    if (fileInput) fileInput.value = '';
    if (cameraInput) cameraInput.value = '';
  }

  function handle(file) {
    if (!file) return;
    if (!/^image\//.test(file.type || '')) {
      window.alert('Please choose a photo (JPG or PNG).');
      return;
    }
    busy = true;
    sizeLabel.textContent = 'Compressing…';
    preview.classList.add('has-photo');

    loadImage(file).then(function (img) {
      var url = encode(img);
      if (!url) throw new Error('empty');
      dataInput.value = url;
      previewImg.src = url;
      sizeLabel.textContent = Math.max(1, Math.round(dataBytes(url) / 1024)) + ' KB — ready to save';
      // the compressed copy is sent instead of the original file
      if (fileInput) fileInput.value = '';
      if (cameraInput) cameraInput.value = '';
      busy = false;
    }).catch(function () {
      clear();
      busy = false;
      window.alert('That photo could not be read — try another one.');
    });
  }

  if (cameraBtn && cameraInput) {
    cameraBtn.addEventListener('click', function () { cameraInput.click(); });
  }
  if (fileInput) {
    fileInput.addEventListener('change', function () { handle(fileInput.files && fileInput.files[0]); });
  }
  if (cameraInput) {
    cameraInput.addEventListener('change', function () { handle(cameraInput.files && cameraInput.files[0]); });
  }
  if (removeBtn) {
    removeBtn.addEventListener('click', clear);
  }

  form.addEventListener('submit', function (ev) {
    if (busy) {
      ev.preventDefault();
      window.alert('The scan is still being compressed — press Save again in a moment.');
    }
  });
})();
