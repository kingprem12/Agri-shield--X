<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/india_engine.php';
require_once __DIR__ . '/farm_validation.php';
require_once __DIR__ . '/layout.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$places = require __DIR__ . '/india_places.php';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['farm_lat'], $_POST['farm_lon'])) {
    if (!secure_equals($_SESSION['csrf'], (string)request_value($_POST, 'csrf', ''))) $notice = 'Session expired. Please try again.';
    elseif (!is_numeric($_POST['farm_lat']) || !is_numeric($_POST['farm_lon']) || abs($_POST['farm_lat']) > 90 || abs($_POST['farm_lon']) > 180) $notice = 'Enter a valid latitude and longitude.';
    else { set_setting('farm_lat', round((float)$_POST['farm_lat'], 4)); set_setting('farm_lon', round((float)$_POST['farm_lon'], 4)); header('Location: validation.php#farm'); exit; }
}
$error = '';
try {
    $model = india_model(); $meta = india_meta();
    $live = read_json_file(__DIR__ . '/data/india/live_validation.json', null);
    if (!$live) throw new Exception('No real-time validation yet. Run: .venv/bin/python ml/india/validate_live.py');
} catch (Exception $e) { $error = $e->getMessage(); }
$city = max(0, min(count($places) - 1, (int)request_value($_GET, 'city', 0)));
$farmLat = setting('farm_lat'); $farmLon = setting('farm_lon');
$farm = ($farmLat !== null) ? farm_comparison((float)$farmLat, (float)$farmLon) : array('days_collected' => count(esp32_daily()));

/* ---------- small SVG chart helpers ---------- */
function scale($v, $d0, $d1, $r0, $r1) { return $d1 == $d0 ? ($r0 + $r1) / 2 : $r0 + ($v - $d0) * ($r1 - $r0) / ($d1 - $d0); }
function fmt_r2($v) { return number_format($v, 3); }
/* Line chart: $labels (x), $series = [[name, cssVar, values]], optional reference [label, value]. */
function line_chart($id, $labels, $series, $yMin, $yMax, $yFmt, $ref = null, $W = 640) {
    $H = 280; $L = 48; $R = 120; $T = 16; $B = 36; $n = count($labels);
    $x = function ($i) use ($n, $L, $W, $R) { return round($n > 1 ? $L + $i * ($W - $L - $R) / ($n - 1) : ($W - $R + $L) / 2, 1); };
    $y = function ($v) use ($yMin, $yMax, $T, $H, $B) { return round(scale($v, $yMin, $yMax, $H - $B, $T), 1); };
    ob_start();
    echo '<svg class="vz" id="' . h($id) . '" viewBox="0 0 ' . $W . ' ' . $H . '" role="img">';
    for ($k = 0; $k <= 4; $k++) { $v = $yMin + ($yMax - $yMin) * $k / 4; echo '<line class="grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y($v) . '" y2="' . $y($v) . '"/><text class="tick" x="' . ($L - 8) . '" y="' . ($y($v) + 4) . '" text-anchor="end">' . h($yFmt($v)) . '</text>'; }
    foreach ($labels as $i => $l) if ($n <= 12 || $i % ceil($n / 8) === 0 || $i === $n - 1) echo '<text class="tick" x="' . $x($i) . '" y="' . ($H - 12) . '" text-anchor="middle">' . h($l) . '</text>';
    if ($ref) echo '<line class="ref" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y($ref[1]) . '" y2="' . $y($ref[1]) . '"/><text class="ref-label" x="' . ($L + 6) . '" y="' . ($y($ref[1]) + 14) . '">' . h($ref[0]) . '</text>';
    foreach ($series as $s) {
        $pts = array(); foreach ($s[2] as $i => $v) if ($v !== null) $pts[] = $x($i) . ',' . $y($v);
        echo '<polyline class="line" style="stroke:var(' . $s[1] . ')" points="' . implode(' ', $pts) . '"/>';
        foreach ($s[2] as $i => $v) if ($v !== null) echo '<circle class="dot" cx="' . $x($i) . '" cy="' . $y($v) . '" r="4" style="fill:var(' . $s[1] . ')"/>';
        $li = count($s[2]) - 1; echo '<text class="direct" x="' . ($x($li) + 8) . '" y="' . ($y($s[2][$li]) + 4) . '">' . h($s[0]) . '</text>';
    }
    $step = $n > 1 ? ($W - $L - $R) / ($n - 1) : 40;
    foreach ($labels as $i => $l) {
        $tip = $l; foreach ($series as $s) $tip .= "\n" . $s[0] . ': ' . ($s[2][$i] === null ? '–' : $yFmt($s[2][$i]));
        echo '<rect class="hit" x="' . round($x($i) - $step / 2, 1) . '" y="' . $T . '" width="' . round($step, 1) . '" height="' . ($H - $T - $B) . '" data-tip="' . h($tip) . '" data-x="' . $x($i) . '"/>';
    }
    echo '<line class="crosshair" y1="' . $T . '" y2="' . ($H - $B) . '" x1="0" x2="0"/></svg>';
    return ob_get_clean();
}
page_start('Model Validation', 1, false);
?>
<link rel="stylesheet" href="css/drought.css"><link rel="stylesheet" href="css/validation.css">
<div class="dp vz-root">
<section class="dp-hero"><div><span class="dp-eyebrow">Model validation</span><h2>Seed data vs real-time data</h2>
<p>The PSO-LightGBM model was trained on historical (seed) data up to <?= h($model['data_until'] ?? '') ?>. Here it is scored on newer months it has never seen, and your ESP32 farm readings are compared with the seed data source.</p></div></section>
<?php if ($error): ?><p class="notice" role="alert"><?= h($error) ?></p>
<?php else:
    $e1s = $model['evaluation']['1']; $e1l = $live['horizons']['1'];
    $drop = $e1s['sm_root']['model']['r2'] - $e1l['sm_root']['model']['r2'];
    $verdict = $drop <= 0.03 ? array('ok', 'Accuracy holds on real-time data') : ($drop <= 0.08 ? array('warn', 'Accuracy is slightly lower on real-time data') : array('bad', 'Accuracy drops on real-time data'));
    $beats = $e1l['sm_root']['model']['r2'] > $e1l['sm_root']['persistence']['r2'];
?>
<section class="dp-card vz-verdict vz-<?= $verdict[0] ?>"><div><span class="dp-eyebrow">Result · <?= count($live['live_months']) ?> real-time months (<?= h($live['live_months'][0]) ?> to <?= h(end($live['live_months'])) ?>) · <?= number_format($live['cells']) ?> locations</span>
<h3><?= h($verdict[1]) ?></h3>
<p>One-month-ahead soil moisture R² is <strong><?= fmt_r2($e1l['sm_root']['model']['r2']) ?></strong> on real-time data vs <strong><?= fmt_r2($e1s['sm_root']['model']['r2']) ?></strong> on the seed test years (<?= h($model['split']['test']) ?>)<?= $beats ? ', and it still beats the "next month = this month" baseline (' . fmt_r2($e1l['sm_root']['persistence']['r2']) . ')' : ', but it does not beat the "next month = this month" baseline (' . fmt_r2($e1l['sm_root']['persistence']['r2']) . ')' ?>.</p></div></section>

<section class="dp-kpis">
  <div class="dp-kpi"><small>Soil moisture R² · real-time</small><strong><?= fmt_r2($e1l['sm_root']['model']['r2']) ?></strong><em>seed test <?= fmt_r2($e1s['sm_root']['model']['r2']) ?></em></div>
  <div class="dp-kpi"><small>Anomaly R² · real-time</small><strong><?= fmt_r2($e1l['sm_root']['anomaly_r2']) ?></strong><em>seed test <?= fmt_r2($e1s['sm_root']['anomaly_r2']) ?></em></div>
  <div class="dp-kpi"><small>Mean abs. error · real-time</small><strong><?= number_format($e1l['sm_root']['model']['mae'], 3) ?></strong><em>seed test <?= number_format($e1s['sm_root']['model']['mae'], 3) ?> (soil wetness 0–1)</em></div>
  <div class="dp-kpi"><small>Category within one level</small><strong><?= number_format(100 * $e1l['category_within_one'], 1) ?>%</strong><em>seed test <?= number_format(100 * $e1s['category_within_one'], 1) ?>%</em></div>
</section>

<section class="dp-card vz-block"><div class="dp-card-head"><div><h3>Seed test vs real-time, side by side</h3><p>Same model, same metrics. Seed test = <?= h($model['split']['test']) ?> (held out from training). Real-time = months after the training data.</p></div></div>
<div class="dp-table-wrap vz-full"><table class="dp-table vz-compare"><thead><tr><th>Metric</th><?php foreach ($model['horizons'] as $h): ?><th>+<?= $h ?> mo · seed</th><th>+<?= $h ?> mo · real-time</th><?php endforeach; ?></tr></thead><tbody>
<?php
$rows = array(
    array('Soil moisture R²', function ($e) { return fmt_r2($e['sm_root']['model']['r2']); }),
    array('Baseline R² (next = this month)', function ($e) { return fmt_r2($e['sm_root']['persistence']['r2']); }),
    array('Anomaly R²', function ($e) { return fmt_r2($e['sm_root']['anomaly_r2']); }),
    array('Mean absolute error', function ($e) { return number_format($e['sm_root']['model']['mae'], 4); }),
    array('Drought category exact', function ($e) { return number_format(100 * $e['category_accuracy'], 1) . '%'; }),
    array('Drought category within one', function ($e) { return number_format(100 * $e['category_within_one'], 1) . '%'; }),
    array('Drought months (D1+): precision', function ($e) { return number_format(100 * $e['drought_precision'], 0) . '%'; }),
    array('Drought months (D1+): recall', function ($e) { return number_format(100 * $e['drought_recall'], 0) . '%'; }),
    array('Drought months (D1+): F1 score', function ($e) { return number_format($e['drought_f1'], 2); }),
    array('Baseline F1 (next = this month)', function ($e) { return number_format($e['persistence_drought_f1'], 2); }),
    array('Share of months in drought (actual)', function ($e) { return number_format(100 * $e['drought_share_actual'], 1) . '%'; }),
    array('Samples', function ($e) { return number_format($e['test_samples']); }),
);
foreach ($rows as $r): ?><tr><td><?= h($r[0]) ?></td><?php foreach ($model['horizons'] as $h): ?><td><?= $r[1]($model['evaluation'][(string)$h]) ?></td><td class="live"><?= $r[1]($live['horizons'][(string)$h]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div></section>

<?php
$mLabels = array_column($live['monthly'], 'month');
$mModel = array_column($live['monthly'], 'r2'); $mPers = array_column($live['monthly'], 'persistence_r2');
$yLo = min(0, floor(min(array_merge($mModel, $mPers)) * 10) / 10);
?>
<section class="vz-grid">
<article class="dp-card vz-block"><div class="dp-card-head"><div><h3>Accuracy for each real-time month</h3><p>R² across all <?= number_format($live['cells']) ?> locations, one month ahead.</p></div></div>
<div class="vz-legend"><span><i style="background:var(--series-1)"></i>PSO-LightGBM</span><span><i style="background:var(--series-2)"></i>Baseline (next = this month)</span><span><i class="ref"></i>Seed test R²</span></div>
<?= line_chart('chart-monthly', $mLabels, array(array('Model', '--series-1', $mModel), array('Baseline', '--series-2', $mPers)), $yLo, 1, function ($v) { return number_format($v, 2); }, array('Seed ' . number_format($e1s['sm_root']['model']['r2'], 2), $e1s['sm_root']['model']['r2'])) ?>
</article>

<article class="dp-card vz-block"><div class="dp-card-head"><div><h3>Predicted vs actual (real-time)</h3><p><?= count($live['scatter']) ?> random location-months, one month ahead. Points on the diagonal are perfect.</p></div></div>
<?php $W = 360; $H = 300; $L = 44; $B = 36; $T = 12; $R = 12; ?>
<svg class="vz vz-scatter" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Scatter of predicted versus actual soil moisture">
<?php for ($k = 0; $k <= 4; $k++): $v = $k / 4; $px = scale($v, 0, 1, $L, $W - $R); $py = scale($v, 0, 1, $H - $B, $T); ?>
<line class="grid" x1="<?= $L ?>" x2="<?= $W - $R ?>" y1="<?= $py ?>" y2="<?= $py ?>"/><text class="tick" x="<?= $L - 6 ?>" y="<?= $py + 4 ?>" text-anchor="end"><?= $v ?></text><text class="tick" x="<?= $px ?>" y="<?= $H - 18 ?>" text-anchor="middle"><?= $v ?></text>
<?php endfor; ?>
<line class="ref" x1="<?= $L ?>" y1="<?= $H - $B ?>" x2="<?= $W - $R ?>" y2="<?= $T ?>"/>
<?php foreach ($live['scatter'] as $p): ?><circle class="pt" cx="<?= round(scale($p[0], 0, 1, $L, $W - $R), 1) ?>" cy="<?= round(scale($p[1], 0, 1, $H - $B, $T), 1) ?>" r="3.5" data-tip="<?= h('Actual ' . $p[0] . "\nPredicted " . $p[1]) ?>"/><?php endforeach; ?>
<text class="axis-title" x="<?= ($W + $L) / 2 ?>" y="<?= $H - 2 ?>" text-anchor="middle">Actual soil moisture</text>
<text class="axis-title" transform="translate(11 <?= ($H - $B + $T) / 2 ?>) rotate(-90)" text-anchor="middle">Predicted</text></svg>
</article>
</section>

<?php $cell = india_nearest_cell($places[$city][1], $places[$city][2]); $sp = $live['series']['pred'][$cell['id']]; $sa = $live['series']['actual'][$cell['id']]; ?>
<section class="dp-card vz-block"><div class="dp-card-head"><div><h3>Real-time check for one location</h3><p>Soil moisture predicted one month ahead vs what NASA later measured.</p></div>
<form method="get" class="vz-inline"><select name="city" onchange="this.form.submit()"><?php foreach ($places as $i => $p): ?><option value="<?= $i ?>" <?= $i === $city ? 'selected' : '' ?>><?= h($p[0]) ?></option><?php endforeach; ?></select><noscript><button class="dp-link">Show</button></noscript></form></div>
<div class="vz-legend"><span><i style="background:var(--series-1)"></i>Predicted</span><span><i style="background:var(--series-2)"></i>Actual</span></div>
<?php $lo = floor(min(array_merge($sp, $sa)) * 10) / 10; $hi = min(1, ceil(max(array_merge($sp, $sa)) * 10) / 10); if ($hi - $lo < .2) $hi = min(1, $lo + .2); ?>
<?= line_chart('chart-city', $mLabels, array(array('Predicted', '--series-1', $sp), array('Actual', '--series-2', $sa)), $lo, $hi, function ($v) { return number_format($v, 2); }, null, 1100) ?>
<p class="dp-note">Grid point <?= number_format($cell['lat'], 2) ?>°N <?= number_format($cell['lon'], 2) ?>°E (<?= h($cell['district'] . ', ' . $cell['state']) ?>). Average error here: <?php $err = 0; foreach ($sp as $i => $v) $err += abs($v - $sa[$i]); echo number_format($err / count($sp), 3); ?>.</p>
</section>

<section class="dp-card vz-block" id="farm"><div class="dp-card-head"><div><h3>Your farm: ESP32 sensors vs seed data</h3><p>Daily ESP32 averages compared with NASA POWER daily values (the model's data source) at the farm location.</p></div></div>
<form method="post" class="vz-farm-form"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<label>Farm latitude<input name="farm_lat" type="number" step="0.0001" value="<?= h($farmLat ?? '') ?>" required></label>
<label>Farm longitude<input name="farm_lon" type="number" step="0.0001" value="<?= h($farmLon ?? '') ?>" required></label>
<button class="dp-primary">Save farm location</button><?php if ($notice): ?><span class="vz-err"><?= h($notice) ?></span><?php endif; ?></form>
<?php if ($farmLat === null): ?>
<p class="dp-status warn">Set the farm location above to compare the ESP32 readings with NASA data for the same place.</p>
<?php elseif (!$farm['days_collected']): ?>
<p class="dp-status warn">No ESP32 readings yet. Once the sensor sends data, each day is compared here automatically. About 14 days give a first check; 30+ days give reliable figures.</p>
<?php elseif (!empty($farm['error'])): ?>
<p class="dp-status warn"><?= h($farm['error']) ?></p>
<?php elseif (!$farm['matched']): ?>
<p class="dp-status"><?= $farm['days_collected'] ?> day(s) of ESP32 data (<?= h($farm['first']) ?> to <?= h($farm['last']) ?>). NASA publishes daily data 2–4 days late<?= $farm['nasa_last'] ? ' (latest ' . h($farm['nasa_last']) . ')' : '' ?>, so no day can be compared yet.</p>
<?php else: $m = count($farm['matched']); ?>
<p class="dp-status ok"><?= $m ?> matched day(s) between <?= h($farm['matched'][0]['date']) ?> and <?= h(end($farm['matched'])['date']) ?>.<?= $m < 14 ? ' Fewer than 14 days: treat these figures as preliminary.' : '' ?></p>
<div class="dp-table-wrap"><table class="dp-table"><thead><tr><th>Measurement</th><th>ESP32 vs NASA</th><th>Correlation (r)</th><th>Average difference</th><th>Mean abs. difference</th><th>What it means</th></tr></thead><tbody>
<?php
$describe = function ($s, $unit, $good) {
    if (!$s || $s['r'] === null) return array('–', '–', '–', 'Needs more varied days.');
    if ($s['r'] < 0.6) $txt = 'Day-to-day changes do not match well yet: collect more days, check sensor placement';
    else $txt = abs($s['bias']) <= $good ? 'Close agreement' : 'Tracks well with a steady offset: calibrate the sensor';
    return array(number_format($s['r'], 2), sprintf('%+.1f %s', $s['bias'], $unit), number_format($s['mae'], 1) . ' ' . $unit, $txt);
};
foreach (array(array('Temperature', 'temperature', '°C', 2, 'DHT11 vs T2M (2 m air)'), array('Humidity', 'humidity', '%', 8, 'DHT11 vs RH2M')) as $row):
    $d = $describe($farm[$row[1]], $row[2], $row[3]); ?>
<tr><td><?= h($row[0]) ?></td><td><?= h($row[4]) ?></td><td><?= $d[0] ?></td><td><?= $d[1] ?></td><td><?= $d[2] ?></td><td><?= h($d[3]) ?></td></tr>
<?php endforeach; $s = $farm['soil']; ?>
<tr><td>Soil moisture</td><td>Sensor % vs GWETTOP × 100</td><td><?= $s && $s['r'] !== null ? number_format($s['r'], 2) : '–' ?></td><td colspan="2">Different units: only the trend is compared</td><td><?= !$s || $s['r'] === null ? 'Needs more varied days.' : ($s['r'] < -0.3 ? 'Moves opposite to NASA: sensor reading is probably inverted (dry = high)' : ($s['r'] > 0.5 ? 'Tracks NASA well' : 'Weak link: point sensor vs 55 km grid')) ?></td></tr>
<tr><td>Rain</td><td>Rain day (sensor &gt; <?= RAIN_WET_PERCENT ?>%) vs NASA ≥ 1 mm</td><td>–</td><td colspan="2"><?= $farm['rain'] ? number_format(100 * $farm['rain']['agreement'], 0) . '% of days agree' : '–' ?></td><td><?= $farm['rain'] ? $farm['rain']['esp'] . ' sensor rain days, ' . $farm['rain']['nasa'] . ' NASA rain days, ' . $farm['rain']['both'] . ' both' : '' ?></td></tr>
</tbody></table></div>
<?php if ($m >= 2):
    $dl = array_map(function ($x) { return substr($x['date'], 5); }, $farm['matched']);
    $te = array_map(function ($x) { return round($x['esp']['temperature'], 2); }, $farm['matched']); $tn = array_map(function ($x) { return $x['nasa']['t2m']; }, $farm['matched']);
    $lo = floor(min(array_merge($te, $tn))) - 1; $hi = ceil(max(array_merge($te, $tn))) + 1; ?>
<h4 class="vz-sub">Daily temperature</h4><div class="vz-legend"><span><i style="background:var(--series-1)"></i>ESP32</span><span><i style="background:var(--series-2)"></i>NASA POWER</span></div>
<?= line_chart('chart-farm-temp', $dl, array(array('ESP32', '--series-1', $te), array('NASA', '--series-2', $tn)), $lo, $hi, function ($v) { return number_format($v, 1) . '°'; }, null, 1100) ?>
<?php endif; endif; ?>
<p class="dp-note">Why they can differ: the ESP32 measures one spot at ground level; NASA POWER is a satellite and weather-model estimate for a ~55 km grid cell. A steady offset is normal and can be corrected by calibration. What matters is that the day-to-day ups and downs agree (correlation close to 1).</p>
</section>

<details class="dp-card vz-block method"><summary>How this validation works</summary>
<p><strong>Seed data:</strong> NASA POWER monthly values, <?= h($model['first_year']) ?>–<?= h($model['data_until']) ?>. The model was trained up to 2014, tuned by PSO on 2015–2018 and tested on <?= h($model['split']['test']) ?>.</p>
<p><strong>Real-time data:</strong> NASA POWER daily values published after training (<?= h($live['live_months'][0]) ?> onward), averaged into months exactly like the seed data. The model was not retrained. Forecasts use only data available at the time.</p>
<p><strong>Anomaly R²</strong> scores the departure from the normal for that month, the part that matters for drought. <strong>Baseline</strong> assumes next month equals this month. Update the real-time check each month with <code>.venv/bin/python ml/india/validate_live.py</code> (last run <?= h(substr($live['generated_at'], 0, 10)) ?>).</p></details>
<?php endif; ?>
</div>
<div class="vz-tip" id="vz-tip" role="tooltip" hidden></div>
<script src="js/validation.js"></script>
<?php page_end(); ?>
