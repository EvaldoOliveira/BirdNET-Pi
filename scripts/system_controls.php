<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);

session_start();
require_once "scripts/common.php";
$user = get_user();
$home = get_home();

$fetch = shell_exec("sudo -u".$user." git -C ".$home."/BirdNET-Pi fetch 2>&1");
$str = trim(shell_exec("sudo -u".$user." git -C ".$home."/BirdNET-Pi status"));
if (preg_match("/behind '.*?' by (\d+) commit(s?)\b/", $str, $matches)) {
  $num_commits_behind = $matches[1];
}
if (preg_match('/\b(\d+)\b and \b(\d+)\b different commits each/', $str, $matches)) {
    $num1 = (int) $matches[1];
    $num2 = (int) $matches[2];
    $num_commits_behind = $num1 + $num2;
}
if (stripos($str, "Your branch is up to date") !== false) {
  $num_commits_behind = '0';
}
$_SESSION['behind'] = $num_commits_behind;
$_SESSION['behind_time'] = time();

// version line and Update label (owner 2026-10-10): the release this station runs (the newest vX.Y.Z tag it contains,
// plus the commits after it on a development checkout) and the release Update would bring
$git = "sudo -u" . $user . " git -C " . $home . "/BirdNET-Pi ";
$installed = trim((string)shell_exec($git . "describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null"));
$ahead = $installed !== '' ? intval(trim((string)shell_exec($git . "rev-list --count " . escapeshellarg($installed) . "..HEAD 2>/dev/null"))) : 0;
$latest = '';
foreach (explode("\n", (string)shell_exec($git . "tag -l 'v*' 2>/dev/null")) as $t) {
  if (preg_match('~^v\d+\.\d+\.\d+$~', trim($t)) && ($latest === '' || version_compare(substr(trim($t), 1), substr($latest, 1), '>'))) $latest = trim($t);
}
$update_branch = get_config()['UPDATE_BRANCH'] ?? 'stable';
$_SESSION['release_installed'] = $installed;
$_SESSION['release_new'] = ($latest !== '' && $installed !== '' && version_compare(substr($latest, 1), substr($installed, 1), '>')) ? $latest : '';
if ($update_branch === 'stable') {
  $update_label = $_SESSION['release_new'] !== '' ? 'Update to ' . $_SESSION['release_new'] : 'Update';
} else {
  $update_label = 'Update to the latest ' . $update_branch;
}

$restore = "cat $home/BirdSongs/restore.log";
$max_upload_size = floor(disk_free_space("$home/BirdNET-Pi/") / 1.001);

?><html>
<meta name="viewport" content="width=device-width, initial-scale=1">
<br>
<br>
<script>
var seconds = 0;
function update() {
  if(confirm('Are you sure you want to update?')) {
    setInterval(function(){ seconds += 1; document.getElementById('updatebtn').innerHTML = "Updating: <pre id='timer' class='bash'>"+new Date(seconds * 1000).toISOString().substring(14, 19)+"</pre>"; }, 1000);
    return true;
  } else {
    return false;
  }
}
</script>
<div class="systemcontrols">
<p class="sysversion" style="text-align:center;font-size:15px;margin:0 0 10px">BirdnetPi++ <b><?php echo htmlspecialchars($installed !== '' ? $installed : 'unknown'); ?></b><?php
  if ($ahead > 0) echo ' + ' . $ahead . ' commit' . ($ahead > 1 ? 's' : '') . ' (' . htmlspecialchars($update_branch) . ')'; ?></p>
<form action="views.php" method="GET">
  <div>
    <button type="submit" name="submit" value="sudo reboot" onclick="return confirm('Are you sure you want to reboot?')">Reboot</button>
  </div>
  <div>
    <?php if (($_SESSION['release_new'] ?? '') !== '') { ?>
    <p><b>New release <?php echo htmlspecialchars($_SESSION['release_new']); ?></b> is available (installed: <?php echo htmlspecialchars($_SESSION['release_installed'] ?: 'unknown'); ?>).
    <a href="https://github.com/EvaldoOliveira/BirdnetPiPlusPlus/releases/tag/<?php echo rawurlencode($_SESSION['release_new']); ?>" target="_blank">What's new</a> —
    Update installs the <?php echo htmlspecialchars(get_config()['UPDATE_BRANCH'] ?? 'stable'); ?> branch.</p>
    <?php } ?>
    <button type="submit" name="submit" id="updatebtn" value="update_birdnet.sh" onclick="return update();"><?php echo htmlspecialchars($update_label); ?> <?php if(isset($_SESSION['behind']) && $_SESSION['behind'] != "0" && $_SESSION['behind'] != "with"){?><div class="updatenumber"><?php echo $_SESSION['behind']; ?></div><?php } ?></button>
  </div>
  <div>
    <button type="submit" name="submit" value="sudo shutdown now" onclick="return confirm('Are you sure you want to shutdown?')">Shutdown</button>
  </div>
  <div>
    <button type="submit" name="submit" value="sudo clear_all_data.sh" onclick="return confirm('Clear ALL Data? Note that this cannot be undone and will take up to 90 seconds.')">Clear ALL data</button>
  </div>
</form>
<div id="container">
  <button id="pickfile" type="button" href="javascript:;">Restore data</button>
</div>
<div><a href="scripts/backup.php" download ><button onclick="return confirm('Download backup? Note that this could take a long time.')">Backup data</button></a></div>
<?php
  $cmd="cd ".$home."/BirdNET-Pi && sudo -u ".$user." git rev-list --max-count=1 HEAD";
  $curr_hash = shell_exec($cmd);
  // link the running commit on the repository this station really updates from (the clone's origin)
  $repo_url = trim((string) shell_exec("cd ".$home."/BirdNET-Pi && sudo -u ".$user." git remote get-url origin 2>/dev/null"));
  $repo_url = preg_replace('/\.git$/', '', $repo_url);
  if (!preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo_url)) {
    $repo_url = "https://github.com/EvaldoOliveira/BirdnetPiPlusPlus";
  }
?>
  <p style="font-size:11px;text-align:center"></br></br>Running version: </p>
  <a href="<?php echo $repo_url; ?>/commit/<?php echo trim($curr_hash); ?>" target="_blank">
    <p style="font-size:11px;text-align:center;box-sizing: border-box"><?php echo $curr_hash; ?></p>
  </a>
  <pre id="console" style="text-align:center"></pre>
</div>
<script type="text/javascript">
// based on Custom example logic

var uploader = new plupload.Uploader({
    runtimes : 'html5',
    browse_button : 'pickfile', // you can pass an id...
    container: document.getElementById('container'), // ... or DOM Element itself
    url : 'scripts/restore.php',
    chunk_size: '2mb',
    multi_selection: false,

    filters : {
        max_file_size : '<?php echo "$max_upload_size"; ?>',
        mime_types: [
            {title : "Tar files", extensions : "tar"}
        ]
    },

    init: {
        FilesAdded: function(up, files) {
            uploader.start();
        },

        UploadProgress: function(up, file) {
            if (file.percent !== 100) {
                document.getElementById('pickfile').innerHTML = "<span>Uploading: <pre id='timer' class='bash'>" + String(file.percent).padStart(2, '0') + "%</pre></span>";
            } else {
                setInterval(function(){ seconds += 1; document.getElementById('pickfile').innerHTML = "Restoring: <pre id='timer' class='bash'>"+new Date(seconds * 1000).toISOString().substring(14, 19)+"</pre>"; }, 1000);
            }
        },

        FileUploaded: function(up, file, info) {
            // Called when file has finished uploading
            console.log('[FileUploaded] File:', file, "Info:", info);
            const xhttp = new XMLHttpRequest();
            xhttp.onload = function() {
                if(this.responseText.length > 0) {
                    document.body.innerHTML=this.responseText;
                }
            };
            xhttp.open("GET", "views.php?submit=<?php echo "$restore"; ?>");
            xhttp.send();
        },

        Error: function(up, err) {
            document.getElementById('console').appendChild(document.createTextNode("\nError #" + err.code + ": " + err.message));
        }
    }
});

uploader.init();
</script>
