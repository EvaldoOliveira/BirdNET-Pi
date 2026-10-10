#!/usr/bin/env python3
"""Brazilian state (UF) of a latitude/longitude, for the states include list (US-51c).

Online first: OpenStreetMap Nominatim reverse geocoding (one request, 5 s timeout, per its
usage policy). Without internet (or when Nominatim fails): the IBGE state boundaries shipped in
model/include_lists/BR-states.geojson, point in polygon. Standard library only, so it runs
before the station's Python environment exists.

Usage: locate_state.py <latitude> <longitude> [--offline] [-v]
Prints the UF (e.g. SP) when the point is in Brazil, nothing otherwise; exit 0 either way.
-v adds the method used on stderr.
"""
import json
import math
import os
import sys
import urllib.request

GEOJSON = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'model', 'include_lists', 'BR-states.geojson')


def online(lat, lon):
    url = ('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=5&addressdetails=1'
           f'&lat={lat}&lon={lon}')
    req = urllib.request.Request(url, headers={'User-Agent': 'BirdNET-Pi installer (github.com/EvaldoOliveira/BirdnetPiPlusPlus)',
                                               'Accept-Language': 'en'})
    with urllib.request.urlopen(req, timeout=5) as r:
        address = json.load(r).get('address', {})
    if address.get('country_code') != 'br':
        return ''
    iso = address.get('ISO3166-2-lvl4', '')  # e.g. BR-SP
    return iso[3:] if iso.startswith('BR-') else ''


def inside_ring(lon, lat, ring):
    hit, j = False, len(ring) - 1
    for i in range(len(ring)):
        xi, yi = ring[i][0], ring[i][1]
        xj, yj = ring[j][0], ring[j][1]
        if (yi > lat) != (yj > lat) and lon < (xj - xi) * (lat - yi) / (yj - yi) + xi:
            hit = not hit
        j = i
    return hit


def offline(lat, lon):
    with open(GEOJSON, encoding='utf-8') as f:
        features = json.load(f)['features']
    for feature in features:
        geometry = feature['geometry']
        polygons = geometry['coordinates'] if geometry['type'] == 'MultiPolygon' else [geometry['coordinates']]
        for polygon in polygons:
            # first ring = outline, the others = holes
            if inside_ring(lon, lat, polygon[0]) and not any(inside_ring(lon, lat, hole) for hole in polygon[1:]):
                return feature['properties']['uf']
    # the shipped outlines are simplified: a coastal point (Recife, -8.43/-34.98) can fall just outside the coastline;
    # the nearest state counts when its outline is within ~30 km
    best, best_km = '', NEAR_KM
    for feature in features:
        geometry = feature['geometry']
        polygons = geometry['coordinates'] if geometry['type'] == 'MultiPolygon' else [geometry['coordinates']]
        for polygon in polygons:
            d = ring_km(lon, lat, polygon[0])
            if d < best_km:
                best, best_km = feature['properties']['uf'], d
    return best


NEAR_KM = 30


def ring_km(x, y, ring):
    # shortest distance (km, flat approximation, fine at this scale) from the point to the ring's edges
    kx = 111.32 * math.cos(math.radians(y))
    best = float('inf')
    for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1]):
        ax, ay, bx, by = (x1 - x) * kx, (y1 - y) * 110.57, (x2 - x) * kx, (y2 - y) * 110.57
        dx, dy = bx - ax, by - ay
        t = max(0.0, min(1.0, -(ax * dx + ay * dy) / (dx * dx + dy * dy))) if dx or dy else 0.0
        best = min(best, math.hypot(ax + t * dx, ay + t * dy))
    return best


def main():
    def number(a):
        try:
            float(a)
            return True
        except ValueError:
            return False
    args = [a for a in sys.argv[1:] if number(a)]  # coordinates may be negative
    verbose = '-v' in sys.argv
    try:
        lat, lon = float(args[0]), float(args[1])
    except (IndexError, ValueError):
        print(__doc__.strip().splitlines()[-3], file=sys.stderr)
        return 0
    state, method = '', ''
    if '--offline' not in sys.argv:
        try:
            state, method = online(lat, lon), 'online (OpenStreetMap Nominatim)'
        except Exception as e:  # no internet, timeout, service error: fall back to the shipped boundaries
            method = f'online failed ({type(e).__name__})'
    if not method.startswith('online (') and os.path.exists(GEOJSON):
        state, method = offline(lat, lon), method + ' -> offline (IBGE boundaries)'
    if verbose:
        print(f'{lat},{lon}: {state or "outside Brazil"} [{method.strip(" ->")}]', file=sys.stderr)
    if state:
        print(state)
    return 0


if __name__ == '__main__':
    sys.exit(main())
