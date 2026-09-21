"""Shadow mode: a second model analyses the same recordings, beside the official one.

The official model (MODEL) keeps feeding birds.db, the extractions and the notifications. The shadow model
(SHADOW_MODEL_NAME) only writes what it would have detected to a database of its own (scripts/birds_shadow.db), so
both can be compared over weeks before a model switch. Nothing is extracted and nobody is notified.

birdnet.conf keys (none of them ends in the name of an official key: config.php rewrites those with unanchored
patterns such as "/MODEL=.*/"): SHADOW_MODEL_NAME (empty = off), SHADOW_MIN_CONF, SHADOW_SENS, SHADOW_GEO_THRESH.
"""
import logging
import os
import sqlite3
import time

from . import analysis
from .classes import Detection
from .helpers import get_settings, get_language, BASE_PATH
from .models import BirdNETPlusV3, get_model

log = logging.getLogger(__name__)

SHADOW_DB_PATH = os.path.join(BASE_PATH, 'scripts/birds_shadow.db')
SHADOW_MODEL = None
SHADOW_FAILED = False


def shadow_settings():
    conf = get_settings()
    return {
        'model': conf.get('SHADOW_MODEL_NAME', fallback='').strip(),
        # the defaults are the ones the BirdNET Live app uses for BirdNET+ V3.0
        'confidence': conf.getfloat('SHADOW_MIN_CONF', fallback=0.35),
        'sensitivity': conf.getfloat('SHADOW_SENS', fallback=1.0),
        'sf_thresh': conf.getfloat('SHADOW_GEO_THRESH', fallback=0.03),
    }


def load_shadow_model():
    global SHADOW_MODEL, SHADOW_FAILED
    settings = shadow_settings()
    if not settings['model'] or SHADOW_FAILED:
        return None
    if settings['model'] == get_settings()['MODEL']:
        log.warning('SHADOW_MODEL_NAME is the official model: shadow mode is off')
        SHADOW_FAILED = True
        return None
    if SHADOW_MODEL is None:
        log.info('LOADING SHADOW MODEL %s...', settings['model'])
        try:
            if settings['model'] == BirdNETPlusV3.model_name:
                SHADOW_MODEL = BirdNETPlusV3(settings['sensitivity'], settings['sf_thresh'])
            else:
                SHADOW_MODEL = get_model(settings['model'])
        except Exception as e:
            # a broken shadow model must never stop the station
            log.error('Shadow model could not be loaded, shadow mode is off until restart: %s', e)
            SHADOW_FAILED = True
            return None
        if SHADOW_MODEL is None:
            log.error('Unknown SHADOW_MODEL_NAME %s, shadow mode is off until restart', settings['model'])
            SHADOW_FAILED = True
            return None
        log.info('SHADOW MODEL LOADED!')
    return SHADOW_MODEL


def overlaps_human(start, end):
    return any(start < h_end and end > h_start for h_start, h_end in analysis.LAST_HUMAN_SLOTS)


def run_shadow_analysis(file):
    """Analyse a recording with the shadow model. Call it right after run_analysis() on the same file."""
    model = load_shadow_model()
    if model is None:
        return []

    settings = shadow_settings()
    conf = get_settings()
    overlap = conf.getfloat('OVERLAP')
    include_list = analysis.loadCustomSpeciesList(os.path.expanduser("~/BirdNET-Pi/include_species_list.txt"))
    exclude_list = analysis.loadCustomSpeciesList(os.path.expanduser("~/BirdNET-Pi/exclude_species_list.txt"))
    whitelist_list = analysis.loadCustomSpeciesList(os.path.expanduser("~/BirdNET-Pi/whitelist_species_list.txt"))
    names = get_language(conf['DATABASE_LANG'])
    model_names = getattr(model, 'common_names', {})

    start = time.time()
    try:
        chunks = analysis.readAudioData(file.file_name, overlap, model.sample_rate, model.chunk_duration)
    except Exception as e:
        log.error('Shadow analysis could not read %s: %s', file.file_name, e)
        return []

    model.set_meta_data(conf.getfloat('LATITUDE'), conf.getfloat('LONGITUDE'), file.week)
    predicted_species_list = model.get_species_list()

    detections = []
    pred_start = 0.0
    for chunk in chunks:
        pred_end = pred_start + model.chunk_duration
        # the official model found a human voice here: the shadow model keeps nothing of that window either
        if not overlaps_human(pred_start, pred_end):
            for sci_name, confidence in model.predict(chunk)[:10]:
                if confidence < settings['confidence']:
                    break
                if sci_name not in include_list and len(include_list) != 0:
                    continue
                if sci_name in exclude_list and len(exclude_list) != 0:
                    continue
                if sci_name not in predicted_species_list and len(predicted_species_list) != 0 and sci_name not in whitelist_list:
                    continue
                com_name = names.get(sci_name) or model_names.get(sci_name, sci_name)
                detections.append(Detection(file.file_date, pred_start, pred_end, sci_name, com_name, confidence))
        pred_start = pred_end - overlap

    write_shadow_detections(file, detections, settings)
    log.info('SHADOW DONE! %d detections, time %.2f SECONDS', len(detections), time.time() - start)
    return detections


def write_shadow_detections(file, detections, settings):
    if not detections:
        return
    conf = get_settings()
    con = None
    try:
        con = sqlite3.connect(SHADOW_DB_PATH, timeout=10)
        con.execute("""CREATE TABLE IF NOT EXISTS detections (
            Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, Com_Name VARCHAR(100) NOT NULL,
            Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, Overlap FLOAT,
            File_Name VARCHAR(100) NOT NULL, Model VARCHAR(100) NOT NULL)""")
        con.execute("CREATE INDEX IF NOT EXISTS detections_date_time ON detections (Date, Time)")
        con.executemany("INSERT INTO detections VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            (d.date, d.time, d.scientific_name, d.common_name, d.confidence, conf['LATITUDE'], conf['LONGITUDE'],
             settings['confidence'], str(d.week), settings['sensitivity'], conf['OVERLAP'],
             os.path.basename(file.file_name), settings['model']) for d in detections])
        con.commit()
    except sqlite3.Error as e:
        log.warning('Shadow database: %s', e)
    finally:
        if con is not None:
            con.close()
