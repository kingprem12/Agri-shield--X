<?php
// CLI only: checks PHP features + tree evaluation reproduce native LightGBM for the India model.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../india_engine.php';
$model = india_model(); $t = india_meta()['months'] - 1; $worst = 0;
foreach ($model['parity']['cells'] as $i => $id) {
    $php = sat_predict($model['models']['sm_h1'], india_features(india_cell($id), india_series($id, $t - 11, $t), india_clim($id), $t, 1));
    $worst = max($worst, abs($php - $model['parity']['pred'][$i]));
}
echo 'Compared ' . count($model['parity']['cells']) . " predictions, max |PHP - LightGBM| = $worst\n";
if ($worst > 1e-9) { echo "FAIL: PHP and Python disagree\n"; exit(1); }
echo "PASS: PHP inference matches native LightGBM\n";
