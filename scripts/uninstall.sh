#!/usr/bin/env bash
# Uninstall script to remove everything
#set -x # Uncomment to debug
trap 'rm -f ${TMPFILE}' EXIT
my_dir=$HOME/BirdNET-Pi/scripts
source /etc/birdnet/birdnet.conf &> /dev/null
SCRIPTS=($(ls -1 ${my_dir}) ${HOME}/.gotty)
set -x
TMP_MOUNT=$(systemd-escape -p --suffix=mount "$RECS_DIR/StreamData")
services=($(awk '/service/ && /systemctl/ && !/php/ {print $3}' ${my_dir}/install_services.sh | sort) custom_recording.service avahi-alias@.service $TMP_MOUNT)

remove_services() {
  for i in "${services[@]}"; do
    if [ -L /etc/systemd/system/multi-user.target.wants/"${i}" ];then
      sudo systemctl disable --now "${i}"
    fi
    if [ -L /lib/systemd/system/"${i}" ];then
      sudo rm -f /lib/systemd/system/$i
    fi
    if [ -f /etc/systemd/system/"${i}" ];then
      sudo rm /etc/systemd/system/"${i}"
    fi
    if [ -d /etc/systemd/system/"${i}" ];then
      sudo rm -drf /etc/systemd/system/"${i}"
    fi
  done
  set +x
  remove_icecast
  remove_crons
  remove_avahi_services
}

remove_avahi_services() {
  sudo rm -v "/etc/avahi/services/http.service"
}

remove_crons() {
  sudo sed -i '/birdnet/,+1d' /etc/crontab
}

remove_icecast() {
  if [ -f /etc/init.d/icecast2 ];then
    sudo /etc/init.d/icecast2 stop
    sudo systemctl disable --now icecast2
  fi
}

remove_scripts() {
  for i in "${SCRIPTS[@]}";do
    if [ -L "/usr/local/bin/${i}" ];then
      sudo rm -v "/usr/local/bin/${i}"
    fi
  done
}

# what this edition installs outside the repository: the microphone hot-plug (unit + udev rule), the
# shared microphone device, the sound-repository upload timer and the installer's sudoers rules
remove_edition_files() {
  for unit in birdnet_mic_hotplug.service sound_repo_upload.timer sound_repo_upload.service; do
    sudo systemctl disable --now "$unit" 2> /dev/null
    sudo rm -f "/usr/lib/systemd/system/$unit" "/etc/systemd/system/$unit"
  done
  sudo systemctl daemon-reload
  if [ -f /etc/udev/rules.d/79-birdnet-mic.rules ];then
    sudo rm -f /etc/udev/rules.d/79-birdnet-mic.rules
    sudo udevadm control --reload-rules
  fi
  sudo rm -f /etc/alsa/conf.d/60-birdnet-mic.conf
  # only the file the installer wrote (one line), never a client.conf of the user's own
  if [ "$(cat ${HOME}/.config/pulse/client.conf 2> /dev/null)" = "autospawn = no" ];then
    rm -f ${HOME}/.config/pulse/client.conf
  fi
  sudo rm -f /etc/sudoers.d/010_caddy-nopasswd
  sudo rm -f /etc/cron.d/birdnet_raw_recording
}

remove_services
remove_scripts
remove_edition_files
if [ -d /etc/birdnet ];then sudo rm -drf /etc/birdnet;fi
if [ -f ${HOME}/BirdNET-Pi/birdnet.conf ];then sudo rm -f ${HOME}/BirdNET-Pi/birdnet.conf;fi
# last: the installer's passwordless-sudo rule for this user (nothing after this needs sudo)
sudo rm -f /etc/sudoers.d/zz-birdnet-${USER}-nopasswd
echo "Uninstall finished. Remove this directory with 'rm -drfv' to finish."
