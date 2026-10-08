<?php
    error_reporting(E_ALL);
    ini_set('display_errors',1);

    if ($species_list=="include") {
        $title="Included";
        $message="Warning!<br>If this list contains ANY species, the system will ONLY recognize those species. Keep this list EMPTY unless you are ONLY interested in detecting specific species.";
        $selectedfilename = './scripts/include_species_list.txt';
    } elseif ($species_list=="exclude") {
        $title="Excluded";
        $message="Once the desired species has been highlighted, click it and then click ADD to have it excluded.";
        $selectedfilename = './scripts/exclude_species_list.txt';
    } elseif ($species_list=="whitelist") {
        $title="Whitelisted";
        $message="Once the desired species has been highlighted, click it and then click ADD to have it whitelisted. This species will be detected even if below the Species Occurrence Frequency Threshold defined in the settings.<br>This is not a recommended way of working : it is preferable to first try first both Species Occurrence models (v1 and v2.4).";
        $selectedfilename = './scripts/whitelist_species_list.txt';   
    }
    

    if (file_exists($selectedfilename)) {
        $eachselected = file($selectedfilename, FILE_IGNORE_NEW_LINES);
    }
    else {
        $eachselected = [];
    }
    $filename = './scripts/labels.txt';
    $eachlabel = file($filename, FILE_IGNORE_NEW_LINES);
?>


<meta name="viewport" content="width=device-width, initial-scale=1">

<div class="left-column">
<?php echo $message ?>
</div>
<?php if ($species_list == "include") {
  // station species lists (owner 2026-10-08): which one is active, save it under a name or load another
  $real = (string)realpath($selectedfilename);
  $active = strpos($real, '/species_lists/') !== false ? basename($real, '.txt') : '';
?>
<div class="left-column">
  <p>Active list: <b><?php echo $active !== '' ? htmlspecialchars($active) : 'none (Settings › Location › Species list filter)'; ?></b></p>
            <input type="text" id="save_list_name" placeholder="name" style="width:10em">
            <button type="button" class="testbtn" onclick="speciesListAction('save')">Save current list as…</button>
            <br>
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
            <button type="button" class="testbtn" onclick="speciesListAction('activate')">Load this list</button>
            <script>
              function speciesListAction(action) {
                const fd = new FormData();
                fd.append('action', action);
                if (action === 'save') {
                  const n = document.getElementById('save_list_name').value.trim();
                  if (!/^[A-Za-z0-9_-]+$/.test(n)) { alert('Name: letters, digits, - and _ only'); return; }
                  fd.append('name', n);
                } else if (action === 'activate') {
                  const sel = document.getElementById('station_lists').value;
                  if (!sel) return;
                  fd.append('name', sel);
                }
                fetch('scripts/species_lists.php', { method: 'POST', body: fd }).then(r => r.text()).then(t => { alert(t); location.reload(); });
              }
            </script>
</div>
<?php } ?>

<div class="customlabels column1">
<form action="" method="GET" id="add">
  <h3>All Species Labels</h3>
  <input autocomplete="off" size="28" type="text" placeholder="Search Species..." id="species_searchterm" name="species_searchterm">
  <select name="species[]" id="species" multiple size="25">
    <?php
    foreach($eachlabel as $lines){echo
    "<option value=\"".$lines."\">$lines</option>";
    } ?>
  </select>
  <input type="hidden" name="add" value="add">
</form>
<div class="customlabels smaller">
  <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">>>ADD>></button>
</div>
</div>

<div class="customlabels column2">
  <table><td>
  <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">>>ADD>></button>
  <br><br>
  <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del">REMOVE</button>
  </td></table>
</div>

<div class="customlabels column3">
<form action="" method="GET" id="del">
  <h3><?php echo "$title" ?> Species List</h3>
  <input style="visibility:hidden" autocomplete="off" size="18" type="text" id="dummy" name="dummy">
  <select name="species[]" id="value2" multiple size="25">
  <?php
  if (count($eachselected) == 0) echo '<option disabled value="base">Please Select</option>';
  foreach($eachselected as $lines){echo
    "<option value=\"".$lines."\">$lines</option>";
  } ?>
  </select>
  <input type="hidden" name="del" value="del">
</form>
<div class="customlabels smaller">
  <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del">REMOVE</button>
</div>
</div>

<script>
    // Store the original list of options in a variable
    var originalOptions = {};

    document.getElementById("add").addEventListener("submit", function(event) {
      var speciesSelect = document.getElementById("species");
      if (speciesSelect.selectedIndex < 0) {
        alert("Please click the species you want to add.");
        document.querySelector('.views').style.opacity = 1;
        event.preventDefault();
      }
    });

    var search_term = document.querySelector("input#species_searchterm");
    search_term.addEventListener("keyup", function() {
      filterOptions("species");
    });

    // Function to filter options in a select element
    function filterOptions(id) {
      var input = document.getElementById("species_searchterm");
      var filter = input.value.toUpperCase();
      var select = document.getElementById(id);
      var options = select.getElementsByTagName("option");

      // If the original list of options for this select element hasn't been stored yet, store it
      if (!originalOptions[id]) {
        originalOptions[id] = Array.from(options).map(option => option.value);
      }

      // Clear the select element
      while (select.firstChild) {
        select.removeChild(select.firstChild);
      }

      // Populate the select element with the filtered labels
      originalOptions[id].forEach(label => {
        if (label.toUpperCase().indexOf(filter) > -1) {
          let option = document.createElement('option');
          option.value = label;
          option.text = label;
          select.appendChild(option);
        }
      });
    }
</script>
