"""Species lists of the station: ~/BirdNET-Pi/species_lists/<name>.txt, one of them active.

The active list is include_species_list.txt (what the analysis applies and Tools > Included edits); it is
a link to the chosen file, so editing it edits that list and switching lists loses nothing.
SPECIES_LIST in birdnet.conf (Settings > Location > Species list filter):
  ''       None — no list, the model's species distribution (location filter) decides
  BR-<UF>  a Brazilian state: built once in species_lists/ from model/include_lists/BR-<UF>.txt (birds
           with WikiAves records, CBRO names) plus every non-bird class of the model, then yours to edit
  <name>   any other file of species_lists/
A hand-made include_species_list.txt that is not a link is saved first as species_lists/custom.txt.

Usage: select_species_list.py [--if-needed]
  --if-needed  complete a state list built before the model's non-bird classes were known
               (V3 is downloaded on first use) and fix a missing link; nothing else
"""
import csv
import json
import os
import sys

from utils.helpers import get_settings, get_language, MODEL_PATH

HOME = os.path.expanduser('~/BirdNET-Pi')
LIST_DIR = os.path.join(HOME, 'species_lists')
ACTIVE = os.path.join(HOME, 'include_species_list.txt')
STATE = os.path.join(LIST_DIR, '.built.json')


def _read(path):
    if not os.path.isfile(path):
        return {}
    with open(path, encoding='utf-8') as f:
        return {sci: com for sci, _, com in (line.strip().partition('_') for line in f) if sci}


def _write(path, entries):
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.writelines(f'{sci}_{com}\n' for sci, com in sorted(entries.items()))
    os.replace(tmp, path)


def _non_birds(model):
    path = os.path.join(MODEL_PATH, f'{model}_Labels.csv')
    out = {}
    if os.path.isfile(path):
        with open(path, encoding='utf-8-sig') as f:
            for row in csv.DictReader(f, delimiter=';'):
                if row.get('class') and row['class'] != 'Aves':
                    out[row['sci_name']] = row.get('com_name') or row['sci_name']
    return out


def _complete(model):
    # only models fetched on first use (BirdNET+ V3) tell bird from non-bird classes
    return not model.startswith('BirdNET-Plus') or os.path.isfile(os.path.join(MODEL_PATH, f'{model}_Labels.csv'))


def _built():
    try:
        with open(STATE) as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def build_state(region, conf, keep=None):
    try:
        names = get_language(conf['DATABASE_LANG'])
    except OSError:
        names = {}
    entries = dict(keep or {})
    for sci, com in _read(os.path.join(MODEL_PATH, 'include_lists', f'{region}.txt')).items():
        entries.setdefault(sci, names.get(sci) or com or sci)
    for sci, com in _non_birds(conf['MODEL']).items():
        entries.setdefault(sci, names.get(sci) or com)
    _write(os.path.join(LIST_DIR, f'{region}.txt'), entries)
    built = _built()
    built[region] = {'model': conf['MODEL'], 'complete': _complete(conf['MODEL'])}
    with open(STATE, 'w') as f:
        json.dump(built, f)
    print(f'Species list {region} built: {len(entries)} species')


def save_unsaved():
    """A regular (not linked) non-empty include_species_list.txt is the user's own list: keep it."""
    if os.path.islink(ACTIVE) or not os.path.isfile(ACTIVE) or os.path.getsize(ACTIVE) == 0:
        return None
    content = _read(ACTIVE)
    for name in os.listdir(LIST_DIR):
        if name.endswith('.txt') and _read(os.path.join(LIST_DIR, name)) == content:
            return name[:-4]
    target, n = 'custom', 1
    while os.path.exists(os.path.join(LIST_DIR, f'{target}.txt')):
        n += 1
        target = f'custom-{n}'
    _write(os.path.join(LIST_DIR, f'{target}.txt'), content)
    print(f'Previous Included species list saved as species_lists/{target}.txt')
    return target


def activate(name):
    tmp = ACTIVE + '.tmp'
    if os.path.lexists(tmp):
        os.remove(tmp)
    if name:
        os.symlink(os.path.join(LIST_DIR, f'{name}.txt'), tmp)
    else:
        open(tmp, 'w').close()  # None: an empty list = no filter
    os.replace(tmp, ACTIVE)


def main():
    if_needed = '--if-needed' in sys.argv
    os.makedirs(LIST_DIR, exist_ok=True)
    conf = get_settings()
    name = (conf.get('SPECIES_LIST') or '').strip()
    if name and not all(c.isalnum() or c in '-_' for c in name):
        print(f'Invalid species list name: {name}', file=sys.stderr)
        return 1
    is_state = len(name) == 5 and name.startswith('BR-') and \
        os.path.isfile(os.path.join(MODEL_PATH, 'include_lists', f'{name}.txt'))
    path = os.path.join(LIST_DIR, f'{name}.txt') if name else ''

    if if_needed:
        if is_state and os.path.isfile(path) and not _built().get(name, {}).get('complete', True) \
                and _complete(conf['MODEL']):
            build_state(name, conf, keep=_read(path))   # add the non-bird classes, keep the user's edits
        current = os.path.realpath(ACTIVE) if os.path.islink(ACTIVE) else None
        if name and os.path.isfile(path) and current != os.path.realpath(path):
            save_unsaved()
            activate(name)
        return 0

    save_unsaved()
    if not name:
        activate('')
        print('Species list filter: None (model distribution)')
        return 0
    if not os.path.isfile(path):
        if not is_state:
            print(f'No species list {name} in species_lists/', file=sys.stderr)
            return 1
        build_state(name, conf)
    activate(name)
    print(f'Species list filter: {name}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
