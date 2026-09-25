<?php
/*
 * India drought model: PHP inference for the PSO-LightGBM soil-moisture trees exported by
 * ml/india/train_india.py. india_features() mirrors ml/india/india_features.py exactly;
 * ml/india/test_parity.php checks both sides agree.
 */
require_once __DIR__ . '/satellite_engine.php'; // sat_tree / sat_predict (shared LightGBM evaluator)

const INDIA_VARS = array('t2m', 'rh', 'precip', 'wind', 'sm_root', 'sm_top');

function india_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $file = __DIR__ . '/data/india/india.db';
    if (!file_exists($file)) throw new Exception('India dataset not found. Run ml/india/fetch_power.py first.');
    return $pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
}
function india_model() {
    static $model = null;
    if ($model) return $model;
    $file = __DIR__ . '/data/india/model.json';
    if (!file_exists($file)) throw new Exception('No trained India model. Run ml/india/train_india.py first.');
    $model = json_decode(file_get_contents($file), true);
    if (!is_array($model) || request_value($model, 'schema', 0) !== 1 || request_value($model, 'region', '') !== 'India') throw new Exception('Unsupported India model file.');
    return $model;
}
function india_meta() {
    static $meta = null;
    if ($meta) return $meta;
    $meta = array();
    foreach (india_db()->query('SELECT key, value FROM meta') as $r) $meta[$r['key']] = $r['value'];
    $meta['first_year'] = (int)$meta['first_year']; $meta['months'] = (int)$meta['months'];
    return $meta;
}
function india_label($t) { $m = india_meta(); return sprintf('%04d-%02d', $m['first_year'] + intdiv($t, 12), $t % 12 + 1); }
function india_index($label) {
    if (!is_string($label) || !preg_match('/^(\d{4})-(\d{2})$/', $label, $x)) return null;
    $m = india_meta(); $t = ((int)$x[1] - $m['first_year']) * 12 + (int)$x[2] - 1;
    return ($t >= 0 && $t < $m['months'] && (int)$x[2] >= 1 && (int)$x[2] <= 12) ? $t : null;
}
function india_cell_row($r) { return array('id' => (int)$r['id'], 'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'state' => $r['state'], 'district' => $r['district']); }
function india_cell($id) {
    $q = india_db()->prepare('SELECT * FROM cells WHERE id = ?'); $q->execute(array((int)$id)); $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? india_cell_row($r) : null;
}
function india_nearest_cell($lat, $lon) {
    $k = cos($lat * M_PI / 180) ** 2; // scale longitude differences so "nearest" is by ground distance
    $q = india_db()->prepare('SELECT * FROM cells ORDER BY (lat - ?) * (lat - ?) + (lon - ?) * (lon - ?) * ? LIMIT 1');
    $q->execute(array($lat, $lat, $lon, $lon, $k));
    return india_cell_row($q->fetch(PDO::FETCH_ASSOC));
}
function india_series($cell, $from, $to) {
    $q = india_db()->prepare('SELECT * FROM obs WHERE cell_id = ? AND t BETWEEN ? AND ? ORDER BY t'); $q->execute(array($cell, $from, $to)); $out = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $row = array(); foreach (INDIA_VARS as $v) $row[$v] = (float)$r[$v]; $out[(int)$r['t']] = $row; }
    return $out;
}
/* Climatology written by the trainer: per month the variable means and sorted 1981-2010 soil moisture values. */
function india_clim($cell) {
    $q = india_db()->prepare('SELECT * FROM clim WHERE cell_id = ? ORDER BY month'); $q->execute(array($cell)); $out = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int)$r['month']; $row = array('sm_base' => json_decode($r['sm_base'], true));
        foreach (INDIA_VARS as $v) $row[$v . '_mean'] = (float)$r[$v . '_mean'];
        $out[$m] = $row;
    }
    return $out;
}
function india_percentile($x, $base) {
    $below = 0; $equal = 0;
    foreach ($base as $b) { if ($b < $x) $below++; elseif ($b == $x) $equal++; }
    return 100 * ($below + 0.5 * $equal) / count($base);
}
/* Mirror of features.build() for one cell. */
function india_features($cell, $s, $clim, $t, $h) {
    $tt = $t + $h; $m0 = $t % 12; $mt = $tt % 12;
    $n = function ($k, $i) use ($s) { if (!isset($s[$i])) throw new Exception('Not enough history for this month.'); return $s[$i][$k]; };
    $p3 = $n('precip', $t) + $n('precip', $t - 1) + $n('precip', $t - 2);
    $p6 = 0.0; for ($i = $t - 5; $i <= $t; $i++) $p6 += $n('precip', $i);
    $pc = 0; for ($k = 0; $k < 3; $k++) $pc += $clim[(($t - $k) % 12 + 12) % 12]['precip_mean'];
    return array(
        $n('sm_root', $t), $n('sm_root', $t - 1), $n('sm_root', $t - 2), $n('sm_top', $t),
        $n('precip', $t), $n('precip', $t - 1), $n('precip', $t - 2), $p3, $p6,
        $n('t2m', $t), $n('t2m', $t - 1), $n('rh', $t), $n('wind', $t),
        $n('sm_root', $t) - $clim[$m0]['sm_root_mean'],
        $p3 - $pc,
        $n('t2m', $t) - $clim[$m0]['t2m_mean'],
        $clim[$mt]['sm_root_mean'], $clim[$mt]['precip_mean'], $n('sm_root', $tt - 12),
        india_percentile($n('sm_root', $t), $clim[$m0]['sm_base']),
        sin(2 * M_PI * $mt / 12), cos(2 * M_PI * $mt / 12),
        $cell['lat'], $cell['lon'],
    );
}
function india_class($pct) {
    foreach (india_model()['classes'] as $c) if ($pct <= $c['max_pct']) return array('code' => $c['code'], 'label' => $c['label'], 'class' => $c['key']);
}
function india_assess($sm, $clim) { $pct = india_percentile($sm, $clim['sm_base']); return array('sm_root' => $sm, 'percentile' => $pct, 'risk' => round(100 - $pct, 1), 'drought' => india_class($pct)); }
/* 1-3 month forecast from origin t. $override replaces this month's inputs (what-if). */
function india_forecast($cellId, $t, $override = null) {
    $model = india_model(); $cell = india_cell($cellId); if (!$cell) throw new Exception('Unknown grid cell.');
    if ($t < 11) throw new Exception('Choose a month from ' . india_label(11) . ' onwards.');
    $meta = india_meta(); $s = india_series($cellId, $t - 11, min($t + 3, $meta['months'] - 1)); $clim = india_clim($cellId);
    $observed = $s[$t];
    if ($override) foreach (INDIA_VARS as $v) if (isset($override[$v])) $s[$t][$v] = (float)$override[$v];
    $rows = array();
    foreach ($model['horizons'] as $h) {
        $sm = sat_predict($model['models']['sm_h' . $h], india_features($cell, $s, $clim, $t, $h));
        $m = ($t + $h) % 12;
        $row = array('h' => $h, 't' => $t + $h, 'month' => india_label($t + $h)) + india_assess($sm, $clim[$m]) + array('actual' => null);
        if (isset($s[$t + $h])) $row['actual'] = india_assess($s[$t + $h]['sm_root'], $clim[$m]);
        $rows[] = $row;
    }
    return array('cell' => $cell, 'origin' => india_label($t), 'observed' => $observed, 'inputs' => $s[$t], 'current' => india_assess($s[$t]['sm_root'], $clim[$t % 12]), 'rows' => $rows);
}
/* Percentile for every grid cell: actual at month t, or the +h forecast from t. Cached. */
function india_region_map($t, $h) {
    $model = india_model(); $dir = __DIR__ . '/data/india/cache'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . '/map-' . md5($model['trained_at']) . "-$t-$h.json";
    $cached = read_json_file($file, null); if ($cached) return $cached;
    $db = india_db(); $cells = $db->query('SELECT * FROM cells ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $obs = array(); $q = $db->prepare('SELECT * FROM obs WHERE t BETWEEN ? AND ?'); $q->execute(array($h ? $t - 11 : $t, $t));
    foreach ($q as $r) { $row = array(); foreach (INDIA_VARS as $v) $row[$v] = (float)$r[$v]; $obs[(int)$r['cell_id']][(int)$r['t']] = $row; }
    $clims = array();
    foreach ($db->query('SELECT * FROM clim') as $r) { $c = array('sm_base' => json_decode($r['sm_base'], true)); foreach (INDIA_VARS as $v) $c[$v . '_mean'] = (float)$r[$v . '_mean']; $clims[(int)$r['cell_id']][(int)$r['month']] = $c; }
    $out = array(); $m = ($t + $h) % 12;
    foreach ($cells as $r) {
        $c = india_cell_row($r); $id = $c['id'];
        $sm = $h ? sat_predict($model['models']['sm_h' . $h], india_features($c, $obs[$id], $clims[$id], $t, $h)) : $obs[$id][$t]['sm_root'];
        $out[] = array($id, $c['lat'], $c['lon'], round(india_percentile($sm, $clims[$id][$m]['sm_base']), 1));
    }
    write_json_file($file, $out);
    return $out;
}
?>
