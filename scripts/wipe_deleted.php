<?php
/* Purge Removed (owner 2026-10-09): detections removed anywhere in the interface ("Remove detection") wait in
 * ~/BirdSongs/Extracted/Removed (files) and deleted_detections (database lines) until they are deleted here for good —
 * all of them or one species. It always asks first, with the number of files per species.
 * Opened as views.php?view=Wipe. */
require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$rows = array();
if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deleted_detections'")) {
  $res = $db->query('SELECT Sci_Name, MAX(Com_Name) AS com, COUNT(*) AS n, MIN(Deleted_At) AS first, MAX(Deleted_At) AS last FROM deleted_detections GROUP BY Sci_Name ORDER BY n DESC');
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $r;
}
$total = array_sum(array_column($rows, 'n'));
$size = (int)trim((string)shell_exec('du -sb ' . escapeshellarg(deleted_dir()) . ' 2>/dev/null | cut -f1'));
?>
<style>
.wipe { max-width: 900px; margin: 0 auto; text-align: left; padding: 0 12px; }
.wipe button.wipebtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid #c62828; background: #fff; color: #c62828; font-weight: 600; cursor: pointer; }
.wipe button.wipebtn:hover { background: #c62828; color: #fff; }
.wipe a.folderbtn { margin-left: 8px; padding: 3px 10px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; text-decoration: none; white-space: nowrap; }
.wipe a.folderbtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.wipe button.restorebtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; }
.wipe button.restorebtn:hover { background: var(--accent,#2b5e22); color: #fff; }
</style>
<div class="wipe">
  <p>"Remove detection" takes a detection out of the BirdNET folders and statistics and moves it to the Removed folder.<br>
    Nothing is deleted automatically: deleting is manual and happens only here.</p>
  <p><b><?php echo number_format($total); ?></b> removed detections (<?php echo round($size / 1048576, 1); ?> MB) in
    <code>~/BirdSongs/Extracted/Removed</code>
    <a class="folderbtn" target="_blank" href="scripts/filemanager/filemanager.php?p=<?php echo rawurlencode(basename(get_home()) . '/BirdSongs/Extracted/Removed'); ?>" title="Opens the File Manager in the Removed folder (new tab)">&#128194; Open the Removed folder</a></p>
  <p>Restore puts them back where they were;<br>Delete removes their audio, spectrogram and database line for good.</p>
  <?php if (!$rows) { echo '<p>Nothing removed.</p>'; } else { ?>
  <p><button type="button" class="restorebtn" onclick="restoreRemoved('', this)">Restore all</button>
    <button type="button" class="wipebtn" onclick="wipeDeleted('', this)">Delete all removed detections</button></p>
  <table class="stdtable">
    <tr><th><select class="namemode" title="Names shown"><option value="com">Common name</option><option value="sci">Scientific name</option><option value="en">English name</option></select></th><th class="num">Files</th><th>Removed</th><th></th></tr>
    <?php foreach ($rows as $r) {
      echo '<tr><td>' . '<a href="views.php?view=Bird&amp;sci=' . rawurlencode($r['Sci_Name']) . '" data-com="' . $h($r['com']) . '" data-sci="' . $h($r['Sci_Name']) . '" data-en="' . $h(get_english_name($r['Sci_Name'])) . '">' . $h($r['com']) . '</a>' . '</td>'
        . '<td class="num">' . number_format($r['n']) . '</td><td>' . $h(substr($r['first'], 0, 16) . ($r['first'] !== $r['last'] ? ' … ' . substr($r['last'], 0, 16) : '')) . '</td>'
        . '<td style="text-align:right !important;white-space:nowrap"><button type="button" class="restorebtn" onclick="restoreRemoved(' . $h(json_encode($r['Sci_Name'])) . ', this)">Restore</button> '
        . '<button type="button" class="wipebtn" onclick="wipeDeleted(' . $h(json_encode($r['Sci_Name'])) . ', this)">Delete</button></td></tr>';
    } ?>
  </table>
  <?php } ?>
</div>
<script src="static/species-modal.js"></script>
<script src="static/wipe-deleted.js"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
