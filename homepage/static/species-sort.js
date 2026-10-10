// Species tables, one behaviour everywhere (owner 2026-10-10: By Hour, By Week, Species Pages, Curation):
//   <span class="hmsort" data-table="TABLE_ID" data-store="KEY"><button data-sort="n|tax|az">…</button>…</span>
//     Count = data-n (most first), Taxonomy = data-tax (eBird/Clements field-guide order, common.php taxon_order()),
//     A–Z = the names shown (follows the Common / Scientific / English picklist), clicked again Z–A (the label shows
//     the current direction); remembered per page in this browser
//   <input class="spfilter" data-table="TABLE_ID">  filters the rows by data-q (names, accents ignored)
// Rows carry data-n, data-tax and data-q; rows with class "chart" (totals) stay at the bottom.
(function () {
  function plain(s) { return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function rowsOf(t) { return Array.prototype.slice.call(t.querySelectorAll('tr[data-n]')); }
  function sortTable(group, k) {
    var t = document.getElementById(group.dataset.table);
    if (!t) return;
    var name = function (r) { var a = r.querySelector('a[data-com]'); return plain(a ? a.textContent : ''); };
    var rows = rowsOf(t);
    rows.sort(function (a, b) {
      if (k === 'tax') return (parseFloat(a.dataset.tax) - parseFloat(b.dataset.tax)) || name(a).localeCompare(name(b));
      if (k === 'az') return name(a).localeCompare(name(b));
      if (k === 'za') return name(b).localeCompare(name(a));
      return (parseFloat(b.dataset.n) - parseFloat(a.dataset.n)) || name(a).localeCompare(name(b));
    });
    if (!rows.length) return;
    var parent = rows[0].parentNode, chart = parent.querySelector('tr.chart');
    rows.forEach(function (r) { parent.insertBefore(r, chart); });
    group.querySelectorAll('button[data-sort]').forEach(function (b) {
      var alpha = b.dataset.sort === 'az';
      b.classList.toggle('on', alpha ? (k === 'az' || k === 'za') : b.dataset.sort === k);
      if (alpha) b.textContent = k === 'za' ? 'Z\u2013A' : 'A\u2013Z';
    });
    group.dataset.current = k;
    // a column sort of the standard table no longer applies
    t.querySelectorAll('th.asc, th.desc').forEach(function (th) { th.classList.remove('asc', 'desc'); });
    try { localStorage.setItem('spsort:' + group.dataset.store, k); } catch (e) {}
  }
  function filterTable(input) {
    var t = document.getElementById(input.dataset.table), q = plain(input.value).trim();
    if (!t) return;
    rowsOf(t).forEach(function (r) { r.style.display = !q || plain(r.dataset.q).indexOf(q) >= 0 ? '' : 'none'; });
  }
  function init() {
    document.querySelectorAll('.hmsort[data-table]').forEach(function (g) {
      g.querySelectorAll('button[data-sort]').forEach(function (b) {
        b.addEventListener('click', function () {
          var k = b.dataset.sort;
          // the alphabetical button switches A–Z / Z–A when it is already on
          if (k === 'az' && g.dataset.current === 'az') k = 'za';
          sortTable(g, k);
        });
      });
      var k = 'n';
      try { k = localStorage.getItem('spsort:' + g.dataset.store) || 'n'; } catch (e) {}
      sortTable(g, k);
    });
    document.querySelectorAll('input.spfilter[data-table]').forEach(function (i) { i.addEventListener('input', function () { filterTable(i); }); });
    document.querySelectorAll('select.namemode').forEach(function (s) {
      s.addEventListener('change', function () {
        setTimeout(function () {
          document.querySelectorAll('.hmsort[data-table]').forEach(function (g) { if (g.dataset.current === 'az' || g.dataset.current === 'za') sortTable(g, g.dataset.current); });
        }, 0);
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
