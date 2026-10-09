"""Builds model/species_info.csv: what each label of the station's models is and where it lives (owner 2026-10-09), so
the species lists can be filtered by type and region. Run once by the maintainer (it queries GBIF, the Global
Biodiversity Information Facility, for every scientific name — about an hour); the result ships with the fork.

Columns (';' separated):
  sci_name    scientific name as in the labels
  type        bird, mammal, amphibian, reptile, insect, other_animal, domestic, noise or unknown
  region      Global (records on four or more continents), else the continents with at least 3 % of the records,
              '+' separated (Africa, Antarctica, Asia, Europe, North America, Oceania, South America); 'Ocean' is added
              when most records have no continent (at sea)
  continents  share of the records per continent, e.g. 'South America 62|North America 38'
  records     GBIF occurrence records of the species
  gbif        GBIF match: class / status (ACCEPTED, SYNONYM...), '/via <name>' when found under another model's name
              for the same English name (recent genus changes GBIF does not know yet), 'labels' when only the class
              of the BirdNET-Plus labels table was available, empty when the name is not a taxon
On a station, scripts/update_species_info.sh (end of every install and update) runs it in the background with
--base model/species_info.csv --out model/species_info_local.csv: only the names missing from the shipped table are
looked up (a new model's labels), into a local file outside git.
Usage: build_species_info.py <labels files: BirdNET-Plus *_Labels.csv and *_Labels.txt, V2.4 labels, l18n/labels_en.json>... [--out model/species_info.csv] [--workers 8]
"""
import argparse
import csv
import json
import os
import sys
import time
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

API = 'https://api.gbif.org/v1/'
CLASS_TYPE = {'Aves': 'bird', 'Mammalia': 'mammal', 'Amphibia': 'amphibian', 'Reptilia': 'reptile', 'Insecta': 'insect'}
CONTINENTS = {'AFRICA': 'Africa', 'ANTARCTICA': 'Antarctica', 'ASIA': 'Asia', 'EUROPE': 'Europe',
              'NORTH_AMERICA': 'North America', 'OCEANIA': 'Oceania', 'SOUTH_AMERICA': 'South America'}
# domestic forms (their calls are heard around houses and farms); the wild relatives keep their own type
DOMESTIC = {'Canis familiaris', 'Canis lupus familiaris', 'Felis catus', 'Bos taurus', 'Capra hircus', 'Ovis aries',
            'Equus caballus', 'Equus asinus', 'Sus domesticus', 'Gallus gallus domesticus', 'Anser anser domesticus',
            'Dog'}
# labels of the models that are not species
NOISE = {'Human vocal', 'Human non-vocal', 'Human whistle', 'Engine', 'Fireworks', 'Gun', 'Noise', 'Power tools',
         'Siren', 'Environmental', 'Human'}


def get(path, tries=6):
    for i in range(tries):
        try:
            req = urllib.request.Request(API + path, headers={'User-Agent': 'BirdnetPi++ species_info build (one-off)'})
            with urllib.request.urlopen(req, timeout=40) as r:
                return json.load(r)
        except Exception as e:
            if i == tries - 1:
                raise
            # GBIF answers 429 when asked too fast: wait longer each time
            time.sleep((15 if getattr(e, 'code', 0) == 429 else 2) * (i + 1))


ALT = {}         # scientific name -> other scientific names with the same English name in the model labels
LABEL_CLASS = {}  # scientific name -> class column of the BirdNET-Plus labels CSV


def lookup(sci):
    r = class_checked(lookup_name(sci), sci)
    if r['type'] != 'unknown':
        return r
    for alt in sorted(ALT.get(sci, ())):
        a = class_checked(lookup_name(alt), sci)
        if a['type'] != 'unknown' and a['gbif'] != 'labels':
            a.update(sci_name=sci, gbif=a['gbif'] + '/via ' + alt)
            return a
    if LABEL_CLASS.get(sci) in CLASS_TYPE:
        r.update(type=CLASS_TYPE[LABEL_CLASS[sci]], gbif='labels')
    return r


def class_checked(r, sci):
    # a GBIF match without a class (Bubo capensis, Ortalis guttata matched a record of another rank) is not trusted:
    # the type comes from the BirdNET-Plus labels table and the region of that match is dropped
    if r['type'] in ('other_animal', 'unknown') and r['gbif'].startswith('/') and LABEL_CLASS.get(sci) in CLASS_TYPE:
        r.update(type=CLASS_TYPE[LABEL_CLASS[sci]], region='', continents='', records='', gbif='labels')
    return r


def lookup_name(sci):
    if sci in NOISE:
        return {'sci_name': sci, 'type': 'noise', 'region': '', 'continents': '', 'records': '', 'gbif': ''}
    if sci in DOMESTIC and ' ' not in sci:
        return {'sci_name': sci, 'type': 'domestic', 'region': 'Global', 'continents': '', 'records': '', 'gbif': ''}
    m = get('species/match?' + urllib.parse.urlencode({'name': sci, 'strict': 'true'}))
    key = m.get('acceptedUsageKey') or m.get('usageKey')
    if not key or m.get('matchType') == 'NONE':
        return {'sci_name': sci, 'type': 'unknown', 'region': '', 'continents': '', 'records': '', 'gbif': ''}
    f = get(f'occurrence/search?taxonKey={key}&facet=continent&limit=0&facetLimit=10')
    total = f.get('count') or 0
    counts = {CONTINENTS.get(c['name'], c['name']): c['count'] for c in (f.get('facets') or [{}])[0].get('counts', [])} if f.get('facets') else {}
    share = {k: v / total * 100 for k, v in counts.items()} if total else {}
    main = [k for k, v in sorted(share.items(), key=lambda x: -x[1]) if v >= 3]
    region = 'Global' if len(main) >= 4 else '+'.join(main)
    if total and sum(counts.values()) < total * 0.5:
        region = (region + '+' if region else '') + 'Ocean'
    cls = m.get('class', '')
    typ = 'domestic' if sci in DOMESTIC else CLASS_TYPE.get(cls, 'other_animal' if m.get('kingdom') == 'Animalia' else 'unknown')
    return {'sci_name': sci, 'type': typ, 'region': region,
            'continents': '|'.join(f'{k} {round(v)}' for k, v in sorted(share.items(), key=lambda x: -x[1]) if round(v) > 0),
            'records': total, 'gbif': f"{cls}/{m.get('status', '')}"}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('labels', nargs='+')
    ap.add_argument('--out', default=os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'model', 'species_info.csv'))
    ap.add_argument('--workers', type=int, default=4)
    ap.add_argument('--base', help='table whose names are already known (skipped)')
    a = ap.parse_args()
    names = set()
    english = {}
    for f in a.labels:
        if f.endswith('.json'):  # model/l18n/labels_en.json: English names only (old names of renamed species)
            with open(f, encoding='utf-8') as h:
                for sci, com in json.load(h).items():
                    english.setdefault(com.lower(), set()).add(sci)
            continue
        with open(f, encoding='utf-8-sig') as h:
            for line in h:
                line = line.strip()
                if not line or line.startswith('idx;'):
                    continue
                if line.count(';') >= 3:  # BirdNET-Plus labels CSV: idx;id;sci_name;com_name;class;order
                    p = line.split(';')
                    names.add(p[2])
                    LABEL_CLASS[p[2]] = p[4] if len(p) > 4 else ''
                    english.setdefault(p[3].lower(), set()).add(p[2])
                else:
                    sci, _, com = line.partition('_')
                    names.add(sci)
                    english.setdefault(com.lower(), set()).add(sci)
    for scis in english.values():
        for n in scis & names:
            ALT.setdefault(n, set()).update(scis - {n})
    done = {}
    if os.path.exists(a.out):  # resumable: names already in the table are kept
        with open(a.out, encoding='utf-8') as h:
            done = {r['sci_name']: r for r in csv.DictReader(h, delimiter=';') if r['type'] != 'unknown' and r['gbif'] != 'labels'}
    known = set()
    if a.base and os.path.exists(a.base):
        with open(a.base, encoding='utf-8') as h:
            known = {r['sci_name'] for r in csv.DictReader(h, delimiter=';')}
    todo = sorted(n for n in names if n not in done and n not in known)
    if not todo and a.base:
        print('nothing missing', flush=True)
        return
    print(f'{len(names)} names, {len(done)} already done, {len(todo)} to look up', flush=True)
    fields = ['sci_name', 'type', 'region', 'continents', 'records', 'gbif']

    def save():
        tmp = a.out + '.tmp'
        with open(tmp, 'w', encoding='utf-8', newline='') as h:
            w = csv.DictWriter(h, fieldnames=fields, delimiter=';')
            w.writeheader()
            for k in sorted(done):
                w.writerow(done[k])
        os.replace(tmp, a.out)

    with ThreadPoolExecutor(a.workers) as ex:
        for i, r in enumerate(ex.map(lambda n: _safe(n), todo), 1):
            if r:
                done[r['sci_name']] = r
            if i % 250 == 0:
                save()
                print(f'{i}/{len(todo)}', flush=True)
    save()
    print('done', len(done), flush=True)


def _safe(n):
    try:
        return lookup(n)
    except Exception as e:
        print(f'failed {n}: {e}', file=sys.stderr, flush=True)
        return None


if __name__ == '__main__':
    main()
