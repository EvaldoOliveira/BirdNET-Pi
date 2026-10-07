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
count=$(find "$spool" -type f | wc -l)
[ "$count" -gt 0 ] || exit 0
find "$spool" -mindepth 1 -delete
echo "sound repo: upload off — ${count} files removed from the spool"
