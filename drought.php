<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/india_engine.php';
require_once __DIR__ . '/layout.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$error = '';
try { $model = india_model(); $meta = india_meta(); $last = india_label($meta['months'] - 1); $ev = $model['evaluation']; }
catch (Exception $e) { $error = $e->getMessage(); }
try { $sensor = latest_reading(1); } catch (Exception $e) { $sensor = null; }
$places = array(
    array('Ranchi, Jharkhand', 23.34, 85.31), array('New Delhi, Delhi', 28.61, 77.21), array('Mumbai, Maharashtra', 19.08, 72.88),
    array('Pune, Maharashtra', 18.52, 73.86), array('Nagpur, Maharashtra', 21.15, 79.09), array('Aurangabad, Maharashtra', 19.88, 75.34),
    array('Chennai, Tamil Nadu', 13.08, 80.27), array('Madurai, Tamil Nadu', 9.93, 78.12), array('Coimbatore, Tamil Nadu', 11.02, 76.96),
    array('Bengaluru, Karnataka', 12.97, 77.59), array('Kalaburagi, Karnataka', 17.33, 76.83), array('Hyderabad, Telangana', 17.39, 78.49),
    array('Anantapur, Andhra Pradesh', 14.68, 77.60), array('Kolkata, West Bengal', 22.57, 88.36), array('Bhubaneswar, Odisha', 20.30, 85.82),
    array('Bhawanipatna, Odisha', 19.91, 83.17), array('Patna, Bihar', 25.59, 85.14), array('Gaya, Bihar', 24.80, 85.00),
    array('Lucknow, Uttar Pradesh', 26.85, 80.95), array('Jhansi, Uttar Pradesh', 25.45, 78.57), array('Bhopal, Madhya Pradesh', 23.26, 77.41),
    array('Indore, Madhya Pradesh', 22.72, 75.86), array('Raipur, Chhattisgarh', 21.25, 81.63), array('Jaipur, Rajasthan', 26.91, 75.79),
    array('Jodhpur, Rajasthan', 26.24, 73.02), array('Bikaner, Rajasthan', 28.02, 73.31), array('Ahmedabad, Gujarat', 23.02, 72.57),
    array('Bhuj, Gujarat', 23.25, 69.67), array('Chandigarh', 30.73, 76.78), array('Ludhiana, Punjab', 30.90, 75.85),
    array('Dehradun, Uttarakhand', 30.32, 78.03), array('Shimla, Himachal Pradesh', 31.10, 77.17), array('Srinagar, Jammu and Kashmir', 34.08, 74.80),
    array('Guwahati, Assam', 26.14, 91.74), array('Thiruvananthapuram, Kerala', 8.52, 76.94), array('Panaji, Goa', 15.49, 73.83));
page_start('Drought Predictor', 1, false);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="css/drought.css">
<?php if ($error): ?><p class="notice" role="alert"><?= h($error) ?></p><?php page_end(); exit; endif; ?>
<div class="dp">
  <section class="dp-hero">
    <div><span class="dp-eyebrow">India Drought Prediction Studio</span><h2>Predict agricultural drought for any district in India</h2>
      <p>PSO-LightGBM forecasts root-zone soil moisture 1–3 months ahead from each location's climate history, then ranks it against the 1981–2010 normal to give a drought category (US Drought Monitor scale D0–D4).</p></div>
    <div class="dp-model-chip"><span class="dot"></span>PSO-LightGBM · soil moisture R² <?= number_format($ev['1']['sm_root']['model']['r2'], 3) ?> · <?= number_format(count(india_db()->query('SELECT id FROM cells')->fetchAll())) ?> grid points</div>
  </section>

  <section class="dp-kpis" aria-label="Prediction statistics">
    <div class="dp-kpi"><small>Predictions</small><strong id="kpi-count">0</strong></div>
    <div class="dp-kpi"><small>High risk alerts</small><strong id="kpi-high">0</strong><em>severe, extreme or exceptional</em></div>
    <div class="dp-kpi"><small>Average risk</small><strong id="kpi-avg">–</strong><em>0 = wettest on record, 100 = driest</em></div>
    <div class="dp-kpi"><small>Latest severity</small><strong id="kpi-latest">–</strong></div>
  </section>
  <div class="dp-banner" id="latest-banner" hidden><span>Latest dashboard update</span><strong id="latest-text"></strong></div>

  <section class="dp-main">
    <article class="dp-card dp-map-card">
      <div class="dp-card-head"><div><h3>Interactive India risk map</h3><p>Hover a district to see its drought level. Click to select it.</p></div>
        <label class="dp-toggle">Show<select id="map-mode"><option value="0">Actual drought</option><option value="1">Forecast +1 month</option></select></label></div>
      <div id="map" role="application" aria-label="India drought map"><p class="dp-map-loading">Loading India map…</p></div>
      <div class="dp-legend"><span class="d-exceptional">D4 Exceptional</span><span class="d-extreme">D3 Extreme</span><span class="d-severe">D2 Severe</span><span class="d-moderate">D1 Moderate</span><span class="d-dry">D0 Abnormally dry</span><span class="d-none">No drought</span><span class="dp-legend-note" id="map-caption"></span></div>
    </article>

    <article class="dp-card dp-form-card">
      <div class="dp-card-head"><div><h3>Prediction form</h3><p>Values auto-fill from the NASA POWER record. Edit them to run a what-if scenario.</p></div></div>
      <form id="predict-form" novalidate>
        <label class="dp-full">Select region<select id="region"><?php foreach ($places as $i => $p): ?><option value="<?= $i ?>"><?= h($p[0]) ?></option><?php endforeach; ?><option value="custom">Custom location (click map)</option></select></label>
        <label>State<input id="state" type="text" readonly tabindex="-1"></label>
        <label>District<input id="district" type="text" readonly tabindex="-1"></label>
        <label>Latitude<input id="lat" type="number" step="0.0001" required></label>
        <label>Longitude<input id="lon" type="number" step="0.0001" required></label>
        <label class="dp-full">Forecast from month<input id="month" type="month" min="<?= h(india_label(11)) ?>" max="<?= h($last) ?>" value="<?= h($last) ?>" required></label>
        <label>Temperature °C<input id="t2m" type="number" step="0.01" min="-40" max="60" required></label>
        <label>Humidity %<input id="rh" type="number" step="0.01" min="0" max="100" required></label>
        <label>Rainfall (mm, month)<input id="precip" type="number" step="0.01" min="0" max="3000" required></label>
        <label>Wind speed (m/s)<input id="wind" type="number" step="0.01" min="0" max="40" required></label>
        <label>Soil moisture, root zone <span class="hint">0–1</span><input id="sm_root" type="number" step="0.0001" min="0" max="1" required></label>
        <label>Soil moisture, surface <span class="hint">0–1</span><input id="sm_top" type="number" step="0.0001" min="0" max="1" required></label>
        <p class="dp-status dp-full" id="lookup-status" aria-live="polite">Loading climate values…</p>
        <div class="dp-actions dp-full"><button type="button" class="dp-link" id="reset-values">Reset to recorded values</button><button type="submit" class="dp-primary" id="predict-btn">Predict drought severity</button></div>
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
      <div class="dp-table-wrap"><table class="dp-table"><thead><tr><th>Time</th><th>Location</th><th>From</th><th>Target</th><th>Percentile</th><th>Risk</th><th>Severity</th></tr></thead><tbody id="history-body"><tr><td colspan="7" class="empty">No predictions yet.</td></tr></tbody></table></div></article>
    <aside class="dp-side">
      <article class="dp-card dp-sensor"><div class="dp-card-head"><div><h3>Live field sensors</h3><p>ESP32 · Area 1 · your farm, for context</p></div></div>
        <?php if ($sensor): ?><div class="dp-sensor-grid"><div><small>Temperature</small><strong><?= h($sensor['temperature']) ?> °C</strong></div><div><small>Humidity</small><strong><?= h($sensor['humidity']) ?> %</strong></div><div><small>Soil moisture</small><strong><?= h(sensor_percent($sensor['soil_value'])) ?> %</strong></div><div><small>Rain sensor</small><strong><?= h(sensor_percent($sensor['rain_value'])) ?> %</strong></div></div><p class="dp-note">Updated <?= h($sensor['recorded_at']) ?></p>
        <?php else: ?><p class="dp-note">No sensor reading yet.</p><?php endif; ?></article>
      <article class="dp-card dp-model"><div class="dp-card-head"><div><h3>Model accuracy</h3><p>Held-out test years <?= h($model['split']['test']) ?></p></div></div>
        <table class="dp-table compact"><thead><tr><th>Ahead</th><th>Soil moisture R²</th><th>Persistence R²</th><th>Anomaly R²</th></tr></thead><tbody>
        <?php foreach ($model['horizons'] as $hh): $e = $ev[(string)$hh]; ?><tr><td>+<?= $hh ?> mo</td><td><strong><?= number_format($e['sm_root']['model']['r2'], 3) ?></strong></td><td><?= number_format($e['sm_root']['persistence']['r2'], 3) ?></td><td><?= number_format($e['sm_root']['anomaly_r2'], 3) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <p class="dp-note"><strong>Anomaly R²</strong> scores only the departure from the seasonal normal, the part that matters for drought. <strong>Drought months (D1+)</strong> at +1 month: <?= round(100 * $ev['1']['drought_precision']) ?>% of flagged months were real droughts and <?= round(100 * $ev['1']['drought_recall']) ?>% of real droughts were caught. Droughts were rare in the test years (<?= round(100 * $ev['1']['drought_share_actual'], 1) ?>% of months), and detection is about as good as assuming next month repeats this one.</p>
        <p class="dp-note">Trained on NASA POWER monthly climate data for India (MERRA-2, 0.5° grid), <?= h($model['first_year']) ?>–<?= h(substr($model['data_until'], 0, 4)) ?>. Categories compare soil moisture with the 1981–2010 normal for that place and month.</p></article>
    </aside>
  </section>
</div>
<script>window.DP = <?= json_encode(array('csrf' => $_SESSION['csrf'], 'places' => $places, 'lastMonth' => $last, 'classes' => $model['classes']), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="js/drought.js"></script>
<?php page_end(); ?>
