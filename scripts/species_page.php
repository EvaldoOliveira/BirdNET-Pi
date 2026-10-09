<?php
/* Species page (owner 2026-10-08): everything about one bird in one place — names and links, totals, the last
 * year as a calendar, months and hours of activity, the location model's expected season (V3 geo model,
 * region_profile.json), the best clips, the latest detections, the review verdicts with a suggested species
 * threshold, and the station settings for the species (threshold, notification tier, lists).
 * Opened as views.php?view=Bird&sci=<scientific name>. */
require_once __DIR__ . '/common.php';
$home = get_home();
$config = get_config();
$sci = trim(html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES));
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$db = get_db();

if ($sci === '') {
  // Species Pages (menu Detections › Species Pages): every species detected here — filter, sort by any column,
  // jump by initial; a click opens the species page (owner 2026-10-08)
  $res = $db->query('SELECT Sci_Name, MAX(Com_Name) AS com, COUNT(*) AS n, MIN(Date) AS first, MAX(Date) AS last,'
    . ' COUNT(DISTINCT Date) AS days, MAX(Confidence) AS maxc FROM detections WHERE 1' . not_rejected_sql() . ' GROUP BY Sci_Name');
  $rows = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $r;
  usort($rows, function ($x, $y) { return intval($y['n']) - intval($x['n']); });  // most detections first
  $today = date('Y-m-d');
  ?>
<style>
.spx { max-width: 1100px; margin: 0 auto; text-align: left; padding: 0 12px; }
.spx .bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; }
.spx input[type=search] { flex: 1 1 240px; max-width: 360px; padding: 6px 8px; font-size: 15px; }
.spx .az a { display: inline-block; min-width: 1.3em; text-align: center; padding: 1px 2px; text-decoration: none; font-weight: 600; }
.spx .az a.off { opacity: .3; pointer-events: none; }
.spx table { width: 100%; border-collapse: collapse; }
.spx th { cursor: pointer; text-align: left !important; white-space: nowrap; padding: 6px; position: sticky; top: 0; }
.spx th:after { content: ' \2195'; opacity: .4; }
.spx td { text-align: left !important; padding: 5px 6px; border-top: 1px solid rgba(128,128,128,.25); }
.spx td.num, .spx th.num { text-align: right !important; }
.spx tr.sprow { cursor: pointer; }
.spx tr.sprow:hover td { background: rgba(217,122,0,.12); }
.spx .new { font-size: 11px; background: #d97a00; color: #fff; border-radius: 8px; padding: 0 6px; margin-left: 4px; }
@media (max-width: 700px) { .spx .hide-m { display: none; } }
</style>
<div class="spx">
  <h2><img src="images/species-page.svg" style="width:30px;height:30px;vertical-align:middle" alt=""> Species Pages</h2>
  <div class="bar">
    <input type="search" id="spq" placeholder="Filter by common or scientific name..." oninput="spFilter()" autofocus>
    <span id="spcount"></span>
  </div>
  <div class="az" id="spaz"></div>
  <table id="sptable">
    <thead><tr>
      <th data-k="com">Species</th><th data-k="sci" class="hide-m">Scientific name</th>
      <th data-k="n" class="num">Detections</th><th data-k="days" class="num hide-m">Days</th>
      <th data-k="maxc" class="num hide-m">Best</th><th data-k="first" class="hide-m">First seen</th><th data-k="last">Last seen</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r) {
      $url = 'views.php?view=Bird&amp;sci=' . rawurlencode($r['Sci_Name']);
      echo '<tr class="sprow" data-href="' . $url . '" data-com="' . $h(mb_strtolower($r['com'])) . '" data-sci="' . $h(strtolower($r['Sci_Name'])) . '"'
        . ' data-n="' . intval($r['n']) . '" data-days="' . intval($r['days']) . '" data-maxc="' . round($r['maxc'], 3) . '" data-first="' . $h($r['first']) . '" data-last="' . $h($r['last']) . '">'
        . '<td>' . species_icon($r['Sci_Name']) . '<a href="' . $url . '">' . $h($r['com']) . '</a>' . ($r['first'] === $today ? '<span class="new">new today</span>' : '') . '</td>'
        . '<td class="hide-m"><i>' . $h($r['Sci_Name']) . '</i></td><td class="num">' . number_format(intval($r['n'])) . '</td>'
        . '<td class="num hide-m">' . intval($r['days']) . '</td><td class="num hide-m">' . round($r['maxc'] * 100) . '%</td>'
        . '<td class="hide-m">' . $h($r['first']) . '</td><td>' . $h($r['last']) . '</td></tr>';
    } ?>
    </tbody>
  </table>
</div>
<script>
(function () {
  var tbody = document.querySelector('#sptable tbody'), rows = Array.prototype.slice.call(tbody.rows);
  // always opens sorted by detections, most first (owner 2026-10-08); a column click re-sorts for this visit only
  var sortKey = 'n', asc = false;
  rows.forEach(function (r) { r.addEventListener('click', function (e) { if (!e.target.closest('a')) location.href = r.dataset.href; }); });
  function norm(s) { return s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
  window.spFilter = function () {
    var q = norm(document.getElementById('spq').value.toLowerCase().trim()), shown = 0;
    rows.forEach(function (r) {
      var ok = !q || norm(r.dataset.com).indexOf(q) >= 0 || r.dataset.sci.indexOf(q) >= 0;
      r.style.display = ok ? '' : 'none'; if (ok) shown++;
    });
    document.getElementById('spcount').textContent = shown + ' of ' + rows.length + ' species';
  };
  function sort() {
    var num = ['n', 'days', 'maxc'].indexOf(sortKey) >= 0;
    rows.sort(function (a, b) {
      var x = a.dataset[sortKey], y = b.dataset[sortKey];
      var c = num ? (parseFloat(x) - parseFloat(y)) : norm(x).localeCompare(norm(y));
      return asc ? c : -c;
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
  }
  document.querySelectorAll('#sptable th').forEach(function (th) {
    th.addEventListener('click', function () {
      if (sortKey === th.dataset.k) asc = !asc; else { sortKey = th.dataset.k; asc = ['com', 'sci'].indexOf(sortKey) >= 0; }
      sort();
    });
  });
  // A–Z: jump to the first species with that initial (common name order)
  var az = document.getElementById('spaz'), initials = {};
  rows.forEach(function (r) { initials[norm(r.dataset.com).charAt(0).toUpperCase()] = true; });
  'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').forEach(function (l) {
    var a = document.createElement('a'); a.textContent = l; a.href = '#';
    if (!initials[l]) a.className = 'off';
    a.onclick = function (e) {
      e.preventDefault(); document.getElementById('spq').value = ''; spFilter();
      if (sortKey !== 'com' || !asc) { sortKey = 'com'; asc = true; sort(); }
      var t = rows.filter(function (r) { return norm(r.dataset.com).charAt(0).toUpperCase() === l; })[0];
      if (t) { t.scrollIntoView({ block: 'start' }); t.style.outline = '2px solid #d97a00'; setTimeout(function () { t.style.outline = ''; }, 1500); }
    };
    az.appendChild(a);
  });
  sort(); spFilter();
})();
</script>
<?php
  return;
}
$nr = not_rejected_sql();
$q = function ($sql, $one = false) use ($db, $sci) {
  $st = $db->prepare($sql);
  ensure_db_ok($st);
  $st->bindValue(':sci', $sci);
  $res = $st->execute();
  if ($one) return $res->fetchArray(SQLITE3_ASSOC);
  $rows = array();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
  return $rows;
};
$sum = $q("SELECT COUNT(*) AS n, MIN(Date) AS first, MAX(Date) AS last, COUNT(DISTINCT Date) AS days, MAX(Confidence) AS maxc,
           AVG(Confidence) AS avgc, MAX(Com_Name) AS com FROM detections WHERE Sci_Name = :sci $nr", true);
if (!$sum || intval($sum['n']) === 0) {
  echo '<div class="settings"><p>No detections of <i>' . $h($sci) . '</i> at this station.</p></div>';
  return;
}
$com = $sum['com'];
$folder = str_replace("'", '', str_replace(' ', '_', $com));

$per_day = array();
foreach ($q("SELECT Date, COUNT(*) AS n FROM detections WHERE Sci_Name = :sci AND Date >= date('now', 'localtime', '-370 days') $nr GROUP BY Date") as $r) {
  $per_day[$r['Date']] = intval($r['n']);
}
$months = array_fill(1, 12, 0);
foreach ($q("SELECT CAST(strftime('%m', Date) AS INT) AS m, COUNT(*) AS n FROM detections WHERE Sci_Name = :sci $nr GROUP BY m") as $r) {
  $months[intval($r['m'])] = intval($r['n']);
}
$hours = array_fill(0, 24, 0);
foreach ($q("SELECT CAST(substr(Time, 1, 2) AS INT) AS hh, COUNT(*) AS n FROM detections WHERE Sci_Name = :sci $nr GROUP BY hh") as $r) {
  $hours[intval($r['hh'])] = intval($r['n']);
}
$best = $q("SELECT Date, Time, Confidence, File_Name FROM detections WHERE Sci_Name = :sci $nr ORDER BY Confidence DESC, Date DESC LIMIT 6");
$recent = $q("SELECT Date, Time, Confidence, Cutoff, Sens, Overlap, File_Name FROM detections WHERE Sci_Name = :sci ORDER BY Date DESC, Time DESC LIMIT 10");

// review verdicts and the suggested threshold: ≥ 3 rejections in 90 days → just above the best rejected one
$reviews = array('yes' => 0, 'no' => 0, 'unsure' => 0);
$suggest = null;
if ($nr !== '') {
  foreach ($q("SELECT Verdict, COUNT(*) AS n FROM detection_reviews WHERE Sci_Name = :sci GROUP BY Verdict") as $r) $reviews[$r['Verdict']] = intval($r['n']);
  $rej = $q("SELECT COUNT(*) AS n, MAX(Confidence) AS maxc FROM detection_reviews WHERE Sci_Name = :sci AND Verdict = 'no'
             AND Date >= date('now', 'localtime', '-90 days')", true);
  if ($rej && intval($rej['n']) >= 3) $suggest = min(0.99, round(floatval($rej['maxc']) + 0.01, 2));
}

// station settings for the species
$read_kv = function ($file) {
  $out = array();
  foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array() as $l) {
    $p = explode('=', trim($l), 2);
    if (count($p) === 2) $out[$p[0]] = $p[1];
  }
  return $out;
};
$in_list = function ($file) use ($sci) {
  foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array() as $l) {
    if (explode('_', trim($l), 2)[0] === $sci) return true;
  }
  return false;
};
$threshold = $read_kv($home . '/BirdNET-Pi/species_confidence.txt')[$sci] ?? null;
$default_tier = strtolower($config['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
if (!in_array($default_tier, array('normal', 'muted', 'rare'), true)) $default_tier = 'normal';
$tier = strtolower($read_kv($home . '/BirdNET-Pi/notification_tiers.txt')[$sci] ?? $default_tier);
$lists = array();
foreach (array('Confirmed' => 'confirmed_species_list.txt', 'Custom list' => 'include_species_list.txt',
               'Whitelist' => 'whitelist_species_list.txt', 'Excluded' => 'exclude_species_list.txt') as $label => $f) {
  if ($in_list(__DIR__ . '/' . $f)) $lists[] = $label;
}

// expected season at this location (rarity.py profile, 48 BirdNET weeks)
$profile = null;
$pf = __DIR__ . '/region_profile.json';
if (is_file($pf)) {
  $p = json_decode(file_get_contents($pf), true);
  if (isset($p['data'][$sci])) $profile = $p['data'][$sci];
}
$week48 = min(48, (intval(date('n')) - 1) * 4 + min(4, intdiv(intval(date('j')) - 1, 7) + 1));
// the location model's probability for this week (same value as the Probability column of Species Management)
$model_prob = $profile ? floatval($profile[$week48 - 1]) : null;
if ($model_prob === null) {
  $out = (string)shell_exec('sudo -u ' . escapeshellarg(get_user()) . ' ' . escapeshellarg($home . '/BirdNET-Pi/birdnet/bin/python3') . ' '
    . escapeshellarg($home . '/BirdNET-Pi/scripts/species.py') . ' --threshold 0 2>/dev/null');
  foreach (explode("\n", $out) as $l) {
    if (preg_match('/^(.*)\s-\s([0-9.]+)\s*$/', trim($l), $m) && explode('_', trim($m[1]), 2)[0] === $sci) { $model_prob = floatval($m[2]); break; }
  }
}
$sf_thresh = floatval($config['SF_THRESH'] ?? 0);
// list membership for the switches above the best detections
$identifier = $sci . '_' . $com;
$in_confirmed = $in_list(__DIR__ . '/confirmed_species_list.txt');
$in_whitelist = $in_list(__DIR__ . '/whitelist_species_list.txt');
$in_exclude = $in_list(__DIR__ . '/exclude_species_list.txt');

$bar = function ($values, $labels, $title, $now = null) use ($h) {
  $max = max(1, max($values));
  $html = '<div class="sp-bars" title="' . $h($title) . '">';
  foreach ($values as $k => $v) {
    $pct = $v > 0 ? max(3, round(100 * $v / $max)) : 0;
    $html .= '<div class="sp-bar' . ($k === $now ? ' now' : '') . '" title="' . $h($labels[$k] . ': ' . $v) . '"><span style="height:' . $pct . '%"></span></div>';
  }
  return $html . '</div>';
};
?>
<style>
.sp { max-width: 1000px; margin: 0 auto; text-align: left; padding: 0 12px; }
.sp h2 { margin-bottom: 0; }
.sp .grid { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
.sp .charts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
@media (max-width: 700px) { .sp .charts { grid-template-columns: 1fr; } }
.sp h3.section { font-size: 1.3em; margin: 22px 0 12px; }
.sp .lists { display: flex; flex-wrap: wrap; gap: 18px; align-items: center; padding: 10px 12px; border: 1px solid rgba(128,128,128,.35); border-radius: 8px; margin: 18px 0 4px; }
.sp .lists label { font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.sp .lists input { width: 18px; height: 18px; cursor: pointer; }
.sp table.list th { text-align: left !important; padding: 4px; border-bottom: 2px solid rgba(128,128,128,.4); font-size: .9em; }
.sp table.list td.num, .sp table.list th.num { text-align: right !important; }
.sp table.list audio { height: 28px; width: 220px; vertical-align: middle; }
.sp .card { min-width: 0; flex: 1 1 280px; border: 1px solid rgba(128,128,128,.35); border-radius: 8px; padding: 10px 12px; }
.sp .card h3 { margin: 0 0 8px; font-size: 1em; }
.sp-bars { display: flex; align-items: flex-end; gap: 2px; height: 70px; }
.sp-bar { flex: 1; height: 100%; display: flex; align-items: flex-end; background: rgba(128,128,128,.08); }
.sp-bar span { display: block; width: 100%; background: #4a8f3c; }
.sp-bar.now span { background: #d97a00; }
.sp-axis { display: flex; justify-content: space-between; font-size: 10px; opacity: .7; }
.sp-cal { display: grid; grid-template-rows: repeat(7, 10px); grid-auto-flow: column; grid-auto-columns: 10px; gap: 2px; overflow-x: auto; padding-bottom: 4px; }
.sp-cal i { display: block; width: 10px; height: 10px; border-radius: 2px; background: rgba(128,128,128,.15); }
.sp-cal i.l1 { background: #b7dfa5; } .sp-cal i.l2 { background: #7fc263; } .sp-cal i.l3 { background: #4a8f3c; } .sp-cal i.l4 { background: #2b5e22; }
.sp table.list { width: 100%; border-collapse: collapse; }
.sp table.list td { text-align: left; vertical-align: middle; padding: 3px 4px; border-top: 1px solid rgba(128,128,128,.2); }
.sp .clips { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.sp .clip { position: relative; padding-top: 32px; }
.sp img.clipspec { display: block; width: 100%; border-radius: 6px; cursor: pointer; margin-top: 4px; transition: filter .15s; }
.sp img.clipspec:hover { filter: brightness(1.15); outline: 2px solid #d97a00; }
</style>
<div class="sp">
  <?php $photo = species_photo($sci);
  if ($photo && !empty($photo['image_url'])) { ?>
  <div class="spphoto"><img src="<?php echo $h($photo['image_url']); ?>" alt="<?php echo $h($com); ?>">
    <small><?php echo $h($photo['title'] ?? ''); ?><?php if (!empty($photo['author_url'])) { ?> · <a href="<?php echo $h($photo['author_url']); ?>" target="_blank">author</a><?php } ?>
    <?php if (!empty($photo['license_url'])) { ?> · <a href="<?php echo $h($photo['license_url']); ?>" target="_blank">licence</a><?php } ?></small></div>
  <?php } ?>
  <div style="font-size:1.3em;margin-top:10px"><?php echo species_title($sci, '<b>' . $h($com) . '</b>', '', true); ?></div>
  <p><b><?php echo number_format(intval($sum['n'])); ?></b> detections on <b><?php echo intval($sum['days']); ?></b> day<?php echo intval($sum['days']) == 1 ? '' : 's'; ?> ·
    first <?php echo $h($sum['first']); ?> · last <?php echo $h($sum['last']); ?> ·
    best <?php echo round(floatval($sum['maxc']) * 100); ?>% · mean <?php echo round(floatval($sum['avgc']) * 100); ?>%<br>
    Model Probability = <b style="color:<?php echo ($model_prob !== null && $model_prob >= $sf_thresh) ? '#1b5e20' : '#b71c1c'; ?>"><?php echo $model_prob !== null ? number_format($model_prob * 100, 1) . '%' : '—'; ?></b>
    <small>(location model, this week<?php echo $sf_thresh > 0 ? '; the station filter keeps species ≥ ' . round($sf_thresh * 100) . '%' : ''; ?>)</small></p>
  <div class="charts">
    <?php if ($profile) { ?>
    <div class="card"><h3>Expected here (location model, by week)</h3>
      <?php echo $bar(array_combine(range(1, 48), array_map(function ($v) { return round($v * 100); }, $profile)),
        array_combine(range(1, 48), array_map(function ($w) { return 'week ' . $w . ' (%)'; }, range(1, 48))), 'occurrence probability', $week48); ?>
      <div class="sp-axis"><span>Jan</span><span>Apr</span><span>Jul</span><span>Oct</span><span>Dec</span></div>
      <small>Peak <?php echo round(max($profile) * 100); ?>%, this week <?php echo round($profile[$week48 - 1] * 100); ?>% (orange).</small>
    </div>
    <?php } else { ?>
    <div class="card"><h3>Expected here (location model, by week)</h3><small>The location profile is not computed yet (it is built with the first notification of the day).</small></div>
    <?php } ?>
    <div class="card"><h3>Last 12 months</h3>
      <div class="sp-cal"><?php
        $start = strtotime('-364 days', strtotime(date('Y-m-d')));
        $start = strtotime('-' . intval(date('w', $start)) . ' days', $start);  // the grid starts on a Sunday
        for ($t = $start; $t <= time(); $t = strtotime('+1 day', $t)) {
          $d = date('Y-m-d', $t);
          $n = $per_day[$d] ?? 0;
          $lvl = $n === 0 ? '' : ($n < 3 ? 'l1' : ($n < 10 ? 'l2' : ($n < 30 ? 'l3' : 'l4')));
          echo '<i class="' . $lvl . '" title="' . $d . ': ' . $n . '"></i>';
        }
      ?></div>
    </div>
    <div class="card"><h3>Months (all years)</h3>
      <?php echo $bar($months, array_combine(range(1, 12), array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec')), 'detections per month', intval(date('n'))); ?>
      <div class="sp-axis"><span>Jan</span><span>Apr</span><span>Jul</span><span>Oct</span><span>Dec</span></div>
    </div>
    <div class="card"><h3>Time of day</h3>
      <?php echo $bar($hours, array_combine(range(0, 23), array_map(function ($x) { return sprintf('%02d h', $x); }, range(0, 23))), 'detections per hour', intval(date('G'))); ?>
      <div class="sp-axis"><span>0 h</span><span>6 h</span><span>12 h</span><span>18 h</span><span>23 h</span></div>
    </div>
  </div>
  <div class="grid">
    <div class="card"><h3>Station settings</h3>
      Species threshold: <b><?php echo $threshold !== null ? round(floatval($threshold) * 100) . '%' : 'global (' . round(floatval($config['CONFIDENCE'] ?? 0.7) * 100) . '%)'; ?></b><br>
      Notifications: <select onchange="spTier(this, <?php echo $h(json_encode($sci)); ?>)" data-was="<?php echo $h($tier); ?>">
        <?php foreach (array('muted' => 'Muted', 'normal' => 'Normal', 'rare' => 'Prio') as $tv => $tl) {
          echo '<option value="' . $tv . '"' . ($tier === $tv ? ' selected' : '') . '>' . $tl . ($tv === $default_tier ? ' (default)' : '') . '</option>';
        } ?>
      </select><br>
    </div>
    <div class="card"><h3>Reviews</h3>
      Yes <b><?php echo $reviews['yes']; ?></b> · Not this bird <b><?php echo $reviews['no']; ?></b> · Can't tell <b><?php echo $reviews['unsure']; ?></b><br>
      <?php if ($suggest !== null) { ?>
        Suggested species threshold: <b><?php echo round($suggest * 100); ?>%</b>
        <small>(just above the best of the <?php echo intval($rej['n']); ?> rejected in 90 days)</small>
      <?php } else { ?>
        <small>Use the <b>Review</b> button on a detection to review it.<br>Three rejections in 90 days suggest a species threshold.</small>
      <?php } ?>
    </div>
  </div>
  <div class="lists">
    <span>Lists:</span>
    <label title="A curation marker: you have checked that the species occurs here"><input type="checkbox" <?php echo $in_confirmed ? 'checked' : ''; ?> onchange="spList(this, 'confirmed', <?php echo $h(json_encode($sci)); ?>)"> Confirmed</label>
    <label title="Accepted even when the location filter does not expect it here and now"><input type="checkbox" <?php echo $in_whitelist ? 'checked' : ''; ?> onchange="spList(this, 'whitelist', <?php echo $h(json_encode($identifier)); ?>)"> Whitelist</label>
    <label title="Never detected again"><input type="checkbox" <?php echo $in_exclude ? 'checked' : ''; ?> onchange="spList(this, 'exclude', <?php echo $h(json_encode($identifier)); ?>)"> Exclude</label>
  </div>
  <h3 class="section">Best detections</h3>
  <div class="clips">
  <?php foreach ($best as $b) {
    $file = $b['Date'] . '/' . $folder . '/' . $b['File_Name'];
    // the spectrogram picture; a click opens it playing, like "open" in Latest detections
    $label = $com . ' · ' . $b['Date'] . ' ' . $b['Time'] . ' · ' . round($b['Confidence'] * 100) . '%';
    echo '<div class="clip">' . detection_actions($file) . '<b>' . $h($b['Date'] . ' ' . $b['Time']) . '</b> · ' . round($b['Confidence'] * 100) . '%'
      . '<img class="clipspec" loading="lazy" src="/By_Date/' . $h($file) . '.png" alt="spectrogram" title="Play"'
      . ' onclick="openSpectrogram(' . $h(json_encode('/By_Date/' . $file)) . ', ' . $h(json_encode($label)) . ')"></div>';
  } ?>
  </div>
  <h3 class="section">Latest detections</h3>
  <table class="list">
  <tr><th>Date</th><th>Time</th><th class="num">Confidence</th><th class="num" title="Minimum confidence in force when it was detected">Min. confidence</th>
    <th class="num" title="Sensitivity / overlap of the analysis">Sens. / overlap</th><th>Listen</th><th>Spectrogram</th><th style="text-align:right !important">Review</th></tr>
  <?php foreach ($recent as $r) {
    $file = $r['Date'] . '/' . $folder . '/' . $r['File_Name'];
    echo '<tr><td>' . $h($r['Date']) . '</td><td>' . $h($r['Time']) . '</td><td class="num">' . round($r['Confidence'] * 100) . '%</td>'
      . '<td class="num">' . ($r['Cutoff'] !== null ? round($r['Cutoff'] * 100) . '%' : '') . '</td>'
      . '<td class="num">' . $h(($r['Sens'] ?? '') . ' / ' . ($r['Overlap'] ?? '')) . '</td>'
      . '<td><audio controls preload="none" src="/By_Date/' . $h($file) . '"></audio></td>'
      . '<td><button type="button" class="openbtn" onclick="openSpectrogram(' . $h(json_encode('/By_Date/' . $file)) . ', ' . $h(json_encode($com . ' · ' . $r['Date'] . ' ' . $r['Time'] . ' · ' . round($r['Confidence'] * 100) . '%')) . ')" title="Open the spectrogram and play">&#9654; Open</button></td>'
      . '<td style="text-align:right !important">' . validate_button($file) . '</td></tr>';
  } ?>
  </table>
</div>
<script>document.querySelectorAll('.sp-cal').forEach(function (c) { c.scrollLeft = c.scrollWidth; });</script>
<script src="static/custom-audio-player.js"></script>
<script src="static/detection-actions.js"></script>
<script src="static/species-modal.js"></script>
<script src="static/spectro-dialog.js"></script>
<script>
// list switches: the same modal and texts as Species Management (static/species-modal.js); whitelist and exclude
// default to No
function spList(box, list, species) {
  var action = box.checked ? 'add' : 'del';
  box.checked = !box.checked;   // only changes once confirmed and saved
  var t = SPECIES_TOGGLE_TEXT[list][action];
  askModal(t[0].replace('NAME', <?php echo json_encode($com . ' (' . $sci . ')'); ?>), t[1], 'Yes', list === 'whitelist' || list === 'exclude').then(function (ok) {
    if (!ok) return;
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.status === 200 && this.responseText.trim() === 'OK') box.checked = (action === 'add');
      else alert('Not changed: ' + (this.status === 401 ? 'log in first' : this.responseText));
    };
    x.onerror = function () { alert('Not changed (network error)'); };
    x.open('GET', 'scripts/species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(species) + '&action=' + action, true);
    x.send();
  });
}
// notification tier of the species (same file and endpoint as Species Management), saved at once
function spTier(sel, sci) {
  var was = sel.dataset.was;
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { sel.dataset.was = sel.value; sel.style.outline = '2px solid #2e7d32'; setTimeout(function () { sel.style.outline = ''; }, 1200); }
    else { sel.value = was; alert('Not changed: ' + (this.status === 401 ? 'log in first' : this.responseText)); }
  };
  x.onerror = function () { sel.value = was; alert('Not changed (network error)'); };
  x.open('GET', 'scripts/species_tools.php?settier=1&species=' + encodeURIComponent(sci) + '&tier=' + encodeURIComponent(sel.value), true);
  x.send();
}
document.addEventListener('play', function (e) { document.querySelectorAll('.sp audio').forEach(function (a) { if (a !== e.target) a.pause(); }); }, true);
</script>
