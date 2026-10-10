<?php
    error_reporting(E_ALL);
    ini_set('display_errors',1);

    // $title is the view name (the ADD / REMOVE forms post it); $label is what the page shows (menu names, owner 2026-10-09)
    if ($species_list=="include") {
        $title="Included";
        $label="Custom Species";
        $message="Warning!<br>If this list contains ANY species, the system will ONLY recognize those species. Keep this list EMPTY unless you are ONLY interested in detecting specific species.";
        $selectedfilename = './scripts/include_species_list.txt';
    } elseif ($species_list=="exclude") {
        $title="Excluded";
        $label="Excluded Species";
        $message="Once the desired species has been highlighted, click it and then click ADD to have it excluded.";
        $selectedfilename = './scripts/exclude_species_list.txt';
    } elseif ($species_list=="whitelist") {
        $title="Whitelisted";
        $label="Whitelisted Species";
        $message="Once the desired species has been highlighted, click it and then click ADD to have it whitelisted.<br>This species will be detected even if below the Species Occurrence Frequency Threshold defined in the settings.<br>This is not a recommended way of working: it is preferable to try both Species Occurrence models (v1 and v2.4) first.";
        $selectedfilename = './scripts/whitelist_species_list.txt';   
    }
    

    if (file_exists($selectedfilename)) {
        $eachselected = file($selectedfilename, FILE_IGNORE_NEW_LINES);
    }
    else {
        $eachselected = [];
    }
    // Available Species (owner 2026-10-09): what the active model can detect minus the species already in this list,
    // so a species added moves from the left to the right (and back when removed)
    $in_list = array();
    foreach ($eachselected as $l) $in_list[explode('_', trim($l), 2)[0]] = true;
    $eachlabel = array();
    foreach (active_model_labels() as $sci => $name) {
      if (!isset($in_list[$sci])) $eachlabel[] = $sci . '_' . $name;
    }
    sort($eachlabel, SORT_STRING);
    // groups from species_info.csv; Noise and None removed (owner 2026-10-09): the V3 model has no noise labels (only the
    // V2.4 ones, kept in old lists) and every V3 label has a type, so both stayed empty — such labels show under All
    // region (owner 2026-10-09, back with the complete model/species_info.csv): continents with >= 3 % of the GBIF
    // records, Global = four or more continents (matches every region), Ocean = mostly records at sea
    $regions = array('' => 'Any region', 'Africa' => 'Africa', 'Asia' => 'Asia', 'Europe' => 'Europe', 'North America' => 'North America',
      'South America' => 'South America', 'Oceania' => 'Oceania', 'Antarctica' => 'Antarctica', 'Ocean' => 'Ocean');
    $groups = array('' => 'All', 'birds' => 'Birds', 'mammals' => 'Mammals', 'amphibians' => 'Amphibians', 'insects' => 'Insects', 'domestic' => 'Domestic');
    $option = function ($line) {
      $v = htmlspecialchars($line, ENT_QUOTES);
      $sci = explode('_', $line, 2)[0];
      return '<option value="' . $v . '" data-g="' . species_group($sci) . '" data-r="' . htmlspecialchars(species_info($sci)[1], ENT_QUOTES) . '">' . $v . '</option>';
    };
?>


<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
  .splist { width: 90%; margin: 10px auto; }
  .splist-row { display: flex; align-items: center; gap: 2%; }
  .splist-box { flex: 1 1 0; min-width: 0; }
  .splist-box form { width: 100%; }
  .splist-box select { width: 100%; height: 60vh; }
  .splist-box input[type=text] { width: 100%; box-sizing: border-box; }
  .splist-mid { flex: 0 0 auto; display: flex; flex-direction: column; gap: 20px; }
  .splist button { padding: 12px; background-color: var(--panel,#dbffeb); }
  .splist-note { margin-top: 14px; text-align: left; }
  .splist-tools { margin-top: 14px; display: grid; grid-template-columns: max-content max-content; justify-content: start; gap: 8px 10px; align-items: center; }
  .splist-tools button { padding: 6px 12px; text-align: left; }
  .splist .smaller { display: none; }
  .splist-source { font-size: 12px; opacity: .8; }
  .splist-groups { margin: 4px 0 8px; display: flex; gap: 6px; flex-wrap: wrap; }
  .splist .splist-groups button { padding: 4px 12px; background: #fff; }
  .splist .splist-groups select { padding: 3px 6px; border-radius: 10px; font-size: 13px; width: auto; height: auto; }
  .splist .splist-groups button.active { background: var(--accent,#2b5e22); color: #fff; }
  .splist h3 small { font-weight: normal; font-size: 12px; color: #444; }
  @media screen and (max-width: 1000px) {
    .splist-mid { display: none; }
    .splist .smaller { display: block; margin-top: 6px; }
  }
</style>
<?php
$active = '';
if ($species_list == "include") {
  // station species lists (owner 2026-10-08): which one is active, save it under a name or load another
  $real = (string)realpath($selectedfilename);
  $active = strpos($real, '/species_lists/') !== false ? basename($real, '.txt') : '';
}
?>
<div class="splist">
<div class="splist-groups nowmodes" title="Show only one group of species in both lists">
  <?php foreach ($groups as $g => $n) echo '<button type="button" data-g="' . $g . '"' . ($g === '' ? ' class="active"' : '') . ' onclick="groupFilter(this.dataset.g)">' . $n . '</button>'; ?>
  <select id="region_filter" onchange="regionFilter(this.value)" title="Where the species lives (GBIF records: continents with at least 3 % of them); Global species match every region; species without data only with Any region">
    <?php foreach ($regions as $r => $n) echo '<option value="' . $r . '">' . $n . '</option>'; ?>
  </select>
</div>
<div class="splist-row">
  <div class="splist-box">
    <form action="views.php?view=<?php echo rawurlencode($title); ?>" method="POST" id="add">
      <h3>Available Species <small id="avail_count"></small></h3>
      <input autocomplete="off" type="text" placeholder="Search Species..." id="species_searchterm" name="species_searchterm">
      <select name="species[]" id="species" multiple size="25">
        <?php
        foreach ($eachlabel as $lines) echo $option($lines); ?>
      </select>
      <input type="hidden" name="add" value="add"><input type="hidden" name="species_lines">
    </form>
    <div class="smaller">
      <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">>>ADD>></button>
    </div>
  </div>

  <div class="splist-mid">
    <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">>>ADD>></button>
    <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del">REMOVE</button>
  </div>

  <div class="splist-box">
    <form action="views.php?view=<?php echo rawurlencode($title); ?>" method="POST" id="del">
      <h3><?php echo $label; ?><?php if ($active !== '') echo ' — ' . htmlspecialchars($active); ?> <small id="list_count"></small></h3>
      <input autocomplete="off" type="text" placeholder="Search Species..." id="list_searchterm">
      <select name="species[]" id="value2" multiple size="25">
      <?php
      if (count($eachselected) == 0) echo '<option disabled value="base">Please Select</option>';
      foreach ($eachselected as $lines) echo $option($lines); ?>
      </select>
      <input type="hidden" name="del" value="del"><input type="hidden" name="species_lines">
    </form>
    <div class="smaller">
      <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del">REMOVE</button>
    </div>
  </div>
</div>

<div class="splist-note"><?php echo $message ?></div>
<div class="splist-note splist-source">Groups (birds, mammals, amphibians, insects, domestic) and regions come from a formal reference:
  <a href="https://www.gbif.org" target="_blank">GBIF</a> (Global Biodiversity Information Facility) — the GBIF Backbone Taxonomy for the class of each label and
  GBIF occurrence records for the continents (at least 3 % of the records; Global = four or more continents; Ocean = mostly records at sea). Table: model/species_info.csv.</div>

<?php if ($species_list == "include") { ?>
<div class="splist-tools">
  <span>Active list:</span>
  <b><?php echo $active !== '' ? htmlspecialchars($active) : 'none (Settings › Location › Species list filter)'; ?></b>
  <button type="button" onclick="speciesListAction('save')">Save current list as…</button>
  <input type="text" id="save_list_name" placeholder="name" style="width:14em">
  <button type="button" onclick="speciesListAction('activate')">Load this list</button>
  <select id="station_lists">
    <?php
    $own = array();
    foreach (glob(dirname(__DIR__) . '/species_lists/*.txt') as $f) { $own[] = basename($f, '.txt'); }
    echo "<optgroup label='Station lists'>";
    foreach ($own as $n) {
      echo '<option value="' . htmlspecialchars($n, ENT_QUOTES) . '"' . ($n === $active ? ' selected' : '') . '>' . htmlspecialchars($n) . '</option>';
    }
    echo "</optgroup><optgroup label='Brazilian states (built when loaded)'>";
    foreach (glob(dirname(__DIR__) . '/model/include_lists/BR-*.txt') as $f) {
      $n = basename($f, '.txt');
      if (!in_array($n, $own, true)) echo '<option value="' . $n . '">' . $n . '</option>';
    }
    echo "</optgroup>";
    ?>
  </select>
</div>
<script>
  function speciesListAction(action) {
    const fd = new FormData();
    fd.append('action', action);
    if (action === 'save') {
      const n = document.getElementById('save_list_name').value.trim();
      if (!/^[A-Za-z0-9_-]+$/.test(n)) { alert('Name: letters, digits, - and _ only'); return; }
      fd.append('name', n);
    } else {
      const sel = document.getElementById('station_lists').value;
      if (!sel) return;
      fd.append('name', sel);
    }
    fetch('scripts/species_lists.php', { method: 'POST', body: fd }).then(r => r.text()).then(t => { alert(t); location.reload(); });
  }
</script>
<?php } ?>
</div>

<script>
    // the selected species travel as one field (newline-separated, POST): hundreds of them used to exceed the address
    // length of the old GET form and only part of a big selection was added or removed (owner 2026-10-09)
    ['add', 'del'].forEach(function (id) {
      document.getElementById(id).addEventListener('submit', function (event) {
        var sel = this.querySelector('select');
        var picked = Array.prototype.filter.call(sel.options, function (o) { return o.selected && !o.hidden && !o.disabled; }).map(function (o) { return o.value; });
        if (!picked.length) { alert('Please click the species first.'); document.querySelector('.views').style.opacity = 1; event.preventDefault(); return; }
        this.querySelector('input[name=species_lines]').value = picked.join('\n');
        sel.disabled = true;   // not sent as species[] any more
      });
    });

    // the search box (left list) and the group buttons (both lists) hide the options that do not match
    var groupNow = '', regionNow = '';
    function regionFilter(r) {
      regionNow = r;
      try { localStorage.setItem('splist_region', r); } catch (e) {}
      applyFilters();
    }
    function applyFilters() {
      var qs = {species: document.getElementById('species_searchterm').value.toUpperCase(),
                value2: document.getElementById('list_searchterm').value.toUpperCase()};
      // the group and region filters act on both lists, each list has its own search box
      [['species', 'avail_count'], ['value2', 'list_count']].forEach(function (x) {
        var q = qs[x[0]];
        var shown = 0, total = 0;
        document.querySelectorAll('#' + x[0] + ' option[data-g]').forEach(function (o) {
          total++;
          var reg = o.dataset.r || '';
          var ok = (!groupNow || o.dataset.g === groupNow) && o.value.toUpperCase().indexOf(q) > -1
            && (!regionNow || reg === 'Global' || reg.split('+').indexOf(regionNow) > -1);
          o.hidden = !ok;
          if (!ok) o.selected = false; else shown++;
        });
        document.getElementById(x[1]).textContent = '(' + (shown === total ? total : shown + ' of ' + total) + ')';
      });
    }
    function groupFilter(g) {
      groupNow = g;
      document.querySelectorAll('.splist-groups button').forEach(function (b) { b.classList.toggle('active', b.dataset.g === g); });
      try { localStorage.setItem('splist_group', g); } catch (e) {}
      applyFilters();
    }
    document.getElementById('species_searchterm').addEventListener('input', applyFilters);
    document.getElementById('list_searchterm').addEventListener('input', applyFilters);
    try {
      regionNow = localStorage.getItem('splist_region') || '';
      document.getElementById('region_filter').value = regionNow;
      var g = localStorage.getItem('splist_group') || '';
      groupFilter(document.querySelector('.splist-groups button[data-g="' + g + '"]') ? g : '');
    } catch (e) { applyFilters(); }
</script>
