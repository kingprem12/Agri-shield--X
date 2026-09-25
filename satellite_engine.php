<?php
/*
 * Satellite drought model: PHP inference for the PSO-LightGBM trees exported by
 * ml/satellite/train_satellite.py. sat_features() mirrors ml/satellite/features.py
 * exactly; ml/satellite/test_parity.php checks both sides agree.
 */
require_once __DIR__ . '/database.php';

function sat_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $file = __DIR__ . '/data/satellite/sindh.db';
    if (!file_exists($file)) throw new Exception('Satellite dataset not found. Run ml/satellite/build_dataset.py first.');
    $pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    return $pdo;
}
function sat_model() {
    static $model = null;
    if ($model) return $model;
    $file = __DIR__ . '/data/satellite/model.json';
    if (!file_exists($file)) throw new Exception('No trained satellite model. Run ml/satellite/train_satellite.py first.');
    $model = json_decode(file_get_contents($file), true);
    if (!is_array($model) || request_value($model, 'schema', 0) !== 1 || request_value($model, 'algorithm', '') !== 'PSO-LightGBM') throw new Exception('Unsupported satellite model file.');
    return $model;
}
function sat_meta() {
    static $meta = null;
    if ($meta) return $meta;
    $meta = array();
    foreach (sat_db()->query('SELECT key, value FROM meta') as $row) $meta[$row['key']] = $row['value'];
    $meta['first_year'] = (int)$meta['first_year']; $meta['months'] = (int)$meta['months'];
    return $meta;
}
/* Month index t (0 = January of first year) <-> "YYYY-MM". */
function sat_label($t) { $m = sat_meta(); return sprintf('%04d-%02d', $m['first_year'] + intdiv($t, 12), $t % 12 + 1); }
function sat_index($label) {
    if (!is_string($label) || !preg_match('/^(\d{4})-(\d{2})$/', $label, $x)) return null;
    $m = sat_meta(); $t = ((int)$x[1] - $m['first_year']) * 12 + (int)$x[2] - 1;
    return ($t >= 0 && $t < $m['months'] && (int)$x[2] >= 1 && (int)$x[2] <= 12) ? $t : null;
}
function sat_cell($id) {
    $q = sat_db()->prepare('SELECT id, lat, lon FROM cells WHERE id = ?'); $q->execute(array((int)$id));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ? array('id' => (int)$row['id'], 'lat' => (float)$row['lat'], 'lon' => (float)$row['lon']) : null;
}
function sat_nearest_cell($lat, $lon) {
    $q = sat_db()->prepare('SELECT id FROM cells ORDER BY (lat - ?) * (lat - ?) + (lon - ?) * (lon - ?) LIMIT 1');
    $q->execute(array($lat, $lat, $lon, $lon));
    return sat_cell($q->fetchColumn());
}
function sat_series($cell, $from, $to) {
    $q = sat_db()->prepare('SELECT t, ndvi, lst, precip, filled FROM obs WHERE cell_id = ? AND t BETWEEN ? AND ? ORDER BY t');
    $q->execute(array($cell, $from, $to)); $out = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['t']] = array('ndvi' => (float)$r['ndvi'], 'lst' => (float)$r['lst'], 'precip' => (float)$r['precip'], 'filled' => (int)$r['filled']);
    return $out;
}
function sat_clim($cell) {
    $q = sat_db()->prepare('SELECT * FROM clim WHERE cell_id = ? ORDER BY month'); $q->execute(array($cell)); $out = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $m = (int)$r['month'] - 1; unset($r['cell_id'], $r['month']); $out[$m] = array_map('floatval', $r); }
    return $out;
}
/* Mirror of features.build() for one cell. Order must match model['features']. */
function sat_features($cell, $series, $clim, $t, $h) {
    $tt = $t + $h; $m0 = $t % 12; $mt = $tt % 12;
    $n = function ($k, $i) use ($series) { if (!isset($series[$i])) throw new Exception('Not enough history for this month.'); return $series[$i][$k]; };
    $p3 = $n('precip', $t) + $n('precip', $t - 1) + $n('precip', $t - 2);
    $p6 = 0.0; for ($i = $t - 5; $i <= $t; $i++) $p6 += $n('precip', $i);
    $pc = 0; for ($k = 0; $k < 3; $k++) $pc += $clim[(($t - $k) % 12 + 12) % 12]['p_mean'];
    return array(
        $n('ndvi', $t), $n('ndvi', $t - 1), $n('ndvi', $t - 2), $n('ndvi', $t - 3),
        $n('lst', $t), $n('lst', $t - 1), $n('lst', $t - 2),
        $n('precip', $t), $n('precip', $t - 1), $n('precip', $t - 2), $p3, $p6,
        $n('ndvi', $t) - $clim[$m0]['ndvi_mean'],
        $n('lst', $t) - $clim[$m0]['lst_mean'],
        $p3 - $pc,
        $clim[$mt]['ndvi_mean'], $clim[$mt]['lst_mean'],
        $n('ndvi', $tt - 12), $n('lst', $tt - 12),
        sin(2 * M_PI * $mt / 12), cos(2 * M_PI * $mt / 12),
        $cell['lat'], $cell['lon'],
    );
}
/* LightGBM numeric tree: go left when value <= threshold; negative child = ~leaf index. */
function sat_tree($tree, $x) {
    if (!isset($tree['f'])) return $tree['v'][0];
    $node = 0;
    while ($node >= 0) $node = $x[$tree['f'][$node]] <= $tree['t'][$node] ? $tree['l'][$node] : $tree['r'][$node];
    return $tree['v'][~$node];
}
function sat_predict($trees, $x) { $sum = 0.0; foreach ($trees as $tree) $sum += sat_tree($tree, $x); return $sum; }
function sat_vhi($ndvi, $lst, $c) {
    $vci = 100 * ($ndvi - $c['ndvi_min']) / max($c['ndvi_max'] - $c['ndvi_min'], 1e-6);
    $tci = 100 * ($c['lst_max'] - $lst) / max($c['lst_max'] - $c['lst_min'], 1e-6);
    return max(0, min(100, 0.5 * max(0, min(100, $vci)) + 0.5 * max(0, min(100, $tci))));
}
function sat_class($vhi) {
    foreach (sat_model()['drought_classes'] as $c) if ($vhi < $c['below']) return array('label' => $c['label'], 'class' => strtolower(strtok($c['label'], ' ')));
}
/* Forecast horizons 1-3 from origin month t for one cell; includes actual values when they exist (backtest). */
function sat_forecast($cellId, $t, $override = null) {
    $model = sat_model(); $cell = sat_cell($cellId); if (!$cell) throw new Exception('Unknown grid cell.');
    if ($t < 11) throw new Exception('Choose a month from ' . sat_label(11) . ' onwards (12 months of history are needed).');
    $meta = sat_meta(); $series = sat_series($cellId, $t - 11, min($t + 3, $meta['months'] - 1)); $clim = sat_clim($cellId); $rows = array();
    $observed = isset($series[$t]) ? $series[$t] : null;
    if ($override && $observed) foreach (array('ndvi', 'lst', 'precip') as $k) if (isset($override[$k])) $series[$t][$k] = (float)$override[$k];
    foreach ($model['horizons'] as $h) {
        $x = sat_features($cell, $series, $clim, $t, $h);
        $ndvi = sat_predict($model['models']['ndvi_h' . $h], $x); $lst = sat_predict($model['models']['lst_h' . $h], $x);
        $m = ($t + $h) % 12; $vhi = sat_vhi($ndvi, $lst, $clim[$m]);
        $row = array('h' => $h, 't' => $t + $h, 'month' => sat_label($t + $h), 'ndvi' => $ndvi, 'lst' => $lst, 'vhi' => $vhi, 'drought' => sat_class($vhi), 'actual' => null);
        if (isset($series[$t + $h])) { $a = $series[$t + $h]; $av = sat_vhi($a['ndvi'], $a['lst'], $clim[$m]); $row['actual'] = array('ndvi' => $a['ndvi'], 'lst' => $a['lst'], 'vhi' => $av, 'drought' => sat_class($av)); }
        $rows[] = $row;
    }
    return array('cell' => $cell, 'origin' => sat_label($t), 'rows' => $rows, 'clim' => $clim, 'observed' => $observed, 'inputs' => $series[$t]);
}
/* Predicted (or actual) VHI for every cell at one horizon; cached because it evaluates ~5k cells in PHP. */
function sat_region_map($t, $h, $actual) {
    $model = sat_model(); $key = md5($model['trained_at']);
    $dir = __DIR__ . '/data/satellite/cache'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . "/map-$key-$t-$h-" . ($actual ? 'actual' : 'pred') . '.json';
    $cached = read_json_file($file, null); if ($cached) return $cached;
    $db = sat_db(); $meta = sat_meta(); $target = $t + $h;
    if ($actual && $target >= $meta['months']) return null;
    $cells = $db->query('SELECT id, lat, lon FROM cells ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $obs = array(); $q = $db->prepare('SELECT cell_id, t, ndvi, lst, precip FROM obs WHERE t BETWEEN ? AND ?'); $q->execute(array($t - 11, $target));
    foreach ($q as $r) $obs[(int)$r['cell_id']][(int)$r['t']] = array('ndvi' => (float)$r['ndvi'], 'lst' => (float)$r['lst'], 'precip' => (float)$r['precip']);
    $clims = array(); foreach ($db->query('SELECT * FROM clim') as $r) { $c = (int)$r['cell_id']; $m = (int)$r['month'] - 1; unset($r['cell_id'], $r['month']); $clims[$c][$m] = array_map('floatval', $r); }
    $out = array(); $m = $target % 12;
    foreach ($cells as $c) {
        $id = (int)$c['id']; $cell = array('id' => $id, 'lat' => (float)$c['lat'], 'lon' => (float)$c['lon']);
        if ($actual) { $a = $obs[$id][$target]; $v = sat_vhi($a['ndvi'], $a['lst'], $clims[$id][$m]); }
        else { $x = sat_features($cell, $obs[$id], $clims[$id], $t, $h); $v = sat_vhi(sat_predict($model['models']['ndvi_h' . $h], $x), sat_predict($model['models']['lst_h' . $h], $x), $clims[$id][$m]); }
        $out[] = array($id, $cell['lat'], $cell['lon'], round($v, 1));
    }
    write_json_file($file, $out);
    return $out;
}
?>
