"""Region-rare detections: is a species unexpected at the station's location, now or in any week?

The station's location model (BirdNET+ V3 geo model; the V2.4 meta model on V2.4 stations) gives, for the
station's coordinates, the occurrence probability of every species in each of the 48 BirdNET weeks. That annual
profile is computed once a day (or when the location or the model changes) and cached in
scripts/region_profile.json. A detection is
  - "vagrant here": the species' highest probability in any week is below VAGRANT_MAX;
  - "out of season here": its probability this week is below OUT_OF_SEASON_MAX and below a quarter of its own
    peak at this location.
Used by the notifications (APPRISE_NOTIFY_REGION_RARE): such a detection goes to the Rare channel with the reason.
"""
import datetime
import json
import logging
import os

from .classes import week48
from .helpers import get_settings, MODEL_PATH

log = logging.getLogger(__name__)

PROFILE_FILE = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'region_profile.json')
VAGRANT_MAX = 0.02
OUT_OF_SEASON_MAX = 0.05

_profile = None


def _compute(conf):
    lat, lon, model_name = conf.getfloat('LATITUDE'), conf.getfloat('LONGITUDE'), conf['MODEL']
    data = {}
    from .models import BirdNETPlusV3, GeoModelV3, MDataModel1, MDataModel2
    if model_name == BirdNETPlusV3.model_name:
        geo = GeoModelV3(model_name, 0.0)
        query = lambda: [(s, sci) for s, sci in geo.get_species_list_details()]
    else:
        with open(os.path.join(MODEL_PATH, 'labels.txt')) as f:
            labels = [line.strip() for line in f if line.strip()]
        geo = MDataModel1(0.0) if conf.getint('DATA_MODEL_VERSION', fallback=2) == 1 else MDataModel2(0.0)
        query = lambda: [(s, label.split('_')[0]) for s, label in geo.get_species_list_details(labels)]
    for week in range(1, 49):
        geo.set_meta_data(lat, lon, week)
        for score, sci in query():
            data.setdefault(sci, [0.0] * 48)[week - 1] = round(float(score), 4)
    return {'date': datetime.date.today().isoformat(), 'lat': lat, 'lon': lon, 'model': model_name, 'data': data}


def get_profile():
    global _profile
    conf = get_settings()
    key = (datetime.date.today().isoformat(), conf.getfloat('LATITUDE'), conf.getfloat('LONGITUDE'), conf['MODEL'])
    if _profile is None and os.path.isfile(PROFILE_FILE):
        try:
            with open(PROFILE_FILE) as f:
                _profile = json.load(f)
        except (OSError, ValueError):
            _profile = None
    if _profile is None or (_profile.get('date'), _profile.get('lat'), _profile.get('lon'), _profile.get('model')) != key:
        try:
            _profile = _compute(conf)
            tmp = PROFILE_FILE + '.tmp'
            with open(tmp, 'w') as f:
                json.dump(_profile, f)
            os.replace(tmp, PROFILE_FILE)
            log.info('region profile computed: %d species', len(_profile['data']))
        except Exception as e:
            log.warning('region profile not available: %s', e)
            return None
    return _profile


def region_rare_reason(sci_name, date=None):
    """'vagrant here', 'out of season here' or None."""
    profile = get_profile()
    if not profile:
        return None
    freqs = profile['data'].get(sci_name)
    if not freqs:
        return None  # the location model does not know the species (no basis to call it rare)
    peak = max(freqs)
    if peak < VAGRANT_MAX:
        return 'vagrant here'
    week = week48(date or datetime.date.today())
    now = freqs[week - 1]
    if now < OUT_OF_SEASON_MAX and now < 0.25 * peak:
        return 'out of season here'
    return None
