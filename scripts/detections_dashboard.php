<?php
/* By Hour (owner 2026-10-09; started as the Dashboard trial, replaced All Detections): a period filter, then
 *   - indicator cards with a 14-day sparkline and the change against the previous period of the same length;
 *   - the day by half hour: every species × 48 half-hour cells, with detections and species per half hour under it;
 *   - the year by calendar week: detections and species per KW (every date, species filter only), as on Seasonality.
 * Plain SVG drawn here, no chart library. Opened as views.php?view=Dashboard. */
require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$valid = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : ''; };
$today = date('Y-m-d');
$from = $valid($_GET['from'] ?? '') ?: $today;
$to = $valid($_GET['to'] ?? '') ?: $from;
if ($to < $from) { $t = $from; $from = $to; $to = $t; }
$sci = trim(html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES));
$nr = not_rejected_sql();
$first_day = (string)$db->querySingle('SELECT MIN(Date) FROM detections') ?: $today;
$is_today = ($from === $today && $to === $today);
$is_all = ($from === $first_day && $to === $today);
$days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
$pfrom = date('Y-m-d', strtotime($from . " -$days days"));
$pto = date('Y-m-d', strtotime($from . ' -1 day'));
$spw = $sci !== '' ? ' AND Sci_Name = :s' : '';
$q = function ($sql, $params) use ($db, $sci) {
  $st = $db->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  if ($sci !== '' && strpos($sql, ':s') !== false) $st->bindValue(':s', $sci);
  return $st->execute();
};
$one = function ($sql, $params) use ($q) { $r = $q($sql, $params)->fetchArray(SQLITE3_NUM); return $r ? $r[0] : null; };
$base = 'views.php?view=Dashboard';
$com_of = array();
$res = $db->query('SELECT Sci_Name, MAX(Com_Name) FROM detections GROUP BY Sci_Name');
while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $com_of[$r[0]] = $r[1];

// ---- cards: value, change vs the previous period, sparkline of the last 14 days ending at $to ----
$cur = $q("SELECT COUNT(*), COUNT(DISTINCT Sci_Name), MAX(Confidence) FROM detections WHERE Date BETWEEN :f AND :t$spw$nr", array(':f' => $from, ':t' => $to))->fetchArray(SQLITE3_NUM);
$prev = $q("SELECT COUNT(*), COUNT(DISTINCT Sci_Name), MAX(Confidence) FROM detections WHERE Date BETWEEN :f AND :t$spw$nr", array(':f' => $pfrom, ':t' => $pto))->fetchArray(SQLITE3_NUM);
$new = $one("SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE Date BETWEEN :f AND :t$spw$nr AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < :f)", array(':f' => $from, ':t' => $to));
$spark = array('n' => array(), 'sp' => array(), 'best' => array());
$sstart = date('Y-m-d', strtotime($to . ' -13 days'));
$res = $q("SELECT Date, COUNT(*), COUNT(DISTINCT Sci_Name), MAX(Confidence) FROM detections WHERE Date BETWEEN :f AND :t$spw$nr GROUP BY Date", array(':f' => $sstart, ':t' => $to));
$byday = array();
while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $byday[$r[0]] = $r;
for ($i = 13; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime($to . " -$i days"));
  $spark['n'][] = intval($byday[$d][1] ?? 0);
  $spark['sp'][] = intval($byday[$d][2] ?? 0);
  $spark['best'][] = round(floatval($byday[$d][3] ?? 0) * 100);
}
$sparkline = function ($vals) {
  $w = 120; $hgt = 28; $max = max(1, max($vals)); $n = count($vals);
  $pts = array();
  foreach ($vals as $i => $v) $pts[] = round($i * $w / max(1, $n - 1), 1) . ',' . round($hgt - 2 - ($hgt - 4) * $v / $max, 1);
  $area = '0,' . $hgt . ' ' . implode(' ', $pts) . ' ' . $w . ',' . $hgt;
  return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none"><polygon points="' . $area . '" class="sa"/><polyline points="' . implode(' ', $pts) . '" class="sl"/></svg>';
};
$delta = function ($c, $p, $label) {
  if ($p === null || floatval($p) == 0) return '<small class="dl">no data ' . $label . '</small>';
  $pct = round(100 * (floatval($c) - floatval($p)) / floatval($p));
  $cls = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : '');
  return '<small class="dl ' . $cls . '">' . ($pct > 0 ? '▲ ' : ($pct < 0 ? '▼ ' : '')) . abs($pct) . '% ' . $label . '</small>';
};
$vs = $is_today ? 'vs yesterday' : ($days === 1 ? 'vs the day before' : "vs previous $days days");

// ---- the day by half hour: every species of the period × 48 half-hour cells (owner 2026-10-09) ----
$res = $q("SELECT Sci_Name, CAST(substr(Time, 1, 2) AS INT) * 2 + (CAST(substr(Time, 4, 2) AS INT) >= 30) AS b, COUNT(*) FROM detections
  WHERE Date BETWEEN :f AND :t$spw$nr GROUP BY Sci_Name, b", array(':f' => $from, ':t' => $to));
$hb = array(); $htot = array(); $hb_n = array_fill(0, 48, 0); $hb_sp = array_fill(0, 48, 0);
while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) { $hb[$r[0]][$r[1]] = $r[2]; $htot[$r[0]] = ($htot[$r[0]] ?? 0) + $r[2]; $hb_n[$r[1]] += $r[2]; $hb_sp[$r[1]]++; }
arsort($htot);
$hmax = 1; foreach ($hb as $x) $hmax = max($hmax, max($x));
$hb_nmax = max(1, max($hb_n)); $hb_spmax = max(1, max($hb_sp));
$now_bin = $is_today ? intval(date('G')) * 2 + (intval(date('i')) >= 30 ? 1 : 0) : -1;

// ---- the year by calendar week (KW1–KW53): every date recorded, species filter only — as on Seasonality ----
$kw_n = array_fill(1, 53, 0); $kw_spset = array_fill(1, 53, array());
$res = $q("SELECT Sci_Name, Date, COUNT(*) FROM detections WHERE 1$spw$nr GROUP BY Sci_Name, Date", array());
while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) { $k = intval(date('W', strtotime($r[1]))); $kw_n[$k] += $r[2]; $kw_spset[$k][$r[0]] = true; }
$kw_sp = array_map('count', $kw_spset);
$kw_nmax = max(1, max($kw_n)); $kw_spmax = max(1, max($kw_sp));
$cur_kw = intval(date('W'));
$yr = intval(date('o')); $kmon = array();
for ($k = 1; $k <= 53; $k++) { $thu = new DateTime(); $thu->setISODate($yr, $k, 4); $kmon[$k] = intval($thu->format('Y')) === $yr ? intval($thu->format('n')) : ($k > 40 ? 13 : 0); }
$mnames = array(1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
$cellbar = function ($v, $max, $px, $cls, $title) {
  return '<td class="b" title="' . $title . '"><div class="bar ' . $cls . '" style="height:' . ($v ? max(2, round($px * $v / $max)) : 0) . 'px"></div></td>';
};
$heatcell = function ($v, $max, $cls, $title) {
  $a = $v ? 0.15 + 0.85 * log(1 + $v) / log(1 + $max) : 0;
  return '<td class="c' . $cls . '" title="' . $title . '"' . ($v ? ' style="background:rgba(var(--accent-rgb,43,94,34),' . round($a, 2) . ') !important"' : '') . '></td>';
};
?>
<style>
.dash { max-width: 1200px; margin: 0 auto; padding: 0 12px; text-align: left; }
.dash .bar { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin: 6px 0 10px; font-size: 13px; }
.dash .bar.top { justify-content: flex-start; gap: 8px 18px; } .dash .bar.top > .right { flex: 1 1 auto; display: flex; justify-content: flex-end; } .dash .bar .right input { width: 220px; max-width: 100%; }
.dash table.hm .spcount { font-weight: normal; font-size: 14px; } .dash .bar span.hmsort { gap: 0; }
.dash .bar span { display: inline-flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.dash .bar button:not(.hmsort button), .dash .bar a.btn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; text-decoration: none; }
.dash .bar a.btn.on, .dash .bar button.on:not(.hmsort button) { background: var(--accent,#2b5e22); color: #fff; }
.dash .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
.dash .card { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px 6px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.dash .card small.t { color: #555; } .dash .card b { display: block; font-size: 1.7em; line-height: 1.2; }
.dash .dl { font-size: 11px; color: #666; } .dash .dl.up { color: #2e7d32; } .dash .dl.down { color: #c62828; }
.dash svg.spark { width: 100%; height: 28px; display: block; margin-top: 4px; }
.dash svg.spark .sl { fill: none; stroke: var(--accent,#2b5e22); stroke-width: 1.6; vector-effect: non-scaling-stroke; } .dash svg.spark .sa { fill: rgba(var(--accent-rgb,43,94,34),.15); }
.dash .panel { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow-x: auto; }
.dash .panel h3 { margin: 0 0 6px; font-size: 1.05em; }
.dash svg .ridge polygon { fill: rgba(74,143,60,.55); stroke: none; } .dash svg .ridge polyline { fill: none; stroke: #1f4a19; stroke-width: 1.2; }
.dash svg .ridge:hover polygon { fill: rgba(217,122,0,.6); }
.dash svg text { font-size: 11px; fill: #222; } .dash svg .ax { font-size: 10px; fill: #555; } .dash svg .grid { stroke: rgba(0,0,0,.08); }
.dash svg rect.off { fill: rgba(128,128,128,.15); } .dash svg rect.on { fill: var(--accent,#2b5e22); } .dash svg a:hover rect { stroke: #d97a00; stroke-width: 2; }
.dash .note { font-size: 12px; color: #555; }
.dash .hmwrap { overflow-x: auto; }
.dash table.hm { border-collapse: separate; border-spacing: 2px; font-size: 12px; }
.dash table.hm th, .dash table.hm td { padding: 0; border: none !important; }
.dash table.hm td.nm, .dash table.hm th.nm { text-align: left !important; white-space: nowrap; padding-right: 8px; position: sticky; left: 0; background: var(--panel,#e8f5ea) !important; z-index: 1; }
.dash table.hm td.c { width: 14px; min-width: 14px; height: 16px; border-radius: 2px; background: #fff !important; }
.dash table.hm td.c.now { outline: 1px solid #d97a00; }
.dash table.hm tr.drill td.c { cursor: pointer; } .dash table.hm tr.drill:hover td.c { box-shadow: inset 0 0 0 1px #d97a00; }
.dash table.hm th.m { text-align: left !important; font-size: 11px; font-weight: 600; border-left: 2px solid rgba(var(--accent-rgb,43,94,34),.45) !important; padding-left: 2px; }
.dash table.hm th.k { font-size: 9px; font-weight: normal; color: #666; text-align: left !important; }
.dash table.hm td.t, .dash table.hm th.t { text-align: right !important; padding-left: 8px; white-space: nowrap; }
.dash table.hm tr.chart td { background: transparent !important; vertical-align: bottom; }
.dash table.hm tr.chart td.nm { vertical-align: bottom; padding-top: 10px; }
.dash table.hm tr.chart td.b { height: 74px; padding-top: 10px; min-width: 14px; }
.dash table.hm .bar { width: 100%; background: rgba(var(--accent-rgb,43,94,34),.8); border-radius: 2px 2px 0 0; }
.dash table.hm .bar.now { background: #d97a00; } .dash table.hm .bar.sp { background: #1565c0; }
.dash svg rect.kwb { fill: rgba(var(--accent-rgb,43,94,34),.75); } .dash svg rect.kwb.now { fill: #d97a00; } .dash svg rect.kwb:hover { fill: #1f4a19; }
.dash svg .spl { fill: none; stroke: #1565c0; stroke-width: 1.6; } .dash svg .spd { fill: #1565c0; }
</style>
<div class="dash">
  <div class="cards">
    <div class="card"><small class="t">Detections</small><b><?php echo number_format(intval($cur[0])); ?></b><?php echo $delta($cur[0], $prev[0], $vs) . $sparkline($spark['n']); ?></div>
    <div class="card"><small class="t">Species</small><b><?php echo intval($cur[1]); ?></b><?php echo $delta($cur[1], $prev[1], $vs) . $sparkline($spark['sp']); ?></div>
    <div class="card"><small class="t">New species</small><b><?php echo intval($new); ?></b><small class="dl">never detected before this period</small></div>
    <div class="card"><small class="t">Best confidence</small><b><?php echo round(floatval($cur[2]) * 100); ?>%</b><?php echo $delta($cur[2], $prev[2], $vs) . $sparkline($spark['best']); ?></div>
  </div>
  <div class="bar top">
    <?php // period navigation, the same control as By Week (owner 2026-10-10): All · ◀ · Today / the period · ▶ — the arrows
      // move by the period's own length (a day, or the N days of a range); ▶ stops at today
      $purl = function ($f, $t) use ($base, $sci) { return $base . '&amp;from=' . $f . '&amp;to=' . $t . ($sci !== '' ? '&amp;sci=' . rawurlencode($sci) : ''); };
      $prev_f = date('Y-m-d', strtotime($from . " -$days days")); $prev_t = date('Y-m-d', strtotime($to . " -$days days"));
      $next_f = date('Y-m-d', strtotime($from . " +$days days")); $next_t = date('Y-m-d', strtotime($to . " +$days days"));
      $label = $is_today ? 'Today' : ($from === $to ? date('D d/m/Y', strtotime($from)) : date('d/m/Y', strtotime($from)) . ' – ' . date('d/m/Y', strtotime($to))); ?>
    <span class="periodnav">
      <a class="<?php echo $is_all ? 'on' : ''; ?>" href="<?php echo $purl($first_day, $today); ?>">All</a>
      <a class="<?php echo ($is_all || $from <= $first_day) ? 'off' : ''; ?>" href="<?php echo $purl($prev_f, $prev_t); ?>" title="Previous <?php echo $days > 1 ? $days . ' days' : 'day'; ?>">&#9664;</a>
      <a class="<?php echo $is_today ? 'on' : ''; ?>" href="<?php echo $purl($today, $today); ?>" title="Today"><?php echo $h($label); ?></a>
      <a class="<?php echo ($is_all || $to >= $today) ? 'off' : ''; ?>" href="<?php echo $purl($next_f, min($today, $next_t)); ?>" title="Next <?php echo $days > 1 ? $days . ' days' : 'day'; ?>">&#9654;</a>
    </span>
    <span>
      <?php if ($sci !== '') { ?><a class="btn on" href="<?php echo $base . '&amp;from=' . $from . '&amp;to=' . $to; ?>" title="Show every species">&times; <?php echo $h($com_of[$sci] ?? $sci); ?></a><?php } ?></span>
    <form class="yrange" method="GET" action="views.php"><input type="hidden" name="view" value="Dashboard">
      From <input type="date" name="from" value="<?php echo $h($from); ?>" max="<?php echo $today; ?>">
      to <input type="date" name="to" value="<?php echo $h($to); ?>" max="<?php echo $today; ?>">
      <?php if ($sci !== '') { ?><input type="hidden" name="sci" value="<?php echo $h($sci); ?>"><?php } ?>
      <button type="submit" class="on">Filter</button></form>
    <!-- species order (owner 2026-10-10): count (most detections first), taxonomy (eBird/Clements field-guide order), A–Z
         (by the names shown); remembered in this browser. One top line, laid out as By Week -->
    <span class="right"><span class="hmsort" data-table="hmday" data-store="byhour"><small>Sort:</small><button type="button" data-sort="n">Count</button><button type="button" data-sort="tax" title="Field-guide order (eBird/Clements taxonomy)">Taxonomy</button><button type="button" data-sort="az" title="Alphabetical, by the names shown">A–Z</button></span>
      <input type="search" class="hmfilter spfilter" data-table="hmday" placeholder="Filter species..." title="Common, scientific or English name (accents ignored)"></span>
  </div>
  <div class="panel">
    <?php if (!$htot) { echo '<p class="note">No detections in this period.</p>'; } else { ?>
    <div class="hmwrap"><table class="hm" id="hmday">
      <tr><th class="nm"><span class="spcount"><?php echo count($htot); ?> species</span><br><select class="namemode" title="Names shown"><option value="com">Common name</option><option value="sci">Scientific name</option><option value="en">English name</option></select></th><?php for ($hh = 0; $hh < 24; $hh++) echo '<th class="m" colspan="2">' . sprintf('%02d', $hh) . '</th>'; ?><th></th></tr>
      <?php foreach ($htot as $s => $n) {
        // the name opens the species page; a click on the cells drills down to this species in the dashboard
        echo '<tr class="drill" data-n="' . intval($n) . '" data-tax="' . taxon_order($s) . '" data-q="' . $h(mb_strtolower(($com_of[$s] ?? $s) . ' ' . $s . ' ' . get_english_name($s))) . '" data-drill="' . $base . '&amp;from=' . $from . '&amp;to=' . $to . '&amp;sci=' . rawurlencode($s) . '"><td class="nm"><a href="views.php?view=Bird&amp;sci=' . rawurlencode($s) . '" title="Species page" data-com="' . $h($com_of[$s] ?? $s) . '" data-sci="' . $h($s) . '" data-en="' . $h(get_english_name($s)) . '">' . $h($com_of[$s] ?? $s) . '</a></td>';
        for ($b2 = 0; $b2 < 48; $b2++) echo $heatcell($hb[$s][$b2] ?? 0, $hmax, $b2 === $now_bin ? ' now' : '', $h(($com_of[$s] ?? $s) . ' · ' . sprintf('%02d:%02d', intdiv($b2, 2), ($b2 % 2) * 30) . ': ' . ($hb[$s][$b2] ?? 0)));
        echo '<td class="t">' . number_format($n) . '</td></tr>';
      } ?>
      <tr class="chart"><td class="nm"><b>Detections</b><br><small><?php echo number_format($hb_nmax); ?> max</small></td><?php
        for ($b2 = 0; $b2 < 48; $b2++) echo $cellbar($hb_n[$b2], $hb_nmax, 70, $b2 === $now_bin ? 'now' : '', sprintf('%02d:%02d', intdiv($b2, 2), ($b2 % 2) * 30) . ': ' . number_format($hb_n[$b2]) . ' detections'); ?><td class="t"><?php echo number_format(array_sum($hb_n)); ?></td></tr>
      <tr class="chart"><td class="nm"><b>Species</b><br><small><?php echo $hb_spmax; ?> max</small></td><?php
        for ($b2 = 0; $b2 < 48; $b2++) echo $cellbar($hb_sp[$b2], $hb_spmax, 50, 'sp', sprintf('%02d:%02d', intdiv($b2, 2), ($b2 % 2) * 30) . ': ' . $hb_sp[$b2] . ' species'); ?><td class="t"><?php echo count($htot); ?></td></tr>
    </table></div>
    <div class="note">Every species of the period, one cell per half hour; darker = more detections<?php echo $now_bin >= 0 ? ', the current half hour outlined' : ''; ?>. Under it: detections and species per half hour. Hover for numbers; click a name for the species page, click its cells for that species alone in this dashboard.</div>
    <?php } ?>
  </div>
  <div class="panel"><h3>The year by calendar week — detections and species<?php echo $sci !== '' ? ' · ' . $h($com_of[$sci] ?? $sci) : ''; ?></h3>
    <div class="hmwrap"><table class="hm">
      <tr><th class="nm"></th><?php
        for ($k = 1; $k <= 53; ) { $span = 1; while ($k + $span <= 53 && $kmon[$k + $span] === $kmon[$k]) $span++; echo '<th class="m" colspan="' . $span . '">' . ($mnames[$kmon[$k]] ?? '') . '</th>'; $k += $span; } ?><th></th></tr>
      <tr><th class="nm"></th><?php for ($k = 1; $k <= 53; $k++) echo '<th class="k">' . ($k % 4 === 1 ? $k : '') . '</th>'; ?><th class="t">Total</th></tr>
      <tr class="chart"><td class="nm"><b>Detections</b><br><small><?php echo number_format($kw_nmax); ?> max</small></td><?php
        for ($k = 1; $k <= 53; $k++) echo $cellbar($kw_n[$k], $kw_nmax, 70, $k === $cur_kw ? 'now' : '', 'KW' . $k . ': ' . number_format($kw_n[$k]) . ' detections'); ?><td class="t"><?php echo number_format(array_sum($kw_n)); ?></td></tr>
      <tr class="chart"><td class="nm"><b>Species</b><br><small><?php echo $kw_spmax; ?> max</small></td><?php
        for ($k = 1; $k <= 53; $k++) echo $cellbar($kw_sp[$k], $kw_spmax, 50, 'sp', 'KW' . $k . ': ' . $kw_sp[$k] . ' species'); ?><td class="t"><?php $allsp = array(); foreach ($kw_spset as $x) $allsp += $x; echo count($allsp); ?></td></tr>
    </table></div>
    <div class="note">Detections and species per calendar week (KW, this week in orange). Every date recorded; hover for numbers. The species of each week are on <a href="views.php?view=Seasonality">Seasonality</a>.</div>
  </div>
</div>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script>
document.querySelectorAll('tr.drill').forEach(function (tr) {
  tr.addEventListener('click', function (e) { if (e.target.closest('td.c')) location.href = tr.dataset.drill.replace(/&amp;/g, '&'); });
});

</script>
