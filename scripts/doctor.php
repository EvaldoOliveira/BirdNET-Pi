<?php
/* Station Doctor (owner 2026-10-08): one page that says whether the station is healthy and, when it is not,
 * what is wrong and the one-click fix. Checks: services, microphone, recording and analysis backlog, latest
 * detection, disk and purge protection, quarantined recordings, model files, the location (region) profile,
 * raw recording, sound-repository spool, version and new release, clock, power and temperature, database.
 * views.php?view=Doctor = the page; scripts/doctor.php?format=json = the same checks as JSON (for monitoring,
 * e.g. the Mesh menu): allowed from the station itself or an authenticated session. */
require_once __DIR__ . '/common.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
set_timezone();
$json = (($_GET['format'] ?? '') === 'json');
if ($json && !in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) {
  ensure_authenticated('You must be authenticated to read the station health.');
}
$home = get_home();
$user = get_user();
$config = get_config();
$checks = array();
function check(&$checks, $group, $name, $status, $detail, $fix = null) {
  $checks[] = array('group' => $group, 'name' => $name, 'status' => $status, 'detail' => $detail, 'fix' => $fix);
}
$sh = function ($cmd) { return trim((string)shell_exec($cmd . ' 2>/dev/null')); };
$age = function ($seconds) {
  if ($seconds < 120) return $seconds . ' s';
  if ($seconds < 7200) return round($seconds / 60) . ' min';
  if ($seconds < 172800) return round($seconds / 3600) . ' h';
  return round($seconds / 86400) . ' days';
};

// services: enabled but not running = failure, with a restart button (the commands views.php allows)
$fixable = array('birdnet_analysis', 'birdnet_recording', 'birdnet_log', 'birdnet_stats', 'chart_viewer', 'spectrogram_viewer', 'web_terminal');
foreach (array('birdnet_recording' => 'Recording', 'birdnet_analysis' => 'Analysis', 'chart_viewer' => 'Daily chart',
               'spectrogram_viewer' => 'Spectrogram', 'birdnet_log' => 'Log viewer', 'birdnet_stats' => 'Species Stats (Streamlit)',
               'livestream' => 'Live audio', 'icecast2' => 'Live audio server', 'web_terminal' => 'Web terminal',
               'caddy' => 'Web server', 'php8.4-fpm' => 'PHP') as $svc => $label) {
  $active = $sh("systemctl is-active $svc.service");
  $enabled = $sh("systemctl is-enabled $svc.service");
  if ($active === 'active') {
    check($checks, 'Services', $label, 'ok', "$svc running");
  } elseif ($enabled === 'enabled') {
    $fix = $svc === 'livestream' || $svc === 'icecast2'
      ? array('Restart live audio', 'sudo systemctl restart livestream.service && sudo systemctl restart icecast2.service')
      : (in_array($svc, $fixable, true) ? array('Restart', "sudo systemctl restart $svc.service") : null);
    check($checks, 'Services', $label, 'fail', "$svc is $active although enabled", $fix);
  } else {
    check($checks, 'Services', $label, 'info', "$svc disabled");
  }
}

// microphone
$cards = (string)@file_get_contents('/proc/asound/cards');
$usb = preg_match_all('/USB-Audio|USB Audio/i', $cards);
$rec_card = trim($config['REC_CARD'] ?? '', '"');
if ($usb > 0) check($checks, 'Audio', 'Microphone', 'ok', "$usb USB sound card(s) present; recording device " . ($rec_card ?: 'default'));
else check($checks, 'Audio', 'Microphone', 'fail', 'no USB sound card — microphone unplugged? (the station stops until it is back)');

// recording and analysis backlog
$recs = rtrim($config['RECS_DIR'] ?? ($home . '/BirdSongs'), '/');
$stream = glob($recs . '/StreamData/*.wav') ?: array();
$len = max(1, intval($config['RECORDING_LENGTH'] ?? 15));
if ($stream) {
  $newest = max(array_map('filemtime', $stream));
  $lag = time() - $newest;
  check($checks, 'Audio', 'Recording', $lag <= 3 * $len + 30 ? 'ok' : 'fail', 'newest chunk ' . $age($lag) . ' old');
  $n = count($stream);
  check($checks, 'Audio', 'Analysis backlog', $n <= 10 ? 'ok' : ($n <= 40 ? 'warn' : 'fail'),
        "$n chunks waiting (" . $age($n * $len) . ' of audio)' . ($n > 10 ? ' — the analysis is not keeping up' : ''),
        $n > 10 ? array('Restart analysis', 'sudo systemctl restart birdnet_analysis.service') : null);
} else {
  check($checks, 'Audio', 'Recording', 'fail', 'no chunks in StreamData — the recording is not running',
        array('Restart recording', 'sudo systemctl restart birdnet_recording.service'));
}

// latest detection
$db = get_db();
$last = $db->querySingle("SELECT Date || ' ' || Time FROM detections ORDER BY Date DESC, Time DESC LIMIT 1");
if ($last) {
  $since = time() - strtotime($last);
  check($checks, 'Detections', 'Latest detection', $since < 86400 ? 'ok' : 'warn', "$last (" . $age(max(0, $since)) . ' ago)');
} else {
  check($checks, 'Detections', 'Latest detection', 'warn', 'no detections yet');
}
$total = intval($db->querySingle('SELECT COUNT(*) FROM detections'));
$dbsize = @filesize(__DIR__ . '/birds.db');
check($checks, 'Detections', 'Database', 'ok', number_format($total) . ' detections, ' . round($dbsize / 1048576, 1) . ' MB');
if (not_rejected_sql() !== '') {
  $rv = $db->querySingle("SELECT COUNT(*) FROM detection_reviews");
  check($checks, 'Detections', 'Reviews', 'info', intval($rv) . (intval($rv) == 1 ? ' detection' : ' detections') . ' reviewed');
}

// disk and purge
$extracted = $config['EXTRACTED'] ?? ($recs . '/Extracted');
$total_b = @disk_total_space($extracted);
$free_b = @disk_free_space($extracted);
if ($total_b) {
  $used = round(100 * (1 - $free_b / $total_b));
  $limit = intval($config['PURGE_THRESHOLD'] ?? 95);
  $mode = $config['FULL_DISK'] ?? 'purge';
  check($checks, 'Disk', 'Disk space', $used >= $limit ? 'fail' : ($used >= $limit - 10 ? 'warn' : 'ok'),
        "$used% used, " . round($free_b / 1073741824) . " GB free; at $limit% the station will " . ($mode === 'keep' ? 'STOP (keep)' : 'purge old clips'));
}
$excl = __DIR__ . '/disk_check_exclude.txt';
$lines = is_file($excl) ? file($excl, FILE_IGNORE_NEW_LINES) : array();
$s = array_search('##start', $lines, true);
$e = array_search('##end', $lines, true);
if ($s !== false && $e !== false) {
  $auto = intdiv($e - $s - 1, 2);
  $manual = count(array_filter(array_slice($lines, $e + 1), function ($l) { return $l !== '' && substr($l, -4) !== '.png'; }));
  check($checks, 'Disk', 'Purge protection', 'ok', "$auto clips protected automatically (best " . intval($config['PURGE_PROTECT_TOP_N'] ?? 3)
        . " per species + confirmed), $manual locked by hand — list of " . date('Y-m-d H:i', filemtime($excl)));
} else {
  check($checks, 'Disk', 'Purge protection', 'warn', 'the protection list has not been built yet (it is built before the first purge)');
}
$quar = glob($recs . '/quarantine/*.wav') ?: array();
check($checks, 'Disk', 'Quarantine', $quar ? 'warn' : 'ok',
      $quar ? count($quar) . ' recordings failed to be reported and were kept in ' . $recs . '/quarantine' : 'no failed recordings');
$raw = glob($home . '/BirdNET-Pi/raw-recording/*.wav') ?: array();
$raw_on = ($config['RAW_REC_ENABLED'] ?? '0') == '1';
$raw_size = array_sum(array_map('filesize', $raw));
check($checks, 'Disk', 'Raw recording', 'info', ($raw_on ? 'schedule on' : 'schedule off') . ', ' . count($raw) . ' files, '
      . round($raw_size / 1073741824, 1) . ' GB (never deleted automatically)');
$spool = trim($config['SOUND_REPO_PATH'] ?? '', '"');
if ($spool !== '') {
  $waiting = intval($sh('find ' . escapeshellarg($spool) . ' -type f -mmin +60 | wc -l'));
  check($checks, 'Disk', 'Sound repository spool', $waiting > 50 ? 'warn' : 'ok', "$waiting clips older than an hour waiting for the upload");
}

// model
$model = $config['MODEL'] ?? '';
$model_files = glob($home . '/BirdNET-Pi/model/' . $model . '.*') ?: array();
$is_v3 = stripos($model, 'V3') !== false;
$geo_ok = !$is_v3 || (glob($home . '/BirdNET-Pi/model/' . $model . '_Geo.onnx') ?: false);
check($checks, 'Model', 'Detection model', $model_files && $geo_ok ? 'ok' : 'fail',
      $model . ($model_files ? '' : ' — model file missing') . ($geo_ok ? '' : ' — location (geo) model missing')
      . '; confidence ' . ($config['CONFIDENCE'] ?? '?') . ', sensitivity ' . ($config['SENSITIVITY'] ?? '?')
      . ', overlap ' . ($config['OVERLAP'] ?? '?') . ', SF_THRESH ' . ($config['SF_THRESH'] ?? '?'));
$pf = __DIR__ . '/region_profile.json';
if (is_file($pf)) {
  $p = json_decode(file_get_contents($pf), true);
  check($checks, 'Model', 'Location profile', ($p['date'] ?? '') >= date('Y-m-d', strtotime('-2 days')) ? 'ok' : 'warn',
        count($p['data'] ?? array()) . ' species, computed ' . ($p['date'] ?? '?') . ' for ' . ($p['lat'] ?? '?') . ', ' . ($p['lon'] ?? '?'));
} elseif (($config['APPRISE_NOTIFY_REGION_RARE'] ?? '0') == '1') {
  check($checks, 'Model', 'Location profile', 'info', 'not computed yet — it is built at the first notification of the day');
}

// version
$branch = $sh("sudo -u $user git -C $home/BirdNET-Pi rev-parse --abbrev-ref HEAD");
$commit = $sh("sudo -u $user git -C $home/BirdNET-Pi log -1 --format='%h %cs'");
$tag = $sh("sudo -u $user git -C $home/BirdNET-Pi describe --tags --match 'v[0-9]*'");
$new = $_SESSION['release_new'] ?? '';
check($checks, 'System', 'Version', $new !== '' ? 'warn' : 'ok', "$tag on $branch ($commit)"
      . ($new !== '' ? " — new release $new available" : ''), $new !== '' ? array('Update', 'update_birdnet.sh') : null);

// clock, power, temperature, load
$ntp = $sh('timedatectl show -p NTPSynchronized --value');
check($checks, 'System', 'Clock', $ntp === 'yes' ? 'ok' : 'warn', $ntp === 'yes' ? 'synchronised (NTP)' : 'not synchronised — detection times may be wrong');
$thr = $sh('sudo -n vcgencmd get_throttled');
if (preg_match('/0x([0-9a-f]+)/i', $thr, $m)) {
  $bits = hexdec($m[1]);
  $now = $bits & 0xF;
  $past = ($bits >> 16) & 0xF;
  $txt = array();
  if ($now & 1) $txt[] = 'under-voltage NOW';
  if ($now & 4) $txt[] = 'throttled NOW';
  if ($past & 1) $txt[] = 'under-voltage since boot';
  if ($past & 4) $txt[] = 'throttled since boot';
  check($checks, 'System', 'Power', $now ? 'fail' : ($past ? 'warn' : 'ok'), $txt ? implode(', ', $txt) . ' — check the power supply' : 'no under-voltage or throttling');
}
$temp = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
if ($temp !== false) {
  $c = round(intval($temp) / 1000);
  check($checks, 'System', 'CPU temperature', $c >= 80 ? 'fail' : ($c >= 70 ? 'warn' : 'ok'), "$c °C");
}
$load = sys_getloadavg();
$cores = max(1, intval($sh('nproc')));
check($checks, 'System', 'Load', $load[1] > $cores ? 'warn' : 'ok', sprintf('%.2f (5 min) on %d cores', $load[1], $cores));
$up = intval(explode(' ', (string)@file_get_contents('/proc/uptime'))[0]);
check($checks, 'System', 'Uptime', 'info', $age($up));

$worst = 'ok';
foreach ($checks as $c) {
  if ($c['status'] === 'fail') { $worst = 'fail'; break; }
  if ($c['status'] === 'warn') $worst = 'warn';
}

if ($json) {
  header('Content-Type: application/json');
  echo json_encode(array('station' => get_sitename(), 'time' => date('c'), 'status' => $worst,
    'checks' => array_map(function ($c) { return array('group' => $c['group'], 'name' => $c['name'], 'status' => $c['status'], 'detail' => $c['detail']); }, $checks)),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$icon = array('ok' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'info' => 'ℹ️');
?>
<style>
.doctor { max-width: 900px; margin: 0 auto; text-align: left; padding: 0 12px; }
.doctor table { width: 100%; border-collapse: collapse; }
.doctor td { text-align: left !important; padding: 5px 6px; border-top: 1px solid rgba(128,128,128,.25); vertical-align: top; }
.doctor td.st { width: 1.6em; }
.doctor td.nm { width: 30%; font-weight: bold; }
.doctor tr.grp td { font-weight: bold; border-top: none; padding-top: 14px; font-size: 1.05em; opacity: .8; }
.doctor .summary { padding: 10px 12px; border-radius: 8px; margin: 10px 0; }
.doctor .summary.ok { background: rgba(46,125,50,.15); } .doctor .summary.warn { background: rgba(217,122,0,.18); } .doctor .summary.fail { background: rgba(198,40,40,.18); }
</style>
<div class="doctor">
  <h2>Station Doctor</h2>
  <div class="summary <?php echo $worst; ?>"><?php echo $icon[$worst] . ' ' . ($worst === 'ok' ? 'Everything looks healthy.' : ($worst === 'warn' ? 'The station works, with warnings below.' : 'Something needs attention — see the red lines.')); ?>
    <small style="float:right"><?php echo date('Y-m-d H:i:s'); ?> · <a href="views.php?view=Doctor">check again</a> · <a href="scripts/doctor.php?format=json" target="_blank">JSON</a></small></div>
  <table>
  <?php $g = '';
  foreach ($checks as $c) {
    if ($c['group'] !== $g) { $g = $c['group']; echo '<tr class="grp"><td colspan="3">' . $h($g) . '</td></tr>'; }
    $fix = '';
    if ($c['fix']) {
      $fix = ' <form action="views.php" method="GET" style="display:inline"><button type="submit" name="submit" value="' . $h($c['fix'][1]) . '">' . $h($c['fix'][0]) . '</button></form>';
    }
    echo '<tr><td class="st">' . $icon[$c['status']] . '</td><td class="nm">' . $h($c['name']) . '</td><td>' . $h($c['detail']) . $fix . '</td></tr>';
  } ?>
  </table>
</div>
