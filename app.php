<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/layout.php';
$area = valid_area(request_value($_GET, 'area', 1));
try { $reading = latest_reading($area); $error = null; } catch (Exception $e) { $reading = null; $error = $e->getMessage(); }
page_start('Live Dashboard', $area);
?>
<h2>Area <?= $area ?> — Live Sensor Data</h2>
<?php if ($error): ?><p class="notice">The data folder is not writable yet. Set its permission to 755 in WinSCP.</p>
<?php elseif (!$reading): ?><p class="notice">Area <?= $area ?> has no sensor reading yet. Turn on the ESP32 and send the first reading.</p>
<?php else:
    $soilPercent = sensor_percent($reading['soil_value']);
    $rainPercent = sensor_percent($reading['rain_value']);
    $soilStatus = $soilPercent > 70 ? 'Soil is wet' : ($soilPercent > 40 ? 'Soil is moist' : 'Soil is dry');
    $rainStatus = $rainPercent > 70 ? 'Heavy rain' : ($rainPercent > 30 ? 'Light rain' : 'No rain');
    $isRain = $rainPercent > RAIN_WET_PERCENT;
?>
<p class="status">Last updated: <?= h($reading['recorded_at']) ?> &bull; Auto refresh: 5 seconds</p>
<div class="grid sensor-grid"><div class="card temperature-card"><div class="sensor-icon">🌡️</div><div class="label">Temperature</div><div class="metric"><?= h($reading['temperature']) ?> °C</div></div><div class="card humidity-card"><div class="sensor-icon">💧</div><div class="label">Humidity</div><div class="metric"><?= h($reading['humidity']) ?> %</div></div><div class="card soil-card"><div class="sensor-icon">🌱</div><div class="label">Soil Moisture</div><div class="metric"><?= h($soilPercent) ?> %</div><div class="progress"><span style="width:<?= h($soilPercent) ?>%"></span></div><span class="status"><?= h($soilStatus) ?></span></div><div class="card rain-card"><div class="sensor-icon">🌧️</div><div class="label">Rain Sensor</div><div class="metric"><?= h($rainPercent) ?> %</div><div class="progress"><span style="width:<?= h($rainPercent) ?>%"></span></div><span class="status"><?= h($rainStatus) ?></span></div></div>
<p><a class="button" href="history.php?area=<?= $area ?>">Save this reading for a date / view history</a></p>
<?php endif; ?>
<script>window.setTimeout(function () { window.location.reload(); }, 5000);</script>
<?php page_end(); ?>
