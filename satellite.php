<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/satellite_engine.php';
require_once __DIR__ . '/layout.php';

$places = array('karachi' => array('Karachi', 24.86, 67.01), 'hyderabad' => array('Hyderabad', 25.40, 68.37), 'thatta' => array('Thatta', 24.75, 67.92),
    'badin' => array('Badin', 24.66, 68.84), 'mirpurkhas' => array('Mirpur Khas', 25.53, 69.01), 'umerkot' => array('Umerkot', 25.36, 69.74),
    'mithi' => array('Mithi (Tharparkar)', 24.74, 69.80), 'nawabshah' => array('Nawabshah', 26.24, 68.41), 'dadu' => array('Dadu', 26.73, 67.78),
    'larkana' => array('Larkana', 27.56, 68.21), 'sukkur' => array('Sukkur', 27.70, 68.86), 'jacobabad' => array('Jacobabad', 28.28, 68.44));
$error = ''; $placeKey = request_value($_GET, 'place', 'hyderabad'); $h = max(1, min(3, (int)request_value($_GET, 'h', 1)));
try {
    $model = sat_model(); $meta = sat_meta(); $last = $meta['months'] - 1;
    $t = sat_index(request_value($_GET, 'from', sat_label($last)));
    if ($t === null || $t < 11) throw new Exception('Choose a month between ' . sat_label(11) . ' and ' . sat_label($last) . '.');
    if (isset($_GET['cell']) && is_numeric($_GET['cell']) && ($c = sat_cell($_GET['cell']))) { $cell = $c; $placeKey = 'cell'; }
    else { if (!isset($places[$placeKey])) $placeKey = 'hyderabad'; $cell = sat_nearest_cell($places[$placeKey][1], $places[$placeKey][2]); }
    $fc = sat_forecast($cell['id'], $t);
    $history = sat_series($cell['id'], max(0, $t - 23), min($last, $t + 3));
    $map = sat_region_map($t, $h, false);
    $mapActual = ($t + $h <= $last) ? sat_region_map($t, $h, true) : null;
} catch (Exception $e) { $error = $e->getMessage(); }
$place = $placeKey === 'cell' ? 'Grid cell #' . $cell['id'] : $places[$placeKey][0];
page_start('Satellite Drought Outlook', 1, false);
?>
<link rel="stylesheet" href="css/prediction.css"><link rel="stylesheet" href="css/satellite.css">
<div class="prediction-heading"><span class="eyebrow">REGIONAL DROUGHT MODEL / PSO-LIGHTGBM</span><h2>Satellite Drought Outlook</h2><p>Forecast vegetation health (NDVI), land surface temperature and drought class 1–3 months ahead.</p></div>
<?php if ($error): ?><p class="notice" role="alert"><?= h($error) ?></p><?php page_end(); exit; endif; ?>
<p class="data-badge">🛰️ Training data: <strong>satellite seed data</strong> — <?= h($meta['region']) ?>, <?= h($model['first_year']) ?>–<?= h(substr($model['data_until'], 0, 4)) ?> (MODIS NDVI &amp; LST, monthly precipitation). Not ESP32 sensor data.</p>
<form class="filters prediction-filters" method="get">
<label>Location<select name="place"><?php foreach ($places as $k => $p): ?><option value="<?= h($k) ?>" <?= $k === $placeKey ? 'selected' : '' ?>><?= h($p[0]) ?></option><?php endforeach; ?><?php if ($placeKey === 'cell'): ?><option value="cell" selected disabled><?= h($place) ?></option><?php endif; ?></select></label>
<?php if ($placeKey === 'cell'): ?><input type="hidden" name="cell" value="<?= (int)$cell['id'] ?>"><?php endif; ?>
<label>Forecast from month<input type="month" name="from" value="<?= h(sat_label($t)) ?>" min="<?= h(sat_label(11)) ?>" max="<?= h(sat_label($last)) ?>" required></label>
<label>Map horizon<select name="h"><?php for ($i = 1; $i <= 3; $i++): ?><option value="<?= $i ?>" <?= $i === $h ? 'selected' : '' ?>>+<?= $i ?> month<?= $i > 1 ? 's' : '' ?></option><?php endfor; ?></select></label>
<button class="button">Generate outlook</button></form>

<?php $first = $fc['rows'][0]; $risk = $first['drought']; ?>
<section class="outlook-grid">
<article class="outlook-panel chart-panel"><div class="panel-title"><div><span class="eyebrow"><?= h(strtoupper($place)) ?> · <?= number_format($cell['lat'], 2) ?>°N <?= number_format($cell['lon'], 2) ?>°E</span><h3>NDVI history &amp; forecast</h3></div><span class="range-pill">from <?= h($fc['origin']) ?></span></div>
<div class="chart-legend"><span>Observed NDVI</span><span class="rain-key">PSO-LightGBM forecast</span><?php if ($first['actual']): ?><span class="risk-key">Actual (backtest)</span><?php endif; ?></div>
<?php
$pts = array(); foreach ($history as $i => $r) $pts[$i] = $r['ndvi'];
$fcp = array($t => $history[$t]['ndvi']); foreach ($fc['rows'] as $r) $fcp[$r['t']] = $r['ndvi'];
$vals = array_merge(array_values($pts), array_values($fcp)); $lo = floor(min($vals) * 20) / 20; $hi = ceil(max($vals) * 20) / 20; if ($hi - $lo < .1) $hi = $lo + .1;
$t0 = min(array_keys($pts)); $t1 = $t + 3; $X = function ($i) use ($t0, $t1) { return round(48 + ($i - $t0) * 626 / max(1, $t1 - $t0), 1); }; $Y = function ($v) use ($lo, $hi) { return round(260 - ($v - $lo) * 230 / ($hi - $lo), 1); };
$line = function ($series) use ($X, $Y) { $o = array(); foreach ($series as $i => $v) $o[] = $X($i) . ',' . $Y($v); return implode(' ', $o); };
$obsPast = array_filter($pts, function ($i) use ($t) { return $i <= $t; }, ARRAY_FILTER_USE_KEY);
$obsFuture = array_filter($pts, function ($i) use ($t) { return $i >= $t; }, ARRAY_FILTER_USE_KEY);
?>
<svg class="forecast-chart" viewBox="0 0 700 320" role="img" aria-label="NDVI history and forecast">
<?php for ($k = 0; $k <= 4; $k++): $v = $lo + ($hi - $lo) * $k / 4; ?><line x1="48" x2="674" y1="<?= $Y($v) ?>" y2="<?= $Y($v) ?>" class="chart-grid"/><text x="40" y="<?= $Y($v) + 4 ?>" text-anchor="end" class="chart-label"><?= number_format($v, 2) ?></text><?php endfor; ?>
<rect x="<?= $X($t) ?>" y="30" width="<?= 674 - $X($t) ?>" height="230" fill="#3485ed" opacity=".06"/>
<polyline points="<?= $line($obsPast) ?>" fill="none" stroke="#149b77" stroke-width="3"/>
<?php if (count($obsFuture) > 1): ?><polyline points="<?= $line($obsFuture) ?>" fill="none" stroke="#d27617" stroke-width="3"/><?php endif; ?>
<polyline points="<?= $line($fcp) ?>" fill="none" stroke="#3485ed" stroke-width="3" stroke-dasharray="7 5"/>
<?php foreach ($fc['rows'] as $r): ?><circle cx="<?= $X($r['t']) ?>" cy="<?= $Y($r['ndvi']) ?>" r="5" fill="#3485ed"><title><?= h($r['month'] . ' forecast NDVI ' . number_format($r['ndvi'], 3)) ?></title></circle><?php endforeach; ?>
<text x="48" y="294" class="chart-label"><?= h(sat_label($t0)) ?></text><text x="<?= $X($t) ?>" y="294" text-anchor="middle" class="chart-label"><?= h(sat_label($t)) ?></text><text x="674" y="294" text-anchor="end" class="chart-label"><?= h(sat_label($t1)) ?></text></svg>
<p class="chart-note">Shaded area = forecast period. NDVI: higher means greener, healthier vegetation.<?= $first['actual'] ? ' Past origin month selected, so actual satellite values are shown for comparison.' : '' ?></p></article>

<aside class="outlook-panel drought-panel risk-<?= h(in_array($risk['class'], array('extreme', 'severe')) ? 'high' : ($risk['class'] === 'no' ? 'low' : 'medium')) ?>"><span class="eyebrow">NEXT MONTH · <?= h($first['month']) ?></span><h3>Vegetation Health Index</h3>
<div class="risk-orbit"><span><?= h($risk['label']) ?></span><strong><?= number_format($first['vhi'], 1) ?><small>/100</small></strong></div>
<p class="risk-date">VHI below 40 = drought (Kogan scale)</p>
<?php if ($first['actual']): ?><p class="risk-counts">Actual: <strong><?= number_format($first['actual']['vhi'], 1) ?></strong> · <?= h($first['actual']['drought']['label']) ?></p><?php endif; ?>
<p class="risk-advice"><?= $first['vhi'] < 20 ? 'Severe vegetation stress expected. Prioritise irrigation planning and water storage.' : ($first['vhi'] < 40 ? 'Drought conditions likely. Monitor soil moisture closely and plan irrigation.' : 'No drought expected from satellite indicators. Continue routine monitoring.') ?></p></aside>
</section>

<div class="forecast-table-wrap"><table><caption>Monthly outlook · <?= h($place) ?> (grid cell #<?= (int)$cell['id'] ?>)</caption><thead><tr><th>Month</th><th>NDVI</th><th>LST °C</th><th>VHI</th><th>Drought class</th><?php if ($first['actual']): ?><th>Actual NDVI</th><th>Actual LST</th><th>Actual class</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($fc['rows'] as $r): ?><tr><td><?= h($r['month']) ?> <small>(+<?= $r['h'] ?>)</small></td><td><?= number_format($r['ndvi'], 3) ?></td><td><?= number_format($r['lst'], 1) ?></td><td><?= number_format($r['vhi'], 1) ?></td><td><span class="drought-badge d-<?= h($r['drought']['class']) ?>"><?= h($r['drought']['label']) ?></span></td>
<?php if ($first['actual']): ?><?php if ($r['actual']): ?><td><?= number_format($r['actual']['ndvi'], 3) ?></td><td><?= number_format($r['actual']['lst'], 1) ?></td><td><span class="drought-badge d-<?= h($r['actual']['drought']['class']) ?>"><?= h($r['actual']['drought']['label']) ?></span></td><?php else: ?><td colspan="3">—</td><?php endif; ?><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div>

<?php
$lons = array_column($map, 2); $lats = array_column($map, 1); $minLon = min($lons); $maxLat = max($lats); $step = 0.08983; $s = 6;
$W = round((max($lons) - $minLon) / $step + 1) * $s; $H = round(($maxLat - min($lats)) / $step + 1) * $s;
$drawMap = function ($data, $title) use ($minLon, $maxLat, $step, $s, $W, $H, $cell) {
    $colors = array('extreme' => '#7f1d1d', 'severe' => '#dc2626', 'moderate' => '#f97316', 'mild' => '#facc15', 'no' => '#16a34a');
    $counts = array_fill_keys(array_keys($colors), 0);
    echo '<figure class="map-figure"><figcaption>' . h($title) . '</figcaption><svg class="drought-map" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($title) . '">';
    foreach ($data as $d) {
        $c = sat_class($d[3])['class']; $counts[$c]++;
        printf('<rect x="%d" y="%d" width="%d" height="%d" fill="%s" data-id="%d" data-v="%s"/>', round(($d[2] - $minLon) / $step) * $s, round(($maxLat - $d[1]) / $step) * $s, $s, $s, $colors[$c], $d[0], $d[3]);
    }
    printf('<circle cx="%d" cy="%d" r="9" fill="none" stroke="#111" stroke-width="2.5"/>', round(($cell['lon'] - $minLon) / $step) * $s + 3, round(($maxLat - $cell['lat']) / $step) * $s + 3);
    $total = max(1, array_sum($counts)); $drought = $total - $counts['no'];
    echo '</svg><p class="map-summary"><strong>' . round(100 * $drought / $total) . '%</strong> of the region in drought (VHI &lt; 40)</p></figure>';
};
?>
<section class="outlook-panel map-panel"><div class="panel-title"><div><span class="eyebrow">REGIONAL MAP · <?= count($map) ?> GRID CELLS (~10 KM)</span><h3>Drought outlook for <?= h(sat_label($t + $h)) ?></h3></div><span class="range-pill">+<?= $h ?> month<?= $h > 1 ? 's' : '' ?></span></div>
<div class="map-row"><?php $drawMap($map, 'PSO-LightGBM forecast'); if ($mapActual) $drawMap($mapActual, 'Actual (satellite)'); ?></div>
<div class="map-legend"><span class="d-extreme">Extreme &lt;10</span><span class="d-severe">Severe &lt;20</span><span class="d-moderate">Moderate &lt;30</span><span class="d-mild">Mild &lt;40</span><span class="d-no">No drought</span></div>
<p class="chart-note">Click any grid cell to open its forecast. The ring marks the selected location. Forecast maps are computed by the PHP model evaluator and cached.</p></section>

<?php $ev = $model['evaluation']; ?>
<section class="outlook-panel model-panel"><span class="eyebrow">MODEL PERFORMANCE · HELD-OUT TEST <?= h($model['split']['test']) ?></span><h3>How accurate is the model?</h3>
<div class="metric-cards"><div><small>NDVI R² (+1 month)</small><strong><?= number_format($ev['1']['ndvi']['model']['r2'], 3) ?></strong></div><div><small>LST R² (+1 month)</small><strong><?= number_format($ev['1']['lst']['model']['r2'], 3) ?></strong></div><div><small>Drought yes/no accuracy</small><strong><?= number_format(100 * $ev['1']['vhi']['drought_accuracy'], 1) ?>%</strong></div><div><small>Test samples</small><strong><?= number_format($ev['1']['test_samples']) ?></strong></div></div>
<div class="forecast-table-wrap"><table><thead><tr><th>Horizon</th><th>NDVI R² model</th><th>NDVI R² persistence</th><th>NDVI R² climatology</th><th>LST R² model</th><th>LST R² climatology</th><th>VHI R²</th><th>Drought accuracy</th></tr></thead><tbody>
<?php foreach ($model['horizons'] as $hh): $e = $ev[(string)$hh]; ?><tr><td>+<?= $hh ?> month</td><td><strong><?= number_format($e['ndvi']['model']['r2'], 3) ?></strong></td><td><?= number_format($e['ndvi']['persistence']['r2'], 3) ?></td><td><?= number_format($e['ndvi']['climatology']['r2'], 3) ?></td><td><strong><?= number_format($e['lst']['model']['r2'], 3) ?></strong></td><td><?= number_format($e['lst']['climatology']['r2'], 3) ?></td><td><?= number_format($e['vhi']['model']['r2'], 3) ?></td><td><?= number_format(100 * $e['vhi']['drought_accuracy'], 1) ?>%</td></tr><?php endforeach; ?>
</tbody></table></div>
<details class="method-details"><summary>Method, PSO search and limitations</summary>
<p><strong>Data:</strong> <?= number_format(count($map)) ?> grid cells × <?= $model['months'] ?> months of MODIS NDVI, MODIS land surface temperature and monthly precipitation for Sindh. Split by time: train <?= h($model['split']['train']) ?>, validation <?= h($model['split']['validation']) ?> (PSO fitness), test <?= h($model['split']['test']) ?> (metrics above). Deployed models are refitted on all years.</p>
<p><strong>PSO-LightGBM:</strong> <?= (int)$model['pso']['particles'] ?> particles × <?= (int)$model['pso']['iterations'] ?> iterations searched num_leaves, learning rate, boosting rounds, min_data_in_leaf, feature fraction and L2 regularisation. Best: <?php $bp = $model['pso']['best_parameters']; echo h('leaves ' . $bp['num_leaves'] . ', learning rate ' . round($bp['learning_rate'], 3) . ', rounds ' . $bp['rounds'] . ', min leaf ' . $bp['min_data_in_leaf'] . ', feature fraction ' . round($bp['feature_fraction'], 2) . ', L2 ' . round($bp['lambda_l2'], 2)); ?>. Six regressors: NDVI and LST for +1, +2, +3 months.</p>
<p><strong>Top features:</strong> <?= h(implode(', ', array_map(function ($f) { return $f[0] . ' ' . round(100 * $f[1]) . '%'; }, array_slice($model['feature_importance'], 0, 6)))) ?>.</p>
<p><strong>Drought:</strong> VHI = 0.5 × VCI + 0.5 × TCI (Kogan), using per-cell monthly NDVI/LST ranges from <?= h($model['split']['train']) ?>. Classes: extreme &lt;10, severe &lt;20, moderate &lt;30, mild &lt;40.</p>
<p><strong>Limitations:</strong> persistence and climatology baselines are shown because vegetation changes slowly, so they also score well; the model's value is the improvement over them. VHI R² is lower than NDVI/LST R² because it amplifies small errors relative to each cell's range. This regional model is trained on Sindh satellite data, not on this farm's ESP32 sensors. Trained <?= h($model['trained_at']) ?>.</p></details>
</section>
<script>
document.querySelectorAll('.drought-map').forEach(function (svg) {
  svg.addEventListener('click', function (e) {
    var id = e.target.getAttribute('data-id'); if (!id) return;
    var p = new URLSearchParams(location.search); p.delete('place'); p.set('cell', id); location.search = p.toString();
  });
  svg.addEventListener('mousemove', function (e) { var v = e.target.getAttribute('data-v'); svg.setAttribute('title', v ? 'VHI ' + v : ''); });
});
</script>
<?php page_end(); ?>
