#!/usr/bin/env bash
set -x

source /etc/birdnet/birdnet.conf
used="$(df -h ${EXTRACTED} | tail -n1 | awk '{print $5}')"
purge_threshold="${PURGE_THRESHOLD:-95}"

if [ "${used//%}" -ge "$purge_threshold" ]; then

  case $FULL_DISK in
    purge) echo "Removing oldest data"
        cd ${EXTRACTED}/By_Date/
        # automatic purge protection (top N per species + confirmed reviews); no fresh list = no purge
        if ! "$HOME"/BirdNET-Pi/birdnet/bin/python3 "$HOME"/BirdNET-Pi/scripts/purge_protection.py; then
            echo "purge protection could not be refreshed - nothing deleted"
            exit 1
        fi
        if ! grep -qxFe \#\#start $HOME/BirdNET-Pi/scripts/disk_check_exclude.txt; then
            exit
        fi
        # the protection list is not rewritten while this purge reads it
        exec 8> /tmp/birdnet_purge.lock
        flock 8
        dircount=$(find ${EXTRACTED}/By_Date/* -maxdepth 0 -type d | wc -l)
        if [ "$dircount" -gt 0 ]; then
            filestodelete=$(($(find ${EXTRACTED}/By_Date/* -type f | wc -l) / dircount))
            iter=0
            for i in */*/*; do
                if [ $iter -ge $filestodelete ]; then
                    break
                fi
                if ! grep -qxFe "$i" $HOME/BirdNET-Pi/scripts/disk_check_exclude.txt; then
                    rm "$i"
                fi
                ((iter++))
            done
        else
            echo "No By_Date directories found, skipping per-directory purge"
        fi
        find ~/BirdSongs/ -type d -empty -mtime +90 -delete
        find ${EXTRACTED}/By_Date/ -empty -type d -delete;;

       #rm -drfv "$(find ${EXTRACTED}/By_Date/* -maxdepth 1 -type d -prune \
        # | sort -r | tail -n1)";;
    keep) echo "Stopping Core Services"
       /usr/local/bin/stop_core_services.sh;;
  esac
fi
sleep 1
used="$(df -h ${EXTRACTED} | tail -n1 | awk '{print $5}')"
if [ "${used//%}" -ge "$purge_threshold" ]; then
  case $FULL_DISK in
    purge) echo "Removing more data"
       rm -rfv ${PROCESSED}/*;;
    keep) echo "Stopping Core Services"
       /usr/local/bin/stop_core_services.sh;;
  esac
fi
