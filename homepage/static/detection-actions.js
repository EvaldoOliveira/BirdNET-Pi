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

// Review loop: is this the bird? Yes (confirmed, protected from purge) / Not this bird (left out of the best
// detections and the species page counts) / Can't tell / Clear the verdict
function reviewDetection(filename, elem) {
  var old = document.getElementById('reviewDialog');
  if (old) old.remove();
  var d = document.createElement('dialog');
  d.id = 'reviewDialog';
  d.style.cssText = 'border-radius:8px;padding:16px;max-width:320px;text-align:center';
  var name = filename.split('/')[1] ? filename.split('/')[1].replace(/_/g, ' ') : '';
  d.innerHTML = '<p style="margin-top:0"><b>Is this the bird?</b><br><small>' + name + '</small></p>' +
    '<button data-v="yes">Yes, this bird</button> <button data-v="no">Not this bird</button><br><br>' +
    '<button data-v="unsure">Can\'t tell</button> <button data-v="clear">Clear</button> <button data-v="">Cancel</button>' +
    '<p style="margin-bottom:0"><small>Yes protects the clip from the disk purge; Not this bird leaves it out of the best ' +
    'detections and the species page counts. Keys: Y / N / U, Esc cancels.</small></p>';
  document.body.appendChild(d);
  function send(v) {
    d.close(); d.remove();
    if (!v) return;
    var icons = {yes: 'images/review_yes.svg', no: 'images/review_no.svg', unsure: 'images/review_unsure.svg', clear: 'images/review.svg'};
    var titles = {yes: 'Reviewed: yes, this bird (protected from purge)', no: 'Reviewed: not this bird',
                  unsure: "Reviewed: can't tell", clear: 'Review: is this the bird? (not reviewed)'};
    var before = elem.getAttribute('src');
    elem.setAttribute('src', 'images/spinner.gif');
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText == 'OK') {
        elem.setAttribute('src', icons[v]);
        elem.setAttribute('title', titles[v]);
      } else {
        elem.setAttribute('src', before);
        alert(this.responseText);
      }
    };
    x.open('GET', 'play.php?review=' + encodeURIComponent(filename) + '&verdict=' + v, true);
    x.send();
  }
  d.querySelectorAll('button').forEach(function (b) { b.onclick = function () { send(b.dataset.v); }; });
  d.addEventListener('keydown', function (e) {
    var k = {y: 'yes', n: 'no', u: 'unsure'}[e.key.toLowerCase()];
    if (k) { e.preventDefault(); send(k); }
  });
  d.showModal();
}
