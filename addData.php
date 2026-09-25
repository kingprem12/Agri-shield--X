<?php
/*
 * Compatibility endpoint for the supplied ESP32 controller.
 *
 * Expected request:
 * addData.php?api_key=KEY&temp=30.5&hum=65&soil=68&rain=25
 *
 * The controller sends soil and rain as percentages. Internally this project
 * keeps the original 12-bit ADC scale so existing prediction/rain logic does
 * not need to change. The dashboard converts it back to a percentage.
 */
require_once __DIR__ . '/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (DEVICE_API_KEY === '' || !secure_equals(DEVICE_API_KEY, (string)request_value($_GET, 'api_key', ''))) {
        http_response_code(401);
        throw new Exception('Invalid API key');
    }
    foreach (array('temp', 'hum', 'soil', 'rain') as $field) {
        if (!isset($_GET[$field]) || !is_numeric($_GET[$field])) {
            http_response_code(422);
            throw new Exception('Missing or invalid ' . $field);
        }
    }

    $temperature = (float)$_GET['temp'];
    $humidity = (float)$_GET['hum'];
    $soilPercent = (float)$_GET['soil'];
    $rainPercent = (float)$_GET['rain'];

    if ($temperature < -40 || $temperature > 125) {
        http_response_code(422);
        throw new Exception('Temperature must be between -40 and 125');
    }
    if ($humidity < 0 || $humidity > 100) {
        http_response_code(422);
        throw new Exception('Humidity must be between 0 and 100');
    }
    if ($soilPercent < 0 || $soilPercent > 100 || $rainPercent < 0 || $rainPercent > 100) {
        http_response_code(422);
        throw new Exception('Soil and rain percentages must be between 0 and 100');
    }

    $soilRaw = (int)round($soilPercent * 4095 / 100);
    $rainRaw = (int)round($rainPercent * 4095 / 100);

    $records = save_reading_to_all_areas($temperature, $humidity, $soilRaw, $rainRaw);

    echo json_encode(array(
        'success' => true,
        'message' => 'ESP32 data saved to Areas 1, 2, 3 and 4',
        'areas' => array(1, 2, 3, 4),
        'received' => array(
            'temperature' => $temperature,
            'humidity' => $humidity,
            'soil_percent' => $soilPercent,
            'rain_percent' => $rainPercent
        ),
        'ids' => array(
            'area_1' => $records[1]['id'],
            'area_2' => $records[2]['id'],
            'area_3' => $records[3]['id'],
            'area_4' => $records[4]['id']
        )
    ), JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    if (http_response_code() === 200) http_response_code(500);
    echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}
?>
