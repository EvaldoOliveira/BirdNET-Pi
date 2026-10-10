<?php
/* Species page (owner 2026-10-08): everything about one bird in one place — names and links, totals, the last
 * year as a calendar, months and hours of activity, the location model's expected season (V3 geo model,
 * region_profile.json), the best clips, the latest detections, the review verdicts with a suggested species
 * threshold, and the Species Settings (editable threshold, notification tier, lists).
 * Opened as views.php?view=Bird&sci=<scientific name>. */
require_once __DIR__ . '/common.php';
$home = get_home();
$config = get_config();
$sci = trim(html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES));
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$db = get_db();

if ($sci === '') {
  // the species list is scripts/species_tools.php (Species Pages = the old Species Management, owner 2026-10-09)
  include __DIR__ . '/species_tools.php';
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
// detections per BirdNET week (48: four per month, as the location model's profile below), every year together
$weeks = array_fill(1, 48, 0);
foreach ($q("SELECT CAST(strftime('%m', Date) AS INT) AS m, CAST(strftime('%d', Date) AS INT) AS d, COUNT(*) AS n FROM detections WHERE Sci_Name = :sci $nr GROUP BY m, d") as $r) {
  $weeks[min(48, (intval($r['m']) - 1) * 4 + min(4, intdiv(intval($r['d']) - 1, 7) + 1))] += intval($r['n']);
}
$hours = array_fill(0, 24, 0);
foreach ($q("SELECT CAST(substr(Time, 1, 2) AS INT) AS hh, COUNT(*) AS n FROM detections WHERE Sci_Name = :sci $nr GROUP BY hh") as $r) {
  $hours[intval($r['hh'])] = intval($r['n']);
}
$best = $q("SELECT Date, Time, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name FROM detections WHERE Sci_Name = :sci $nr ORDER BY Confidence DESC, Date DESC LIMIT 6");
$recent = $q("SELECT Date, Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name FROM detections WHERE Sci_Name = :sci ORDER BY Date DESC, Time DESC LIMIT 30");

// review verdicts and the suggested threshold: ≥ 3 rejections in 90 days → just above the best rejected one
$reviews = array('yes' => 0, 'no' => 0, 'unsure' => 0);
$suggest = null;
if ($nr !== '') {
  foreach ($q("SELECT Verdict, COUNT(*) AS n FROM detection_reviews WHERE Sci_Name = :sci GROUP BY Verdict") as $r) $reviews[$r['Verdict']] = intval($r['n']);
  $rej = $q("SELECT COUNT(*) AS n, MAX(Confidence) AS maxc FROM detection_reviews WHERE Sci_Name = :sci AND Verdict = 'no'
             AND Date >= date('now', 'localtime', '-90 days')", true);
  if ($rej && intval($rej['n']) >= 3) $suggest = min(0.99, round(floatval($rej['maxc']) + 0.01, 2));
}
// Calibration (owner 2026-10-09): precision by confidence band from the yes / no reviews of this species, and the
// lowest threshold whose detections above it are right at least 90 % of the time (5 reviews or more above it)
$bands = array(array(0, 0.5, '< 50%'), array(0.5, 0.7, '50–70%'), array(0.7, 0.85, '70–85%'), array(0.85, 1.01, '≥ 85%'));
$band_stats = array();
$calib = null;
$samples = array();
if ($nr !== '') {
  $rv = $q("SELECT Confidence, Verdict FROM detection_reviews WHERE Sci_Name = :sci AND Verdict IN ('yes', 'no') ORDER BY Confidence");
  foreach ($bands as $b) {
    $y = 0; $n = 0;
    foreach ($rv as $r) if ($r['Confidence'] >= $b[0] && $r['Confidence'] < $b[1]) { if ($r['Verdict'] === 'yes') $y++; else $n++; }
    $band_stats[] = array($b[2], $y, $n);
  }
  foreach ($rv as $i => $r) {
    $above = array_slice($rv, $i);
    $yes = count(array_filter($above, function ($x) { return $x['Verdict'] === 'yes'; }));
    if (count($above) >= 5 && $yes / count($above) >= 0.9) { $calib = round(floatval($r['Confidence']), 2); break; }
  }
  // samples for a calibration round: up to 4 unreviewed detections per band, at random
  foreach ($bands as $b) {
    foreach ($q("SELECT Date, Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name FROM detections WHERE Sci_Name = :sci
                 AND Confidence >= " . $b[0] . " AND Confidence < " . $b[1] . " AND File_Name NOT IN (SELECT File_Name FROM detection_reviews)
                 ORDER BY RANDOM() LIMIT 4") as $r) $samples[] = $r;
  }
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
// the location model's probability for this week (same value as the Probability column of the Species Pages list)
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
.sp .spset label { display: flex; align-items: center; gap: 6px; margin: 5px 0; cursor: pointer; }
.sp .spset input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; margin: 0; }
.sp .spset #sp_threshold { width: 5em; padding: 2px 5px; }
.sp .spset .spdel { margin-top: 8px; padding-top: 6px; border-top: 1px solid rgba(128,128,128,.3); }
.sp .card { min-width: 0; flex: 1 1 280px; border: 1px solid rgba(128,128,128,.35); border-radius: 8px; padding: 10px 12px; }
.sp .card h3 { margin: 0 0 8px; font-size: 1em; }
.sp-bars { display: flex; align-items: flex-end; gap: 2px; height: 70px; }
.sp-bar { flex: 1; height: 100%; display: flex; align-items: flex-end; background: rgba(128,128,128,.08); }
.sp-bar span { display: block; width: 100%; background: #4a8f3c; }
.sp-bar.now span { background: #d97a00; }
.sp-axis { display: flex; justify-content: space-between; font-size: 10px; opacity: .7; }
.sp-cal { display: grid; grid-template-rows: repeat(7, 10px); grid-auto-flow: column; grid-auto-columns: 10px; gap: 2px; overflow-x: auto; padding-bottom: 4px; }
.sp-cal i { display: block; width: 10px; height: 10px; border-radius: 2px; background: rgba(128,128,128,.15); }
.sp-cal i.l1 { background: #b7dfa5; } .sp-cal i.l2 { background: #7fc263; } .sp-cal i.l3 { background: #4a8f3c; } .sp-cal i.l4 { background: var(--accent,#2b5e22); }
.sp .clips { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.sp .clip { position: relative; padding-top: 32px; }
.sp button.wipebtn { width: auto; padding: 3px 10px; border-radius: 12px; border: 1px solid #c62828; background: #fff; color: #c62828; font-weight: 600; cursor: pointer; }
.sp button.wipebtn:hover { background: #c62828; color: #fff; }
.sp button.restorebtn { width: auto; padding: 3px 10px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; }
.sp button.restorebtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.sp table.calib { border-collapse: collapse; margin: 6px 0; font-size: 12px; }
.sp table.calib th, .sp table.calib td { padding: 1px 8px 1px 0; text-align: left; }
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
    <!-- by week, on the same 48 weeks as "Expected here" (owner 2026-10-10: it used to be by month) -->
    <div class="card"><h3>Weeks (all years)</h3>
      <?php echo $bar($weeks, array_combine(range(1, 48), array_map(function ($w) { return 'week ' . $w; }, range(1, 48))), 'detections per week', $week48); ?>
      <div class="sp-axis"><span>Jan</span><span>Apr</span><span>Jul</span><span>Oct</span><span>Dec</span></div>
    </div>
    <div class="card"><h3>Time of day</h3>
      <?php echo $bar($hours, array_combine(range(0, 23), array_map(function ($x) { return sprintf('%02d h', $x); }, range(0, 23))), 'detections per hour', intval(date('G'))); ?>
      <div class="sp-axis"><span>0 h</span><span>6 h</span><span>12 h</span><span>18 h</span><span>23 h</span></div>
    </div>
  </div>
  <div class="grid">
    <!-- Species Settings (owner 2026-10-10): threshold (editable), notifications and the lists, one per line -->
    <div class="card spset"><h3>Species Settings</h3>
      <label title="Minimum confidence for this species; empty = the global Min. Conf. (<?php echo round(floatval($config['CONFIDENCE'] ?? 0.7) * 100); ?>%). Saved at once (species_confidence.txt).">Species threshold:
        <input type="number" id="sp_threshold" min="0.01" max="0.99" step="0.01" placeholder="<?php echo $h(sprintf('%.2f', floatval($config['CONFIDENCE'] ?? 0.7))); ?>"
          value="<?php echo $threshold !== null ? $h(sprintf('%.2f', floatval($threshold))) : ''; ?>" onchange="spThreshold(this, <?php echo $h(json_encode($sci)); ?>)">
        <small>empty = global</small></label>
      <label>Notifications: <select onchange="spTier(this, <?php echo $h(json_encode($sci)); ?>)" data-was="<?php echo $h($tier); ?>">
        <?php foreach (array('muted' => 'Muted', 'normal' => 'Normal', 'rare' => 'Prio') as $tv => $tl) {
          echo '<option value="' . $tv . '"' . ($tier === $tv ? ' selected' : '') . '>' . $tl . ($tv === $default_tier ? ' (default)' : '') . '</option>';
        } ?>
      </select></label>
      <label title="A curation marker: you have checked that the species occurs here"><input type="checkbox" <?php echo $in_confirmed ? 'checked' : ''; ?> onchange="spList(this, 'confirmed', <?php echo $h(json_encode($sci)); ?>)"> Confirmed</label>
      <label title="Accepted even when the location filter does not expect it here and now"><input type="checkbox" <?php echo $in_whitelist ? 'checked' : ''; ?> onchange="spList(this, 'whitelist', <?php echo $h(json_encode($identifier)); ?>)"> Whitelist</label>
      <label title="Never detected again"><input type="checkbox" <?php echo $in_exclude ? 'checked' : ''; ?> onchange="spList(this, 'exclude', <?php echo $h(json_encode($identifier)); ?>)"> Exclude</label>
      <?php $ndel = deleted_count($sci); if ($ndel) { ?>
        <div class="spdel">Removed detections: <b><?php echo $ndel; ?></b>
          <button type="button" class="restorebtn" onclick="restoreRemoved(<?php echo $h(json_encode($sci)); ?>, this)">Restore</button>
          <button type="button" class="wipebtn" onclick="wipeDeleted(<?php echo $h(json_encode($sci)); ?>, this)">Purge removed</button></div>
      <?php } ?>
    </div>
    <div class="card"><h3>Reviews</h3>
      Yes <b><?php echo $reviews['yes']; ?></b> · Not this bird <b><?php echo $reviews['no']; ?></b> · Can't tell <b><?php echo $reviews['unsure']; ?></b><br>
      <?php if ($suggest !== null) { ?>
        Suggested species threshold: <b><?php echo round($suggest * 100); ?>%</b>
        <small>(just above the best of the <?php echo intval($rej['n']); ?> rejected in 90 days)</small>
      <?php } else { ?>
        <small>Use the <b>Review</b> button on a detection to review it.<br>Three rejections in 90 days suggest a species threshold.</small>
      <?php } ?>
      <table class="calib">
        <tr><th>Confidence</th><th>Yes</th><th>Not</th><th>Right</th></tr>
        <?php foreach ($band_stats as $bs) {
          $t = $bs[1] + $bs[2];
          echo '<tr><td>' . $h($bs[0]) . '</td><td>' . $bs[1] . '</td><td>' . $bs[2] . '</td><td>' . ($t ? round(100 * $bs[1] / $t) . '%' : '—') . '</td></tr>';
        } ?>
      </table>
      <?php if ($calib !== null) { ?>
        <div>Calibrated threshold: <b><?php echo round($calib * 100); ?>%</b> <small>(≥ 90 % right above it)</small>
          <button type="button" class="openbtn" onclick="applyThreshold(<?php echo $h(json_encode($sci)); ?>, <?php echo $calib; ?>, this)">Apply</button></div>
      <?php } ?>
      <?php if ($samples) { ?>
        <button type="button" class="openbtn" style="margin-top:6px" onclick="reviewDetection(document.querySelector('#calibsamples [data-ri]'))"
          title="Review a sample across the confidence bands; the threshold above is recalculated from the answers">&#127919; Calibrate: review <?php echo count($samples); ?> samples</button>
        <div id="calibsamples" data-review-list="1" style="display:none"><?php foreach ($samples as $r) {
          $f = $r['Date'] . '/' . $folder . '/' . $r['File_Name'];
          echo '<div' . review_item_attrs($f, $r['Com_Name'] . ' · ' . $r['Date'] . ' ' . $r['Time'] . ' · ' . round($r['Confidence'] * 100) . '%', $sci, $r) . '>' . validate_button($f, '') . '</div>';
        } ?></div>
      <?php } ?>
    </div>
  </div>
  <h3 class="section">Best detections</h3>
  <div class="clips" data-review-list="1">
  <?php foreach ($best as $b) {
    $file = $b['Date'] . '/' . $folder . '/' . $b['File_Name'];
    // the spectrogram picture; a click opens it playing, like "open" in Latest detections
    $label = $com . ' · ' . $b['Date'] . ' ' . $b['Time'] . ' · ' . round($b['Confidence'] * 100) . '%';
    echo '<div class="clip"' . review_item_attrs($file, $label, $sci, $b + array('Com_Name' => $com)) . '>' . detection_actions($file) . '<b>' . $h($b['Date'] . ' ' . $b['Time']) . '</b> · ' . round($b['Confidence'] * 100) . '%'
      . '<img class="clipspec" loading="lazy" src="/By_Date/' . $h($file) . '.png" alt="spectrogram" title="Listen and review" onclick="reviewDetection(this)"></div>';
  } ?>
  </div>
  <h3 class="section">Latest detections</h3>
  <?php // the standard detection list: a click on a row opens the review player, which goes on down the list
  echo detection_review_table($recent, false); ?>
</div>
<script>document.querySelectorAll('.sp-cal').forEach(function (c) { c.scrollLeft = c.scrollWidth; });</script>
<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/custom-audio-player.js"); ?>"></script>
<script src="static/detection-actions.js"></script>
<script src="static/species-modal.js"></script>
<script src="static/spectro-dialog.js"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/wipe-deleted.js"></script>
<script>
// list switches: the same modal and texts as the Species Pages list (static/species-modal.js); whitelist and exclude
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
// species threshold typed in Species Settings: saved at once (empty = back to the global Min. Conf.)
function spThreshold(inp, sci) {
  var v = inp.value.trim();
  if (v !== '') { v = Math.min(0.99, Math.max(0.01, parseFloat(v.replace(',', '.')))); if (isNaN(v)) { inp.value = ''; return; } v = v.toFixed(2); inp.value = v; }
  var x = new XMLHttpRequest();
  x.onload = function () {
    var ok = this.status === 200 && this.responseText.trim() === 'OK';
    inp.style.outline = ok ? '2px solid #2e7d32' : '2px solid #c62828';
    setTimeout(function () { inp.style.outline = ''; }, 1500);
    if (!ok) alert('Not saved: ' + (this.status === 401 ? 'log in first' : this.responseText));
  };
  x.open('GET', 'scripts/species_tools.php?setconf=1&species=' + encodeURIComponent(sci) + '&value=' + encodeURIComponent(v), true);
  x.send();
}
// calibrated threshold: saved as the species threshold (same endpoint as the Species Pages list)
function applyThreshold(sci, value, btn) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { btn.textContent = 'Applied'; btn.disabled = true; var i = document.getElementById('sp_threshold'); if (i) i.value = Number(value).toFixed(2); }
    else alert('Not saved: ' + (this.status === 401 ? 'log in first' : this.responseText));
  };
  x.open('GET', 'scripts/species_tools.php?setconf=1&species=' + encodeURIComponent(sci) + '&value=' + value, true);
  x.send();
}
// notification tier of the species (same file and endpoint as the Species Pages list), saved at once
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
