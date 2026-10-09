// Delete the excluded detections for good (Species › Delete Excluded and the species page, owner 2026-10-09): asks first, showing how
// many files of each species will be removed for good, with No as the default. Needs static/species-modal.js.
// sci = a scientific name, or '' for every species.
function wipeDeleted(sci, btn) {
  var base = (location.pathname.indexOf('/scripts/') >= 0 ? '../' : '') + 'play.php?wipe=1&sci=' + encodeURIComponent(sci);
  var x = new XMLHttpRequest();
  x.onload = function () {
    var rows = [];
    try { rows = JSON.parse(this.responseText); } catch (e) { alert(this.status === 401 ? 'Log in first.' : this.responseText); return; }
    if (!rows.length) { alert('Nothing to delete.'); return; }
    var total = rows.reduce(function (s, r) { return s + r.n; }, 0);
    var lines = rows.slice(0, 25).map(function (r) { return '• ' + r.Com_Name + ' (' + r.Sci_Name + '): ' + r.n; });
    if (rows.length > 25) lines.push('… and ' + (rows.length - 25) + ' more species');
    askModal('Delete ' + total + ' excluded detection' + (total === 1 ? '' : 's') + ' for good?',
      'Their audio, spectrogram and database line are removed for good (' + (total * 2) + ' files):\n\n' + lines.join('\n') + '\n\nThis cannot be undone.',
      'Delete', true).then(function (ok) {
        if (!ok) return;
        var y = new XMLHttpRequest();
        y.onload = function () {
          var m = this.responseText.match(/^OK (\d+)/);
          if (!m) { alert('Not deleted: ' + this.responseText); return; }
          if (btn) { btn.textContent = 'Deleted ' + m[1]; btn.disabled = true; }
          setTimeout(function () { location.reload(); }, 800);
        };
        y.open('GET', base + '&confirm=1', true);
        y.send();
      });
  };
  x.open('GET', base, true);
  x.send();
}

// Restore excluded detections (one species, or '' for all): files back to By_Date, lines back to the detections
function restoreExcluded(sci, btn) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var m = this.responseText.match(/^OK (\d+)/);
    if (!m) { alert(this.status === 401 ? 'Log in first.' : 'Not restored: ' + this.responseText); return; }
    if (btn) { btn.textContent = 'Restored ' + m[1]; btn.disabled = true; }
    setTimeout(function () { location.reload(); }, 800);
  };
  x.open('GET', 'play.php?restore=1&sci=' + encodeURIComponent(sci), true);
  x.send();
}
