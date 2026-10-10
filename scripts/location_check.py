#!/usr/bin/env python3
"""Has the station moved? (owner 2026-10-10) Run at every boot by birdnet_location_check.service.

The Pi has no GPS: the network gives an approximate position (geolocation of the public IP: ipapi.co, then
ip-api.com — city level, enough for the 100 km threshold). When it is farther than LOCATION_MOVE_KM (default 100 km) from LATITUDE/LONGITUDE
the station changes nothing by itself: it records the finding in ~/BirdNET-Pi/location_check.json, notifies
through Apprise once, and the Now page offers "Use this location" (coordinates, Brazilian state species list,
timezone) or "Not moved" (remembered; asked again only after another move of that distance).
LOCATION_CHECK=0 in birdnet.conf (Basic Settings) turns it off — the check sends the public IP to those services.
Standard library only. Usage: location_check.py [--force] [--json]
  --force  run even with LOCATION_CHECK=0;  --json  print the result as JSON (the Auto Locate Now button)
"""
import json
import math
import os
import subprocess
import sys
import time
import urllib.request

HOME = os.path.expanduser('~/BirdNET-Pi')
STATE = os.path.join(HOME, 'location_check.json')
LIMIT_KM = 100  # default of LOCATION_MOVE_KM (Station Setup / Basic Settings)
UA = {'User-Agent': 'BirdnetPi++ location check (github.com/EvaldoOliveira/BirdnetPiPlusPlus)'}


def conf():
    out = {}
    with open('/etc/birdnet/birdnet.conf', encoding='utf-8') as f:
        for line in f:
            k, sep, v = line.strip().partition('=')
            if sep and not k.startswith('#'):
                out[k] = v.strip().strip('"')
    return out


def km(lat1, lon1, lat2, lon2):
    p1, p2 = math.radians(lat1), math.radians(lat2)
    a = math.sin((p2 - p1) / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(math.radians(lon2 - lon1) / 2) ** 2
    return 6371 * 2 * math.asin(math.sqrt(a))


def get(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=8) as r:
        return json.load(r)


def network_position():
    try:
        j = get('https://ipapi.co/json/')
        if j.get('latitude') is not None:
            return {'lat': float(j['latitude']), 'lon': float(j['longitude']), 'city': j.get('city', ''),
                    'region': j.get('region', ''), 'country': j.get('country_code', ''), 'timezone': j.get('timezone', ''),
                    'source': 'ipapi.co'}
    except Exception:
        pass
    j = get('http://ip-api.com/json/?fields=status,lat,lon,city,regionName,countryCode,timezone')
    if j.get('status') != 'success':
        raise RuntimeError('no network position')
    return {'lat': float(j['lat']), 'lon': float(j['lon']), 'city': j.get('city', ''), 'region': j.get('regionName', ''),
            'country': j.get('countryCode', ''), 'timezone': j.get('timezone', ''), 'source': 'ip-api.com'}


def notify(c, text):
    apprise, cfg = os.path.join(HOME, 'birdnet/bin/apprise'), os.path.join(HOME, 'apprise.txt')
    if os.access(apprise, os.X_OK) and os.path.getsize(cfg if os.path.exists(cfg) else os.devnull) > 0:
        subprocess.run([apprise, '-t', f"BirdnetPi++ {c.get('SITE_NAME') or os.uname().nodename}", '-b', text, f'--config={cfg}'],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)


def main():
    c = conf()
    if c.get('LOCATION_CHECK', '1') == '0' and '--force' not in sys.argv:
        return
    global LIMIT_KM
    try:
        LIMIT_KM = max(1, int(float(c.get('LOCATION_MOVE_KM') or 100)))
    except ValueError:
        LIMIT_KM = 100
    try:
        lat, lon = float(c['LATITUDE']), float(c['LONGITUDE'])
    except (KeyError, ValueError):
        return
    pos = None
    for _ in range(12):  # the network may need a while after boot (Wi-Fi, hotspot)
        try:
            pos = network_position()
            break
        except Exception:
            time.sleep(10)
    if pos is None:
        print(json.dumps({'error': 'no network position'}) if '--json' in sys.argv else 'location check: no network position')
        return
    try:
        with open(STATE, encoding='utf-8') as f:
            old = json.load(f)
    except (OSError, ValueError):
        old = {}
    dist = round(km(lat, lon, pos['lat'], pos['lon']))
    dismissed = old.get('dismissed')  # the network position the owner said "Not moved" to
    moved = dist > LIMIT_KM and not (dismissed and km(dismissed['lat'], dismissed['lon'], pos['lat'], pos['lon']) <= LIMIT_KM)
    state = dict(pos, checked=time.strftime('%Y-%m-%d %H:%M:%S'), distance_km=dist, moved=moved,
                 configured={'lat': lat, 'lon': lon}, dismissed=dismissed,
                 notified=old.get('notified') if moved else None)
    where = ', '.join(x for x in (pos['city'], pos['region'], pos['country']) if x)
    if moved and not (old.get('notified') and km(old['notified']['lat'], old['notified']['lon'], pos['lat'], pos['lon']) <= LIMIT_KM):
        notify(c, f'The station seems to be in {where}, about {dist} km from its configured location. '
                  'Open the Now page to use this location (coordinates, species list, timezone) or keep the current one.')
        state['notified'] = {'lat': pos['lat'], 'lon': pos['lon']}
    tmp = STATE + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(state, f, ensure_ascii=False, indent=1)
    os.chmod(tmp, 0o664)  # the web pages (caddy, in the station user's group) record the answer in it
    os.replace(tmp, STATE)
    if '--json' in sys.argv:
        print(json.dumps(dict(pos, distance_km=dist, moved=moved, where=where, limit_km=LIMIT_KM), ensure_ascii=False))
    else:
        print(f"location check: {where} ({pos['source']}), {dist} km from the configured location, moved={moved}")


if __name__ == '__main__':
    main()
