#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include "secrets.h"  // WIFI_SSID, WIFI_PASSWORD, SERVER_URL, DEVICE_API_KEY (copy secrets.example.h)

#define DHT_PIN 27
#define DHT_TYPE DHT11

#define SOIL_PIN 34
#define RAIN_PIN 35

const unsigned long SEND_INTERVAL_MS = 10000;

DHT dht(DHT_PIN, DHT_TYPE);

void setup()
{
  Serial.begin(115200);
  analogReadResolution(12);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  while (WiFi.status() != WL_CONNECTED)
  {
    delay(500);
    Serial.print(".");
  }

  dht.begin();
}

void loop()
{
  float temp = dht.readTemperature();
  float hum  = dht.readHumidity();

  int soilRaw = analogRead(SOIL_PIN);
  int rainRaw = analogRead(RAIN_PIN);

  // Convert ADC readings to percentages before sending to the project.
  int soilPercent = map(soilRaw, 0, 4095, 0, 100);
  soilPercent = constrain(soilPercent, 0, 100);

  int rainPercent = map(rainRaw, 0, 4095, 0, 100);
  rainPercent = constrain(rainPercent, 0, 100);

  Serial.println("--------------------------------");

  if (!isnan(temp))
  {
    Serial.print("Temperature : ");
    Serial.print(temp);
    Serial.println(" C");

    Serial.print("Humidity    : ");
    Serial.print(hum);
    Serial.println(" %");
  }

  Serial.print("Soil Raw    : ");
  Serial.println(soilRaw);

  Serial.print("Soil Moisture : ");
  Serial.print(soilPercent);
  Serial.println("%");

  if (soilPercent > 70)
    Serial.println("Soil : WET");
  else if (soilPercent > 40)
    Serial.println("Soil : MOIST");
  else
    Serial.println("Soil : DRY");

  Serial.println();

  Serial.print("Rain Raw    : ");
  Serial.println(rainRaw);

  Serial.print("Rain Level  : ");
  Serial.print(rainPercent);
  Serial.println("%");

  if (rainPercent > 70)
    Serial.println("Rain : HEAVY");
  else if (rainPercent > 30)
    Serial.println("Rain : LIGHT");
  else
    Serial.println("Rain : NO RAIN");

  Serial.println("--------------------------------");

  if (isnan(temp) || isnan(hum))
  {
    Serial.println("DHT11 read failed - reading not sent");
    delay(SEND_INTERVAL_MS);
    return;
  }

  if (WiFi.status() != WL_CONNECTED)
  {
    Serial.println("WiFi lost - reconnecting");
    WiFi.reconnect();
    delay(SEND_INTERVAL_MS);
    return;
  }

  String url = String(SERVER_URL) + "/addData.php?api_key=" + DEVICE_API_KEY + "&temp=";
  url += String(temp);
  url += "&hum=";
  url += String(hum);
  url += "&soil=";
  url += String(soilPercent);
  url += "&rain=";
  url += String(rainPercent);

  Serial.print("Sending: ");
  Serial.println(url);

  HTTPClient http;
  http.begin(url);
  int code = http.GET();
  Serial.print("Server: ");
  Serial.print(code);
  Serial.print(" ");
  Serial.println(code > 0 ? http.getString() : http.errorToString(code));
  http.end();

  delay(SEND_INTERVAL_MS);
}
