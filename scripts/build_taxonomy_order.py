"""Builds model/taxonomy_order.csv: the taxonomic position of every model label (owner 2026-10-10), so species lists can
be shown in field-guide order (By Hour: Sort › Taxonomy). Run once by the maintainer; the result ships with the fork.

Source: the eBird/Clements taxonomy (public download https://api.ebird.org/v2/ref/taxonomy/ebird?fmt=csv), its
TAXON_ORDER sequence, order and family. A bird missing under the model's name takes the first position of its genus;
non-birds (and anything unknown) come after every bird, grouped by class.
Columns (';'): sci_name;taxon_order;order;family
Usage: build_taxonomy_order.py [taxonomy.csv]   (downloads the taxonomy when no file is given)
"""
import csv
import io
import os
import sys
import urllib.request

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(HERE, 'model', 'taxonomy_order.csv')
INFO = os.path.join(HERE, 'model', 'species_info.csv')
URL = 'https://api.ebird.org/v2/ref/taxonomy/ebird?fmt=csv'
CLASS_BASE = {'mammal': 100000, 'amphibian': 200000, 'reptile': 300000, 'insect': 400000}


def main():
    if len(sys.argv) > 1:
        text = open(sys.argv[1], encoding='utf-8').read()
    else:
        req = urllib.request.Request(URL, headers={'User-Agent': 'BirdnetPi++ taxonomy build (one-off)'})
        text = urllib.request.urlopen(req, timeout=120).read().decode('utf-8')
    tax, genus = {}, {}
    for r in csv.DictReader(io.StringIO(text)):
        order = float(r['TAXON_ORDER'])
        tax.setdefault(r['SCIENTIFIC_NAME'], (order, r['ORDER'], r['FAMILY_SCI_NAME']))
        g = r['SCIENTIFIC_NAME'].split()[0]
        if g not in genus or order < genus[g][0]:
            genus[g] = (order, r['ORDER'], r['FAMILY_SCI_NAME'])
    with open(INFO, encoding='utf-8') as f:
        labels = [(r['sci_name'], r['type']) for r in csv.DictReader(f, delimiter=';')]
    rows = []
    for sci, typ in labels:
        if sci in tax:
            o = tax[sci]
        elif typ == 'bird' and sci.split()[0] in genus:
            o = genus[sci.split()[0]]
        else:
            o = (CLASS_BASE.get(typ, 900000), '', '')
        rows.append((sci, o[0], o[1], o[2]))
    rows.sort(key=lambda x: (x[1], x[0]))
    with open(OUT, 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f, delimiter=';', lineterminator='\n')
        w.writerow(['sci_name', 'taxon_order', 'order', 'family'])
        for sci, o, ordr, fam in rows:
            w.writerow([sci, ('%g' % o), ordr, fam])
    print(f'{len(rows)} labels -> {OUT}')


if __name__ == '__main__':
    main()
