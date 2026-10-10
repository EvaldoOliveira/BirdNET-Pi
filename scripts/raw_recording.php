<?php
/* Raw Recording page (owner 2026-10-08): scheduled long, unprocessed recordings (dawn chorus) from the shared
 * microphone, in parallel with the analysis — days of the week, recurrent or once, start and end time, segment
 * length. Settings in birdnet.conf (RAW_REC_*), schedule in /etc/cron.d/birdnet_raw_recording
 * (raw_recording_cron.sh), sessions by raw_recording.sh into ~/BirdNET-Pi/raw-recording/. */
require_once __DIR__ . '/common.php';
ensure_authenticated();
$home = get_home();
$user = get_user();
$config = get_config();
$conf_file = '/etc/birdnet/birdnet.conf';
$out_dir = $home . '/BirdNET-Pi/raw-recording';
$dow = array(1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun');
$message = '';

function set_conf_key($contents, $key, $value, $comment) {
  if (preg_match("/^$key=/m", $contents)) return preg_replace("/^$key=.*/m", "$key=$value", $contents);
  return $contents . "\n## $comment\n$key=$value\n";
}
function hms($v, $default) {
  return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$v) ? (strlen($v) == 5 ? "$v:00" : $v) : $default;
}

if (isset($_POST['raw_save'])) {
  $enabled = isset($_POST['enabled']) ? 1 : 0;
  $days = array();
  foreach ($dow as $n => $label) if (isset($_POST['day_' . $n])) $days[] = $n;
  $recurrent = isset($_POST['recurrent']) ? 1 : 0;
  $start = hms($_POST['start'] ?? '', '04:00:00');
  $end = hms($_POST['end'] ?? '', '10:00:00');
  $segment = max(1, min(240, intval($_POST['segment'] ?? 30)));
  $c = file_get_contents($conf_file);
  $c = set_conf_key($c, 'RAW_REC_ENABLED', $enabled, 'RAW_REC_*: scheduled raw recording (Raw Recording page); 1 = on');
  $c = set_conf_key($c, 'RAW_REC_DAYS', '"' . implode(',', $days) . '"', 'days of the week to record (cron numbers, 0 = Sunday)');
  $c = set_conf_key($c, 'RAW_REC_RECURRENT', $recurrent, '1 = every chosen day, 0 = only the next one, then off');
  $c = set_conf_key($c, 'RAW_REC_START', $start, 'start time HH:MM:SS');
  $c = set_conf_key($c, 'RAW_REC_END', $end, 'end time HH:MM:SS (next day when earlier than the start)');
  $c = set_conf_key($c, 'RAW_REC_SEGMENT_MIN', $segment, 'maximum length of each WAV file in minutes');
  file_put_contents($conf_file, $c);
  $message = trim((string)shell_exec('sudo ' . escapeshellarg($home . '/BirdNET-Pi/scripts/raw_recording_cron.sh') . ' 2>&1'));
  $config = get_config(true);
} elseif (isset($_POST['raw_now'])) {
  shell_exec('sudo -u ' . escapeshellarg($user) . ' nohup ' . escapeshellarg($home . '/BirdNET-Pi/scripts/raw_recording.sh') . ' --now > /dev/null 2>&1 &');
  $message = 'Recording started now; it stops at the end time.';
} elseif (isset($_POST['raw_stop'])) {
  shell_exec('sudo pkill -f ' . escapeshellarg('BirdNET-Pi/scripts/raw_recording.sh') . ' 2>&1; sudo pkill -f ' . escapeshellarg('arecord .*raw-recording') . ' 2>&1');
  $message = 'Recording stopped.';
}

$enabled = ($config['RAW_REC_ENABLED'] ?? '0') == '1';
$days = array_filter(explode(',', trim($config['RAW_REC_DAYS'] ?? '', '"')), 'strlen');
$recurrent = ($config['RAW_REC_RECURRENT'] ?? '1') == '1';
$start = hms($config['RAW_REC_START'] ?? '', '04:00:00');
$end = hms($config['RAW_REC_END'] ?? '', '10:00:00');
$segment = intval($config['RAW_REC_SEGMENT_MIN'] ?? 30) ?: 30;
$running = trim((string)shell_exec("pgrep -f 'BirdNET-Pi/scripts/raw_recording.sh' 2>/dev/null")) !== '';

// sessions: the .recording logs, newest first, with their WAV files
$sessions = array();
foreach (glob($out_dir . '/*.recording') ?: array() as $log) {
  $base = basename($log, '.recording');
  $day = substr($base, 0, 10);
  $wavs = glob($out_dir . '/' . $day . '-*.wav') ?: array();
  $size = 0;
  foreach ($wavs as $w) $size += filesize($w);
  $sessions[] = array($base, count($wavs), $size);
}
rsort($sessions);
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<div class="settings">
<form method="POST" action="views.php?view=Raw%20Recording">
<table class="settingstable"><tr><td>
  <p>Long, unprocessed WAV recordings (e.g. the whole dawn chorus) from the station's microphone, made while the
  analysis keeps running.<br>Files: <code>~/BirdNET-Pi/raw-recording/YYYY-MM-DD-&lt;time&gt;-&lt;station&gt;.wav</code>
  (48 kHz, 16-bit) with a <code>.recording</code> session log.</p>
  <?php if ($message !== '') echo '<p><b>' . $h($message) . '</b></p>'; ?>
  <label><input type="checkbox" name="enabled" <?php echo $enabled ? 'checked' : ''; ?>> Scheduled recording on</label><br><br>
  <b>Days:</b>
  <?php foreach ($dow as $n => $label) {
    echo '<label style="margin-right:8px"><input type="checkbox" name="day_' . $n . '"' . (in_array((string)$n, $days, true) ? ' checked' : '') . '> ' . $label . '</label>';
  } ?><br>
  <label><input type="checkbox" name="recurrent" <?php echo $recurrent ? 'checked' : ''; ?>> Recurrent (every chosen day;<br>untick = only the next one, then off)</label><br><br>
  <label>Start: <input type="time" step="1" name="start" value="<?php echo $h($start); ?>"></label>
  <label style="margin-left:12px">End: <input type="time" step="1" name="end" value="<?php echo $h($end); ?>"></label>
  <small>(an end earlier than the start ends the next day)</small><br><br>
  <label>Segment length (max): <input type="number" name="segment" min="1" max="240" style="width:5em" value="<?php echo $segment; ?>"> minutes per WAV file</label><br><br>
  <button type="submit" name="raw_save" value="1">Save schedule</button>
  <button type="submit" name="raw_now" value="1" style="margin-left:8px">Record now (until the end time)</button>
  <?php if ($running) { ?><button type="submit" name="raw_stop" value="1" style="margin-left:8px">Stop recording</button><?php } ?>
  <p>Status: <b><?php echo $running ? 'recording now' : 'not recording'; ?></b> —
  schedule <?php echo $enabled && $days ? 'on, ' . $h($start) . ' to ' . $h($end) . ' on ' . $h(implode(', ', array_map(function ($d) use ($dow) { return $dow[intval($d)]; }, $days))) . ($recurrent ? ' (every week)' : ' (once)') : 'off'; ?>.</p>
</td></tr></table>
</form>
<br>
<table class="settingstable"><tr><td>
  <h2>Sessions</h2>
  <?php if (!$sessions) { echo '<p>No raw recordings yet.</p>'; } else { ?>
  <table><tr><th>Session</th><th>WAV files (day)</th><th>Size</th></tr>
  <?php foreach (array_slice($sessions, 0, 30) as $s) {
    echo '<tr><td>' . $h($s[0]) . '</td><td>' . $s[1] . '</td><td>' . round($s[2] / 1048576) . ' MB</td></tr>';
  } ?>
  </table>
  <?php } ?>
  <p><small>Raw recordings are not deleted automatically — keep an eye on the disk space (System › System Info).</small></p>
</td></tr></table>
</div>
