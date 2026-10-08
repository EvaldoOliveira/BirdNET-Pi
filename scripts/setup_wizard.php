<?php
/* First-run setup wizard (US-51c part 2). Shown by views.php instead of the Overview while
 * ~/BirdNET-Pi/firstrun_pending exists (an installation nobody answered: no terminal, no seed
 * file), and on demand from Tools -> Setup Wizard. Same answers as the installer questions
 * (scripts/install_firstrun.sh). Included from views.php ($config, $home, $user available). */
require_once 'scripts/common.php';

$pending_file = $home . '/BirdNET-Pi/firstrun_pending';
$pending = file_exists($pending_file);
// an unconfigured station without a web password is open on purpose; anything else needs the login
if (!($pending && empty($config['CADDY_PWD']))) {
  ensure_authenticated();
}

$model_v3 = 'BirdNET-Plus_V3.0-preview3.1_Global_10K';
$model_v24 = 'BirdNET_GLOBAL_6K_V2.4_Model_FP16';
$langs = array();
foreach (glob($home . '/BirdNET-Pi/model/l18n/labels_*.json') as $f) {
  $langs[] = preg_replace('/^labels_(.*)\.json$/', '$1', basename($f));
}
sort($langs);
$states = array();
foreach (glob($home . '/BirdNET-Pi/model/include_lists/BR-*.txt') as $f) {
  $states[] = substr(basename($f, '.txt'), 3);
}
sort($states);
$timezones = DateTimeZone::listIdentifiers();
$current_tz = trim(shell_exec('timedatectl show --value --property=Timezone 2>/dev/null'));

function wizard_set_key($contents, $key, $value) {
  if (preg_match("/^$key=/m", $contents)) {
    return preg_replace("/^$key=.*/m", "$key=" . str_replace('$', '\$', $value), $contents);
  }
  return rtrim($contents, "\n") . "\n$key=$value\n";
}

$errors = array();
$saved = false;
if (isset($_POST['wizard_save'])) {
  $p = array_map(function ($v) { return is_string($v) ? trim(htmlspecialchars_decode($v, ENT_QUOTES)) : $v; }, $_POST);
  // control characters too: a newline would leave a second, executable line in birdnet.conf
  $site_name = preg_replace('/[\x00-\x1F\x7F]/', '', str_replace(array('"', "'", '\\', '$', '`'), '', $p['site_name'] ?? ''));
  $lat = $p['latitude'] ?? '';
  $lon = $p['longitude'] ?? '';
  if (!is_numeric($lat) || $lat < -90 || $lat > 90) $errors[] = 'Latitude must be a number between -90 and 90';
  if (!is_numeric($lon) || $lon < -180 || $lon > 180) $errors[] = 'Longitude must be a number between -180 and 180';
  $tz = $p['timezone'] ?? '';
  if (!in_array($tz, $timezones, true)) $errors[] = 'Unknown timezone';
  $model = ($p['model'] ?? '') === 'V2.4' ? $model_v24 : $model_v3;
  $lang = $p['language'] ?? 'en';
  if ($lang === 'pt') $lang = 'pt_BR';
  if (!in_array($lang, $langs, true)) $errors[] = 'No species names for that language';
  $state = strtoupper($p['state'] ?? '');
  $state_detected = false;
  if ($state === 'AUTO' && empty($errors)) {
    // the state of the coordinates: OpenStreetMap online, the shipped IBGE boundaries offline
    $state = trim((string)shell_exec('python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/locate_state.py') . ' '
      . escapeshellarg((string)(float)$lat) . ' ' . escapeshellarg((string)(float)$lon) . ' 2>/dev/null'));
    $state_detected = true;
  }
  if ($state === 'AUTO') $state = '';
  if ($state !== '' && !in_array($state, $states, true)) $errors[] = 'No include list for that state';
  $pwd = $p['password'] ?? '';
  if ($pwd !== '' && !preg_match('/^[A-Za-z0-9]+$/', $pwd)) $errors[] = 'The password may only contain letters and digits';
  if ($pwd !== ($p['password2'] ?? '')) $errors[] = 'The two passwords differ';
  $bw = $p['birdweather_id'] ?? '';
  if ($bw !== '' && !preg_match('/^[A-Za-z0-9]+$/', $bw)) $errors[] = 'Invalid BirdWeather ID';
  $apprise = preg_replace('/[\x00-\x1F\x7F]/', '', $p['apprise_url'] ?? '');

  if (empty($errors)) {
    $contents = file_get_contents('/etc/birdnet/birdnet.conf');
    $old_model = $config['MODEL'] ?? '';
    $old_lang = $config['DATABASE_LANG'] ?? '';
    $contents = wizard_set_key($contents, 'SITE_NAME', "\"$site_name\"");
    $contents = wizard_set_key($contents, 'LATITUDE', round((float)$lat, 4));
    $contents = wizard_set_key($contents, 'LONGITUDE', round((float)$lon, 4));
    $contents = wizard_set_key($contents, 'MODEL', $model);
    if ($model !== $old_model) {
      // per-generation defaults (V3 = 0.25 / 1.0 / location 0.1, V2.4 = upstream 0.7 / 1.25 / 0.03)
      $contents = wizard_set_key($contents, 'CONFIDENCE', $model === $model_v3 ? '0.25' : '0.7');
      $contents = wizard_set_key($contents, 'SENSITIVITY', $model === $model_v3 ? '1.0' : '1.25');
      $contents = wizard_set_key($contents, 'SF_THRESH', $model === $model_v3 ? '0.1' : '0.03');
    }
    $contents = wizard_set_key($contents, 'DATABASE_LANG', $lang);
    $contents = wizard_set_key($contents, 'INCLUDE_REGION', $state === '' ? '' : "BR-$state");
    if (strpos($lang, 'pt') === 0 || $state !== '') $contents = wizard_set_key($contents, 'INFO_SITE', '"EBIRD"');
    $contents = wizard_set_key($contents, 'BIRDWEATHER_ID', $bw);
    $update_caddy = false;
    if ($pwd !== '' && $pwd !== ($config['CADDY_PWD'] ?? '')) {
      $contents = wizard_set_key($contents, 'CADDY_PWD', "\"$pwd\"");
      $update_caddy = true;
    }
    // notification titles and bodies stay English (owner 2026-10-07)
    file_put_contents('/etc/birdnet/birdnet.conf', $contents);
    if ($apprise !== '') file_put_contents($home . '/BirdNET-Pi/apprise.txt', $apprise . "\n");

    if ($tz !== $current_tz) {
      shell_exec('sudo timedatectl set-timezone ' . escapeshellarg($tz));
      if (file_exists('/etc/timezone')) shell_exec('echo ' . escapeshellarg($tz) . ' | sudo tee /etc/timezone > /dev/null');
    }
    // a new state rebuilds the Custom Species List: the state's birds + the model's non-bird classes
    $new_region = $state === '' ? '' : "BR-$state";
    if ($new_region !== ($config['INCLUDE_REGION'] ?? '')) {
      shell_exec('sudo -u ' . escapeshellarg($user) . ' python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/state_include_list.py') . ' > /dev/null 2>&1');
    }
    if ($model !== $old_model || $lang !== $old_lang) {
      shell_exec('sudo -u ' . escapeshellarg($user) . ' ' . escapeshellarg($home . '/BirdNET-Pi/scripts/install_language_label.sh') . ' > /dev/null 2>&1');
    }
    if ($update_caddy) exec('sudo /usr/local/bin/update_caddyfile.sh > /dev/null 2>&1 &');
    if ($pending) @unlink($pending_file);
    shell_exec('sudo restart_services.sh > /dev/null 2>&1 &');
    $saved = true;
    $config = get_config($force_reload = true);
  }
}

$cur_lang = $config['DATABASE_LANG'] ?? 'en';
$cur_state = preg_replace('/^BR-/', '', $config['INCLUDE_REGION'] ?? '');
$cur_model = ($config['MODEL'] ?? '') === $model_v24 ? 'V2.4' : 'V3';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<div class="settings">
  <div class="brbanner"><h1>Station setup</h1></div><br>
<?php if ($saved) { ?>
  <table class="settingstable"><tr><td>
    <h2>Saved</h2>
    <p>The station restarts its services with the new settings. Detections start appearing on the
    <a href="views.php?view=Overview">Overview</a> in a few minutes<?php echo $update_caddy ? ' — Tools and Settings now ask for the user <b>birdnet</b> and your password' : ''; ?>.</p>
    <?php if ($state_detected) { echo '<p>Brazilian states include list detected from the coordinates: <b>' . ($state === '' ? 'none (outside Brazil)' : $h($state)) . '</b>.</p>'; } ?>
  </td></tr></table>
<?php } else { ?>
<?php if ($pending) { ?>
  <p>Welcome! This station was installed without answering the first-run questions. Check the values below
  (the location, timezone and state were guessed from the network) and save to start detecting.</p>
<?php } ?>
<?php foreach ($errors as $e) { echo '<p style="color:red">' . $h($e) . '</p>'; } ?>
  <form method="POST" action="views.php?view=Setup">
  <table class="settingstable"><tr><td>
    <h2>Station</h2>
    <label>Station name: <input name="site_name" type="text" value="<?php echo $h($config['SITE_NAME'] ?? ''); ?>"></label><br>
    <label>Latitude: <input name="latitude" type="number" step="0.0001" min="-90" max="90" style="width:9em" value="<?php echo $h($config['LATITUDE'] ?? ''); ?>" required></label>
    <label>Longitude: <input name="longitude" type="number" step="0.0001" min="-180" max="180" style="width:9em" value="<?php echo $h($config['LONGITUDE'] ?? ''); ?>" required></label><br>
    <label>Timezone: <select name="timezone">
<?php foreach ($timezones as $tz) { echo '<option' . ($tz === $current_tz ? ' selected' : '') . '>' . $h($tz) . '</option>'; } ?>
    </select></label>
    <p><small>Detections are stamped with this timezone. Get coordinates on <a href="https://latlong.net" target="_blank">latlong.net</a>.</small></p>
  </td></tr></table><br>
  <table class="settingstable"><tr><td>
    <h2>Detection</h2>
    <label>Model: <select name="model">
      <option value="V3"<?php echo $cur_model === 'V3' ? ' selected' : ''; ?>>BirdNET+ V3.0 (recommended)</option>
      <option value="V2.4"<?php echo $cur_model === 'V2.4' ? ' selected' : ''; ?>>BirdNET V2.4</option>
    </select></label><br>
    <label>Species names: <select name="language" id="wiz_lang">
<?php
$lang_names = array('pt_BR' => 'Portuguese Brazil (CBRO)', 'pt_PT' => 'Portuguese Portugal');
foreach ($langs as $l) {
  $sel = ($l === $cur_lang || ($l === 'pt_BR' && $cur_lang === 'pt')) ? ' selected' : '';
  echo '<option value="' . $h($l) . '"' . $sel . '>' . $h($lang_names[$l] ?? $l) . '</option>';
}
?>
    </select></label>
    <br>
    <label>Brazilian states include list: <select name="state">
      <option value="AUTO"<?php echo $pending ? ' selected' : ''; ?>>Detect from the coordinates</option>
      <option value=""<?php echo (!$pending && $cur_state === '') ? ' selected' : ''; ?>>None (outside Brazil)</option>
<?php foreach ($states as $s) { echo '<option' . (!$pending && $s === $cur_state ? ' selected' : '') . '>' . $h($s) . '</option>'; } ?>
    </select></label>
  </td></tr></table><br>
  <table class="settingstable"><tr><td>
    <h2>Access and notifications</h2>
    <label>Web password (letters and digits; empty = keep <?php echo empty($config['CADDY_PWD']) ? 'no password' : 'the current one'; ?>):
      <input name="password" type="password" autocomplete="new-password" pattern="[A-Za-z0-9]*"></label><br>
    <label>Repeat the password: <input name="password2" type="password" autocomplete="new-password" pattern="[A-Za-z0-9]*"></label><br>
    <label>BirdWeather ID (optional): <input name="birdweather_id" type="text" value="<?php echo $h($config['BIRDWEATHER_ID'] ?? ''); ?>"></label><br>
    <label>Notification URL for Apprise (optional, e.g. tgram://token/chat): <input name="apprise_url" type="text" style="width:40ch"></label>
  </td></tr></table><br>
  <button type="submit" name="wizard_save" value="1">Save and start</button>
  </form>
<?php } ?>
</div>
