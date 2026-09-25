"""Real-time validation: does the model trained on seed data stay accurate on new data?

1. Downloads NASA POWER *daily* data for every grid point, from the first month after the training
   data up to today, and aggregates complete months exactly like the monthly endpoint used for
   training (daily means rounded to 2 dp; rainfall = mean mm/day rounded to 2 dp x days).
2. Appends those months to data/india/india.db. meta.live_from marks where real-time data starts:
   the trainer never uses these months, while the website can now forecast from the latest month.
3. Scores the deployed PSO-LightGBM trees (data/india/model.json) on every forecast whose target
   month is real-time data and writes data/india/live_validation.json for the Validation page.

Usage: python ml/india/validate_live.py [--workers 4]   (re-run monthly to extend the live period)
"""
import argparse
import calendar
import datetime as dt
import json
import sqlite3
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

import numpy as np

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(HERE.parent / 'satellite'))
from india_features import FEATURES, HORIZONS, VARS, build, climatology, percentile  # noqa: E402
from train_india import metrics  # noqa: E402
from train_satellite import r2, scores  # noqa: E402

ROOT = HERE.parents[1]
PARAMS = {'t2m': 'T2M', 'rh': 'RH2M', 'precip': 'PRECTOTCORR', 'wind': 'WS2M', 'sm_root': 'GWETROOT', 'sm_top': 'GWETTOP'}
API = ('https://power.larc.nasa.gov/api/temporal/daily/point?parameters={p}&community=AG'
       '&longitude={lon}&latitude={lat}&start={start}&end={end}&format=JSON')


def fetch(lat, lon, start, end, cache_dir):
    cache = cache_dir / f'{lat:.4f}_{lon:.4f}.json'
    if cache.exists():
        return json.loads(cache.read_text())
    url = API.format(p=','.join(PARAMS.values()), lat=lat, lon=lon, start=start, end=end)
    for attempt in range(8):
        try:
            with urllib.request.urlopen(url, timeout=90) as r:
                data = json.loads(r.read())['properties']['parameter']
            cache.write_text(json.dumps(data))
            return data
        except Exception as exc:
            if attempt == 7:
                raise RuntimeError(f'{lat},{lon}: {exc}')
            # NASA POWER rate-limits bursts (HTTP 429): honour Retry-After, otherwise back off exponentially.
            retry = getattr(exc, 'headers', None) and exc.headers.get('Retry-After')
            time.sleep(int(retry) if retry and retry.isdigit() else min(300, 15 * 2 ** attempt))


def monthly(daily, year, month):
    """Aggregate one month of daily values; None if any day is missing."""
    days = calendar.monthrange(year, month)[1]
    keys = [f'{year}{month:02d}{d:02d}' for d in range(1, days + 1)]
    out = {}
    for var, p in PARAMS.items():
        vals = [daily[p].get(k, -999) for k in keys]
        if any(v == -999 for v in vals):
            return None
        mean = round(sum(vals) / days, 2)
        out[var] = round(mean * days, 3) if var == 'precip' else mean
    return out


def tree_predict(trees, X):
    """Vectorised evaluation of the exported trees (same rule as sat_tree() in PHP)."""
    out = np.zeros(len(X))
    for tr in trees:
        if 'f' not in tr:
            out += tr['v'][0]
            continue
        f, th, left, right, v = (np.asarray(tr[k]) for k in ('f', 't', 'l', 'r', 'v'))
        node = np.zeros(len(X), dtype=int)
        active = np.arange(len(X))
        while len(active):
            n = node[active]
            nxt = np.where(X[active, f[n]] <= th[n], left[n], right[n])
            node[active] = nxt
            active = active[nxt >= 0]
        out += v[~node]
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--db', default=str(ROOT / 'data/india/india.db'))
    ap.add_argument('--model', default=str(ROOT / 'data/india/model.json'))
    ap.add_argument('--workers', type=int, default=2)
    args = ap.parse_args()
    started = time.time()
    log = lambda s: print(f'[{time.time() - started:6.0f}s] {s}', flush=True)  # noqa: E731

    con = sqlite3.connect(args.db)
    meta = dict(con.execute('SELECT key, value FROM meta'))
    first_year = int(meta['first_year'])
    live_from = int(meta.get('live_from', meta['months']))
    cells = con.execute('SELECT id, lat, lon FROM cells ORDER BY id').fetchall()
    ly, lm = first_year + live_from // 12, live_from % 12 + 1
    today = dt.date.today()
    start, end = f'{ly}{lm:02d}01', today.strftime('%Y%m%d')

    # 1. Download daily data (cached per run day, so a crash resumes without re-downloading)
    cache_dir = ROOT / 'data/india/live_raw' / end
    cache_dir.mkdir(parents=True, exist_ok=True)
    log(f'Downloading NASA POWER daily {start}-{end} for {len(cells)} grid points')
    daily = {}
    with ThreadPoolExecutor(args.workers) as pool:
        futures = {pool.submit(fetch, lat, lon, start, end, cache_dir): cid for cid, lat, lon in cells}
        for n, fut in enumerate(as_completed(futures), 1):
            daily[futures[fut]] = fut.result()
            if n % 100 == 0 or n == len(cells):
                log(f'  downloaded {n}/{len(cells)}')

    # 2. Complete months for every cell, appended after the seed record
    live_months, (y, m) = [], (ly, lm)
    while (y, m) < (today.year, today.month):
        rows = [monthly(daily[cid], y, m) for cid, _, _ in cells]
        if any(r is None for r in rows):
            break
        live_months.append(((y, m), rows))
        y, m = (y + 1, 1) if m == 12 else (y, m + 1)
    if not live_months:
        raise SystemExit('No complete real-time month available yet.')
    con.execute('DELETE FROM obs WHERE t >= ?', (live_from,))
    con.executemany('INSERT INTO obs VALUES (?,?,?,?,?,?,?,?)', [
        (cid, live_from + i, *(r[v] for v in VARS)) for i, (_, rows) in enumerate(live_months) for (cid, _, _), r in zip(cells, rows)])
    labels = [f'{y}-{m:02d}' for (y, m), _ in live_months]
    con.executemany('INSERT OR REPLACE INTO meta VALUES (?,?)', [
        ('live_from', str(live_from)), ('months', str(live_from + len(live_months))),
        ('live_until', labels[-1]), ('live_updated', today.isoformat()),
        ('live_source', 'NASA LaRC POWER daily (near real-time), aggregated to months')])
    con.commit()
    log(f'Appended real-time months {labels[0]} to {labels[-1]} ({len(labels)} months)')

    # 3. Score the deployed model on forecasts whose target month is real-time data
    T = live_from + len(live_months)
    obs = np.array(con.execute(f'SELECT {", ".join(VARS)} FROM obs ORDER BY cell_id, t').fetchall(), dtype=float)
    con.close()
    g = {v: obs[:, i].reshape(len(cells), T) for i, v in enumerate(VARS)}
    latlon = np.array([(lat, lon) for _, lat, lon in cells])
    clim = climatology(g, first_year)  # base period 1981-2010, identical to training
    model = json.loads(Path(args.model).read_text())
    seed_last = live_from - 1
    check = tree_predict(model['models']['sm_h1'], build(g, clim, latlon[:, 0], latlon[:, 1], seed_last, 1)[model['parity']['cells']])
    assert np.allclose(check, model['parity']['pred'], rtol=0, atol=1e-9), 'tree evaluator does not match LightGBM'

    n = len(cells)
    result = dict(generated_at=dt.datetime.now().isoformat(timespec='seconds'), model_trained_at=model['trained_at'],
                  seed_until=model['data_until'], live_months=labels, horizons={}, monthly=[], cells=n)
    for h in HORIZONS:
        origins = list(range(live_from - h, T - h))
        X = np.vstack([build(g, clim, latlon[:, 0], latlon[:, 1], t, h) for t in origins])
        y = np.concatenate([g['sm_root'][:, t + h] for t in origins])
        pred = tree_predict(model['models'][f'sm_h{h}'], X)
        cell_idx = np.tile(np.arange(n), len(origins))
        months = np.repeat([(t + h) % 12 for t in origins], n)
        result['horizons'][str(h)] = metrics(y, pred, X, cell_idx, months, clim) | {'target_months': len(origins)}
        e = result['horizons'][str(h)]
        log(f'h={h}: live soil moisture R2 {e["sm_root"]["model"]["r2"]} (seed test {model["evaluation"][str(h)]["sm_root"]["model"]["r2"]}), '
            f'anomaly R2 {e["sm_root"]["anomaly_r2"]}, drought F1 {e["drought_f1"]} (persistence {e["persistence_drought_f1"]})')
        if h == 1:
            rng = np.random.default_rng(1)
            pick = rng.choice(len(y), min(700, len(y)), replace=False)
            result['scatter'] = [[round(float(y[i]), 4), round(float(pred[i]), 4)] for i in pick]
            P, A = pred.reshape(len(origins), n), y.reshape(len(origins), n)
            pers = X[:, FEATURES.index('sm_root_0')].reshape(len(origins), n)
            for k, t in enumerate(origins):
                mo = np.full(n, (t + h) % 12)
                pa, pp = percentile(A[k], np.arange(n), mo, clim), percentile(P[k], np.arange(n), mo, clim)
                result['monthly'].append(dict(month=labels[t + h - live_from], r2=round(r2(A[k], P[k]), 4),
                                              persistence_r2=round(r2(A[k], pers[k]), 4), mae=scores(A[k], P[k])['mae'],
                                              drought_actual=round(float(np.mean(pa <= 20)), 4), drought_predicted=round(float(np.mean(pp <= 20)), 4)))
            result['series'] = {'pred': np.round(P.T, 4).tolist(), 'actual': np.round(A.T, 4).tolist()}  # [cell][month]
    out = ROOT / 'data/india/live_validation.json'
    out.write_text(json.dumps(result, separators=(',', ':')), encoding='utf-8')
    log(f'Wrote {out.relative_to(ROOT)}')


if __name__ == '__main__':
    main()
