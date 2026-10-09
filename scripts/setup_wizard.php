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
$station_lists = array();
foreach (glob($home . '/BirdNET-Pi/species_lists/*.txt') as $f) {
  $station_lists[] = basename($f, '.txt');
}
sort($station_lists);
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
  // species list filter, the same choice as Settings: '' none, a station list, BR-<UF>, or AUTO (the state
  // of the coordinates: OpenStreetMap online, the shipped IBGE boundaries offline)
  $sel = (string)($p['species_list'] ?? '');
  $state_detected = false;
  if ($sel === 'AUTO' && empty($errors)) {
    $uf = trim((string)shell_exec('python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/locate_state.py') . ' '
      . escapeshellarg((string)(float)$lat) . ' ' . escapeshellarg((string)(float)$lon) . ' 2>/dev/null'));
    $sel = in_array($uf, $states, true) ? "BR-$uf" : '';
    $state_detected = true;
  }
  if ($sel === 'AUTO') $sel = '';
  $is_state = preg_match('/^BR-[A-Z]{2}$/', $sel) && in_array(substr($sel, 3), $states, true);
  if ($sel !== '' && !$is_state && !in_array($sel, $station_lists, true)) $errors[] = 'No such species list';
  $state = $is_state ? substr($sel, 3) : '';
  $pwd = $p['password'] ?? '';
  if ($pwd !== '' && !preg_match('/^[A-Za-z0-9]+$/', $pwd)) $errors[] = 'The password may only contain letters and digits';
  if ($pwd !== ($p['password2'] ?? '')) $errors[] = 'The two passwords differ';
  // app colours (System Appearance themes; the custom theme is set later in Settings › Appearance)
  $theme = strtolower($p['app_theme'] ?? 'forest');
  if (!isset(theme_presets()[$theme])) $errors[] = 'Unknown colour theme';
  $bw = $p['birdweather_id'] ?? '';
  if ($bw !== '' && !preg_match('/^[A-Za-z0-9]+$/', $bw)) $errors[] = 'Invalid BirdWeather ID';

  if (empty($errors)) {
    $contents = file_get_contents('/etc/birdnet/birdnet.conf');
    $old_model = $config['MODEL'] ?? '';
    $old_lang = $config['DATABASE_LANG'] ?? '';
    $contents = wizard_set_key($contents, 'SITE_NAME', "\"$site_name\"");
    $contents = wizard_set_key($contents, 'LATITUDE', round((float)$lat, 4));
    $contents = wizard_set_key($contents, 'LONGITUDE', round((float)$lon, 4));
    $contents = wizard_set_key($contents, 'MODEL', $model);
    if ($model !== $old_model) {
      // per-generation defaults (V3 = 0.35 / 1.0 / location 0.5 / overlap 1.2, V2.4 = upstream 0.7 / 1.25 / 0.03 / 0.0)
      $contents = wizard_set_key($contents, 'CONFIDENCE', $model === $model_v3 ? '0.35' : '0.7');
      $contents = wizard_set_key($contents, 'SENSITIVITY', $model === $model_v3 ? '1.0' : '1.25');
      $contents = wizard_set_key($contents, 'SF_THRESH', $model === $model_v3 ? '0.5' : '0.03');
      $contents = wizard_set_key($contents, 'OVERLAP', $model === $model_v3 ? '1.2' : '0.0');
    }
    $contents = wizard_set_key($contents, 'DATABASE_LANG', $lang);
    $contents = wizard_set_key($contents, 'SPECIES_LIST', $sel);
    if (strpos($lang, 'pt') === 0 || $state !== '') $contents = wizard_set_key($contents, 'INFO_SITE', '"EBIRD"');
    $contents = wizard_set_key($contents, 'BIRDWEATHER_ID', $bw);
    $contents = wizard_set_key($contents, 'APP_THEME', "\"$theme\"");
    $update_caddy = false;
    if ($pwd !== '' && $pwd !== ($config['CADDY_PWD'] ?? '')) {
      $contents = wizard_set_key($contents, 'CADDY_PWD', "\"$pwd\"");
      $update_caddy = true;
    }
    // notification titles and bodies stay English (owner 2026-10-07)
    file_put_contents('/etc/birdnet/birdnet.conf', $contents);

    if ($tz !== $current_tz) {
      shell_exec('sudo timedatectl set-timezone ' . escapeshellarg($tz));
      if (file_exists('/etc/timezone')) shell_exec('echo ' . escapeshellarg($tz) . ' | sudo tee /etc/timezone > /dev/null');
    }
    // another species list: a state is built once (its birds + the model's non-bird classes), then linked
    if ($sel !== ($config['SPECIES_LIST'] ?? '')) {
      shell_exec('sudo -u ' . escapeshellarg($user) . ' python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/select_species_list.py') . ' > /dev/null 2>&1');
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
$cur_list = (string)($config['SPECIES_LIST'] ?? '');
// no list yet: offer the Brazilian state of the station's coordinates, preselected
$detected_state = '';
if ($cur_list === '' && is_numeric($config['LATITUDE'] ?? '') && is_numeric($config['LONGITUDE'] ?? '')) {
  $uf = trim((string)shell_exec('python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/locate_state.py') . ' '
    . escapeshellarg((string)(float)$config['LATITUDE']) . ' ' . escapeshellarg((string)(float)$config['LONGITUDE']) . ' 2>/dev/null'));
  if (in_array($uf, $states, true)) $detected_state = "BR-$uf";
}
$preselect = $cur_list !== '' ? $cur_list : $detected_state;
$cur_model = ($config['MODEL'] ?? '') === $model_v24 ? 'V2.4' : 'V3';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<div class="settings">
  <div class="brbanner"><h1>Station setup</h1></div><br>
<?php if ($saved) { ?>
  <table class="settingstable"><tr><td>
    <h2>Saved</h2>
    <p>The station restarts its services with the new settings. Detections start appearing on the
    <a href="views.php?view=Now">Now</a> in a few minutes<?php echo $update_caddy ? ' — Tools and Settings now ask for the user <b>birdnet</b> and your password' : ''; ?>.</p>
    <?php if ($state_detected) { echo '<p>Species list from the coordinates: <b>' . ($state === '' ? 'none (outside Brazil)' : 'BR-' . $h($state)) . '</b>.</p>'; } ?>
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
    <br>
    <label>BirdWeather ID (optional): <input name="birdweather_id" type="text" value="<?php echo $h($config['BIRDWEATHER_ID'] ?? ''); ?>"></label><br>
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
    <label>Species list filter: <select name="species_list">
      <option value=""<?php echo $preselect === '' ? ' selected' : ''; ?>>None — use the model's species distribution</option>
      <option value="AUTO">Detect the Brazilian state from the coordinates</option>
<?php if ($station_lists) { echo '<optgroup label="Station lists">';
  foreach ($station_lists as $n) { echo '<option value="' . $h($n) . '"' . ($n === $preselect ? ' selected' : '') . '>' . $h($n) . '</option>'; }
  echo '</optgroup>'; } ?>
      <optgroup label="Brazilian states (WikiAves records, CBRO names)">
<?php foreach ($states as $uf) { $v = "BR-$uf"; if (in_array($v, $station_lists, true)) continue;
  echo '<option value="' . $v . '"' . ($v === $preselect ? ' selected' : '') . '>' . $v . ($v === $detected_state ? ' (from the coordinates)' : '') . '</option>'; } ?>
      </optgroup>
    </select></label>
  </td></tr></table><br>
  <table class="settingstable"><tr><td>
    <h2>Appearance</h2>
    <div class="wizthemes">
<?php $cur_theme = strtolower(trim($config['APP_THEME'] ?? 'forest')); if (!isset(theme_presets()[$cur_theme])) $cur_theme = 'forest';
  foreach (theme_presets() as $k => $t) {
    echo '<label class="wiztheme"><input type="radio" name="app_theme" value="' . $k . '"' . ($k === $cur_theme ? ' checked' : '') . ' data-colors="' . implode(',', array_slice($t, 1)) . '">'
      . '<span class="mock" style="background:' . $t[1] . '"><span class="mm" style="background:' . $t[2] . '"></span><span class="mp" style="background:' . $t[3] . '"><i style="background:' . $t[4] . '"></i><i style="background:' . $t[5] . '"></i></span></span>'
      . '<b>' . $h($t[0]) . '</b></label>';
  } ?>
    </div>
    <small>Colours of the app, shown at once; a custom theme can be made later in Settings › Appearance.</small>
  </td></tr></table><br>
  <style>
    .wizthemes { display: flex; flex-wrap: wrap; gap: 10px; margin: 6px 0; }
    .wiztheme { display: flex; flex-direction: column; align-items: center; gap: 4px; cursor: pointer; padding: 6px; border-radius: 10px; border: 2px solid rgba(0,0,0,.08); }
    .wiztheme input { display: none; } .wiztheme:has(input:checked) { border-color: var(--accent, #2b5e22); box-shadow: 0 0 0 2px var(--accent, #2b5e22); }
    .wiztheme .mock { display: flex; width: 110px; height: 56px; border-radius: 6px; padding: 5px; gap: 5px; box-sizing: border-box; }
    .wiztheme .mm { width: 26%; border-radius: 3px; } .wiztheme .mp { flex: 1; border-radius: 3px; display: flex; align-items: flex-end; gap: 3px; padding: 4px; }
    .wiztheme .mp i { display: block; width: 20px; height: 9px; border-radius: 5px; }
  </style>
  <script>
    // a theme is shown on this page and on the frame around it as soon as it is picked; saved with the rest
    document.querySelectorAll('.wiztheme input').forEach(function (r) {
      r.addEventListener('change', function () {
        var c = r.dataset.colors.split(','), vars = ['--bg', '--menu', '--panel', '--accent', '--accent2'];
        [document, window.parent && window.parent.document].forEach(function (doc) {
          if (!doc) return;
          c.forEach(function (v, i) { doc.documentElement.style.setProperty(vars[i], v); });
          doc.documentElement.style.setProperty('--accent-rgb', [1, 3, 5].map(function (j) { return parseInt(c[3].substr(j, 2), 16); }).join(','));
        });
      });
    });
  </script>
  <table class="settingstable"><tr><td>
    <h2>Web Access</h2>
    <label>Web password (letters and digits; empty = keep <?php echo empty($config['CADDY_PWD']) ? 'no password' : 'the current one'; ?>):
      <input name="password" type="password" autocomplete="new-password" pattern="[A-Za-z0-9]*"></label><br>
    <label>Repeat the password: <input name="password2" type="password" autocomplete="new-password" pattern="[A-Za-z0-9]*"></label><br>
  </td></tr></table><br>
  <button type="submit" name="wizard_save" value="1">Save and start</button>
  </form>
<?php } ?>
</div>
