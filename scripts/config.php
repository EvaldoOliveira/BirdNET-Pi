<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);
define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__.'/scripts/common.php');

$user = get_user();
$home = get_home();
$config = get_config();
set_timezone();

ensure_authenticated();

if (file_exists($home."/BirdNET-Pi/apprise.txt")) {
  $apprise_config = file_get_contents($home."/BirdNET-Pi/apprise.txt");
} else {
  $apprise_config = "";
}
$apprise_config_rare = file_exists($home."/BirdNET-Pi/apprise-rare.txt") ? file_get_contents($home."/BirdNET-Pi/apprise-rare.txt") : "";
$apprise_notification_body_rare = file_exists($home."/BirdNET-Pi/body-rare.txt") ? file_get_contents($home."/BirdNET-Pi/body-rare.txt") : "";

if (file_exists($home."/BirdNET-Pi/body.txt")) {
  $apprise_notification_body = file_get_contents($home."/BirdNET-Pi/body.txt");
} else {
  $apprise_notification_body = "";
}

function syslog_shell_exec($cmd, $sudo_user = null) {
  if ($sudo_user) {
    $cmd = "sudo -u $sudo_user $cmd";
  }
  $output = shell_exec($cmd);

  if (strlen($output) > 0) {
    syslog(LOG_INFO, $output);
  }
}

if(isset($_GET['threshold'])) {
  $threshold = $_GET['threshold'];
  if (!is_numeric($threshold) || $threshold < 0 || $threshold > 1) {
    die('Invalid threshold value');
  }

  $command = "sudo -u $user ".$home."/BirdNET-Pi/birdnet/bin/python3 ".$home."/BirdNET-Pi/scripts/species.py --threshold $threshold 2>&1";

  $output = shell_exec($command);

  echo $output;
  die();
}

if(isset($_GET['restart_php']) && $_GET['restart_php'] == "true") {
  shell_exec("sudo service php*-fpm restart");
  die();
}

# Basic Settings
if(isset($_GET["latitude"])){
  $latitude = $_GET["latitude"];
  $longitude = $_GET["longitude"];
  $site_name = $_GET["site_name"];
  $site_name = str_replace('"', "", $site_name);
  $site_name = str_replace('\'', "", $site_name);
  $birdweather_id = $_GET["birdweather_id"];
  $apprise_input = $_GET['apprise_input'];
  if(isset($_GET['apprise_input_rare'])) { $apprise_input_rare = $_GET['apprise_input_rare']; }
  $apprise_notification_title = $_GET['apprise_notification_title'];
  $apprise_notification_body = htmlspecialchars_decode($_GET['apprise_notification_body'], ENT_QUOTES);
  if(isset($_GET['apprise_notification_title_rare'])) { $apprise_notification_title_rare = $_GET['apprise_notification_title_rare']; }
  if(isset($_GET['apprise_notification_body_rare'])) { $apprise_notification_body_rare = htmlspecialchars_decode($_GET['apprise_notification_body_rare'], ENT_QUOTES); }
  $minimum_time_limit = $_GET['minimum_time_limit'];
  $image_provider = $_GET["image_provider"];
  $flickr_api_key = $_GET['flickr_api_key'];
  $flickr_filter_email = $_GET["flickr_filter_email"];
  $language = $_GET["language"];
  $info_site = $_GET["info_site"];
  $color_scheme = $_GET["color_scheme"];
  // US-41 follow-up (owner 2026-09-17): spectrogram height + palette live here, under "Spectrogram and colours"
  $spectrogram_height = isset($_GET['spectrogram_height']) && is_numeric($_GET['spectrogram_height']) ? max(20, min(100, intval($_GET['spectrogram_height']))) : null;
  $spectrogram_palette = isset($_GET['spectrogram_palette']) && in_array($_GET['spectrogram_palette'], array('birdnet','viridis','inferno','ocean','grayscale','soxheat'), true) ? $_GET['spectrogram_palette'] : null;
  // US-42: colour sensitivity
  $spectrogram_floor_db = isset($_GET['spectrogram_floor_db']) && is_numeric($_GET['spectrogram_floor_db']) ? max(-120, min(-40, intval($_GET['spectrogram_floor_db']))) : null;
  $spectrogram_range_db = isset($_GET['spectrogram_range_db']) && is_numeric($_GET['spectrogram_range_db']) ? max(30, min(120, intval($_GET['spectrogram_range_db']))) : null;
  $spectrogram_contrast = isset($_GET['spectrogram_contrast']) && is_numeric($_GET['spectrogram_contrast']) ? max(0.5, min(2.0, round(floatval($_GET['spectrogram_contrast']), 2))) : null;
  $timezone = $_GET["timezone"];
  $model = $_GET["model"];
  $known_models = array("BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL", "BirdNET-Plus_V3.0-preview3.1_Global_10K");
  $shadow_model = isset($_GET['shadow_model']) && in_array($_GET['shadow_model'], $known_models) && $_GET['shadow_model'] != $model ? $_GET['shadow_model'] : '';
  $shadow_min_conf = isset($_GET['shadow_min_conf']) && is_numeric($_GET['shadow_min_conf']) ? max(0.01, min(0.99, round(floatval($_GET['shadow_min_conf']), 2))) : 0.35;
  $sf_thresh = $_GET["sf_thresh"];
  // US-47 (owner 2026-09-22): both models and their parameters live in one "Models" block.
  // Official: CONFIDENCE / SENSITIVITY / SF_THRESH; shadow: SHADOW_MIN_CONF / SHADOW_SENS /
  // SHADOW_GEO_THRESH; OVERLAP is shared (one recording pipeline, both models see the same windows).
  $confidence = isset($_GET['confidence']) && is_numeric($_GET['confidence']) ? max(0.01, min(0.99, round(floatval($_GET['confidence']), 2))) : $config['CONFIDENCE'];
  $sensitivity = isset($_GET['sensitivity']) && is_numeric($_GET['sensitivity']) ? max(0.5, min(1.5, round(floatval($_GET['sensitivity']), 2))) : $config['SENSITIVITY'];
  $overlap = isset($_GET['overlap']) && is_numeric($_GET['overlap']) ? max(0.0, min(2.9, round(floatval($_GET['overlap']), 1))) : $config['OVERLAP'];
  $shadow_sens = isset($_GET['shadow_sens']) && is_numeric($_GET['shadow_sens']) ? max(0.5, min(1.5, round(floatval($_GET['shadow_sens']), 2))) : ($config['SHADOW_SENS'] ?? 1.0);
  $shadow_geo_thresh = isset($_GET['shadow_geo_thresh']) && is_numeric($_GET['shadow_geo_thresh']) ? max(0.0005, min(0.99, floatval($_GET['shadow_geo_thresh']))) : ($config['SHADOW_GEO_THRESH'] ?? 0.03);
  // Swap: the shadow model becomes the official one and vice versa, each keeping its own
  // three parameters (what the owner did by hand on 2026-09-21). Only when a shadow is set.
  if(isset($_GET['swap_models']) && $shadow_model != '') {
    list($model, $shadow_model) = array($shadow_model, $model);
    list($confidence, $shadow_min_conf) = array($shadow_min_conf, $confidence);
    list($sensitivity, $shadow_sens) = array($shadow_sens, $sensitivity);
    list($sf_thresh, $shadow_geo_thresh) = array($shadow_geo_thresh, $sf_thresh);
  }
  if(isset($_GET['data_model_version'])) {
    $data_model_version = 2;
  } else {
    $data_model_version = 1;
  }
  if(isset($_GET['only_notify_species_names'])) { $only_notify_species_names = htmlspecialchars_decode($_GET['only_notify_species_names'], ENT_QUOTES); }
  if(isset($_GET['only_notify_species_names_2'])) { $only_notify_species_names_2 = htmlspecialchars_decode($_GET['only_notify_species_names_2'], ENT_QUOTES); }

  if(isset($_GET['notification_email']) && trim($_GET['notification_email']) != '') { $notification_email = trim($_GET['notification_email']); }
  if(isset($_GET['notification_default_tier'])) {
    $notification_default_tier = strtolower($_GET['notification_default_tier']);
    if(!in_array($notification_default_tier, ['muted', 'normal', 'rare'], true)) {
      $notification_default_tier = 'normal';
    }
  }
  if(isset($_GET['apprise_notify_each_detection'])) {
    $apprise_notify_each_detection = 1;
  } else {
    $apprise_notify_each_detection = 0;
  }
  if(isset($_GET['apprise_notify_new_species'])) {
    $apprise_notify_new_species = 1;
  } else {
    $apprise_notify_new_species = 0;
  }
  if(isset($_GET['apprise_notify_new_species_each_day'])) {
    $apprise_notify_new_species_each_day = 1;
  } else {
    $apprise_notify_new_species_each_day = 0;
  }
  if(isset($_GET['apprise_weekly_report'])) {
    $apprise_weekly_report = 1;
  } else {
    $apprise_weekly_report = 0;
  }

  if(isset($timezone) && in_array($timezone, DateTimeZone::listIdentifiers())) {
    # dpkg-reconfigure tzdata is a pain to run non-interactively, so we do it in two steps instead
    # tzlocal.get_localzone() will fail if the Debian specific /etc/timezone is not in sync
    shell_exec("sudo timedatectl set-timezone ".$timezone);
    if (file_exists('/etc/timezone')) {
        shell_exec("echo ".$timezone." | sudo tee /etc/timezone > /dev/null");
    }
    $_SESSION['my_timezone'] = $timezone;
    date_default_timezone_set($timezone);
    echo "<script>setTimeout(
    function() {
      const xhttp = new XMLHttpRequest();
    xhttp.open(\"GET\", \"./config.php?restart_php=true\", true);
    xhttp.send();
    }, 1000);</script>";
  }

  // logic for setting the date and time based on user inputs from the form below
  if(isset($_GET['date']) && isset($_GET['time'])) {
    // can't set the date manually if it's getting it from the internet, disable ntp
    exec("sudo timedatectl set-ntp false");

    // check if valid date and time
    $datetime = DateTime::createFromFormat('Y-m-d H:i', $_GET['date'] . ' ' . $_GET['time']);
    if ($datetime && $datetime->format('Y-m-d H:i') === $_GET['date'] . ' ' . $_GET['time']) {
      exec("sudo date -s '".$_GET['date']." ".$_GET['time']."'");
    }
  } else {
    // user checked 'use time from internet if available,' so make sure that's on
    if(strlen(trim(exec("sudo timedatectl | grep \"NTP service: active\""))) == 0){
      exec("sudo timedatectl set-ntp true");
      sleep(3);
    }
  }

  $contents = file_get_contents("/etc/birdnet/birdnet.conf");
  $contents = preg_replace("/SITE_NAME=.*/", "SITE_NAME=\"$site_name\"", $contents);
  $contents = preg_replace("/LATITUDE=.*/", "LATITUDE=$latitude", $contents);
  $contents = preg_replace("/LONGITUDE=.*/", "LONGITUDE=$longitude", $contents);
  $contents = preg_replace("/BIRDWEATHER_ID=.*/", "BIRDWEATHER_ID=$birdweather_id", $contents);
  $contents = preg_replace("/APPRISE_NOTIFICATION_TITLE=.*/", "APPRISE_NOTIFICATION_TITLE=\"$apprise_notification_title\"", $contents);
  if(isset($apprise_notification_title_rare)) {
    if(preg_match("/^APPRISE_NOTIFICATION_TITLE_RARE=/m", $contents)) {
      $contents = preg_replace("/APPRISE_NOTIFICATION_TITLE_RARE=.*/", "APPRISE_NOTIFICATION_TITLE_RARE=\"$apprise_notification_title_rare\"", $contents);
    } else {
      // Config written before this setting existed - append the new key
      $contents .= "\nAPPRISE_NOTIFICATION_TITLE_RARE=\"$apprise_notification_title_rare\"\n";
    }
  }
  $contents = preg_replace("/APPRISE_NOTIFY_EACH_DETECTION=.*/", "APPRISE_NOTIFY_EACH_DETECTION=$apprise_notify_each_detection", $contents);
  $contents = preg_replace("/APPRISE_NOTIFY_NEW_SPECIES=.*/", "APPRISE_NOTIFY_NEW_SPECIES=$apprise_notify_new_species", $contents);
  $contents = preg_replace("/APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY=.*/", "APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY=$apprise_notify_new_species_each_day", $contents);
  $contents = preg_replace("/APPRISE_WEEKLY_REPORT=.*/", "APPRISE_WEEKLY_REPORT=$apprise_weekly_report", $contents);
  $contents = preg_replace("/IMAGE_PROVIDER=.*/", "IMAGE_PROVIDER=$image_provider", $contents);
  $contents = preg_replace("/FLICKR_API_KEY=.*/", "FLICKR_API_KEY=$flickr_api_key", $contents);
  if(strlen($language) == 2 || strlen($language) == 5){
    $contents = preg_replace("/DATABASE_LANG=.*/", "DATABASE_LANG=$language", $contents);
  }
  $contents = preg_replace("/INFO_SITE=.*/", "INFO_SITE=$info_site", $contents);
  $contents = preg_replace("/COLOR_SCHEME=.*/", "COLOR_SCHEME=$color_scheme", $contents);  
  if($spectrogram_height !== null) {
    if(preg_match("/^SPECTROGRAM_HEIGHT=/m", $contents)) {
      $contents = preg_replace("/SPECTROGRAM_HEIGHT=.*/", "SPECTROGRAM_HEIGHT=$spectrogram_height", $contents);
    } else {
      $contents .= "\n## SPECTROGRAM_HEIGHT is the height of the live spectrogram in percent of the page height (vh)\nSPECTROGRAM_HEIGHT=$spectrogram_height\n";
    }
  }
  if($spectrogram_palette !== null) {
    if(preg_match("/^SPECTROGRAM_PALETTE=/m", $contents)) {
      $contents = preg_replace("/SPECTROGRAM_PALETTE=.*/", "SPECTROGRAM_PALETTE=$spectrogram_palette", $contents);
    } else {
      $contents .= "\n## SPECTROGRAM_PALETTE is the colour palette of the spectrograms: birdnet, viridis, inferno, ocean, grayscale, soxheat\nSPECTROGRAM_PALETTE=$spectrogram_palette\n";
    }
    if($spectrogram_palette != ($config['SPECTROGRAM_PALETTE'] ?? '')) {
      shell_exec("sudo systemctl restart spectrogram_viewer.service");
    }
  }
  $spec_restart = false;
  foreach (array('SPECTROGRAM_FLOOR_DB' => array($spectrogram_floor_db, 'dB floor of the spectrogram colour scale'),
                 'SPECTROGRAM_RANGE_DB' => array($spectrogram_range_db, 'dB range of the spectrogram colour scale above the floor'),
                 'SPECTROGRAM_CONTRAST' => array($spectrogram_contrast, 'contrast (gamma) of the spectrogram colour ramp; 1 = linear')) as $key => $pair) {
    list($val, $desc) = $pair;
    if($val === null) continue;
    if(preg_match("/^$key=/m", $contents)) {
      $contents = preg_replace("/$key=.*/", "$key=$val", $contents);
    } else {
      $contents .= "\n## $key is the $desc\n$key=$val\n";
    }
    if((string)$val !== (string)($config[$key] ?? '')) $spec_restart = true;
  }
  if($spec_restart) shell_exec("sudo systemctl restart spectrogram_viewer.service");
  $contents = preg_replace("/FLICKR_FILTER_EMAIL=.*/", "FLICKR_FILTER_EMAIL=$flickr_filter_email", $contents);
  $contents = preg_replace("/APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES=.*/", "APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES=$minimum_time_limit", $contents);
  $contents = preg_replace("/MODEL=.*/", "MODEL=$model", $contents);
  $contents = preg_replace("/^CONFIDENCE=.*/m", "CONFIDENCE=$confidence", $contents);
  $contents = preg_replace("/^SENSITIVITY=.*/m", "SENSITIVITY=$sensitivity", $contents);
  $contents = preg_replace("/^OVERLAP=.*/m", "OVERLAP=$overlap", $contents);
  foreach (array('SHADOW_MODEL_NAME' => array($shadow_model, 'model that analyses the same recordings beside the official one, into scripts/birds_shadow.db only; empty = off'),
                 'SHADOW_MIN_CONF' => array($shadow_min_conf, 'minimum confidence of a shadow model detection'),
                 'SHADOW_SENS' => array($shadow_sens, 'sigmoid sensitivity of the shadow model'),
                 'SHADOW_GEO_THRESH' => array($shadow_geo_thresh, 'location (species occurrence) threshold of the shadow model')) as $key => $pair) {
    list($val, $desc) = $pair;
    if(preg_match("/^$key=/m", $contents)) {
      $contents = preg_replace("/^$key=.*/m", "$key=$val", $contents);
    } else {
      $contents .= "\n## $key is the $desc\n$key=$val\n";
    }
  }
  $contents = preg_replace("/SF_THRESH=.*/", "SF_THRESH=$sf_thresh", $contents);
  $contents = preg_replace("/DATA_MODEL_VERSION=.*/", "DATA_MODEL_VERSION=$data_model_version", $contents);
  if(isset($only_notify_species_names)) { $contents = preg_replace("/APPRISE_ONLY_NOTIFY_SPECIES_NAMES=.*/", "APPRISE_ONLY_NOTIFY_SPECIES_NAMES=\"$only_notify_species_names\"", $contents); }
  if(isset($only_notify_species_names_2)) { $contents = preg_replace("/APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2=.*/", "APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2=\"$only_notify_species_names_2\"", $contents); }
  if(isset($notification_email)) {
    if(preg_match("/^NOTIFICATION_EMAIL=/m", $contents)) {
      $contents = preg_replace("/NOTIFICATION_EMAIL=.*/", "NOTIFICATION_EMAIL=\"$notification_email\"", $contents);
    } else {
      // Config written before this setting existed - append the new key
      $contents .= "\nNOTIFICATION_EMAIL=\"$notification_email\"\n";
    }
  }
  if(isset($notification_default_tier)) {
    if(preg_match("/^NOTIFICATION_DEFAULT_TIER=/m", $contents)) {
      $contents = preg_replace("/NOTIFICATION_DEFAULT_TIER=.*/", "NOTIFICATION_DEFAULT_TIER=$notification_default_tier", $contents);
    } else {
      // Config written before this setting existed - append the new key
      $contents .= "\nNOTIFICATION_DEFAULT_TIER=$notification_default_tier\n";
    }
  }

  if($site_name != $config["SITE_NAME"] || $color_scheme != $config["COLOR_SCHEME"]) {
    echo "<script>setTimeout(
    function() {
      window.parent.document.location.reload();
    }, 1000);</script>";

    shell_exec("sudo systemctl restart chart_viewer.service");
    // the sleep allows for the service to restart and image to be generated
    sleep(5);
  }

  $fh = fopen("/etc/birdnet/birdnet.conf", "w");
  fwrite($fh, $contents);

  if(isset($apprise_input)){
    $appriseconfig = fopen($home."/BirdNET-Pi/apprise.txt", "w");
    fwrite($appriseconfig, $apprise_input);
    $apprise_config = $apprise_input;
  }
  if(isset($apprise_input_rare)){
    $appriseconfigrare = fopen($home."/BirdNET-Pi/apprise-rare.txt", "w");
    fwrite($appriseconfigrare, $apprise_input_rare);
    $apprise_config_rare = $apprise_input_rare;
  }
  if(isset($apprise_notification_body)){
    $apprisebody = fopen($home."/BirdNET-Pi/body.txt", "w");
    fwrite($apprisebody, $apprise_notification_body);
  }
  if(isset($apprise_notification_body_rare)){
    $apprisebodyrare = fopen($home."/BirdNET-Pi/body-rare.txt", "w");
    fwrite($apprisebodyrare, $apprise_notification_body_rare);
  }
  if ($model != $config['MODEL'] || $language != $config['DATABASE_LANG']){
    if(strlen($language) == 2){
      syslog_shell_exec("$home/BirdNET-Pi/scripts/install_language_label.sh", $user);
      syslog(LOG_INFO, "Successfully changed language to '$language' and model to '$model'");
    }
  }
  syslog(LOG_INFO, "Restarting Services");
  shell_exec("sudo restart_services.sh");
}

if(isset($_GET['sendtest']) && $_GET['sendtest'] == "true") {
  $conf = $_GET['apprise_config'];
  $title = $_GET['apprise_notification_title'];
  $body = $_GET['apprise_notification_body'];

  $temp_conf = tmpfile();
  $t_conf_path = stream_get_meta_data($temp_conf)['uri'];
  chmod($t_conf_path, 0644);
  fwrite($temp_conf, $conf);

  $temp_body = tmpfile();
  $t_body_path = stream_get_meta_data($temp_body)['uri'];
  chmod($t_body_path, 0644);
  fwrite($temp_body, $body);

  $cmd = "sudo -u $user $home/BirdNET-Pi/birdnet/bin/python3 $home/BirdNET-Pi/scripts/send_test_notification.py --body $t_body_path --config $t_conf_path --title '" . escapeshellcmd($title) . "' 2>&1";
  $ret = shell_exec($cmd);
  echo "<pre class=\"bash\">".$ret."</pre>";
  fclose($temp_conf);
  fclose($temp_body);

  die();
}

// have to get the config again after we change the variables, so the UI reflects the changes too
$config = get_config($force_reload=true);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  </style>
  </head>
<div class="settings">
      <div class="brbanner"><h1>Basic Settings</h1></div><br>
    <form id="basicform" action=""  method="GET">


<script>
  document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('modelsel').addEventListener('change', function() {
    if(this.value == "BirdNET_GLOBAL_6K_V2.4_Model_FP16" || this.value == "BirdNET-Plus_V3.0-preview3.1_Global_10K"){ 
      document.getElementById("soft").style.display="unset";
    } else {
      document.getElementById("soft").style.display="none";
    }
  });
}, false);
function sendTestNotification(e, which, msgspan, titlefield, bodyfield) {
  which = which || "apprise_input";
  msgspan = msgspan || "testsuccessmsg";
  document.getElementById(msgspan).innerHTML = "";
  e.classList.add("disabled");

  var apprise_notification_title = (titlefield && document.getElementsByName(titlefield)[0].value)
    || document.getElementsByName("apprise_notification_title")[0].value;
  var apprise_notification_body = encodeURIComponent((bodyfield && document.getElementsByName(bodyfield)[0].value)
    || document.getElementsByName("apprise_notification_body")[0].value);
  var apprise_config = encodeURIComponent(document.getElementsByName(which)[0].value);

  var xmlHttp = new XMLHttpRequest();
    xmlHttp.onreadystatechange = function() { 
        if (xmlHttp.readyState == 4 && xmlHttp.status == 200) {
            document.getElementById(msgspan).innerHTML = this.responseText+" Test sent! Make sure to <b>Update Settings</b> below."
            e.classList.remove("disabled");
        }
    }
    xmlHttp.open("GET", "scripts/config.php?sendtest=true"+"&apprise_notification_body="+apprise_notification_body+"&apprise_config="+apprise_config+"&apprise_notification_title="+apprise_notification_title, true); // true for asynchronous
    xmlHttp.send(null);
}
</script>
      <table class="settingstable"><tr><td>
      <h2>Models</h2>
      <?php
      // US-47: official and shadow model side by side, each with its own minimum confidence,
      // sigmoid sensitivity and location threshold; the overlap is shared. Config keys unchanged.
      $models = array("BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL", "BirdNET-Plus_V3.0-preview3.1_Global_10K");
      $model_defaults = array(
        "BirdNET_GLOBAL_6K_V2.4_Model_FP16" => "upstream defaults: confidence 0.7, sensitivity 1.25, location 0.03",
        "BirdNET_6K_GLOBAL_MODEL" => "legacy 6K model: confidence 0.7, sensitivity 1.25",
        "BirdNET-Plus_V3.0-preview3.1_Global_10K" => "BirdNET Live recipe: confidence 0.35, sensitivity 1.0, location 0.03");
      ?>
      <table class="modelstable">
        <tr><th></th><th>Model</th><th>Min. confidence<br><small>[0.01–0.99]</small></th><th>Sensitivity<br><small>[0.5–1.5]</small></th><th>Location threshold<br><small>[0.0005–0.99]</small></th></tr>
        <tr>
          <td><b>Official</b></td>
          <td><select id="modelsel" name="model" class="testbtn">
      <?php
      foreach($models as $modelName){
          $isSelected = ($config['MODEL'] == $modelName) ? 'selected="selected"' : '';
          echo "<option value='{$modelName}' $isSelected>$modelName</option>";
        }
      ?>
          </select></td>
          <td><input name="confidence" type="number" style="width:5em;" min="0.01" max="0.99" step="0.01" value="<?php print($config['CONFIDENCE']);?>"/></td>
          <td><input name="sensitivity" type="number" style="width:5em;" min="0.5" max="1.5" step="0.01" value="<?php print($config['SENSITIVITY']);?>"/></td>
          <td><input name="sf_thresh" type="number" style="width:5em;" max="0.99" min="0.0005" step="any" value="<?php print($config['SF_THRESH']);?>"/></td>
        </tr>
        <tr>
          <td><b>Shadow</b></td>
          <td><select name="shadow_model" class="testbtn">
        <option value="">None</option>
      <?php
      foreach($models as $modelName){
          $isSelected = (($config['SHADOW_MODEL_NAME'] ?? '') == $modelName) ? 'selected="selected"' : '';
          echo "<option value='{$modelName}' $isSelected>$modelName</option>";
        }
      ?>
          </select></td>
          <td><input name="shadow_min_conf" type="number" style="width:5em;" max="0.99" min="0.01" step="0.01" value="<?php print($config['SHADOW_MIN_CONF'] ?? '0.35');?>"/></td>
          <td><input name="shadow_sens" type="number" style="width:5em;" min="0.5" max="1.5" step="0.01" value="<?php print($config['SHADOW_SENS'] ?? '1.0');?>"/></td>
          <td><input name="shadow_geo_thresh" type="number" style="width:5em;" max="0.99" min="0.0005" step="any" value="<?php print($config['SHADOW_GEO_THRESH'] ?? $config['SF_THRESH']);?>"/></td>
        </tr>
        <tr>
          <td><b>Overlap</b></td>
          <td colspan="4"><input name="overlap" type="number" style="width:5em;" min="0.0" max="2.9" step="0.1" value="<?php print($config['OVERLAP']);?>"/> s <small>[0.0–2.9] — shared: the recording is cut once, both models analyse the same 3 s windows</small></td>
        </tr>
      </table>
      <button type="submit" name="swap_models" value="1" class="testbtn" onclick="return confirm('Swap the official and the shadow model (each keeps its own confidence, sensitivity and location threshold)? Services restart.');">Swap official ↔ shadow</button>
      <span onclick="document.getElementById('shadowhelp').style.display='unset'" style="text-decoration:underline;cursor:pointer">[more info]</span>
      <p><small>Thresholds are NOT comparable between generations — <?php foreach($model_defaults as $m => $d) { echo "<b>" . str_replace("_", " ", preg_replace('/_Model_FP16|_Global_10K|-preview3\.1/', '', $m)) . "</b>: $d. "; } ?>Calibrate from a shadow period before judging false positives. The same three fields for the official model also appear in Advanced Settings (same keys).</small></p>
      <p id="shadowhelp" style='display:none'>A shadow model analyses every recording right after the model selected above and writes what it would have detected to a database of its own (<code>scripts/birds_shadow.db</code>). It never extracts audio, never notifies and never touches the detections of the station, so a new model can be compared with the current one for weeks before switching. <b>BirdNET-Plus_V3.0-preview3.1_Global_10K</b> is the developer preview model of the BirdNET Live app (32 kHz, about 10,000 classes including amphibians, mammals and insects, its own location filter). It needs ONNX Runtime and is downloaded (about 80 MB) the first time it is used. It has no human voice class yet, so the privacy filter does not work while it is the selected model.</p>
      <br>
      <span <?php if($config['MODEL'] == "BirdNET_6K_GLOBAL_MODEL") { ?>style="display: none"<?php } ?> id="soft">
      <input type="checkbox" name="data_model_version" <?php if($config['DATA_MODEL_VERSION'] == 2) { echo "checked"; };?> >
      <label for="data_model_version">Species range model V2.4 - V2</label>  [ <a target="_blank" href="https://github.com/kahst/BirdNET-Analyzer/discussions/234">Info here</a> ]<br>
      <label>Species Occurrence Frequency Threshold = the "Location threshold" column of the Models table above.</label> <span onclick="document.getElementById('sfhelp').style.display='unset'" style="text-decoration:underline;cursor:pointer">[more info]</span><br>
      <p id="sfhelp" style='display:none'>This value is used by the model to constrain the list of possible species that it will try to detect, given the minimum occurrence frequency. A 0.03 threshold means that for a species to be included in this list, it needs to, on average, be seen on at least 3% of historically submitted eBird checklists for your given lat/lon/current week of year. So, the lower the threshold, the rarer the species it will include.<br><img style='max-width:100%;padding-top:5px;padding-bottom:5px' alt="BirdNET-Pi new model detection flowchart" title="BirdNET-Pi new model detection flowchart" src="images/BirdNET-Pi_nm_flowchart.alpha.png">
        <br>If you'd like to tinker with this threshold value and see which species make it onto the list, <?php if($config['MODEL'] == "BirdNET_6K_GLOBAL_MODEL"){ ?>please click "Update Settings" at the very bottom of this page to install the appropriate label file, then come back here and you'll be able to use the Species List Tester.<?php } else { ?>you can use this tool: <button type="button" class="testbtn" id="openModal">Species List Tester</button><?php } ?></p>
      </span>

<script src="static/dialog-polyfill.js"></script>

<dialog id="modal">
  <div>
    <label for="threshold">Threshold:</label>
    <input type="number" id="threshold" step="0.01" min="0" max="1" value="">
    <button type="button" id="runProcess">Preview Species List</button>
  </div>
  <pre id="output"></pre>
  <button type="button" id="closeModal">Close</button>
</dialog>

<style>
#output {
  max-width: 100vw;
  word-wrap: break-word;
  white-space: pre-wrap;
}
#modal {
  max-height: 80vh;
  overflow-y: auto;
}
#modal div {
  display: flex;
  align-items: center;
}

#modal input[type="number"] {
  height: 32px;
}

#modal button {
  height: 32px;
  margin-left: 5px;
  padding: 0 10px;
  box-sizing: border-box;
}
</style>


<script>
// Get the button and modal elements
const openModalBtn = document.getElementById('openModal');
const modal = document.getElementById('modal');
dialogPolyfill.registerDialog(modal);
const output = document.getElementById('output');
const thresholdInput = document.getElementById('threshold');
const runProcessBtn = document.getElementById('runProcess');
const sfThreshInput = document.getElementsByName('sf_thresh')[0];
const closeModalBtn = document.getElementById('closeModal');


// Add an event listener to the button to open the modal
openModalBtn.addEventListener('click', () => {

  // Set the initial value of the threshold input element
  thresholdInput.value = sfThreshInput.value;

// Show the modal
  modal.showModal();
});

// Add an event listener to the "Preview Species List" button
runProcessBtn.addEventListener('click', () => {

  runProcess();
});

// Add an event listener to the "Close" button
closeModalBtn.addEventListener('click', () => {
  modal.close();
});

// Function to run the process
function runProcess() {
  // Get the value of the threshold input element
  const threshold = thresholdInput.value;

 // Set the output to "Loading..."
  output.innerHTML = "Loading...";

  // Make the AJAX request
  const xhr = new XMLHttpRequest();
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      // Handle the response
      output.innerHTML = xhr.responseText;
    }
  };
  xhr.open('GET', `scripts/config.php?threshold=${threshold}`);
  xhr.send();
}
</script>

      <dl>
      <dt>BirdNET-Plus_V3.0-preview3.1_Global_10K (2026)</dt>
      <dd id="ddnewline">Developer preview of the next BirdNET generation, the model of the BirdNET Live app. Try it as a shadow model first.</dd><br>
      <dt>BirdNET_GLOBAL_6K_V2.4_Model_FP16 (2023)</dt>
      <br>
      <dd id="ddnewline">This is the BirdNET-Analyzer model, the most advanced BirdNET model to date. Currently it  supports over 6,000 species worldwide, giving quite good species coverage for people in most of the world.</dd>
      <br>
      <dt>BirdNET_6K_GLOBAL_MODEL (2020)</dt>
      <br>
      <dd id="ddnewline">This is the BirdNET-Lite model, with bird sound recognition for more than 6,000 species worldwide. This has generally worse performance than the newer models but is kept as a legacy option.</dd>
      <br>
      <dt>[ In-depth technical write-up on the models <a target="_blank" href="https://github.com/mcguirepr89/BirdNET-Pi/wiki/BirdNET-Pi:-some-theory-on-classification-&-some-practical-hints">here</a> ]</dt>
      </dl>
      </td></tr></table><br>

      <table class="settingstable"><tr><td>
      <h2>Location</h2>
      <table class="settingstable plaintable">
        <tr>
          <td><label for="site_name">Site Name:</label></td>
          <td><input name="site_name" type="text" value="<?php print($config['SITE_NAME']);?>"/></td>
          <td>(Optional)</td>
        </tr>
        <tr>
          <td><label for="latitude">Latitude:</label></td>
          <td><input name="latitude" type="number" style="width:6em;" max="90" min="-90" step="0.0001" value="<?php print($config['LATITUDE']);?>" required/></td>
        </tr>
        <tr>
          <td><label for="longitude">Longitude: </label></td>
          <td><input name="longitude" type="number" style="width:6em;" max="180" min="-180" step="0.0001" value="<?php print($config['LONGITUDE']);?>" required/></td>
          <td></td>
        </tr>
      </table>
      <p>Set your Latitude and Longitude to 4 decimal places. Get your coordinates <a href="https://latlong.net" target="_blank">here</a>.</p>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2>BirdWeather</h2>
      <label for="birdweather_id">BirdWeather Token: </label>
      <input name="birdweather_id" type="text" value="<?php print($config['BIRDWEATHER_ID']);?>" /><br>
           <p><a href="https://app.birdweather.com" target="_blank">BirdWeather.com</a> is a weather map for bird sounds. 
        Stations around the world supply audio and video streams to BirdWeather where they are then analyzed by BirdNET 
        and compared to eBird Grid data. BirdWeather catalogues the bird audio and spectrogram visualizations so that you 
        can listen to, view, and read about birds throughout the world. <br><br> 
        To request a BirdWeather Token, You'll first need to create an account - <a href="https://app.birdweather.com/login" target="_blank">https://app.birdweather.com/</a><br>
        Once that's done - you can go to - <a href="https://app.birdweather.com/account/stations" target="_blank">https://app.birdweather.com/account/stations</a><br>
        Make sure that the Latitude and Longitude match what is in your BirdNET-Pi configuration.
        <br><br>
        <dt>NOTE - by using your BirdWeather Token - you are consenting to sharing your soundscapes and detections with BirdWeather</dt></p>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2>BirdDB-Br</h2>
      <?php $srl = $config['SOUND_REPO_LINK'] ?? ''; ?>
      <label>Central sound repository: </label>
      <?php if($srl != '') { echo "<a href='" . htmlspecialchars($srl, ENT_QUOTES) . "' target='_blank'>" . htmlspecialchars($srl) . "</a>"; } else { echo "<i>not configured on this station</i>"; } ?><br>
      <p><b>How to contribute from your own station (one-time setup):</b></p>
      <ol>
        <li>Run <code>rclone config</code> on your station and create a remote named <code>birddb</code>
          of type <code>drive</code>. Leave client_id/client_secret empty and set
          <code>root_folder_id</code> to the ID at the end of the repository URL above
          (the part after <code>/folders/</code>).</li>
        <li>When rclone opens the browser, <b>log in with your own Google account</b> and authorize
          it — that creates your personal OAuth token. Uploads are attributed to this account.</li>
        <li>The token is stored locally on your station in <code>~/.config/rclone/rclone.conf</code>.
          Keep that file private (permissions 600): it grants access with your account. It never
          leaves the station, renews itself automatically, and must never be shared or committed
          to any repository.</li>
        <li>Contributions must be FLAC: set <code>AUDIOFMT=flac</code> in your station's
          <code>birdnet.conf</code> (Advanced Settings), otherwise deposits are rejected
          on ingestion.</li>
        <li>Every deposit is validated on ingestion: a real FLAC clip (30 s max) with its metadata
          sidecar and a matching sha256 — anything else is deleted and reported.</li>
      </ol>
      <p>BirdDB-Br is a community sound repository: every detection of this station
        (the audio clip plus its metadata sidecar — station name, GPS coordinates, date/time,
        species, confidence and model parameters) is contributed to the central repository
        linked above.<br><br>
        <dt>By running this station you contribute your recordings voluntarily, in the spirit of
        community collections — with one important difference: contributed sounds are
        <b>NOT publicly available</b>.<br>
        They are used exclusively to build and train bird-identification models for the
        BirdDB-Br project.</dt><br><br>
        <b>Contributor terms (summary):</b> you declare you are the rightful producer of the
        recordings your station contributes and that they contain no third-party copyrighted
        material.<br>
        You keep all rights over your recordings; by contributing you grant the BirdDB-Br
        project a <b>non-exclusive, perpetual, worldwide, royalty-free licence</b> to store,
        process and use the recordings and their metadata to build, train, evaluate and
        distribute bird-identification models and derived works, including commercially.
        Each contribution is attributed to the Google account that uploaded it, and the
        repository maintainer validates every deposit (integrity, format, metadata) before it
        enters the base. Recordings containing intelligible human speech must not be
        contributed. You may request removal of your material from the repository at any time;
        removal applies to the stored recordings and future use.<br>
        Models already trained are not reversible.</p>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2>Notifications - Global</h2>
      <p><a target="_blank" href="https://github.com/caronc/apprise/wiki">Apprise Notifications</a> can be setup and enabled for 90+ notification services. Each service should be on its own line.</p>
      <p>Use the following variables to customize your notification title and body:</p>
      <dl>
      <dt>$sciname</dt>
      <dd>Scientific Name</dd>
      <dt>$comname</dt>
      <dd>Common Name</dd>
      <dt>$confidence</dt>
      <dd>Confidence Score</dd>
      <dt>$confidencepct</dt>
      <dd>Confidence Score as a percentage (eg. 0.91 => 91)</dd>
      <dt>$listenurl</dt>
      <dd>A link to the detection</dd>
      <dt>$friendlyurl</dt>
      <dd>A masked link to the detection. Only useful for services that support Markdown (e.g. Discord). </dd>
      <dt>$date</dt>
      <dd>Date</dd>
      <dt>$time</dt>
      <dd>Time</dd>
      <dt>$week</dt>
      <dd>Week</dd>
      <dt>$latitude</dt>
      <dd>Latitude</dd>
      <dt>$longitude</dt>
      <dd>Longitude</dd>
      <dt>$cutoff</dt>
      <dd>Minimum Confidence set in "Advanced Settings"</dd>
      <dt>$sens</dt>
      <dd>Sigmoid Sensitivity set in "Advanced Settings"</dd>
      <dt>$overlap</dt>
      <dd>Overlap set in "Advanced Settings"</dd>
      <dt>$image</dt>
      <dd>An image of the detected species from a photo source, see below.</dd>
      <dt>$reason</dt>
      <dd>The reason a notification was sent</dd>
      <dt>$audio</dt>
      <dd>Attach the detection's audio clip to the notification — identical on every
        tier: no tag, no clip. The tag itself is removed from the text.</dd>
      <dt>$user, $hostname</dt>
      <dd>This station's user and hostname — usable inside the Apprise Configuration boxes
        (e.g. <code>from=$user@$hostname.local</code>) to keep the box content generic.</dd>
      <dt>$email</dt>
      <dd>The notification e-mail address configured below — usable ONLY inside the Apprise
        Configuration boxes (e.g. <code>mailto://localhost:25?from=$user@$hostname.local&amp;to=$email&amp;secure=no&amp;format=text</code>),
        so the box content stays generic and shareable.</dd>
      <dt>$token1 &hellip; $token20</dt>
      <dd>Secret values from the station's private token store (<code>apprise-tokens.txt</code>, refreshed from the station secrets on every service start).<br>
        Usable ONLY inside the Apprise Configuration boxes (e.g. <code>tgram://$token1/$token2</code>)<br>
        Never in the notification title or body. Secrets never appear on this page.</dd>
      </dl>
      <p>Use the variables defined above to customize your notification title and body.</p>
      <input type="checkbox" name="apprise_notify_new_species" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES'] == 1 && filesize($home."/BirdNET-Pi/apprise.txt") != 0) { echo "checked"; };?> >
      <label for="apprise_notify_new_species">Notify each new infrequent species detection (<5 visits per week)</label><br>
      <input type="checkbox" name="apprise_notify_new_species_each_day" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] == 1 && filesize($home."/BirdNET-Pi/apprise.txt") != 0) { echo "checked"; };?> >
      <label for="apprise_notify_new_species_each_day">Notify each species first detection of the day</label><br>
      <input type="checkbox" name="apprise_notify_each_detection" <?php if($config['APPRISE_NOTIFY_EACH_DETECTION'] == 1 && filesize($home."/BirdNET-Pi/apprise.txt") != 0) { echo "checked"; };?> >
      <label for="apprise_weekly_report">Notify each new detection</label><br>
      <input type="checkbox" name="apprise_weekly_report" <?php if($config['APPRISE_WEEKLY_REPORT'] == 1 && filesize($home."/BirdNET-Pi/apprise.txt") != 0) { echo "checked"; };?> >
      <label for="apprise_weekly_report">Send <a href="views.php?view=Weekly%20Report"> weekly report</a></label><br>

      <hr>
      <label for="minimum_time_limit">Minimum time between notifications of the same species (sec):</label>
      <input type="number" id="minimum_time_limit" name="minimum_time_limit" value="<?php echo $config['APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES'];?>" style="width:6em;" min="0"><br>
      <label for="notification_email">Notification e-mail address (used as $email in the Apprise boxes):</label>
      <input type="text" id="notification_email" name="notification_email" placeholder="you@example.com (empty = keep current)" value="<?php echo $config['NOTIFICATION_EMAIL'] ?? '';?>" size=40><br>
      <label for="notification_default_tier">Default notification tier for species not listed in Species Management:</label>
      <select name="notification_default_tier" id="notification_default_tier" style="width:12em;">
        <?php $ndt = strtolower($config['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
        foreach (['muted' => 'None (muted)', 'normal' => 'Normal', 'rare' => 'Rare'] as $t_val => $t_label) {
          $t_sel = $ndt === $t_val ? " selected" : "";
          echo "<option value='{$t_val}'{$t_sel}>{$t_label}</option>";
        } ?>
      </select><br>
      <label>Per-species notification policy: </label>
      <a href="views.php?view=Species%20Management"><b>edit the species list in Species Management</b></a>:<br>
      &nbsp;&nbsp;Set a species to <b>Muted</b> to silence it;<br>
      &nbsp;&nbsp;To notify ONLY selected species, set the default tier above to <b>None (muted)</b> and mark the wanted species Normal or Rare.
      <?php if(($config['APPRISE_ONLY_NOTIFY_SPECIES_NAMES'] ?? '') != '' || ($config['APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2'] ?? '') != '') {
        echo "<br><i>Note: legacy comma-separated name filters are present in birdnet.conf and still apply to Normal-tier notifications.</i>";
      } ?><br>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2>Notifications - Normal</h2>
      <label for="apprise_notification_title">NORMAL Notification Title: </label>
      <input name="apprise_notification_title" style="width: 100%" type="text" value="<?php print($config['APPRISE_NOTIFICATION_TITLE']);?>" /><br><br>
      <label for="apprise_notification_body">NORMAL Notification Body: </label>
      <textarea class="testbtn" name="apprise_notification_body" rows="9" style="width:100%; margin-top:0" type="text" ><?php print($apprise_notification_body);?></textarea><br><br>
      <label for="apprise_input">Apprise Notifications Configuration: </label>
      <textarea placeholder="tgram://$token1/$token2   ($token1..$token20 placeholders resolve from the station's private apprise-tokens.txt - secrets never appear here)
mailto://{user}:{password}@gmail.com
twitter://{ConsumerKey}/{ConsumerSecret}/{AccessToken}/{AccessSecret}
https://discordapp.com/api/webhooks/{WebhookID}/{WebhookToken}
..." style="vertical-align: top; width:100%; margin-top:0" class="testbtn" name="apprise_input" rows="3" type="text" ><?php print($apprise_config);?></textarea><br>

      <br>

      <button type="button" class="testbtn" onclick="sendTestNotification(this)">Send Test Notification</button><br>
      <span id="testsuccessmsg"></span>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2>Notifications - Rare</h2>
      <label for="apprise_notification_title_rare">RARE Notification Title: </label>
      <input name="apprise_notification_title_rare" style="width: 100%" type="text" placeholder="empty = use the Normal title" value="<?php print($config['APPRISE_NOTIFICATION_TITLE_RARE'] ?? '');?>" /><br><br>
      <label for="apprise_notification_body_rare">RARE Notification Body: </label>
      <textarea class="testbtn" name="apprise_notification_body_rare" rows="9" style="width:100%; margin-top:0" type="text" placeholder="empty = use the Normal body"><?php print($apprise_notification_body_rare);?></textarea><br><br>
      <label for="apprise_input_rare">Apprise Configuration for Rare species: </label>
      <textarea placeholder="tgram://$token1/$token3   ($token1..$token20 placeholders resolve from the station's private apprise-tokens.txt)
mailto://{user}:{password}@gmail.com
..." style="vertical-align: top; width:100%; margin-top:0" class="testbtn" name="apprise_input_rare" rows="3" type="text" ><?php print($apprise_config_rare);?></textarea><br>
      &nbsp;&nbsp;Empty Rare Apprise/title/body fall back to the Normal ones.<br><br>
      <button type="button" class="testbtn" onclick="sendTestNotification(this, 'apprise_input_rare', 'testsuccessmsgrare', 'apprise_notification_title_rare', 'apprise_notification_body_rare')">Send Test Notification (Rare)</button><br>
      <span id="testsuccessmsgrare"></span>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2>Bird Photo Source</h2>
      <label for="image_provider">Image Provider: </label>
      <select name="image_provider" class="testbtn">
        <option value="" <?php if(empty($config['IMAGE_PROVIDER'])) { echo 'selected'; } ?>>None</option>
        <option value="WIKIPEDIA" <?php if($config['IMAGE_PROVIDER'] == 'WIKIPEDIA') { echo 'selected'; } ?>>Wikipedia</option>
        <option value="FLICKR" <?php if(empty($config['FLICKR_API_KEY'])) { echo 'disabled'; } else if($config['IMAGE_PROVIDER'] == 'FLICKR') { echo 'selected'; } ?>>Flickr</option>
      </select>
      <hr>
      <p>Set your Flickr API key to enable the display of bird images next to detections. <a target="_blank" href="https://www.flickr.com/services/api/misc.api_keys.html">Get your key here.</a></p>
      <label for="flickr_api_key">Flickr API Key: </label>
      <input name="flickr_api_key" type="text" size="32" value="<?php print($config['FLICKR_API_KEY']);?>"/><br>
      <label for="flickr_filter_email">Only search photos from this Flickr user: </label>
      <input name="flickr_filter_email" type="email" size="24" placeholder="myflickraccount@gmail.com" value="<?php print($config['FLICKR_FILTER_EMAIL']);?>"/><br>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2>Localization</h2>
      <label for="language">Database Language: </label>
      <select name="language" class="testbtn">
      <?php
        $langs = array(
          'not-selected' => 'Not Selected',
          "af" => "Afrikaans",
          "ar" => "Arabic",
          "ca" => "Catalan",
          "cs" => "Czech",
          "zh_CN" => "Chinese (simplified)",
          "zh_TW" => "Chinese (traditional)",
          "hr" => "Croatian",
          "da" => "Danish",
          "nl" => "Dutch",
          "en" => "English",
          "et" => "Estonian",
          "fi" => "Finnish",
          "fr" => "French",
          "de" => "German",
          "hu" => "Hungarian",
          "is" => "Icelandic",
          "id" => "Indonesia",
          "it" => "Italian",
          "ja" => "Japanese",
          "ko" => "Korean",
          "lv" => "Latvian",
          "lt" => "Lithuania",
          "no" => "Norwegian",
          "pl" => "Polish",
          "pt" => "Portuguese",
          "ro" => "Romanian",
          "ru" => "Russian",
          "sr" => "Serbian",
          "sk" => "Slovak",
          "sl" => "Slovenian",
          "es" => "Spanish",
          "sv" => "Swedish",
          "th" => "Thai",
          "tr" => "Turkish",
          "uk" => "Ukrainian",
          "vi" => "Vietnamese"
        );

        // Create options for each language
        foreach($langs as $langTag => $langName){
          $isSelected = "";
          if($config['DATABASE_LANG'] == $langTag){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$langTag}' $isSelected>$langName</option>";
        }
      ?>

      </select>
      <p>! Only modify this at initial setup !</p>
      </td></tr></table>
      <br>

      <table class="settingstable"><tr><td>
      <h2>Additional Info </h2>
      <label for="info_site">Site to pull additional species info from: </label>
      <select name="info_site" class="testbtn">
      <?php
        $info_site = array(
          'ALLABOUTBIRDS' => 'allaboutbirds.org',
          "EBIRD" => "ebird.org"
        );

        // Create options for each site
        foreach($info_site as $infoTag => $infoName){
          $isSelected = "";
          if($config['INFO_SITE'] == $infoTag){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$infoTag}' $isSelected>$infoName</option>";
        }
      ?>

      </select>
      <p>allaboutbirds.org default
      <br>ebirds.org has more European species</p>
      </td></tr></table><br>


      <table class="settingstable"><tr><td>
      <h2>Spectrogram and colours</h2>
      Note: when changing themes the daily chart may need a page refresh before updating.<br><br>
      <label for="color_scheme">Color scheme for the site : </label>
      <select name="color_scheme" class="testbtn">
      <?php
      $scheme = array("light", "dark");
      foreach($scheme as $color_scheme){
          $isSelected = "";
          if($config['COLOR_SCHEME'] == $color_scheme){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$color_scheme}' $isSelected>$color_scheme</option>";
        }
      ?>
      </select><br><br>
      <label for="spectrogram_palette">Spectrogram palette: </label>
      <select name="spectrogram_palette" class="testbtn">
      <?php
      $pal = $config['SPECTROGRAM_PALETTE'] ?? 'birdnet';
      foreach (array('birdnet'=>'BirdNET classic','viridis'=>'Viridis (dark → yellow)','inferno'=>'Inferno (black → red → yellow)','ocean'=>'Ocean (black → cyan)','grayscale'=>'Grayscale','soxheat'=>'SoX heat') as $k => $l) {
        echo "<option value='{$k}'" . ($k == $pal ? ' selected="selected"' : '') . ">{$l}</option>";
      }
      ?>
      </select><br>
      Applies to the live waterfall and to the SoX images (Overview, detections). Also selectable at the top left of the Spectrogram page.<br><br>
      <label for="spectrogram_height">Live spectrogram height (% of the page): </label>
      <input name="spectrogram_height" type="number" style="width:5em;" min="20" max="100" step="1" value="<?php print(is_numeric($config['SPECTROGRAM_HEIGHT'] ?? null) ? $config['SPECTROGRAM_HEIGHT'] : 80);?>" required/><br><br>
      <b>Colour sensitivity</b> (live waterfall; the SoX images follow floor and range)<br>
      <label for="spectrogram_floor_db">Floor (dB, −120…−40): </label>
      <input name="spectrogram_floor_db" type="number" style="width:5em;" min="-120" max="-40" step="5" value="<?php print(is_numeric($config['SPECTROGRAM_FLOOR_DB'] ?? null) ? $config['SPECTROGRAM_FLOOR_DB'] : -100);?>" required/>
      &nbsp; <label for="spectrogram_range_db">Range (dB, 30…120): </label>
      <input name="spectrogram_range_db" type="number" style="width:5em;" min="30" max="120" step="5" value="<?php print(is_numeric($config['SPECTROGRAM_RANGE_DB'] ?? null) ? $config['SPECTROGRAM_RANGE_DB'] : 70);?>" required/>
      &nbsp; <label for="spectrogram_contrast">Contrast (gamma, 0.5…2): </label>
      <input name="spectrogram_contrast" type="number" style="width:5em;" min="0.5" max="2" step="0.1" value="<?php print(is_numeric($config['SPECTROGRAM_CONTRAST'] ?? null) ? $config['SPECTROGRAM_CONTRAST'] : 1.0);?>" required/><br>
      Floor: signal at or below it takes the darkest colour. Range: width of the scale above the floor. Contrast: below 1 lifts faint sounds, above 1 keeps only the strong ones.
      </td></tr></table><br>
        
      <script>
        function handleChange(checkbox) {
          // this disables the input of manual date and time if the user wants to use the internet time
          var date=document.getElementById("date");
          var time=document.getElementById("time");
          if(checkbox.checked) {
            date.setAttribute("disabled", "disabled");
            time.setAttribute("disabled", "disabled");
          } else {
            date.removeAttribute("disabled");
            time.removeAttribute("disabled");
          }
        }
      </script>
      <?php
      // if NTP service is active, show the checkboxes as checked, and disable the manual input
      $tdc = trim(exec("sudo timedatectl | grep \"NTP service: active\""));
      if (strlen($tdc) > 0) {
        $checkedvalue = "checked";
        $disabledvalue = "disabled";
      } else {
        $checkedvalue = "";
        $disabledvalue = "";
      }
      ?>
      <table class="settingstable"><tr><td>
      <h2>Time and Date</h2>
      <span>If connected to the internet, retrieve time automatically?</span>
      <input type="checkbox" onchange='handleChange(this)' <?php echo $checkedvalue; ?> ><br>
      <?php
      $date = new DateTime('now');
      ?>
      <input onclick="this.showPicker()" type="date" id="date" name="date" value="<?php echo $date->format('Y-m-d') ?>" <?php echo $disabledvalue; ?>>
      <input onclick="this.showPicker()" type="time" id="time" name="time" value="<?php echo $date->format('H:i'); ?>" <?php echo $disabledvalue; ?>><br>
      <br>
      <label for="timezone">Select a Timezone: </label>
      <select name="timezone" class="testbtn">
      <option disabled selected>
        Select a timezone
      </option>
      <?php
      $current_timezone = trim(shell_exec("timedatectl show --value --property=Timezone"));
      $timezone_identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
        
      $n = 425;
      for($i = 0; $i < $n; $i++) {
          $isSelected = "";
          if($timezone_identifiers[$i] == $current_timezone) {
            $isSelected = 'selected="selected"';
          }
          echo "<option $isSelected value='".$timezone_identifiers[$i]."'>".$timezone_identifiers[$i]."</option>";
      }
      ?>
      </select>
      </td></tr></table><br>

      <br><br>

      <input type="hidden" name="status" value="success">
      <input type="hidden" name="submit" value="settings">
<div class="float">
      <button type="submit" id="basicformsubmit" onclick="if(document.getElementById('basicform').checkValidity()){this.innerHTML = 'Updating... please wait.';this.classList.add('disabled')}" name="view" value="Settings">
<?php
if(isset($_GET['status'])){
  echo '<script>alert("Settings successfully updated");</script>';
}
echo "Update Settings";
?>
      </button></div>
      </form>
      <form action="" method="GET">
      <div class="float">
        <button type="submit" name="view" value="Advanced">Advanced Settings</button>
      </div></form>
</div>
