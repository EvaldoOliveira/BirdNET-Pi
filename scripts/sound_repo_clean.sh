#!/usr/bin/env bash
# Empties the sound-repo spool (SOUND_REPO_PATH) while the upload is off.
# The spool only holds clips waiting for scripts/sound_repo_upload.sh, which
# deletes each clip once it reaches SOUND_REPO_REMOTE; with no remote or an
# interval of 0 (paused) nothing will ever send them, so nothing is kept.
# Called by disk_species_clean.sh; the root folder itself stays.
source /etc/birdnet/birdnet.conf

spool="${SOUND_REPO_PATH:-}"
remote="${SOUND_REPO_REMOTE:-}"
minutes="${SOUND_REPO_UPLOAD_MINUTES:-0}"

[ -n "$spool" ] && [ -d "$spool" ] || exit 0
if [ "${BIRDDB_ENABLED:-0}" = "1" ] && [ -n "$remote" ] && [[ "$minutes" =~ ^[0-9]+$ ]] && [ "$minutes" -gt 0 ]; then
  exit 0
fi
# Only ever the spool's own files (clip + sidecar), never a folder the station needs: a path set to
# the home, the recordings or the system root would otherwise be wiped every night.
spool=$(realpath -m "$spool")
recs=$(realpath -m "${RECS_DIR:-$HOME/BirdSongs}")
case "$spool" in
  /|/home|"$HOME"|"$recs"|"$recs/Extracted"*|"$recs/StreamData"*|"$recs/Processed"*|"$(realpath -m "$HOME/BirdNET-Pi")"*)
    echo "sound repo: refusing to clean '$spool' (not a spool folder)"; exit 0 ;;
esac
# the writer's layout: <spool>/<station>/<date>/<species>/<clip>.flac + .json
count=$(find "$spool" -mindepth 4 -type f \( -name '*.flac' -o -name '*.json' \) | wc -l)
[ "$count" -gt 0 ] || exit 0
find "$spool" -mindepth 4 -type f \( -name '*.flac' -o -name '*.json' \) -delete
find "$spool" -mindepth 1 -type d -empty -delete
echo "sound repo: upload off — ${count} files removed from the spool"
