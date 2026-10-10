<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);
ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();

set_timezone();
$myDate = date('Y-m-d');
$chart = "Combo-$myDate.png";

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

if(isset($_GET['custom_image'])){
  if(isset($config["CUSTOM_IMAGE"])) {
  ?>
    <br>
    <h3><?php echo $config["CUSTOM_IMAGE_TITLE"]; ?></h3>
    <?php
    $image_data = file_get_contents($config["CUSTOM_IMAGE"]);
    $image_base64 = base64_encode($image_data);
    $img_tag = "<img src='data:image/png;base64," . $image_base64 . "'>";
    echo $img_tag;
  }
  die();
}

if(isset($_GET['blacklistimage'])) {
  ensure_authenticated('You must be authenticated.');
  $imageid = $_GET['blacklistimage'];
  $file_handle = fopen($home."/BirdNET-Pi/scripts/blacklisted_images.txt", 'a+');
  fwrite($file_handle, $imageid . "\n");
  fclose($file_handle);
  unset($_SESSION['images']);
  die("OK");
}

// Analysis settings from the Now page: CONFIDENCE, SENSITIVITY, OVERLAP (the analysis reloads them on the next file)
if (isset($_GET['set_analysis'])) {
  ensure_authenticated('You must be authenticated to change the settings.');
  $limits = array('CONFIDENCE' => array('confidence', 0.01, 0.99), 'SENSITIVITY' => array('sensitivity', 0.5, 1.5), 'OVERLAP' => array('overlap', 0.0, 2.9),
                  'SF_THRESH' => array('sf_thresh', 0.0005, 0.99));
  $f = '/etc/birdnet/birdnet.conf';
  $c = file_get_contents($f);
  foreach ($limits as $key => $l) {
    $v = str_replace(',', '.', trim($_GET[$l[0]] ?? ''));
    if ($v === '' || !is_numeric($v) || floatval($v) < $l[1] || floatval($v) > $l[2]) { echo "Invalid $l[0] (" . $l[1] . ' – ' . $l[2] . ')'; die(); }
    $v = rtrim(rtrim(sprintf($key === 'SF_THRESH' ? '%.4f' : '%.2f', floatval($v)), '0'), '.');
    $c = preg_match("/^$key=/m", $c) ? preg_replace("/^$key=.*/m", "$key=$v", $c) : $c . "\n$key=$v\n";
  }
  // recording length (owner 2026-10-09): whole seconds 3–60; the extraction length can not be longer; the recording
  // service is restarted so the next file has the new length
  $len = trim($_GET['recording_length'] ?? '');
  $restart = false;
  if ($len !== '') {
    if (!ctype_digit($len) || intval($len) < 3 || intval($len) > 60) { echo 'Invalid recording length (3 – 60 s)'; die(); }
    $restart = intval($len) !== intval($config['RECORDING_LENGTH'] ?? 15);
    $c = preg_match('/^RECORDING_LENGTH=/m', $c) ? preg_replace('/^RECORDING_LENGTH=.*/m', 'RECORDING_LENGTH=' . intval($len), $c) : $c . "\nRECORDING_LENGTH=" . intval($len) . "\n";
    if (intval($config['EXTRACTION_LENGTH'] ?? 0) > intval($len)) $c = preg_replace('/^EXTRACTION_LENGTH=.*/m', 'EXTRACTION_LENGTH=' . intval($len), $c);
  }
  $ok = file_put_contents($f, $c) !== false;
  if ($ok && $restart) exec('sudo systemctl restart birdnet_recording.service > /dev/null 2>&1 &');
  echo $ok ? 'OK' : 'Error writing the settings';
  die();
}

// The station moved? (owner 2026-10-10) scripts/location_check.py compares the network position with the coordinates
// at boot and writes location_check.json; the Now page asks. location=use: the detected coordinates (4 decimals), the
// Brazilian state species list when the current filter is a state, the detected timezone; location=keep: remembered,
// asked again only after another move of more than LOCATION_MOVE_KM (default 100 km)
if (isset($_GET['location'])) {
  ensure_authenticated('You must be authenticated to change the settings.');
  $f = $home . '/BirdNET-Pi/location_check.json';
  $st = json_decode((string)@file_get_contents($f), true);
  if (!is_array($st) || empty($st['moved'])) { echo 'Nothing to do'; die(); }
  $user = get_user();
  if ($_GET['location'] === 'use') {
    $lat = round(floatval($st['lat']), 4); $lon = round(floatval($st['lon']), 4);
    $cf = '/etc/birdnet/birdnet.conf';
    $c = file_get_contents($cf);
    $c = preg_replace('/^LATITUDE=.*/m', "LATITUDE=$lat", $c);
    $c = preg_replace('/^LONGITUDE=.*/m', "LONGITUDE=$lon", $c);
    $new_list = null;
    if (preg_match('/^BR-[A-Z]{2}$/', $config['SPECIES_LIST'] ?? '')) {
      $uf = trim((string)shell_exec('python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/locate_state.py') . ' ' . escapeshellarg((string)$lat) . ' ' . escapeshellarg((string)$lon) . ' 2>/dev/null'));
      if (preg_match('/^[A-Z]{2}$/', $uf) && is_file($home . "/BirdNET-Pi/model/include_lists/BR-$uf.txt")) {
        $new_list = "BR-$uf";
        $c = preg_replace('/^SPECIES_LIST=.*/m', "SPECIES_LIST=$new_list", $c);
      }
    }
    if (file_put_contents($cf, $c) === false) { echo 'Error writing the settings'; die(); }
    if ($new_list !== null && $new_list !== ($config['SPECIES_LIST'] ?? '')) {
      shell_exec('sudo -u ' . escapeshellarg($user) . ' python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/select_species_list.py') . ' > /dev/null 2>&1');
    }
    $tz = (string)($st['timezone'] ?? '');
    if ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
      shell_exec('sudo timedatectl set-timezone ' . escapeshellarg($tz));
      if (file_exists('/etc/timezone')) shell_exec('echo ' . escapeshellarg($tz) . ' | sudo tee /etc/timezone > /dev/null');
    }
    $st['moved'] = false; $st['configured'] = array('lat' => $lat, 'lon' => $lon); $st['distance_km'] = 0; $st['applied'] = date('Y-m-d H:i:s');
  } else {
    $st['moved'] = false; $st['dismissed'] = array('lat' => $st['lat'], 'lon' => $st['lon']);
  }
  echo file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false ? 'OK' : 'Error';
  die();
}

// Currently Analyzing status (owner 2026-10-09): recordings waiting for the analysis (StreamData *.wav minus the one
// being recorded), how far behind real time that is, and the recording the spectrogram shows (analyzing_now.txt)
if (isset($_GET['analysis_status'])) {
  header('Content-Type: application/json');
  $sd = rtrim($config['RECS_DIR'] ?? ($home . '/BirdSongs'), '/') . '/StreamData';
  $waiting = max(0, count(glob($sd . '/*.wav') ?: array()) - 1);
  $now = trim((string)@file_get_contents($sd . '/analyzing_now.txt'));
  $shown = preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})h(\d{2})m(\d{2})s/', basename($now), $m) ? "$m[2]:$m[3]:$m[4]" : '';
  // behind = age of the recording being analysed (its name carries when it started); without it, waiting × length
  $behind = $shown !== '' ? max(0, time() - strtotime("$m[1] $shown")) : $waiting * max(1, intval($config['RECORDING_LENGTH'] ?? 15));
  $running = trim((string)shell_exec('systemctl is-active birdnet_analysis.service 2>/dev/null')) === 'active';
  echo json_encode(array('waiting' => $waiting, 'behind' => $behind, 'shown' => $shown, 'running' => $running));
  die();
}

// "Set as default" of Currently Analyzing: NOW_ANALYZING = show | hide
if (isset($_GET['set_now_analyzing'])) {
  ensure_authenticated('You must be authenticated to change the settings.');
  $v = $_GET['set_now_analyzing'] === 'hide' ? 'hide' : 'show';
  $f = '/etc/birdnet/birdnet.conf';
  $c = file_get_contents($f);
  $c = preg_match('/^NOW_ANALYZING=/m', $c) ? preg_replace('/^NOW_ANALYZING=.*/m', "NOW_ANALYZING=$v", $c)
    : $c . "\n## NOW_ANALYZING: the Now page opens with the Currently Analyzing spectrogram shown (show) or hidden (hide)\nNOW_ANALYZING=$v\n";
  echo (file_put_contents($f, $c) !== false) ? 'OK' : 'Error writing the settings';
  die();
}

// "Set as default" of the Now page: NOW_VIEW = spectrogram | list
if (isset($_GET['set_now_view'])) {
  ensure_authenticated('You must be authenticated to change the settings.');
  $v = $_GET['set_now_view'] === 'list' ? 'list' : 'spectrogram';
  $f = '/etc/birdnet/birdnet.conf';
  $c = file_get_contents($f);
  $c = preg_match('/^NOW_VIEW=/m', $c) ? preg_replace('/^NOW_VIEW=.*/m', "NOW_VIEW=$v", $c)
    : $c . "\n## NOW_VIEW: how the Now page shows the most recent detections: spectrogram (cards) or list\nNOW_VIEW=$v\n";
  echo (file_put_contents($f, $c) !== false) ? 'OK' : 'Error writing the settings';
  die();
}

if(isset($_GET['fetch_chart_string']) && $_GET['fetch_chart_string'] == "true") {
  $myDate = date('Y-m-d');
  $chart = "Combo-$myDate.png";
  echo $chart;
  die();
}

if(isset($_GET['ajax_detections']) && $_GET['ajax_detections'] == "true" && isset($_GET['previous_detection_identifier'])) {

  $statement4 = $db->prepare('SELECT Com_Name, Sci_Name, Date, Time, Confidence, File_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 15');
  ensure_db_ok($statement4);
  $result4 = $statement4->execute();
  if(!isset($_SESSION['images'])) {
    $_SESSION['images'] = [];
  }
  $iterations = 0;
  $image_provider = null;

  // hopefully one of the 5 most recent detections has an image that is valid, we'll use that one as the most recent detection until the newer ones get their images created
  while($mostrecent = $result4->fetchArray(SQLITE3_ASSOC)) {
    $comname = preg_replace('/ /', '_', $mostrecent['Com_Name']);
    $sciname = preg_replace('/ /', '_', $mostrecent['Sci_Name']);
    $comnamegraph = str_replace("'", "\'", $mostrecent['Com_Name']);
    $comname = preg_replace('/\'/', '', $comname);
    $filename = "By_Date/".$mostrecent['Date']."/".$comname."/".$mostrecent['File_Name'];

    // check to make sure the image actually exists, sometimes it takes a minute to be created\
    if(file_exists($home."/BirdSongs/Extracted/".$filename.".png")){
      if($_GET['previous_detection_identifier'] == $filename) { die(); }
      if($_GET['only_name'] == "true") { echo $comname.",".$filename;die(); }

      $iterations++;

      if (!empty($config["IMAGE_PROVIDER"])) {
        if ($image_provider === null) {
          $image_provider = new Wikipedia();
          if ($image_provider->is_reset()) {
            $_SESSION['images'] = [];
          }
        }

        // if we already searched for this species before, use the previous image rather than doing an unneccesary api call
        $key = array_search($comname, array_column($_SESSION['images'], 0));
        if ($key !== false) {
          $image = $_SESSION['images'][$key];
        } else {
          $cached_image = $image_provider->get_image($mostrecent['Sci_Name']);
          array_push($_SESSION["images"], array($comname, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]));
          $image = $_SESSION['images'][count($_SESSION['images']) - 1];
        }
      }
    ?>
        <style>
        .fade-in {
          opacity: 1;
          animation-name: fadeInOpacity;
          animation-iteration-count: 1;
          animation-timing-function: ease-in;
          animation-duration: 1s;
        }

        @keyframes fadeInOpacity {
          0% {
            opacity: 0;
          }
          100% {
            opacity: 1;
          }
        }
        </style>
        <table class="<?php echo ($_GET['previous_detection_identifier'] == 'undefined') ? '' : 'fade-in';  ?>">
          <h3>Most Recent Detection: <span style="font-weight: normal;"><?php echo $mostrecent['Date']." ".$mostrecent['Time'];?></span></h3>
          <tr>
            <td class="relative"><?php echo detection_actions($mostrecent['Date'] . '/' . str_replace(array("'", ' '), array('', '_'), $mostrecent['Com_Name']) . '/' . $mostrecent['File_Name']); ?>
            <!-- photo on the left, then names (common name = link to the species page, scientific, English), links and
                 confidence — like Today's Detections (owner 2026-10-08) -->
            <div class="tdhead">
              <?php if(!empty($config["IMAGE_PROVIDER"]) && strlen($image[2]) > 0) { ?>
                <img onclick='setModalText(<?php echo $iterations; ?>,"<?php echo urlencode($image[2]); ?>", "<?php echo $image[3]; ?>", "<?php echo $image[4]; ?>", "<?php echo $image[1]; ?>", "<?php echo $image[5]; ?>")' src="<?php echo $image[1]; ?>" class="img1">
              <?php } ?>
              <div class="tdinfo">
                <?php echo species_title($mostrecent['Sci_Name'], '<b><a class="a2" href="views.php?view=Bird&amp;sci=' . rawurlencode($mostrecent['Sci_Name']) . '" title="Open the species page">' . $mostrecent['Com_Name'] . '</a></b>',
                  '<img class="splink" title="View species stats" onclick="generateMiniGraph(this, \'' . $comnamegraph . '\')" src="images/chart.svg">', true, false); ?>
                <div>Confidence: <?php echo $percent = round((float)round($mostrecent['Confidence'],2) * 100 ) . '%';?></div>
              </div>
            </div>
            <div class='custom-audio-player' data-audio-src="<?php echo $filename; ?>" data-image-src="<?php echo $filename.".png";?>"></div>
            </td>
          </tr>
        </table> <?php break;
      }
  }
  if($iterations == 0) {
    $statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')');
    ensure_db_ok($statement2);
    $result2 = $statement2->execute();
    $todaycount = $result2->fetchArray(SQLITE3_ASSOC);
    if($todaycount['COUNT(*)'] > 0) {
      echo "<h3>Your system is currently processing a backlog of audio. This can take several hours before normal functionality of your BirdnetPi++ resumes.</h3>";
    } else {
      echo "<h3>No Detections For Today.</h3>";
    }
  }
  die();
}

if(isset($_GET['ajax_left_chart']) && $_GET['ajax_left_chart'] == "true") {

  $chart_data = get_summary();
  $_SESSION['chart_data'] = $chart_data;
  // species detected today for the first time (never before today)
  $new_today = 0;
  $stmt_new = get_db()->prepare("SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections WHERE Date = DATE('now', 'localtime')
    AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now', 'localtime'))");
  if ($stmt_new !== false && ($res_new = $stmt_new->execute()) !== false) {
    $new_today = (int)($res_new->fetchArray(SQLITE3_ASSOC)['n'] ?? 0);
  }
?>
<table class="totals">
  <tr>
    <th>#Total</th>
    <th>#Today</th>
    <th>Sp. Total</th>
    <th>Sp. Today</th>
    <th>New Today</th>
  </tr>
  <tr>
    <td><?php echo $chart_data['totalcount'];?></td>
    <td><form action="" method="GET"><button type="submit" name="view" value="Now"><?php echo $chart_data['todaycount'];?></button></form></td>
    <td><form action="" method="GET"><button type="submit" name="view" value="Species Stats"><?php echo $chart_data['totalspeciestally'];?></button></form></td>
    <td><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>"><?php echo $chart_data['speciestally'];?></button></form></td>
    <td><?php echo $new_today; ?></td>
  </tr>
</table>
<?php
  die();
}

if(isset($_GET['ajax_center_chart']) && $_GET['ajax_center_chart'] == "true") {

  // Retrieve the cached data from session without regenerating
  $chart_data = $_SESSION['chart_data'];
?>
  <table><tr>
  <th>Total</th>
  <th>Today</th>
  <th>Last Hour</th>
  <th>Species Total</th>
  <th>Species Today</th>
      </tr>
      <tr>
      <td><?php echo $chart_data['totalcount'];?></td>
      <td><form action="" method="GET"><input type="hidden" name="view" value="Now"><?php echo $chart_data['todaycount'];?></td></form>
      <td><?php echo $chart_data['hourcount'];?></td>
      <td><form action="" method="GET"><button type="submit" name="view" value="Species Stats"><?php echo $chart_data['totalspeciestally'];?></button></td></form>
      <td><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>"><?php echo $chart_data['speciestally'];?></button></td></form>
  </tr>
  </table>

<?php
  die();
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Overview</title>
</head>';
}
?>
<div class="overview">
  <dialog style="margin-top: 5px;max-height: 95vh;
  overflow-y: auto;overscroll-behavior:contain" id="attribution-dialog">
    <h1 id="modalHeading"></h1>
    <p id="modalText"></p>
    <button onclick="hideDialog()">Close</button>
    <button style="font-weight:bold;color:blue" onclick="if(confirm('Are you sure you want to blacklist this image?')) { blacklistImage(); }" <?php if($config["IMAGE_PROVIDER"] === 'WIKIPEDIA'){ echo 'hidden';} ?> >Blacklist this image</button>
  </dialog>
  <script src="static/dialog-polyfill.js"></script>
  <script src="static/Chart.bundle.js"></script>
  <script src="static/chartjs-plugin-trendline.min.js"></script>
  <script>
  var last_photo_link;
  var dialog = document.querySelector('dialog');
  dialogPolyfill.registerDialog(dialog);

  function showDialog() {
    document.getElementById('attribution-dialog').showModal();
  }

  function hideDialog() {
    document.getElementById('attribution-dialog').close();
  }

  function blacklistImage() {
    const match = last_photo_link.match(/\d+$/); // match one or more digits
    const result = match ? match[0] : null; // extract the first match or return null if no match is found
    console.log(last_photo_link)
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.responseText.length > 0) {
       location.reload();
      }
    }
    xhttp.open("GET", "overview.php?blacklistimage="+result, true);
    xhttp.send();

  }

  function shorten(u) {
    if (u.length < 48) {
      return u;
    }
    uend = u.slice(u.length - 16);
    ustart = u.substr(0, 32);
    var shorter = ustart + '...' + uend;
    return shorter;
  }

  function setModalText(iter, title, text, authorlink, photolink, licenseurl) {
    let text_display = shorten(text);
    let authorlink_display = shorten(authorlink);
    let licenseurl_display = shorten(licenseurl);
    document.getElementById('modalHeading').innerHTML = "Photo: \""+decodeURIComponent(title.replaceAll("+"," "))+"\" Attribution";
    document.getElementById('modalText').innerHTML = "<div><img style='border-radius:5px;max-height: calc(100vh - 15rem);display: block;margin: 0 auto;' src='"+photolink+"'></div><br><div style='white-space:nowrap'>Image link: <a target='_blank' href="+text+">"+text_display+"</a><br>Author link: <a target='_blank' href="+authorlink+">"+authorlink_display+"</a><br>License URL: <a href="+licenseurl+" target='_blank'>"+licenseurl_display+"</a></div>";
    last_photo_link = text;
    showDialog();
  }
  </script>  
<div class="overview-stats part-<?php echo ($overview_part ?? 'now') === 'records' ? 'records' : 'now'; ?>">
<div class="left-column">
</div>
<div class="right-column">
<div class="center-column">
</div>
<?php
$statement = $db->prepare("
SELECT d_today.Com_Name, d_today.Sci_Name, d_today.Date, d_today.Time, d_today.Confidence, d_today.File_Name, 
       MAX(d_today.Confidence) as MaxConfidence,
       (SELECT MAX(Date) FROM detections d_prev WHERE d_prev.Sci_Name = d_today.Sci_Name AND d_prev.Date < DATE('now', 'localtime')) as LastSeenDate,
       (SELECT COUNT(*) FROM detections d_occ WHERE d_occ.Sci_Name = d_today.Sci_Name AND d_occ.Date = DATE('now', 'localtime')) as OccurrenceCount
FROM detections d_today
WHERE d_today.Date = DATE('now', 'localtime')
GROUP BY d_today.Sci_Name
");
ensure_db_ok($statement);
$result = $statement->execute();

$new_species = [];
$rare_species = [];
$rare_species_threshold = isset($config['RARE_SPECIES_THRESHOLD']) ? $config['RARE_SPECIES_THRESHOLD'] : 30;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $last_seen_date = $row['LastSeenDate'];
    if ($last_seen_date === NULL) {
        $new_species[] = $row;
    } else {
        $date1 = new DateTime($last_seen_date);
        $date2 = new DateTime('now');
        $interval = $date1->diff($date2);
        $days_ago = $interval->days;
        if ($days_ago > $rare_species_threshold) {
            $row['DaysAgo'] = $days_ago;
            $rare_species[] = $row;
        }
    }
}

if (!isset($_SESSION['images'])) {
    $_SESSION['images'] = [];
}

function display_species($species_list, $title, $show_last_seen=false) {
    global $config, $_SESSION, $image_provider;
    $species_count = count($species_list);
    if ($species_count > 0): ?>
        <div class="<?php echo strtolower(str_replace(' ', '_', $title)); ?>">
            <?php if ($title !== 'New Species') { // the new species count is in the totals (New Sp. Today) ?>
            <h2 style="text-align:center;"><?php echo $species_count; ?> <?php echo strtolower($title); ?> detected today!</h2>
            <?php } ?>
            <?php if ($species_count > 5 && $title === 'New Species'): // no recordings button for new species (owner 2026-10-08) ?>
            <?php elseif ($species_count > 5): ?>
                <table><tr><td style="text-align:center;"><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>">Open Today's detections page</button></form></td></tr></table>
            <?php else: ?>
                <table>
                    <?php
                    $iterations = 0;
                    foreach($species_list as $todaytable):
                        $iterations++;
                        $comname = preg_replace('/ /', '_', $todaytable['Com_Name']);
                        $comname = preg_replace('/\'/', '', $comname);
                        $comnamegraph = str_replace("'", "\'", $todaytable['Com_Name']);
                        $filename = "/By_Date/".$todaytable['Date']."/".$comname."/".$todaytable['File_Name'];
                        $filename_formatted = $todaytable['Date']."/".$comname."/".$todaytable['File_Name'];
                        $sciname = preg_replace('/ /', '_', $todaytable['Sci_Name']);
                        $engname = get_com_en_name($todaytable['Sci_Name']);
                        $engname_url = str_replace("'", '', str_replace(' ', '_', $engname));
                        $info_url = get_info_url($todaytable['Sci_Name']);
                        $url = $info_url['URL'];
                        $url_title = $info_url['TITLE'];

                        $image_url = ""; // Default empty image URL
                        
                        if (!empty($config["IMAGE_PROVIDER"])) {
                          if ($image_provider === null) {
                            $image_provider = new Wikipedia();
                            if ($image_provider->is_reset()) {
                              $_SESSION['images'] = [];
                            }
                          }

                            // Check if the image has been cached in the session
                            $key = array_search($comname, array_column($_SESSION['images'], 0));
                            if ($key !== false) {
                                $image = $_SESSION['images'][$key];
                            } else {
                                // Retrieve the image from Flickr API and cache it
                                $cached_image = $image_provider->get_image($todaytable['Sci_Name']);
                                array_push($_SESSION["images"], array($comname, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]));
                                $image = $_SESSION['images'][count($_SESSION['images']) - 1];
                            }
                            $image_url = $image[1] ?? ""; // Get the image URL if available
                        }

                        $last_seen_text = "";
                        if ($show_last_seen && isset($todaytable['DaysAgo'])) {
                            $days_ago = $todaytable['DaysAgo'];
                            if ($days_ago > 30) {
                                $months_ago = floor($days_ago / 30);
                                $last_seen_text = "<br><i><span class='text left'>Last seen: </span>{$months_ago}mo ago</i>";
                            } else {
                                $last_seen_text = "<br><i><span class='text left'>Last seen: </span>{$days_ago}d ago</i>";
                            }
                        }

                        $occurrence_text = "";
                        if (isset($todaytable['OccurrenceCount']) && $todaytable['OccurrenceCount'] > 1) {
                            $occurrence_text = " ({$todaytable['OccurrenceCount']}x)";
                        }
                    ?>
                    <tr class="relative" id="<?php echo $iterations; ?>">
                        <td><?php if (!empty($image_url)): ?>
                          <img onclick='setModalText(<?php echo $iterations; ?>,"<?php echo urlencode($image[2]); ?>", "<?php echo $image[3]; ?>", "<?php echo $image[4]; ?>", "<?php echo $image[1]; ?>", "<?php echo $image[5]; ?>")' src="<?php echo $image_url; ?>" style="max-width: none; height: 50px; width: 50px; border-radius: 5px; cursor: pointer;" class="img1" title="Image from Flickr" />
                        <?php endif; ?></td>
                        <td id="recent_detection_middle_td">
                            <div><form action="" method="GET">
                                    <input type="hidden" name="view" value="Species Stats">
                                    <?php echo species_title($todaytable['Sci_Name'], '<b><a class="a2" href="views.php?view=Bird&amp;sci=' . rawurlencode($todaytable['Sci_Name']) . '" title="Open the species page">' . $todaytable['Com_Name'] . '</a></b>', '', true, false); ?>
                                    <i>
                                        <?php if ($show_last_seen): ?>
                                            <img style="height: 1em;cursor:pointer;float:unset;display:inline" title="View species stats" onclick="generateMiniGraph(this, '<?php echo $comnamegraph; ?>', 160)" width="25" src="images/chart.svg">
                                        <?php endif; ?>
                                        <?php echo detection_actions($filename_formatted, false, 'height: 1em;float:unset;display:inline', 16); ?>
                                    </i>
                            </form></div>
                        </td>
                        <td style="white-space: nowrap;"><?php
                                echo '<span class="text left">Max confidence: </span>' . round($todaytable['Confidence'] * 100 ) . '%' . $occurrence_text;
                                echo "<br><span class='text left'>First detection: </span>{$todaytable['Time']}";
                                echo $last_seen_text;
                        ?></td>
                      </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    <?php endif;
}

display_species($new_species, 'New Species');
display_species($rare_species, 'Rare Species', true);
?>
<script src="static/chart-rows.js"></script>
<div class="chart">
<script>
  // the totals table starts where the chart's bars start: daily_plot.py draws the bar axes from 12.5 % of
  // the image width (subplots_adjust left=0.125), the species labels sit before that (owner 2026-10-08)
  function alignTotalsToChart() {
    var img = document.getElementById('chart'), col = document.querySelector('.overview-stats .left-column');
    var t = col ? col.querySelector('table') : null;
    if (!img || !t || !img.offsetWidth) return;
    col.style.justifyContent = 'flex-start';
    var x = img.getBoundingClientRect().left + img.offsetWidth * 0.125 - col.getBoundingClientRect().left;
    t.style.setProperty('margin-left', Math.max(0, Math.round(x)) + 'px', 'important');
  }
  window.addEventListener('resize', alignTotalsToChart);
  new MutationObserver(alignTotalsToChart).observe(document.body, { childList: true, subtree: true });
</script>
<?php
$refresh = $config['RECORDING_LENGTH'];
$dividedrefresh = $refresh/4;
if($dividedrefresh < 1) { 
  $dividedrefresh = 1;
}
$time = time();
if (file_exists('./Charts/'.$chart)) {
  // every species row of the chart opens its species page (row map written by daily_plot.py)
  echo "<div class='chartwrap'><img id='chart' src=\"Charts/$chart?nocache=$time\" onload='chartRowLinks(this)'></div>";
} 
?>
</div>

<!-- today's totals and the search, formerly the top of Today's Detections (owner 2026-10-09) -->
<?php $loc = json_decode((string)@file_get_contents($home . '/BirdNET-Pi/location_check.json'), true);
if (is_array($loc) && !empty($loc['moved'])) {
  $where = htmlspecialchars(implode(', ', array_filter(array($loc['city'] ?? '', $loc['region'] ?? '', $loc['country'] ?? ''))));
  $list_note = preg_match('/^BR-[A-Z]{2}$/', $config['SPECIES_LIST'] ?? '') ? ', the species list of that state' : ''; ?>
<div class="now-only locmoved" id="locmoved">&#128205; The station seems to be in <b><?php echo $where; ?></b> — about <?php echo intval($loc['distance_km']); ?> km from its configured location
  (network position at boot, <?php echo htmlspecialchars($loc['checked'] ?? ''); ?>).
  <button type="button" onclick="locAnswer('use')" title="Coordinates <?php echo round($loc['lat'], 4) . ', ' . round($loc['lon'], 4); ?><?php echo $list_note; ?> and timezone <?php echo htmlspecialchars($loc['timezone'] ?? ''); ?>">Use this location</button>
  <button type="button" class="keep" onclick="locAnswer('keep')">Not moved</button></div>
<script>
function locAnswer(a) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.responseText.trim() === 'OK') { document.getElementById('locmoved').remove(); if (a === 'use') location.reload(); }
    else alert(this.status === 401 ? 'Log in first.' : this.responseText);
  };
  x.open('GET', 'overview.php?location=' + a, true);
  x.send();
}
</script>
<?php } ?>
<div class="now-only nowtoday"><div id="todaystats" class="overview"></div>
  <!-- analysis settings, applied from the next recording (owner 2026-10-09) -->
  <!-- folded to one Param. button (owner 2026-10-10: the box takes too much room on a phone); open / closed is remembered
       per browser — closed by default on a phone, open on a wider screen -->
  <div class="nacontrols"><div class="nablock">
  <button type="button" class="naopen" id="na_open" onclick="naShow(true)" title="Analysis settings (Min. Conf., Loc. Thresh., Sigm. Sens., Rec. Length, Overlap)">Param.</button>
  <form class="nowanalysis" id="na_form" onsubmit="saveAnalysis(event)" title="Applied from the next recording (about <?php echo intval($config['RECORDING_LENGTH'] ?? 15); ?> s), no restart">
    <!-- Default (owner 2026-10-10): the model generation's defaults in every field, then saved like Apply -->
    <?php $is_v3 = strpos($config['MODEL'] ?? '', 'BirdNET-Plus') === 0;
      $na_defaults = $is_v3 ? array('na_conf' => '0.35', 'na_sf' => '0.5', 'na_sens' => '1', 'na_len' => '15', 'na_over' => '1.2')
                            : array('na_conf' => '0.7', 'na_sf' => '0.03', 'na_sens' => '1.25', 'na_len' => '15', 'na_over' => '0'); ?>
    <button type="button" class="nadefault" onclick="naDefaults()" data-defaults="<?php echo htmlspecialchars(json_encode($na_defaults), ENT_QUOTES); ?>"
      title="Back to the defaults of the model in use (<?php echo $is_v3 ? 'BirdNET+ V3: Min. Conf. 0.35, Loc. Thresh. 0.5, Sigm. Sens. 1, Rec. Length 15 s, Overlap 1.2' : 'BirdNET V2.4: Min. Conf. 0.7, Loc. Thresh. 0.03, Sigm. Sens. 1.25, Rec. Length 15 s, Overlap 0'; ?>) and save">Default</button>
    <!-- two groups (owner 2026-10-09): the thresholds, then the analysis settings -->
    <span class="nagroup" title="Thresholds">
      <label title="Min. Conf. — the lowest score a detection needs to be kept (CONFIDENCE).&#10;Default BirdNET+ V3: 0.35&#10;Default BirdNET V2.4: 0.70&#10;Range: 0.01 – 0.99&#10;Higher: fewer detections, more reliable; quiet or distant birds are missed.&#10;Lower: more detections, more of them wrong (a species threshold, Sp. Override, replaces it for that species).">Min. Conf. <input type="number" id="na_conf" min="0.01" max="0.99" step="any" value="<?php echo htmlspecialchars($config['CONFIDENCE'] ?? ''); ?>"></label>
      <label title="Loc. Thresh. — species the location model expects here this week below this probability are left out (SF_THRESH).&#10;Default BirdNET+ V3: 0.50&#10;Default BirdNET V2.4: 0.03&#10;Range: 0.0005 – 0.99&#10;Higher: only the species expected here (the Whitelist bypasses it).&#10;Lower: more species allowed — rare and vagrant ones too, with more errors.">Loc. Thresh. <input type="number" id="na_sf" min="0.0005" max="0.99" step="any" value="<?php echo htmlspecialchars($config['SF_THRESH'] ?? ''); ?>"></label>
    </span>
    <span class="nagroup" title="Analysis">
      <label title="Sigm. Sens. — bends the model&#x27;s scores before Min. Conf. is applied (SENSITIVITY).&#10;Default BirdNET+ V3: 1.0&#10;Default BirdNET V2.4: 1.25&#10;Range: 0.5 – 1.5&#10;Higher: scores rise — more detections, more false ones.&#10;Lower: scores fall — fewer detections.">Sigm. Sens. <input type="number" id="na_sens" min="0.5" max="1.5" step="any" value="<?php echo htmlspecialchars($config['SENSITIVITY'] ?? ''); ?>"></label>
      <label title="Rec. Length — seconds of each recorded and analysed file (RECORDING_LENGTH); a change restarts the recording service.&#10;Default BirdNET+ V3: 15 s&#10;Default BirdNET V2.4: 15 s&#10;Range: 3 – 60 s&#10;Higher: fewer files; a detection shows up later.&#10;Lower: detections appear sooner; more files, a little more work per minute.">Rec. Length <input type="number" id="na_len" min="3" max="60" step="any" value="<?php echo htmlspecialchars($config['RECORDING_LENGTH'] ?? '15'); ?>"></label>
      <label title="Overlap — seconds the 3 s analysis windows overlap (OVERLAP).&#10;Default BirdNET+ V3: 1.2 s&#10;Default BirdNET V2.4: 0 s&#10;Range: 0 – 2.9 s&#10;Higher: calls on a window edge are caught; more CPU and repeated detections of one call.&#10;Lower: fewer windows; 0 = side by side, the fastest.">Overlap <input type="number" id="na_over" min="0" max="2.9" step="any" value="<?php echo htmlspecialchars($config['OVERLAP'] ?? ''); ?>"></label>
    </span>
    <span class="nabuttons"><button type="button" class="naclose" onclick="naShow(false)" title="Fold the settings away">Close</button><button type="submit" id="na_apply">Apply</button></span>
  </form>
  </div>
  </div>
</div>
<!-- Now page (owner 2026-10-08): Currently Analyzing first, then the 30 most recent detections as cards. The most recent
     detection card is no longer shown; it is still loaded (hidden) because the page watches it to notice a new
     detection and refresh the cards (30, like Best Detections). -->
<h3 class="now-only nowsec">Currently Analyzing
  <span class="nowmodes"><button type="button" id="analyzing_toggle" onclick="toggleAnalyzing()">Hide spectrogram</button>
  <button type="button" class="nowdefault" id="analyzing_default" onclick="analyzingSetDefault()" title="Keep it like this when the page opens (Basic Settings › Spectrogram and colours)">Set as default</button></span>
  <span id="analysis_status" class="nowstatus" title="Recordings waiting for the analysis (behind real time), and the time of the recording the spectrogram shows"></span></h3>
<?php
$refresh = $config['RECORDING_LENGTH'];
$time = time();
echo "<img id=\"spectrogramimage\" src=\"spectrogram.png?nocache=$time\">";

?>
<div id="most_recent_detection" style="display:none"></div>
<h3 class="now-only now-cards nowhead nowsec">Last 50 Detections
  <span class="nowmodes"><button type="button" data-mode="spectrogram" onclick="nowMode('spectrogram')">Spectrogram</button><button type="button" data-mode="list" onclick="nowMode('list')">List</button>
  <button type="button" class="nowdefault" id="nowview_default" onclick="nowSetDefault()" title="Show this view first (Basic Settings › Spectrogram and colours)">Set as default</button></span>
  <span class="nowsearch"><span class="nowmodes nowfilters" title="Only the detections worth a look: confidence or location probability below the value">
    <span class="nowfilterlabel" title="Filter"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="Filter" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span><button type="button" data-filter="" onclick="nowFilter('')">All</button><button type="button" data-filter="uncommon" onclick="nowFilter('uncommon')" title="Only species detected at most N times from yesterday 00:00 until now">Uncommon</button><button type="button" data-filter="lowconf" onclick="nowFilter('lowconf')">Low Conf</button><button type="button" data-filter="lowprob" onclick="nowFilter('lowprob')">Low Prob</button>
    </span>
    <?php // Uncommon picklist: from the largest species count since yesterday 00:00 down to 1, in round steps
      $most = intval(get_db()->querySingle("SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM detections WHERE Date >= DATE('now', 'localtime', '-1 day') GROUP BY Sci_Name)"));
      $steps = array();
      foreach (array(1, 2, 3, 5, 10, 20, 30, 50, 100, 200, 300, 500, 1000, 2000, 3000, 5000, 10000, 20000, 50000) as $v) if ($v < $most) $steps[] = $v;
      if ($most > 0) $steps[] = $most;
      rsort($steps); ?>
    <script>var NOW_UNCOMMON_STEPS = <?php echo json_encode($steps ?: array(1)); ?>;</script>
    <input autocomplete="off" size="22" type="search" placeholder="Search detections..." id="searchterm"
    title="Common or scientific name, time, confidence; start with NOT to leave matches out" oninput="nowSearchTyped(this.value)"></span></h3>
<div style="padding-bottom:10px;" id="detections_table"><h3>Loading...</h3></div>

<div id="customimage"></div>
<br>

</div>
</div>
</div>
<script>
// we're passing a unique ID of the currently displayed detection to our script, which checks the database to see if the newest detection entry is that ID, or not. If the IDs don't match, it must mean we have a new detection and it's loaded onto the page
function loadDetectionIfNewExists(previous_detection_identifier=undefined) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    // if there's a new detection that needs to be updated to the page
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy") && !this.responseText.includes("No Detections") || previous_detection_identifier == undefined) {
      document.getElementById("most_recent_detection").innerHTML = this.responseText;

      // only going to load left chart & the recent cards if there's a new detection (cards: newest page only)
      loadLeftChart();
      if (nowOffset === 0 || previous_detection_identifier == undefined) loadFiveMostRecentDetections();
      refreshTodayStats();
      refreshTopTen();

      // Now that new HTML is inserted, re-run player init:
      initCustomAudioPlayers();
    }
  }
  xhttp.open("GET", "overview.php?ajax_detections=true&previous_detection_identifier="+previous_detection_identifier, true);
  xhttp.send();
}
function loadLeftChart() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementsByClassName("left-column")[0].innerHTML = this.responseText;
      loadCenterChart();
    }
  }
  xhttp.open("GET", "overview.php?ajax_left_chart=true", true);
  xhttp.send();
}
function loadCenterChart() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementsByClassName("center-column")[0].innerHTML = this.responseText;
    }
  }
  xhttp.open("GET", "overview.php?ajax_center_chart=true", true);
  xhttp.send();
}
function refreshTopTen() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
  if(this.responseText.length > 0 && !this.responseText.includes("Database is busy") && !this.responseText.includes("No Detections") || previous_detection_identifier == undefined) {
    if (document.getElementById("chart")) {document.getElementById("chart").src = "Charts/"+this.responseText+"?nocache="+Date.now();}
  }
  }
  xhttp.open("GET", "overview.php?fetch_chart_string=true", true);
  xhttp.send();
}
function refreshDetection() {
  if (!document.hidden) {
    const audioPlayers = document.querySelectorAll(".custom-audio-player");
    // If no custom-audio-player elements are found, refresh
    if (audioPlayers.length === 0) {
      loadDetectionIfNewExists();
      return;
    }
    // Check if any custom audio player is currently playing
    let isPlaying = false;
    audioPlayers.forEach((player) => {
      const audioEl = player.querySelector("audio");
      if (audioEl && audioEl.currentTime > 0 && !audioEl.paused && !audioEl.ended && audioEl.readyState > 2) {
        isPlaying = true;
      }
    });
    // nor an audio of the Now list
    document.querySelectorAll('#detections_table audio').forEach(function (a) { if (!a.paused && !a.ended) isPlaying = true; });
    // If none are playing, refresh detections
    if (!isPlaying) {
      const currentIdentifier = audioPlayers[0]?.dataset.audioSrc || undefined;
      loadDetectionIfNewExists(currentIdentifier);
    }
  }
}
// Currently Analyzing: hide / show the live spectrogram; NOW_ANALYZING of Basic Settings (show | hide) is how the page
// opens, "Set as default" saves the current state there (owner 2026-10-09)
var analyzingDefaultHidden = <?php echo json_encode(($config['NOW_ANALYZING'] ?? 'show') === 'hide'); ?>;
function applyAnalyzing(hidden) {
  var img = document.getElementById('spectrogramimage'), b = document.getElementById('analyzing_toggle'), d = document.getElementById('analyzing_default');
  if (img) img.style.display = hidden ? 'none' : '';
  if (b) b.textContent = hidden ? 'Show spectrogram' : 'Hide spectrogram';
  if (d) d.style.visibility = (hidden === analyzingDefaultHidden) ? 'hidden' : 'visible';
}
function toggleAnalyzing() {
  var hidden = document.getElementById('spectrogramimage').style.display !== 'none';
  applyAnalyzing(hidden);
  if (!hidden) document.getElementById('spectrogramimage').src = 'spectrogram.png?nocache=' + Date.now();
}
function analyzingSetDefault() {
  var hidden = document.getElementById('spectrogramimage').style.display === 'none';
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { analyzingDefaultHidden = hidden; applyAnalyzing(hidden); }
    else alert('Not saved: ' + (this.status === 401 ? 'log in first' : this.responseText));
  };
  x.open('GET', 'overview.php?set_now_analyzing=' + (hidden ? 'hide' : 'show'), true);
  x.send();
}
document.addEventListener('DOMContentLoaded', function () { applyAnalyzing(analyzingDefaultHidden); });
function refreshAnalysisStatus() {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var st; try { st = JSON.parse(this.responseText); } catch (e) { return; }
    var el = document.getElementById('analysis_status');
    if (!el) return;
    var behind = st.behind >= 60 ? Math.round(st.behind / 60) + ' min' : st.behind + ' s';
    el.textContent = !st.running ? 'analysis stopped \u00b7 ' + st.waiting + ' waiting'
      : (st.waiting === 0 ? 'up to date' : st.waiting + ' waiting \u00b7 ' + behind + ' behind') + (st.shown ? ' \u00b7 showing ' + st.shown : '');
    el.classList.toggle('late', !st.running || st.waiting > 10);
  };
  x.open('GET', 'overview.php?analysis_status=1', true);
  x.send();
}
document.addEventListener('DOMContentLoaded', function () { refreshAnalysisStatus(); setInterval(refreshAnalysisStatus, 5000); });
// the Now cards page through the detections 50 at a time; only the newest page follows new detections
var nowOffset = 0;
var nowTerm = '';
// any value is accepted while typing; leaving a field rounds it to its precision and keeps it inside its limits
// (owner 2026-10-09: the browser refused 0.5 for Loc. Thresh., whose min 0.0005 + step 0.01 allowed only 0.0105, 0.0205...)
// a field whose value is not the model's default turns light yellow (owner 2026-10-10)
function naMarkDefaults() {
  var b = document.querySelector('.nadefault');
  if (!b) return;
  var d = JSON.parse(b.dataset.defaults);
  Object.keys(d).forEach(function (id) {
    var inp = document.getElementById(id);
    if (inp) inp.classList.toggle('nondefault', parseFloat(inp.value) !== parseFloat(d[id]));
  });
}
document.addEventListener('DOMContentLoaded', function () {
  naMarkDefaults();
  ['na_conf', 'na_sf', 'na_sens', 'na_len', 'na_over'].forEach(function (id) { var inp = document.getElementById(id); if (inp) inp.addEventListener('input', naMarkDefaults); });
});
function naDefaults() {
  var d = JSON.parse(document.querySelector('.nadefault').dataset.defaults);
  Object.keys(d).forEach(function (id) { var inp = document.getElementById(id); if (inp) inp.value = d[id]; });
  naMarkDefaults();
  document.getElementById('na_form').requestSubmit();
}
function naShow(open) {
  document.getElementById('na_form').style.display = open ? '' : 'none';
  document.getElementById('na_open').style.display = open ? 'none' : '';
  try { localStorage.setItem('now_params', open ? 'open' : 'closed'); } catch (e) {}
}
document.addEventListener('DOMContentLoaded', function () {
  var saved = null;
  try { saved = localStorage.getItem('now_params'); } catch (e) {}
  naShow(saved ? saved === 'open' : window.innerWidth > 800);
});
var NA_DECIMALS = {na_conf: 2, na_sf: 4, na_sens: 2, na_over: 1, na_len: 0};
function naRound(inp) {
  var v = parseFloat(String(inp.value).replace(',', '.'));
  if (isNaN(v)) return;
  v = Math.min(parseFloat(inp.max), Math.max(parseFloat(inp.min), v));
  inp.value = String(parseFloat(v.toFixed(NA_DECIMALS[inp.id])));
}
document.addEventListener('DOMContentLoaded', function () {
  Object.keys(NA_DECIMALS).forEach(function (id) {
    var inp = document.getElementById(id);
    if (inp) inp.addEventListener('change', function () { naRound(inp); naMarkDefaults(); });
  });
});
function saveAnalysis(e) {
  Object.keys(NA_DECIMALS).forEach(function (id) { var inp = document.getElementById(id); if (inp) naRound(inp); });
  e.preventDefault();
  // no message beside the button (owner 2026-10-09: it pushed the line); the button itself says Saved for a moment,
  // an error opens a dialog
  var b = document.getElementById('na_apply');
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') {
      b.textContent = '\u2713';
      b.title = 'Saved: applied from the next recording';
      setTimeout(function () { b.textContent = 'Apply'; }, 1500);
    } else alert('Not saved: ' + (this.status === 401 ? 'log in first' : this.responseText));
  };
  x.open('GET', 'overview.php?set_analysis=1&confidence=' + encodeURIComponent(document.getElementById('na_conf').value)
    + '&sensitivity=' + encodeURIComponent(document.getElementById('na_sens').value) + '&overlap=' + encodeURIComponent(document.getElementById('na_over').value)
    + '&sf_thresh=' + encodeURIComponent(document.getElementById('na_sf').value) + '&recording_length=' + encodeURIComponent(document.getElementById('na_len').value), true);
  x.send();
}
// filters (owner 2026-10-09/10): Uncommon (species detected at most N times since yesterday 00:00), Low Conf (confidence
// below P %), Low Prob (location probability below P %) — each button switches its filter on/off, several apply together,
// each active one keeps its picklist open beside its button; All switches them all off. Remembered in this browser.
var nowFilters = {};
function nowStore(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) {} return null; }
function nowDefaultValue(kind) {
  if (kind === 'uncommon') return String(NOW_UNCOMMON_STEPS.filter(function (x) { return x <= 10; })[0] || NOW_UNCOMMON_STEPS[NOW_UNCOMMON_STEPS.length - 1]);
  return '50';
}
function nowFilterOptions(kind) {
  return kind === 'uncommon'
    ? NOW_UNCOMMON_STEPS.map(function (v) { return [v, '\u2264 ' + v + ' since yesterday']; })
    : Array.apply(null, Array(19)).map(function (_, i) { var v = (i + 1) * 5; return [v, '< ' + v + '%']; });
}
function nowFilterButtons() {
  var any = Object.keys(nowFilters).length > 0;
  document.querySelectorAll('.nowfilters button[data-filter]').forEach(function (b) {
    var k = b.dataset.filter;
    b.classList.toggle('active', k === '' ? !any : nowFilters[k] !== undefined);
    if (k === '') return;
    var sel = document.getElementById('nowbelow_' + k);
    if (nowFilters[k] === undefined) { if (sel) sel.remove(); return; }
    if (!sel) {
      sel = document.createElement('select');
      sel.id = 'nowbelow_' + k;
      sel.className = 'nowbelow';
      sel.innerHTML = nowFilterOptions(k).map(function (o) { return '<option value="' + o[0] + '">' + o[1] + '</option>'; }).join('');
      // the chosen value is kept as this filter's default: switched off and on again, it comes back with it
      sel.onchange = function () { nowFilters[k] = sel.value; nowStore('now_below_' + k, sel.value); nowSaveFilters(); nowOffset = 0; loadFiveMostRecentDetections(); };
      b.after(sel);
    }
    if (![].some.call(sel.options, function (o) { return o.value === String(nowFilters[k]); })) nowFilters[k] = nowDefaultValue(k);
    sel.value = nowFilters[k];
  });
}
function nowSaveFilters() { nowStore('now_filters', JSON.stringify(nowFilters)); }
function nowFilter(kind) {
  if (kind === '') nowFilters = {};
  else if (nowFilters[kind] !== undefined) delete nowFilters[kind];
  else nowFilters[kind] = nowStore('now_below_' + kind) || nowDefaultValue(kind);
  nowFilterButtons();
  Object.keys(nowFilters).forEach(function (k) { nowStore('now_below_' + k, nowFilters[k]); });
  nowSaveFilters();
  nowOffset = 0;
  loadFiveMostRecentDetections();
}
document.addEventListener('DOMContentLoaded', function () {
  try { nowFilters = JSON.parse(nowStore('now_filters') || '{}') || {}; } catch (e) { nowFilters = {}; }
  Object.keys(nowFilters).forEach(function (k) { if (['uncommon', 'lowconf', 'lowprob'].indexOf(k) < 0) delete nowFilters[k]; });
  nowFilterButtons();
  if (Object.keys(nowFilters).length) loadFiveMostRecentDetections();
});
function nowFilterQuery() { return Object.keys(nowFilters).map(function (k) { return '&' + k + '=' + encodeURIComponent(nowFilters[k]); }).join(''); }
function nowSearch(v) { nowTerm = v.trim(); nowOffset = 0; loadFiveMostRecentDetections(); }
// search as you type (owner 2026-10-09): 300 ms after the last key; an empty box shows every detection again
var nowSearchTimer = null;
function nowSearchTyped(v) {
  clearTimeout(nowSearchTimer);
  nowSearchTimer = setTimeout(function () { if (v.trim() !== nowTerm) nowSearch(v); }, v.trim() === '' ? 0 : 300);
}
function refreshTodayStats() {
  var x = new XMLHttpRequest();
  x.onload = function () { if (this.responseText.length > 0 && !this.responseText.includes('Database is busy')) document.getElementById('todaystats').innerHTML = this.responseText; };
  x.open('GET', 'todays_detections.php?today_stats=true', true);
  x.send();
}
document.addEventListener('DOMContentLoaded', refreshTodayStats);
// spectrogram cards or list (owner 2026-10-09): NOW_VIEW of Basic Settings is the default, the buttons switch it
var nowView = <?php echo json_encode(($config['NOW_VIEW'] ?? 'spectrogram') === 'list' ? 'list' : 'spectrogram'); ?>;
var nowDefault = nowView;
function nowModeButtons() {
  document.querySelectorAll('.nowmodes button[data-mode]').forEach(function (b) { b.classList.toggle('active', b.dataset.mode === nowView); });
  var d = document.getElementById('nowview_default');
  if (d) d.style.visibility = (nowView === nowDefault) ? 'hidden' : 'visible';
}
function nowMode(mode) { nowView = mode; nowModeButtons(); loadFiveMostRecentDetections(); }
function nowSetDefault() {
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { nowDefault = nowView; nowModeButtons(); }
    else alert('Not saved: ' + (this.status === 401 ? 'log in first' : this.responseText));
  };
  x.open('GET', 'overview.php?set_now_view=' + nowView, true);
  x.send();
}
document.addEventListener('DOMContentLoaded', nowModeButtons);
function nowPage(offset) {
  nowOffset = offset;
  loadFiveMostRecentDetections();
  var h = document.querySelector('h3.now-cards');
  if (h) h.scrollIntoView({behavior: 'smooth'});
}
// only the answer to the latest request is shown (owner 2026-10-10): a filtered request is slower, and switching a filter
// off quickly let its late answer overwrite the unfiltered list, so the filter looked still on
var nowListRequest = 0;
function loadFiveMostRecentDetections() {
  const xhttp = new XMLHttpRequest();
  const mine = ++nowListRequest;
  xhttp.onload = function() {
    if (mine !== nowListRequest) return;
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementById("detections_table").innerHTML= this.responseText;
      if (window.stdTables) stdTables(document.getElementById("detections_table"));
      if (window.applyNameMode) applyNameMode();
    }
  }
  if (window.innerWidth > 500) {
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=50&gallery=1&mode=" + nowView + "&offset=" + nowOffset + (nowTerm ? "&searchterm=" + encodeURIComponent(nowTerm) : "") + nowFilterQuery(), true);
  } else {
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=50&gallery=1&mobile=true&mode=" + nowView + "&offset=" + nowOffset + (nowTerm ? "&searchterm=" + encodeURIComponent(nowTerm) : "") + nowFilterQuery(), true);
  }
  xhttp.send();
}
function refreshCustomImage(){
  // Find the customimage element
  var customimage = document.getElementById("customimage");

  function updateCustomImage() {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "overview.php?custom_image=true", true);
    xhr.onload = function() {
      customimage.innerHTML = xhr.responseText;
    }
    xhr.send();
  }
  updateCustomImage();
}
function startAutoRefresh() {
    i_fn1 = window.setInterval(function(){
                    // a hidden spectrogram is not downloaded again
                    if (document.getElementById("spectrogramimage").style.display === "none") return;
                    document.getElementById("spectrogramimage").src = "spectrogram.png?nocache="+Date.now();
                    }, <?php echo $refresh; ?>*1000);
    i_fn2 = window.setInterval(refreshDetection, <?php echo intval($dividedrefresh); ?>*1000);
    if (customImage) i_fn3 = window.setInterval(refreshCustomImage, 1000);
}
<?php if(isset($config["CUSTOM_IMAGE"]) && strlen($config["CUSTOM_IMAGE"]) > 2){?>
customImage = true;
<?php } else { ?>
customImage = false;
<?php } ?>
window.addEventListener("load", function(){
  loadDetectionIfNewExists();
});
document.addEventListener("visibilitychange", function() {
  console.log(document.visibilityState);
  console.log(document.hidden);
  if (document.hidden) {
    clearInterval(i_fn1);
    clearInterval(i_fn2);
    if (customImage) clearInterval(i_fn3);
  } else {
    loadDetectionIfNewExists();
    startAutoRefresh();
  }
});
startAutoRefresh();
</script>

<style>
  .tooltip {
  background-color: white;
  border: 1px solid #ccc;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
  padding: 10px;
  transition: opacity 0.2s ease-in-out;
}
</style>
<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/custom-audio-player.js"); ?>"></script>
<script src="static/spectro-dialog.js"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/detection-actions.js"></script>
<script src="static/generateMiniGraph.js"></script>
<script>
// Listen for the scroll event on the window object
window.addEventListener('scroll', function() {
  // Get all chart elements
  var charts = document.querySelectorAll('.chartdiv');
  
  // Loop through all chart elements and remove them
  charts.forEach(function(chart) {
    chart.parentNode.removeChild(chart);
    window.chartWindow = undefined;
  });
});

</script>
