<?php
/* Auto Locate Now (owner 2026-10-10): runs scripts/location_check.py at once (as the station user, even when the boot
 * check is off) and answers its result as JSON — network position, place, distance to the saved coordinates. The
 * Station Setup and Basic Settings pages put the position in their Latitude / Longitude fields; saving stays yours. */
require_once __DIR__ . '/common.php';
ensure_authenticated();
header('Content-Type: application/json');
$out = shell_exec('sudo -u ' . escapeshellarg(get_user()) . ' python3 ' . escapeshellarg(get_home() . '/BirdNET-Pi/scripts/location_check.py')
  . ' --force --json 2>/dev/null');
$line = trim((string)$out);
echo $line !== '' ? substr($line, strrpos("\n" . $line, "\n")) : json_encode(array('error' => 'no answer'));
