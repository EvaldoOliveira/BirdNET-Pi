#!/usr/bin/env bash
# First-run questions of a new installation (US-51c). Sourced by install_config.sh before it
# writes birdnet.conf, only when there is no birdnet.conf yet. Every answer comes from, in order:
#   1. a seed file for unattended / batch installs, the first that exists of
#      /boot/firmware/birdnet-setup.conf, /boot/birdnet-setup.conf, $HOME/birdnet-setup.conf
#      (KEY=value lines: SITE_NAME LATITUDE LONGITUDE TIMEZONE MODEL LANGUAGE STATE WEB_PASSWORD
#       STREAM_PASSWORD BIRDWEATHER_ID APPRISE_URL — see docs/birdnet-setup.conf.example)
#   2. a question on the terminal (read from /dev/tty, so `curl ... | bash` works too),
#      skipped when BIRDNET_UNATTENDED=1 or there is no terminal
#   3. a default: the ip-api.com answer of install_config.sh (location, country, state)
# Sets FR_* variables for install_config.sh. Secrets are never traced into the install log.

case $- in *x*) fr_xtrace=1 ;; *) fr_xtrace= ;; esac
set +x

fr_interactive=
if [ -z "${BIRDNET_UNATTENDED}" ] && (: < /dev/tty) 2>/dev/null; then
  fr_interactive=1
fi

# seed file: plain KEY=value lines, read (never sourced), only the known keys
declare -A fr_seed=()
for f in /boot/firmware/birdnet-setup.conf /boot/birdnet-setup.conf "$HOME/birdnet-setup.conf"; do
  [ -r "$f" ] || continue
  echo "First-run answers read from $f"
  while IFS='=' read -r key value; do
    key=$(echo "$key" | tr -d '[:space:]')
    case "$key" in
      SITE_NAME|LATITUDE|LONGITUDE|TIMEZONE|MODEL|LANGUAGE|STATE|WEB_PASSWORD|STREAM_PASSWORD|BIRDWEATHER_ID|APPRISE_URL)
        value="${value%$'\r'}"; value="${value#\"}"; value="${value%\"}"
        fr_seed[$key]="$value"
        ;;
    esac
  done < <(grep -E '^[[:space:]]*[A-Z_]+=' "$f")
  break
done

# fr_ask VAR "question" "default" [secret] — seed value wins, then the terminal, then the default
fr_ask() {
  local var=$1 question=$2 default=$3 secret=$4 seed_key=$5 answer
  if [ -n "$seed_key" ] && [ -n "${fr_seed[$seed_key]+set}" ]; then
    printf -v "$var" '%s' "${fr_seed[$seed_key]}"
    return
  fi
  if [ -n "$fr_interactive" ]; then
    if [ -n "$secret" ]; then
      read -r -s -p "$question: " answer < /dev/tty; echo > /dev/tty
    else
      # -e: line editing, so arrow keys and the like edit the answer instead of ending up in it
      read -e -r -p "$question [${default}]: " answer < /dev/tty
    fi
  fi
  # no control characters (escape sequences, stray keys) in a value that goes into birdnet.conf
  answer=$(printf '%s' "$answer" | tr -d '\000-\037\177')
  printf -v "$var" '%s' "${answer:-$default}"
}

fr_country=; fr_region=; fr_tz=
if [ -n "$json" ] && [ "$(echo "$json" | jq -r .status 2>/dev/null)" = "success" ]; then
  fr_country=$(echo "$json" | jq -r .countryCode)
  fr_region=$(echo "$json" | jq -r .region)
  fr_tz=$(echo "$json" | jq -r .timezone)
fi

# drop what was typed (or what the terminal answered) while the packages were installing:
# it would otherwise become the beginning of the first answer
if [ -n "$fr_interactive" ]; then
  while read -r -t 0.1 -n 1000 _ < /dev/tty; do :; done
fi
[ -n "$fr_interactive" ] && echo -e "\n=== BirdNET-Pi first-run settings (Enter keeps the value in brackets) ===" > /dev/tty

fr_ask FR_SITE_NAME "Station name" "$HOSTNAME" "" SITE_NAME
while :; do
  fr_ask FR_LATITUDE "Latitude (4 decimals, e.g. -23.5505)" "$LATITUDE" "" LATITUDE
  fr_ask FR_LONGITUDE "Longitude (4 decimals, e.g. -46.6333)" "$LONGITUDE" "" LONGITUDE
  if [[ "$FR_LATITUDE" =~ ^-?[0-9]+(\.[0-9]+)?$ && "$FR_LONGITUDE" =~ ^-?[0-9]+(\.[0-9]+)?$ ]]; then
    break
  fi
  echo "Latitude/longitude must be numbers" >&2
  [ -n "$fr_interactive" ] || { FR_LATITUDE=$LATITUDE; FR_LONGITUDE=$LONGITUDE; break; }
  unset 'fr_seed[LATITUDE]' 'fr_seed[LONGITUDE]'
done

# timezone: detections are stamped with the system clock — a fresh Raspberry Pi OS is on
# Europe/London (US-51d)
fr_tz_now=$(timedatectl show --value --property=Timezone 2>/dev/null || cat /etc/timezone 2>/dev/null || true)
while :; do
  fr_ask FR_TIMEZONE "Timezone" "${fr_tz:-$fr_tz_now}" "" TIMEZONE
  [ -z "$FR_TIMEZONE" ] || [ -e "/usr/share/zoneinfo/$FR_TIMEZONE" ] && break
  echo "Unknown timezone '$FR_TIMEZONE' (e.g. America/Sao_Paulo)" >&2
  [ -n "$fr_interactive" ] || { FR_TIMEZONE=; break; }
  unset 'fr_seed[TIMEZONE]'
done

fr_ask fr_model "Model: V3 (BirdNET+ V3.0, recommended) or V2.4" "V3" "" MODEL
case "${fr_model,,}" in
  v2*|2*|*v2.4*)
    FR_MODEL=BirdNET_GLOBAL_6K_V2.4_Model_FP16; FR_CONFIDENCE=0.7; FR_SENSITIVITY=1.25; FR_SF_THRESH=0.03 ;;
  *)
    # V3 defaults: minimum confidence 0.25, location filter 0.1 (owner 2026-10-07), sensitivity 1.0 (BirdNET Live)
    FR_MODEL=BirdNET-Plus_V3.0-preview3.1_Global_10K; FR_CONFIDENCE=0.25; FR_SENSITIVITY=1.0; FR_SF_THRESH=0.1 ;;
esac

case "$fr_country" in
  BR) fr_lang_default=pt_BR ;;
  PT) fr_lang_default=pt_PT ;;
  *) fr_lang_default=en ;;
esac
fr_langs=$(ls "$my_dir"/model/l18n/labels_*.json 2>/dev/null | sed -E 's/.*labels_(.*)\.json/\1/' | tr '\n' ' ')
while :; do
  fr_ask FR_LANGUAGE "Species names language (${fr_langs% }; pt = Portuguese)" "$fr_lang_default" "" LANGUAGE
  if [ "$FR_LANGUAGE" = "pt" ]; then
    fr_ask fr_pt "Portuguese names: BR = Brazil (CBRO) or PT = Portugal" "BR"
    [ "${fr_pt^^}" = "PT" ] && FR_LANGUAGE=pt_PT || FR_LANGUAGE=pt_BR
  fi
  [ -f "$my_dir/model/l18n/labels_${FR_LANGUAGE}.json" ] && break
  echo "No species names for '$FR_LANGUAGE'" >&2
  [ -n "$fr_interactive" ] || { FR_LANGUAGE=en; break; }
  unset 'fr_seed[LANGUAGE]'
done

FR_REGION=
# the state of the CONFIRMED coordinates (OpenStreetMap online, the shipped IBGE boundaries
# offline) — the network's guess (ip-api region) only when that finds nothing
fr_located=$(python3 "$my_dir/scripts/locate_state.py" "$FR_LATITUDE" "$FR_LONGITUDE" 2>/dev/null || true)
if [ -n "$fr_located" ] || [ "$fr_country" = "BR" ] || [ "$FR_LANGUAGE" = "pt_BR" ] || [ -n "${fr_seed[STATE]+set}" ]; then
  if [ -n "$fr_located" ]; then
    fr_state_default=$fr_located
  elif [ "$fr_country" = "BR" ] && [ "$FR_LATITUDE" = "$LATITUDE" ] && [ "$FR_LONGITUDE" = "$LONGITUDE" ]; then
    fr_state_default=$fr_region
  else
    fr_state_default=none
  fi
  while :; do
    fr_ask fr_state "Brazilian state for the include list (UF, e.g. SP; none = no list)" "$fr_state_default" "" STATE
    fr_state=$(echo "$fr_state" | tr '[:lower:]' '[:upper:]' | tr -d ' ')
    fr_state=${fr_state#BR-}
    case "$fr_state" in
      ""|NONE|N|NO) FR_REGION=; break ;;
    esac
    if [ -f "$my_dir/model/include_lists/BR-${fr_state}.txt" ]; then
      FR_REGION="BR-${fr_state}"; break
    fi
    echo "No include list for '$fr_state'" >&2
    [ -n "$fr_interactive" ] || break
    unset 'fr_seed[STATE]'
  done
fi

# species info links: eBird has every species and pages in Portuguese; All About Birds (the
# upstream default) is a North American guide without most neotropical species
case "$fr_country:$FR_LANGUAGE" in
  US:*|CA:*) FR_INFO_SITE=ALLABOUTBIRDS ;;
  *) FR_INFO_SITE=EBIRD ;;
esac

fr_ask FR_CADDY_PWD "Web password for Tools / Settings (empty = no password)" "" secret WEB_PASSWORD
[ -z "$FR_CADDY_PWD" ] && echo -e "\033[33mNo web password: anyone on your network can change the settings. Set one later in Tools -> Settings -> Advanced.\033[0m"
fr_ask FR_ICE_PWD "Live stream (icecast) password (empty = random)" "" secret STREAM_PASSWORD
[ -z "$FR_ICE_PWD" ] && FR_ICE_PWD=$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')
fr_ask FR_BIRDWEATHER_ID "BirdWeather ID (empty = none)" "" "" BIRDWEATHER_ID
fr_ask FR_APPRISE_URL "Notification URL for Apprise, e.g. tgram://token/chat (empty = none)" "" "" APPRISE_URL

echo "First-run settings: site '${FR_SITE_NAME}', ${FR_LATITUDE}/${FR_LONGITUDE}, timezone ${FR_TIMEZONE:-unchanged}, info ${FR_INFO_SITE}, model ${FR_MODEL}, language ${FR_LANGUAGE}, state list ${FR_REGION:-none}, web password $([ -n "$FR_CADDY_PWD" ] && echo set || echo none), BirdWeather $([ -n "$FR_BIRDWEATHER_ID" ] && echo set || echo none), notifications $([ -n "$FR_APPRISE_URL" ] && echo set || echo none)"

# nobody answered (no terminal, no seed file): the web interface opens on the setup wizard
# until it is saved (scripts/setup_wizard.php, US-51c part 2)
FR_WIZARD=
[ -z "$fr_interactive" ] && [ ${#fr_seed[@]} -eq 0 ] && FR_WIZARD=1
unset fr_seed
[ -n "$fr_xtrace" ] && set -x
true
