#!/usr/bin/env bash
# US-40: station-side transport to the central sound repository (BirdDB-Br).
# Moves the local spool (SOUND_REPO_PATH, filled per detection by
# scripts/utils/reporting.py) to SOUND_REPO_REMOTE — an rclone "remote:path"
# the station owner configured with their own Google account (Basic Settings
# › BirdDB-Br). Fired every minute by sound_repo_upload.timer; the interval
# (SOUND_REPO_UPLOAD_MINUTES) is read from birdnet.conf on every call, so a
# change in the settings page needs no unit reload. Files stay in the spool
# until the move succeeds, which gives retries for free.
source /etc/birdnet/birdnet.conf

spool="${SOUND_REPO_PATH:-}"
remote="${SOUND_REPO_REMOTE:-}"
minutes="${SOUND_REPO_UPLOAD_MINUTES:-0}"
stamp="$HOME/.sound_repo_last_upload"

[ -n "$spool" ] || exit 0
# The writer refuses to store when the spool root is missing: keep it present.
[ -d "$spool" ] || mkdir -p "$spool"
[ -n "$remote" ] || exit 0
[[ "$minutes" =~ ^[0-9]+$ ]] && [ "$minutes" -gt 0 ] || exit 0

# Interval gate: the timer ticks every minute, the setting decides the cadence.
if [ -f "$stamp" ]; then
  last=$(stat -c %Y "$stamp")
  now=$(date +%s)
  [ $((now - last)) -ge $((minutes * 60)) ] || exit 0
fi

# Nothing settled in the spool (a file younger than a minute may still be
# written by the reporting queue) — no rclone process, no API call.
[ -n "$(find "$spool" -type f -mmin +1 -print -quit 2>/dev/null)" ] || exit 0

if ! rclone listremotes 2>/dev/null | grep -qx "${remote%%:*}:"; then
  echo "sound repo: rclone remote '${remote%%:*}' is not configured — nothing uploaded (see Basic Settings › BirdDB-Br)"
  exit 0
fi

touch "$stamp"
# --min-age 1m: never move a clip or sidecar the writer may still be finishing.
rclone move "$spool" "$remote" --min-age 1m --delete-empty-src-dirs \
  --transfers 4 --checkers 4 --stats 0 2>&1
rc=$?
if [ $rc -eq 0 ]; then
  echo "sound repo: upload done"
else
  echo "sound repo: rclone move failed (rc=$rc) — files stay in the spool and are retried"
fi
exit 0
