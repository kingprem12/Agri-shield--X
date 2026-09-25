"""Import the Sindh monthly satellite grid CSVs into SQLite (data/satellite/sindh.db).

Source: Google Earth Engine export, one CSV per month (Sindh_Grid_YYYY_MM.csv) with
LST (deg C), NDVI and Precipitation (mm/month) per ~10 km grid point.
Files named "... (1).csv" are re-exports of the same month and are skipped.

Usage: python ml/satellite/build_dataset.py --src ~/Desktop/capstone_extracted
"""
import argparse
import glob
import re
import sqlite3
import sys
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).parent))
from features import climatology  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]


def load(src):
    files = [f for f in sorted(glob.glob(str(Path(src) / 'Sindh_Grid_*.csv'))) if '(1)' not in f]
    if not files:
        raise SystemExit(f'No Sindh_Grid_*.csv files in {src}')
    parts = []
    for f in files:
        d = pd.read_csv(f, usecols=['LST', 'NDVI', 'Precipitation', 'month', 'year', '.geo'])
        xy = d['.geo'].str.extract(r'\[([-\d.]+),([-\d.]+)\]').astype(float)
        d['lon'], d['lat'] = xy[0].round(5), xy[1].round(5)
        parts.append(d.drop(columns='.geo'))
    df = pd.concat(parts, ignore_index=True)
    return df, len(files)


def to_grid(df):
    """Pivot to [cell, month] arrays; fill the few missing cell-months by time interpolation."""
    first_year = int(df.year.min())
    df['t'] = (df.year - first_year) * 12 + df.month - 1
    months = int(df.t.max()) + 1
    cells = df[['lon', 'lat']].drop_duplicates().sort_values(['lat', 'lon']).reset_index(drop=True)
    cells['id'] = np.arange(len(cells))
    df = df.merge(cells, on=['lon', 'lat'])
    grids, filled = {}, None
    for col, name in (('NDVI', 'ndvi'), ('LST', 'lst'), ('Precipitation', 'precip')):
        g = np.full((len(cells), months), np.nan)
        g[df.id.values, df.t.values] = df[col].values
        if filled is None:
            filled = np.isnan(g)
        g = pd.DataFrame(g).interpolate(axis=1, limit_direction='both').values
        grids[name] = g
    return cells, grids, filled, first_year, months


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--src', default=str(Path.home() / 'Desktop/capstone_extracted'))
    ap.add_argument('--db', default=str(ROOT / 'data/satellite/sindh.db'))
    args = ap.parse_args()

    df, nfiles = load(args.src)
    cells, g, filled, first_year, months = to_grid(df)
    clim = climatology(g['ndvi'], g['lst'], g['precip'], [first_year])

    db = Path(args.db)
    db.parent.mkdir(parents=True, exist_ok=True)
    tmp = db.with_suffix('.tmp')
    tmp.unlink(missing_ok=True)
    con = sqlite3.connect(tmp)
    con.executescript('''
        CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE cells (id INTEGER PRIMARY KEY, lon REAL, lat REAL);
        CREATE TABLE obs (cell_id INTEGER, t INTEGER, ndvi REAL, lst REAL, precip REAL, filled INTEGER,
                          PRIMARY KEY (cell_id, t)) WITHOUT ROWID;
        CREATE TABLE clim (cell_id INTEGER, month INTEGER, ndvi_min REAL, ndvi_max REAL, ndvi_mean REAL,
                           lst_min REAL, lst_max REAL, lst_mean REAL, p_mean REAL,
                           PRIMARY KEY (cell_id, month)) WITHOUT ROWID;
    ''')
    con.executemany('INSERT INTO meta VALUES (?,?)', [
        ('first_year', str(first_year)), ('months', str(months)), ('source_files', str(nfiles)),
        ('source', 'Google Earth Engine monthly grid: MODIS LST (C), MODIS NDVI, precipitation (mm/month)'),
        ('region', 'Sindh, Pakistan (~10 km grid)'),
    ])
    con.executemany('INSERT INTO cells VALUES (?,?,?)', cells[['id', 'lon', 'lat']].itertuples(index=False))
    ids, ts = np.meshgrid(np.arange(len(cells)), np.arange(months), indexing='ij')
    con.executemany('INSERT INTO obs VALUES (?,?,?,?,?,?)', zip(
        ids.ravel().tolist(), ts.ravel().tolist(), g['ndvi'].ravel().tolist(), g['lst'].ravel().tolist(),
        g['precip'].ravel().tolist(), filled.ravel().astype(int).tolist()))
    keys = ('ndvi_min', 'ndvi_max', 'ndvi_mean', 'lst_min', 'lst_max', 'lst_mean', 'p_mean')
    con.executemany('INSERT INTO clim VALUES (?,?,?,?,?,?,?,?,?)', [
        (c, m + 1, *(float(clim[k][c, m]) for k in keys)) for c in range(len(cells)) for m in range(12)])
    con.commit()
    con.close()
    tmp.replace(db)
    print(f'{nfiles} monthly files -> {len(cells)} cells x {months} months '
          f'({first_year}-{first_year + months // 12 - 1}), {int(filled.sum())} gaps interpolated -> {db}')


if __name__ == '__main__':
    main()
