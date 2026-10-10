<?php
/* Appearance (owner 2026-10-09): the app colours — five fixed themes and one custom (five colours). A click previews
 * the theme on the whole app at once; Apply saves it in birdnet.conf (APP_THEME, APP_THEME_CUSTOM) for every browser.
 * Opened as views.php?view=Appearance; scripts/appearance.php?save=1&theme=...[&custom=...] saves. Colours: common.php
 * theme_presets() / theme_style(). */
require_once __DIR__ . '/common.php';
if (isset($_GET['save'])) {
  ensure_authenticated('You must be authenticated to change the settings.');
  $theme = strtolower($_GET['theme'] ?? '');
  $custom = $_GET['custom'] ?? '';
  $ok_custom = count(preg_grep('/^#[0-9a-fA-F]{6}$/', explode(',', $custom))) === 5;
  if (!isset(theme_presets()[$theme]) && !($theme === 'custom' && $ok_custom)) { echo 'Error: unknown theme'; die(); }
  $f = '/etc/birdnet/birdnet.conf';
  $c = file_get_contents($f);
  $set = function ($c, $key, $value, $comment) {
    return preg_match("/^$key=/m", $c) ? preg_replace("/^$key=.*/m", "$key=\"$value\"", $c) : $c . "\n## $comment\n$key=\"$value\"\n";
  };
  $c = $set($c, 'APP_THEME', $theme, 'APP_THEME: app colours, System > Appearance (forest, ocean, sand, graphite, blossom, white or custom)');
  if ($ok_custom) $c = $set($c, 'APP_THEME_CUSTOM', strtolower($custom), 'APP_THEME_CUSTOM: the custom theme: background, menu, panels, accent, buttons');
  echo file_put_contents($f, $c) !== false ? 'OK' : 'Error writing the settings';
  die();
}
$c = get_config();
$current = strtolower(trim($c['APP_THEME'] ?? 'forest')) ?: 'forest';
$cv = array_map('trim', explode(',', $c['APP_THEME_CUSTOM'] ?? ''));
$custom = count($cv) === 5 && count(preg_grep('/^#[0-9a-fA-F]{6}$/', $cv)) === 5 ? $cv : array_slice(theme_presets()['forest'], 1);
$dark = strtolower($c['COLOR_SCHEME'] ?? '') === 'dark';
$roles = array('Background', 'Menu', 'Panels', 'Accent', 'Buttons');
$card = function ($key, $name, $col) use ($current) {
  return '<button type="button" class="thcard' . ($key === $current ? ' on' : '') . '" data-theme="' . $key . '" data-colors="' . implode(',', $col) . '">'
    . '<span class="mock" style="background:' . $col[0] . '"><span class="mm" style="background:' . $col[1] . '"></span>'
    . '<span class="mp" style="background:' . $col[2] . '"><i style="background:' . $col[3] . '"></i><i style="background:' . $col[4] . '"></i></span></span>'
    . '<b>' . str_replace(' (', '<br>(', htmlspecialchars($name)) . '</b></button>';
};
?>
<style>
.app { max-width: 1000px; margin: 0 auto; padding: 0 12px; text-align: left; }
.app h2 { margin: 8px 0 4px; font-size: 1.3em; } .app p.note { font-size: 13px; color: #333; margin: 0 0 12px; }
.app .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.app .thcard { width: auto; display: flex; flex-direction: column; gap: 6px; align-items: stretch; padding: 8px; border-radius: 12px; border: 2px solid rgba(0,0,0,.08); background: rgba(255,255,255,.6); cursor: pointer; color: #000; }
.app .thcard:hover { border-color: #d97a00; } .app .thcard.on { border-color: var(--accent, #2b5e22); box-shadow: 0 0 0 2px var(--accent, #2b5e22); }
.app .mock { display: flex; height: 74px; border-radius: 8px; overflow: hidden; padding: 6px; gap: 6px; }
.app .mm { width: 26%; border-radius: 4px; } .app .mp { flex: 1; border-radius: 4px; display: flex; align-items: flex-end; gap: 4px; padding: 6px; }
.app .mp i { display: block; width: 28px; height: 12px; border-radius: 6px; }
.app .custom { margin-top: 16px; border: 1px solid rgba(0,0,0,.08); background: rgba(255,255,255,.6); border-radius: 12px; padding: 10px 12px; }
.app .custom label { display: inline-flex; flex-direction: column; font-size: 12px; margin: 6px 12px 0 0; }
.app .custom input[type=color] { width: 64px; height: 34px; padding: 0; border: 1px solid #888; border-radius: 6px; background: none; cursor: pointer; }
.app .actions { margin-top: 14px; display: flex; gap: 10px; align-items: center; }
.app .actions button { width: auto; padding: 6px 18px; border-radius: 14px; font-weight: 600; cursor: pointer; border: 1px solid var(--accent, #2b5e22); }
.app .actions .apply { background: var(--accent, #2b5e22); color: #fff; } .app .actions .undo { background: #fff; color: var(--accent, #2b5e22); }
.app #thmsg { font-size: 13px; }
</style>
<div class="app">
  <p class="note">Colours of the app. A click shows the theme at once; <b>Apply</b> keeps it for every browser.<?php
    if ($dark) echo ' <b>The dark colour scheme is on</b> (Basic Settings): themes change the light scheme only.'; ?></p>
  <div class="grid">
    <?php foreach (theme_presets() as $k => $p) echo $card($k, $p[0], array_slice($p, 1));
      echo $card('custom', 'Custom', $custom); ?>
  </div>
  <div class="custom">
    <b>Custom theme</b> — pick the five colours (shown at once; the Custom card is selected):<br>
    <?php foreach ($roles as $i => $r) echo '<label>' . $r . '<input type="color" data-i="' . $i . '" value="' . htmlspecialchars($custom[$i]) . '"></label>'; ?>
  </div>
  <div class="actions"><button type="button" class="apply" onclick="thApply()">Apply</button>
    <button type="button" class="undo" onclick="location.reload()">Back to the saved theme</button><span id="thmsg"></span></div>
</div>
<script>
var thTheme = <?php echo json_encode($current); ?>;
var thVars = ['--bg', '--menu', '--panel', '--accent', '--accent2'];
// the theme on this page and on the frame around it (header and menu), until Apply or reload
function thShow(colors) {
  [document, window.parent && window.parent.document].forEach(function (doc) {
    if (!doc) return;
    var st = doc.documentElement.style;
    colors.forEach(function (c, i) { st.setProperty(thVars[i], c); });
    st.setProperty('--accent-rgb', [1, 3, 5].map(function (j) { return parseInt(colors[3].substr(j, 2), 16); }).join(','));
  });
}
function thPick(card) {
  document.querySelectorAll('.thcard').forEach(function (c) { c.classList.toggle('on', c === card); });
  thTheme = card.dataset.theme;
  thShow(card.dataset.colors.split(','));
  document.getElementById('thmsg').textContent = 'Preview — Apply to keep it.';
}
document.querySelectorAll('.thcard').forEach(function (c) { c.onclick = function () { thPick(c); }; });
document.querySelectorAll('.custom input[type=color]').forEach(function (inp) {
  inp.oninput = function () {
    var cols = Array.prototype.map.call(document.querySelectorAll('.custom input[type=color]'), function (x) { return x.value; });
    var card = document.querySelector('.thcard[data-theme=custom]');
    card.dataset.colors = cols.join(',');
    var sw = card.querySelectorAll('.mock, .mm, .mp, .mp i');
    [0, 1, 2, 3, 4].forEach(function (i) { sw[i].style.background = cols[i]; });
    thPick(card);
  };
});
function thApply() {
  var x = new XMLHttpRequest();
  x.onload = function () {
    document.getElementById('thmsg').textContent = this.responseText == 'OK' ? 'Saved.' : (this.status === 401 ? 'Log in first.' : this.responseText);
  };
  var q = 'scripts/appearance.php?save=1&theme=' + encodeURIComponent(thTheme);
  if (thTheme === 'custom') q += '&custom=' + encodeURIComponent(document.querySelector('.thcard[data-theme=custom]').dataset.colors);
  x.open('GET', q, true);
  x.send();
}
</script>
