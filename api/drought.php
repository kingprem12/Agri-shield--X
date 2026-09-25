<?php
/*
 * JSON API for the India Drought Predictor page (drought.php). Session login required.
 *   GET  ?action=cells                                grid cells [id, lat, lon, state, district]
 *   GET  ?action=lookup&lat=..&lon=..&month=YYYY-MM   nearest grid cell + observed NASA POWER values
 *   GET  ?action=coverage&month=YYYY-MM&h=0|1          drought percentile per cell (h=0 actual, h=1 forecast)
 *   GET  ?action=history                              prediction log + dashboard stats
 *   POST ?action=predict  {lat, lon, month, place, t2m, rh, precip, wind, sm_root, sm_top}
 *   POST ?action=clear
 * POST requests need the X-CSRF-Token header from the page.
 */
require_once __DIR__ . '/../india_engine.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Kolkata');
session_start();

const COVERAGE_KM = 60; // grid spacing is 0.5 deg (~55 km)
// Form inputs: [min, max, rounding tolerance of the form field]
const INPUTS = array('t2m' => array(-40, 60, 5e-3), 'rh' => array(0, 100, 5e-3), 'precip' => array(0, 3000, 5e-3),
    'wind' => array(0, 40, 5e-3), 'sm_root' => array(0, 1, 5e-5), 'sm_top' => array(0, 1, 5e-5));

function reply($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function num($src, $key, $min, $max) {
    if (!isset($src[$key]) || !is_numeric($src[$key])) throw new InvalidArgumentException("Missing or invalid $key");
    $v = (float)$src[$key];
    if ($v < $min || $v > $max) throw new InvalidArgumentException("$key must be between $min and $max");
    return $v;
}
function month_index($src) {
    $t = india_index((string)request_value($src, 'month', ''));
    if ($t === null || $t < 11) throw new InvalidArgumentException('month must be between ' . india_label(11) . ' and ' . india_label(india_meta()['months'] - 1));
    return $t;
}
function km($lat1, $lon1, $lat2, $lon2) {
    $r = M_PI / 180; $a = sin(($lat2 - $lat1) * $r / 2) ** 2 + cos($lat1 * $r) * cos($lat2 * $r) * sin(($lon2 - $lon1) * $r / 2) ** 2;
    return 6371 * 2 * asin(min(1, sqrt($a)));
}
function locate($lat, $lon) {
    $cell = india_nearest_cell($lat, $lon); $d = km($lat, $lon, $cell['lat'], $cell['lon']);
    return array('cell' => $cell, 'distance_km' => round($d, 1), 'inside' => $d <= COVERAGE_KM);
}
function log_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . __DIR__ . '/../data/app.db', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('CREATE TABLE IF NOT EXISTS india_predictions (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, user TEXT, place TEXT,
        state TEXT, district TEXT, lat REAL, lon REAL, cell_id INTEGER, origin TEXT, target TEXT, inputs TEXT, edited INTEGER,
        sm_root REAL, percentile REAL, risk REAL, severity TEXT, severity_class TEXT, result TEXT)');
    return $pdo;
}
function history() {
    $db = log_db();
    $rows = $db->query('SELECT id, created_at, place, state, district, lat, lon, origin, target, percentile, risk, severity, severity_class, edited FROM india_predictions ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $s = $db->query("SELECT COUNT(*) n, AVG(risk) avg_risk, SUM(severity_class IN ('severe','extreme','exceptional')) high FROM india_predictions")->fetch(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; foreach (array('lat', 'lon', 'percentile', 'risk') as $k) $r[$k] = (float)$r[$k]; $r['edited'] = (bool)$r['edited']; }
    return array('items' => $rows, 'stats' => array('predictions' => (int)$s['n'], 'high_risk' => (int)$s['high'],
        'average_risk' => $s['n'] ? round((float)$s['avg_risk'], 1) : null, 'latest' => $rows ? $rows[0] : null));
}

if (!isset($_SESSION['normal_user'])) reply(array('error' => 'Please log in again.'), 401);
$action = (string)request_value($_GET, 'action', '');
$method = $_SERVER['REQUEST_METHOD'];
try {
    if ($method === 'POST') {
        $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
        if (empty($_SESSION['csrf']) || !secure_equals($_SESSION['csrf'], $token)) reply(array('error' => 'Session expired. Reload the page.'), 403);
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) $in = array();
    }
    if ($action === 'cells' && $method === 'GET') {
        $out = array(); foreach (india_db()->query('SELECT id, lat, lon, state, district FROM cells ORDER BY id') as $r) $out[] = array((int)$r['id'], (float)$r['lat'], (float)$r['lon'], $r['state'], $r['district']);
        reply(array('cells' => $out));
    }
    if ($action === 'lookup' && $method === 'GET') {
        $lat = num($_GET, 'lat', -90, 90); $lon = num($_GET, 'lon', -180, 180); $t = month_index($_GET); $loc = locate($lat, $lon);
        $obs = $loc['inside'] ? india_series($loc['cell']['id'], $t, $t) : array();
        reply($loc + array('month' => india_label($t), 'observed' => $obs ? $obs[$t] : null));
    }
    if ($action === 'coverage' && $method === 'GET') {
        $t = month_index($_GET); $h = (int)request_value($_GET, 'h', 0) ? 1 : 0;
        reply(array('month' => india_label($t + $h), 'forecast' => (bool)$h, 'values' => array_column(india_region_map($t, $h), 3)));
    }
    if ($action === 'history' && $method === 'GET') reply(history());
    if ($action === 'predict' && $method === 'POST') {
        $lat = num($in, 'lat', -90, 90); $lon = num($in, 'lon', -180, 180); $t = month_index($in); $loc = locate($lat, $lon);
        if (!$loc['inside']) reply(array('error' => 'This location is ' . round($loc['distance_km']) . ' km from the nearest India grid point. Choose a location inside India.'), 422);
        $observed = india_series($loc['cell']['id'], $t, $t); $observed = $observed[$t];
        $override = array(); $edited = false;
        foreach (INPUTS as $k => $spec) {
            $v = num($in, $k, $spec[0], $spec[1]);
            // Within the form's rounding it is the observed record, not a what-if change.
            if (abs($v - $observed[$k]) <= $spec[2]) $v = $observed[$k]; else $edited = true;
            $override[$k] = $v;
        }
        $fc = india_forecast($loc['cell']['id'], $t, $override); $next = $fc['rows'][0];
        $place = trim(mb_substr((string)request_value($in, 'place', ''), 0, 80));
        if ($place === '') $place = $loc['cell']['district'] . ', ' . $loc['cell']['state'];
        $result = array('place' => $place, 'lat' => $lat, 'lon' => $lon, 'cell' => $loc['cell'], 'distance_km' => $loc['distance_km'], 'origin' => $fc['origin'],
            'inputs' => $override, 'observed' => $observed, 'edited' => $edited, 'current' => $fc['current'], 'rows' => $fc['rows']);
        $q = log_db()->prepare('INSERT INTO india_predictions (created_at, user, place, state, district, lat, lon, cell_id, origin, target, inputs, edited, sm_root, percentile, risk, severity, severity_class, result) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute(array(date('Y-m-d H:i:s'), $_SESSION['normal_user'], $place, $loc['cell']['state'], $loc['cell']['district'], $lat, $lon, $loc['cell']['id'], $fc['origin'], $next['month'],
            json_encode($override), (int)$edited, $next['sm_root'], $next['percentile'], $next['risk'], $next['drought']['label'], $next['drought']['class'], json_encode($result)));
        reply(array('result' => $result, 'history' => history()));
    }
    if ($action === 'clear' && $method === 'POST') { log_db()->exec('DELETE FROM india_predictions'); reply(array('history' => history())); }
    reply(array('error' => 'Unknown action'), 404);
} catch (InvalidArgumentException $e) { reply(array('error' => $e->getMessage()), 422); }
  catch (Exception $e) { reply(array('error' => $e->getMessage()), 500); }
