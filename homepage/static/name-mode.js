// Name picklist of the species tables (Dashboard, Seasonality, detection lists — owner 2026-10-09): Common / Scientific /
// English. A <select class="namemode"> in a header switches every <a data-com data-sci data-en> of the page; the choice is
// remembered in this browser. applyNameMode() again after a list is loaded.
(function () {
  function mode() { try { return localStorage.getItem('name_mode') || 'com'; } catch (e) { return 'com'; } }
  function apply(m) {
    document.querySelectorAll('a[data-com]').forEach(function (a) {
      a.textContent = m === 'sci' ? a.dataset.sci : (m === 'en' ? (a.dataset.en || a.dataset.com) : a.dataset.com);
      a.style.fontStyle = m === 'sci' ? 'italic' : '';
    });
    document.querySelectorAll('select.namemode').forEach(function (s) {
      s.value = m;
      if (!s.dataset.bound) {
        s.dataset.bound = '1';
        s.addEventListener('change', function () { try { localStorage.setItem('name_mode', s.value); } catch (e) {} apply(s.value); });
      }
    });
  }
  window.applyNameMode = function () { apply(mode()); };
  apply(mode());
})();
