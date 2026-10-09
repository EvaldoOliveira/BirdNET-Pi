// Spectrogram dialog (species page, Best Detections, owner 2026-10-08): opens the detection's spectrogram with the
// card player, already playing. Needs static/custom-audio-player.js. Usage: openSpectrogram('/By_Date/<file>', 'label')
function openSpectrogram(src, label) {
  var old = document.getElementById('specDialog');
  if (old) old.remove();
  document.querySelectorAll('audio').forEach(function (a) { a.pause(); });
  var d = document.createElement('div');
  d.id = 'specDialog';
  d.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:1000';
  d.innerHTML = '<div style="background:#1b1b1b;border-radius:10px;padding:10px;width:min(900px,calc(100% - 24px));position:relative">'
    + '<div class="spec-label" style="color:#fff;margin:0 30px 6px 4px;font-weight:600"></div>'
    + '<button type="button" title="Close" style="position:absolute;top:6px;right:8px;width:auto;background:none;border:none;color:#fff;font-size:22px;cursor:pointer">&times;</button>'
    + '<div class="custom-audio-player"></div></div>';
  d.querySelector('.spec-label').textContent = label;
  var p = d.querySelector('.custom-audio-player');
  p.dataset.audioSrc = src;
  p.dataset.imageSrc = src + '.png';
  document.body.appendChild(d);
  function close() { d.querySelectorAll('audio').forEach(function (a) { a.pause(); }); d.remove(); document.removeEventListener('keydown', esc); }
  function esc(e) { if (e.key === 'Escape') close(); }
  d.querySelector('button').onclick = close;
  d.onclick = function (e) { if (e.target === d) close(); };
  document.addEventListener('keydown', esc);
  initCustomAudioPlayers(d);
  // start right here, inside the click: browsers only allow sound that starts from the user's own gesture
  var a = p.querySelector('audio');
  if (a) { a.src = src; a.play().catch(function () {}); }
}
