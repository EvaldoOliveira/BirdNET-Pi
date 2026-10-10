// Live Audio (owner 2026-10-10): the station's microphone, from the header of every page. One click listens, another
// stops; while it plays, the player's own bar (time) and a speaker whose click opens a vertical volume slider. It
// plays in place: the page below is not reloaded. The volume is remembered in this browser. /stream asks for the
// login when the station has a web password.
(function () {
  function el(id) { return document.getElementById(id); }
  function storedVolume() {
    var v = 0.8;
    try { v = parseFloat(localStorage.getItem('now_live_volume') || '0.8'); } catch (e) {}
    return isNaN(v) ? 0.8 : Math.min(1, Math.max(0, v));
  }
  function setIcon(a) {
    el('live_volbtn').innerHTML = a.volume === 0 ? '&#128263;' : (a.volume < 0.5 ? '&#128265;' : '&#128266;');
  }
  window.liveVolume = function (v) {
    var a = el('live_audio');
    a.volume = parseFloat(v);
    a.muted = a.volume === 0;
    try { localStorage.setItem('now_live_volume', String(a.volume)); } catch (e) {}
    setIcon(a);
  };
  window.liveVolumeToggle = function () {
    el('live_volrange').value = storedVolume();
    el('live_volpop').classList.toggle('open');
  };
  function stop(a) {
    a.pause(); a.removeAttribute('src'); a.load();
    a.style.display = 'none';
    el('live_vol').style.display = 'none';
    el('live_volpop').classList.remove('open');
    var b = el('live_btn'); b.innerHTML = '&#9654; Live Audio'; b.classList.remove('on');
  }
  window.liveAudio = function () {
    var a = el('live_audio');
    if (a.paused) {
      a.src = '/stream?' + Date.now();   // a fresh connection: the live point, not a stale buffer
      a.style.display = ''; el('live_vol').style.display = '';
      a.volume = storedVolume(); setIcon(a);
      a.play().catch(function () { stop(a); });
    } else {
      stop(a);
    }
  };
  document.addEventListener('click', function (e) {
    var pop = el('live_volpop');
    if (pop && pop.classList.contains('open') && !e.target.closest('#live_vol')) pop.classList.remove('open');
  });
  // going Back to the page, or the browser restoring it, must not leave the sound running by itself
  window.addEventListener('pageshow', function (e) { var a = el('live_audio'); if (e.persisted && a && !a.paused) stop(a); });
  // the button follows the player itself (its own controls can pause and resume it too)
  document.addEventListener('DOMContentLoaded', function () {
    var a = el('live_audio'), b = el('live_btn');
    if (!a || !b) return;
    a.addEventListener('playing', function () { b.innerHTML = '&#9632; Live Audio'; b.classList.add('on'); });
    a.addEventListener('pause', function () { b.innerHTML = '&#9654; Live Audio'; b.classList.remove('on'); });
  });
})();
