<?php
/*
 * JSON API for the Drought Predictor page (drought.php). Session login required.
 *   GET  ?action=lookup&lat=..&lon=..&month=YYYY-MM   nearest grid cell + observed satellite values
 *   GET  ?action=coverage&month=YYYY-MM              actual VHI for every grid cell (map layer)
 *   GET  ?action=history                             prediction log + dashboard stats
 *   POST ?action=predict  {lat, lon, month, place, ndvi, lst, precip}   run PSO-LightGBM
 *   POST ?action=clear                               empty the prediction log
 * POST requests need the X-CSRF-Token header from the page.
 */
require_once __DIR__ . '/../satellite_engine.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Kolkata');
session_start();

const COVERAGE_KM = 15; // grid spacing is ~10 km; farther than this from a cell = outside the trained region

function reply($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function num($src, $key, $min, $max) {
    if (!isset($src[$key]) || !is_numeric($src[$key])) throw new InvalidArgumentException("Missing or invalid $key");
    $v = (float)$src[$key];
    if ($v < $min || $v > $max) throw new InvalidArgumentException("$key must be between $min and $max");
    return $v;
}
function month_index($src) {
    $t = sat_index((string)request_value($src, 'month', ''));
    if ($t === null || $t < 11) throw new InvalidArgumentException('month must be between ' . sat_label(11) . ' and ' . sat_label(sat_meta()['months'] - 1));
    return $t;
}
function km($lat1, $lon1, $lat2, $lon2) {
    $r = M_PI / 180; $a = sin(($lat2 - $lat1) * $r / 2) ** 2 + cos($lat1 * $r) * cos($lat2 * $r) * sin(($lon2 - $lon1) * $r / 2) ** 2;
    return 6371 * 2 * asin(min(1, sqrt($a)));
}
function locate($lat, $lon) {
    $cell = sat_nearest_cell($lat, $lon); $d = km($lat, $lon, $cell['lat'], $cell['lon']);
    return array('cell' => $cell, 'distance_km' => round($d, 1), 'inside' => $d <= COVERAGE_KM);
}
function log_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . __DIR__ . '/../data/app.db', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('CREATE TABLE IF NOT EXISTS predictions (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, user TEXT, place TEXT,
        lat REAL, lon REAL, cell_id INTEGER, origin TEXT, target TEXT, ndvi REAL, lst REAL, precip REAL, edited INTEGER,
        pred_ndvi REAL, pred_lst REAL, vhi REAL, risk REAL, severity TEXT, severity_class TEXT, result TEXT)');
    return $pdo;
}
function history() {
    $rows = log_db()->query('SELECT id, created_at, place, lat, lon, origin, target, vhi, risk, severity, severity_class, edited FROM predictions ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $s = log_db()->query("SELECT COUNT(*) n, AVG(risk) avg_risk, SUM(severity_class IN ('severe','extreme')) high FROM predictions")->fetch(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; foreach (array('lat', 'lon', 'vhi', 'risk') as $k) $r[$k] = (float)$r[$k]; $r['edited'] = (bool)$r['edited']; }
    return array('items' => $rows, 'stats' => array('predictions' => (int)$s['n'], 'high_risk' => (int)$s['high'],
        'average_risk' => $s['n'] ? round((float)$s['avg_risk'], 1) : null, 'latest' => $rows ? $rows[0] : null));
}

if (!isset($_SESSION['normal_user'])) reply(array('error' => 'Please log in again.'), 401);
$action = (string)request_value($_GET, 'action', '');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
        if (empty($_SESSION['csrf']) || !secure_equals($_SESSION['csrf'], $token)) reply(array('error' => 'Session expired. Reload the page.'), 403);
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) $in = array();
    }
    if ($action === 'lookup' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $lat = num($_GET, 'lat', -90, 90); $lon = num($_GET, 'lon', -180, 180); $t = month_index($_GET); $loc = locate($lat, $lon);
        $obs = $loc['inside'] ? sat_series($loc['cell']['id'], $t, $t) : array();
        reply($loc + array('month' => sat_label($t), 'observed' => $obs ? $obs[$t] : null));
    }
    if ($action === 'coverage' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $t = month_index($_GET);
        reply(array('month' => sat_label($t), 'cells' => sat_region_map($t - 1, 1, true)));
    }
    if ($action === 'history' && $_SERVER['REQUEST_METHOD'] === 'GET') reply(history());
    if ($action === 'predict' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $lat = num($in, 'lat', -90, 90); $lon = num($in, 'lon', -180, 180); $t = month_index($in); $loc = locate($lat, $lon);
        if (!$loc['inside']) reply(array('error' => 'This location is ' . round($loc['distance_km']) . ' km from the nearest trained grid cell. The model only has satellite history for the Sindh region, so it cannot predict here.'), 422);
        $override = array('ndvi' => num($in, 'ndvi', -1, 1), 'lst' => num($in, 'lst', -30, 80), 'precip' => num($in, 'precip', 0, 2000));
        // The form rounds values (NDVI 4 dp, LST/rain 2 dp): within that rounding it is the observed record, not a what-if.
        $observed = sat_series($loc['cell']['id'], $t, $t); $observed = $observed[$t]; $edited = false;
        foreach (array('ndvi' => 5e-5, 'lst' => 5e-3, 'precip' => 5e-3) as $k => $tol) {
            if (abs($override[$k] - $observed[$k]) <= $tol) $override[$k] = $observed[$k]; else $edited = true;
        }
        $fc = sat_forecast($loc['cell']['id'], $t, $override);
        $next = $fc['rows'][0]; $risk = round(100 - $next['vhi'], 1);
        $place = trim(mb_substr((string)request_value($in, 'place', ''), 0, 80)); if ($place === '') $place = sprintf('%.2f°N, %.2f°E', $lat, $lon);
        $result = array('place' => $place, 'lat' => $lat, 'lon' => $lon, 'cell' => $loc['cell'], 'distance_km' => $loc['distance_km'], 'origin' => $fc['origin'],
            'inputs' => $override, 'observed' => $fc['observed'], 'edited' => $edited, 'risk' => $risk, 'rows' => $fc['rows']);
        $q = log_db()->prepare('INSERT INTO predictions (created_at, user, place, lat, lon, cell_id, origin, target, ndvi, lst, precip, edited, pred_ndvi, pred_lst, vhi, risk, severity, severity_class, result) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute(array(date('Y-m-d H:i:s'), $_SESSION['normal_user'], $place, $lat, $lon, $loc['cell']['id'], $fc['origin'], $next['month'], $override['ndvi'], $override['lst'], $override['precip'], (int)$edited,
            $next['ndvi'], $next['lst'], $next['vhi'], $risk, $next['drought']['label'], $next['drought']['class'], json_encode($result)));
        reply(array('result' => $result, 'history' => history()));
    }
    if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') { log_db()->exec('DELETE FROM predictions'); reply(array('history' => history())); }
    reply(array('error' => 'Unknown action'), 404);
} catch (InvalidArgumentException $e) { reply(array('error' => $e->getMessage()), 422); }
  catch (Exception $e) { reply(array('error' => $e->getMessage()), 500); }
