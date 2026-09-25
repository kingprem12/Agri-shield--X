<?php
/* File storage version: no MySQL/phpMyAdmin required. */
require_once __DIR__ . '/config.php';

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function request_value($source, $key, $default) { return isset($source[$key]) ? $source[$key] : $default; }
function valid_area($area) { $area = (int)$area; return ($area >= 1 && $area <= 4) ? $area : 1; }
/* Convert the ESP32 12-bit ADC value (0-4095) to a display percentage. */
function sensor_percent($value) { return round(max(0, min(4095, (float)$value)) * 100 / 4095, 1); }
function secure_equals($known, $given) {
    if (function_exists('hash_equals')) return hash_equals($known, $given);
    if (strlen($known) !== strlen($given)) return false;
    $result = 0; for ($i=0; $i<strlen($known); $i++) $result |= ord($known[$i]) ^ ord($given[$i]); return $result === 0;
}
function storage_dir($area) {
    $dir = __DIR__ . '/data/area_' . valid_area($area);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new Exception('Cannot create the server data folder. Set the data folder permission to 755.');
    return $dir;
}
function read_json_file($file, $default) {
    if (!file_exists($file)) return $default;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}
function write_json_file($file, $data) {
    $temp = $file . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $file)) throw new Exception('Cannot save data. Set the data folder permission to 755.');
}
function flat_file_path() { return __DIR__ . '/flat_file.ini'; }
function flat_area_reading($area) {
    $area = valid_area($area); $file = flat_file_path();
    if (!file_exists($file)) return null;
    $ini = parse_ini_file($file, true, INI_SCANNER_RAW);
    if (!is_array($ini)) return null;
    $section = 'area_' . $area;
    if (isset($ini[$section])) {
        $row = $ini[$section];
        foreach (array('temperature','humidity','soil_value','rain_value') as $key) if (!isset($row[$key]) || !is_numeric($row[$key])) return null;
        return array('id'=>'flat_area_'.$area, 'area_id'=>$area, 'temperature'=>(float)$row['temperature'], 'humidity'=>(float)$row['humidity'], 'soil_value'=>(int)$row['soil_value'], 'rain_value'=>(int)$row['rain_value'], 'action'=>isset($row['action'])?$row['action']:'auto', 'recorded_at'=>date('Y-m-d H:i:s', filemtime($file)), 'source'=>'flat_file.ini');
    }
    return null;
}
function update_flat_area($area, $temp, $hum, $soil, $rain) {
    require_once __DIR__ . '/file_functions.php';
    $section = 'area_' . valid_area($area); $file = flat_file_path();
    foreach (array('temperature'=>$temp,'humidity'=>$hum,'soil_value'=>$soil,'rain_value'=>$rain,'action'=>'sensor') as $key=>$value) write_ini_file($file, $section, $key, $value);
}
function reading_file($area, $date) { return storage_dir($area) . '/readings-' . $date . '.json'; }
function save_sensor_reading($area, $temp, $hum, $soil, $rain) {
    $date = date('Y-m-d'); $file = reading_file($area, $date); $records = read_json_file($file, array());
    $record = array('id'=>uniqid('r_', true), 'area_id'=>(int)$area, 'temperature'=>(float)$temp, 'humidity'=>(float)$hum, 'soil_value'=>(int)$soil, 'rain_value'=>(int)$rain, 'recorded_at'=>date('Y-m-d H:i:s'));
    $records[] = $record; write_json_file($file, $records); return $record;
}
/* Save one physical ESP32 reading to all four dashboard areas. */
function save_reading_to_all_areas($temp, $hum, $soil, $rain) {
    $saved = array();
    for ($area = 1; $area <= 4; $area++) {
        $saved[$area] = save_sensor_reading($area, $temp, $hum, $soil, $rain);
        update_flat_area($area, $temp, $hum, $soil, $rain);
    }
    return $saved;
}
function latest_reading($area) {
    $flat = flat_area_reading($area);
    if ($flat) return $flat;
    for ($i=0; $i<366; $i++) { $date=date('Y-m-d', strtotime('-'.$i.' day')); $records=read_json_file(reading_file($area,$date),array()); if ($records) return $records[count($records)-1]; }
    return null;
}
function readings_for_date($area, $date) { return array_reverse(read_json_file(reading_file($area,$date),array())); }
function snapshot_file($area) { return storage_dir($area) . '/snapshots.json'; }
function save_snapshot($area, $date, $reading) { $file=snapshot_file($area); $all=read_json_file($file,array()); $all[$date]=$reading; $all[$date]['snapshot_date']=$date; $all[$date]['saved_at']=date('Y-m-d H:i:s'); write_json_file($file,$all); return $all[$date]; }
function snapshot_for_date($area, $date) { $all=read_json_file(snapshot_file($area),array()); return isset($all[$date]) ? $all[$date] : null; }
function daily_trends($area, $until, $limit) {
    $result=array(); for ($i=$limit-1; $i>=0; $i--) { $date=date('Y-m-d',strtotime($until.' -'.$i.' day')); $items=read_json_file(reading_file($area,$date),array()); if (!$items) continue; $sum=array('temperature'=>0,'humidity'=>0,'soil_value'=>0,'rain_value'=>0); foreach($items as $item) foreach($sum as $key=>$value) $sum[$key]+=$item[$key]; $count=count($items); $result[]=array('day'=>$date,'temperature'=>$sum['temperature']/$count,'humidity'=>$sum['humidity']/$count,'soil'=>$sum['soil_value']/$count,'rain'=>$sum['rain_value']/$count); } return $result;
}
?>
