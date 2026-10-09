// Shared confirmation modal of the species lists (Species Management and the species page, owner 2026-10-08):
// title, what it does / impact, Yes and No; the safe answer is the default (No for whitelist and exclude).
const SPECIES_TOGGLE_TEXT = {
  confirmed: { add: ['Confirm NAME?', 'What it does: marks the species as confirmed — you have checked that it really occurs at this station.\n\nImpact: a curation marker only; detection, filters and notifications do not change.'],
               del: ['Remove the confirmation of NAME?', 'What it does: the species is no longer marked as confirmed.\n\nImpact: a curation marker only; detection, filters and notifications do not change.'] },
  whitelist: { add: ['Whitelist NAME?', 'What it does: the species is accepted even when the location filter (species occurrence threshold) does not expect it here and in this week.\n\nImpact: it can be detected all year; if it does not occur here, more false detections are possible. The minimum confidence and the species list still apply.'],
               del: ['Remove NAME from the whitelist?', 'What it does: the location filter applies to the species again.\n\nImpact: it is only detected in the weeks the model expects it here.'] },
  exclude:   { add: ['Exclude NAME?', 'What it does: the species is never detected again (exclude list).\n\nImpact: no new detections, notifications or recordings for it; its past detections are kept. Untick to detect it again.'],
               del: ['Detect NAME again?', 'What it does: the species leaves the exclude list.\n\nImpact: it is detected again whenever it passes the minimum confidence and the filters.'] },
};

function askModal(title, text, okLabel, defaultNo = false) {
  return new Promise(resolve => {
    let m = document.getElementById('spModal');
    if (!m) {
      m = document.createElement('div');
      m.id = 'spModal';
      m.innerHTML = '<div class="spm-box"><div class="spm-head"><img src="images/species-page.svg" alt=""><h3 id="spModalTitle"></h3></div>'
        + '<p id="spModalText"></p><div class="spm-buttons"><button id="spModalCancel">No</button><button id="spModalOk"></button></div></div>';
      document.body.appendChild(m);
      const st = document.createElement('style');
      st.textContent = '#spModal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:1000}'
        + '#spModal .spm-box{background:#fff;color:#000;border-radius:10px;max-width:460px;width:calc(100% - 32px);overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,.35);text-align:left;font-size:14px}'
        + '#spModal .spm-head{display:flex;align-items:center;gap:10px;background:#2b5e22;color:#fff;padding:10px 14px}'
        + '#spModal .spm-head img{width:26px;height:26px}#spModal h3{margin:0;font-size:16px;color:#fff;text-align:left}'
        + '#spModal p{margin:0;padding:14px 16px;white-space:pre-line;line-height:1.4}'
        + '#spModal .spm-buttons{display:flex;justify-content:flex-end;gap:8px;padding:0 16px 14px}'
        + '#spModal .spm-buttons button{min-width:72px;padding:6px 14px;border-radius:14px;border:1px solid #2b5e22;background:#fff;color:#2b5e22;font-weight:600;cursor:pointer;width:auto}'
        + '#spModal .spm-buttons button.primary{background:#2b5e22;color:#fff}'
        + '#spModal .spm-buttons button:focus{outline:2px solid #d97a00;outline-offset:1px}';
      document.head.appendChild(st);
    }
    document.getElementById('spModalTitle').textContent = title;
    document.getElementById('spModalText').textContent = text;
    const yes = document.getElementById('spModalOk'), no = document.getElementById('spModalCancel');
    yes.textContent = okLabel;
    yes.className = defaultNo ? '' : 'primary';
    no.className = defaultNo ? 'primary' : '';
    m.style.display = 'flex';
    (defaultNo ? no : yes).focus();
    const done = v => { m.style.display = 'none'; document.removeEventListener('keydown', key); resolve(v); };
    const key = e => { if (e.key === 'Escape') done(false); };
    document.addEventListener('keydown', key);
    yes.onclick = () => done(true);
    no.onclick = () => done(false);
    m.onclick = e => { if (e.target === m) done(false); };
  });
}
