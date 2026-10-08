// Daily chart: every species row (bar + hours) becomes a link to its species page. daily_plot.py writes, next to
// Combo-<date>.png, Combo-<date>.json with each row as fractions of the image (owner 2026-10-08).
function chartRowLinks(img) {
  var wrap = img.parentNode;
  if (!wrap || !wrap.classList.contains('chartwrap')) return;
  var src = img.getAttribute('src').split('?')[0];
  var x = new XMLHttpRequest();
  x.onload = function () {
    var rows;
    try { rows = JSON.parse(this.responseText); } catch (e) { return; }
    wrap.querySelectorAll('a.chartrow').forEach(function (a) { a.remove(); });
    rows.forEach(function (r) {
      var a = document.createElement('a');
      a.className = 'chartrow';
      a.href = 'views.php?view=Bird&sci=' + encodeURIComponent(r.sci);
      a.title = 'Open the species page: ' + r.com;
      a.dataset.label = r.com + ' \u2192 species page';
      a.style.left = (r.left * 100) + '%';
      a.style.width = ((r.right - r.left) * 100) + '%';
      a.style.top = (r.top * 100) + '%';
      a.style.height = ((r.bottom - r.top) * 100) + '%';
      wrap.appendChild(a);
    });
  };
  x.open('GET', src.replace(/\.png$/, '.json') + '?nocache=' + Date.now(), true);
  x.send();
}
