"""PSO-LightGBM agricultural drought model for India (NASA POWER monthly data).

Target: root-zone soil wetness (GWETROOT, 0-1) 1, 2 and 3 months ahead. The forecast is ranked
against each location's 1981-2010 values for that calendar month (percentile) and mapped to US
Drought Monitor categories (D0-D4).

Chronological split by target month: train <=2014 | validation 2015-2018 (PSO fitness) |
test 2019 onwards (reported metrics). Exported models are refitted on all data.

Usage: python ml/india/train_india.py [--particles 8 --iterations 6]
"""
import argparse
import datetime as dt
import json
import sqlite3
import sys
import time
from pathlib import Path

import numpy as np

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(HERE.parent / 'satellite'))
from india_features import CLASSES, FEATURES, HORIZONS, MIN_ORIGIN, VARS, build, category, climatology, percentile  # noqa: E402
from train_satellite import export_trees, fit, params, pso, r2, scores  # noqa: E402

ROOT = HERE.parents[1]
TRAIN_END, VAL_END = 2014, 2018


def load(db):
    con = sqlite3.connect(db)
    meta = dict(con.execute('SELECT key, value FROM meta'))
    n = con.execute('SELECT COUNT(*) FROM cells').fetchone()[0]
    first_year = int(meta['first_year'])
    # Months appended by validate_live.py are real-time validation data, never training data.
    T = int(meta.get('live_from', meta['months']))
    obs = np.array(con.execute(f'SELECT {", ".join(VARS)} FROM obs WHERE t < ? ORDER BY cell_id, t', (T,)).fetchall(), dtype=float)
    g = {v: obs[:, i].reshape(n, T) for i, v in enumerate(VARS)}
    latlon = np.array(con.execute('SELECT lat, lon FROM cells ORDER BY id').fetchall())
    con.close()
    return g, latlon, first_year, T


def metrics(y, pred, X, cells, months, clim):
    """Soil-moisture regression scores plus drought-category agreement (shared with validate_live.py)."""
    pct_true, pct_pred = percentile(y, cells, months, clim), percentile(pred, cells, months, clim)
    c_true, c_pred = category(pct_true), category(pct_pred)
    d_true, d_pred = pct_true <= 20, pct_pred <= 20  # D1 or worse
    tp = int(np.sum(d_true & d_pred))
    persistence = X[:, FEATURES.index('sm_root_0')]
    climo = X[:, FEATURES.index('clim_sm_target')]
    d_pers = percentile(persistence, cells, months, clim) <= 20
    tp_pers = int(np.sum(d_true & d_pers))
    return dict(
        sm_root=dict(model=scores(y, pred), persistence=scores(y, persistence), climatology=scores(y, climo),
                     anomaly_r2=round(r2(y - climo, pred - climo), 4)),
        percentile=dict(model=scores(pct_true, pct_pred)),
        category_accuracy=round(float(np.mean(c_true == c_pred)), 4),
        category_within_one=round(float(np.mean(np.abs(c_true - c_pred) <= 1)), 4),
        drought_accuracy=round(float(np.mean(d_true == d_pred)), 4),
        drought_precision=round(tp / max(1, int(d_pred.sum())), 4),
        drought_recall=round(tp / max(1, int(d_true.sum())), 4),
        drought_f1=round(2 * tp / max(1, int(d_pred.sum() + d_true.sum())), 4),
        drought_share_actual=round(float(d_true.mean()), 4),
        drought_share_predicted=round(float(d_pred.mean()), 4),
        persistence_drought_accuracy=round(float(np.mean(d_true == d_pers)), 4),
        persistence_drought_f1=round(2 * tp_pers / max(1, int(d_pers.sum() + d_true.sum())), 4),
        test_samples=int(len(y)))


def save_climatology(db, clim, n):
    """Store the exact climatology the model was trained with, for PHP inference."""
    con = sqlite3.connect(db)
    con.executescript('DROP TABLE IF EXISTS clim; CREATE TABLE clim (cell_id INTEGER, month INTEGER, ' +
                      ', '.join(f'{v}_mean REAL' for v in VARS) + ', sm_base TEXT, PRIMARY KEY (cell_id, month)) WITHOUT ROWID;')
    con.executemany(f'INSERT INTO clim VALUES ({",".join("?" * (len(VARS) + 3))})', [
        (c, m, *(float(clim[f'{v}_mean'][c, m]) for v in VARS), json.dumps(clim['sm_base'][c, m].tolist()))
        for c in range(n) for m in range(12)])
    con.commit()
    con.close()


def dataset(g, clim, latlon, h, t_from, t_to):
    X, Y, O = [], [], []
    for t in range(max(MIN_ORIGIN, t_from - h), t_to - h + 1):
        X.append(build(g, clim, latlon[:, 0], latlon[:, 1], t, h))
        Y.append(g['sm_root'][:, t + h])
        O.append(np.full(len(latlon), t))
    return np.vstack(X), np.concatenate(Y)[:, None], np.concatenate(O)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--db', default=str(ROOT / 'data/india/india.db'))
    ap.add_argument('--out', default=str(ROOT / 'data/india/model.json'))
    ap.add_argument('--particles', type=int, default=8)
    ap.add_argument('--iterations', type=int, default=6)
    ap.add_argument('--pso-sample', type=int, default=250000)
    args = ap.parse_args()
    import lightgbm as lgb

    started = time.time()
    log = lambda s: print(f'[{time.time() - started:6.0f}s] {s}', flush=True)  # noqa: E731
    rng = np.random.default_rng(42)
    g, latlon, first_year, T = load(args.db)
    clim = climatology(g, first_year)
    save_climatology(args.db, clim, len(latlon))
    t_train, t_val, t_last = (TRAIN_END - first_year) * 12 + 11, (VAL_END - first_year) * 12 + 11, T - 1
    last_label = f'{first_year + t_last // 12}-{t_last % 12 + 1:02d}'
    log(f'Loaded {len(latlon)} cells x {T} months ({first_year}-01 to {last_label})')

    Xtr, Ytr, _ = dataset(g, clim, latlon, 1, 0, t_train)
    Xva, Yva, _ = dataset(g, clim, latlon, 1, t_train + 1, t_val)
    idx = rng.choice(len(Xtr), min(args.pso_sample, len(Xtr)), replace=False)
    log(f'PSO: {args.particles} particles x {args.iterations} iterations, {len(idx)} train rows, {len(Xva)} validation rows')
    best, best_f, history = pso(lgb, Xtr[idx], Ytr[idx], Xva, Yva, args.particles, args.iterations, rng, log)
    bp = params(best)

    evaluation, models, fi = {}, {}, np.zeros(len(FEATURES))
    cells_all = np.arange(len(latlon))
    for h in HORIZONS:
        Xfit, Yfit, _ = dataset(g, clim, latlon, h, 0, t_val)
        Xte, Yte, Ote = dataset(g, clim, latlon, h, t_val + 1, t_last)
        y, pred = Yte[:, 0], fit(lgb, best, Xfit, Yfit[:, 0]).predict(Xte)
        cells, months = np.tile(cells_all, len(y) // len(cells_all)), (Ote + h) % 12
        evaluation[str(h)] = e = metrics(y, pred, Xte, cells, months, clim)
        log(f'h={h}: soil moisture R2 {e["sm_root"]["model"]["r2"]} (persistence {e["sm_root"]["persistence"]["r2"]}, '
            f'climatology {e["sm_root"]["climatology"]["r2"]}), percentile R2 {e["percentile"]["model"]["r2"]}, '
            f'drought acc {e["drought_accuracy"]} (persistence {e["persistence_drought_accuracy"]}), category acc {e["category_accuracy"]}')

        Xall, Yall, _ = dataset(g, clim, latlon, h, 0, t_last)
        booster = fit(lgb, best, Xall, Yall[:, 0])
        fi += booster.feature_importance('gain')
        models[f'sm_h{h}'] = export_trees(booster)
        if h == 1:
            fx = build(g, clim, latlon[:, 0], latlon[:, 1], t_last, 1)
            parity = {'cells': list(range(0, len(latlon), 37)), 'pred': booster.predict(fx[::37]).tolist()}
        log(f'h={h}: final model refitted on {len(Xall)} rows')

    importance = sorted(zip(FEATURES, (fi / fi.sum()).round(4).tolist()), key=lambda x: -x[1])
    out = dict(schema=1, algorithm='PSO-LightGBM', region='India', task='Root-zone soil moisture forecast -> drought percentile (USDM D0-D4)',
               source='NASA LaRC POWER monthly (MERRA-2)', first_year=first_year, months=T, data_until=last_label,
               horizons=list(HORIZONS), features=list(FEATURES),
               classes=[dict(max_pct=b, code=c, label=l, key=k) for b, c, l, k in CLASSES],
               split=dict(train=f'{first_year}-{TRAIN_END}', validation=f'{TRAIN_END + 1}-{VAL_END}', test=f'{VAL_END + 1}-{last_label[:4]}',
                          base_period='1981-2010', note='Metrics: fitted up to 2018, scored on 2019 onwards. Exported models refitted on all data.'),
               pso=dict(particles=args.particles, iterations=args.iterations, pso_sample=int(len(idx)),
                        fitness='normalised validation MAE, horizon 1', best_fitness=round(best_f, 6), history=history,
                        best_parameters={k: bp[k] for k in ('num_leaves', 'learning_rate', 'min_data_in_leaf', 'feature_fraction', 'lambda_l2')}
                        | {'rounds': int(round(best[2]))}),
               evaluation=evaluation, feature_importance=importance, parity=parity,
               trained_at=dt.datetime.now().isoformat(timespec='seconds'), training_seconds=round(time.time() - started), models=models)
    path = Path(args.out)
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(out, separators=(',', ':'), allow_nan=False), encoding='utf-8')
    tmp.replace(path)
    log(f'Exported {path} ({path.stat().st_size / 1e6:.1f} MB)')


if __name__ == '__main__':
    main()
