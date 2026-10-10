<?php

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (strpos($requestUri, '/api/v1/') === 0) {
  include_once 'scripts/api.php';
  die();
}

/* Prevent XSS input */
$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);
require_once 'scripts/common.php';
$config = get_config();
$site_name = get_sitename();
$color_scheme = get_color_scheme();
set_timezone();
?>
<!DOCTYPE html>
<html lang="en">
<title><?php echo $site_name; ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link id="iconLink" rel="icon" type="image/x-icon" href="images/BirdNetBr_full.ico" />
<link rel="stylesheet" href="<?php echo $color_scheme . '?v=' . filemtime($color_scheme); ?>"><?php echo theme_style(); ?>
<link rel="stylesheet" type="text/css" href="static/dialog-polyfill.css" />
<body>
<div class="banner">
  <div class="logo">
<?php if(isset($_GET['logo'])) {
// Station logo (owner 2026-09-22): BirdNetBr.png, the edition's own mark; the link goes to the fork.
// The BirdnetPi++ wordmark is centred (birdnetpi-plus.png, owner 2026-10-09: the project is BirdnetPi++).
echo "<a href=\"https://github.com/EvaldoOliveira/BirdnetPiPlusPlus\" target=\"_blank\"><img style=\"width:60;height:60;\" src=\"images/BirdNetBr.png\"></a>";
} else {
echo "<a href=\"https://github.com/EvaldoOliveira/BirdnetPiPlusPlus\" target=\"_blank\"><img src=\"images/BirdNetBr.png\"></a>";
}?>
  </div>
  <div class="sitename"><?php echo $site_name; ?></div>
  <h1><a href="/"><img class="topimage" src="images/birdnetpi-plus.png" alt="BirdnetPi++"></a></h1>
  <div class="stream">
<?php
// Compact header (owner 2026-09-17): station name beside the small logo on the
// left, BirdnetPi++ logo centred, Live Audio on the right — one line, no h3 below.
// Live Audio (owner 2026-10-10) plays in place (static/live-audio.js, the player first made for Now): no reload, so the
// page below keeps running; the time bar and a speaker with a vertical volume slider appear while it plays.
echo "
  <button type=\"button\" class=\"liveaudio\" id=\"live_btn\" onclick=\"liveAudio()\" title=\"Listen to the station's microphone live\">&#9654; Live Audio</button>
  <audio id=\"live_audio\" preload=\"none\" controls controlslist=\"nodownload noplaybackrate\" style=\"display:none\"></audio>
  <span class=\"livevol\" id=\"live_vol\" style=\"display:none\"><button type=\"button\" id=\"live_volbtn\" onclick=\"liveVolumeToggle()\" title=\"Volume\">&#128266;</button>
    <span class=\"volpop\" id=\"live_volpop\"><input type=\"range\" id=\"live_volrange\" min=\"0\" max=\"1\" step=\"0.05\" orient=\"vertical\" oninput=\"liveVolume(this.value)\"></span></span>
  <script src=\"static/live-audio.js?v=" . @filemtime(__DIR__ . '/static/live-audio.js') . "\"></script>";
echo "
  </div>
</div>";
if(isset($_GET['filename'])) {
  $filename = $_GET['filename'];
echo "
<iframe allow=\"autoplay\" src=\"views.php?view=Recordings&filename=$filename\"></iframe>";
} elseif(isset($_GET['view'])) {
  // a page's own address (/?view=...): the side menu links and the address bar point here
  echo "
<iframe allow=\"autoplay\" src=\"views.php?view=" . rawurlencode($_GET['view'])
  . implode('', array_map(function ($k) { return isset($_GET[$k]) ? '&' . $k . '=' . rawurlencode($_GET[$k]) : ''; }, array('sci', 'from', 'to', 'year', 'scope', 'yfrom', 'yto'))) . "\"></iframe>";
} else {
  echo "
<iframe allow=\"autoplay\" src=\"views.php\"></iframe>";
}
