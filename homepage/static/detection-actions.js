// Detection actions shared by the Recordings, Today's Detections and Overview pages: delete, change the
// species, protect from purge (lock) and frequency shift — all through play.php (owner 2026-10-08).
function deleteDetection(filename,copylink=false) {
  if (confirm("Are you sure you want to delete this detection from the database?") == true) {
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.responseText == "OK"){
        if(copylink == true) {
          window.top.close();
        } else {
          location.reload();
        }
      } else {
        alert(this.responseText);
      }
    }
    xhttp.open("GET", "play.php?deletefile="+filename, true);
    xhttp.send();
  }
}

function toggleLock(filename, type, elem) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText == "OK"){
      if(type == "add") {
        elem.setAttribute("src","images/lock.svg");
        elem.setAttribute("title", "This file is excluded from being purged.");
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("add","del"));
      } else {
        elem.setAttribute("src","images/unlock.svg");
        elem.setAttribute("title", "This file will be deleted when disk space needs to be freed.");
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("del","add"));
      }
    }
  }
  if(type == "add") {
    xhttp.open("GET", "play.php?excludefile="+filename+"&exclude_add=true", true);
  } else {
    xhttp.open("GET", "play.php?excludefile="+filename+"&exclude_del=true", true);  
  }
  xhttp.send();
  elem.setAttribute("src","images/spinner.gif");
}

function toggleShiftFreq(filename, shiftAction, elem) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText == "OK"){
      if(shiftAction == "shift") {
        elem.setAttribute("src","images/unshift.svg");
        elem.setAttribute("title", "This file has been shifted down in frequency.");
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("shift","unshift"));
	console.log("shifted freqs of " + filename);
        const audioDiv = elem.parentNode.querySelector(".custom-audio-player");
        if (audioDiv) {
          audioDiv.setAttribute("data-audio-src", audioDiv.getAttribute("data-audio-src").replace("/By_Date/", "/By_Date/shifted/"));
        } else {
          const atag = elem.parentNode.querySelector("a");
          if (atag) {
            atag.setAttribute("href", atag.getAttribute("href").replace("/By_Date/", "/By_Date/shifted/"));
          }
        }
      } else {
        elem.setAttribute("src","images/shift.svg");
        elem.setAttribute("title", "This file is not shifted in frequency.");
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("unshift","shift"));
        console.log("unshifted freqs of " + filename);
        const audioDiv = elem.parentNode.querySelector(".custom-audio-player");
        if (audioDiv) {
          audioDiv.setAttribute("data-audio-src", audioDiv.getAttribute("data-audio-src").replace("/By_Date/shifted/", "/By_Date/"));
        } else {
          const atag = elem.parentNode.querySelector("a");
          if (atag) {
            atag.setAttribute("href", atag.getAttribute("href").replace("/By_Date/shifted/", "/By_Date/"));
          }
        }
      }
    }
  }
  if(shiftAction == "shift") {
    console.log("shifting freqs of " + filename);
    xhttp.open("GET", "play.php?shiftfile="+filename+"&doshift=true", true);
  } else {
    console.log("unshifting freqs of " + filename);
    xhttp.open("GET", "play.php?shiftfile="+filename, true);  
  }
  xhttp.send();
  elem.setAttribute("src","images/spinner.gif");
}

function changeDetection(filename,copylink=false) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    const labels = JSON.parse(this.responseText);
    let dropdown = '<input type="text" id="filterInput" placeholder="Type to filter..."> <button id="cancelButton">Cancel</button> <br><select id="labelDropdown" class="testbtn" size="5" style="display: block; margin: 0 auto;"></select>';

	// Check if the modal already exists
    let modal = document.getElementById('myModal');
    if (!modal) {
      // Create a modal box
      modal = document.createElement('div');
      modal.setAttribute('id', 'myModal');
      modal.setAttribute('class', 'modal');

      // Create a content box
      let content = document.createElement('div');
      content.setAttribute('class', 'modal-content');

      // Add a title to the modal box
      let title = document.createElement('h2');
      title.textContent = 'Please select the correct species here:';
      content.appendChild(title);

      // Add the dropdown to the content
      let selectElement = document.createElement('div');
      selectElement.innerHTML = dropdown;
      content.appendChild(selectElement);

      // Append the content to the modal
      modal.appendChild(content);

      // Append the modal to the body
      document.body.appendChild(modal);
    }

    // Display the modal
    modal.style.display = "block";

    // Populate the dropdown list
    let dropdownList = document.getElementById('labelDropdown');
    labels.forEach(label => {
      let option = document.createElement('option');
      option.value = label;
      option.text = label;
      dropdownList.appendChild(option);
    });

    // Add an event listener to the modal box to hide it when clicked outside
    document.addEventListener('click', function(event) {
      if (event.target == modal) {
        modal.style.display = "none";
        dropdownList.selectedIndex = -1; // Reset the dropdown selection
      }
    });

    // Add an event listener to the input box to filter the dropdown list
    document.getElementById('filterInput').addEventListener('keyup', function() {
      let filter = this.value.toUpperCase();
      let options = dropdownList.options;
      // Clear the dropdown list
      while (dropdownList.firstChild) {
        dropdownList.removeChild(dropdownList.firstChild);
      }
      // Populate the dropdown list with the filtered labels
      labels.forEach(label => {
        if (label.toUpperCase().indexOf(filter) > -1) {
          let option = document.createElement('option');
          option.value = label;
          option.text = label;
          dropdownList.appendChild(option);
        }
      });
    });

    // Add an event listener to the cancel button to hide the modal box
    document.getElementById('cancelButton').addEventListener('click', function() {
      modal.style.display = "none";
      dropdownList.selectedIndex = -1; // Reset the dropdown selection
    });

    dropdownList.addEventListener('change', function() {
      const newname = this.value;
      // Check if the default option is selected
      if (newname === '') {
        return; // Exit the function early
      }
      if (confirm("Are you sure you want to change the specie identified in this detection to " + newname + "?") == true) {
        const xhttp2 = new XMLHttpRequest();
        xhttp2.onload = function() {
          if(this.responseText == "OK"){
            if(copylink == true) {
              alert("Successfully converted");
              window.top.close();
            } else {
              alert("Successfully converted");
              location.reload();
            }
          } else {
            alert(this.responseText);
          }
        }
        xhttp2.open("GET", "play.php?changefile="+filename+"&newname="+newname, true);
        xhttp2.send();
      }
      // Hide the modal box and reset the dropdown selection
      modal.style.display = "none";
      this.selectedIndex = -1;
    });
  }
  xhttp.open("GET", "play.php?getlabels=true", true);
  xhttp.send();
}

// Review loop (owner 2026-10-08): is this the bird? The modal explains each answer:
//   Yes          = confirmed: the species goes to the Confirmed list and this clip is protected from the disk purge
//   Not this bird = rejected: the clip leaves the best detections and the species page counts
//   Can't tell   = reviewed as unsure: kept as reviewed, nothing else changes
//   Clear        = removes the review: the detection is "not reviewed" again
function reviewDetection(filename, elem) {
  var old = document.getElementById('reviewDialog');
  if (old) old.remove();
  if (!document.getElementById('reviewDialogStyle')) {
    var st = document.createElement('style');
    st.id = 'reviewDialogStyle';
    st.textContent = '#reviewDialog{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:1000}'
      + '#reviewDialog .rv-box{background:#fff;color:#000;border-radius:10px;max-width:440px;width:calc(100% - 32px);overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,.35);text-align:left;font-size:14px}'
      + '#reviewDialog .rv-head{display:flex;align-items:center;gap:10px;background:#2b5e22;color:#fff;padding:10px 14px}'
      + '#reviewDialog .rv-head img{width:26px;height:26px}#reviewDialog .rv-head b{font-size:16px;display:block}#reviewDialog .rv-head small{opacity:.85}'
      + '#reviewDialog .rv-list{padding:12px 14px 6px;display:flex;flex-direction:column;gap:8px}'
      + '#reviewDialog .rv-opt{display:flex;align-items:center;gap:12px;width:100%;text-align:left;padding:8px 10px;border-radius:8px;border:1px solid #ccc;background:#fff;color:#000;cursor:pointer;font-size:14px;line-height:1.35}'
      + '#reviewDialog .rv-opt:hover,#reviewDialog .rv-opt:focus{border-color:#d97a00;background:#fff7ec;outline:none}'
      + '#reviewDialog .rv-ico{flex:0 0 34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:19px;font-weight:bold}'
      + '#reviewDialog .rv-opt b{display:block}#reviewDialog .rv-opt span.rv-txt{font-size:12.5px;color:#444}'
      + '#reviewDialog .rv-key{margin-left:auto;font-size:11px;color:#888;border:1px solid #ccc;border-radius:4px;padding:0 5px}'
      + '#reviewDialog .rv-foot{display:flex;justify-content:flex-end;padding:4px 14px 12px}'
      + '#reviewDialog .rv-foot button{width:auto;padding:5px 14px;border-radius:14px;border:1px solid #2b5e22;background:#fff;color:#2b5e22;font-weight:600;cursor:pointer}';
    document.head.appendChild(st);
  }
  var parts = filename.split('/');
  var name = parts[1] ? parts[1].replace(/_/g, ' ') : '';
  var opts = [
    ['yes', '✓', '#2e7d32', 'Yes, this bird', 'Confirms the species (Confirmed list);<br>protects this clip from the disk purge.', 'Y'],
    ['no', '✗', '#c62828', 'Not this bird', 'The clip leaves the best detections;<br>it is not counted on the species page.', 'N'],
    ['unsure', '?', '#9e9e9e', "Can't tell", 'Saved as reviewed but unsure;<br>nothing else changes.', 'U'],
    ['clear', '↺', '#607d8b', 'Clear the review', 'Removes any answer given before;<br>the detection is back to "not reviewed".', 'C']
  ];
  var d = document.createElement('div');
  d.id = 'reviewDialog';
  d.innerHTML = '<div class="rv-box"><div class="rv-head"><img src="images/species-page.svg" alt=""><div><b>Is this the bird?</b><small>' + name + '</small></div></div>'
    + '<div class="rv-list">' + opts.map(function (o) {
        return '<button type="button" class="rv-opt" data-v="' + o[0] + '"><span class="rv-ico" style="background:' + o[2] + '">' + o[1] + '</span>'
          + '<span><b>' + o[3] + '</b><span class="rv-txt">' + o[4] + '</span></span><span class="rv-key">' + o[5] + '</span></button>';
      }).join('') + '</div><div class="rv-foot"><button type="button" data-v="">Cancel</button></div></div>';
  document.body.appendChild(d);
  function close() { d.remove(); document.removeEventListener('keydown', key); }
  function send(v) {
    close();
    if (!v) return;
    var icons = {yes: 'images/review_yes.svg', no: 'images/review_no.svg', unsure: 'images/review_unsure.svg', clear: 'images/review.svg'};
    var titles = {yes: 'Reviewed: yes, this bird (species confirmed, clip protected from purge)', no: 'Reviewed: not this bird',
                  unsure: "Reviewed: can't tell", clear: 'Is this the bird? Review this detection'};
    var isBtn = elem.tagName === 'BUTTON';
    var labels = {yes: '✓ Valid', no: '✗ Not this bird', unsure: "? Can't tell", clear: 'Review'};
    var before = isBtn ? elem.innerHTML : elem.getAttribute('src');
    if (isBtn) elem.textContent = '…'; else elem.setAttribute('src', 'images/spinner.gif');
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText == 'OK') {
        if (isBtn) {
          elem.textContent = labels[v];
          elem.className = elem.className.replace(/\bv-\w+/, 'v-' + (v === 'clear' ? 'none' : v));
        } else {
          elem.setAttribute('src', icons[v]);
        }
        elem.setAttribute('title', titles[v]);
      } else {
        if (isBtn) elem.innerHTML = before; else elem.setAttribute('src', before);
        alert(this.status === 401 ? 'Log in first to review detections.' : this.responseText);
      }
    };
    x.open('GET', 'play.php?review=' + encodeURIComponent(filename) + '&verdict=' + v, true);
    x.send();
  }
  function key(e) {
    if (e.key === 'Escape') { close(); return; }
    var k = {y: 'yes', n: 'no', u: 'unsure', c: 'clear'}[e.key.toLowerCase()];
    if (k) { e.preventDefault(); send(k); }
  }
  d.querySelectorAll('button').forEach(function (b) { b.onclick = function () { send(b.dataset.v); }; });
  d.onclick = function (e) { if (e.target === d) close(); };
  document.addEventListener('keydown', key);
  d.querySelector('.rv-opt').focus();
}
