#include <WiFi.h>
#include "iot_functions.h"
#include <DHT.h>
#include "secrets.h"  // WIFI_SSID, WIFI_PASSWORD (copy secrets.example.h to secrets.h)

#define DHT_PIN 27
#define DHT_TYPE DHT11

#define SOIL_PIN 34
#define RAIN_PIN 35

const char* host = "codexedgesolution.in";

DHT dht(DHT_PIN, DHT_TYPE);

void setup()
{
  Serial.begin(115200);
  analogReadResolution(12);
  initWiFi(WIFI_SSID, WIFI_PASSWORD, 1);

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

  String url = "/27project/CXPEM20260004-AGRI_SHIELD/addData.php?temp=";
  url += String(temp);
  url += "&hum=";
  url += String(hum);
  url += "&soil=";
  url += String(soilPercent);
  url += "&rain=";
  url += String(rainPercent);

  Serial.print("Sending: ");
  Serial.println(url);
  requestURL(host, url);

  delay(2000);
}
