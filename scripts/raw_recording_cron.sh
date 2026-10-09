#!/usr/bin/env bash
# Writes /etc/cron.d/birdnet_raw_recording from the Raw Recording settings in birdnet.conf
# (RAW_REC_ENABLED, RAW_REC_DAYS = cron days of the week 0-6 with 0 = Sunday, RAW_REC_START = HH:MM[:SS]).
# Disabled or no day chosen: the file is removed. Run as root (the Raw Recording page calls it with sudo).
source /etc/birdnet/birdnet.conf
cron_file=/etc/cron.d/birdnet_raw_recording
user="${BIRDNET_USER:-$(awk -F: '/1000/ {print $1}' /etc/passwd)}"
days=$(echo "${RAW_REC_DAYS:-}" | tr -cd '0-6,')

if [ "${RAW_REC_ENABLED:-0}" != "1" ] || [ -z "$days" ]; then
  rm -f "$cron_file"
  echo "Raw recording schedule off"
  exit 0
fi

IFS=: read -r hh mm _ <<< "${RAW_REC_START:-04:00:00}"
hh=$((10#${hh:-4})); mm=$((10#${mm:-0}))
cat > "$cron_file.tmp" << EOF
# Raw recording schedule (BirdnetPi++ Raw Recording page) - written by raw_recording_cron.sh, do not edit
$mm $hh * * $days $user /home/$user/BirdNET-Pi/scripts/raw_recording.sh > /dev/null 2>&1
EOF
chmod 644 "$cron_file.tmp"
mv "$cron_file.tmp" "$cron_file"
echo "Raw recording scheduled: $(printf '%02d:%02d' $hh $mm) on days $days"
