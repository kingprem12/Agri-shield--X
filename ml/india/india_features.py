"""Feature definitions for the India soil-moisture drought model.

Mirrored exactly in india_engine.php (india_features). Change both together and re-run
php ml/india/test_parity.php.
"""
import numpy as np

HORIZONS = (1, 2, 3)
BASE_FIRST, BASE_LAST = 1981, 2010  # WMO 30-year normal used for climatology and percentiles
MIN_ORIGIN = 11
VARS = ('t2m', 'rh', 'precip', 'wind', 'sm_root', 'sm_top')
FEATURES = (
    'sm_root_0', 'sm_root_1', 'sm_root_2', 'sm_top_0',
    'p_0', 'p_1', 'p_2', 'p_sum3', 'p_sum6',
    't2m_0', 't2m_1', 'rh_0', 'wind_0',
    'sm_root_anom', 'p_anom3', 't2m_anom',
    'clim_sm_target', 'clim_p_target', 'sm_last_year_target', 'sm_pct_0',
    'month_sin', 'month_cos', 'lat', 'lon',
)
# US Drought Monitor percentile categories (upper bound inclusive).
CLASSES = ((2, 'D4', 'Exceptional Drought', 'exceptional'), (5, 'D3', 'Extreme Drought', 'extreme'),
           (10, 'D2', 'Severe Drought', 'severe'), (20, 'D1', 'Moderate Drought', 'moderate'),
           (30, 'D0', 'Abnormally Dry', 'dry'), (100, '-', 'No Drought', 'none'))


def climatology(g, first_year):
    """Per cell and calendar month: mean of each variable and the sorted base-period soil moisture values."""
    n, T = g['sm_root'].shape
    t = np.arange(T)
    year = first_year + t // 12
    base = (year >= BASE_FIRST) & (year <= BASE_LAST)
    clim = {f'{v}_mean': np.zeros((n, 12)) for v in VARS}
    samples = []
    for m in range(12):
        cols = np.where(base & (t % 12 == m))[0]
        for v in VARS:
            clim[f'{v}_mean'][:, m] = g[v][:, cols].mean(1)
        samples.append(np.sort(g['sm_root'][:, cols], axis=1))
    clim['sm_base'] = np.stack(samples, axis=1)  # [cell, month, years]
    return clim


def percentile(x, cells, months, clim):
    """Share of base-period values below x (ties count half), 0-100."""
    base = clim['sm_base'][cells, months]  # [k, years]
    x = np.asarray(x)[:, None]
    return 100 * ((base < x).sum(1) + 0.5 * (base == x).sum(1)) / base.shape[1]


def build(g, clim, lat, lon, t, h):
    tt, m0, mt = t + h, t % 12, (t + h) % 12
    c = np.arange(g['sm_root'].shape[0])
    p = g['precip']
    p3 = p[:, t] + p[:, t - 1] + p[:, t - 2]
    cols = [
        g['sm_root'][:, t], g['sm_root'][:, t - 1], g['sm_root'][:, t - 2], g['sm_top'][:, t],
        p[:, t], p[:, t - 1], p[:, t - 2], p3, p[:, t - 5:t + 1].sum(1),
        g['t2m'][:, t], g['t2m'][:, t - 1], g['rh'][:, t], g['wind'][:, t],
        g['sm_root'][:, t] - clim['sm_root_mean'][c, m0],
        p3 - sum(clim['precip_mean'][c, (t - k) % 12] for k in range(3)),
        g['t2m'][:, t] - clim['t2m_mean'][c, m0],
        clim['sm_root_mean'][c, mt], clim['precip_mean'][c, mt], g['sm_root'][:, tt - 12],
        percentile(g['sm_root'][:, t], c, np.full(len(c), m0), clim),
        np.full(len(c), np.sin(2 * np.pi * mt / 12)), np.full(len(c), np.cos(2 * np.pi * mt / 12)),
        lat, lon,
    ]
    return np.column_stack(cols)


def category(pct):
    """Index into CLASSES for each percentile."""
    bounds = np.array([b for b, *_ in CLASSES[:-1]], dtype=float)
    return np.searchsorted(bounds, np.asarray(pct), side='left')
