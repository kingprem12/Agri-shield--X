"""Shared feature definitions for the satellite drought model.

Every feature here is mirrored exactly in satellite_engine.php (sat_features).
If you change one side, change the other and re-run test_parity.py.
"""
import numpy as np

HORIZONS = (1, 2, 3)
TARGETS = ('ndvi', 'lst')
CLIM_FIRST_YEAR, CLIM_LAST_YEAR = 2001, 2017  # climatology uses training years only

FEATURES = (
    'ndvi_0', 'ndvi_1', 'ndvi_2', 'ndvi_3',
    'lst_0', 'lst_1', 'lst_2',
    'p_0', 'p_1', 'p_2', 'p_sum3', 'p_sum6',
    'ndvi_anom', 'lst_anom', 'p_anom3',
    'clim_ndvi_target', 'clim_lst_target',
    'ndvi_last_year_target', 'lst_last_year_target',
    'month_sin', 'month_cos', 'lat', 'lon',
)
MIN_ORIGIN = 11  # need 12 months of history (t-11) for last-year-target at h=1


def climatology(ndvi, lst, precip, years):
    """Per cell, per calendar month: ndvi min/max/mean, lst min/max/mean, precip mean.

    Arrays are [cell, time] with time 0 = Jan of the first year.
    Returns dict of [cell, 12] arrays.
    """
    t = np.arange(ndvi.shape[1])
    year = years[0] + t // 12
    mask = (year >= CLIM_FIRST_YEAR) & (year <= CLIM_LAST_YEAR)
    out = {k: np.zeros((ndvi.shape[0], 12)) for k in
           ('ndvi_min', 'ndvi_max', 'ndvi_mean', 'lst_min', 'lst_max', 'lst_mean', 'p_mean')}
    for m in range(12):
        cols = np.where(mask & (t % 12 == m))[0]
        out['ndvi_min'][:, m] = ndvi[:, cols].min(1)
        out['ndvi_max'][:, m] = ndvi[:, cols].max(1)
        out['ndvi_mean'][:, m] = ndvi[:, cols].mean(1)
        out['lst_min'][:, m] = lst[:, cols].min(1)
        out['lst_max'][:, m] = lst[:, cols].max(1)
        out['lst_mean'][:, m] = lst[:, cols].mean(1)
        out['p_mean'][:, m] = precip[:, cols].mean(1)
    return out


def build(ndvi, lst, precip, clim, lat, lon, origin, h):
    """Feature matrix for all cells at origin month index `origin`, horizon h."""
    t, tt = origin, origin + h
    m0, mt = t % 12, tt % 12
    c = np.arange(ndvi.shape[0])
    p3 = precip[:, t] + precip[:, t - 1] + precip[:, t - 2]
    cols = [
        ndvi[:, t], ndvi[:, t - 1], ndvi[:, t - 2], ndvi[:, t - 3],
        lst[:, t], lst[:, t - 1], lst[:, t - 2],
        precip[:, t], precip[:, t - 1], precip[:, t - 2], p3, precip[:, t - 5:t + 1].sum(1),
        ndvi[:, t] - clim['ndvi_mean'][c, m0],
        lst[:, t] - clim['lst_mean'][c, m0],
        p3 - sum(clim['p_mean'][c, (t - k) % 12] for k in range(3)),
        clim['ndvi_mean'][c, mt], clim['lst_mean'][c, mt],
        ndvi[:, tt - 12], lst[:, tt - 12],
        np.full(len(c), np.sin(2 * np.pi * mt / 12)), np.full(len(c), np.cos(2 * np.pi * mt / 12)),
        lat, lon,
    ]
    return np.column_stack(cols)


def vhi(ndvi, lst, clim, cells, month):
    """Kogan Vegetation Health Index (0-100) from NDVI and LST for given calendar month."""
    nmin, nmax = clim['ndvi_min'][cells, month], clim['ndvi_max'][cells, month]
    lmin, lmax = clim['lst_min'][cells, month], clim['lst_max'][cells, month]
    vci = 100 * (ndvi - nmin) / np.maximum(nmax - nmin, 1e-6)
    tci = 100 * (lmax - lst) / np.maximum(lmax - lmin, 1e-6)
    return np.clip(0.5 * np.clip(vci, 0, 100) + 0.5 * np.clip(tci, 0, 100), 0, 100)
