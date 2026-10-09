<?php

define('__ROOT__', dirname(dirname(__FILE__)));

if (session_status() !== PHP_SESSION_ACTIVE)
  session_start();

function ensure_db_ok($sql_stmt) {
  if ($sql_stmt == False) {
    echo "Database is busy";
    header("refresh:1;");
    exit;
  }
}

function set_timezone() {
  if (!isset($_SESSION['my_timezone'])) {
    $_SESSION['my_timezone'] = trim(shell_exec('timedatectl show --value --property=Timezone'));
  }
  date_default_timezone_set($_SESSION['my_timezone']);
}

function get_config($force_reload = false) {
  $mtime = stat('/etc/birdnet/birdnet.conf')["mtime"];
  if (isset($_SESSION['my_config_version']) && $_SESSION['my_config_version'] !== $mtime) {
    $force_reload = true;
  }
  if (!isset($_SESSION['my_config']) || $force_reload) {
    $source = preg_replace("~^#+.*$~m", "", file_get_contents('/etc/birdnet/birdnet.conf'));
    $my_config = parse_ini_string($source);
    if ($my_config) {
      $_SESSION['my_config'] = $my_config;
    } else {
      syslog(LOG_ERR, "Cannot parse config");
    }
    $_SESSION['my_config_version'] = $mtime;
  }
  return $_SESSION['my_config'];
}

function get_user() {
  $config = get_config();
  $user = $config['BIRDNET_USER'];
  return $user;
}

function get_home() {
  $home = '/home/' . get_user();
  return $home;
}

function get_sitename() {
  $config = get_config();

  if ($config["SITE_NAME"] == "") {
    $site_name = "BirdNET-Pi";
  } else {
    $site_name = $config['SITE_NAME'];
  }
  return $site_name;
}

function get_service_mount_name() {
  $home = get_home();
  $service_mount = trim(shell_exec("systemd-escape -p --suffix=mount " . $home . "/BirdSongs/StreamData"));
  return $service_mount;
}

function is_authenticated() {
  $ret = false;
  if (isset($_SERVER['PHP_AUTH_USER'])) {
    $config = get_config();
    $ret = ($_SERVER['PHP_AUTH_PW'] == $config['CADDY_PWD'] && $_SERVER['PHP_AUTH_USER'] == 'birdnet');
  }
  return $ret;
}

function ensure_authenticated($error_message = 'You cannot edit the settings for this installation') {
  if (!is_authenticated()) {
    header('WWW-Authenticate: Basic realm="My Realm"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<table><tr><td>' . $error_message . '</td></tr></table>';
    exit;
  }
}

function debug_log($message) {
  if (is_bool($message)) {
    $message = $message ? 'true' : 'false';
  }
  error_log($message . "\n", 3, $_SERVER['DOCUMENT_ROOT'] . "/debug_log.log");
}

function get_com_en_name($sci_name) {
  if (!isset($_labels_flickr)) {
    $_labels_flickr = json_decode(file_get_contents(get_home() . "/BirdNET-Pi/model/l18n/labels_en.json"), true);
  }
  $engname = $_labels_flickr[$sci_name];
  return $engname;
}

function get_label($record, $sort_by, $date=null) {
  $name = $record["Com_Name"];
  if ($sort_by == "confidence") {
    $ret = $name . ' (' . round($record['MaxConfidence'] * 100) . '%)';
  } elseif ($sort_by == "occurrences") {
    $valuescount = $record['Count'];
    if ($valuescount >= 1000) {
      $ret = $name . ' (' . round($valuescount / 1000, 1) . 'k)';
    } else {
      $ret = $name . ' (' . $valuescount . ')';
    }
  } elseif (($sort_by == "date") && !isset($date)) {
    $ret = $name . ' (' . $record['Date'] . ')';
  } elseif (($sort_by == "date") && isset($date)) {
    $ret = $name . ' (' . $record['Time'] . ')';
  } else {
    $ret = $name;
  }
  return $ret;
}

function get_db() {
  static $_db;
  if (!isset($_db)) {
    $_db = new SQLite3(__ROOT__ . '/scripts/birds.db', SQLITE3_OPEN_READONLY);
    $_db->busyTimeout(1000);
  }
  return $_db;
}

function fetch_species_array($sort_by, $date=null) {
  $db = get_db();
  // rejected detections (Review: "not this bird") are not a species' best detection
  $where = "WHERE 1" . ((isset($date)) ? " AND Date == \"$date\"" : "") . not_rejected_sql();
  if ($sort_by === "occurrences") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY COUNT(*) DESC");
  } elseif ($sort_by === "confidence") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY MAX(Confidence) DESC");
  } elseif ($sort_by === "date") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY MIN(Date) DESC, Time DESC");
  } else {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY Com_Name ASC");
  }
  ensure_db_ok($statement);
  $result = $statement->execute();
  return $result;
}

function fetch_best_detection($com_name) {
  $db = get_db();
  $statement = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*), MAX(Confidence), File_Name, Date, Time from detections WHERE Com_Name = \"$com_name\"" . not_rejected_sql());
  ensure_db_ok($statement);
  $result = $statement->execute();
  return $result;
}

function fetch_all_detections($sci_name, $sort_by, $date=null) {
  $db = get_db();
  $filter = (isset($date)) ? "AND Date == \"$date\"" : "";
  if ($sort_by === "occurrences") {
    $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == \"$sci_name\" $filter ORDER BY COUNT(*) DESC");
  } elseif ($sort_by === "confidence") {
    $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == \"$sci_name\" $filter ORDER BY Confidence DESC");
  } else {
    $order = (isset($date)) ? "Time DESC" : "Date DESC, Time DESC";
    $statement = $db->prepare("SELECT * FROM detections where Sci_Name == \"$sci_name\" $filter ORDER BY $order");
  }
  ensure_db_ok($statement);
  $result = $statement->execute();
  return $result;
}

function get_summary() {
  $db = get_db();
  // detections reviewed "not this bird" are left out of every total (owner 2026-10-09)
  $nr = not_rejected_sql();
  $statement = $db->prepare('SELECT COUNT(*) FROM detections WHERE 1' . $nr);
  ensure_db_ok($statement);
  $result = $statement->execute();
  $totalcount = $result->fetchArray(SQLITE3_ASSOC);

  $statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')' . $nr);
  ensure_db_ok($statement2);
  $result2 = $statement2->execute();
  $todaycount = $result2->fetchArray(SQLITE3_ASSOC);

  $statement3 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == Date(\'now\', \'localtime\') AND TIME >= TIME(\'now\', \'localtime\', \'-1 hour\')' . $nr);
  ensure_db_ok($statement3);
  $result3 = $statement3->execute();
  $hourcount = $result3->fetchArray(SQLITE3_ASSOC);

  $statement5 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE Date == Date(\'now\',\'localtime\')' . $nr);
  ensure_db_ok($statement5);
  $result5 = $statement5->execute();
  $todayspeciestally = $result5->fetchArray(SQLITE3_ASSOC);

  $statement6 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE 1' . $nr);
  ensure_db_ok($statement6);
  $result6 = $statement6->execute();
  $totalspeciestally = $result6->fetchArray(SQLITE3_ASSOC);

  $ret = [
    'totalcount' => $totalcount['COUNT(*)'],
    'todaycount' => $todaycount['COUNT(*)'],
    'hourcount' => $hourcount['COUNT(*)'],
    'speciestally' => $todayspeciestally['COUNT(DISTINCT(Sci_Name))'],
    'totalspeciestally' => $totalspeciestally['COUNT(DISTINCT(Sci_Name))']
  ];
  return $ret;
}

class ImageProvider {

  protected $db = null;
  protected $db_path = null;
  protected $db_reset = false;
  protected $context = null;

  public function __construct() {
    $this->set_db();
    $opts = ['http' => ['header' => "User-Agent: BirdNET-Pi"]];
    $this->context = stream_context_create($opts);
  }

  public function get_image($sci_name) {
    $image = $this->get_image_from_db($sci_name);
    if ($image !== false) {
      $now = new DateTime();
      $datetime = DateTime::createFromFormat("Y-m-d", $image['date_created']);
      $interval = $now->diff($datetime);
      $expire_days = rand(15, 25);
      if ($interval->days > $expire_days) {
        $image = false;
      }
    }
    if ($image === false) {
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
    }
    return $image;
  }

  public function is_reset() {
    return $this->db_reset;
  }

  protected function get_json($url) {
    return json_decode(file_get_contents($url, false, $this->context), true);
  }

  protected function set_db() {
    try {
      if ($this->db === null) {
        $db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
        $this->db = $db;
      }
    } catch (Exception $ex) {
      $this->create_tables();
    }
    $this->db->busyTimeout(1000);
  }

  protected function create_tables() {
    $tbl_def = "CREATE TABLE images (sci_name VARCHAR(63) NOT NULL PRIMARY KEY, com_en_name VARCHAR(63) NOT NULL, image_url TEXT NOT NULL, title TEXT NOT NULL, id TEXT NOT NULL UNIQUE, author_url TEXT NOT NULL, license_url TEXT NOT NULL, date_created DATE)";
    $db = new SQLite3($this->db_path);
    $db->exec($tbl_def);
    $db->exec('CREATE TABLE source (ID INTEGER PRIMARY KEY, email VARCHAR(63), uid VARCHAR(63), date_created DATE)');
    $this->db_reset = true;
    $this->db = $db;
  }

  protected function delete_image_from_db($sci_name) {
    $statement0 = $this->db->prepare('DELETE FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->execute();
  }

  protected function get_image_from_db($sci_name) {
    $statement0 = $this->db->prepare('SELECT sci_name, com_en_name, image_url, title, id, author_url, license_url, date_created FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  protected function set_image_in_db($sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url) {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO images VALUES (:sci_name, :com_en_name, :image_url, :title, :id, :author_url, :license_url, DATE(\"now\"))");
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->bindValue(':com_en_name', $com_en_name);
    $statement0->bindValue(':image_url', $image_url);
    $statement0->bindValue(':title', $title);
    $statement0->bindValue(':id', $id);
    $statement0->bindValue(':author_url', $author_url);
    $statement0->bindValue(':license_url', $license_url);
    $statement0->execute();
  }
}

class Flickr extends ImageProvider {

  protected $db_path = __ROOT__ . '/scripts/flickr.db';

  private $flickr_api_key = null;
  private $args = "&license=2%2C3%2C4%2C5%2C6%2C9&orientation=square,portrait";
  private $blacklisted_ids = [];
  private $licenses_urls = [];
  private $flickr_email = null;
  private $comnameprefix = "%20bird";

  public function __construct() {
    $this->set_db();

    $blacklisted = get_home() . "/BirdNET-Pi/scripts/blacklisted_images.txt";
    if (file_exists($blacklisted)) {
      $blacklisted_file = file($blacklisted);
      if ($blacklisted_file) {
        $this->blacklisted_ids = array_map('trim', $blacklisted_file);
      }
    }
    $this->flickr_api_key = get_config()["FLICKR_API_KEY"];
    $this->flickr_email = get_config()["FLICKR_FILTER_EMAIL"];
    $source = $this->get_uid_from_db();
    if ($source['email'] !== $this->flickr_email) {
      // reset the DB
      $this->db->exec("DROP TABLE images;");
      $this->create_tables();
      if (!empty($this->flickr_email)) {
        $source = $this->get_uid_from_db();
        if ($source['email'] !== $this->flickr_email) {
          $this->get_uid_from_flickr();
          $source = $this->get_uid_from_db();
        }
      } else {
        $this->set_uid_in_db("");
      }
    }
    if (!empty($this->flickr_email)) {
      $this->args = "&user_id=" . $source['uid'];
      $this->comnameprefix = "";
    }
  }

  public function get_image($sci_name) {
    $image = parent::get_image_from_db($sci_name);
    if ($image !== false && in_array($image['id'], $this->blacklisted_ids)) {
      $image = false;
      $this->delete_image_from_db($sci_name);
    }
    if ($image === false) {
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
    }
    if ($image === false)
      return false;
    // external link to photo
    $photos_url = str_replace('/people/', '/photos/', $image['author_url'] . '/' . $image['id']);
    $image['photos_url'] = $photos_url;
    return $image;
  }

  private function get_from_source($sci_name) {
    $engname = get_com_en_name($sci_name);

    $flickrjson = json_decode(file_get_contents("https://www.flickr.com/services/rest/?method=flickr.photos.search&api_key=" . $this->flickr_api_key . "&text=" . str_replace(" ", "%20", $engname) . $this->comnameprefix . "&sort=relevance" . $this->args . "&per_page=5&media=photos&format=json&nojsoncallback=1"), true)["photos"]["photo"];
    // could be null!!
    // Find the first photo that is not blacklisted or is not the specific blacklisted id
    $photo = null;
    foreach ($flickrjson as $flickrphoto) {
      if ($flickrphoto["id"] !== "4892923285" && !in_array($flickrphoto["id"], $this->blacklisted_ids)) {
        $photo = $flickrphoto;
        break;
      }
    }

    if ($photo === null)
      return;

    $license_response = $this->get_json("https://api.flickr.com/services/rest/?method=flickr.photos.getInfo&api_key=" . $this->flickr_api_key . "&photo_id=" . $photo["id"] . "&format=json&nojsoncallback=1");
    $license_id = $license_response["photo"]["license"];
    $license_url = $this->get_license_url($license_id);

    $authorlink = "https://flickr.com/people/" . $photo["owner"];
    $imageurl = 'https://farm' . $photo["farm"] . '.static.flickr.com/' . $photo["server"] . '/' . $photo["id"] . '_' . $photo["secret"] . '.jpg';

    $this->set_image_in_db($sci_name, $engname, $imageurl, $photo["title"], $photo["id"], $authorlink, $license_url);
  }

  private function get_license_url($id) {
    if (empty($this->licenses_urls)) {
      $licenses_url = "https://api.flickr.com/services/rest/?method=flickr.photos.licenses.getInfo&api_key=" . $this->flickr_api_key . "&format=json&nojsoncallback=1";
      $licenses_response = $this->get_json($licenses_url);
      $licenses_data = $licenses_response["licenses"]["license"];
      foreach ($licenses_data as $license) {
        $license_id = $license["id"];
        $license_url = $license["url"];
        $this->licenses_urls[$license_id] = $license_url;
      }
    }
    return $this->licenses_urls[$id];
  }

  public function get_uid_from_db() {
    $statement0 = $this->db->prepare('SELECT email, uid, date_created FROM source');
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  private function set_uid_in_db($uid) {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO source VALUES (1, :email, :uid, DATE(\"now\"))");
    $statement0->bindValue(':email', $this->flickr_email);
    $statement0->bindValue(':uid', $uid);
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  private function get_uid_from_flickr() {
    $uid = json_decode(file_get_contents("https://www.flickr.com/services/rest/?method=flickr.people.findByEmail&api_key=" . $this->flickr_api_key . "&find_email=" . $this->flickr_email . "&format=json&nojsoncallback=1"), true)["user"]["nsid"];
    $this->set_uid_in_db($uid);
  }
}

class Wikipedia extends ImageProvider {

  protected $db_path = __ROOT__ . '/scripts/wikipedia.db';

  protected function get_from_source($sci_name) {
    $page_title = str_replace(' ', '_', $sci_name);
    $data = $this->get_json("https://en.wikipedia.org/api/rest_v1/page/summary/$page_title");
    if ($data == false or !isset($data['originalimage']))
      return;

    $image_name = substr($data['originalimage']['source'], strrpos($data['originalimage']['source'], '/') + 1);
    $metadata = $this->get_json("https://commons.wikimedia.org/w/api.php?action=query&titles=File:$image_name&prop=imageinfo&iiprop=extmetadata|size&format=json");
    if ($metadata == false or !isset($metadata['query']['pages']))
      return;

    $image_url = $data['originalimage']['source'];
    $title = $data['title'];

    foreach ($metadata['query']['pages'] as $page) {
      $details = $page['imageinfo']['0']['extmetadata'];
      $author = $details['Artist']['value'];
      $matches = [];
      if (preg_match('/href="(http\S*)"/', $author, $matches)) {
        $author_url = $matches[1];
      } else {
        $author_url = $this->get_external_link($image_url);
      }
      if (isset($details['LicenseUrl'])) {
        $license_url = $details['LicenseUrl']['value'];
      } else {
        $license_url = $this->get_external_link($image_url);
      }
      if ($page["imageinfo"][0]["width"] > 1280) {
        $image_url = preg_replace('#/commons/#', '/commons/thumb/', $image_url) . '/1280px-'. $image_name;
      }
    }

    $engname = get_com_en_name($sci_name);

    //                     $sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url
    $this->set_image_in_db($sci_name, $engname, $image_url, $title, $sci_name, $author_url, $license_url);
  }

  public function get_image($sci_name) {
    $image = parent::get_image($sci_name);
    if ($image === false)
      return false;

    $image['photos_url'] = $this->get_external_link($image['image_url']);
    return $image;
  }

  private function get_external_link($image_url) {
    if (strpos($image_url, '/commons/thumb/') !== false) {
      $parts = explode('/', $image_url);
      $image_name = $parts[count($parts) - 2];
    } else {
      $image_name = substr($image_url, strrpos($image_url, '/') + 1);
    }
    $photo_url = "https://en.wikipedia.org/wiki/File:$image_name";
    return $photo_url;
  }
}

// WikiAves page of a Brazilian bird, from its CBRO name (pt_BR): "Sanhaço-cinzento" -> sanhaco-cinzento.
// Only for species of the Brazilian state lists (model/include_lists/BR-*.txt): the pt_BR file also
// names non-Brazilian birds (Portugal or English names), which have no WikiAves page.
function get_wikiaves_url($sciname) {
  static $brazilian = null, $cbro = null;
  if ($brazilian === null) {
    $brazilian = array();
    foreach (glob(__ROOT__ . '/model/include_lists/BR-*.txt') as $list) {
      foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $brazilian[explode('_', $line)[0]] = true;
      }
    }
    $cbro = json_decode((string)@file_get_contents(__ROOT__ . '/model/l18n/labels_pt_BR.json'), true) ?: array();
  }
  if (!isset($brazilian[$sciname]) || empty($cbro[$sciname])) return '';
  $slug = strtr(mb_strtolower($cbro[$sciname], 'UTF-8'), array('á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
    'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
    'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'));
  $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
  return $slug === '' ? '' : "https://www.wikiaves.com.br/wiki/$slug";
}

// Review loop: verdict of one detection file ('' = not reviewed), and the SQL that leaves rejected
// detections ("not this bird") out of a query on detections — empty while the table does not exist yet
function review_verdicts() {
  static $verdicts = null;
  if ($verdicts === null) {
    $verdicts = array();
    $db = get_db();
    if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")) {
      $res = $db->query('SELECT File_Name, Verdict FROM detection_reviews');
      while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $verdicts[$r[0]] = $r[1];
    }
  }
  return $verdicts;
}
// $onclick: JavaScript instead of the review modal (the standard list opens the review player)
function validate_button($file, $verdict = null, $positioned = false, $onclick = null) {
  if ($verdict === null) $verdict = review_verdict(basename($file));
  $labels = array('' => 'Review', 'yes' => '&#10003; Valid', 'no' => '&#10007; Not this bird', 'unsure' => "? Can't tell");
  $titles = array('' => 'Is this the bird? Review this detection', 'yes' => 'Reviewed: yes, this bird (species confirmed, clip protected from purge)',
                  'no' => 'Reviewed: not this bird', 'unsure' => "Reviewed: can't tell");
  return '<button type="button" class="validatebtn v-' . ($verdict ?: 'none') . ($positioned ? ' positioned' : '') . '" title="' . $titles[$verdict]
    . '" onclick="' . ($onclick !== null ? htmlspecialchars($onclick, ENT_QUOTES) : 'reviewDetection(' . htmlspecialchars(json_encode($file), ENT_QUOTES) . ', this)') . '">' . $labels[$verdict] . '</button>';
}
// Removed detections ("Remove detection", owner 2026-10-09): files in ~/BirdSongs/Extracted/Removed/<date>/<species>/,
// lines in deleted_detections, until they are deleted by hand in Species › Delete Removed
function deleted_dir() {
  return get_home() . '/BirdSongs/Extracted/Removed';
}
function deleted_table($rw) {
  $rw->exec("CREATE TABLE IF NOT EXISTS deleted_detections (Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, Com_Name VARCHAR(100) NOT NULL,
    Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, Overlap FLOAT, File_Name VARCHAR(100) NOT NULL, Deleted_At TEXT)");
}
// how many deleted detections wait for a wipe: one species, or every species when $sci is null
function deleted_count($sci = null) {
  $db = get_db();
  if (!$db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deleted_detections'")) return 0;
  if ($sci === null) return intval($db->querySingle('SELECT COUNT(*) FROM deleted_detections'));
  $st = $db->prepare('SELECT COUNT(*) FROM deleted_detections WHERE Sci_Name = :s');
  $st->bindValue(':s', $sci);
  $r = $st->execute()->fetchArray(SQLITE3_NUM);
  return intval($r[0]);
}

// The reviews table (created on first use; Reason added 2026-10-09 for the "not this bird" causes)
function review_table($rw) {
  $rw->exec("CREATE TABLE IF NOT EXISTS detection_reviews (File_Name VARCHAR(100) PRIMARY KEY, Sci_Name VARCHAR(100), Com_Name VARCHAR(100), Date DATE, Confidence FLOAT, Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')), Reviewed_At TEXT, Reason TEXT)");
  $has = false;
  $res = $rw->query("PRAGMA table_info(detection_reviews)");
  while ($res && ($c = $res->fetchArray(SQLITE3_ASSOC))) if ($c['name'] === 'Reason') $has = true;
  if (!$has) $rw->exec("ALTER TABLE detection_reviews ADD COLUMN Reason TEXT");
}

// Attributes of one item of a review list (review player, static/review-player.js)
// $sci: the scientific name; the player shows "Scientific - English (eBird / Clements)" under the title
// $row: the detection (Confidence, Cutoff, Sens, Overlap, Date): the player lists the analysis settings at the bottom
function review_item_attrs($file, $label, $sci = '', $row = null) {
  static $locked = null;
  $home = get_home();
  if ($locked === null) {
    $list = $home . '/BirdNET-Pi/scripts/disk_check_exclude.txt';
    $locked = is_file($list) ? array_flip(file($list, FILE_IGNORE_NEW_LINES)) : array();
  }
  $shifted = file_exists($home . '/BirdSongs/Extracted/By_Date/shifted/' . $file);
  // the clip's folder for the station File Manager (its root is /home): <user>/BirdSongs/Extracted/By_Date/<date>/<species>
  $dir = basename($home) . '/BirdSongs/Extracted/By_Date/' . dirname($file);
  return ' data-ri="1" data-file="' . htmlspecialchars($file, ENT_QUOTES) . '" data-clip="' . htmlspecialchars('/By_Date/' . ($shifted ? 'shifted/' : '') . $file, ENT_QUOTES)
    . '" data-label="' . htmlspecialchars($label, ENT_QUOTES) . '" data-dir="' . htmlspecialchars($dir, ENT_QUOTES) . '"'
    . ($sci !== '' ? ' data-sci="' . htmlspecialchars($sci, ENT_QUOTES) . '" data-en="' . htmlspecialchars(get_english_name($sci), ENT_QUOTES) . '"' : '')
    . ($row !== null && isset($row['Date']) ? ' data-when="' . htmlspecialchars($row['Date'] . (isset($row['Time']) ? ' ' . $row['Time'] : ''), ENT_QUOTES) . '"' : '')
    . ($row !== null && isset($row['Com_Name']) ? ' data-com="' . htmlspecialchars($row['Com_Name'], ENT_QUOTES) . '"' : '')
    . ($row !== null ? ' data-params="' . htmlspecialchars(json_encode(review_params($sci, $row)), ENT_QUOTES) . '"' : '')
    . ' data-locked="' . (isset($locked[$file]) ? 1 : 0) . '" data-shifted="' . ($shifted ? 1 : 0) . '"';
}

// The standard detection list (owner 2026-10-09): Now (list view) and the species page. $rows: detections with Date,
// Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, File_Name. A click on a row (or its Review button)
// opens the review player there; it goes on down the list. $with_species = false on a species' own page.
function detection_review_table($rows, $with_species = true) {
  static $profile = null;
  if ($profile === null) {
    $pj = is_file(__DIR__ . '/region_profile.json') ? json_decode(file_get_contents(__DIR__ . '/region_profile.json'), true) : null;
    $profile = $pj['data'] ?? array();
  }
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
  $lang_en = (get_config()['DATABASE_LANG'] ?? 'en') === 'en';
  $out = '<div class="reviewlist" data-review-list="1"><table class="list"><tr><th>Date</th><th>Time</th>'
    . ($with_species ? '<th>Species</th><th>Scientific name</th>' . ($lang_en ? '' : '<th>English name</th>') : '')
    . '<th class="num">Confidence</th><th class="num" title="Minimum confidence in force when it was detected">Min. confidence</th>'
    . '<th class="num" title="Location model probability of the species in that week">Model probability</th>'
    . '<th class="num">Sens. / overlap</th><th class="rv">Review</th></tr>';
  foreach ($rows as $g) {
    $folder = str_replace("'", '', str_replace(' ', '_', $g['Com_Name']));
    $file = $g['Date'] . '/' . $folder . '/' . $g['File_Name'];
    $t = strtotime($g['Date']);
    $w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
    $prob = isset($profile[$g['Sci_Name']]) ? number_format($profile[$g['Sci_Name']][$w - 1] * 100, 1) . '%' : '—';
    $label = $g['Com_Name'] . ' · ' . $g['Date'] . ' ' . $g['Time'] . ' · ' . round($g['Confidence'] * 100) . '%';
    $species = '';
    if ($with_species) {
      $en = $lang_en ? '' : get_english_name($g['Sci_Name']);
      $species = '<td><a href="views.php?view=Bird&amp;sci=' . rawurlencode($g['Sci_Name']) . '" title="Open the species page">' . $h($g['Com_Name']) . '</a></td>'
        . '<td><i>' . $h($g['Sci_Name']) . '</i></td>' . ($lang_en ? '' : '<td>' . $h($en) . '</td>');
    }
    $out .= '<tr class="rvrow"' . review_item_attrs($file, $label, $g['Sci_Name'], $g) . ' onclick="if (!event.target.closest(\'a,button\')) openReviewPlayer(this)" title="Listen and review">'
      . '<td class="nw">' . $h($g['Date']) . '</td><td class="nw">' . $h($g['Time']) . '</td>' . $species
      . '<td class="num">' . round($g['Confidence'] * 100) . '%</td>'
      . '<td class="num">' . ($g['Cutoff'] !== null ? round($g['Cutoff'] * 100) . '%' : '') . '</td>'
      . '<td class="num">' . $prob . '</td>'
      . '<td class="num">' . $h(($g['Sens'] ?? '') . ' / ' . ($g['Overlap'] ?? '')) . '</td>'
      . '<td class="rv">' . validate_button($file, null, false, 'openReviewPlayer(this)') . '</td></tr>';
  }
  return $out . '</table></div>';
}

// Analysis settings of one detection for the review player: confidence, the minimum confidence it was detected
// with, the species override (US-48), sigmoid sensitivity, overlap, the location threshold (station setting, current)
// and the location model probability of the species in that week
function review_params($sci, $row) {
  static $over = null, $profile = null;
  if ($over === null) {
    $over = array();
    foreach (@file(get_home() . '/BirdNET-Pi/species_confidence.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
      $p = explode('=', trim($l), 2);
      if (count($p) === 2) $over[$p[0]] = $p[1];
    }
    $pj = is_file(__DIR__ . '/region_profile.json') ? json_decode(file_get_contents(__DIR__ . '/region_profile.json'), true) : null;
    $profile = $pj['data'] ?? array();
  }
  $pct = function ($v) { return ($v === null || $v === '') ? '—' : round(floatval($v) * 100) . '%'; };
  $t = strtotime($row['Date'] ?? date('Y-m-d'));
  $w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
  $sf = get_config()['SF_THRESH'] ?? '';
  // in the order the decision is made: the score and the confidence thresholds it had to pass, then the location
  // filter (model probability vs threshold), then the analysis settings that produced the score
  return array(
    'Confidence' => $pct($row['Confidence'] ?? null),
    'Min. confidence' => $pct($row['Cutoff'] ?? null),
    'Species override' => isset($over[$sci]) ? $pct($over[$sci]) : 'none',
    'Model probability' => isset($profile[$sci]) ? number_format($profile[$sci][$w - 1] * 100, 1) . '%' : '—',
    'Location threshold' => $sf === '' ? '—' : round(floatval($sf) * 100) . '%',
    'Sigmoid sensitivity' => (string)($row['Sens'] ?? '—'),
    'Overlap' => ($row['Overlap'] ?? '') === '' ? '—' : $row['Overlap'] . ' s',
  );
}

function review_verdict($file_name) {
  return review_verdicts()[$file_name] ?? '';
}
function not_rejected_sql() {
  static $sql = null;
  if ($sql === null) {
    $sql = get_db()->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")
      ? " AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')" : '';
  }
  return $sql;
}

// Species links shown next to every scientific name (owner 2026-10-08): WikiAves first when the names are
// CBRO (Portuguese Brazil) and the bird is Brazilian, then eBird and Birds of the World
// Action icons of one detection (delete, change species, protect from purge, frequency shift), the same
// ones the Recordings page shows, so a card does not need the "open in new tab" detour (owner 2026-10-08).
// $file = "<date>/<common name>/<file>"; $positioned = the absolute top-right layout of the cards.
function detection_actions($file, $positioned = true, $style = '', $width = 25, $with_review = true) {
  static $locked = null;
  $home = get_home();
  if ($locked === null) {
    $list = $home . '/BirdNET-Pi/scripts/disk_check_exclude.txt';
    $locked = is_file($list) ? array_flip(file($list, FILE_IGNORE_NEW_LINES)) : array();
  }
  $verdict = review_verdict(basename($file));
  $f = htmlspecialchars(json_encode($file), ENT_QUOTES);
  $lock = isset($locked[$file])
    ? array('del', 'images/lock.svg', 'This file is excluded from being purged.')
    : array('add', 'images/unlock.svg', 'This file will be deleted when disk space needs to be freed (>95% usage).');
  $shift = file_exists($home . '/BirdSongs/Extracted/By_Date/shifted/' . $file)
    ? array('unshift', 'images/unshift.svg', 'This file has been shifted down in frequency.')
    : array('shift', 'images/shift.svg', 'This file is not shifted in frequency.');
  $icons = array(
    array("deleteDetection($f)", 'images/delete.svg', 'Delete Detection', '190px'),
    array("changeDetection($f)", 'images/bird.svg', 'Change Detection', '155px'),
    array("toggleLock($f, &quot;$lock[0]&quot;, this)", $lock[1], $lock[2], '115px'),
    array("toggleShiftFreq($f, &quot;$shift[0]&quot;, this)", $shift[1], $shift[2], '80px'),
  );
  // Review loop: a narrow "Review" button (owner 2026-10-08), its label and colour show the verdict; the last
  // button on the right of the action row
  $html = '';
  foreach ($icons as $i) {
    $css = 'cursor:pointer;' . ($positioned && $i[3] !== '' ? "right:$i[3];" : '') . $style;
    $html .= "<img style=\"$css\" src=\"$i[1]\" onclick=\"$i[0]\"" . ($positioned ? ' class="copyimage"' : '')
      . " width=\"$width\" title=\"" . htmlspecialchars($i[2], ENT_QUOTES) . '"> ';
  }
  return $html . ($with_review ? validate_button($file, $verdict, $positioned) : '');
}

// for the birds eBird knows, and Wikipedia in the station language. $style/$width are the page's icon style.
// The species-page icon (a green "ID card" with a bird) shown before every common name: one click opens the
// species page — totals, calendar, best clips, settings, reviews (owner 2026-10-08)
function species_icon($sciname, $size = 20) {
  return '<a class="spicon" href="views.php?view=Bird&amp;sci=' . rawurlencode($sciname) . '" title="Species page: totals, calendar, best clips, settings">'
    . '<img src="images/species-page.svg" style="width:' . intval($size) . 'px;height:' . intval($size) . 'px" alt="Species page"></a>';
}

// Species title block (owner 2026-10-08): [species-page icon] [common name / scientific name] [links], the
// WikiAves / eBird / Birds of the World / Wikipedia icons beside the names at the height of both lines.
// $name_html = the common name as the page renders it (a link, a button...); $extra = more icons for the link row.
// $english = true: a third line with the English (eBird/Clements) name when the station shows names in another language
function species_title($sciname, $name_html, $extra = '', $english = false, $icon = true) {
  $en = '';
  if ($english && (get_config()['DATABASE_LANG'] ?? 'en') !== 'en') {
    $en_name = get_english_name($sciname);
    if ($en_name !== '' && mb_strtolower($en_name) !== mb_strtolower(trim(strip_tags($name_html)))) {
      $en = '<br><span class="spen" title="English name (eBird / Clements)">' . htmlspecialchars($en_name) . '</span>';
    }
  }
  return '<div class="sptitle">' . ($icon ? species_icon($sciname, 26) : '') . '<div class="spnames">' . $name_html . '<br><i>'
    . htmlspecialchars($sciname) . '</i>' . $en . '</div><div class="splinks">' . species_links($sciname, '', 0) . $extra . '</div></div>';
}

// English (eBird / Clements) name of a species: the V3 model labels ("Sci_English", always English, whatever the
// station language), else the upstream English labels; '' when unknown
function get_english_name($sciname) {
  static $map = null;
  if ($map === null) {
    $map = array();
    $model = get_home() . '/BirdNET-Pi/model/';
    foreach (glob($model . 'BirdNET-Plus_*_Labels.txt') ?: array() as $f) {
      if (strpos($f, '_Geo_') !== false) continue;
      foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $p = explode('_', $l, 2);
        if (count($p) === 2 && !isset($map[$p[0]])) $map[$p[0]] = $p[1];
      }
    }
    if (!$map) {
      $en = json_decode((string)@file_get_contents($model . 'l18n/labels_en.json'), true);
      if (is_array($en)) $map = $en;
    }
  }
  return $map[$sciname] ?? '';
}

// Photo of a species from the image provider of the Settings (cached in its own database); false when none
function species_photo($sciname) {
  static $provider = null;
  $config = get_config();
  if (empty($config['IMAGE_PROVIDER'])) return false;
  if ($provider === null) $provider = ($config['IMAGE_PROVIDER'] === 'FLICKR') ? new Flickr() : new Wikipedia();
  try { return $provider->get_image($sciname); } catch (Throwable $e) { return false; }
}

function species_links($sciname, $style = '', $width = 20) {
  static $ebirds = null;
  if ($ebirds === null) {
    require __ROOT__ . '/scripts/ebird.php';
  }
  $config = get_config();
  $lang = $config['DATABASE_LANG'] ?? 'en';
  $links = array();
  if ($lang === 'pt_BR' && ($wikiaves = get_wikiaves_url($sciname)) !== '') {
    $links[] = array($wikiaves, 'WikiAves', 'images/wikiaves.png');
  }
  $code = $ebirds[$sciname] ?? '';
  if ($code !== '') {
    $links[] = array("https://ebird.org/species/$code?siteLanguage=$lang", 'eBird', 'images/ebird.png');
    $links[] = array("https://birdsoftheworld.org/bow/species/$code/cur/introduction", 'Birds of the World', 'images/bow.png');
  }
  $wiki_lang = explode('_', $lang)[0];
  $links[] = array("https://$wiki_lang.wikipedia.org/wiki/" . str_replace(' ', '_', $sciname), 'Wikipedia', 'images/wiki.png');
  $html = '';
  foreach ($links as $l) {
    $html .= '<a href="' . htmlspecialchars($l[0], ENT_QUOTES) . '" target="_blank"><img style="' . htmlspecialchars($style, ENT_QUOTES)
      . '" title="' . $l[1] . '" src="' . $l[2] . '"' . ($width ? ' width="' . intval($width) . '"' : '') . '></a> ';
  }
  return $html;
}

function get_info_url($sciname){
  $engname = get_com_en_name($sciname);
  $config = get_config();
  // WikiAves is offered only with CBRO names (Portuguese Brazil); other species fall back to eBird
  if ($config['INFO_SITE'] === 'WIKIAVES' && ($config['DATABASE_LANG'] ?? '') === 'pt_BR'
      && ($wikiaves = get_wikiaves_url($sciname)) !== '') {
    return array('URL' => $wikiaves, 'TITLE' => 'WikiAves');
  }
  if ($config['INFO_SITE'] === 'EBIRD' || $config['INFO_SITE'] === 'WIKIAVES'){
    require 'scripts/ebird.php';
    $ebird = $ebirds[$sciname] ?? '';
    $language = $config['DATABASE_LANG'];
    if ($ebird !== '') {
      $url = "https://ebird.org/species/$ebird?siteLanguage=$language";
      $url_title = "eBirds";
    } else {
      // not a bird on eBird (V3 also detects frogs, insects, mammals): Wikipedia in the station language
      $wiki_lang = explode('_', $language)[0];
      $url = "https://$wiki_lang.wikipedia.org/wiki/" . str_replace(' ', '_', $sciname);
      $url_title = "Wikipedia";
    }
  } else {
    $engname_url = str_replace("'", '', str_replace(' ', '_', $engname));
    $url = "https://allaboutbirds.org/guide/$engname_url";
    $url_title = "All About Birds";
  }
  $ret = array(
      'URL' => $url,
      'TITLE' => $url_title
          );
  return $ret;
}

function get_color_scheme(){
  $config = get_config();
  if (strtolower($config['COLOR_SCHEME']) === 'dark'){
    return 'static/dark-style.css';
  } else {
    return 'style.css';
  }
}
