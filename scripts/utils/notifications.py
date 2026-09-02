import apprise
import os
import shutil
import tempfile
import unicodedata
import socket
import requests
import html
import time

from .db import get_todays_count_for, get_this_weeks_count_for
from .helpers import get_settings

userDir = os.path.expanduser('~')
APPRISE_CONFIG = userDir + '/BirdNET-Pi/apprise.txt'
APPRISE_BODY = userDir + '/BirdNET-Pi/body.txt'
NOTIFICATION_TIERS = userDir + '/BirdNET-Pi/notification_tiers.txt'
APPRISE_CONFIG_RARE = userDir + '/BirdNET-Pi/apprise-rare.txt'
APPRISE_BODY_RARE = userDir + '/BirdNET-Pi/body-rare.txt'
APPRISE_TOKENS = userDir + '/BirdNET-Pi/apprise-tokens.txt'

apobjs = {}
images = {}
species_last_notified = {}


def _has_config(path):
    return os.path.exists(path) and os.path.getsize(path) > 0


def _load_apprise_tokens():
    # Station-private token store (name=value per line, mode 600): the apprise
    # config boxes carry only $name placeholders, never the secrets themselves.
    tokens = {}
    try:
        with open(APPRISE_TOKENS) as f:
            for line in f:
                name, _, value = line.strip().partition('=')
                if name and value:
                    tokens[name] = value
    except OSError:
        pass
    return tokens


def notify(body, title, attached="", tier='normal'):
    # Rare species use their own channel file when it is configured;
    # an empty/missing apprise-rare.txt falls back to the normal channels.
    config_path = APPRISE_CONFIG
    if tier == 'rare' and _has_config(APPRISE_CONFIG_RARE):
        config_path = APPRISE_CONFIG_RARE
    apobj = apobjs.get(config_path)
    if apobj is None:
        asset = apprise.AppriseAsset(
            plugin_paths=[
                userDir + "/.apprise/plugins",
                userDir + "/.config/apprise/plugins",
            ]
        )
        apobj = apprise.Apprise(asset=asset)
        config = apprise.AppriseConfig()
        try:
            with open(config_path) as f:
                content = f.read()
        except OSError:
            content = ''
        tokens = _load_apprise_tokens()
        # longest name first, so $token_telegram_bot is never clipped by $token_telegram
        for name in sorted(tokens, key=len, reverse=True):
            content = content.replace('$' + name, tokens[name])
        config.add_config(content, format='text')
        apobj.add(config)
        apobjs[config_path] = apobj

    if attached:
        apobj.notify(
            body=body,
            title=title,
            attach=attached,
        )
    else:
        apobj.notify(
            body=body,
            title=title,
        )


def get_notification_tier(sci_name):
    # Species tiers set on the Species Management page: one 'Sci_Name=tier' line
    # per non-normal species (muted/rare); a species not listed gets the
    # NOTIFICATION_DEFAULT_TIER setting (normal when absent/invalid).
    try:
        with open(NOTIFICATION_TIERS) as f:
            for line in f:
                name, _, tier = line.strip().partition('=')
                if name == sci_name:
                    return tier.lower() or 'normal'
    except OSError:
        pass
    default = (get_settings().get('NOTIFICATION_DEFAULT_TIER') or 'normal').lower()
    return default if default in ('muted', 'normal', 'rare') else 'normal'


def sendAppriseNotifications(sci_name, com_name, confidence, confidencepct, path, date, time_of_day, week, latitude, longitude, cutoff, sens, overlap, file_path=""):
    def render_template(template, reason=""):
        ret = template.replace("$sciname", sci_name) \
            .replace("$comname", com_name) \
            .replace("$confidencepct", str(confidencepct)) \
            .replace("$confidence", str(confidence)) \
            .replace("$listenurl", listenurl) \
            .replace("$friendlyurl", friendlyurl) \
            .replace("$date", str(date)) \
            .replace("$time", str(time_of_day)) \
            .replace("$week", str(week)) \
            .replace("$latitude", str(latitude)) \
            .replace("$longitude", str(longitude)) \
            .replace("$cutoff", str(cutoff)) \
            .replace("$sens", str(sens)) \
            .replace("$flickrimage", image_url if "{" in body else "") \
            .replace("$image", image_url if "{" in body else "") \
            .replace("$overlap", str(overlap)) \
            .replace("$audio", "") \
            .replace("$reason", reason)
        return ret

    tier = get_notification_tier(sci_name)
    if tier == 'muted':
        return

    if tier == 'rare':
        # Rare species notify on every recording file with a detection (upstream
        # apprise() dedups per file), with the audio clip attached, bypassing
        # the name filters and the per-species rate limit.
        if not (_has_config(APPRISE_CONFIG) or _has_config(APPRISE_CONFIG_RARE)):
            return
    elif not should_notify(com_name):
        return

    settings_dict = get_settings()
    title = html.unescape(settings_dict.get('APPRISE_NOTIFICATION_TITLE'))
    f = open(APPRISE_BODY, 'r')
    body = f.read()

    if tier == 'rare':
        # Rare species may carry their own title/body; empty falls back to Normal
        rare_title = settings_dict.get('APPRISE_NOTIFICATION_TITLE_RARE')
        if rare_title:
            title = html.unescape(rare_title)
        if _has_config(APPRISE_BODY_RARE):
            body = open(APPRISE_BODY_RARE, 'r').read()

    websiteurl = settings_dict.get('BIRDNETPI_URL')
    if websiteurl is None or len(websiteurl) == 0:
        websiteurl = f"http://{socket.gethostname()}.local"

    listenurl = f"{websiteurl}?filename={path}"
    friendlyurl = f"[Listen here]({listenurl})"

    image_url = ""
    if "$flickrimage" in body or "$image" in body:
        if com_name not in images:
            try:
                url = f"http://localhost/api/v1/image/{sci_name}"
                resp = requests.get(url=url, timeout=10).json()
                images[com_name] = resp['data']['image_url']
            except Exception as e:
                print("IMAGE API ERROR:", e)
        image_url = images.get(com_name, "")

    # The $audio tag in the body template is the ONLY thing that attaches the
    # detection clip — identical behavior on every tier (owner rule 2026-09-02).
    attach_audio = "$audio" in body
    temp_attachments = []

    def build_attachments():
        attachments = []
        if attach_audio and file_path and os.path.isfile(file_path):
            # Attach a temp COPY named send-timestamp + species: the raw
            # extraction name carries colons, which mail clients mangle
            # (apprise's ?name= override proved unreliable). The copy is
            # removed in the finally block below.
            ext = os.path.splitext(file_path)[1] or '.flac'
            stamp = time.strftime('%Y-%m-%d_%Hh%Mm%Ss')
            # ASCII-only: apprise's email plugin RFC2047-encodes the whole
            # Content-Disposition header on non-ASCII names (accented species
            # like "Pica-pau-de-cabeça-amarela") and Gmail then shows "noname".
            safe_name = unicodedata.normalize('NFKD', com_name).encode('ascii', 'ignore').decode('ascii')
            safe_name = safe_name.replace(' ', '_').replace("'", '')
            named_copy = os.path.join(tempfile.gettempdir(), stamp + '_' + safe_name + ext)
            try:
                shutil.copy2(file_path, named_copy)
                temp_attachments.append(named_copy)
                attachments.append(named_copy)
            except OSError:
                attachments.append(file_path)
        if image_url:
            attachments.append(image_url)
        return attachments

    try:
        if tier == 'rare':
            reason = "rare species"
            notify_body = render_template(body, reason)
            notify_title = render_template(title, reason)
            notify(notify_body, notify_title, build_attachments(), tier='rare')
            species_last_notified[com_name] = int(time.time())
            return

        if settings_dict.get('APPRISE_NOTIFY_EACH_DETECTION') == "1":
            reason = "detection"
            notify_body = render_template(body, reason)
            notify_title = render_template(title, reason)
            notify(notify_body, notify_title, build_attachments())
            species_last_notified[com_name] = int(time.time())

        APPRISE_NOTIFICATION_NEW_SPECIES_DAILY_COUNT_LIMIT = 1  # Notifies the first N per day.
        if settings_dict.get('APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY') == "1":
            numberDetections = get_todays_count_for(sci_name)
            if 0 < numberDetections <= APPRISE_NOTIFICATION_NEW_SPECIES_DAILY_COUNT_LIMIT:
                reason = "first time today"
                notify_body = render_template(body, reason)
                notify_title = render_template(title, reason)
                notify(notify_body, notify_title, build_attachments())
                species_last_notified[com_name] = int(time.time())

        if settings_dict.get('APPRISE_NOTIFY_NEW_SPECIES') == "1":
            numberDetections = get_this_weeks_count_for(sci_name)
            if 0 < numberDetections <= 5:
                reason = f"only seen {numberDetections} times in last 7d"
                notify_body = render_template(body, reason)
                notify_title = render_template(title, reason)
                notify(notify_body, notify_title, build_attachments())
                species_last_notified[com_name] = int(time.time())

    finally:
        for t in temp_attachments:
            try:
                os.remove(t)
            except OSError:
                pass

def should_notify(com_name):
    settings_dict = get_settings()
    if not (os.path.exists(APPRISE_CONFIG) and os.path.getsize(APPRISE_CONFIG) > 0):
        return False

    # check if this is an excluded species
    APPRISE_ONLY_NOTIFY_SPECIES_NAMES = settings_dict.get('APPRISE_ONLY_NOTIFY_SPECIES_NAMES')
    if APPRISE_ONLY_NOTIFY_SPECIES_NAMES is not None and APPRISE_ONLY_NOTIFY_SPECIES_NAMES.strip() != "":
        excluded_species = [bird.lower().replace(" ", "") for bird in APPRISE_ONLY_NOTIFY_SPECIES_NAMES.split(",")]
        if com_name.lower().replace(" ", "") in excluded_species:
            return False

    # check if this is an included species
    APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2 = settings_dict.get('APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2')
    if APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2 is not None and APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2.strip() != "":
        included_species = [bird.lower().replace(" ", "") for bird in APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2.split(",")]
        if com_name.lower().replace(" ", "") not in included_species:
            return False

    # is it still too soon?
    APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES = settings_dict.get('APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES')
    if APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES != "0":
        if species_last_notified.get(com_name) is not None:
            try:
                if int(time.time()) - species_last_notified[com_name] < int(APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES):
                    return False
            except Exception as e:
                print("APPRISE NOTIFICATION EXCEPTION: " + str(e))
                return False

    return True


if __name__ == "__main__":
    print("notfications")
