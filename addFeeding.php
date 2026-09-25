<?php
/* ESP32 endpoint. Supports POST (recommended) and GET for easy testing. */
require_once __DIR__ . '/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($contentType, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (!is_array($decoded)) { http_response_code(400); throw new Exception('Invalid JSON request body'); }
        $input = $decoded;
    }
    if (!secure_equals(DEVICE_API_KEY, (string)request_value($input, 'api_key', ''))) {
        http_response_code(401);
        throw new Exception('Invalid API key');
    }

    foreach (array('temp', 'hum', 'soil', 'rain') as $field) {
        if (!isset($input[$field]) || !is_numeric($input[$field])) {
            http_response_code(422);
            throw new Exception('Missing or invalid ' . $field);
        }
    }
    $temp = (float)$input['temp'];
    $hum = (float)$input['hum'];
    $soil = (int)$input['soil'];
    $rain = (int)$input['rain'];
    if ($temp < -40 || $temp > 125 || $hum < 0 || $hum > 100 || $soil < 0 || $soil > 4095 || $rain < 0 || $rain > 4095) {
        http_response_code(422);
        throw new Exception('Sensor value is outside its valid range');
    }

    $records = save_reading_to_all_areas($temp, $hum, $soil, $rain);
    echo json_encode(array(
        'success' => true,
        'message' => 'Reading saved to Areas 1, 2, 3 and 4',
        'areas' => array(1, 2, 3, 4),
        'reading_ids' => array($records[1]['id'], $records[2]['id'], $records[3]['id'], $records[4]['id'])
    ), JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    if (http_response_code() === 200) http_response_code(500);
    echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}
?>
