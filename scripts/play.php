<?php

/* Prevent XSS input */
$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);

error_reporting(E_ERROR);
ini_set('display_errors',1);
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();
$user = get_user();

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

if(isset($_GET['deletefile'])) {
  ensure_authenticated('You must be authenticated to delete files.');
  if (preg_match('~^.*(\.\.\/).+$~', $_GET['deletefile'])) {
    echo "Error";
    die();
  }
  $db_writable = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READWRITE);
  $db->busyTimeout(1000);
  $statement1 = $db_writable->prepare('DELETE FROM detections WHERE File_Name = :file_name LIMIT 1');
  ensure_db_ok($statement1);
  $statement1->bindValue(':file_name', explode("/", $_GET['deletefile'])[2]);
  $file_pointer = $home."/BirdSongs/Extracted/By_Date/".$_GET['deletefile'];
  if (!exec("sudo rm $file_pointer 2>&1 && sudo rm $file_pointer.png 2>&1", $output)) {
    echo "OK";
  } else {
    echo "Error - file deletion failed : " . implode(", ", $output) . "<br>";
  }
  $result1 = $statement1->execute();
  if ($result1 === false || $db_writable->changes() === 0) {
    echo "Error - database line deletion failed : " . $db_writable->lastErrorMsg();
  }
  $db_writable->close();
  die();
}

if(isset($_GET['excludefile'])) {
  ensure_authenticated('You must be authenticated to change the protection of files.');
  if(!file_exists($home."/BirdNET-Pi/scripts/disk_check_exclude.txt")) {
    file_put_contents($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", "##start\n##end\n");
  }
  // shared with purge_protection.py and the purge scripts: never edit the list while they use it
  $purge_lock = fopen('/tmp/birdnet_purge.lock', 'a');
  if ($purge_lock) flock($purge_lock, LOCK_EX);
  if(isset($_GET['exclude_add'])) {
    $myfile = fopen($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", "a") or die("Unable to open file!");
    $txt = $_GET['excludefile'];
    fwrite($myfile, $txt."\n");
    fwrite($myfile, $txt.".png\n");
    fclose($myfile);
    echo "OK";
    die();
  } else {
    $lines  = file($home."/BirdNET-Pi/scripts/disk_check_exclude.txt");
    $search = $_GET['excludefile'];

    $result = '';
    foreach($lines as $line) {
      if(stripos($line, $search) === false && stripos($line, $search.".png") === false) {
        $result .= $line;
      }
    }
    file_put_contents($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", $result);
    echo "OK";
    die();
  }
}

// Review loop: one verdict per detection — yes (confirmed: protected from purge), no (not this bird: left out of
// the best detections and the species page counts), unsure; "clear" removes it
if(isset($_GET['review']) && isset($_GET['verdict'])) {
  ensure_authenticated('You must be authenticated to review detections.');
  $file_name = basename($_GET['review']);
  $verdict = $_GET['verdict'];
  if (!in_array($verdict, array('yes', 'no', 'unsure', 'clear'), true)) { echo "Error"; die(); }
  $rw = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READWRITE);
  $rw->busyTimeout(5000);
  $rw->exec("CREATE TABLE IF NOT EXISTS detection_reviews (File_Name VARCHAR(100) PRIMARY KEY, Sci_Name VARCHAR(100), Com_Name VARCHAR(100), Date DATE, Confidence FLOAT, Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')), Reviewed_At TEXT)");
  if ($verdict === 'clear') {
    $st = $rw->prepare('DELETE FROM detection_reviews WHERE File_Name = :f');
    $st->bindValue(':f', $file_name);
  } else {
    $st = $rw->prepare("INSERT OR REPLACE INTO detection_reviews (File_Name, Sci_Name, Com_Name, Date, Confidence, Verdict, Reviewed_At)
      SELECT File_Name, Sci_Name, Com_Name, Date, Confidence, :v, datetime('now', 'localtime') FROM detections WHERE File_Name = :f LIMIT 1");
    $st->bindValue(':f', $file_name);
    $st->bindValue(':v', $verdict);
  }
  $ok = $st->execute() !== false && ($verdict === 'clear' || $rw->changes() > 0);
  $rw->close();
  echo $ok ? "OK" : "Error - detection not found";
  die();
}

if(isset($_GET['getlabels'])) {
    $labels = file('./scripts/labels.txt', FILE_IGNORE_NEW_LINES);
    echo json_encode($labels);
    die();
}

if(isset($_GET['changefile']) && isset($_GET['newname'])) {
  ensure_authenticated('You must be authenticated to delete files.');
  if (preg_match('~^.*(\.\.\/).+$~', $_GET['changefile'])) {
    echo "Error";
    die();
  }
  $oldname = basename(urldecode($_GET['changefile']));
  $newname = urldecode($_GET['newname']);
  if (!exec("sudo -u ".$user." ".$home."/BirdNET-Pi/scripts/birdnet_changeidentification.sh \"$oldname\" \"$newname\" log_errors 2>&1", $output)) {
    echo "OK";
  } else {
    echo "Error : " . implode(", ", $output) . "<br>";
  }
  die();
}

$shifted_path = $home."/BirdSongs/Extracted/By_Date/shifted/";

if(isset($_GET['shiftfile'])) {
  ensure_authenticated('You cannot shift files for this installation');

    $filename = $_GET['shiftfile'];
    $pp = pathinfo($filename);
    $dir = $pp['dirname'];
    $fn  = $pp['filename'];
    $ext = $pp['extension'];
    $pi = $home."/BirdSongs/Extracted/By_Date/";

    if(isset($_GET['doshift'])) {
  $freqshift_tool = $config['FREQSHIFT_TOOL'];

  if ($freqshift_tool == "ffmpeg") {
    $cmd = "sudo /usr/bin/nohup /usr/bin/ffmpeg -y -i ".escapeshellarg($pi.$filename)." -af \"rubberband=pitch=".$config['FREQSHIFT_LO']."/".$config['FREQSHIFT_HI']."\" ".escapeshellarg($shifted_path.$filename)."";
    shell_exec("sudo mkdir -p ".$shifted_path.$dir." && ".$cmd);

  } else if ($freqshift_tool == "sox") {
    //linux.die.net/man/1/sox
    $soxopt = "-q";
    $soxpitch = $config['FREQSHIFT_PITCH'];
    $cmd = "sudo /usr/bin/nohup /usr/bin/sox ".escapeshellarg($pi.$filename)." ".escapeshellarg($shifted_path.$filename)." pitch ".$soxopt." ".$soxpitch;
   shell_exec("sudo mkdir -p ".$shifted_path.$dir." && ".$cmd);
  }
    } else {
     $cmd = "sudo rm -f " . escapeshellarg($shifted_path.$filename);
     shell_exec($cmd);
    }

    echo "OK";
    die();
}

if(isset($_GET['bydate'])){
  $statement = $db->prepare('SELECT DISTINCT(Date) FROM detections GROUP BY Date ORDER BY Date DESC');
  ensure_db_ok($statement);
  $result = $statement->execute();
  $view = "bydate";

  #Specific Date
} elseif(isset($_GET['date'])) {
  $date = $_GET['date'];
  session_start();
  $_SESSION['date'] = $date;
  $result = fetch_species_array($_GET['sort'], $date);
  $view = "date";

  #By Species
} elseif(isset($_GET['byspecies'])) {
  $result = fetch_species_array($_GET['sort']);
  $view = "byspecies";

  #Specific Species
} elseif(isset($_GET['species'])) {
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  session_start();
  $_SESSION['species'] = $species;
  $result2 = fetch_all_detections($species, $_GET['sort'], $_SESSION['date']);
  $view = "species";
} else {
  // no choice made: open by species (owner 2026-10-08); a single button switches to by date
  unset($_SESSION['species']);
  unset($_SESSION['date']);
  $result = fetch_species_array($_GET['sort']);
  $view = "byspecies";
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
  </head>';
}

?>
<script src="static/custom-audio-player.js"></script>
<script src="static/detection-actions.js"></script>

<?php
#If no specific species
if(!isset($_GET['species']) && !isset($_GET['filename'])){
?>
<div class="play">
<?php if(in_array($view, array("byspecies", "bydate", "date"), true)) {
  // top left: only the button to the other way of browsing
  // two buttons, By Species / By Date: the current way of browsing highlighted, the other greyed
  $by_date = $view == "bydate"; ?>
<form action="views.php" method="GET" style="float:left;margin:6px 8px;">
  <input type="hidden" name="view" value="Recordings">
  <button type="submit" name="byspecies" value="byspecies" class="sortbutton modebutton<?php echo $by_date ? ' inactive' : ' active'; ?>">By Species</button>
  <button type="submit" name="bydate" value="bydate" class="sortbutton modebutton<?php echo $by_date ? ' active' : ' inactive'; ?>">By Date</button>
</form>
<?php } ?>
<?php if($view == "byspecies" || $view == "date") { ?>
<div class="sortbar" style="width: auto; text-align: right; margin: 0 8px;">
   <form action="views.php" method="GET">
      <input type="hidden" name="view" value="Recordings">
      <input type="hidden" name="<?php echo $view; ?>" value="<?php echo $_GET['date']; ?>">
      <span class="sortlabel">Sort by:</span>
      <button <?php if(!isset($_GET['sort']) || $_GET['sort'] == "alphabetical"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="alphabetical">
         <span title="Sort by alphabetical">Name</span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "occurrences"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="occurrences">
         <span title="Sort by occurrences">Occur.</span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "confidence"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="confidence">
         <span title="Sort by confidence">Conf.</span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "date"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="date">
         <span title="Sort by date">Date</span>
      </button>
   </form>
</div>
<?php } ?>
<div style="clear:both"></div>
<form action="views.php" method="GET">
<input type="hidden" name="view" value="Recordings">
<table>
<?php
  #By Date
  if($view == "bydate") {
    while($results=$result->fetchArray(SQLITE3_ASSOC)){
      $date = $results['Date'];
      if(realpath($home."/BirdSongs/Extracted/By_Date/".$date) !== false){
        echo "<td>
          <button action=\"submit\" name=\"date\" value=\"$date\">".($date == date('Y-m-d') ? "Today" : $date)."</button></td></tr>";}}

          #By Species
  } elseif($view == "byspecies") {
    $birds = array();
    $values = array();
    while($results=$result->fetchArray(SQLITE3_ASSOC))
    {
      $birds[] = $results['Sci_Name'];
      $values[] = get_label($results, $_GET['sort']);
    }

    if(count($birds) > 45) {
      $num_cols = 3;
    } else {
      $num_cols = 1;
    }
    $num_rows = ceil(count($birds) / $num_cols);

    for ($row = 0; $row < $num_rows; $row++) {
      echo "<tr>";

      for ($col = 0; $col < $num_cols; $col++) {
        $index = $row + $col * $num_rows;

        if ($index < count($birds)) {
          ?>
          <td class="spec">
              <button type="submit" name="species" value="<?php echo $birds[$index];?>"><?php echo $values[$index];?></button>
          </td>
          <?php
        } else {
          echo "<td></td>";
        }
      }

      echo "</tr>";
    }
  } elseif($view == "date") {
    $birds = array();
    $values = array();
while($results=$result->fetchArray(SQLITE3_ASSOC))
{
  $dir_name = str_replace("'", '', $results['Com_Name']);
  if(realpath($home."/BirdSongs/Extracted/By_Date/".$date."/".str_replace(" ", "_", $dir_name)) !== false){
    $birds[] = $results['Sci_Name'];
    $values[] = get_label($results, $_GET['sort'], $_GET['date']);
  }
}

if(count($birds) > 45) {
  $num_cols = 3;
} else {
  $num_cols = 1;
}
$num_rows = ceil(count($birds) / $num_cols);

for ($row = 0; $row < $num_rows; $row++) {
  echo "<tr>";

  for ($col = 0; $col < $num_cols; $col++) {
    $index = $row + $col * $num_rows;

    if ($index < count($birds)) {
      ?>
      <td class="spec">
          <button type="submit" name="species" value="<?php echo $birds[$index];?>"><?php echo $values[$index];?></button>
      </td>
      <?php
    } else {
      echo "<td></td>";
    }
  }

  echo "</tr>";
}

    #Choose
  } else {
    echo "<td>
      <button action=\"submit\" name=\"byspecies\" value=\"byspecies\">By Species</button></td></tr>
      <tr><td><button action=\"submit\" name=\"bydate\" value=\"bydate\">By Date</button></td>";
  } 

  echo "</table></form>";
}

#Specific Species
if(isset($_GET['species'])){ ?>
<div style="width: auto;
   text-align: center">
   <form action="views.php" method="GET">
      <input type="hidden" name="view" value="Recordings">
      <input type="hidden" name="species" value="<?php echo $_GET['species']; ?>">
      <input type="hidden" name="sort" value="<?php echo $_GET['sort']; ?>">
      <span class="sortlabel">Sort by:</span>
      <button <?php if(!isset($_GET['sort']) || $_GET['sort'] == "" || $_GET['sort'] == "date"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="date">
         <span title="Sort by date">Date</span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "confidence"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="confidence">
         <span title="Sort by confidence">Conf.</span>
      </button><br>
      <label style="cursor: pointer; margin-top: 10px; margin-bottom: 10px;font-weight: normal; display: inline-flex; align-items: center; justify-content: center;">
        <input type="checkbox" name="only_excluded" <?= isset($_GET['only_excluded']) ? 'checked' : '' ?> onchange="submit()" style="display:none;">
        <span style="width: 40px; height: 20px; background: <?= isset($_GET['only_excluded']) ? '#555555' : 'rgba(85, 85, 85, 0.3)' ?>; border: 1px solid #777777; border-radius: 20px; display: inline-block; position: relative; margin-right: 8px; transition: background 0.4s, border 0.4s; box-sizing: border-box;">
        <span style="width: 16px; height: 16px; background: white; border-radius: 50%; position: absolute; top: 1.5px; left: 2px; transition: 0.4s; display: flex; align-items: center; justify-content: center; font-size: 14px; color: black; <?= isset($_GET['only_excluded']) ? 'transform: translateX(20px);' : '' ?>">
        <?= isset($_GET['only_excluded']) ? '✓' : '' ?>
      </span></span>Only Show Purge Excluded</label>
   </form>
</div>
<?php
  // add disk_check_exclude.txt lines into an array for grepping
  $fp = @fopen($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", 'r'); 
if ($fp) {
  $disk_check_exclude_arr = explode("\n", fread($fp, filesize($home."/BirdNET-Pi/scripts/disk_check_exclude.txt")));
} else {
  $disk_check_exclude_arr = [];
}

$name = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;

$result2 = fetch_all_detections($name, $_GET['sort'], $_SESSION['date']);
$results=$result2->fetchArray(SQLITE3_ASSOC);
$com_name = $results['Com_Name'];
$result2->reset(); // reset the pointer to the beginning of the result set
$sciname = $name;
$info_url = get_info_url($sciname);
$url = $info_url['URL'];
echo "<table>
  <tr><th>" . species_icon($sciname, 22) . " $com_name<br><span style=\"font-weight:normal;\">
  <i>$sciname</i></span><br>
    " . species_links($sciname, '', 20) . "
  </th></tr>";
  $iter=0;
  while($results=$result2->fetchArray(SQLITE3_ASSOC))
  {
    $comname = preg_replace('/ /', '_', $results['Com_Name']);
    $comname = preg_replace('/\'/', '', $comname);
    $date = $results['Date'];
    $filename = "/By_Date/".$date."/".$comname."/".$results['File_Name'];
    $filename_shifted = "/By_Date/shifted/".$date."/".$comname."/".$results['File_Name'];
    $filename_png = $filename . ".png";
    $sciname = preg_replace('/ /', '_', $results['Sci_Name']);
    $sci_name = $results['Sci_Name'];
    $time = $results['Time'];
    $values = round((float)round($results['Confidence'],2) * 100 ) . '%';
    $filename_formatted = $date."/".$comname."/".$results['File_Name'];

    // file was deleted by disk check, no need to show the detection in recordings
    if(!file_exists($home."/BirdSongs/Extracted/".$filename)) {
      continue;
    }
    if(!in_array($filename_formatted, $disk_check_exclude_arr) && isset($_GET['only_excluded'])) {
      continue;
    }
    $iter++;
    if($iter > $limit) {
      $iter_additional=true;
      break;
    }

    if($iter < 100){
      $imageelem = "<div class='custom-audio-player' data-audio-src=\"$filename\" data-image-src=\"$filename_png\"></div>";
    } else {
      $imageelem = "<a href=\"$filename\"><img src=\"$filename_png\"></a>";
    }

      if(!in_array($filename_formatted, $disk_check_exclude_arr)) {
        $imageicon = "images/unlock.svg";
        $title = "This file will be deleted when disk space needs to be freed (>95% usage).";
        $type = "add";
      } else {
        $imageicon = "images/lock.svg";
        $title = "This file is excluded from being purged.";
        $type = "del";
      }

      if(file_exists($shifted_path.$filename_formatted)) {
        $shiftImageIcon = "images/unshift.svg";
        $shiftTitle = "This file has been shifted down in frequency."; 
        $shiftAction = "unshift";
  $filename = $filename_shifted;
      } else {
        $shiftImageIcon = "images/shift.svg";
        $shiftTitle = "This file is not shifted in frequency.";
        $shiftAction = "shift";
      }

      echo "<tr>
  <td class=\"relative\"> 

<img style='cursor:pointer;right:120px' src='images/delete.svg' onclick='deleteDetection(\"".$filename_formatted."\")' class=\"copyimage\" width=25 title='Delete Detection'> 
<img style='cursor:pointer;right:85px' src='images/bird.svg' onclick='changeDetection(\"".$filename_formatted."\")' class=\"copyimage\" width=25 title='Change Detection'> 
<img style='cursor:pointer;right:45px' onclick='toggleLock(\"".$filename_formatted."\",\"".$type."\", this)' class=\"copyimage\" width=25 title=\"".$title."\" src=\"".$imageicon."\"> 
<img style='cursor:pointer' onclick='toggleShiftFreq(\"".$filename_formatted."\",\"".$shiftAction."\", this)' class=\"copyimage\" width=25 title=\"".$shiftTitle."\" src=\"".$shiftImageIcon."\"> $date $time<br>$values<br>

        ".$imageelem."
        </td>
        </tr>";

  }if($iter == 0){ echo "<tr><td><b>No detections were found.</b><br><br><span style='font-size:medium'>They may have been deleted to make space for new detections. You can prevent this from happening in the future by clicking the <img src='images/unlock.svg' style='width:20px'> icon in the top right of a detection.<br>You can also modify this behavior globally under \"Full Disk Behavior\" <a href='views.php?view=Advanced'>here.</a></span></td></tr>";}echo "</table>";}

  if ($iter_additional) {
    echo "<div style='text-align:center'>";
    echo "<form action='views.php' method='GET' style='display:inline'>";
    echo "<input type='hidden' name='view' value='Recordings'>";
    echo "<input type='hidden' name='species' value=\"" . htmlspecialchars($_GET['species'], ENT_QUOTES) . "\">";
    if(isset($_GET['sort'])) {
      echo "<input type='hidden' name='sort' value=\"" . htmlspecialchars($_GET['sort'], ENT_QUOTES) . "\">";
    }
    if(isset($_GET['only_excluded'])) {
      echo "<input type='hidden' name='only_excluded' value='" . $_GET['only_excluded'] . "'>";
    }
    if(isset($_SESSION['date'])) {
      echo "<input type='hidden' name='date' value='" . $_SESSION['date'] . "'>";
    }
    echo "<input type='hidden' name='limit' value='" . ($limit + 40) . "'>";
    echo "<button type='submit' class='loadmore'>Load 40 more...</button>";
    echo "</form>";
    echo "</div>";
  }

  if(isset($_GET['filename'])){
    $name = $_GET['filename'];
    $statement2 = $db->prepare("SELECT * FROM detections where File_name == \"$name\" ORDER BY Date DESC, Time DESC");
    ensure_db_ok($statement2);
    $result2 = $statement2->execute();
    $results = $result2->fetchArray(SQLITE3_ASSOC);
    $sciname = $results['Sci_Name'];
    $result2->reset();
    $info_url = get_info_url($sciname);
    $url = $info_url['URL'];
    echo "<table>
      <tr><th>" . species_icon($sciname, 22) . " $name<br>
      <i>$sciname</i><br>
          " . species_links($sciname, '', 20) . "
      </th></tr>";
      while($results=$result2->fetchArray(SQLITE3_ASSOC))
      {
        $comname = preg_replace('/ /', '_', $results['Com_Name']);
        $comname = preg_replace('/\'/', '', $comname);
        $date = $results['Date'];
        $filename = "/By_Date/".$date."/".$comname."/".$results['File_Name'];
        $filename_shifted = "/By_Date/shifted/".$date."/".$comname."/".$results['File_Name'];
        $filename_png = $filename . ".png";
        $sciname = preg_replace('/ /', '_', $results['Sci_Name']);
        $sci_name = $results['Sci_Name'];
        $time = $results['Time'];
        $values = round((float)round($results['Confidence'],2) * 100 ) . '%';
        $filename_formatted = $date."/".$comname."/".$results['File_Name'];

        // add disk_check_exclude.txt lines into an array for grepping
        $fp = @fopen($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", 'r');
        if ($fp) {
          $disk_check_exclude_arr = explode("\n", fread($fp, filesize($home."/BirdNET-Pi/scripts/disk_check_exclude.txt")));
        } else {
          $disk_check_exclude_arr = [];
        }

          if(!in_array($filename_formatted, $disk_check_exclude_arr)) {
            $imageicon = "images/unlock.svg";
            $title = "This file will be deleted when disk space needs to be freed (>95% usage).";
            $type = "add";
          } else {
            $imageicon = "images/lock.svg";
            $title = "This file is excluded from being purged.";
            $type = "del";
          }

      if(file_exists($shifted_path.$filename_formatted)) {
        $shiftImageIcon = "images/unshift.svg";
        $shiftTitle = "This file has been shifted down in frequency."; 
        $shiftAction = "unshift";
  $filename = $filename_shifted;
      } else {
        $shiftImageIcon = "images/shift.svg";
        $shiftTitle = "This file is not shifted in frequency.";
        $shiftAction = "shift";
      }

          echo "<tr>
      <td class=\"relative\"> 

<img style='cursor:pointer;right:120px' src='images/delete.svg' onclick='deleteDetection(\"".$filename_formatted."\", true)' class=\"copyimage\" width=25 title='Delete Detection'> 
<img style='cursor:pointer;right:85px' src='images/bird.svg' onclick='changeDetection(\"".$filename_formatted."\")' class=\"copyimage\" width=25 title='Change Detection'> 
<img style='cursor:pointer;right:45px' onclick='toggleLock(\"".$filename_formatted."\",\"".$type."\", this)' class=\"copyimage\" width=25 title=\"".$title."\" src=\"".$imageicon."\"> 
<img style='cursor:pointer' onclick='toggleShiftFreq(\"".$filename_formatted."\",\"".$shiftAction."\", this)' class=\"copyimage\" width=25 title=\"".$shiftTitle."\" src=\"".$shiftImageIcon."\">$date $time<br>$values<br>

<div class='custom-audio-player' data-audio-src='$filename' data-image-src='$filename_png'></div>
</td></tr>";

      }echo "</table>";}
      echo "</div>";
if (get_included_files()[0] === __FILE__) {
  echo '</html>';
}
