#!/usr/bin/env bash
# USB microphone hot-plug (US-51b, product version of US-44). Run by birdnet_mic_hotplug.service,
# which a udev rule restarts on every USB sound card add/remove. State-driven: it reads the
# current state, so duplicate or out-of-order events are harmless.
#   no USB capture card  -> stop recording and livestream at once (no crash loop without a mic); the analysis
#                           keeps running until the recordings already waiting are analysed, then stops too
#   USB capture card     -> install_audio.sh (shared ALSA device, REC_CARD) and start them
# Notifies through the station's Apprise configuration when the state changes.
source /etc/birdnet/birdnet.conf
services="birdnet_recording.service birdnet_analysis.service livestream.service"
mic_services="birdnet_recording.service livestream.service"
home_dir=$(getent passwd "${BIRDNET_USER}" | cut -d: -f6)

notify() {
  local apprise_bin=$home_dir/BirdNET-Pi/birdnet/bin/apprise conf=$home_dir/BirdNET-Pi/apprise.txt
  [ -x "$apprise_bin" ] && [ -s "$conf" ] || return 0
  sudo -u "${BIRDNET_USER}" "$apprise_bin" -vv -t "BirdnetPi++ ${SITE_NAME:-$HOSTNAME}" -b "$1" --config="$conf" > /dev/null 2>&1 || true
}

sleep 2 # let the kernel finish adding/removing the card nodes
usb_mic=
for usbid in /proc/asound/card*/usbid; do
  [ -e "$usbid" ] || continue
  card_dir=$(dirname "$usbid")
  grep -q '^Capture:' "$card_dir"/stream* 2>/dev/null && { usb_mic=1; break; }
done

active=0
for svc in $services; do systemctl is-active --quiet "$svc" && active=$((active + 1)); done
mic_active=0
for svc in $mic_services; do systemctl is-active --quiet "$svc" && mic_active=$((mic_active + 1)); done

if [ -n "$usb_mic" ]; then
  [ "$active" -eq 3 ] && { echo "USB microphone present, station running - nothing to do"; exit 0; }
  echo "USB microphone plugged in - configuring and starting the station"
  sleep 3 # let ALSA settle
  sudo -u "${BIRDNET_USER}" "$home_dir/BirdNET-Pi/scripts/install_audio.sh" || echo "install_audio.sh failed - starting anyway"
  systemctl start $services
  logger -t birdnet-mic "USB microphone plugged in - recording, analysis and livestream started"
  notify "USB microphone connected - recording, analysis and livestream running"
else
  [ "$active" -eq 0 ] && { echo "No USB microphone, station stopped - nothing to do"; exit 0; }
  # only a station that records from a USB microphone stops: RTSP or a non-USB card keeps running
  if [ -n "${RTSP_STREAM}" ] || [ "${REC_CARD}" != "birdnet_mic" ]; then
    echo "No USB microphone, but the station does not record from one (REC_CARD=${REC_CARD}) - nothing to do"
    exit 0
  fi
  if [ "$mic_active" -gt 0 ]; then
    echo "USB microphone removed - stopping recording and livestream (the analysis finishes the waiting recordings)"
    systemctl stop $mic_services
    logger -t birdnet-mic "USB microphone removed - recording and livestream stopped, analysis empties the queue"
    notify "USB microphone removed - recording and livestream stopped until it is plugged in again; the recordings already waiting are still analysed"
  fi
  # wait while the analysis works through StreamData (a replug restarts this unit); 12 h cap
  for _ in $(seq 1 2880); do
    ls /proc/asound/card*/usbid > /dev/null 2>&1 && grep -qs '^Capture:' /proc/asound/card*/stream* && exit 0
    systemctl is-active --quiet birdnet_analysis.service || break
    compgen -G "$home_dir/BirdSongs/StreamData/*.wav" > /dev/null || break
    sleep 15
  done
  systemctl stop birdnet_analysis.service
  logger -t birdnet-mic "queue empty - analysis stopped until the microphone returns"
fi
