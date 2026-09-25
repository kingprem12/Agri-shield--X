<?php
/*
 * Farm sensors vs seed data: compares daily ESP32 readings with NASA POWER daily values
 * (the same source as the model's seed data) at the farm's location.
 */
require_once __DIR__ . '/database.php';

function app_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/app.db', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
    return $pdo;
}
function setting($key, $default = null) {
    $q = app_db()->prepare('SELECT value FROM settings WHERE key = ?'); $q->execute(array($key));
    $v = $q->fetchColumn(); return $v === false ? $default : $v;
}
function set_setting($key, $value) { app_db()->prepare('INSERT OR REPLACE INTO settings VALUES (?, ?)')->execute(array($key, (string)$value)); }

/* Daily means of all ESP32 readings (Area 1; every area receives the same physical sensor). */
function esp32_daily() {
    $days = array();
    foreach (glob(storage_dir(1) . '/readings-*.json') as $file) {
        if (!preg_match('/readings-(\d{4}-\d{2}-\d{2})\.json$/', $file, $m)) continue;
        $rows = read_json_file($file, array()); if (!$rows) continue;
        $sum = array('t' => 0, 'h' => 0, 'soil' => 0, 'rain' => 0); $maxRain = 0;
        foreach ($rows as $r) {
            $sum['t'] += $r['temperature']; $sum['h'] += $r['humidity'];
            $sum['soil'] += sensor_percent($r['soil_value']); $rain = sensor_percent($r['rain_value']); $sum['rain'] += $rain; $maxRain = max($maxRain, $rain);
        }
        $n = count($rows);
        $days[$m[1]] = array('temperature' => $sum['t'] / $n, 'humidity' => $sum['h'] / $n, 'soil' => $sum['soil'] / $n, 'rain' => $sum['rain'] / $n, 'rain_max' => $maxRain, 'readings' => $n);
    }
    ksort($days);
    return $days;
}

/* NASA POWER daily values at the farm, cached for 12 hours. Returns [date => values] or throws. */
function farm_power_daily($lat, $lon, $from, $to) {
    $dir = __DIR__ . '/data/validation'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = sprintf('%s/farm-%.4f_%.4f-%s-%s.json', $dir, $lat, $lon, $from, $to);
    if (file_exists($file) && filemtime($file) > time() - 43200) return read_json_file($file, array());
    $url = sprintf('https://power.larc.nasa.gov/api/temporal/daily/point?parameters=T2M,RH2M,PRECTOTCORR,GWETTOP,GWETROOT&community=AG&longitude=%.4f&latitude=%.4f&start=%s&end=%s&format=JSON',
        $lon, $lat, str_replace('-', '', $from), str_replace('-', '', $to));
    $raw = @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 40))));
    $json = $raw ? json_decode($raw, true) : null;
    if (!isset($json['properties']['parameter'])) throw new Exception('Could not download NASA POWER data for the farm (internet needed).');
    $p = $json['properties']['parameter']; $out = array();
    foreach ($p['T2M'] as $d => $v) {
        $row = array('t2m' => $v, 'rh' => $p['RH2M'][$d], 'precip' => $p['PRECTOTCORR'][$d], 'sm_top' => $p['GWETTOP'][$d], 'sm_root' => $p['GWETROOT'][$d]);
        if (in_array(-999, $row, true) || in_array(-999.0, $row, true)) continue; // not published yet
        $out[substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2)] = $row;
    }
    write_json_file($file, $out);
    return $out;
}

function stats_pair($a, $b) {
    $n = count($a); if ($n < 2) return null;
    $ma = array_sum($a) / $n; $mb = array_sum($b) / $n; $sab = $saa = $sbb = $abs = 0;
    for ($i = 0; $i < $n; $i++) { $da = $a[$i] - $ma; $db = $b[$i] - $mb; $sab += $da * $db; $saa += $da * $da; $sbb += $db * $db; $abs += abs($a[$i] - $b[$i]); }
    $r = ($saa > 0 && $sbb > 0) ? $sab / sqrt($saa * $sbb) : null;
    return array('n' => $n, 'r' => $r, 'bias' => $ma - $mb, 'mae' => $abs / $n, 'slope' => $sbb > 0 ? $sab / $sbb : null, 'intercept' => $sbb > 0 ? $ma - $sab / $sbb * $mb : null);
}

/* Match ESP32 days with NASA days and compute agreement statistics. */
function farm_comparison($lat, $lon) {
    $esp = esp32_daily();
    $out = array('days_collected' => count($esp), 'first' => $esp ? array_key_first($esp) : null, 'last' => $esp ? array_key_last($esp) : null, 'matched' => array(), 'error' => null);
    if (!$esp) return $out;
    try { $nasa = farm_power_daily($lat, $lon, $out['first'], date('Y-m-d')); } catch (Exception $e) { $out['error'] = $e->getMessage(); return $out; }
    $pairs = array('temperature' => array(array(), array()), 'humidity' => array(array(), array()), 'soil' => array(array(), array()));
    $rainAgree = 0; $rainDays = array('esp' => 0, 'nasa' => 0, 'both' => 0);
    foreach ($esp as $d => $e) {
        if (!isset($nasa[$d])) continue; $n = $nasa[$d];
        $out['matched'][] = array('date' => $d, 'esp' => $e, 'nasa' => $n);
        $pairs['temperature'][0][] = $e['temperature']; $pairs['temperature'][1][] = $n['t2m'];
        $pairs['humidity'][0][] = $e['humidity']; $pairs['humidity'][1][] = $n['rh'];
        $pairs['soil'][0][] = $e['soil']; $pairs['soil'][1][] = 100 * $n['sm_top'];
        $er = $e['rain_max'] > RAIN_WET_PERCENT; $nr = $n['precip'] >= 1.0;
        $rainAgree += ($er === $nr) ? 1 : 0; $rainDays['esp'] += $er; $rainDays['nasa'] += $nr; $rainDays['both'] += ($er && $nr);
    }
    foreach ($pairs as $k => $p) $out[$k] = stats_pair($p[0], $p[1]);
    $m = count($out['matched']);
    $out['rain'] = $m ? array('agreement' => $rainAgree / $m) + $rainDays : null;
    $out['nasa_last'] = $nasa ? array_key_last($nasa) : null;
    return $out;
}
?>
