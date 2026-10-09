// Standard tables (owner 2026-10-09): every <table class="stdtable"> gets the same look (CSS .stdtable) and, unless it
// has data-nosort or its own sorting, sorting by a click on a column title (numbers, percentages, dates and text, accents
// ignored). Header row = the first row whose cells are <th>; it stays at the top while the page scrolls (CSS). The
// chosen sorting is remembered in this browser per page and table (or the container of a list that reloads itself).
(function () {
  function key(cell) {
    var v = cell.dataset.sort !== undefined ? cell.dataset.sort : cell.textContent.trim();
    var n = v.replace(/[%,\s×]/g, '').replace(/^—$/, '');
    if (n !== '' && !isNaN(n)) return {n: parseFloat(n)};
    return {s: v.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')};
  }
  function setup(t) {
    if (t.dataset.nosort !== undefined || t.dataset.sortReady) return;
    t.dataset.sortReady = '1';
    var rows = Array.prototype.slice.call(t.rows);
    var hi = rows.findIndex(function (r) { return r.cells.length && r.cells[0].tagName === 'TH'; });
    if (hi < 0) return;
    var head = rows[hi];
    var holder = t.closest('[id]'), id = t.id || (holder ? holder.id : '');
    var view = (location.search.match(/[?&]view=([^&]*)/) || [])[1] || location.pathname;
    var mem = id ? 'stdsort:' + decodeURIComponent(view) + ':' + id : '';
    function load() { try { return JSON.parse(localStorage.getItem(mem) || 'null'); } catch (e) { return null; } }
    Array.prototype.forEach.call(head.cells, function (th, ci) {
      if (th.dataset.nosort !== undefined || !th.textContent.trim()) return;
      th.classList.add('sortable');
      var asc = false;
      th.addEventListener('click', function (e) {
        if (e.target.closest('select,input,button,a')) return;
        asc = (e.restore !== undefined) ? e.restore : !asc;
        if (mem) { try { localStorage.setItem(mem, JSON.stringify({ci: ci, asc: asc})); } catch (err) {} }
        Array.prototype.forEach.call(head.cells, function (o) { o.classList.remove('asc', 'desc'); });
        th.classList.add(asc ? 'asc' : 'desc');
        var body = Array.prototype.slice.call(t.rows, hi + 1).filter(function (r) { return !r.classList.contains('nosortrow'); });
        var tail = Array.prototype.slice.call(t.rows, hi + 1).filter(function (r) { return r.classList.contains('nosortrow'); });
        body.sort(function (a, b) {
          var x = key(a.cells[ci] || a.cells[0]), y = key(b.cells[ci] || b.cells[0]), c;
          if (x.n !== undefined && y.n !== undefined) c = x.n - y.n;
          else if (x.n !== undefined) c = -1; else if (y.n !== undefined) c = 1;
          else c = x.s.localeCompare(y.s);
          return asc ? c : -c;
        });
        var parent = head.parentNode;
        body.concat(tail).forEach(function (r) { parent.appendChild(r); });
      });
    });
    var saved = mem ? load() : null;
    if (saved && head.cells[saved.ci]) {
      var ev = new MouseEvent('click'); ev.restore = saved.asc;
      head.cells[saved.ci].dispatchEvent(ev);
    }
  }
  window.stdTables = function (root) { (root || document).querySelectorAll('table.stdtable').forEach(setup); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { stdTables(); }); else stdTables();
})();
