<?php
// CLI only: checks PHP features + tree evaluation reproduce native LightGBM predictions.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../satellite_engine.php';
$model = sat_model(); $t = sat_meta()['months'] - 1; $worst = 0; $n = 0;
foreach (array('ndvi', 'lst') as $target) {
    foreach ($model['parity'][$target]['cells'] as $i => $cellId) {
        $cell = sat_cell($cellId); $series = sat_series($cellId, $t - 11, $t);
        $php = sat_predict($model['models'][$target . '_h1'], sat_features($cell, $series, sat_clim($cellId), $t, 1));
        $diff = abs($php - $model['parity'][$target]['pred'][$i]); $worst = max($worst, $diff); $n++;
    }
}
echo "Compared $n predictions, max |PHP - LightGBM| = $worst\n";
if ($worst > 1e-9) { echo "FAIL: PHP and Python disagree\n"; exit(1); }
echo "PASS: PHP inference matches native LightGBM\n";
