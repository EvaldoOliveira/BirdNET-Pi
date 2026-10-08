"""Custom Species List from a Brazilian state (INCLUDE_REGION, e.g. BR-SP).

The list a station sees in Tools > Included becomes: the birds with records in the state
(model/include_lists/<region>.txt, WikiAves) plus every non-bird class of the running model
(frogs, insects, mammals of BirdNET+ V3), so that only the birds that do not occur in the
state are left out. A list the user had edited by hand is kept as a dated .bak copy.

Usage: state_include_list.py [--if-needed] [--merge]
  --if-needed  only when the list was never built for the current INCLUDE_REGION
               (e.g. the V3 model arrived after the installation)
  --merge      keep the species already in the list (update of a station that used the
               old separate regional filter)
"""
import argparse
import csv
import hashlib
import json
import os
import shutil
import sys
from datetime import datetime

from utils.helpers import get_settings, get_language, MODEL_PATH

LIST_FILE = os.path.expanduser('~/BirdNET-Pi/include_species_list.txt')
STATE_FILE = os.path.expanduser('~/BirdNET-Pi/include_species_list.state')


def _species(path):
    # {scientific name: common name} of a list of 'Sci_Common' lines
    if not os.path.isfile(path):
        return {}
    with open(path, encoding='utf-8') as f:
        return {sci: com for sci, _, com in (line.strip().partition('_') for line in f) if sci}


def _labels_csv(model):
    # only models fetched on first use (BirdNET+ V3) tell bird from non-bird classes
    return os.path.join(MODEL_PATH, f'{model}_Labels.csv')


def _non_birds(model):
    non_birds = {}
    if os.path.isfile(_labels_csv(model)):
        with open(_labels_csv(model), encoding='utf-8-sig') as f:
            for row in csv.DictReader(f, delimiter=';'):
                if row.get('class') and row['class'] != 'Aves':
                    non_birds[row['sci_name']] = row.get('com_name') or row['sci_name']
    return non_birds


def _sha(path):
    if not os.path.isfile(path):
        return ''
    with open(path, 'rb') as f:
        return hashlib.sha256(f.read()).hexdigest()


def _read_state():
    try:
        with open(STATE_FILE) as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def _names(conf):
    try:
        return get_language(conf['DATABASE_LANG'])
    except OSError:
        return {}


def build(region, conf, keep=None):
    names = _names(conf)
    lines = {}
    with open(os.path.join(MODEL_PATH, 'include_lists', f'{region}.txt'), encoding='utf-8') as f:
        for line in f:
            sci, _, com = line.strip().partition('_')
            if sci:
                lines[sci] = names.get(sci) or com or sci
    for sci, com in _non_birds(conf['MODEL']).items():
        lines.setdefault(sci, names.get(sci) or com)
    for sci, com in (keep or {}).items():
        lines.setdefault(sci, com or names.get(sci) or sci)
    return [f'{sci}_{com}\n' for sci, com in sorted(lines.items())]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--if-needed', action='store_true')
    parser.add_argument('--merge', action='store_true')
    args = parser.parse_args()

    conf = get_settings()
    region = (conf.get('INCLUDE_REGION') or '').strip()
    state = _read_state()
    built_here = state.get('sha256') and state.get('sha256') == _sha(LIST_FILE)
    edited = os.path.isfile(LIST_FILE) and os.path.getsize(LIST_FILE) > 0 and not built_here

    if args.if_needed and state.get('region', '') == region and (state.get('complete') or not region):
        return 0

    if not region:
        # state list switched off: empty the list only if it is still the one built here
        if built_here:
            open(LIST_FILE, 'w').close()
            print('Custom Species List emptied (no state list)')
        if os.path.exists(STATE_FILE):
            os.remove(STATE_FILE)
        return 0

    if not os.path.isfile(os.path.join(MODEL_PATH, 'include_lists', f'{region}.txt')):
        print(f'No include list for {region}', file=sys.stderr)
        return 1

    keep = _species(LIST_FILE) if (args.merge and edited) else {}
    if edited:
        backup = f'{LIST_FILE}.{datetime.now():%Y%m%d-%H%M%S}.bak'
        shutil.copy2(LIST_FILE, backup)
        print(f'Previous Custom Species List kept as {backup}')
    lines = build(region, conf, keep)
    with open(LIST_FILE, 'w', encoding='utf-8') as f:
        f.writelines(lines)
    # complete = the model's non-bird classes were known (a V3 model not downloaded yet has none)
    complete = not conf['MODEL'].startswith('BirdNET-Plus') or os.path.isfile(_labels_csv(conf['MODEL']))
    with open(STATE_FILE, 'w') as f:
        json.dump({'region': region, 'model': conf['MODEL'], 'complete': complete, 'sha256': _sha(LIST_FILE)}, f)
    print(f'Custom Species List built from {region}: {len(lines)} species')
    return 0


if __name__ == '__main__':
    sys.exit(main())
