<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);

require_once "scripts/common.php";
$home = get_home();
$config = get_config();

if(!empty($config['FREQSHIFT_RECONNECT_DELAY']) && is_numeric($config['FREQSHIFT_RECONNECT_DELAY'])){
    $FREQSHIFT_RECONNECT_DELAY = ($config['FREQSHIFT_RECONNECT_DELAY']);
}else{
    $FREQSHIFT_RECONNECT_DELAY = 4000;
}

if(!empty($config['SPECTROGRAM_HEIGHT']) && is_numeric($config['SPECTROGRAM_HEIGHT'])){
    $SPECTROGRAM_HEIGHT = ($config['SPECTROGRAM_HEIGHT']);
}else{
    $SPECTROGRAM_HEIGHT = 80;
}

// US-41: colour palette of the live waterfall (and of the SoX images, see
// spectrogram.sh / reporting.py). Names must match $SPECTROGRAM_PALETTES.
$SPECTROGRAM_PALETTES = array(
  'birdnet'   => 'BirdNET classic',
  'viridis'   => 'Viridis (dark → yellow)',
  'inferno'   => 'Inferno (black → red → yellow)',
  'ocean'     => 'Ocean (black → cyan)',
  'grayscale' => 'Grayscale',
  'soxheat'   => 'SoX heat',
);
$SPECTROGRAM_PALETTE = 'birdnet';
if(!empty($config['SPECTROGRAM_PALETTE']) && array_key_exists($config['SPECTROGRAM_PALETTE'], $SPECTROGRAM_PALETTES)){
    $SPECTROGRAM_PALETTE = $config['SPECTROGRAM_PALETTE'];
}
// US-42: colour sensitivity — dB floor, dB range and contrast (gamma) of the ramp
$SPECTROGRAM_FLOOR_DB = (isset($config['SPECTROGRAM_FLOOR_DB']) && is_numeric($config['SPECTROGRAM_FLOOR_DB'])) ? max(-120, min(-40, intval($config['SPECTROGRAM_FLOOR_DB']))) : -100;
$SPECTROGRAM_RANGE_DB = (isset($config['SPECTROGRAM_RANGE_DB']) && is_numeric($config['SPECTROGRAM_RANGE_DB'])) ? max(30, min(120, intval($config['SPECTROGRAM_RANGE_DB']))) : 70;
$SPECTROGRAM_CONTRAST = (isset($config['SPECTROGRAM_CONTRAST']) && is_numeric($config['SPECTROGRAM_CONTRAST'])) ? max(0.5, min(2.0, floatval($config['SPECTROGRAM_CONTRAST']))) : 1.0;

// US-41/US-42 save endpoint (owner 2026-09-17: "do not restart the server when
// changing colour or spectrogram — only the spectrogram engine"): writes ONLY
// the spectrogram keys to birdnet.conf and restarts spectrogram_viewer.service
// (the SoX image engine). Nothing else is touched — no restart_services.sh.
if(isset($_GET['save_spectrogram'])) {
  ensure_authenticated();
  $keys = array(
    'spectrogram_palette'  => array('SPECTROGRAM_PALETTE', 'enum', array_keys($SPECTROGRAM_PALETTES), 'colour palette of the spectrograms: birdnet, viridis, inferno, ocean, grayscale, soxheat'),
    'spectrogram_height'   => array('SPECTROGRAM_HEIGHT', 'int', array(20, 100), 'height of the live spectrogram in percent of the page height (vh)'),
    'spectrogram_floor_db' => array('SPECTROGRAM_FLOOR_DB', 'int', array(-120, -40), 'dB floor of the spectrogram colour scale'),
    'spectrogram_range_db' => array('SPECTROGRAM_RANGE_DB', 'int', array(30, 120), 'dB range of the spectrogram colour scale above the floor'),
    'spectrogram_contrast' => array('SPECTROGRAM_CONTRAST', 'float', array(0.5, 2.0), 'contrast (gamma) of the spectrogram colour ramp; 1 = linear'),
  );
  $contents = file_get_contents('/etc/birdnet/birdnet.conf');
  $changed = 0;
  foreach ($keys as $param => $spec) {
    if (!isset($_GET[$param])) continue;
    list($key, $type, $bounds, $desc) = $spec;
    $raw = trim($_GET[$param]);
    if ($type == 'enum') { if (!in_array($raw, $bounds, true)) continue; $val = $raw; }
    elseif (!is_numeric($raw)) { continue; }
    elseif ($type == 'int') { $val = max($bounds[0], min($bounds[1], intval($raw))); }
    else { $val = max($bounds[0], min($bounds[1], round(floatval($raw), 2))); }
    if (preg_match("/^$key=/m", $contents)) {
      $contents = preg_replace("/^$key=.*/m", "$key=$val", $contents);
    } else {
      $contents .= "\n## $key is the $desc\n$key=$val\n";
    }
    $changed++;
  }
  if ($changed > 0) {
    file_put_contents('/etc/birdnet/birdnet.conf', $contents);
    exec("sudo systemctl restart spectrogram_viewer.service");
  }
  header('Content-Type: text/plain');
  echo $changed > 0 ? "OK" : "NOCHANGE";
  die();
}

if(isset($_GET['ajax_csv'])) {
  $RECS_DIR = $config["RECS_DIR"];
  $STREAM_DATA_DIR = $RECS_DIR . "/StreamData/";

  if (empty($config['RTSP_STREAM'])) {
    $look_in_directory = $STREAM_DATA_DIR;
    $files = glob($look_in_directory . "*.wav.json");
    if (count($files) !== 0) {
      // glob() returns full paths (unlike the scandir branch below) and sorts
      // alphabetically, i.e. chronologically for these date-prefixed names:
      // take the newest and reduce it to a basename like the RTSP branch does,
      // since $look_in_directory is prepended again when the file is read.
      $newest_file = basename(end($files));
    }
  }
  else {
    $look_in_directory = $STREAM_DATA_DIR;

    //Load the file in the directory
    $files = scandir($look_in_directory, SCANDIR_SORT_ASCENDING);

    //Because there might be more than 1 stream, we can't really assume the file at index 2 is the latest, or even for the stream being listened to
    //Read the RTSP_STREAM_TO_LIVESTREAM setting, then try to find that CSV file
    if(!empty($config['RTSP_STREAM_TO_LIVESTREAM']) && is_numeric($config['RTSP_STREAM_TO_LIVESTREAM'])){
        //The stored setting of RTSP_STREAM_TO_LIVESTREAM is 0 based, but filenames are 1's based, so just add 1 to the config value
        //so we can match up the stream the user is listening to with the appropriate filename
        $RTSP_STREAM_LISTENED_TO = ($config['RTSP_STREAM_TO_LIVESTREAM'] + 1);
    }else{
        //Setting is invalid somehow
        //The stored setting of RTSP_STREAM_TO_LIVESTREAM is 0 based, but filenames are 1's based, so just add 1 to the config value
        //This will be the first stream
        $RTSP_STREAM_LISTENED_TO = 1;
    }

    //The RTSP streams contain 'RTSP_X' in the filename, were X is the stream url index in the comma separated list of RTSP streams
    //We can use this to locate the file for this stream
    foreach ($files as $file_idx => $stream_file_name) {
        //Skip the folder hierarchy entries
        if ($stream_file_name != "." && $stream_file_name != "..") {
            //See if the filename contains the correct RTSP name, also only check .wav.csv files
            if (stripos($stream_file_name, 'RTSP_' . $RTSP_STREAM_LISTENED_TO) !== false && stripos($stream_file_name, '.wav.json') !== false) {
                //Found a match - set it as the newest file
                $newest_file = $stream_file_name;
            }
        }
    }
}


//If the newest file param has been supplied and it's the same as the newest file found
//then stop processing
if($newest_file == $_GET['newest_file']) {
  die();
}

$contents = file_get_contents($look_in_directory . $newest_file);
if ($contents !== false) {
  $json = json_decode($contents);
  if ($json != null) {
    $datetime = DateTime::createFromFormat(DateTime::ISO8601, $json->{'timestamp'});
    $now = new DateTime();
    $interval = $now->diff($datetime);
    $json->delay = $interval->format('%s');
    echo json_encode($json);
  }
}

//Kill the script so no further processing or output is done
die();
}

//Hold the array of RTSP steams once they are exploded
$RTSP_Stream_Config = array();

//Load the birdnet config so we can read the RTSP setting
// Valid config data
if (is_array($config) && array_key_exists('RTSP_STREAM',$config)) {
	if (is_null($config['RTSP_STREAM']) === false && $config['RTSP_STREAM'] !== "") {
		$RTSP_Stream_Config_Data = explode(",", $config['RTSP_STREAM']);

		//Process the stream further
		//we need to able to ID it (just do this by position), get the hostname to show in the dropdown box
		foreach ($RTSP_Stream_Config_Data as $stream_idx => $stream_url) {
			//$stream_idx is the array position of the the RSP stream URL, idx of 0 is the first, 1 - second etc
			$RTSP_stream_url = parse_url($stream_url);
			$RTSP_Stream_Config[$stream_idx] = $RTSP_stream_url['host'];
		}
	}
}

?>
<script>  
// CREDITS: https://codepen.io/jakealbaugh/pen/jvQweW

// UPDATE: there is a problem in chrome with starting audio context
//  before a user gesture. This fixes it.
var started = null;
var player = null;
var gain = 128;
const ctx = null;
let fps =[];
let avgfps;
let requestTime;

<?php 
if(isset($_GET['legacy']) && $_GET['legacy'] == "true") {
  echo "var legacy = true;";
} else {
  echo "var legacy = false;";
}
?>

window.onload = function(){
  var playersrc =  document.getElementById('playersrc');
  playersrc.onerror = function() {
    window.location="views.php?view=Spectrogram&legacy=true";
  };

  // if user agent includes iPhone or Mac use legacy mode
  if(window.navigator.userAgent.includes("iPhone") || legacy == true) {
    document.getElementById("spectrogramimage").style.display="";
    document.body.querySelector('canvas').remove();
    document.getElementById('player').remove();
    document.body.querySelector('h1').remove();
    document.getElementsByClassName("centered")[0].remove()

    <?php 
  $refresh = $config['RECORDING_LENGTH'];
  $time = time();
  ?>
    // every $refresh seconds, this loop will run and refresh the spectrogram image
  window.setInterval(function(){
    document.getElementById("spectrogramimage").src = "spectrogram.png?nocache="+Date.now();
  }, <?php echo $refresh; ?>*1000);
  } else {
    document.getElementById("spectrogramimage").remove();

  var audioelement =  window.parent.document.getElementsByTagName("audio")[0];
  if (typeof(audioelement) != 'undefined') {

    document.getElementById('player').remove();

    player = audioelement;
    if (started) return;
    started = true;
    initialize();
  } else {
    player = document.getElementById('player');
    player.oncanplay = function() {
      if (started) return;
        started = true;
        initialize();
    };
  }
  player.play();
  
  }
};

function fitTextOnCanvas(text,fontface,yPosition){    
    var fontsize=300;
    do{
        fontsize--;
        CTX.font=fontsize+"px "+fontface;
    }while(CTX.measureText(text).width>document.body.querySelector('canvas').width)
    CTX.font = CTX.font=(fontsize*0.35)+"px "+fontface;
    CTX.fillText(text,document.body.querySelector('canvas').width - (document.body.querySelector('canvas').width * 0.50),yPosition);
}

function applyText(text,x,y,opacity) {
  console.log("conf: "+opacity)
  console.log(text+" "+parseInt(x)+" "+y)
  if(opacity < 0.2) {
    opacity = 0.2;
  }
  CTX.textAlign = "center";
    CTX.fillStyle = "rgba(255, 255, 255, "+opacity+")";
  CTX.font = '15px Roboto Flex';
  //fitTextOnCanvas(text,"Roboto Flex",document.body.querySelector('canvas').scrollHeight * 0.35)
  CTX.fillText(text,parseInt(x),y)
  CTX.fillStyle = paletteColor(0);
}

var add=0;
var newest_file;
function loadDetectionIfNewExists() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    // if there's a new detection that needs to be updated to the page
    if(this.responseText.length > 0 && !this.responseText.includes("Database")) {
      const resp = JSON.parse(this.responseText);
      newest_file = resp.file_name;
      console.log("delay " + resp.delay);
      for (detection of resp.detections) {
        console.log("detection.start  " + detection.start);
        secago = resp.delay - detection.start;
        x = document.body.querySelector('canvas').width - (secago * avgfps);
        y = (document.body.querySelector('canvas').height * 0.50) + add;
        if(x > document.body.querySelector('canvas').width - (5*avgfps) && detection.common_name.length > 8) {
          setTimeout(function (detection, x, y, x_org) {
            console.log("originally at "+x_org+", now waiting 3 sec and at "+x);
            applyText(detection.common_name, x, y, detection.confidence);
          }, 3*1000, detection, x - (5*avgfps), y, x);
        } else {
          applyText(detection.common_name, x, y, detection.confidence);
        }
        // stagger Y placement
        add+= 15;
        if(add >= 60) {
           add = 0;
        }
      }
    }
  };
  xhttp.open("GET", "spectrogram.php?ajax_csv=true&newest_file="+newest_file, true);
  xhttp.send();
}

window.setInterval(function(){
   loadDetectionIfNewExists();
}, 1000);

// US-41: palettes as RGB stops over the 0..1 magnitude; birdnet keeps the
// original HSL ramp (hue 280 → 400, lightness 10 % → 80 %).
const PALETTE_STOPS = {
  viridis:   [[68,1,84],[59,82,139],[33,145,140],[94,201,98],[253,231,37]],
  inferno:   [[0,0,4],[87,16,110],[188,55,84],[249,142,9],[252,255,164]],
  ocean:     [[0,0,0],[0,30,90],[0,110,180],[0,200,230],[220,255,255]],
  grayscale: [[0,0,0],[255,255,255]],
  soxheat:   [[0,0,0],[30,0,90],[120,0,140],[200,40,60],[240,140,0],[255,240,120],[255,255,255]],
};
var palette = "<?php echo $SPECTROGRAM_PALETTE; ?>";
// US-42: sensitivity — the analyser maps [floor, floor+range] dB onto 0..255;
// the ramp is then bent by the contrast gamma (1 = linear, < 1 lifts faint
// sounds, > 1 keeps only the strong ones).
var specFloor = <?php echo $SPECTROGRAM_FLOOR_DB; ?>;
var specRange = <?php echo $SPECTROGRAM_RANGE_DB; ?>;
var specGamma = <?php echo $SPECTROGRAM_CONTRAST; ?>;
var silentMode = false;
try { silentMode = window.localStorage.getItem('spectrogram_silent') === '1'; } catch (e) {}
function applySilent() {
  if (typeof outGain === 'undefined' || !outGain) return;
  outGain.gain.setValueAtTime(silentMode ? 0 : 1, ACTX.currentTime);
}
function applySensitivity() {
  if (typeof ANALYSER === 'undefined' || !ANALYSER) return;
  ANALYSER.minDecibels = specFloor;
  ANALYSER.maxDecibels = Math.min(0, specFloor + specRange);
}
function paletteColor(rat) {
  rat = Math.pow(Math.min(Math.max(rat, 0), 1), specGamma);
  if (!(palette in PALETTE_STOPS)) {
    let hue = Math.round((rat * 120) + 280 % 360);
    return `hsl(${hue}, 100%, ${10 + (70 * rat)}%)`;
  }
  const st = PALETTE_STOPS[palette];
  const pos = Math.min(Math.max(rat, 0), 1) * (st.length - 1);
  const i = Math.min(Math.floor(pos), st.length - 2);
  const f = pos - i;
  const r = Math.round(st[i][0] + (st[i+1][0] - st[i][0]) * f);
  const g = Math.round(st[i][1] + (st[i+1][1] - st[i][1]) * f);
  const b = Math.round(st[i][2] + (st[i+1][2] - st[i][2]) * f);
  return `rgb(${r}, ${g}, ${b})`;
}

var compressor = undefined;
var SOURCE;
var ACTX;
var ANALYSER;
var gainNode;
var outGain;   // last stage before the speakers — 0 = silent mode (spectrogram only), 1 = audible

function toggleCompression(state) {
  //var biquadFilter = ACTX.createBiquadFilter();
  //biquadFilter.type = "highpass";
 // biquadFilter.frequency.setValueAtTime(13000, ACTX.currentTime);
  if(state == true) {
    SOURCE.disconnect(gainNode)
    gainNode.disconnect(ANALYSER);
    gainNode.disconnect(outGain);
    SOURCE.connect(compressor);
    compressor.connect(ANALYSER);
    ANALYSER.connect(gainNode);
    gainNode.connect(outGain);
    //biquadFilter.connect(ANALYSER);
    //biquadFilter.connect(ACTX.destination);
  } else {
    SOURCE.disconnect(compressor);
    compressor.disconnect(ANALYSER);
    ANALYSER.disconnect(gainNode);
    gainNode.disconnect(outGain);
    SOURCE.connect(gainNode);
    gainNode.connect(ANALYSER);
    gainNode.connect(outGain);
  }
}

function toggleFreqshift(state) {
  if (state == true) {
    console.log("freqshift activated")
  } else {
    console.log("freqshift deactivated")
  }

  freqShiftReconnectDelay = <?php echo $FREQSHIFT_RECONNECT_DELAY; ?>;

  var livestream_freqshift_spinner = document.getElementById('livestream_freqshift_spinner');
  livestream_freqshift_spinner.style.display = "inline"; 
  // Create the XMLHttpRequest object.
  const xhr = new XMLHttpRequest();
  // Initialize the request
  xhr.open("GET", 'views.php?activate_freqshift_in_livestream=' + state + '&view=Advanced&submit=advanced');
  // Send the request
  xhr.send();
  // Fired once the request completes successfully
  xhr.onload = function (e) {
    // Check if the request was a success
    if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
      // Restart the audio player in case it stopped working while the livestream service was restarted
      var audio_player = document.querySelector('audio#player');
      if (audio_player !== 'undefined') {
        //central_controls_element.appendChild(h1_loading);
        //Wait 2 seconds before restarting the stream
        setTimeout(function () {
          console.log("Restarting connection with livestream");
          audio_player.pause();
          audio_player.setAttribute('src', 'stream');
          audio_player.load();
          audio_player.play();

          livestream_freqshift_spinner.style.display = "none"; 
        },
        freqShiftReconnectDelay
        )
      }
    }
  }
}

function drawFrequencyAxis() {
  // Left-edge frequency scale. Mirrors the drawing math in process(): FFT bin
  // i is plotted at y = H - i*h with h = H/LEN + 0.9, and bin i corresponds to
  // i * (sampleRate/2) / LEN Hz — so the labels sit exactly on their bins.
  var old = document.getElementById('freqaxis');
  if (old) { old.remove(); }
  const CVS = document.body.querySelector('canvas');
  const rect = CVS.getBoundingClientRect();
  const LEN = ANALYSER.frequencyBinCount;
  const h = (CVS.height / LEN + 0.9);
  const nyquist = ACTX.sampleRate / 2;
  const scaleY = rect.height / CVS.height;
  const axis = document.createElement('div');
  axis.id = 'freqaxis';
  axis.style.cssText = 'position:absolute;pointer-events:none;z-index:5;'
    + 'left:' + (rect.left + window.scrollX) + 'px;'
    + 'top:' + (rect.top + window.scrollY) + 'px;'
    + 'width:60px;height:' + rect.height + 'px;font:10px sans-serif;color:#fff;';
  for (let f = 2000; f < nyquist; f += 2000) {
    const y = (CVS.height - (f / nyquist) * LEN * h) * scaleY;
    if (y < 10 || y > rect.height - 6) continue;
    const tick = document.createElement('span');
    tick.style.cssText = 'position:absolute;left:2px;top:' + (y - 6) + 'px;'
      + 'text-shadow:0 0 3px #000,0 0 3px #000,0 0 3px #000;';
    tick.textContent = '\u2014 ' + (f / 1000) + ' kHz';
    axis.appendChild(tick);
  }
  document.body.appendChild(axis);
}

function initialize() {
  document.body.querySelector('h1').remove();
  const CVS = document.body.querySelector('canvas');
  CTX = CVS.getContext('2d');
  // Match the drawing buffer to the CSS-laid-out size so the spectrogram is
  // neither stretched nor taller than the visible area.
  const W = CVS.width = CVS.clientWidth;
  const H = CVS.height = CVS.clientHeight;

  ACTX = new AudioContext();
  ANALYSER = ACTX.createAnalyser();

  ANALYSER.fftSize = 2048;  
  applySensitivity();
  drawFrequencyAxis();
  
  try{
    process();
  } catch(e) {
    console.log(e)
    window.top.location.reload();
  }



  function process() {
    SOURCE = ACTX.createMediaElementSource(player);
    

    compressor = ACTX.createDynamicsCompressor();
    compressor.threshold.setValueAtTime(-50, ACTX.currentTime);
    compressor.knee.setValueAtTime(40, ACTX.currentTime);
    compressor.ratio.setValueAtTime(12, ACTX.currentTime);
    compressor.attack.setValueAtTime(0, ACTX.currentTime);
    compressor.release.setValueAtTime(0.25, ACTX.currentTime);
    gainNode = ACTX.createGain();
    gainNode.gain = 1;
    // Silent mode (owner 2026-09-17): the analyser keeps drawing while the
    // speakers get nothing — outGain sits between the graph and the output.
    outGain = ACTX.createGain();
    outGain.connect(ACTX.destination);
    applySilent();
    SOURCE.connect(gainNode);
    gainNode.connect(ANALYSER);
    gainNode.connect(outGain);

    document.getElementById("compression").removeAttribute("disabled");
    document.getElementById("freqshift").removeAttribute("disabled");

    console.log(SOURCE);
    const DATA = new Uint8Array(ANALYSER.frequencyBinCount);
    const LEN = DATA.length;
    const h = (H / LEN + 0.9);
    const x = W - 1;
    CTX.fillStyle = paletteColor(0);
    CTX.fillRect(0, 0, W, H);

    loop();

    function loop(time) {
      if (requestTime) {
          fpsval = Math.round(1000/((performance.now() - requestTime)))
          if(fpsval > 0){
              fps.push( fpsval);
          }
      }
      if(fps.length > 0){
          avgfps = fps.reduce((a, b) => a + b) / fps.length;
      }
      requestTime = time;
      window.requestAnimationFrame((timeRes) => loop(timeRes));
      let imgData = CTX.getImageData(1, 0, W - 1, H);

      CTX.fillStyle = paletteColor(0);
      CTX.fillRect(0, 0, W, H);
      CTX.putImageData(imgData, 0, 0);
      ANALYSER.getByteFrequencyData(DATA);
      for (let i = 0; i < LEN; i++) {
        let rat = DATA[i] / 255 ;
        CTX.beginPath();
        CTX.strokeStyle = paletteColor(rat);
        CTX.moveTo(x, H - (i * h));
        CTX.lineTo(x, H - (i * h + h));
        CTX.stroke();
      }
    }
  }
}

</script>
<style>
html, body {
  height: 100%;
}

/* SPECTROGRAM_HEIGHT (birdnet.conf, in vh): the live graph takes that share of
   the pane height, leaving room for the topnav and the controls bar above it. */
canvas {
  display: block;
  height: <?php echo $SPECTROGRAM_HEIGHT; ?>vh;
  width: 100%;
}

#spectrogramimage {
  height: <?php echo $SPECTROGRAM_HEIGHT; ?>vh;
}

h1 {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  margin: 0;
}
</style>

<img id="spectrogramimage" style="width:100%;display:none" src="spectrogram.png?nocache=<?php echo $time;?>">

<!-- US-41: palette picklist + height box, top-left of the spectrogram pane -->
<div id="specopts" style="text-align:left;padding:2px 8px;font-size:12px;position:relative;">
  <span style="position:absolute;right:8px;top:2px;">
    <label for="height_input">Height (% of page): </label>
    <input id="height_input" type="number" min="20" max="100" step="1" style="width:4.5em;" value="<?php echo $SPECTROGRAM_HEIGHT; ?>">
  </span>
  <label for="palette_select">Palette: </label>
  <select id="palette_select" class="testbtn">
    <?php foreach ($SPECTROGRAM_PALETTES as $key => $label) {
      echo '<option value="' . $key . '"' . ($key == $SPECTROGRAM_PALETTE ? ' selected="selected"' : '') . '>' . $label . '</option>';
    } ?>
  </select>
  &nbsp;&nbsp;
  <label for="floor_input" title="Signal at or below this level takes the darkest colour">Floor </label>
  <input id="floor_input" type="range" min="-120" max="-40" step="5" style="width:110px;vertical-align:middle;" value="<?php echo $SPECTROGRAM_FLOOR_DB; ?>">
  <span id="floor_value" style="display:inline-block;width:4em;"><?php echo $SPECTROGRAM_FLOOR_DB; ?> dB</span>
  &nbsp;
  <label for="range_input" title="Width of the colour scale above the floor">Range </label>
  <input id="range_input" type="range" min="30" max="120" step="5" style="width:110px;vertical-align:middle;" value="<?php echo $SPECTROGRAM_RANGE_DB; ?>">
  <span id="range_value" style="display:inline-block;width:4em;"><?php echo $SPECTROGRAM_RANGE_DB; ?> dB</span>
  &nbsp;
  <label for="contrast_input" title="Gamma of the colour ramp: below 1 lifts faint sounds, above 1 keeps only the strong ones">Contrast </label>
  <input id="contrast_input" type="range" min="0.5" max="2" step="0.1" style="width:110px;vertical-align:middle;" value="<?php echo $SPECTROGRAM_CONTRAST; ?>">
  <span id="contrast_value" style="display:inline-block;width:2.5em;"><?php echo $SPECTROGRAM_CONTRAST; ?></span>
  <span id="specopts_status" style="margin-left:6px;color:#9f9;"></span>
</div>

<div class="centered">
	<?php
	if (isset($RTSP_Stream_Config) && !empty($RTSP_Stream_Config)) {
		?>
        <div style="display:inline" id="RTSP_streams">
            <label>RTSP Stream: </label>
            <select id="rtsp_stream_select" class="testbtn" name="RTSP Streams">
				<?php
				//The setting representing which livestream to stream is more than the number of RTSP streams available
				//maybe the list of streams has been modified
                //This isn't the ideal for this, but needed a way to fix this setting without calling the advanced setting page
				if (array_key_exists($config['RTSP_STREAM_TO_LIVESTREAM'], $RTSP_Stream_Config) === false) {
					$contents = file_get_contents('/etc/birdnet/birdnet.conf');
					$contents = preg_replace("/RTSP_STREAM_TO_LIVESTREAM=.*/", "RTSP_STREAM_TO_LIVESTREAM=\"0\"", $contents);
					$fh = fopen("/etc/birdnet/birdnet.conf", "w");
					fwrite($fh, $contents);
					get_config($force_reload=true);
					exec("sudo systemctl restart livestream.service");
				}

				//Print out the dropdown list for the RTSP streams
				foreach ($RTSP_Stream_Config as $stream_id => $stream_host) {
					$isSelected = "";
					//Match up the selected value saved in config so we can preselect it
					if ($config['RTSP_STREAM_TO_LIVESTREAM'] == $stream_id) {
						$isSelected = 'selected="selected"';
					}
					//Create the select option
					echo "<option value=" . $stream_id . " $isSelected >" . $stream_host . "</option>";
				}

				?>
            </select>
        </div>
        &mdash;
		<?php
	}
	?>
  <!-- Gain slider removed (owner 2026-09-17): colour sensitivity lives in the top bar (floor / range / contrast) -->
  <div style="display:inline" id="silent" >
    <label for="silent_input" title="Draw the spectrogram without sending the audio to the speakers">Silent: </label>
    <input name="silent" type="checkbox" id="silent_input">
  </div>
    &mdash;
  <div style="display:inline" id="comp" >
    <label>Compression: </label>
    <input name="compression" type="checkbox" id="compression" disabled>
  </div>
  <div style="display:inline" id="fshift" >
    <label>Freq shift: </label>
    <?php 
        if ($config['ACTIVATE_FREQSHIFT_IN_LIVESTREAM'] == "true") {
          $freqshift_state = "checked";
        } else {
          $freqshift_state = "";
        }
    ?>
    <input name="freqshift" type="checkbox" id="freqshift" <?php echo($freqshift_state); ?>  disabled>
    <img id="livestream_freqshift_spinner" src=images/spinner.gif style="height: 25px; vertical-align: top; display: none">
  </div>
</div>

<audio style="display:none" controls="" crossorigin="anonymous" id='player' preload="none"><source id="playersrc" src="stream"></audio>
<h1 id="loading-h1">Loading...</h1>
<canvas></canvas>

<script>
var rtsp_stream_select = document.getElementById("rtsp_stream_select");
if (typeof (rtsp_stream_select) !== 'undefined' && rtsp_stream_select !== null) {
    //When the dropdown selection is changed set the new value is settings, then restart the livestream service so it broadcasts newly selected RTSP stream
    rtsp_stream_select.onchange = function () {
        if (this.value !== 'undefined') {
            // Get the audio player element
            var audio_player = document.querySelector('audio#player');
            var central_controls_element = document.getElementsByClassName('centered')[0];

            //Create the loading header again as a placeholder while we're waiting to reload the stream
            var h1_loading = document.createElement("H1");
            var h1_loading_text = document.createTextNode("Loading...");
            h1_loading.setAttribute("id", "loading-h1");
            h1_loading.setAttribute("style", "font-size:48px; font-weight: bolder; color: #FFF");
            h1_loading.appendChild(h1_loading_text);

            // Create the XMLHttpRequest object.
            const xhr = new XMLHttpRequest();
            // Initialize the request
            xhr.open("GET", 'views.php?rtsp_stream_to_livestream=' + this.value + '&view=Advanced&submit=advanced');
            // Send the request
            xhr.send();
            // Fired once the request completes successfully
            xhr.onload = function (e) {
                // Check if the request was a success
                if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
                    // Restart the audio player in case it stopped working while the livestream service was restarted
                    if (audio_player !== 'undefined') {
                        central_controls_element.appendChild(h1_loading);
                        //Wait 5 seconds before restarting the stream
                        setTimeout(function () {
                                audio_player.pause();
                                audio_player.setAttribute('src', 'stream');
                                audio_player.load();
                                audio_player.play();

                                document.getElementById('loading-h1').remove()
                            },
                            10000
                        )
                    }
                }
            }
        }
    }
}

var silentBox = document.getElementById("silent_input");
silentBox.checked = silentMode;
silentBox.onclick = function() {
  silentMode = this.checked;
  try { window.localStorage.setItem('spectrogram_silent', silentMode ? '1' : '0'); } catch (e) {}
  applySilent();
}

var compression = document.getElementById("compression");
compression.onclick = function() {
  toggleCompression(this.checked);
}

var freqshift = document.getElementById("freqshift");
freqshift.onclick = function() {
  toggleFreqshift(this.checked);
}

// US-41/US-42: persist through the page's own endpoint (save_spectrogram),
// which writes only the spectrogram keys and restarts only the SoX image
// engine — the audio stream and the analysis are never touched, so the live
// canvas keeps running (owner 2026-09-17: "o rendering atual, mais nada").
// Palette / floor / range / contrast apply live; only the height needs a
// reload (the drawing buffer is sized once at initialize()).
function saveSpectrogramSetting(param, value) {
  var status = document.getElementById('specopts_status');
  status.textContent = 'saving…';
  const xhr = new XMLHttpRequest();
  xhr.open("GET", 'spectrogram.php?save_spectrogram=1&' + param + '=' + encodeURIComponent(value));
  xhr.onload = function () {
    if (this.status === 200) {
      if (param === 'spectrogram_height') {
        status.textContent = 'saved — reloading';
        window.location = "views.php?view=Spectrogram";
      } else {
        status.textContent = 'saved';
        setTimeout(function(){ status.textContent = ''; }, 2000);
      }
    } else {
      status.textContent = 'not saved (login?)';
    }
  };
  xhr.onerror = function () { status.textContent = 'not saved'; };
  xhr.send();
}
document.getElementById("palette_select").onchange = function() {
  palette = this.value;   // live preview until the reload
  saveSpectrogramSetting('spectrogram_palette', this.value);
};
document.getElementById("height_input").onchange = function() {
  var v = Math.max(20, Math.min(100, parseInt(this.value) || 80));
  this.value = v;
  saveSpectrogramSetting('spectrogram_height', v);
};
// US-42: sensitivity sliders — live preview while dragging ('input'), saved
// on release ('change'); nothing else on the page is touched.
var floorS = document.getElementById("floor_input"), rangeS = document.getElementById("range_input"), contrastS = document.getElementById("contrast_input");
floorS.oninput = function() { specFloor = parseInt(this.value); document.getElementById("floor_value").textContent = specFloor + " dB"; applySensitivity(); };
rangeS.oninput = function() { specRange = parseInt(this.value); document.getElementById("range_value").textContent = specRange + " dB"; applySensitivity(); };
contrastS.oninput = function() { specGamma = parseFloat(this.value); document.getElementById("contrast_value").textContent = specGamma.toFixed(1); };
floorS.onchange = function() { saveSpectrogramSetting('spectrogram_floor_db', parseInt(this.value)); };
rangeS.onchange = function() { saveSpectrogramSetting('spectrogram_range_db', parseInt(this.value)); };
contrastS.onchange = function() { saveSpectrogramSetting('spectrogram_contrast', parseFloat(this.value)); };
</script>
