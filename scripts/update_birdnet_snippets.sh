#!/usr/bin/env bash
set -x
# Update BirdnetPi++
trap 'exit 1' SIGINT SIGHUP
source /etc/birdnet/birdnet.conf
if [ -n "${BIRDNET_USER}" ]; then
  echo "BIRDNET_USER: ${BIRDNET_USER}"
  USER=${BIRDNET_USER}
  HOME=/home/${BIRDNET_USER}
else
  echo "WARNING: no BIRDNET_USER found"
  USER=$(awk -F: '/1000/ {print $1}' /etc/passwd)
  HOME=$(awk -F: '/1000/ {print $6}' /etc/passwd)
fi
my_dir=$HOME/BirdNET-Pi/scripts
source "$my_dir/install_helpers.sh"

# Sets proper permissions and ownership
find $HOME/Bird* -type f ! -perm -g+wr -exec chmod g+wr {} + 2>/dev/null
find $HOME/Bird* -not -user $USER -execdir sudo -E chown $USER:$USER {} \+
chmod 666 ~/BirdNET-Pi/scripts/*.txt
chmod 666 ~/BirdNET-Pi/*.txt
find $HOME/BirdNET-Pi -path "$HOME/BirdNET-Pi/birdnet" -prune -o -type f ! -perm /o=w -exec chmod a+w {} \;
chmod g+r $HOME

# remove world-writable perms
chmod -R o-w ~/BirdNET-Pi/templates/*

APT_UPDATED=0
PIP_UPDATED=0

# helpers
sudo_with_user () {
  sudo -u $USER "$@"
}

ensure_apt_updated () {
  [[ $APT_UPDATED != "UPDATED" ]] && apt-get update && APT_UPDATED="UPDATED"
}

ensure_pip_updated () {
  [[ $PIP_UPDATED != "UPDATED" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install -U pip && PIP_UPDATED="UPDATED"
}

remove_unit_file() {
  # remove_unit_file pushed_notifications.service $HOME/BirdNET-Pi/templates/pushed_notifications.service
  if systemctl list-unit-files "${1}" &>/dev/null;then
    systemctl disable --now "${1}"
    rm -f "/usr/lib/systemd/system/${1}"
    rm "$HOME/BirdNET-Pi/templates/${1}"
    if [ $# == 2 ]; then
      rm -f "${2}"
    fi
  fi
}

ensure_python_package() {
  # ensure_python_package pytest pytest==7.1.2
  pytest_installation_status=$(~/BirdNET-Pi/birdnet/bin/python3 -c 'import pkgutil; import sys; print("installed" if pkgutil.find_loader(sys.argv[1]) else "not installed")' "$1")
  if [[ "$pytest_installation_status" = "not installed" ]];then
    ensure_pip_updated
    sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install "$2"
  fi
}

# sed -i on /etc/birdnet/birdnet.conf overwites the symbolic link - restore the link
if ! [ -L /etc/birdnet/birdnet.conf ] ; then
  sudo_with_user cp -f /etc/birdnet/birdnet.conf $HOME/BirdNET-Pi/
  ln -fs  $HOME/BirdNET-Pi/birdnet.conf /etc/birdnet/birdnet.conf
fi

# update snippets below
SRC="APPRISE_NOTIFICATION_BODY='(.*)'$"
DST='APPRISE_NOTIFICATION_BODY="\1"'
sed -i --follow-symlinks -E "s/$SRC/$DST/" /etc/birdnet/birdnet.conf

if ! grep -E '^DATA_MODEL_VERSION=' /etc/birdnet/birdnet.conf &>/dev/null;then
    echo "DATA_MODEL_VERSION=1" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^SPECTROGRAM_HEIGHT=' /etc/birdnet/birdnet.conf &>/dev/null;then
    echo "SPECTROGRAM_HEIGHT=70" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^APPRISE_NOTIFICATION_TITLE_RARE=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo 'APPRISE_NOTIFICATION_TITLE_RARE="RARE BirdnetPi++ $comname ($sciname) $confidencepct% confidence"' >> /etc/birdnet/birdnet.conf
fi

# standard notification bodies (created only when missing — user bodies kept)
BNP_DIR=$(dirname "$(readlink -f /etc/birdnet/birdnet.conf)")
if ! [ -s "$BNP_DIR/body.txt" ];then
  cat << 'BODYEOF' > "$BNP_DIR/body.txt"
A Normal $comname ($sciname) detected with $confidencepct% confidence
Reason: $reason
Link to the detection: $listenurl
Minimum Confidence: $cutoff
Sigmoid Sensitivity: $sens
Overlap: $overlap
$image
$audio
BODYEOF
fi
if ! [ -f "$BNP_DIR/body-rare.txt" ];then
  cat << 'BODYEOF' > "$BNP_DIR/body-rare.txt"
$comname ($sciname) detected
Confidence=$confidencepct% 
Reason: $reason
Minimum Confidence: $cutoff
Sigmoid Sensitivity: $sens
Overlap: $overlap
Link to the detection: $listenurl
$image
$audio
BODYEOF
fi

if ! grep -E '^NOTIFICATION_EMAIL=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo 'NOTIFICATION_EMAIL=""' >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^NOTIFICATION_DEFAULT_TIER=' /etc/birdnet/birdnet.conf &>/dev/null;then
    echo "NOTIFICATION_DEFAULT_TIER=normal" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^SOUND_REPO_PATH=' /etc/birdnet/birdnet.conf &>/dev/null;then
    echo 'SOUND_REPO_PATH=""' >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^SOUND_REPO_LINK=' /etc/birdnet/birdnet.conf &>/dev/null;then
    echo 'SOUND_REPO_LINK=""' >> /etc/birdnet/birdnet.conf
fi

# US-40: station-side upload of the sound-repo spool
if ! grep -E '^SOUND_REPO_REMOTE=' /etc/birdnet/birdnet.conf &>/dev/null;then
    echo '## SOUND_REPO_REMOTE is the rclone destination of the central sound repository (remote:path; empty = deposits stay in SOUND_REPO_PATH)' >> /etc/birdnet/birdnet.conf
    echo 'SOUND_REPO_REMOTE=""' >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^SOUND_REPO_UPLOAD_MINUTES=' /etc/birdnet/birdnet.conf &>/dev/null;then
    echo '## SOUND_REPO_UPLOAD_MINUTES is the interval in minutes between uploads of SOUND_REPO_PATH to SOUND_REPO_REMOTE (0 = never)' >> /etc/birdnet/birdnet.conf
    echo 'SOUND_REPO_UPLOAD_MINUTES=5' >> /etc/birdnet/birdnet.conf
fi

# US-51e: the release channel a station follows. A station that came from another BirdNET-Pi has
# no key yet: keep the line it was just updated to (stable or main), so the next update neither
# jumps to another line nor goes back to an older release.
if ! grep -E '^UPDATE_BRANCH=' /etc/birdnet/birdnet.conf &>/dev/null;then
  current_branch=$(git -C $HOME/BirdNET-Pi rev-parse --abbrev-ref HEAD 2>/dev/null)
  case "$current_branch" in stable|main) ;; *) current_branch=stable ;; esac
  echo "## UPDATE_BRANCH is the release channel the updater follows: stable = released versions, main = development" >> /etc/birdnet/birdnet.conf
  echo "UPDATE_BRANCH=$current_branch" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^BIRDDB_ENABLED=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "## BIRDDB_ENABLED: 1 = contribute this station's clips to BirdDB-Br (sound repository), 0 = off (default)" >> /etc/birdnet/birdnet.conf
  echo "BIRDDB_ENABLED=0" >> /etc/birdnet/birdnet.conf
fi

# Species lists (owner 2026-10-08): station lists live in ~/BirdNET-Pi/species_lists/ and SPECIES_LIST picks
# the active one (include_species_list.txt becomes a link to it). A station's own list becomes
# species_lists/custom.txt and stays selected; otherwise a Brazilian state chosen before (INCLUDE_REGION).
if ! grep -qE '^SPECIES_LIST=' /etc/birdnet/birdnet.conf; then
  sudo -u $USER mkdir -p $HOME/BirdNET-Pi/species_lists
  selected=$(grep -oE '^INCLUDE_REGION=BR-[A-Z]{2}' /etc/birdnet/birdnet.conf | cut -d= -f2)
  if [ -s $HOME/BirdNET-Pi/include_species_list.txt ] && ! [ -L $HOME/BirdNET-Pi/include_species_list.txt ]; then
    sudo -u $USER cp $HOME/BirdNET-Pi/include_species_list.txt $HOME/BirdNET-Pi/species_lists/custom.txt
    selected=custom
  fi
  echo "## SPECIES_LIST is the species list filter: empty = none, BR-<UF> = a Brazilian state, or a list of species_lists/" >> /etc/birdnet/birdnet.conf
  echo "SPECIES_LIST=$selected" >> /etc/birdnet/birdnet.conf
  sudo -u $USER python3 $HOME/BirdNET-Pi/scripts/select_species_list.py || true
  rm -f $HOME/BirdNET-Pi/include_species_list.state
fi

if ! grep -E '^BIRDWEATHER_ENABLED=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "## BIRDWEATHER_ENABLED: 1 = upload to BirdWeather (needs BIRDWEATHER_ID), 0 = paused (the token is kept)" >> /etc/birdnet/birdnet.conf
  echo "BIRDWEATHER_ENABLED=1" >> /etc/birdnet/birdnet.conf
fi

# livestream: systemd must never give up restarting it (a replugged microphone stops ffmpeg repeatedly)
if [ -f $HOME/BirdNET-Pi/templates/livestream.service ] && ! grep -q '^StartLimitIntervalSec=0' $HOME/BirdNET-Pi/templates/livestream.service; then
  sed -i 's/^Requires=network-online.target$/Requires=network-online.target\nStartLimitIntervalSec=0/' $HOME/BirdNET-Pi/templates/livestream.service
  systemctl daemon-reload
fi

# PHP workers: 5 queued the pages that load several parts at once (owner 2026-10-09)
for pool in /etc/php/*/fpm/pool.d/www.conf; do
  if [ -f "$pool" ] && grep -q '^pm.max_children = 5$' "$pool"; then
    sudo sed -i 's/^pm.max_children = .*/pm.max_children = 12/; s/^pm.start_servers = .*/pm.start_servers = 3/; s/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/' "$pool"
    sudo systemctl restart php\*-fpm.service
  fi
done
if ! grep -E '^PURGE_PROTECT_TOP_N=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "## PURGE_PROTECT_TOP_N: the best N detections of every species are never purged (plus confirmed reviews)" >> /etc/birdnet/birdnet.conf
  echo "PURGE_PROTECT_TOP_N=3" >> /etc/birdnet/birdnet.conf
fi
# Review loop: one verdict per detection (yes / no / unsure), additive table
sqlite3 $HOME/BirdNET-Pi/scripts/birds.db "CREATE TABLE IF NOT EXISTS detection_reviews (File_Name VARCHAR(100) PRIMARY KEY, Sci_Name VARCHAR(100), Com_Name VARCHAR(100), Date DATE, Confidence FLOAT, Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')), Reviewed_At TEXT, Reason TEXT);" 2>/dev/null || true
sqlite3 $HOME/BirdNET-Pi/scripts/birds.db "PRAGMA table_info(detection_reviews)" 2>/dev/null | grep -q '|Reason|' || sqlite3 $HOME/BirdNET-Pi/scripts/birds.db "ALTER TABLE detection_reviews ADD COLUMN Reason TEXT;" 2>/dev/null || true
# settings in force when each detection was made (owner 2026-10-09): location threshold, recording length and the species
# threshold; older rows keep them empty (shown as —)
for table in detections deleted_detections; do
  for column in "Loc_Thresh FLOAT" "Rec_Length INT" "Sp_Override FLOAT"; do
    sqlite3 $HOME/BirdNET-Pi/scripts/birds.db "PRAGMA table_info($table)" 2>/dev/null | grep -q "|${column%% *}|" \
      || sqlite3 $HOME/BirdNET-Pi/scripts/birds.db "ALTER TABLE $table ADD COLUMN $column;" 2>/dev/null || true
  done
done

if ! grep -E '^APPRISE_NOTIFY_REGION_RARE=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "## APPRISE_NOTIFY_REGION_RARE: 1 = species the location model does not expect here go to the Rare notification channel" >> /etc/birdnet/birdnet.conf
  echo "APPRISE_NOTIFY_REGION_RARE=1" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^BIRDNET_USER=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "## BIRDNET_USER is for scripts to easily find where BirdnetPi++ is installed" >> /etc/birdnet/birdnet.conf
  echo "## DO NOT EDIT!" >> /etc/birdnet/birdnet.conf
  echo "BIRDNET_USER=$(awk -F: '/1000/ {print $1}' /etc/passwd)" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^RTSP_STREAM_TO_LIVESTREAM=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "RTSP_STREAM_TO_LIVESTREAM=\"0\"" >> /etc/birdnet/birdnet.conf
fi

SRC='^APPRISE_NOTIFICATION_BODY="A \$comname \(\$sciname\)  was just detected with a confidence of \$confidence"$'
DST='APPRISE_NOTIFICATION_BODY="A \$comname (\$sciname)  was just detected with a confidence of \$confidence (\$reason)"'
sed -i --follow-symlinks -E "s/$SRC/$DST/" /etc/birdnet/birdnet.conf

if ! [ -f $HOME/BirdNET-Pi/body.txt ];then
  grep -E '^APPRISE_NOTIFICATION_BODY=".*"' /etc/birdnet/birdnet.conf | cut -d '"' -f 2 | sudo_with_user tee "$HOME/BirdNET-Pi/body.txt"
  chmod g+w "$HOME/BirdNET-Pi/body.txt"
  sed -i --follow-symlinks -E 's/^APPRISE_NOTIFICATION_BODY=/#APPRISE_NOTIFICATION_BODY=/' /etc/birdnet/birdnet.conf
fi

if ! grep -E '^INFO_SITE=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "INFO_SITE=\"ALLABOUTBIRDS\"" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^COLOR_SCHEME=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "COLOR_SCHEME=\"light\"" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^PURGE_THRESHOLD=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "PURGE_THRESHOLD=95" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^MAX_FILES_SPECIES=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "MAX_FILES_SPECIES=\"0\"" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^AUTOMATIC_UPDATE=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "AUTOMATIC_UPDATE=0" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^RARE_SPECIES_THRESHOLD=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo '## RARE_SPECIES_THRESHOLD defines after how many days a species is considered as rare and highlighted on overview page' >> /etc/birdnet/birdnet.conf
  echo "RARE_SPECIES_THRESHOLD=\"30\"" >> /etc/birdnet/birdnet.conf
fi

# Bird photos come from Wikipedia (the Flickr option and the Bird Photo Source settings were retired,
# owner 2026-10-08): stations on FLICKR, None or without the key move to WIKIPEDIA
if grep -qE '^IMAGE_PROVIDER=' /etc/birdnet/birdnet.conf; then
  sed -i 's/^IMAGE_PROVIDER=.*/IMAGE_PROVIDER=WIKIPEDIA/' /etc/birdnet/birdnet.conf
else
  echo '## IMAGE_PROVIDER: bird photos from Wikipedia' >> /etc/birdnet/birdnet.conf
  echo "IMAGE_PROVIDER=WIKIPEDIA" >> /etc/birdnet/birdnet.conf
fi

if grep -E '^DATABASE_LANG=zh$' /etc/birdnet/birdnet.conf &>/dev/null;then
  sed -i --follow-symlinks -E 's/^DATABASE_LANG=zh/DATABASE_LANG=zh_CN/' /etc/birdnet/birdnet.conf
  install_language_label.sh
fi
# Portuguese split in two name sets (2026-10-07): labels_pt.json (upstream, Portugal names)
# became labels_pt_PT.json and labels_pt_BR.json carries the CBRO names. A station on the
# old 'pt' keeps exactly the names it had.
if grep -E '^DATABASE_LANG=pt$' /etc/birdnet/birdnet.conf &>/dev/null;then
  sed -i --follow-symlinks -E 's/^DATABASE_LANG=pt$/DATABASE_LANG=pt_PT/' /etc/birdnet/birdnet.conf
fi

[ -d $RECS_DIR/StreamData ] || sudo_with_user mkdir -p $RECS_DIR/StreamData
[ -L ${EXTRACTED}/spectrogram.png ] || sudo_with_user ln -sf ${RECS_DIR}/StreamData/spectrogram.png ${EXTRACTED}/spectrogram.png

if ! which inotifywait &>/dev/null;then
  ensure_apt_updated
  apt-get -y install inotify-tools
fi

apprise_version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import apprise; print(apprise.__version__)")
[[ $apprise_version != "1.9.5" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install apprise==1.9.5
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import streamlit; print(streamlit.__version__)")
[[ $version != "1.44.0" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install streamlit==1.44.0
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import seaborn; print(seaborn.__version__)")
[[ $version != "0.13.2" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install seaborn==0.13.2
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import suntime; print(suntime.__version__)")
[[ $version != "1.3.2" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install suntime==1.3.2
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import pyarrow; print(pyarrow.__version__)")
[[ $version != "20.0.0" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install pyarrow==20.0.0

PY_VERSION=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import sys; print(f'{sys.version_info[0]}{sys.version_info[1]}')")
tf_version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import tflite_runtime; print(tflite_runtime.__version__)")
if { [ "$PY_VERSION" == 39 ] && [ "$tf_version" != "2.11.0" ]; } || { [ "$PY_VERSION" != 39 ] && [ "$tf_version" != "2.17.1" ]; }; then
  get_tf_whl
  # include our numpy dependants so pip can figure out which numpy version to install
  sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install $HOME/BirdNET-Pi/$WHL pandas librosa matplotlib
fi

ensure_python_package inotify inotify
ensure_python_package soundfile soundfile

if ! which inotifywait &>/dev/null;then
  ensure_apt_updated
  apt-get -y install inotify-tools
fi

install_tmp_mount
remove_unit_file birdnet_server.service /usr/local/bin/server.py
remove_unit_file extraction.service /usr/local/bin/extract_new_birdsounds.sh

if ! grep 'daemon' $HOME/BirdNET-Pi/templates/chart_viewer.service &>/dev/null;then
  sed -i "s|daily_plot.py.*|daily_plot.py --daemon --sleep 2|" ~/BirdNET-Pi/templates/chart_viewer.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q 'birdnet_server.service' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service" &>/dev/null; then
    sed -i '/After=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i '/Requires=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i '/RuntimeMaxSec=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i "s|ExecStart=.*|ExecStart=$HOME/BirdNET-Pi/birdnet/bin/python3 /usr/local/bin/birdnet_analysis.py|" "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    systemctl daemon-reload && restart_services.sh
fi

TMP_MOUNT=$(systemd-escape -p --suffix=mount "$RECS_DIR/StreamData")
if ! [ -f "$HOME/BirdNET-Pi/templates/$TMP_MOUNT" ]; then
   install_birdnet_mount
   chown $USER:$USER "$HOME/BirdNET-Pi/templates/$TMP_MOUNT"
fi

# US-40: sound-repo upload timer (new unit on an existing station)
if ! [ -f "$HOME/BirdNET-Pi/templates/sound_repo_upload.timer" ]; then
  install_sound_repo_upload_service
  chown $USER:$USER "$HOME/BirdNET-Pi/templates/sound_repo_upload.service" "$HOME/BirdNET-Pi/templates/sound_repo_upload.timer"
  systemctl daemon-reload && systemctl start sound_repo_upload.timer
fi
[ -z "${SOUND_REPO_PATH}" ] || [ -d "${SOUND_REPO_PATH}" ] || sudo_with_user mkdir -p "${SOUND_REPO_PATH}"

if grep -q -e '-P log' $HOME/BirdNET-Pi/templates/birdnet_log.service ; then
  sed -i "s/-P log/--path log/" ~/BirdNET-Pi/templates/birdnet_log.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e '-P terminal' $HOME/BirdNET-Pi/templates/web_terminal.service ; then
  sed -i "s/-P terminal/--path terminal/" ~/BirdNET-Pi/templates/web_terminal.service
  systemctl daemon-reload && systemctl restart web_terminal.service
fi

if grep -q -e ' login' $HOME/BirdNET-Pi/templates/web_terminal.service ; then
  sed -i "s/ login/ bash -c 'read -p \"Login: \" username \&\& [[ \"\$username\" =~ ^[-_.a-z0-9]{1,30}\$ ]] \&\& su --pty -l \$username'/" ~/BirdNET-Pi/templates/web_terminal.service
  sed -i "/\[Service\]/a User=$BIRDNET_USER" ~/BirdNET-Pi/templates/web_terminal.service
  systemctl daemon-reload && systemctl restart web_terminal.service
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/birdnet_recording.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/birdnet_recording.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/custom_recording.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/custom_recording.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/livestream.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/livestream.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q 'php7.4-' /etc/caddy/Caddyfile &>/dev/null; then
  sed -i 's/php7.4-/php-/' /etc/caddy/Caddyfile
fi

if ! [ -L /etc/avahi/services/http.service ];then
  # symbolic link does not work here, so just copy
  cp -f $HOME/BirdNET-Pi/templates/http.service /etc/avahi/services/
  systemctl restart avahi-daemon.service
fi

if [ -L /usr/local/bin/analyze.py ];then
  rm -f /usr/local/bin/analyze.py
fi

if [ -L /usr/local/bin/birdnet_analysis.sh ];then
  rm -f /usr/local/bin/birdnet_analysis.sh
fi

# the weekly report was retired (owner 2026-10-09): its cron line goes
if grep -q '/usr/local/bin/weekly_report.sh' /etc/crontab; then
  # the #birdnet marker line before it goes too
  sudo sed -i -e '/^#birdnet$/{N;/weekly_report\.sh/d}' -e '\|/usr/local/bin/weekly_report.sh|d' /etc/crontab
fi
sudo rm -f /usr/local/bin/weekly_report.sh "$HOME"/BirdSongs/Extracted/weekly_report.php "$HOME"/BirdSongs/Extracted/history.php
# Clean state and update cron if all scripts are not installed
if [ "$(grep -o "#birdnet" /etc/crontab | wc -l)" -lt 5 ]; then
  sudo sed -i -e '/^#birdnet$/d' \
    -e '\|/usr/local/bin/disk_check.sh|d' \
    -e '\|/usr/local/bin/disk_species_clean.sh|d' \
    -e '\|/usr/local/bin/cleanup.sh|d' \
    -e '\|/usr/local/bin/weekly_report.sh|d' \
    -e '\|/usr/local/bin/update_birdnet.sh|d' /etc/crontab
  sed "s/\$USER/$USER/g" "$HOME"/BirdNET-Pi/templates/cleanup.cron >> /etc/crontab
  sed "s/\$USER/$USER/g" "$HOME"/BirdNET-Pi/templates/automatic_update.cron >> /etc/crontab
fi

set +x
AUTH=$(grep basicauth /etc/caddy/Caddyfile)
[ -n "${CADDY_PWD}" ] && [ -z "${AUTH}" ] && sudo /usr/local/bin/update_caddyfile.sh > /dev/null 2>&1
set -x

if [ -L $HOME/BirdNET-Pi/model/labels_flickr.txt ]; then
  rm $HOME/BirdNET-Pi/model/labels_flickr.txt
fi
if [ -L $HOME/BirdNET-Pi/model/labels.txt ]; then
  sudo_with_user install_language_label.sh
fi

sqlite3 $HOME/BirdNET-Pi/scripts/birds.db << EOF
CREATE INDEX IF NOT EXISTS "detections_Sci_Name" ON "detections" ("Sci_Name");
EOF

# Station layer (fork): update_birdnet.sh resets the tree to the branch, which puts
# the upstream model files back over the station's own (custom/<layer>/model). The
# analyzer loads them at start, so re-apply them before restart_services.sh below;
# otherwise the detections get the upstream common names (2026-09-17: a CBRO station
# wrote 36 detections with Portugal-Portuguese names after a webui deploy).
# STATION_LAYER comes from birdnet.conf; empty or missing = no layer, nothing to do.
if [ -n "${STATION_LAYER}" ] && [ -d "$HOME/BirdNET-Pi/custom/${STATION_LAYER}/model" ]; then
  layer_model="$HOME/BirdNET-Pi/custom/${STATION_LAYER}/model"
  for src in "$layer_model"/labels.txt "$layer_model"/l18n/labels_*.json; do
    [ -f "$src" ] || continue
    dst="$HOME/BirdNET-Pi/model/${src#"$layer_model"/}"
    # model/labels.txt may be a language symlink on stock installs - leave those alone
    [ -L "$dst" ] && continue
    if ! cmp -s "$src" "$dst"; then
      install -m 664 -o "$USER" -g "$USER" "$src" "$dst" \
        && echo "station layer ${STATION_LAYER}: re-applied ${dst#"$HOME"/BirdNET-Pi/}"
    fi
  done
fi

# boot location check (owner 2026-10-10): the setting and the service on stations installed before it
grep -q '^LOCATION_CHECK=' /etc/birdnet/birdnet.conf || printf '\n## LOCATION_CHECK: 1 = at boot, compare the network position with the coordinates and ask when the station moved more than 100 km\nLOCATION_CHECK=1\n' >> /etc/birdnet/birdnet.conf
if [ ! -f "$HOME/BirdNET-Pi/templates/birdnet_location_check.service" ]; then
  cat << EOF > "$HOME/BirdNET-Pi/templates/birdnet_location_check.service"
[Unit]
Description=BirdnetPi++ location check (did the station move more than 100 km?)
Wants=network-online.target
After=network-online.target
[Service]
Type=oneshot
User=$USER
ExecStart=/usr/bin/python3 $HOME/BirdNET-Pi/scripts/location_check.py
[Install]
WantedBy=multi-user.target
EOF
  chown "$USER:$USER" "$HOME/BirdNET-Pi/templates/birdnet_location_check.service"
  ln -sf "$HOME/BirdNET-Pi/templates/birdnet_location_check.service" /usr/lib/systemd/system
  systemctl daemon-reload
  systemctl enable birdnet_location_check.service
fi

# species table: look up, in the background, the labels missing from model/species_info.csv (a new model)
sudo -u "$USER" -H "$HOME/BirdNET-Pi/scripts/update_species_info.sh" || true

# update snippets above

systemctl daemon-reload
restart_services.sh
