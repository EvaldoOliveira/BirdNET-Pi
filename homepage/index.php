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
<link id="iconLink" rel="shortcut icon" sizes=85x85 href="images/BirdNetBr.png" />
<link rel="stylesheet" href="<?php echo $color_scheme . '?v=' . date('n.d.y', filemtime($color_scheme)); ?>">
<link rel="stylesheet" type="text/css" href="static/dialog-polyfill.css" />
<body>
<div class="banner">
  <div class="logo">
<?php if(isset($_GET['logo'])) {
// Station logo (owner 2026-09-22): BirdNetBr.png, the BR edition's own mark; the
// link goes to the fork. The BirdNET-Pi wordmark stays centred (bnp.png).
echo "<a href=\"https://github.com/EvaldoOliveira/BirdNET-Pi\" target=\"_blank\"><img style=\"width:60;height:60;\" src=\"images/BirdNetBr.png\"></a>";
} else {
echo "<a href=\"https://github.com/EvaldoOliveira/BirdNET-Pi\" target=\"_blank\"><img src=\"images/BirdNetBr.png\"></a>";
}?>
  </div>
  <div class="sitename"><?php echo $site_name; ?></div>
  <h1><a href="/"><img class="topimage" src="images/bnp.png"></a></h1>
  <div class="stream">
<?php
// Compact header (owner 2026-09-17): station name beside the small logo on the
// left, BirdNET-Pi logo centred, Live Audio on the right — one line, no h3 below.
if(isset($_GET['stream'])){
  ensure_authenticated('You cannot listen to the live audio stream');
      echo "
  <audio controls autoplay><source src=\"/stream\"></audio>";
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
} else {
  echo "
<iframe src=\"views.php\"></iframe>";
}
