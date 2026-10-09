// Review player (owner 2026-10-09): the detection's spectrogram, playing, with the review answers on the right.
// An answer is saved and the next detection of the same list starts at once, so a list is reviewed in a few clicks.
// Items are any elements with data-ri (data-file = "<date>/<folder>/<file>", data-clip = "/By_Date/...",
// data-label = title line) inside the closest [data-review-list] (or the page). Their button.validatebtn shows the
// verdict and is updated. Needs static/custom-audio-player.js. Keys: Y N U C, → next, ← previous, Esc close.
// "Not this bird" asks which bird it was (the station's model re-analyses the clip: scripts/clip_alternatives.py) or
// the cause (insect, frog, rain...), optionally for every unreviewed detection of that species in the same hour.
// Speed: loop of the detected 3 s, 1.5x playback, the next clip preloaded, swipe right = Yes / left = Not this bird.
var REVIEW_OPTS = [
  ['yes', '✓', '#2e7d32', 'Yes, this bird', 'Confirms the species (Confirmed list);<br>protects this clip from the disk purge.', 'Y'],
  ['no', '✗', '#c62828', 'Not this bird', 'Then: which bird was it, or the cause;<br>left out of totals, charts and best detections.', 'N'],
  ['unsure', '?', '#9e9e9e', "Can't tell", 'Saved as reviewed but unsure;<br>nothing else changes.', 'U'],
  ['clear', '↺', '#607d8b', 'Clear the review', 'Removes any answer given before;<br>back to "not reviewed".', 'C']
];
var REVIEW_LABELS = {yes: '✓ Valid', no: '✗ Not this bird', unsure: "? Can't tell", clear: 'Review'};
var REVIEW_CAUSES = [['insect', 'Insect'], ['frog', 'Frog / amphibian'], ['rain', 'Rain / wind'], ['human', 'Human / voice'],
  ['mechanical', 'Mechanical / noise'], ['other bird', 'Another bird (unknown)'], ['unknown', 'Unknown']];

function reviewPlayerStyle() {
  if (document.getElementById('rpStyle')) return;
  var st = document.createElement('style');
  st.id = 'rpStyle';
  st.textContent = '#rpDialog{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:1000}'
    + '#rpDialog .rp-box{background:#1b1b1b;border-radius:10px;padding:10px;width:min(1180px,calc(100% - 20px));max-height:calc(100% - 20px);overflow:auto;display:flex;gap:12px;position:relative}'
    + '#rpDialog .rp-left{flex:1 1 auto;min-width:0}#rpDialog .rp-title{display:flex;justify-content:space-between;gap:12px;margin:0 30px 6px 4px;color:#fff}'
    + '#rpDialog .rp-when{font-size:12px;color:#bbb}#rpDialog .rp-com{font-size:17px;font-weight:600}'
    + '#rpDialog .rp-tr{text-align:right}#rpDialog .rp-sci{font-style:italic;font-size:14px}#rpDialog .rp-en{font-size:13px;color:#ddd}'
    + '#rpDialog .rp-bottom{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;flex-wrap:nowrap}'
    + '#rpDialog .rp-right{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex:0 0 auto}'
    + '#rpDialog .rp-folder{color:#fff;background:#2a2a2a;border:1px solid #555;border-radius:6px;padding:4px 9px;font-size:12px;text-decoration:none;white-space:nowrap}'
    + '#rpDialog .rp-folder:hover{border-color:#d97a00}'
    + '#rpDialog .rp-vol{display:flex;align-items:center;gap:6px;margin:0 4px 2px;flex:0 0 auto}'
    + '#rpDialog .rp-vol button{width:auto;background:#2a2a2a;color:#fff;border:1px solid #555;border-radius:6px;padding:6px 9px;cursor:pointer;font-size:14px}'
    + '#rpDialog .rp-vol button:hover{border-color:#d97a00}#rpDialog .rp-vol button.on{background:#c62828;border-color:#c62828}'
    + '#rpDialog .rp-vollevel{color:#bbb;font-size:12px;min-width:3em}'
    + '#rpDialog .rp-params{display:flex;flex-wrap:nowrap;gap:5px;margin:8px 4px 2px;color:#fff;flex:1 1 auto;min-width:0}'
    + '#rpDialog .rp-params span{display:flex;flex-direction:column;background:#2a2a2a;border-radius:6px;padding:4px 7px;flex:1 1 auto;min-width:0}'
    + '#rpDialog .rp-params small{color:#aaa;font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}#rpDialog .rp-params b{font-size:14px;white-space:nowrap}'
    + '#rpDialog .rp-close{position:absolute;top:6px;right:8px;width:auto;background:none;border:none;color:#fff;font-size:22px;cursor:pointer}'
    + '#rpDialog .rp-side{flex:0 0 270px;background:#fff;color:#000;border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:7px;text-align:left;font-size:13px;margin-top:26px}'
    + '#rpDialog .rp-head{display:flex;align-items:center;gap:8px;font-weight:600}#rpDialog .rp-head img{width:24px;height:24px}'
    + '#rpDialog .rp-now{font-size:12px;color:#444}'
    + '#rpDialog .rp-opt{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:6px 8px;border-radius:8px;border:1px solid #ccc;background:#fff;color:#000;cursor:pointer;font-size:13px;line-height:1.3}'
    + '#rpDialog .rp-opt:hover,#rpDialog .rp-opt:focus{border-color:#d97a00;background:#fff7ec;outline:none}'
    + '#rpDialog .rp-opt.current{border:2px solid #2b5e22}'
    + '#rpDialog .rp-ico{flex:0 0 30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px;font-weight:bold}'
    + '#rpDialog .rp-opt b{display:block}#rpDialog .rp-opt .rp-txt{font-size:11.5px;color:#444}#rpDialog .rp-key{margin-left:auto;font-size:11px;color:#888;border:1px solid #ccc;border-radius:4px;padding:0 5px}'
    + '#rpDialog .rp-nav{display:flex;justify-content:space-between;gap:6px;margin-top:4px}'
    + '#rpDialog .rp-nav button{flex:1;width:auto;padding:5px 8px;border-radius:14px;border:1px solid #2b5e22;background:#fff;color:#2b5e22;font-weight:600;cursor:pointer}'
    + '#rpDialog .rp-nav button:disabled{opacity:.35;cursor:default}'
    + '#rpDialog .rp-msg{font-size:12px;color:#2b5e22;min-height:1.2em}'
    + '#rpDialog .rp-tools{display:flex;justify-content:flex-end;gap:12px;margin:-2px 0 2px}#rpDialog .rp-tools img{width:24px;height:24px;cursor:pointer}'
    + '#rpDialog .rp-tools img:hover{transform:scale(1.15)}'
    + '#rpDialog .rp-delete{margin-top:8px;width:100%;padding:7px;border-radius:8px;border:1px solid #c62828;background:#fff;color:#c62828;font-weight:600;cursor:pointer}'
    + '#rpDialog .rp-delete:hover{background:#c62828;color:#fff}'
    + '#rpDialog .rp-not{display:none;flex-direction:column;gap:6px}#rpDialog.notmode .rp-not{display:flex}#rpDialog.notmode .rp-main{display:none}'
    + '#rpDialog .rp-not h4{margin:2px 0;font-size:14px}#rpDialog .rp-sub{font-size:11.5px;color:#555;margin-top:4px}'
    + '#rpDialog .rp-alt{display:flex;justify-content:space-between;align-items:center;gap:6px;width:100%;text-align:left;padding:5px 8px;border-radius:7px;border:1px solid #ccc;background:#fff;color:#000;cursor:pointer;font-size:12.5px}'
    + '#rpDialog .rp-alt:hover{border-color:#d97a00;background:#fff7ec}#rpDialog .rp-alt i{color:#555}#rpDialog .rp-alt small{color:#666;white-space:nowrap}'
    + '#rpDialog .rp-alt.far{opacity:.6}#rpDialog .rp-alts .rp-wait{font-size:12px;color:#666}'
    + '#rpDialog .rp-search{width:100%;padding:5px 7px;font-size:12.5px;border:1px solid #bbb;border-radius:6px;box-sizing:border-box}'
    + '#rpDialog .rp-causes{display:flex;flex-wrap:wrap;gap:5px}#rpDialog .rp-cause{width:auto;padding:4px 8px;border-radius:12px;border:1px solid #c62828;background:#fff;color:#c62828;font-size:12px;cursor:pointer}'
    + '#rpDialog .rp-cause:hover{background:#c62828;color:#fff}#rpDialog .rp-batch{font-size:12px;display:flex;gap:6px;align-items:flex-start}'
    + '#rpDialog .rp-back{width:auto;align-self:flex-start;padding:4px 12px;border-radius:12px;border:1px solid #2b5e22;background:#fff;color:#2b5e22;cursor:pointer;font-weight:600}'
    + '#rpDialog .rp-toolsrow{display:flex;justify-content:space-between;align-items:center;gap:8px;margin:6px 4px 0}'
    + '#rpDialog .rp-speed{display:flex;gap:6px}#rpDialog .rp-speed button{width:auto;background:#2a2a2a;color:#fff;border:1px solid #555;border-radius:6px;padding:4px 9px;cursor:pointer;font-size:12px}'
    + '#rpDialog .rp-speed button.on{background:#2b5e22;border-color:#2b5e22}'
    + '#rpDialog .rp-rates{display:inline-flex;margin-left:4px}#rpDialog .rp-rates button{border-radius:0;margin-left:-1px}'
    + '#rpDialog .rp-rates button:first-child{border-radius:6px 0 0 6px}#rpDialog .rp-rates button:last-child{border-radius:0 6px 6px 0}'
    + '@media (max-width:800px){#rpDialog .rp-params,#rpDialog .rp-bottom{flex-wrap:wrap}#rpDialog .rp-box{flex-direction:column}#rpDialog .rp-side{flex:0 0 auto;margin-top:0}}';
  document.head.appendChild(st);
}

function openReviewPlayer(el) {
  var start = el.closest('[data-ri]');
  if (!start) return;
  var scope = start.closest('[data-review-list]') || document;
  var items = Array.prototype.slice.call(scope.querySelectorAll('[data-ri]'));
  var idx = items.indexOf(start);
  reviewPlayerStyle();
  var old = document.getElementById('rpDialog');
  if (old) old.remove();
  document.querySelectorAll('audio').forEach(function (a) { a.pause(); });
  var d = document.createElement('div');
  d.id = 'rpDialog';
  d.innerHTML = '<div class="rp-box"><button type="button" class="rp-close" title="Close (Esc)">&times;</button>'
    + '<div class="rp-left"><div class="rp-title"><div class="rp-tl"><div class="rp-when"></div><div class="rp-com"></div></div>'
    + '<div class="rp-tr"><div class="rp-sci"></div><div class="rp-en"></div></div></div>'
    + '<div class="rp-player"></div>'
    + '<div class="rp-toolsrow"><a class="rp-folder" target="_blank" title="">&#128194; Open file location</a>'
    + '<div class="rp-speed"><button type="button" data-sp="loop" title="Repeat only the detected 3 seconds">&#10227; Loop 3 s</button>'
    + '<span class="rp-rates" title="Playback speed">' + [0.5, 0.8, 1, 1.5, 2].map(function (r) { return '<button type="button" data-rate="' + r + '">' + String(r).replace('.', ',') + '&times;</button>'; }).join('') + '</span></div></div>'
    + '<div class="rp-bottom"><div class="rp-params"></div>'
    + '<div class="rp-right">'
    + '<div class="rp-vol"><button type="button" data-vol="down" title="Volume down">&#128265;&minus;</button><button type="button" data-vol="up" title="Volume up">&#128266;+</button>'
    + '<button type="button" data-vol="mute" title="Mute">&#128263;</button><span class="rp-vollevel"></span></div></div></div></div>'
    + '<div class="rp-side"><div class="rp-tools">'
    + '<img data-t="change" src="images/bird.svg" title="Change the species of this detection">'
    + '<img data-t="lock" src="images/unlock.svg" title="">'
    + '<img data-t="shift" src="images/shift.svg" title="">'
    + '</div><div class="rp-head"><img src="images/species-page.svg" alt=""><span>Is this the bird?</span></div>'
    + '<div class="rp-now"></div><div class="rp-main">'
    + REVIEW_OPTS.map(function (o) {
        return '<button type="button" class="rp-opt" data-v="' + o[0] + '"><span class="rp-ico" style="background:' + o[2] + '">' + o[1] + '</span>'
          + '<span><b>' + o[3] + '</b><span class="rp-txt">' + o[4] + '</span></span><span class="rp-key">' + o[5] + '</span></button>';
      }).join('')
    + '<div class="rp-msg"></div>'
    + '<div class="rp-nav"><button type="button" data-nav="-1">&#9664; Previous</button><button type="button" data-nav="1">Next &#9654;</button></div></div>'
    + '<div class="rp-not"><h4>Not this bird \u2014 which was it?</h4>'
    + '<div class="rp-sub">Species the station\'s model hears in this clip (expected here first):</div><div class="rp-alts"></div>'
    + '<input type="search" class="rp-search" placeholder="Or search any species..."><div class="rp-found"></div>'
    + '<div class="rp-sub">Or reject it with the cause:</div><div class="rp-causes">'
    + REVIEW_CAUSES.map(function (c) { return '<button type="button" class="rp-cause" data-r="' + c[0] + '">' + c[1] + '</button>'; }).join('') + '</div>'
    + '<label class="rp-batch"><input type="checkbox" class="rp-batchbox"> <span class="rp-batchtxt"></span></label>'
    + '<button type="button" class="rp-back">&#9664; Back</button></div>'
    + '<button type="button" class="rp-delete" title="Moves it to the Excluded folder; deleted for good only in Species \u203a Delete Excluded">&#128683; Exclude this detection</button>'
    + '</div></div>';
  document.body.appendChild(d);

  function verdictOf(item) {
    var b = item.querySelector('button.validatebtn');
    var m = b ? b.className.match(/\bv-(\w+)/) : null;
    return m && m[1] !== 'none' ? m[1] : '';
  }
  function toolIcons(item) {
    var lk = d.querySelector('.rp-tools [data-t=lock]'), sh = d.querySelector('.rp-tools [data-t=shift]');
    var locked = item.dataset.locked === '1', shifted = item.dataset.shifted === '1';
    lk.src = locked ? 'images/lock.svg' : 'images/unlock.svg';
    lk.title = locked ? 'Protected from the disk purge (click to unprotect)' : 'Protect this clip from the disk purge';
    sh.src = shifted ? 'images/unshift.svg' : 'images/shift.svg';
    sh.title = shifted ? 'Frequency shifted down (click to undo)' : 'Shift the frequency down (high-pitched birds)';
  }
  function show(i, msg) {
    idx = i;
    var item = items[i];
    // title: date and common name on the left, scientific and US (eBird / Clements) name on the right
    var lab = (item.dataset.label || '').split(' \u00b7 ');
    d.querySelector('.rp-when').textContent = item.dataset.when || lab[1] || '';
    d.querySelector('.rp-com').textContent = item.dataset.com || lab[0] || '';
    d.querySelector('.rp-sci').textContent = item.dataset.sci || '';
    d.querySelector('.rp-en').textContent = item.dataset.en || '';
    // the folder of the clip in the station File Manager (a web page cannot open the computer's own explorer)
    var dir = item.dataset.dir || ('BirdSongs/Extracted/By_Date/' + (item.dataset.file || '').split('/').slice(0, 2).join('/'));
    var fl = d.querySelector('.rp-folder');
    fl.href = 'scripts/filemanager/filemanager.php?p=' + encodeURIComponent(dir);
    fl.title = 'Opens the File Manager in this folder (new tab): /home/' + dir;
    // analysis settings of this detection, under the spectrogram
    var params = {};
    try { params = JSON.parse(item.dataset.params || '{}'); } catch (e) {}
    d.querySelector('.rp-params').innerHTML = Object.keys(params).map(function (k) {
      return '<span><small>' + k + '</small><b>' + String(params[k]).replace(/</g, '&lt;') + '</b></span>';
    }).join('');
    d.querySelector('.rp-now').textContent = 'Detection ' + (i + 1) + ' of ' + items.length;
    d.querySelector('.rp-msg').textContent = msg || '';
    var v = verdictOf(item);
    toolIcons(item);
    d.querySelectorAll('.rp-opt').forEach(function (b) { b.classList.toggle('current', b.dataset.v === v); });
    d.querySelector('[data-nav="-1"]').disabled = (i === 0);
    d.querySelector('[data-nav="1"]').disabled = (i === items.length - 1);
    d.querySelectorAll('.rp-player audio').forEach(function (a) { a.pause(); });
    var holder = d.querySelector('.rp-player');
    holder.innerHTML = '<div class="custom-audio-player"></div>';
    var p = holder.firstChild;
    p.dataset.audioSrc = item.dataset.clip;
    p.dataset.imageSrc = item.dataset.clip + '.png';
    initCustomAudioPlayers(holder);
    // started inside the click that led here (browsers only allow sound from the user's own gesture)
    var a = p.querySelector('audio');
    if (a) { applyVolume(a); applySpeed(a); a.src = item.dataset.clip; a.play().catch(function () {}); }
    d.classList.remove('notmode');
    preloadNext();
    item.scrollIntoView({block: 'nearest'});
  }
  function answer(v, reason) {
    if (v === 'no' && reason === undefined) { openNot(); return; }
    d.classList.remove('notmode');
    var item = items[idx];
    var file = item.dataset.file;
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText == 'OK') {
        item.querySelectorAll('button.validatebtn').forEach(function (b) {
          b.textContent = REVIEW_LABELS[v];
          b.className = b.className.replace(/\bv-\w+/, 'v-' + (v === 'clear' ? 'none' : v));
        });
      } else {
        d.querySelector('.rp-msg').textContent = (this.status === 401 ? 'Log in first to review detections.' : 'Not saved: ' + this.responseText);
      }
    };
    x.open('GET', 'play.php?review=' + encodeURIComponent(file) + '&verdict=' + v + (reason ? '&reason=' + encodeURIComponent(reason) : ''), true);
    x.send();
    // the next detection starts right away (inside this click)
    if (idx < items.length - 1) show(idx + 1, 'Saved: ' + REVIEW_LABELS[v].replace(/^\W+ /, '') + ' — next detection');
    else d.querySelector('.rp-msg').textContent = 'Saved. That was the last detection of this list.';
  }
  // ---- "Not this bird": which bird was it, or the cause ----
  var labels = null;
  function setVerdict(item, v, text) {
    item.querySelectorAll('button.validatebtn').forEach(function (b) {
      b.textContent = text || REVIEW_LABELS[v];
      b.className = b.className.replace(/\bv-\w+/, 'v-' + (v === 'clear' ? 'none' : v));
    });
  }
  function nextAfter(msg) {
    if (idx < items.length - 1) show(idx + 1, msg + ' \u2014 next detection');
    else { d.classList.remove('notmode'); d.querySelector('.rp-msg').textContent = msg + '. That was the last detection of this list.'; }
  }
  function altButton(a, i) {
    return '<button type="button" class="rp-alt' + (a.expected === false ? ' far' : '') + '" data-label="' + a.label.replace(/"/g, '&quot;') + '" data-com="' + a.com.replace(/"/g, '&quot;') + '">'
      + '<span>' + (i !== null ? '<b>' + (i + 1) + '</b> ' : '') + a.com + ' <i>' + a.sci + '</i></span><small>'
      + (a.score !== undefined ? Math.round(a.score * 100) + '%' : '') + (a.prob !== undefined && a.prob !== null ? ' \u00b7 here ' + Math.round(a.prob * 100) + '%' : (a.expected === false ? ' \u00b7 not expected here' : '')) + '</small></button>';
  }
  function bindAlts(box) {
    box.querySelectorAll('.rp-alt').forEach(function (b) { b.onclick = function () { changeTo(b.dataset.label, b.dataset.com); }; });
  }
  function openNot() {
    var item = items[idx];
    d.classList.add('notmode');
    var when = item.dataset.when || '';
    d.querySelector('.rp-batchtxt').textContent = 'Apply the cause to every unreviewed ' + (item.dataset.com || 'detection') + ' of ' + when.slice(0, 10) + ' ' + when.slice(11, 13) + ':00\u2013' + when.slice(11, 13) + ':59';
    d.querySelector('.rp-batchbox').checked = false;
    d.querySelector('.rp-search').value = '';
    d.querySelector('.rp-found').innerHTML = '';
    var box = d.querySelector('.rp-alts');
    box.innerHTML = '<div class="rp-wait">Asking the station\'s model about this clip (a few seconds)\u2026</div>';
    var asked = item;
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (items[idx] !== asked || !d.classList.contains('notmode')) return;
      var alts = [];
      try { alts = JSON.parse(this.responseText); } catch (e) {}
      box.innerHTML = alts.length ? alts.map(altButton).join('') : '<div class="rp-wait">' + (this.status === 401 ? 'Log in first.' : 'The model found no other species in this clip.') + '</div>';
      bindAlts(box);
    };
    x.open('GET', 'play.php?alternatives=' + encodeURIComponent(item.dataset.file), true);
    x.send();
  }
  function search(q) {
    var found = d.querySelector('.rp-found');
    q = q.trim().toLowerCase();
    if (q.length < 2) { found.innerHTML = ''; return; }
    function render() {
      var hits = labels.filter(function (l) { return l.toLowerCase().indexOf(q) >= 0; }).slice(0, 8);
      found.innerHTML = hits.map(function (l) { var p = l.split('_'); return altButton({label: l, sci: p[0], com: p.slice(1).join('_') || p[0]}, null); }).join('');
      bindAlts(found);
    }
    if (labels) { render(); return; }
    var x = new XMLHttpRequest();
    x.onload = function () { try { labels = JSON.parse(this.responseText); } catch (e) { labels = []; } render(); };
    x.open('GET', 'play.php?getlabels=1', true);
    x.send();
  }
  function changeTo(label, com) {
    var item = items[idx];
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText.trim() == 'OK') setVerdict(item, 'yes', '\u2192 ' + com);
      else d.querySelector('.rp-msg').textContent = 'Species not changed: ' + this.responseText;
    };
    x.open('GET', 'play.php?changefile=' + encodeURIComponent(item.dataset.file) + '&newname=' + encodeURIComponent(label), true);
    x.send();
    d.classList.remove('notmode');
    nextAfter('Changed to ' + com);
  }
  function reject(reason) {
    var item = items[idx];
    if (!d.querySelector('.rp-batchbox').checked) { answer('no', reason); return; }
    var when = item.dataset.when || '', date = when.slice(0, 10), hour = when.slice(11, 13), sci = item.dataset.sci || '';
    var x = new XMLHttpRequest();
    x.onload = function () {
      var m = this.responseText.match(/^OK (\d+)/);
      if (!m) { d.querySelector('.rp-msg').textContent = 'Not saved: ' + this.responseText; return; }
      items.forEach(function (it) {
        var w = it.dataset.when || '';
        if (it.dataset.sci === sci && w.slice(0, 10) === date && w.slice(11, 13) === hour) setVerdict(it, 'no');
      });
    };
    x.open('GET', 'play.php?review_batch=1&sci=' + encodeURIComponent(sci) + '&date=' + date + '&hour=' + parseInt(hour, 10) + '&reason=' + encodeURIComponent(reason), true);
    x.send();
    // this detection too (it may have had an answer already), then the next one outside that hour
    answer('no', reason);
  }
  d.querySelectorAll('.rp-cause').forEach(function (b) { b.onclick = function () { reject(b.dataset.r); }; });
  d.querySelector('.rp-back').onclick = function () { d.classList.remove('notmode'); };
  d.querySelector('.rp-search').oninput = function () { search(this.value); };

  // ---- speed: loop of the detected 3 s, 1.5x, preload of the next clip, swipe ----
  var RATES = [0.5, 0.8, 1, 1.5, 2];
  function spState() {
    var st = {loop: false, rate: 1};
    try {
      st.loop = localStorage.getItem('rp_loop') === '1';
      var r = parseFloat(localStorage.getItem('rp_speed') || '1');
      st.rate = RATES.indexOf(r) >= 0 ? r : 1;
    } catch (e) {}
    return st;
  }
  function applySpeed(a) {
    var st = spState();
    d.querySelector('[data-sp=loop]').classList.toggle('on', st.loop);
    d.querySelectorAll('.rp-rates [data-rate]').forEach(function (b) { b.classList.toggle('on', parseFloat(b.dataset.rate) === st.rate); });
    (a ? [a] : Array.prototype.slice.call(d.querySelectorAll('.rp-player audio'))).forEach(function (x) {
      x.playbackRate = st.rate;
      x.defaultPlaybackRate = st.rate;
      if (!x.dataset.loopHooked) {
        x.dataset.loopHooked = '1';
        // the extraction keeps the detected window in the middle of the clip
        x.addEventListener('timeupdate', function () {
          if (!spState().loop || !x.duration) return;
          var start = Math.max(0, (x.duration - 3) / 2), end = Math.min(x.duration, start + 3);
          if (x.currentTime < start - 0.1 || x.currentTime >= end) x.currentTime = start;
        });
      }
    });
  }
  d.querySelectorAll('[data-sp]').forEach(function (b) {
    b.onclick = function () {
      var st = spState(), k = b.dataset.sp;
      try {
        localStorage.setItem('rp_loop', st.loop ? '0' : '1');
      } catch (e) {}
      applySpeed(null);
    };
  });
  // speed buttons: one click picks the speed (remembered in this browser)
  d.querySelectorAll('.rp-rates [data-rate]').forEach(function (b) {
    b.onclick = function () {
      try { localStorage.setItem('rp_speed', b.dataset.rate); } catch (e) {}
      applySpeed(null);
    };
  });
  var preloaded = null;
  function preloadNext() {
    var nx = items[idx + 1];
    if (!nx) return;
    preloaded = new Audio();
    preloaded.preload = 'auto';
    preloaded.src = nx.dataset.clip;
    (new Image()).src = nx.dataset.clip + '.png';
  }
  var touch = null;
  d.querySelector('.rp-left').addEventListener('touchstart', function (e) { var t = e.changedTouches[0]; touch = {x: t.clientX, y: t.clientY}; }, {passive: true});
  d.querySelector('.rp-left').addEventListener('touchend', function (e) {
    if (!touch) return;
    var t = e.changedTouches[0], dx = t.clientX - touch.x, dy = t.clientY - touch.y;
    touch = null;
    if (Math.abs(dx) > 80 && Math.abs(dy) < 60) answer(dx > 0 ? 'yes' : 'no');
  }, {passive: true});

  // volume (remembered in this browser): steps of 10 %, mute
  function volState() {
    var v = 1, m = false;
    try { v = parseFloat(localStorage.getItem('rp_volume') || '1'); m = localStorage.getItem('rp_muted') === '1'; } catch (e) {}
    return {v: isNaN(v) ? 1 : Math.min(1, Math.max(0, v)), m: m};
  }
  function applyVolume(a) {
    var s = volState();
    (a ? [a] : Array.prototype.slice.call(d.querySelectorAll('.rp-player audio'))).forEach(function (x) { x.volume = s.v; x.muted = s.m; });
    d.querySelector('.rp-vollevel').textContent = s.m ? 'muted' : Math.round(s.v * 100) + '%';
    d.querySelector('.rp-vol [data-vol=mute]').classList.toggle('on', s.m);
  }
  function volume(op) {
    var s = volState();
    if (op === 'mute') s.m = !s.m;
    else { s.m = false; s.v = Math.min(1, Math.max(0, Math.round((s.v + (op === 'up' ? 0.1 : -0.1)) * 10) / 10)); }
    try { localStorage.setItem('rp_volume', String(s.v)); localStorage.setItem('rp_muted', s.m ? '1' : '0'); } catch (e) {}
    applyVolume(null);
  }
  function close() { d.querySelectorAll('audio').forEach(function (a) { a.pause(); }); d.remove(); document.removeEventListener('keydown', key); }
  function key(e) {
    if (e.target && e.target.classList && e.target.classList.contains('rp-search')) { if (e.key === 'Escape') d.classList.remove('notmode'); return; }
    if (d.classList.contains('notmode')) {
      if (e.key === 'Escape') { d.classList.remove('notmode'); return; }
      var n = parseInt(e.key, 10), alts = d.querySelectorAll('.rp-alts .rp-alt');
      if (n >= 1 && n <= alts.length) { e.preventDefault(); alts[n - 1].click(); }
      return;
    }
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowRight' && idx < items.length - 1) { e.preventDefault(); show(idx + 1); return; }
    if (e.key === 'ArrowLeft' && idx > 0) { e.preventDefault(); show(idx - 1); return; }
    var k = {y: 'yes', n: 'no', u: 'unsure', c: 'clear'}[e.key.toLowerCase()];
    if (k) { e.preventDefault(); answer(k); }
  }
  // tools: change species / protect / frequency shift (top right) and delete (bottom), on the current detection
  function tool(t) {
    var item = items[idx], file = item.dataset.file;
    if (t === 'change') { close(); changeDetection(file); return; }
    var x = new XMLHttpRequest();
    if (t === 'lock') {
      var add = item.dataset.locked !== '1';
      // only the icon changes: the sound keeps playing
      x.onload = function () { if (this.responseText == 'OK') { item.dataset.locked = add ? '1' : '0'; toolIcons(item); d.querySelector('.rp-msg').textContent = add ? 'Protected from the disk purge' : 'No longer protected'; } };
      x.open('GET', 'play.php?excludefile=' + encodeURIComponent(file) + (add ? '&exclude_add=true' : '&exclude_del=true'), true);
      x.send();
    } else if (t === 'shift') {
      var on = item.dataset.shifted !== '1';
      var msg = d.querySelector('.rp-msg');
      msg.textContent = on ? 'Shifting the frequency…' : 'Undoing the frequency shift…';
      x.onload = function () {
        if (this.responseText == 'OK') {
          item.dataset.shifted = on ? '1' : '0';
          item.dataset.clip = '/By_Date/' + (on ? 'shifted/' : '') + file;
          msg.textContent = on ? 'Frequency shifted — press play' : 'Original frequency — press play';
          var keep = msg.textContent;
          show(idx, keep);
        } else msg.textContent = 'Not changed: ' + this.responseText;
      };
      x.open('GET', 'play.php?shiftfile=' + encodeURIComponent(file) + (on ? '&doshift=true' : ''), true);
      x.send();
    }
  }
  function remove() {
    var item = items[idx];
    // no confirmation in the review player (owner 2026-10-09): one click deletes and the next detection starts
    d.querySelectorAll('.rp-player audio').forEach(function (a) { a.pause(); });
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText == 'OK') {
        items.splice(idx, 1);
        item.remove();
        if (!items.length) { close(); return; }
        show(Math.min(idx, items.length - 1), 'Excluded — next detection');
      } else d.querySelector('.rp-msg').textContent = 'Not excluded: ' + this.responseText;
    };
    x.open('GET', 'play.php?deletefile=' + encodeURIComponent(item.dataset.file), true);
    x.send();
  }
  d.querySelectorAll('.rp-vol [data-vol]').forEach(function (b) { b.onclick = function () { volume(b.dataset.vol); }; });
  applyVolume(null);
  applySpeed(null);
  d.querySelectorAll('.rp-tools [data-t]').forEach(function (b) { b.onclick = function () { tool(b.dataset.t); }; });
  d.querySelector('.rp-delete').onclick = remove;
  d.querySelector('.rp-close').onclick = close;
  d.onclick = function (e) { if (e.target === d) close(); };
  d.querySelectorAll('.rp-opt').forEach(function (b) { b.onclick = function () { answer(b.dataset.v); }; });
  d.querySelectorAll('[data-nav]').forEach(function (b) { b.onclick = function () { show(idx + parseInt(b.dataset.nav, 10)); }; });
  document.addEventListener('keydown', key);
  show(idx);
}
