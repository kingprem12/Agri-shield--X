"""Build the India training dataset from NASA POWER monthly data (free, no API key).

1. Simplifies the India district map (official boundaries) into assets/india-*.geojson for the web map.
2. Lays a 0.5 degree grid over India and keeps points inside the country (each tagged with state/district).
3. Downloads monthly T2M, RH2M, PRECTOTCORR, WS2M, GWETROOT, GWETTOP (1981 onwards) for every point.
   Raw responses are cached in data/india/raw/ so an interrupted run resumes where it stopped.
4. Writes data/india/india.db (SQLite): meta, cells, obs.

Usage: python ml/india/fetch_power.py [--step 0.5 --workers 4 --end 2025]
Data source: NASA Langley Research Center POWER Project (https://power.larc.nasa.gov), MERRA-2 based.
"""
import argparse
import calendar
import json
import sqlite3
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

import numpy as np
from shapely.geometry import Point, shape
from shapely.ops import unary_union
from shapely.strtree import STRtree

ROOT = Path(__file__).resolve().parents[2]
GEO_URL = 'https://cdn.jsdelivr.net/gh/udit-001/india-maps-data@main/geojson/india.geojson'
PARAMS = ('T2M', 'RH2M', 'PRECTOTCORR', 'WS2M', 'GWETROOT', 'GWETTOP')
API = ('https://power.larc.nasa.gov/api/temporal/monthly/point?parameters={p}&community=AG'
       '&longitude={lon}&latitude={lat}&start={start}&end={end}&format=JSON')


def load_geo(cache):
    if not cache.exists():
        cache.parent.mkdir(parents=True, exist_ok=True)
        urllib.request.urlretrieve(GEO_URL, cache)
    features = json.loads(cache.read_text(encoding='utf-8'))['features']
    districts = [f for f in features if f['properties'].get('district')]
    # State outlines = union of their districts (the source lacks outlines for some states/UTs).
    by_state = {}
    for f in districts:
        by_state.setdefault(f['properties']['st_nm'], []).append(shape(f['geometry']).buffer(0))
    states = [{'properties': {'st_nm': st}, 'geometry': unary_union(g).buffer(0.001).buffer(-0.001).__geo_interface__}
              for st, g in sorted(by_state.items())]
    return districts, states


def write_web_geojson(districts, states, tolerance):
    out = ROOT / 'assets'
    out.mkdir(exist_ok=True)
    for name, feats, keys in (('india-states', states, ('st_nm',)), ('india-districts', districts, ('district', 'st_nm'))):
        slim = []
        for f in feats:
            g = shape(f['geometry']).simplify(tolerance, preserve_topology=True)
            geo = json.loads(json.dumps(g.__geo_interface__), parse_float=lambda x: round(float(x), 3))
            slim.append({'type': 'Feature', 'properties': {k: f['properties'][k] for k in keys}, 'geometry': geo})
        path = out / f'{name}.geojson'
        path.write_text(json.dumps({'type': 'FeatureCollection', 'features': slim}, separators=(',', ':')), encoding='utf-8')
        print(f'{path.relative_to(ROOT)}: {len(slim)} features, {path.stat().st_size / 1e6:.2f} MB')


def grid_points(districts, step):
    shapes = [shape(f['geometry']) for f in districts]
    india = unary_union(shapes)
    tree = STRtree(shapes)
    minx, miny, maxx, maxy = india.bounds
    pts = []
    for lat in np.arange(np.floor(miny / step) * step + step / 2, maxy, step):
        for lon in np.arange(np.floor(minx / step) * step + step / 2, maxx, step):
            p = Point(lon, lat)
            hits = [i for i in tree.query(p) if shapes[i].contains(p)]
            if hits:
                props = districts[hits[0]]['properties']
                pts.append((round(float(lat), 4), round(float(lon), 4), props['st_nm'], props['district']))
    return pts


def fetch(lat, lon, start, end, raw_dir):
    cache = raw_dir / f'{lat:.4f}_{lon:.4f}.json'
    if cache.exists():
        return json.loads(cache.read_text())
    url = API.format(p=','.join(PARAMS), lat=lat, lon=lon, start=start, end=end)
    for attempt in range(5):
        try:
            with urllib.request.urlopen(url, timeout=90) as r:
                data = json.loads(r.read())['properties']['parameter']
            cache.write_text(json.dumps(data))
            return data
        except Exception as exc:  # network hiccup or rate limit: back off and retry
            if attempt == 4:
                raise RuntimeError(f'{lat},{lon}: {exc}')
            time.sleep(5 * (attempt + 1))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--step', type=float, default=0.5)
    ap.add_argument('--start', type=int, default=1981)
    ap.add_argument('--end', type=int, default=2025)
    ap.add_argument('--workers', type=int, default=4)
    ap.add_argument('--simplify', type=float, default=0.01)
    args = ap.parse_args()
    data_dir = ROOT / 'data/india'
    raw_dir = data_dir / 'raw'
    raw_dir.mkdir(parents=True, exist_ok=True)

    districts, states = load_geo(data_dir / 'india-official.geojson')
    write_web_geojson(districts, states, args.simplify)
    pts = grid_points(districts, args.step)
    print(f'{len(pts)} grid points ({args.step} deg) inside India')

    results, t0 = {}, time.time()
    with ThreadPoolExecutor(args.workers) as pool:
        futures = {pool.submit(fetch, p[0], p[1], args.start, args.end, raw_dir): i for i, p in enumerate(pts)}
        for n, fut in enumerate(as_completed(futures), 1):
            results[futures[fut]] = fut.result()
            if n % 50 == 0 or n == len(pts):
                print(f'  downloaded {n}/{len(pts)} ({time.time() - t0:.0f}s)', flush=True)

    # Month keys YYYYMM (skip YYYY13 = annual). Keep months where every point has every value.
    months = sorted(k for k in results[0]['T2M'] if not k.endswith('13'))
    valid = [m for m in months if all(results[i][p].get(m, -999) != -999 for i in results for p in PARAMS)]
    last = valid[-1]
    months = [m for m in months if m <= last]
    missing = sorted(set(months) - set(valid))
    if missing:
        raise SystemExit(f'Gaps inside the record: {missing[:10]}')
    first_year = int(months[0][:4])

    db = data_dir / 'india.db'
    tmp = db.with_suffix('.tmp')
    tmp.unlink(missing_ok=True)
    con = sqlite3.connect(tmp)
    con.executescript('''
        CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE cells (id INTEGER PRIMARY KEY, lat REAL, lon REAL, state TEXT, district TEXT);
        CREATE TABLE obs (cell_id INTEGER, t INTEGER, t2m REAL, rh REAL, precip REAL, wind REAL, sm_root REAL, sm_top REAL,
                          PRIMARY KEY (cell_id, t)) WITHOUT ROWID;
        CREATE INDEX obs_t ON obs (t);
    ''')
    con.executemany('INSERT INTO meta VALUES (?,?)', [
        ('first_year', str(first_year)), ('months', str(len(months))), ('step_deg', str(args.step)),
        ('source', 'NASA LaRC POWER monthly (MERRA-2): T2M, RH2M, PRECTOTCORR, WS2M, GWETROOT, GWETTOP'),
        ('region', f'India, {args.step} degree grid'), ('downloaded_at', time.strftime('%Y-%m-%d'))])
    con.executemany('INSERT INTO cells VALUES (?,?,?,?,?)', [(i, *p) for i, p in enumerate(pts)])
    rows = []
    for i in range(len(pts)):
        r = results[i]
        for t, m in enumerate(months):
            days = calendar.monthrange(int(m[:4]), int(m[4:]))[1]
            rows.append((i, t, r['T2M'][m], r['RH2M'][m], round(r['PRECTOTCORR'][m] * days, 3),  # mm/day -> mm/month
                         r['WS2M'][m], r['GWETROOT'][m], r['GWETTOP'][m]))
    con.executemany('INSERT INTO obs VALUES (?,?,?,?,?,?,?,?)', rows)
    con.commit()
    con.close()
    tmp.replace(db)
    print(f'{db.relative_to(ROOT)}: {len(pts)} cells x {len(months)} months ({months[0]}-{months[-1]})')


if __name__ == '__main__':
    main()
