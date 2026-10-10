<?php
/* Basic input sanitation */
$_GET  = filter_input_array(INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];
$_POST = filter_input_array(INPUT_POST, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];

require_once __DIR__ . '/common.php';
/* Species Pages (owner 2026-10-09: the species list and Species Management are one page, view=Bird): anyone sees the
 * list; every change (tier, threshold, lists, delete) needs the login */
foreach (['settier', 'setconf', 'toggle', 'getcounts', 'delete'] as $action) {
  if (isset($_GET[$action])) ensure_authenticated();
}

$home = get_home();

/* ---------- disk species counts (AJAX endpoint) ---------- */
if (isset($_GET['diskcounts'])) {
    header('Content-Type: application/json');
    $script = __DIR__ . '/disk_species_count.sh';
    $cmd    = 'HOME=' . escapeshellarg($home) . ' bash ' . escapeshellarg($script) . ' 2>&1';
    $output = @shell_exec($cmd);
    $counts = [];
    if ($output !== null) {
        foreach (preg_split('/\\r?\\n/', $output) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^([0-9]+(?:\\.[0-9]+)?)(k?)\\s*:\\s*(.+)$/i', $line, $m)) {
                $num = (float)$m[1];
                if (strtolower($m[2]) === 'k') $num *= 1000;
                $counts[$m[3]] = (int)round($num);
            }
        }
    }
    echo json_encode($counts, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- DB open (RO unless deleting) ---------- */
$flags = isset($_GET['delete']) ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY;
$db   = new SQLite3(__DIR__ . '/birds.db', $flags);
$db->busyTimeout(1000);

/* Paths / lists */
$base_symlink   = $home . '/BirdSongs/Extracted/By_Date';
$base           = realpath($base_symlink);

$confirm_file   = __DIR__ . '/confirmed_species_list.txt';
$exclude_file   = __DIR__ . '/exclude_species_list.txt';
$whitelist_file = __DIR__ . '/whitelist_species_list.txt';
$tiers_file     = dirname(__DIR__) . '/notification_tiers.txt';
$conf_file      = dirname(__DIR__) . '/species_confidence.txt';

foreach ([$confirm_file, $exclude_file, $whitelist_file, $tiers_file, $conf_file] as $file) {
    if (!file_exists($file)) touch($file);
}

/* Notification tiers: 'Sci_Name=tier' per non-normal species (muted/rare) */
$species_tiers = [];
// a species without its own line follows the default tier of Settings (Notifications)
$default_tier_view = strtolower(get_config()['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
if (!in_array($default_tier_view, ['normal', 'muted', 'rare'], true)) $default_tier_view = 'normal';
foreach (file_exists($tiers_file) ? file($tiers_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
    [$t_sci, $t_tier] = array_pad(explode('=', trim($l), 2), 2, '');
    if ($t_sci !== '' && $t_tier !== '') $species_tiers[$t_sci] = strtolower($t_tier);
}

/* US-48: per-species minimum confidence, 'Sci_Name=0.60' per listed species */
$species_conf = [];
foreach (file_exists($conf_file) ? file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
    [$c_sci, $c_val] = array_pad(explode('=', trim($l), 2), 2, '');
    if ($c_sci !== '' && is_numeric($c_val)) $species_conf[$c_sci] = (float)$c_val;
}

$confirmed_species   = file_exists($confirm_file)   ? file($confirm_file,   FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$excluded_species = file_exists($exclude_file) ? array_map(fn($l) => explode('_', trim($l), 2)[0], file($exclude_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
$whitelisted_species = file_exists($whitelist_file) ? array_map(fn($l) => explode('_', trim($l), 2)[0], file($whitelist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];

$config    = get_config();
$sf_thresh = isset($config['SF_THRESH']) ? (float)$config['SF_THRESH'] : 0.0;
$global_conf = isset($config['CONFIDENCE']) ? (float)$config['CONFIDENCE'] : 0.7;

/* ---------- helpers ---------- */
function join_path(...$parts): string { return preg_replace('#/+#', '/', implode('/', $parts)); }
function can_unlink(string $p): bool { return is_link($p) || is_file($p); }

/* Collect files/dirs for a species */
function collect_species_targets(SQLite3 $db, string $species, string $home, $base): array {
  $stmt = $db->prepare('SELECT Date, Com_Name, Sci_Name, File_Name FROM detections WHERE Sci_Name = :name');
  ensure_db_ok($stmt);
  $stmt->bindValue(':name', $species, SQLITE3_TEXT);
  $res = $stmt->execute();

  $count = 0; $files = []; $dirs = []; $sci = null;
  while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $count++; if ($sci === null) $sci = $row['Sci_Name'];
    $dir = str_replace([' ', "'"], ['_', ''], $row['Com_Name']);
    $candidates = [
      join_path($home, 'BirdSongs/Extracted/By_Date',         $row['Date'], $dir, $row['File_Name']),
      join_path($home, 'BirdSongs/Extracted/By_Date/shifted', $row['Date'], $dir, $row['File_Name']),
    ];
    foreach ($candidates as $c) {
      if (can_unlink($c)) { $files[$c] = true; $dirs[] = dirname($c); continue; }
      $d = realpath(dirname($c));
      if ($d !== false) {
        $alt = $d . DIRECTORY_SEPARATOR . basename($c);
        if (can_unlink($alt)) { $files[$alt] = true; $dirs[] = dirname($alt); }
      }
    }
  }
  return ['count'=>$count, 'files'=>array_keys($files), 'dirs'=>array_values(array_unique($dirs)), 'sci'=>$sci];
}

/* ---------- set notification tier (Muted/Normal/Rare) ---------- */
if (isset($_GET['settier'], $_GET['species'], $_GET['tier'])) {
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  if ($species === '' || strpbrk($species, "=\n\r") !== false) { header('Content-Type: text/plain'); echo 'Invalid species'; exit; }
  $tier    = strtolower($_GET['tier']);
  if (!in_array($tier, ['normal', 'muted', 'rare'], true)) { header('Content-Type: text/plain'); echo 'Invalid tier'; exit; }
  $lines = file_exists($tiers_file) ? file($tiers_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  $lines = array_values(array_filter($lines, fn($l) => explode('=', trim($l), 2)[0] !== $species));
  // a line only when the tier differs from the default one (NOTIFICATION_DEFAULT_TIER); "normal" on a station
  // whose default is muted must be written, or the species would stay muted
  $default_tier = strtolower(get_config()['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
  if (!in_array($default_tier, ['normal', 'muted', 'rare'], true)) $default_tier = 'normal';
  if ($tier !== $default_tier) $lines[] = $species . '=' . $tier;
  sort($lines, SORT_STRING);
  file_put_contents($tiers_file, implode("\n", $lines) . (empty($lines) ? "" : "\n"), LOCK_EX);
  header('Content-Type: text/plain'); echo 'OK'; exit;
}

/* ---------- set per-species minimum confidence (US-48) ---------- */
if (isset($_GET['setconf'], $_GET['species'], $_GET['value'])) {
  header('Content-Type: text/plain');
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  if ($species === '' || strpbrk($species, "=\n\r") !== false) { echo 'Invalid species'; exit; }
  $value = trim(str_replace(',', '.', $_GET['value']));
  if ($value !== '' && (!is_numeric($value) || (float)$value < 0.01 || (float)$value > 0.99)) { echo 'Invalid value (0.01 - 0.99, empty = global)'; exit; }
  $lines = file_exists($conf_file) ? file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  $lines = array_values(array_filter($lines, fn($l) => explode('=', trim($l), 2)[0] !== $species));
  if ($value !== '') $lines[] = $species . '=' . sprintf('%.2f', round((float)$value, 2));
  sort($lines, SORT_STRING);
  file_put_contents($conf_file, implode("\n", $lines) . (empty($lines) ? "" : "\n"), LOCK_EX);
  echo 'OK'; exit;
}

/* ---------- toggle exclude/whitelist/confirmed ---------- */
if (isset($_GET['toggle'], $_GET['species'], $_GET['action'])) {
  $list    = $_GET['toggle'];
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);

  if     ($list === 'exclude')   { $file = $exclude_file; }
  elseif ($list === 'whitelist') { $file = $whitelist_file; }
  elseif ($list === 'confirmed') { $file = $confirm_file; }
  else { header('Content-Type: text/plain'); echo 'Invalid list type'; exit; }

  $lines = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  if ($_GET['action'] === 'add') {
    if (!in_array($species, $lines, true)) $lines[] = $species;
  } else {
    $lines = array_values(array_filter($lines, fn($l) => $l !== $species));
  }
  file_put_contents($file, implode("\n", $lines) . (empty($lines) ? "" : "\n"));
  header('Content-Type: text/plain'); echo 'OK'; exit;
}

/* ---------- count ---------- */
if (isset($_GET['getcounts'])) {
  header('Content-Type: application/json');
  if ($base === false) { http_response_code(500); exit(json_encode(['error' => 'Base directory not found'])); }
  $species = htmlspecialchars_decode($_GET['getcounts'], ENT_QUOTES);
  $info = collect_species_targets($db, $species, $home, $base);
  echo json_encode(['count' => $info['count'], 'files' => count($info['files'])]); exit;
}

/* ---------- delete ---------- */
if (isset($_GET['delete'])) {
  header('Content-Type: application/json');
  if ($base === false) { http_response_code(500); exit(json_encode(['error' => 'Base directory not found'])); }
  $species = htmlspecialchars_decode($_GET['delete'], ENT_QUOTES);
  $info = collect_species_targets($db, $species, $home, $base);
  $deleted = count($info['files']);
  foreach ($info['dirs'] as $dir) {
    $output = [];
    if (exec("sudo rm -r " . escapeshellarg($dir) . " 2>&1", $output, $rc) === false || $rc !== 0) {
      echo json_encode(['error' => 'files deletion failed: ' . implode(', ', $output)]);
      exit;
    }
  }
  $del = $db->prepare('DELETE FROM detections WHERE Sci_Name = :name');
  ensure_db_ok($del);
  $del->bindValue(':name', $species, SQLITE3_TEXT);
  $del->execute();
  $lines_deleted = $db->changes();

  if ($info['sci'] !== null && file_exists($confirm_file)) {
    $identifier = $info['sci'];
    $lines = array_values(array_filter($confirmed_species, fn($l) => $l !== $identifier));
    file_put_contents($confirm_file, implode("\n", $lines) . (empty($lines) ? "" : "\n"));
  }

  if ($info['sci'] !== null && file_exists($tiers_file)) {
    $identifier = $info['sci'];
    $t_lines = file($tiers_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $t_lines = array_values(array_filter($t_lines, fn($l) => explode('=', trim($l), 2)[0] !== $identifier));
    file_put_contents($tiers_file, implode("\n", $t_lines) . (empty($t_lines) ? "" : "\n"), LOCK_EX);
  }

  if ($info['sci'] !== null && file_exists($conf_file)) {
    $identifier = $info['sci'];
    $c_lines = file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $c_lines = array_values(array_filter($c_lines, fn($l) => explode('=', trim($l), 2)[0] !== $identifier));
    file_put_contents($conf_file, implode("\n", $c_lines) . (empty($c_lines) ? "" : "\n"), LOCK_EX);
  }

  echo json_encode(['lines' => $lines_deleted, 'files' => $deleted]); exit;
}

/* ---------- query species aggregates ---------- */
$sql = <<<SQL
SELECT Com_Name, Sci_Name, COUNT(*) AS Count, MAX(Confidence) AS MaxConfidence, MAX(Date) AS LastSeen, MIN(Date) AS FirstSeen, COUNT(DISTINCT Date) AS Days
FROM detections
GROUP BY Sci_Name;
SQL;
$result = $db->query($sql);
// Detected (default) = the species with detections; All (owner 2026-10-09) = also every species the station can detect:
// the active model's labels, limited to the include list (Custom Species List) when it has species, minus the exclude
// list — the location threshold is ignored. Both are sorted from the most to the least recorded.
$scope = ($_GET['scope'] ?? '') === 'all' ? 'all' : 'detected';
$rows = [];
while ($r = $result->fetchArray(SQLITE3_ASSOC)) $rows[$r['Sci_Name']] = $r;
$confirmed_set = array_flip($confirmed_species);
$excluded_set = array_flip($excluded_species);
$whitelisted_set = array_flip($whitelisted_species);
if ($scope === 'all') {
  $label_name = active_model_labels();
  $include = [];
  foreach (@file($home . '/BirdNET-Pi/include_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $include[explode('_', trim($l), 2)[0]] = true;
  }
  foreach ($label_name as $sci => $name) {
    if (isset($rows[$sci]) || isset($excluded_set[$sci]) || ($include && !isset($include[$sci]))) continue;
    $rows[$sci] = ['Com_Name' => $name, 'Sci_Name' => $sci, 'Count' => 0, 'MaxConfidence' => null, 'LastSeen' => '', 'FirstSeen' => '', 'Days' => 0];
  }
}
uasort($rows, fn($a, $b) => ($b['Count'] <=> $a['Count']) ?: strcmp($a['Com_Name'], $b['Com_Name']));
?>
<style>
  .circle-icon{display:inline-block;width:12px;height:12px;border:1px solid #777;border-radius:50%;cursor:pointer;}
  /* left-aligned beside the side menu, smaller type (owner 2026-10-08) */
  .centered{max-width:none;margin:0 4px}
  /* the table standard (stdtable + std-table.js + species name picklist), compact type (owner 2026-10-09) */
  #speciesTable{font-size:12px;width:auto}
  #speciesTable th,#speciesTable td{padding:3px 6px}
  #speciesTable select,#speciesTable input{font-size:12px}
  /* table standard: list buttons on the left, the filter on the right, both on the table's width */
  .spm{display:inline-block;max-width:100%}
  .toolbar{display:flex;gap:8px;align-items:center;justify-content:space-between;margin:8px 0;flex-wrap:wrap}
  .toolbar .right{display:flex;gap:8px;align-items:center}
  .toolbar input[type="text"]{padding:5px 8px;width:260px;max-width:100%;font-size:14px}
  .toolbar .nowmodes button{padding:4px 12px;font-size:12px;border-radius:12px;height:auto;line-height:normal;margin:0}
  .toolbar .hmsort button{padding:3px 12px;font-size:12px;height:auto;line-height:normal}
  #speciesTable td{white-space:nowrap}
  .spnew{font-size:11px;background:#d97a00;color:#fff;border-radius:8px;padding:0 6px;margin-left:4px}
</style>

<div class="centered"><div class="spm">
  <div class="toolbar">
    <span class="nowmodes"><button type="button" class="<?php echo $scope === 'detected' ? 'active' : ''; ?>" onclick="location.href='views.php?view=Bird'" title="Species with detections">Detected</button><button type="button" class="<?php echo $scope === 'all' ? 'active' : ''; ?>" onclick="location.href='views.php?view=Bird&amp;scope=all'" title="Every species the station can detect: the model's species, limited to the Custom Species List when it has species, without the Excluded Species; the location threshold is ignored">All</button></span>
    <span class="right"><span class="hmsort" data-table="speciesTable" data-store="speciespages"><small>Sort:</small><button type="button" data-sort="n">Count</button><button type="button" data-sort="tax" title="Field-guide order (eBird/Clements taxonomy)">Taxonomy</button><button type="button" data-sort="az" title="Alphabetical, by the names shown">A–Z</button></span><small id="matchCount"></small><input id="q" type="text" placeholder="Filter species… (common, scientific or English name)" title="Type to filter; persists across reloads"></span>
  </div>

  <table id="speciesTable" class="stdtable">
    <thead>
      <tr>
        <th><select class="namemode" title="Names shown"><option value="com">Common name</option><option value="sci">Scientific name</option><option value="en">English name</option></select></th>
        <th class="r">Count</th>
        <th class="r" title="Days with detections">Days</th>
        <th class="r" title="Highest confidence of its detections">Max. Conf.</th>
        <th>First Seen</th>
        <th>Last Seen</th>
        <th class="r" title="Minimum confidence for this species (species override); empty = the global Minimum Confidence (<?php echo htmlspecialchars(sprintf('%.2f', $global_conf)); ?>)">Sp. Override</th>
        <th class="r">Probability</th>
        <th>Notification</th>
        <th>Confirmed</th>
        <th>Whitelist</th>
        <th title="Ticked = in the exclude list: no longer detected (its past detections stay listed here)">Exclude</th>
        <th class="r" title="Clip files on disk">Files</th>
        <th data-nosort>Delete</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $row) {
  $common = $row['Com_Name'];
  $scient = $row['Sci_Name'];
  $count  = (int)$row['Count'];
  $max_confidence = $row['MaxConfidence'] === null ? '' : round((float)$row['MaxConfidence'] * 100, 1);
  $identifier = $row['Sci_Name'].'_'.$row['Com_Name'];
  $identifier_sci = $row['Sci_Name'];

  $lastSeen = $row['LastSeen'] ?? '';
  $lastSeenSort = $lastSeen ? (strtotime($lastSeen) ?: 0) : 0;

  $english = get_english_name($row['Sci_Name']);
  $common_link = "<a href='views.php?view=Bird&amp;sci=" . rawurlencode($row['Sci_Name']) . "' title='Open the species page' data-com=\"" . htmlspecialchars($common, ENT_QUOTES)
    . "\" data-sci=\"" . htmlspecialchars($scient, ENT_QUOTES) . "\" data-en=\"" . htmlspecialchars($english, ENT_QUOTES) . "\">{$common}</a>";

  $is_confirmed   = isset($confirmed_set[$identifier_sci]);
  $is_excluded    = isset($excluded_set[$identifier_sci]);
  $is_whitelisted = isset($whitelisted_set[$identifier_sci]);


  $identifier_js = addslashes($identifier);
  $identifier_sci_js = addslashes($identifier_sci);

  $confirm_cell = $is_confirmed
    ? "<img style='cursor:pointer;max-width:12px;max-height:12px' src='images/check.svg' onclick=\"toggleSpecies('confirmed','{$identifier_sci_js}','del')\">"
    : "<span class='circle-icon' onclick=\"toggleSpecies('confirmed','{$identifier_sci_js}','add')\"></span>";

  $excl_cell = $is_excluded
    ? "<img style='cursor:pointer;max-width:12px;max-height:12px' src='images/check.svg' onclick=\"toggleSpecies('exclude','{$identifier_js}','del')\">"
    : "<span class='circle-icon' onclick=\"toggleSpecies('exclude','{$identifier_js}','add')\"></span>";

  $white_cell = $is_whitelisted
    ? "<img style='cursor:pointer;max-width:12px;max-height:12px' src='images/check.svg' onclick=\"toggleSpecies('whitelist','{$identifier_js}','del')\">"
    : "<span class='circle-icon' onclick=\"toggleSpecies('whitelist','{$identifier_js}','add')\"></span>";

  $species_tier = $species_tiers[$identifier_sci] ?? $default_tier_view;
  $tier_cell = "<select onchange=\"setTier('{$identifier_sci_js}', this.value)\">";
  foreach (['normal' => 'Normal', 'muted' => 'Muted', 'rare' => 'Rare'] as $t_val => $t_label) {
    $t_sel = $species_tier === $t_val ? " selected" : "";
    $tier_cell .= "<option value='{$t_val}'{$t_sel}>{$t_label}</option>";
  }
  $tier_cell .= "</select>";

  $own_conf  = $species_conf[$identifier_sci] ?? null;
  $conf_sort = sprintf('%.2f', $own_conf ?? $global_conf);
  $conf_val  = $own_conf === null ? '' : sprintf('%.2f', $own_conf);
  $conf_cell = "<input type='number' class='minconf' min='0.01' max='0.99' step='0.01' style='width:5em'"
             . " placeholder='" . sprintf('%.2f', $global_conf) . "' value='{$conf_val}'"
             . " title='Empty = global " . sprintf('%.2f', $global_conf) . "'"
             . " onchange=\"setConf('{$identifier_sci_js}', this)\">";

  // location model probability this week (region_profile.json, as in the detection lists) — computed here, so the
  // column needs no second request (that one needed the login and stayed 0.0000 for visitors, owner 2026-10-09)
  $prob = location_probability($scient, date('Y-m-d'));
  $prob_cell = $prob === null ? "<td class='r' data-sort='-1'>—</td>"
    : "<td class='r' data-sort='" . sprintf('%.4f', $prob) . "' style='color:" . ($prob >= $sf_thresh ? 'green' : 'red') . "'>" . sprintf('%.4f', $prob) . "</td>";
  echo "<tr data-n=\"{$count}\" data-tax=\"" . taxon_order($scient) . "\" data-comname=\"{$common}\" data-sciname=\"{$scient}\" data-q=\"" . htmlspecialchars(mb_strtolower($common . ' ' . $scient . ' ' . $english), ENT_QUOTES) . "\">"
     . "<td style='white-space:nowrap'>{$common_link}" . (($row['FirstSeen'] ?? '') === date('Y-m-d') ? "<span class='spnew'>new today</span>" : '') . "</td>"
     . "<td class='r'>{$count}</td>"
     . "<td class='r'>" . (int)($row['Days'] ?? 0) . "</td>"
     . "<td class='r' data-sort='" . ($max_confidence === '' ? -1 : $max_confidence) . "'>" . ($max_confidence === '' ? '—' : $max_confidence . '%') . "</td>"
     . "<td>" . (($row['FirstSeen'] ?? '') === '' ? '—' : $row['FirstSeen']) . "</td>"
     . "<td data-sort=\"{$lastSeenSort}\">" . ($lastSeen === '' ? '—' : $lastSeen) . "</td>"
     . "<td class='r' data-sort='{$conf_sort}'>".$conf_cell."</td>"
     . $prob_cell
     . "<td data-sort='{$species_tier}'>".$tier_cell."</td>"
     . "<td data-sort='".($is_confirmed?0:1)."'>".$confirm_cell."</td>"
     . "<td data-sort='".($is_whitelisted?0:1)."'>".$white_cell."</td>"
     . "<td data-sort='".($is_excluded?0:1)."'>".$excl_cell."</td>"
     . "<td class='r diskcount' data-sort='0'>…</td>"
     . "<td>" . ($count ? "<img style='cursor:pointer;max-width:20px' src='images/delete.svg' onclick=\"deleteSpecies('".addslashes($row['Sci_Name'])." + ".addslashes($row['Com_Name'])."')\">" : '') . "</td>"
     . "</tr>";
} ?>
    </tbody>
  </table>
</div></div>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/species-modal.js"></script>
<script>
const scriptsBase = 'scripts/';
const get = (url) => fetch(url, {cache:'no-store'}).then(r => r.text());

/* ---------- Files on Disk column, filled after the page shows ---------- */
function addDiskCounts() {
  return get(scriptsBase + 'species_tools.php?diskcounts=1').then(t => {
    let counts; try { counts = JSON.parse(t); } catch { console.warn('Could not parse disk counts'); return; }
    const decoder = document.createElement('textarea');
    document.querySelectorAll('#speciesTable tbody tr').forEach(tr => {
      decoder.innerHTML = tr.getAttribute('data-comname') || '';
      const count = counts[decoder.value.replace(/'/g, '')] || 0;
      const td = tr.querySelector('td.diskcount');
      td.textContent = count;
      td.dataset.sort = count;
    });
  }).catch(() => {
    console.warn('Disk counts load failed.');
  });
}

/* ---------- toggles / delete ---------- */
function setTier(species, tier) {
  get(scriptsBase + 'species_tools.php?settier=1&species=' + encodeURIComponent(species) + '&tier=' + encodeURIComponent(tier));
}
function setConf(species, input) {
  const value = input.value.trim();
  get(scriptsBase + 'species_tools.php?setconf=1&species=' + encodeURIComponent(species) + '&value=' + encodeURIComponent(value))
    .then(t => {
      const ok = t.trim() === 'OK';
      input.style.outline = ok ? '2px solid green' : '2px solid red';
      input.title = ok ? (value === '' ? 'Global value in use' : 'Saved: ' + value) : t.trim();
      if (ok) input.parentElement.dataset.sort = value === '' ? input.placeholder : parseFloat(value).toFixed(2);
      setTimeout(() => { input.style.outline = ''; }, 1500);
    });
}
/* confirmation modal for the Confirmed / Whitelist / Exclude columns (owner 2026-10-08) */
// askModal() and SPECIES_TOGGLE_TEXT live in static/species-modal.js (shared with the species page)
function toggleSpecies(list, species, action) {
  const parts = species.split('_');   // "Scientific name_Common name"
  const name = parts.length > 1 ? parts.slice(1).join('_') + ' (' + parts[0] + ')' : species;
  const t = (SPECIES_TOGGLE_TEXT[list] || {})[action];
  const go = () => get(scriptsBase + 'species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(species) + '&action=' + action)
    .then(r => { if (r.trim() === 'OK') location.reload(); });
  if (!t) { go(); return; }
  // whitelist and exclude change what is detected: No is the default there
  askModal(t[0].replace('NAME', name), t[1], 'Yes', list === 'whitelist' || list === 'exclude').then(ok => { if (ok) go(); });
}
function deleteSpecies(species) {
  let parts = species.split(' + '); let sci_species = parts[0]; let com_species = parts[1];
  get(scriptsBase + 'species_tools.php?getcounts=' + encodeURIComponent(sci_species)).then(t => {
    let info; try { info = JSON.parse(t); } catch { alert('Could not parse count response'); return; }
    if (!confirm('Delete ' + info.count + ' detections and local audio and png files for ' + com_species + '?')) return;
    get(scriptsBase + 'species_tools.php?delete=' + encodeURIComponent(sci_species)).then(t2 => {
      try { const res = JSON.parse(t2); alert('Deleted ' + res.lines + ' detections and ' + res.files + ' files for ' + com_species); }
      catch { alert('Deletion complete'); }
      location.reload();
    });
  });
}

/* ---------- Search with persistence ---------- */
const q = document.getElementById('q');
const matchCount = document.getElementById('matchCount');
function applyFilter() {
  const needle = (q.value || '').trim().toLowerCase();
  let shown = 0, total = 0;
  document.querySelectorAll('#speciesTable tbody tr').forEach(tr => {
    total++;
    const txt = tr.dataset.q || tr.innerText.toLowerCase();
    const vis = txt.includes(needle);
    tr.style.display = vis ? '' : 'none';
    if (vis) shown++;
  });
  matchCount.textContent = total ? `${shown} / ${total}` : '';
  try { localStorage.setItem('speciesFilter', q.value); } catch(e){}
}
q.addEventListener('input', applyFilter);

/* ---------- boot ---------- */
document.addEventListener('DOMContentLoaded', () => {
  try { const saved = localStorage.getItem('speciesFilter'); if (saved !== null) q.value = saved; } catch(e){}
  applyFilter();
  // Auto-load both heavy enrichments
  addDiskCounts();
});
</script>
