<?php
/* Seasonality (owner 2026-10-09): when in the year each species is heard — every species detected at the station,
 * one row each, one cell per calendar week (KW1–KW53, the week belongs to the month of its Thursday), darker = more
 * detections. Every date recorded. A filter box narrows the rows; a click on a name opens the species page.
 * Opened as views.php?view=Seasonality. */
require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$nr = not_rejected_sql();
// year: every year together (default) or one ISO year; a calendar week belongs to the year of its Thursday
$this_year = intval(date('o'));
$year = isset($_GET['year']) && preg_match('/^\d{4}$/', $_GET['year']) ? intval($_GET['year']) : 0;
// a range of years (owner 2026-10-10, as the date range of By Hour): yfrom..yto; one year = year
$yfrom = isset($_GET['yfrom']) && preg_match('/^\d{4}$/', $_GET['yfrom']) ? intval($_GET['yfrom']) : $year;
$yto = isset($_GET['yto']) && preg_match('/^\d{4}$/', $_GET['yto']) ? intval($_GET['yto']) : $yfrom;
if ($yfrom && $yto < $yfrom) { $t = $yfrom; $yfrom = $yto; $yto = $t; }
if ($yfrom && $yfrom === $yto) $year = $yfrom; elseif ($yfrom) $year = 0;
$first_year = intval(date('o', strtotime((string)$db->querySingle('SELECT MIN(Date) FROM detections') ?: date('Y-m-d'))));
$by = array(); $tot = array(); $com = array();
// the cards (as By Hour): the previous range of the same length, for the change
$pspan = $yfrom ? $yto - $yfrom + 1 : 0; $p_n = 0; $p_sp = array();
$res = $db->query('SELECT Sci_Name, MAX(Com_Name) AS com, Date, COUNT(*) AS n FROM detections WHERE 1' . $nr . ' GROUP BY Sci_Name, Date');
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
  $t = strtotime($r['Date']);
  $iy = intval(date('o', $t));
  if ($pspan && $iy >= $yfrom - $pspan && $iy <= $yto - $pspan) { $p_n += $r['n']; $p_sp[$r['Sci_Name']] = 1; }
  if ($yfrom && ($iy < $yfrom || $iy > $yto)) continue;
  $k = intval(date('W', $t));
  $by[$r['Sci_Name']][$k] = ($by[$r['Sci_Name']][$k] ?? 0) + $r['n'];
  $tot[$r['Sci_Name']] = ($tot[$r['Sci_Name']] ?? 0) + $r['n'];
  $com[$r['Sci_Name']] = $r['com'];
}
arsort($tot);
// totals per week for the chart under the table (aligned with the week columns)
$wk_n = array_fill(1, 53, 0); $wk_sp = array_fill(1, 53, 0);
foreach ($by as $sci => $weeks) foreach ($weeks as $k => $v) { $wk_n[$k] += $v; $wk_sp[$k]++; }
$wk_nmax = max(1, max($wk_n)); $wk_spmax = max(1, max($wk_sp));
$max = 1; foreach ($by as $x) $max = max($max, max($x));
$cur = (!$yfrom || $yto === $this_year) ? intval(date('W')) : 0;
$yr = $yfrom ? $yto : $this_year;
$mon = array();
for ($k = 1; $k <= 53; $k++) {
  $thu = new DateTime(); $thu->setISODate($yr, $k, 4);
  $mon[$k] = intval($thu->format('Y')) === $yr ? intval($thu->format('n')) : ($k > 40 ? 13 : 0);
}
// cards: value, change vs the previous range, sparkline of the weeks (the same helpers as By Hour)
$sparkline = function ($vals) {
  $vals = array_values($vals); $w = 120; $hgt = 28; $mx = max(1, max($vals)); $n = count($vals);
  $pts = array();
  foreach ($vals as $i => $v) $pts[] = round($i * $w / max(1, $n - 1), 1) . ',' . round($hgt - 2 - ($hgt - 4) * $v / $mx, 1);
  return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none"><polygon points="0,' . $hgt . ' ' . implode(' ', $pts) . ' ' . $w . ',' . $hgt . '" class="sa"/><polyline points="' . implode(' ', $pts) . '" class="sl"/></svg>';
};
$delta = function ($c, $p) use ($pspan) {
  if (!$pspan) return '<small class="dl">every year together</small>';
  $label = $pspan === 1 ? 'vs the year before' : "vs previous $pspan years";
  if (!$p) return '<small class="dl">no data ' . $label . '</small>';
  $pct = round(100 * ($c - $p) / $p);
  return '<small class="dl ' . ($pct > 0 ? 'up' : ($pct < 0 ? 'down' : '')) . '">' . ($pct > 0 ? '▲ ' : ($pct < 0 ? '▼ ' : '')) . abs($pct) . '% ' . $label . '</small>';
};
$names = array(1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
?>
<style>
.sea .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin: 0 0 16px; max-width: 1040px; }
.sea .card { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px 6px; box-shadow: 0 1px 3px rgba(0,0,0,.08); text-align: left; }
.sea .card small.t { color: #555; } .sea .card b { display: block; font-size: 1.7em; line-height: 1.2; }
.sea .dl { font-size: 11px; color: #666; } .sea .dl.up { color: #2e7d32; } .sea .dl.down { color: #c62828; }
.sea svg.spark { width: 100%; height: 28px; display: block; margin-top: 4px; }
.sea svg.spark .sl { fill: none; stroke: var(--accent,#2b5e22); stroke-width: 1.6; vector-effect: non-scaling-stroke; } .sea svg.spark .sa { fill: rgba(var(--accent-rgb,43,94,34),.15); }
.sea { max-width: 1300px; margin: 0 auto; padding: 0 12px; text-align: left; }
.sea .top { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin: 6px 0 10px; }
.sea .top input { padding: 5px 8px; font-size: 14px; }
/* title, then the period control and the year range on the left (as By Hour), sort + filter on the right */
.sea .top { justify-content: flex-start; gap: 8px 18px; } .sea .top > h2, .sea .top .periodnav, .sea .top .yrange { flex: 0 0 auto; } .sea .top > .right { flex: 1 1 auto; justify-content: flex-end; }
.sea .top .right { display: flex; justify-content: flex-end; align-items: center; gap: 10px; } .sea th.spcount { font-weight: normal; text-align: left; white-space: nowrap; } .sea .top .right input { width: 220px; max-width: 100%; }
.sea .wrap { overflow-x: auto; background: rgba(255,255,255,.65); border-radius: 12px; padding: 8px 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.sea table { border-collapse: separate; border-spacing: 2px; font-size: 12px; }
.sea th, .sea td { padding: 0; }
.sea td.nm, .sea th.nm { text-align: left !important; white-space: nowrap; padding-right: 8px; position: sticky; left: 0; background: var(--panel,#e8f5ea); z-index: 1; }
.sea td.nm i { color: #555; font-size: 11px; }
.sea td.c { width: 14px; min-width: 14px; height: 16px; border-radius: 2px; background: #fff !important; border: none !important; }
.sea td.c.norec { background: #e9ecea !important; }
.sea td.c.now { outline: 1px solid #d97a00; }
.sea td.c[data-drill] { cursor: pointer; } .sea td.c[data-drill]:hover { box-shadow: inset 0 0 0 2px #d97a00; }
.sea th.m { text-align: left !important; font-size: 11px; font-weight: 600; border-left: 2px solid rgba(var(--accent-rgb,43,94,34),.45); padding-left: 2px; }
.sea th.k { font-size: 9px; font-weight: normal; color: #666; text-align: left !important; }
.sea tr.chart td { border: none !important; background: transparent !important; vertical-align: bottom; }
.sea tr.chart td.nm { background: var(--panel,#e8f5ea) !important; vertical-align: bottom; padding-top: 10px; }
.sea tr.chart td.b { height: 74px; padding-top: 10px; }
.sea .bar { width: 100%; background: rgba(var(--accent-rgb,43,94,34),.8); border-radius: 2px 2px 0 0; }
.sea .bar.now { background: #d97a00; } .sea .bar.sp { background: #1565c0; }
.sea td.t { text-align: right !important; padding-left: 8px; white-space: nowrap; }
.sea .note { font-size: 12px; color: #555; margin-top: 6px; }
</style>
<div class="sea">
  <div class="cards">
    <div class="card"><small class="t">Detections</small><b><?php echo number_format(array_sum($wk_n)); ?></b><?php echo $delta(array_sum($wk_n), $p_n) . $sparkline($wk_n); ?></div>
    <div class="card"><small class="t">Species</small><b><?php echo count($tot); ?></b><?php echo $delta(count($tot), count($p_sp)) . $sparkline($wk_sp); ?></div>
  </div>
  <div class="top">
    <?php $yurl = function ($y) { return 'views.php?view=Seasonality' . ($y ? '&amp;year=' . $y : ''); };
      $sel = $year ?: 0; $shown = $year ?: $this_year; ?>
    <?php // period navigation, the same control as By Hour: All years · ◀ · This year / the years · ▶ (moves by the range's
      // length) — plus a From / To year range
      $yurl2 = function ($f, $t) { return 'views.php?view=Seasonality' . ($f ? '&amp;yfrom=' . $f . '&amp;yto=' . $t : ''); };
      $span = $yfrom ? $yto - $yfrom + 1 : 1;
      $ylabel = !$yfrom ? 'This year' : ($yfrom === $yto ? ($yfrom === $this_year ? 'This year' : (string)$yfrom) : $yfrom . ' – ' . $yto); ?>
    <span class="periodnav">
      <a class="<?php echo $yfrom ? '' : 'on'; ?>" href="<?php echo $yurl2(0, 0); ?>">All years</a>
      <a class="<?php echo ($yfrom && $yfrom <= $first_year) ? 'off' : ''; ?>" href="<?php echo $yfrom ? $yurl2($yfrom - $span, $yto - $span) : $yurl2($this_year - 1, $this_year - 1); ?>" title="Previous year">&#9664;</a>
      <a class="<?php echo ($yfrom && $yfrom === $yto && $yfrom === $this_year) ? 'on' : ''; ?>" href="<?php echo $yurl2($this_year, $this_year); ?>"><?php echo $h($ylabel); ?></a>
      <a class="<?php echo (!$yfrom || $yto >= $this_year) ? 'off' : ''; ?>" href="<?php echo $yurl2($yfrom + $span, min($this_year, $yto + $span)); ?>" title="Next year">&#9654;</a>
    </span>
    <form class="yrange" method="GET" action="views.php"><input type="hidden" name="view" value="Seasonality">
      From <select name="yfrom"><?php for ($y = $first_year; $y <= $this_year; $y++) echo '<option' . ($y === ($yfrom ?: $first_year) ? ' selected' : '') . '>' . $y . '</option>'; ?></select>
      to <select name="yto"><?php for ($y = $first_year; $y <= $this_year; $y++) echo '<option' . ($y === ($yto ?: $this_year) ? ' selected' : '') . '>' . $y . '</option>'; ?></select>
      <button type="submit">Filter</button></form>
    <span class="right"><span class="hmsort" data-table="seatable" data-store="byweek"><small>Sort:</small><button type="button" data-sort="n">Count</button><button type="button" data-sort="tax" title="Field-guide order (eBird/Clements taxonomy)">Taxonomy</button><button type="button" data-sort="az" title="Alphabetical, by the names shown">A–Z</button></span>
      <input type="search" class="spfilter" data-table="seatable" placeholder="Filter species..."></span>
  </div>
  <?php if (!$tot) { echo '<div class="wrap"><p>No detections in ' . ($year ?: 'any year') . '.</p></div>'; } else { ?>
  <div class="wrap"><table id="seatable" class="hmsorted">
    <tr><th class="nm spcount"><?php echo count($tot); ?> species</th><?php
      for ($k = 1; $k <= 53; ) {
        $span = 1;
        while ($k + $span <= 53 && $mon[$k + $span] === $mon[$k]) $span++;
        echo '<th class="m" colspan="' . $span . '">' . ($names[$mon[$k]] ?? '') . '</th>';
        $k += $span;
      } ?><th></th></tr>
    <tr><th class="nm"><select class="namemode" title="Names shown"><option value="com">Common name</option><option value="sci">Scientific name</option><option value="en">English name</option></select></th><?php for ($k = 1; $k <= 53; $k++) echo '<th class="k">' . ($k % 4 === 1 ? $k : '') . '</th>'; ?><th class="t">Total</th></tr>
    <?php foreach ($tot as $sci => $n) {
      echo '<tr data-n="' . intval($n) . '" data-tax="' . taxon_order($sci) . '" data-q="' . $h(mb_strtolower($com[$sci] . ' ' . $sci . ' ' . get_english_name($sci))) . '"><td class="nm"><a href="views.php?view=Bird&amp;sci=' . rawurlencode($sci) . '" title="' . $h($sci) . '" data-com="' . $h($com[$sci]) . '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . $h($com[$sci]) . '</a></td>';
      for ($k = 1; $k <= 53; $k++) {
        $v = $by[$sci][$k] ?? 0;
        $a = $v ? 0.15 + 0.85 * log(1 + $v) / log(1 + $max) : 0;
        // a week the station recorded nothing at all stays light grey; a recorded week without this species is white
        $mon_d = (new DateTime())->setISODate($yr, $k, 1)->format('Y-m-d');
        $dl = $v ? ' data-drill="views.php?view=Dashboard&amp;from=' . $mon_d . '&amp;to=' . date('Y-m-d', strtotime($mon_d . ' +6 days')) . '&amp;sci=' . rawurlencode($sci) . '"' : '';
        echo '<td' . $dl . ' class="c' . ($wk_n[$k] ? '' : ' norec') . ($k === $cur ? ' now' : '') . '" title="' . $h($com[$sci] . ' · KW' . $k . ': ' . $v) . '"' . ($v ? ' style="background:rgba(var(--accent-rgb,43,94,34),' . round($a, 2) . ') !important"' : '') . '></td>';
      }
      echo '<td class="t">' . number_format($n) . '</td></tr>';
    } ?>
    <tr class="chart"><td class="nm"><b>Detections</b><br><small><?php echo number_format($wk_nmax); ?> max</small></td><?php
      for ($k = 1; $k <= 53; $k++) {
        echo '<td class="b" title="KW' . $k . ': ' . number_format($wk_n[$k]) . ' detections"><div class="bar' . ($k === $cur ? ' now' : '') . '" style="height:' . ($wk_n[$k] ? max(2, round(70 * $wk_n[$k] / $wk_nmax)) : 0) . 'px"></div></td>';
      } ?><td class="t"><?php echo number_format(array_sum($wk_n)); ?></td></tr>
    <tr class="chart"><td class="nm"><b>Species</b><br><small><?php echo $wk_spmax; ?> max</small></td><?php
      for ($k = 1; $k <= 53; $k++) {
        echo '<td class="b" title="KW' . $k . ': ' . $wk_sp[$k] . ' species"><div class="bar sp" style="height:' . ($wk_sp[$k] ? max(2, round(50 * $wk_sp[$k] / $wk_spmax)) : 0) . 'px"></div></td>';
      } ?><td class="t"><?php echo count($tot); ?></td></tr>
  </table></div>
  <?php } ?>
  <div class="note">Under the species: detections and number of species per calendar week, on the same columns. One row per species, one cell per calendar week (KW); darker = more detections, white = recorded but not heard, light grey = a week with no recording at all, the current week outlined in orange. <?php echo $yfrom ? ($yfrom === $yto ? 'Year ' . $yfrom . ' only' : 'Years ' . $yfrom . '–' . $yto) : 'Every year together'; ?>; hover for numbers; click a name for the species page, a green cell for that species and week in By Hour.</div>
</div>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script>
// a cell with detections opens the dashboard for that species and that week; the name opens the species page
document.querySelectorAll('td.c[data-drill]').forEach(function (td) {
  td.addEventListener('click', function () { location.href = td.dataset.drill.replace(/&amp;/g, '&'); });
});
</script>
