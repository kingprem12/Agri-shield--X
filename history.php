<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/layout.php';
$area = valid_area(request_value($_REQUEST, 'area', 1));
$date = request_value($_REQUEST, 'date', date('Y-m-d'));
$dateOk = DateTime::createFromFormat('Y-m-d', $date) && DateTime::createFromFormat('Y-m-d', $date)->format('Y-m-d') === $date;
if (!$dateOk) $date = date('Y-m-d');
$message = ''; $error = '';
try {
    $readings = array_slice(readings_for_date($area, $date), 0, 200);
    if (!$readings) {
        $flatReading = flat_area_reading($area);
        if ($flatReading) {
            $readings[] = $flatReading;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_snapshot'])) {
        $selectedId = (string)request_value($_POST, 'reading_id', '');
        if ($selectedId === '') throw new Exception('Please select one sensor reading before saving.');

        $selectedReading = null;
        foreach ($readings as $candidate) {
            if (isset($candidate['id']) && secure_equals((string)$candidate['id'], $selectedId)) {
                $selectedReading = $candidate;
                break;
            }
        }
        if (!$selectedReading) throw new Exception('The selected sensor reading is not available. Please reload and select again.');

        save_snapshot($area, $date, $selectedReading);
        $message = 'Only the selected sensor reading was saved for ' . $date . '.';
    }
    $snapshot = snapshot_for_date($area, $date);
} catch (Exception $e) {
    $error = $e->getMessage();
    if (!isset($readings)) $readings = array();
    try { $snapshot = snapshot_for_date($area, $date); } catch (Exception $ignored) { $snapshot = null; }
}
page_start('History & Save', $area);
?>
<h2>Area <?= $area ?> — Date-wise History</h2>
<form class="filters" method="get"><input type="hidden" name="area" value="<?= $area ?>"><label>Date<input type="date" name="date" value="<?= h($date) ?>" max="<?= date('Y-m-d') ?>"></label><button class="button">View data</button></form>
<?php if ($message): ?><p class="ok"><?= h($message) ?></p><?php endif; ?><?php if ($error): ?><p class="notice"><?= h($error) ?></p><?php endif; ?>
<?php if ($snapshot): ?><p class="ok"><strong>Selected data saved:</strong> <?= h($snapshot['temperature']) ?> °C, <?= h($snapshot['humidity']) ?> %, soil <?= h(sensor_percent($snapshot['soil_value'])) ?>%, rain <?= h(sensor_percent($snapshot['rain_value'])) ?>% — <?= h($snapshot['recorded_at']) ?>.</p><?php endif; ?>
<h3>Select one sensor reading on <?= h($date) ?></h3>
<form class="reading-selection" method="post">
<input type="hidden" name="area" value="<?= $area ?>"><input type="hidden" name="date" value="<?= h($date) ?>">
<table><tr><th>Select</th><th>Recorded Time</th><th>Temp &deg;C</th><th>Humidity %</th><th>Soil %</th><th>Rain %</th></tr><?php foreach ($readings as $index => $row): ?><tr class="selectable-row"><td><input type="radio" name="reading_id" value="<?= h($row['id']) ?>" <?= $index === 0 ? 'checked' : '' ?> required aria-label="Select reading at <?= h($row['recorded_at']) ?>"></td><td><?= h($row['recorded_at']) ?></td><td><?= h($row['temperature']) ?></td><td><?= h($row['humidity']) ?></td><td><?= h(sensor_percent($row['soil_value'])) ?>%</td><td><?= h(sensor_percent($row['rain_value'])) ?>%</td></tr><?php endforeach; ?><?php if (!$readings): ?><tr><td colspan="6">No sensor readings are available.</td></tr><?php endif; ?></table>
<?php if ($readings): ?><div class="selection-actions"><div><strong>Select one row and save</strong><br><span class="label">Only the selected reading will be kept as the snapshot for this area and date.</span></div><button class="button" name="save_snapshot" value="1">Save selected reading</button></div><?php endif; ?>
</form>
<script>
document.querySelectorAll('.selectable-row').forEach(function (row) {
    row.addEventListener('click', function (event) {
        if (event.target.tagName !== 'INPUT') row.querySelector('input[type="radio"]').checked = true;
    });
});
</script>
<?php page_end(); ?>
