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

if ($sci === '') { echo '<div class="settings"><p>No species chosen.</p></div>'; return; }
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
$best = $q("SELECT Date, Time, Confidence, File_Name FROM detections WHERE Sci_Name = :sci $nr ORDER BY Confidence DESC, Date DESC LIMIT 5");
$recent = $q("SELECT Date, Time, Confidence, File_Name FROM detections WHERE Sci_Name = :sci ORDER BY Date DESC, Time DESC LIMIT 10");

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
$tier = $read_kv($home . '/BirdNET-Pi/notification_tiers.txt')[$sci] ?? 'normal';
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
.sp .grid { display: flex; flex-wrap: wrap; gap: 12px; }
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
.sp table.list td { text-align: left; padding: 3px 4px; border-top: 1px solid rgba(128,128,128,.2); }
.sp .clips { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.sp .clip { position: relative; padding-top: 32px; }
</style>
<div class="sp">
  <h2><?php echo $h($com); ?></h2>
  <i><?php echo $h($sci); ?></i> <?php echo species_links($sci, 'width: unset !important; display: inline; height: 1em; cursor: pointer;', 20); ?>
  <p><b><?php echo number_format(intval($sum['n'])); ?></b> detections on <b><?php echo intval($sum['days']); ?></b> day<?php echo intval($sum['days']) == 1 ? '' : 's'; ?> ·
    first <?php echo $h($sum['first']); ?> · last <?php echo $h($sum['last']); ?> ·
    best <?php echo round(floatval($sum['maxc']) * 100); ?>% · mean <?php echo round(floatval($sum['avgc']) * 100); ?>%</p>
  <div class="grid">
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
    <?php if ($profile) { ?>
    <div class="card"><h3>Expected here (location model, by week)</h3>
      <?php echo $bar(array_combine(range(1, 48), array_map(function ($v) { return round($v * 100); }, $profile)),
        array_combine(range(1, 48), array_map(function ($w) { return 'week ' . $w . ' (%)'; }, range(1, 48))), 'occurrence probability', $week48); ?>
      <div class="sp-axis"><span>Jan</span><span>Apr</span><span>Jul</span><span>Oct</span><span>Dec</span></div>
      <small>Peak <?php echo round(max($profile) * 100); ?>%, this week <?php echo round($profile[$week48 - 1] * 100); ?>% (orange).</small>
    </div>
    <?php } ?>
    <div class="card"><h3>Station settings</h3>
      Species threshold: <b><?php echo $threshold !== null ? round(floatval($threshold) * 100) . '%' : 'global (' . round(floatval($config['CONFIDENCE'] ?? 0.7) * 100) . '%)'; ?></b><br>
      Notifications: <b><?php echo $h($tier); ?></b><br>
      Lists: <b><?php echo $lists ? $h(implode(', ', $lists)) : '—'; ?></b><br>
      <small><a href="views.php?view=Species%20Management">Change in Species Management</a></small>
    </div>
    <div class="card"><h3>Review</h3>
      Yes <b><?php echo $reviews['yes']; ?></b> · Not this bird <b><?php echo $reviews['no']; ?></b> · Can't tell <b><?php echo $reviews['unsure']; ?></b><br>
      <?php if ($suggest !== null) { ?>
        Suggested species threshold: <b><?php echo round($suggest * 100); ?>%</b>
        <small>(just above the best of the <?php echo intval($rej['n']); ?> rejected in 90 days)</small>
      <?php } else { ?>
        <small>Use the <img src="images/review.svg" width="14" style="vertical-align:middle"> icon on a detection to review it; three rejections in 90 days suggest a species threshold.</small>
      <?php } ?>
    </div>
  </div>
  <h3>Best detections</h3>
  <div class="clips">
  <?php foreach ($best as $b) {
    $file = $b['Date'] . '/' . $folder . '/' . $b['File_Name'];
    echo '<div class="clip">' . detection_actions($file) . '<b>' . $h($b['Date'] . ' ' . $b['Time']) . '</b> · ' . round($b['Confidence'] * 100) . '%'
      . '<div class="custom-audio-player" data-audio-src="/By_Date/' . $h($file) . '" data-image-src="/By_Date/' . $h($file) . '.png"></div></div>';
  } ?>
  </div>
  <h3>Latest detections</h3>
  <table class="list">
  <?php foreach ($recent as $r) {
    $v = review_verdict($r['File_Name']);
    echo '<tr><td>' . $h($r['Date'] . ' ' . $r['Time']) . '</td><td>' . round($r['Confidence'] * 100) . '%</td><td>'
      . ($v ? $h(array('yes' => 'confirmed', 'no' => 'not this bird', 'unsure' => "can't tell")[$v]) : '') . '</td>'
      . '<td><a href="/By_Date/' . $h($r['Date'] . '/' . $folder . '/' . $r['File_Name']) . '" target="_blank">clip</a></td></tr>';
  } ?>
  </table>
</div>
<script>document.querySelectorAll('.sp-cal').forEach(function (c) { c.scrollLeft = c.scrollWidth; });</script>
<script src="static/custom-audio-player.js"></script>
<script src="static/detection-actions.js"></script>
