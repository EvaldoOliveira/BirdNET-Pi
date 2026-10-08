<?php
/* Station species lists (Settings > Location > Species list filter): save the active list under a name,
 * load a .txt list into ~/BirdNET-Pi/species_lists/, or make a station list the active one (owner 2026-10-08). POST only, authenticated. */
error_reporting(E_ERROR);
ini_set('display_errors', 0);
require_once __DIR__ . '/common.php';
ensure_authenticated();

$home = get_home();
$dir = $home . '/BirdNET-Pi/species_lists';
if (!is_dir($dir)) {
  mkdir($dir, 0775, true);
}
$action = $_POST['action'] ?? '';

// keep only "Scientific name_Common name" lines, without anything a shell or the config could trip on
function clean_list($text) {
  $out = array();
  foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
    $line = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $line));
    if ($line !== '' && strpos($line, '_') !== false) {
      $out[] = $line;
    }
  }
  $out = array_values(array_unique($out));
  sort($out);
  return $out;
}

if ($action === 'save') {
  $name = $_POST['name'] ?? '';
  if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) die('Invalid name');
  $active = $home . '/BirdNET-Pi/include_species_list.txt';
  $lines = clean_list(is_file($active) ? file_get_contents($active) : '');
  if (empty($lines)) die('The current list is empty: nothing to save');
  file_put_contents("$dir/$name.txt", implode("\n", $lines) . "\n");
  echo "Saved as $name (" . count($lines) . " species). Choose it in the Species list filter to use it.";
} elseif ($action === 'load') {
  if (!isset($_FILES['list']) || $_FILES['list']['error'] !== UPLOAD_ERR_OK) die('No file received');
  if ($_FILES['list']['size'] > 2 * 1024 * 1024) die('File too large');
  $name = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($_FILES['list']['name'], PATHINFO_FILENAME));
  $name = substr(trim($name, '_'), 0, 40);
  if ($name === '') die('Invalid file name');
  $lines = clean_list(file_get_contents($_FILES['list']['tmp_name']));
  if (empty($lines)) die('No "Scientific name_Common name" lines in the file');
  file_put_contents("$dir/$name.txt", implode("\n", $lines) . "\n");
  echo "Loaded $name (" . count($lines) . " species). Choose it in the Species list filter to use it.";
} elseif ($action === 'activate') {
  // make a station list the active one: SPECIES_LIST in birdnet.conf, then link the active list to it
  $name = $_POST['name'] ?? '';
  if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name) || !is_file("$dir/$name.txt")) die('No such list');
  $conf = file_get_contents('/etc/birdnet/birdnet.conf');
  $conf = preg_match('/^SPECIES_LIST=/m', $conf) ? preg_replace('/^SPECIES_LIST=.*/m', "SPECIES_LIST=$name", $conf)
                                                  : $conf . "\nSPECIES_LIST=$name\n";
  file_put_contents('/etc/birdnet/birdnet.conf', $conf);
  shell_exec('sudo -u ' . escapeshellarg(get_user()) . ' python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/select_species_list.py') . ' 2>&1');
  echo "$name is now the active species list.";
} else {
  http_response_code(400);
  echo 'Unknown action';
}
