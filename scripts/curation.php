<?php
/* Curation (owner 2026-10-09): how far the review of the station's detections has gone — per species the share
 * reviewed, the answers, how often the model was right (precision = yes / (yes + not this bird)), the species threshold
 * and the last review; the causes of the rejections; and the latest reviews with Undo.
 * Opened as views.php?view=Curation. */
require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$has = $db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'");
$over = array();
foreach (@file(get_home() . '/BirdNET-Pi/species_confidence.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
  $p = explode('=', trim($l), 2);
  if (count($p) === 2) $over[$p[0]] = floatval($p[1]);
}
$rows = array();
$res = $db->query('SELECT Sci_Name, MAX(Com_Name) AS com, COUNT(*) AS n FROM detections GROUP BY Sci_Name');
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[$r['Sci_Name']] = $r + array('yes' => 0, 'no' => 0, 'unsure' => 0, 'last' => '');
$reasons = array();
$recent = array();
if ($has) {
  $res = $db->query("SELECT Sci_Name, Verdict, COUNT(*) AS c, MAX(Reviewed_At) AS last FROM detection_reviews GROUP BY Sci_Name, Verdict");
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    if (!isset($rows[$r['Sci_Name']])) continue;
    $rows[$r['Sci_Name']][$r['Verdict']] = intval($r['c']);
    if ($r['last'] > $rows[$r['Sci_Name']]['last']) $rows[$r['Sci_Name']]['last'] = $r['last'];
  }
  $hasReason = false;
  $ti = $db->query("PRAGMA table_info(detection_reviews)");
  while ($ti && ($c = $ti->fetchArray(SQLITE3_ASSOC))) if ($c['name'] === 'Reason') $hasReason = true;
  if ($hasReason) {
    $res = $db->query("SELECT COALESCE(NULLIF(Reason, ''), 'no cause given') AS r, COUNT(*) AS c FROM detection_reviews WHERE Verdict = 'no' GROUP BY r ORDER BY c DESC");
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $reasons[] = $r;
  }
  $res = $db->query("SELECT r.File_Name, r.Sci_Name, r.Com_Name, r.Date, r.Confidence, r.Verdict, r.Reviewed_At" . ($hasReason ? ", r.Reason" : ", '' AS Reason")
    . ", d.Time FROM detection_reviews r LEFT JOIN detections d ON d.File_Name = r.File_Name ORDER BY r.Reviewed_At DESC LIMIT 50");
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $recent[] = $r;
}
$total = array_sum(array_column($rows, 'n'));
$rev = 0; $yes = 0; $no = 0; $uns = 0;
foreach ($rows as $r) { $rev += $r['yes'] + $r['no'] + $r['unsure']; $yes += $r['yes']; $no += $r['no']; $uns += $r['unsure']; }
// by detections, fewest first (owner 2026-10-09): the rare species, where a review matters most, on top
uasort($rows, function ($a, $b) { return $a['n'] - $b['n']; });
?>
<style>
.cur { max-width: 1100px; margin: 0 auto; text-align: left; padding: 0 12px; }
.cur .kpis { display: flex; flex-wrap: wrap; gap: 10px; margin: 8px 0 14px; }
.cur .kpi { background: rgba(255,255,255,.55); border-radius: 8px; padding: 8px 14px; }
.cur .kpi b { display: block; font-size: 1.4em; }
.cur #curtable th { cursor: pointer; }
.cur .bar { display: inline-block; width: 60px; height: 8px; background: rgba(128,128,128,.25); border-radius: 4px; vertical-align: middle; margin-right: 6px; }
.cur .bar span { display: block; height: 100%; background: #2e7d32; border-radius: 4px; }
.cur h3 { margin: 18px 0 8px; }
</style>
<div class="cur">
  <div class="kpis">
    <div class="kpi"><small>Detections</small><b><?php echo number_format($total); ?></b></div>
    <div class="kpi"><small>Reviewed</small><b><?php echo number_format($rev); ?> <small>(<?php echo $total ? number_format(100 * $rev / $total, 2) : 0; ?>%)</small></b></div>
    <div class="kpi"><small>Yes</small><b><?php echo $yes; ?></b></div>
    <div class="kpi"><small>Not this bird</small><b><?php echo $no; ?></b></div>
    <div class="kpi"><small>Can't tell</small><b><?php echo $uns; ?></b></div>
    <div class="kpi"><small>Right (yes ÷ yes + not)</small><b><?php echo ($yes + $no) ? round(100 * $yes / ($yes + $no)) . '%' : '—'; ?></b></div>
  </div>
  <?php if ($reasons) { ?>
    <div><b>Causes of "not this bird":</b> <?php echo $h(implode(' · ', array_map(function ($r) { return $r['r'] . ' ' . $r['c']; }, $reasons))); ?></div>
  <?php } ?>
  <h3 class="hmhead">By species <span class="hmsort" data-table="curtable" data-store="curation"><small>Sort:</small><button type="button" data-sort="n">Count</button><button type="button" data-sort="tax" title="Field-guide order (eBird/Clements taxonomy)">Taxonomy</button><button type="button" data-sort="az" title="Alphabetical, by the names shown">A–Z</button></span><input type="search" class="hmfilter spfilter" data-table="curtable" placeholder="Filter species..." title="Common, scientific or English name (accents ignored)"></h3>
  <table id="curtable" class="stdtable" data-nosort>
    <tr><th><select class="namemode" title="Names shown"><option value="com">Common name</option><option value="sci">Scientific name</option><option value="en">English name</option></select></th><th class="num">Detections</th><th class="num">Reviewed</th><th class="num">Yes</th><th class="num">Not</th>
      <th class="num">Can't tell</th><th class="num">Right</th><th class="num">Threshold</th><th>Last review</th></tr>
    <?php foreach ($rows as $sci => $r) {
      $rv = $r['yes'] + $r['no'] + $r['unsure'];
      $pct = $r['n'] ? 100 * $rv / $r['n'] : 0;
      echo '<tr data-n="' . intval($r['n']) . '" data-tax="' . taxon_order($sci) . '" data-q="' . $h(mb_strtolower($r['com'] . ' ' . $sci . ' ' . get_english_name($sci))) . '"><td>' . '<a href="views.php?view=Bird&amp;sci=' . rawurlencode($sci) . '" data-com="' . $h($r['com']) . '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . $h($r['com']) . '</a>' . '</td>'
        . '<td class="num">' . number_format($r['n']) . '</td>'
        . '<td class="num" data-v="' . $pct . '"><span class="bar"><span style="width:' . min(100, max($rv ? 3 : 0, $pct)) . '%"></span></span>' . $rv . ' (' . ($pct >= 10 ? round($pct) : number_format($pct, 1)) . '%)</td>'
        . '<td class="num">' . $r['yes'] . '</td><td class="num">' . $r['no'] . '</td><td class="num">' . $r['unsure'] . '</td>'
        . '<td class="num">' . (($r['yes'] + $r['no']) ? round(100 * $r['yes'] / ($r['yes'] + $r['no'])) . '%' : '—') . '</td>'
        . '<td class="num">' . (isset($over[$sci]) ? round($over[$sci] * 100) . '%' : 'global') . '</td>'
        . '<td>' . $h($r['last']) . '</td></tr>';
    } ?>
  </table>
  <h3>Latest reviews</h3>
  <?php if (!$recent) { echo '<p>No reviews yet. Use the Review button on any detection.</p>'; } else { ?>
  <table class="stdtable">
    <tr><th>Reviewed</th><th><select class="namemode" title="Names shown"><option value="com">Common name</option><option value="sci">Scientific name</option><option value="en">English name</option></select></th><th>Detection</th><th class="num">Confidence</th><th>Answer</th><th>Cause</th><th></th></tr>
    <?php foreach ($recent as $r) {
      $ans = array('yes' => '✓ Yes', 'no' => '✗ Not this bird', 'unsure' => "? Can't tell")[$r['Verdict']] ?? $r['Verdict'];
      echo '<tr><td>' . $h($r['Reviewed_At']) . '</td><td>' . '<a href="views.php?view=Bird&amp;sci=' . rawurlencode($r['Sci_Name']) . '" data-com="' . $h($r['Com_Name']) . '" data-sci="' . $h($r['Sci_Name']) . '" data-en="' . $h(get_english_name($r['Sci_Name'])) . '">' . $h($r['Com_Name']) . '</a>' . '</td>'
        . '<td>' . $h($r['Date'] . ' ' . ($r['Time'] ?? '')) . '</td><td class="num">' . round($r['Confidence'] * 100) . '%</td>'
        . '<td>' . $h($ans) . '</td><td>' . $h($r['Reason'] ?? '') . '</td>'
        . '<td><button type="button" class="openbtn" onclick="undoReview(' . $h(json_encode($r['File_Name'])) . ', this)" title="Remove this answer">Undo</button></td></tr>';
    } ?>
  </table>
  <?php } ?>
</div>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script>
function undoReview(file, btn) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.responseText == 'OK') { var tr = btn.closest('tr'); tr.style.opacity = .4; btn.textContent = 'Undone'; btn.disabled = true; }
    else alert(this.status === 401 ? 'Log in first.' : this.responseText);
  };
  x.open('GET', 'play.php?review=' + encodeURIComponent(file) + '&verdict=clear', true);
  x.send();
}
// sort the species table by a column (click the header)
document.querySelectorAll('#curtable th').forEach(function (th, i) {
  var asc = false;
  th.onclick = function (e) {
    if (e && e.target.closest('select')) return;
    var t = document.getElementById('curtable'), rows = Array.prototype.slice.call(t.rows, 1);
    asc = !asc;
    rows.sort(function (a, b) {
      var x = a.cells[i].dataset.v || a.cells[i].textContent, y = b.cells[i].dataset.v || b.cells[i].textContent;
      var nx = parseFloat(String(x).replace(/[^0-9.\-]/g, '')), ny = parseFloat(String(y).replace(/[^0-9.\-]/g, ''));
      var c = (!isNaN(nx) && !isNaN(ny)) ? nx - ny : String(x).localeCompare(String(y));
      return asc ? c : -c;
    });
    rows.forEach(function (r) { t.appendChild(r); });
  };
});
</script>
