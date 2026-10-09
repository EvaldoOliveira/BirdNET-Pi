<?php
/* Delete Excluded (owner 2026-10-09): detections excluded anywhere in the interface wait in
 * ~/BirdSongs/Extracted/Excluded (files) and deleted_detections (database lines) until they are deleted here for good —
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
.wipe table { width: 100%; border-collapse: collapse; background: rgba(255,255,255,.55); border-radius: 8px; }
.wipe th { text-align: left !important; padding: 5px 6px; border-bottom: 2px solid rgba(128,128,128,.4); font-size: .9em; }
.wipe td { text-align: left !important; padding: 4px 6px; border-top: 1px solid rgba(128,128,128,.2); }
.wipe td.num, .wipe th.num { text-align: right !important; }
.wipe button.wipebtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid #c62828; background: #fff; color: #c62828; font-weight: 600; cursor: pointer; }
.wipe button.wipebtn:hover { background: #c62828; color: #fff; }
.wipe button.restorebtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid #2b5e22; background: #fff; color: #2b5e22; font-weight: 600; cursor: pointer; }
.wipe button.restorebtn:hover { background: #2b5e22; color: #fff; }
</style>
<div class="wipe">
  <h2>&#128465; Delete Excluded</h2>
  <p>Excluded detections are kept until they are deleted here: <b><?php echo number_format($total); ?></b> detections
    (<?php echo round($size / 1048576, 1); ?> MB) in <code>~/BirdSongs/Extracted/Excluded</code>.
    Deleting removes their audio, spectrogram and database line for good.</p>
  <?php if (!$rows) { echo '<p>Nothing excluded.</p>'; } else { ?>
  <p><button type="button" class="restorebtn" onclick="restoreExcluded('', this)">Restore all</button>
    <button type="button" class="wipebtn" onclick="wipeDeleted('', this)">Delete all excluded detections</button></p>
  <table>
    <tr><th>Species</th><th>Scientific name</th><th class="num">Files</th><th>Excluded</th><th></th></tr>
    <?php foreach ($rows as $r) {
      echo '<tr><td><a href="views.php?view=Bird&amp;sci=' . rawurlencode($r['Sci_Name']) . '">' . $h($r['com']) . '</a></td><td><i>' . $h($r['Sci_Name']) . '</i></td>'
        . '<td class="num">' . number_format($r['n']) . '</td><td>' . $h(substr($r['first'], 0, 16) . ($r['first'] !== $r['last'] ? ' … ' . substr($r['last'], 0, 16) : '')) . '</td>'
        . '<td style="text-align:right !important;white-space:nowrap"><button type="button" class="restorebtn" onclick="restoreExcluded(' . $h(json_encode($r['Sci_Name'])) . ', this)">Restore</button> '
        . '<button type="button" class="wipebtn" onclick="wipeDeleted(' . $h(json_encode($r['Sci_Name'])) . ', this)">Delete excluded</button></td></tr>';
    } ?>
  </table>
  <?php } ?>
</div>
<script src="static/species-modal.js"></script>
<script src="static/wipe-deleted.js"></script>
