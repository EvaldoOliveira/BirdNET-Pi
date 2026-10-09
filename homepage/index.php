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
echo "<a href=\"https://github.com/EvaldoOliveira/BirdNET-Pi\" target=\"_blank\"><img style=\"width:60;height:60;\" src=\"images/BirdNetBr.png\"></a>";
} else {
echo "<a href=\"https://github.com/EvaldoOliveira/BirdNET-Pi\" target=\"_blank\"><img src=\"images/BirdNetBr.png\"></a>";
}?>
  </div>
  <div class="sitename"><?php echo $site_name; ?></div>
  <h1><a href="/"><img class="topimage" src="images/birdnetpi-plus.png" alt="BirdnetPi++"></a></h1>
  <div class="stream">
<?php
// Compact header (owner 2026-09-17): station name beside the small logo on the
// left, BirdnetPi++ logo centred, Live Audio on the right — one line, no h3 below.
if(isset($_GET['stream'])){
  ensure_authenticated('You cannot listen to the live audio stream');
      // starts only when Live Audio was just clicked: going Back to this page, or the browser restoring it, must not
      // start the live sound again by itself (owner 2026-10-09)
      echo "
  <audio controls id=\"livestream\" preload=\"none\"><source src=\"/stream\"></audio>
  <script>(function () { var n = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (!n || n.type === 'navigate') document.getElementById('livestream').play().catch(function () {});
    window.addEventListener('pageshow', function (e) { if (e.persisted) document.getElementById('livestream').pause(); }); })();</script>";
} else {
    echo "
  <form action=\"index.php\" method=\"GET\">
    <button type=\"submit\" name=\"stream\" value=\"play\">Live Audio</button>
  </form>";
}
echo "
  </div>
</div>";
if(isset($_GET['filename'])) {
  $filename = $_GET['filename'];
echo "
<iframe src=\"views.php?view=Recordings&filename=$filename\"></iframe>";
} elseif(isset($_GET['view'])) {
  // a page's own address (/?view=...): the side menu links and the address bar point here
  echo "
<iframe src=\"views.php?view=" . rawurlencode($_GET['view'])
  . implode('', array_map(function ($k) { return isset($_GET[$k]) ? '&' . $k . '=' . rawurlencode($_GET[$k]) : ''; }, array('sci', 'from', 'to', 'year', 'scope'))) . "\"></iframe>";
} else {
  echo "
<iframe src=\"views.php\"></iframe>";
}
