#!/usr/bin/env bash
# Raw recording session (Raw Recording page): long unprocessed WAV files of the dawn chorus or any other
# window, recorded from the station's shared microphone while the analysis keeps running on it.
# Files named YYYY-MM-DD-<time>-<station>.wav (time HHhMMmSSs, station = SITE_NAME), RAW_REC_SEGMENT_MIN
# minutes each at 48 kHz S16_LE, plus a <start>-<station>.recording session log, in ~/BirdNET-Pi/raw-recording/.
# Started by cron (/etc/cron.d/birdnet_raw_recording, written by raw_recording_cron.sh) at RAW_REC_START;
# records until RAW_REC_END (next day when the end is earlier than the start).
# Usage: raw_recording.sh [--now]   (--now: start immediately, still stopping at RAW_REC_END)
source /etc/birdnet/birdnet.conf

out_dir="$HOME/BirdNET-Pi/raw-recording"
name="${SITE_NAME:-$(hostname)}"
name="${name// /_}"
name="${name//[^A-Za-z0-9_-]/}"
[ -n "$name" ] || name=$(hostname)
device="${RAW_REC_DEVICE:-${REC_CARD:-default}}"
rate="${RAW_REC_RATE:-48000}"
channels="${CHANNELS:-1}"
segment_s=$(( ${RAW_REC_SEGMENT_MIN:-30} * 60 ))
lock="/tmp/birdnet_raw_recording.lock"

# one session at a time
exec 9> "$lock"
if ! flock -n 9; then
  echo "A raw recording session is already running"
  exit 0
fi

to_s() { local IFS=:; set -- $1; echo $(( 10#${1:-0} * 3600 + 10#${2:-0} * 60 + 10#${3:-0} )); }
start_s=$(to_s "${RAW_REC_START:-04:00:00}")
end_s=$(to_s "${RAW_REC_END:-10:00:00}")
today=$(date -d "today 00:00" +%s)

if [ "$1" != "--now" ]; then
  # cron starts on the minute: wait for the configured second
  target=$(( today + start_s ))
  now=$(date +%s)
  [ $target -gt $now ] && [ $(( target - now )) -lt 120 ] && sleep $(( target - now ))
fi
end=$(( today + end_s ))
[ $end_s -le $start_s ] && end=$(( end + 86400 ))
[ "$1" = "--now" ] && [ $end -le $(date +%s) ] && end=$(( end + 86400 ))
[ $end -le $(date +%s) ] && { echo "The end time has already passed"; exit 0; }

mkdir -p "$out_dir"
session=$(date +%Y-%m-%d-%Hh%Mm%Ss)
log_file="$out_dir/${session}-${name}.recording"
{
  echo "############## Start ##############"
  echo "Recording until:     $(date -d "@$end" +%Y-%m-%d-%Hh%Mm%Ss) in blocks of $segment_s seconds"
  echo "Start of recording:  $session"
  echo "----"
  echo "TARGET_PATH          $out_dir"
  echo "DEVICE_NAME          $name"
  echo "DEVICE               $device"
  echo "RATE                 $rate"
  echo "Format               S16_LE"
  echo "Channels             $channels"
  echo "----"
  echo "STATION              ${SITE_NAME:-$(hostname)}"
  echo "LATITUDE             $LATITUDE"
  echo "LONGITUDE            $LONGITUDE"
} > "$log_file"

while [ "$(date +%s)" -lt "$end" ]; do
  left=$(( end - $(date +%s) ))
  length=$(( left < segment_s ? left : segment_s ))
  [ $length -lt 1 ] && break
  stamp=$(date +%Y-%m-%d-%Hh%Mm%Ss)
  wav="$out_dir/${stamp}-${name}.wav"
  if ! arecord -q -D "$device" -f S16_LE -c "$channels" -r "$rate" -d "$length" "$wav" 2>> "$log_file"; then
    echo "$(date +%Y-%m-%d-%Hh%Mm%Ss) recording error (microphone unplugged?) - retrying in 10 s" >> "$log_file"
    rm -f "$wav"
    sleep 10
  fi
done
echo "############## End $(date +%Y-%m-%d-%Hh%Mm%Ss) ##############" >> "$log_file"

# a one-time schedule switches itself off after its session
if [ "${RAW_REC_RECURRENT:-1}" = "0" ] && [ "$1" != "--now" ]; then
  sudo sed -i 's/^RAW_REC_ENABLED=.*/RAW_REC_ENABLED=0/' /etc/birdnet/birdnet.conf
  sudo "$HOME/BirdNET-Pi/scripts/raw_recording_cron.sh"
fi
