"""PSO-LightGBM drought model on Sindh satellite data (monthly NDVI, LST, precipitation).

For each horizon h (1-3 months) two regressors are trained: NDVI(t+h) and LST(t+h).
The Vegetation Health Index (Kogan VHI = 0.5*VCI + 0.5*TCI) and drought class are
derived from the two forecasts.

Chronological split by target month (no shuffling):
  train 2001-2017 | validation 2018-2020 (PSO fitness) | test 2021-2023 (reported metrics)
Reported metrics come from models fitted on 2001-2020 and scored on 2021-2023.
The exported models are then refitted on all data (2001-2023) to forecast 2024.

Usage: python ml/satellite/train_satellite.py [--particles 8 --iterations 6]
"""
import argparse
import datetime as dt
import json
import sqlite3
import sys
import time
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from features import FEATURES, HORIZONS, MIN_ORIGIN, TARGETS, build, climatology, vhi  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
TRAIN_END, VAL_END = 2017, 2020
# PSO search space: num_leaves, learning_rate, rounds, min_data_in_leaf, feature_fraction, lambda_l2
BOUNDS = np.array([[15, 63], [.03, .2], [100, 400], [20, 300], [.5, 1.], [0., 10.]])
DROUGHT_CLASSES = ((10, 'Extreme'), (20, 'Severe'), (30, 'Moderate'), (40, 'Mild'), (101, 'No drought'))


def load(db):
    con = sqlite3.connect(db)
    meta = dict(con.execute('SELECT key, value FROM meta'))
    n = con.execute('SELECT COUNT(*) FROM cells').fetchone()[0]
    months, first_year = int(meta['months']), int(meta['first_year'])
    obs = np.array(con.execute('SELECT ndvi, lst, precip FROM obs ORDER BY cell_id, t').fetchall())
    g = {k: obs[:, i].reshape(n, months) for i, k in enumerate(('ndvi', 'lst', 'precip'))}
    latlon = np.array(con.execute('SELECT lat, lon FROM cells ORDER BY id').fetchall())
    con.close()
    return g, latlon, first_year, months


def year_to_t(year, first_year):
    return (year - first_year) * 12 + 11  # December of `year`


def dataset(g, clim, latlon, h, t_from, t_to):
    """Samples whose TARGET month index lies in [t_from, t_to]."""
    X, Y, O = [], [], []
    for t in range(max(MIN_ORIGIN, t_from - h), t_to - h + 1):
        X.append(build(g['ndvi'], g['lst'], g['precip'], clim, latlon[:, 0], latlon[:, 1], t, h))
        Y.append(np.column_stack([g['ndvi'][:, t + h], g['lst'][:, t + h]]))
        O.append(np.full(len(latlon), t))
    return np.vstack(X), np.vstack(Y), np.concatenate(O)


def params(p):
    return dict(objective='regression', verbosity=-1, seed=42, deterministic=True, num_threads=0,
                num_leaves=int(round(p[0])), learning_rate=float(p[1]), min_data_in_leaf=int(round(p[3])),
                feature_fraction=float(p[4]), lambda_l2=float(p[5]),
                bagging_fraction=.8, bagging_freq=1, feature_fraction_seed=42, bagging_seed=42)


def fit(lgb, p, X, y):
    return lgb.train(params(p), lgb.Dataset(X, y, free_raw_data=False), num_boost_round=int(round(p[2])))


def r2(y, p):
    return float(1 - np.sum((y - p) ** 2) / np.sum((y - y.mean()) ** 2))


def scores(y, p):
    return dict(r2=round(r2(y, p), 4), mae=round(float(np.mean(np.abs(y - p))), 5),
                rmse=round(float(np.sqrt(np.mean((y - p) ** 2))), 5))


def pso(lgb, Xtr, Ytr, Xva, Yva, particles, iterations, rng, log):
    """Particle swarm over BOUNDS. Fitness = mean normalised validation MAE over NDVI and LST."""
    scale = Yva.std(0)
    pos = rng.uniform(BOUNDS[:, 0], BOUNDS[:, 1], size=(particles, len(BOUNDS)))
    vel = np.zeros_like(pos)
    pbest, pbest_f = pos.copy(), np.full(particles, np.inf)
    gbest, gbest_f, history = pos[0].copy(), np.inf, []
    span = BOUNDS[:, 1] - BOUNDS[:, 0]
    for it in range(iterations):
        for i in range(particles):
            pred = np.column_stack([fit(lgb, pos[i], Xtr, Ytr[:, j]).predict(Xva) for j in range(2)])
            f = float(np.mean(np.mean(np.abs(pred - Yva), 0) / scale))
            if f < pbest_f[i]:
                pbest[i], pbest_f[i] = pos[i].copy(), f
            if f < gbest_f:
                gbest, gbest_f = pos[i].copy(), f
        history.append(round(gbest_f, 6))
        log(f'  PSO iteration {it + 1}/{iterations}: best fitness {gbest_f:.5f}')
        w = .9 - .5 * it / max(1, iterations - 1)  # inertia decays 0.9 -> 0.4
        r1, r2_ = rng.random(pos.shape), rng.random(pos.shape)
        vel = w * vel + 1.5 * r1 * (pbest - pos) + 1.5 * r2_ * (gbest - pos)
        vel = np.clip(vel, -.3 * span, .3 * span)
        pos = np.clip(pos + vel, BOUNDS[:, 0], BOUNDS[:, 1])
    return gbest, gbest_f, history


def export_trees(booster):
    """Compact numeric trees parsed from LightGBM's native model string.

    Node arrays: f=split feature, t=threshold, l/r=children (negative = ~leaf index), v=leaf values.
    Only numerical '<=' splits without missing values are supported (checked here).
    """
    trees = []
    for block in booster.model_to_string().split('\nTree=')[1:]:
        kv = dict(line.split('=', 1) for line in block.split('\n')[1:] if '=' in line)
        leaves = [float(x) for x in kv['leaf_value'].split()]
        if int(kv['num_leaves']) == 1:
            trees.append({'v': leaves})
            continue
        for d in map(int, kv['decision_type'].split()):
            if d & 1:
                raise ValueError('Categorical split not supported by PHP evaluator')
        trees.append({'f': [int(x) for x in kv['split_feature'].split()],
                      't': [float(x) for x in kv['threshold'].split()],
                      'l': [int(x) for x in kv['left_child'].split()],
                      'r': [int(x) for x in kv['right_child'].split()],
                      'v': leaves})
    return trees


def drought_class(v):
    return next(i for i, (limit, _) in enumerate(DROUGHT_CLASSES) if v < limit)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--db', default=str(ROOT / 'data/satellite/sindh.db'))
    ap.add_argument('--out', default=str(ROOT / 'data/satellite/model.json'))
    ap.add_argument('--particles', type=int, default=8)
    ap.add_argument('--iterations', type=int, default=6)
    ap.add_argument('--pso-sample', type=int, default=300000, help='training rows used per PSO fitness fit')
    args = ap.parse_args()
    import lightgbm as lgb

    started = time.time()
    log = lambda s: print(f'[{time.time() - started:6.0f}s] {s}', flush=True)  # noqa: E731
    rng = np.random.default_rng(42)
    g, latlon, first_year, months = load(args.db)
    clim = climatology(g['ndvi'], g['lst'], g['precip'], [first_year])
    t_train, t_val, t_last = year_to_t(TRAIN_END, first_year), year_to_t(VAL_END, first_year), months - 1
    log(f'Loaded {len(latlon)} cells x {months} months')

    # 1. PSO hyperparameter search on horizon 1 (train -> validation)
    Xtr, Ytr, _ = dataset(g, clim, latlon, 1, 0, t_train)
    Xva, Yva, _ = dataset(g, clim, latlon, 1, t_train + 1, t_val)
    idx = rng.choice(len(Xtr), min(args.pso_sample, len(Xtr)), replace=False)
    log(f'PSO: {args.particles} particles x {args.iterations} iterations, {len(idx)} train rows, {len(Xva)} validation rows')
    best, best_f, history = pso(lgb, Xtr[idx], Ytr[idx], Xva, Yva, args.particles, args.iterations, rng, log)
    best_params = params(best)
    log(f'Best: {best_params} rounds={int(round(best[2]))}')

    # 2. Held-out test evaluation (fit 2001-2020, score 2021-2023) and 3. final refit on all data
    evaluation, models = {}, {}
    fi = np.zeros(len(FEATURES))
    for h in HORIZONS:
        Xfit, Yfit, _ = dataset(g, clim, latlon, h, 0, t_val)
        Xte, Yte, Ote = dataset(g, clim, latlon, h, t_val + 1, t_last)
        pred = np.column_stack([fit(lgb, best, Xfit, Yfit[:, j]).predict(Xte) for j in range(2)])
        ev = {}
        for j, name in enumerate(TARGETS):
            persistence = Xte[:, FEATURES.index(f'{name}_0')]
            clim_target = Xte[:, FEATURES.index(f'clim_{name}_target')]
            last_year = Xte[:, FEATURES.index(f'{name}_last_year_target')]
            ev[name] = dict(model=scores(Yte[:, j], pred[:, j]), persistence=scores(Yte[:, j], persistence),
                            climatology=scores(Yte[:, j], clim_target), last_year=scores(Yte[:, j], last_year),
                            anomaly_r2=round(r2(Yte[:, j] - clim_target, pred[:, j] - clim_target), 4))
        cells = np.tile(np.arange(len(latlon)), len(Yte) // len(latlon))
        month = (Ote + h) % 12
        v_true = vhi(Yte[:, 0], Yte[:, 1], clim, cells, month)
        v_pred = vhi(pred[:, 0], pred[:, 1], clim, cells, month)
        d_true, d_pred = v_true < 40, v_pred < 40
        tp = int(np.sum(d_true & d_pred))
        ev['vhi'] = dict(model=scores(v_true, v_pred),
                         drought_accuracy=round(float(np.mean(d_true == d_pred)), 4),
                         drought_precision=round(tp / max(1, int(d_pred.sum())), 4),
                         drought_recall=round(tp / max(1, int(d_true.sum())), 4),
                         drought_share_actual=round(float(d_true.mean()), 4))
        ev['test_samples'] = int(len(Xte))
        evaluation[str(h)] = ev
        log(f'h={h}: NDVI R2 {ev["ndvi"]["model"]["r2"]} (persistence {ev["ndvi"]["persistence"]["r2"]}), '
            f'LST R2 {ev["lst"]["model"]["r2"]}, VHI R2 {ev["vhi"]["model"]["r2"]}, '
            f'drought accuracy {ev["vhi"]["drought_accuracy"]}')

        Xall, Yall, _ = dataset(g, clim, latlon, h, 0, t_last)
        for j, name in enumerate(TARGETS):
            booster = fit(lgb, best, Xall, Yall[:, j])
            fi += booster.feature_importance('gain')
            models[f'{name}_h{h}'] = export_trees(booster)
            if h == 1:  # parity fixture: native LightGBM predictions for the PHP test
                fx = build(g['ndvi'], g['lst'], g['precip'], clim, latlon[:, 0], latlon[:, 1], t_last, 1)
                models.setdefault('_parity', {})[name] = {
                    'cells': list(range(0, len(latlon), 97)),
                    'pred': booster.predict(fx[::97]).tolist()}
        log(f'h={h}: final models refitted on {len(Xall)} rows')

    parity = models.pop('_parity')
    importance = sorted(zip(FEATURES, (fi / fi.sum()).round(4).tolist()), key=lambda x: -x[1])
    out = dict(schema=1, algorithm='PSO-LightGBM', task='Monthly NDVI and LST forecast -> VHI drought class',
               region='Sindh, Pakistan (~10 km grid)', first_year=first_year, months=months,
               data_until=f'{first_year + (months - 1) // 12}-{(months - 1) % 12 + 1:02d}',
               horizons=list(HORIZONS), features=list(FEATURES),
               split=dict(train=f'2001-{TRAIN_END}', validation=f'{TRAIN_END + 1}-{VAL_END}',
                          test=f'{VAL_END + 1}-{first_year + (months - 1) // 12}',
                          note='Metrics: fitted 2001-2020, scored on 2021-2023. Exported models refitted on all years.'),
               pso=dict(particles=args.particles, iterations=args.iterations, pso_sample=int(len(idx)),
                        fitness='mean normalised validation MAE (NDVI, LST), horizon 1',
                        best_fitness=round(best_f, 6), history=history,
                        best_parameters={k: best_params[k] for k in
                                         ('num_leaves', 'learning_rate', 'min_data_in_leaf', 'feature_fraction', 'lambda_l2')}
                        | {'rounds': int(round(best[2]))}),
               drought_classes=[dict(below=b, label=l) for b, l in DROUGHT_CLASSES],
               evaluation=evaluation, feature_importance=importance, parity=parity,
               trained_at=dt.datetime.now().isoformat(timespec='seconds'),
               training_seconds=round(time.time() - started), models=models)
    path = Path(args.out)
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(out, separators=(',', ':'), allow_nan=False), encoding='utf-8')
    tmp.replace(path)
    log(f'Exported {path} ({path.stat().st_size / 1e6:.1f} MB)')


if __name__ == '__main__':
    main()
