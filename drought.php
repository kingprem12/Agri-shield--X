<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/satellite_engine.php';
require_once __DIR__ . '/layout.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$error = '';
try {
    $model = sat_model(); $meta = sat_meta(); $last = sat_label($meta['months'] - 1); $ev = $model['evaluation'];
} catch (Exception $e) { $error = $e->getMessage(); }
try { $sensor = latest_reading(1); } catch (Exception $e) { $sensor = null; }
$places = array(
    array('Hyderabad, Sindh', 25.40, 68.37), array('Karachi, Sindh', 24.86, 67.01), array('Sukkur, Sindh', 27.70, 68.86),
    array('Larkana, Sindh', 27.56, 68.21), array('Nawabshah, Sindh', 26.24, 68.41), array('Mirpur Khas, Sindh', 25.53, 69.01),
    array('Mithi, Tharparkar', 24.74, 69.80), array('Umerkot, Sindh', 25.36, 69.74), array('Badin, Sindh', 24.66, 68.84),
    array('Thatta, Sindh', 24.75, 67.92), array('Dadu, Sindh', 26.73, 67.78), array('Jacobabad, Sindh', 28.28, 68.44));
page_start('Drought Predictor', 1, false);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="css/drought.css">
<?php if ($error): ?><p class="notice" role="alert"><?= h($error) ?></p><?php page_end(); exit; endif; ?>
<div class="dp">
  <section class="dp-hero">
    <div><span class="dp-eyebrow">Drought Prediction Studio</span><h2>Predict drought severity for any location</h2>
      <p>PSO-LightGBM forecasts vegetation health (NDVI) and land surface temperature 1–3 months ahead from each location's satellite history, then converts them to a Vegetation Health Index drought class.</p></div>
    <div class="dp-model-chip"><span class="dot"></span>PSO-LightGBM · NDVI R² <?= number_format($ev['1']['ndvi']['model']['r2'], 3) ?> · <?= number_format($ev['1']['test_samples']) ?> test samples</div>
  </section>

  <section class="dp-kpis" aria-label="Prediction statistics">
    <div class="dp-kpi"><small>Predictions</small><strong id="kpi-count">0</strong></div>
    <div class="dp-kpi"><small>High risk alerts</small><strong id="kpi-high">0</strong><em>severe or extreme</em></div>
    <div class="dp-kpi"><small>Average risk</small><strong id="kpi-avg">–</strong><em>0 = healthy, 100 = extreme</em></div>
    <div class="dp-kpi"><small>Latest severity</small><strong id="kpi-latest">–</strong></div>
  </section>
  <div class="dp-banner" id="latest-banner" hidden><span>Latest dashboard update</span><strong id="latest-text"></strong></div>

  <section class="dp-main">
    <article class="dp-card dp-map-card">
      <div class="dp-card-head"><div><h3>Interactive risk map</h3><p>Click inside the shaded Sindh grid to choose a location.</p></div>
        <label class="dp-toggle"><input type="checkbox" id="coverage-toggle" checked> Actual VHI layer <span id="coverage-month"></span></label></div>
      <div id="map" role="application" aria-label="Drought risk map"></div>
      <div class="dp-legend"><span class="d-extreme">Extreme</span><span class="d-severe">Severe</span><span class="d-moderate">Moderate</span><span class="d-mild">Mild</span><span class="d-no">No drought</span><span class="dp-legend-note">● your predictions</span></div>
    </article>

    <article class="dp-card dp-form-card">
      <div class="dp-card-head"><div><h3>Prediction form</h3><p>Values auto-fill from the satellite record. Edit them to run a what-if scenario.</p></div></div>
      <form id="predict-form" novalidate>
        <label class="dp-full">Select region<select id="region"><?php foreach ($places as $i => $p): ?><option value="<?= $i ?>"><?= h($p[0]) ?></option><?php endforeach; ?><option value="custom">Custom location (click map)</option></select></label>
        <label>Latitude<input id="lat" name="lat" type="number" step="0.0001" required></label>
        <label>Longitude<input id="lon" name="lon" type="number" step="0.0001" required></label>
        <label class="dp-full">Forecast from month<input id="month" name="month" type="month" min="<?= h(sat_label(11)) ?>" max="<?= h($last) ?>" value="<?= h($last) ?>" required></label>
        <label>NDVI <span class="hint">-1 to 1</span><input id="ndvi" name="ndvi" type="number" step="0.0001" min="-1" max="1" required></label>
        <label>LST °C <span class="hint">land surface temp</span><input id="lst" name="lst" type="number" step="0.01" min="-30" max="80" required></label>
        <label class="dp-full">Rainfall this month (mm)<input id="precip" name="precip" type="number" step="0.01" min="0" max="2000" required></label>
        <p class="dp-status dp-full" id="lookup-status" aria-live="polite">Loading satellite values…</p>
        <div class="dp-actions dp-full"><button type="button" class="dp-link" id="reset-values">Reset to satellite values</button><button type="submit" class="dp-primary" id="predict-btn">Predict drought severity</button></div>
      </form>
    </article>
  </section>

  <section class="dp-card dp-result" id="result" hidden aria-live="polite">
    <div class="dp-result-grid">
      <div class="dp-gauge"><svg viewBox="0 0 200 120" aria-hidden="true"><path d="M20 110 A80 80 0 0 1 180 110" class="track"/><path d="M20 110 A80 80 0 0 1 180 110" class="value" id="gauge-arc"/></svg>
        <div class="dp-gauge-text"><strong id="res-risk">–</strong><span>risk / 100</span></div><div class="dp-severity" id="res-severity">–</div></div>
      <div class="dp-result-body"><span class="dp-eyebrow" id="res-where"></span><h3 id="res-title"></h3><p id="res-advice"></p>
        <div class="dp-outlook" id="res-outlook"></div><p class="dp-note" id="res-note"></p></div>
    </div>
  </section>

  <section class="dp-lower">
    <article class="dp-card"><div class="dp-card-head"><div><h3>Prediction history</h3><p>Saved on this computer only.</p></div><div class="dp-head-actions"><button class="dp-link" id="export-csv" type="button">Export CSV</button><button class="dp-link danger" id="clear-history" type="button">Clear</button></div></div>
      <div class="dp-table-wrap"><table class="dp-table"><thead><tr><th>Time</th><th>Location</th><th>From</th><th>Target</th><th>VHI</th><th>Risk</th><th>Severity</th></tr></thead><tbody id="history-body"><tr><td colspan="7" class="empty">No predictions yet.</td></tr></tbody></table></div></article>
    <aside class="dp-side">
      <article class="dp-card dp-sensor"><div class="dp-card-head"><div><h3>Live field sensors</h3><p>ESP32 · Area 1 · for context, not a model input</p></div></div>
        <?php if ($sensor): ?><div class="dp-sensor-grid"><div><small>Temperature</small><strong><?= h($sensor['temperature']) ?> °C</strong></div><div><small>Humidity</small><strong><?= h($sensor['humidity']) ?> %</strong></div><div><small>Soil moisture</small><strong><?= h(sensor_percent($sensor['soil_value'])) ?> %</strong></div><div><small>Rain sensor</small><strong><?= h(sensor_percent($sensor['rain_value'])) ?> %</strong></div></div><p class="dp-note">Updated <?= h($sensor['recorded_at']) ?></p>
        <?php else: ?><p class="dp-note">No sensor reading yet.</p><?php endif; ?></article>
      <article class="dp-card dp-model"><div class="dp-card-head"><div><h3>Model accuracy</h3><p>Held-out test years <?= h($model['split']['test']) ?></p></div></div>
        <table class="dp-table compact"><thead><tr><th>Ahead</th><th>NDVI R²</th><th>LST R²</th><th>Drought acc.</th></tr></thead><tbody>
        <?php foreach ($model['horizons'] as $hh): $e = $ev[(string)$hh]; ?><tr><td>+<?= $hh ?> mo</td><td><?= number_format($e['ndvi']['model']['r2'], 3) ?></td><td><?= number_format($e['lst']['model']['r2'], 3) ?></td><td><?= number_format(100 * $e['vhi']['drought_accuracy'], 1) ?>%</td></tr><?php endforeach; ?>
        </tbody></table><p class="dp-note">Trained on <?= h($meta['region']) ?> satellite data, <?= h($model['first_year']) ?>–<?= h(substr($model['data_until'], 0, 4)) ?>. Predictions are only available inside that grid. <a href="satellite.php">Full model report →</a></p></article>
    </aside>
  </section>
</div>
<script>window.DP = <?= json_encode(array('csrf' => $_SESSION['csrf'], 'places' => $places, 'lastMonth' => $last,
    'classes' => $model['drought_classes']), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="js/drought.js"></script>
<?php page_end(); ?>
