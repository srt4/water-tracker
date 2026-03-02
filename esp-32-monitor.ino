#include <WiFi.h>
#include <HTTPClient.h>
#include <NetworkClientSecure.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <esp_task_wdt.h>

// --- Configuration ---
const char* ssid = "<replace>"; 
const char* password = "<replace>";
const char* serverName = "https://spencerthomas.org/austin-temp/";

#define LED_PIN 2           
#define ONE_WIRE_BUS 4
#define WDT_TIMEOUT_SECONDS 30 

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

void setup() {
  // --- New WDT Initialization for v3.0+ ---
  esp_task_wdt_config_t wdt_config = {
    .timeout_ms = WDT_TIMEOUT_SECONDS * 1000,
    .idle_core_mask = (1 << portNUM_PROCESSORS) - 1,
    .trigger_panic = true
  };
  esp_task_wdt_init(&wdt_config);
  esp_task_wdt_add(NULL); 

  pinMode(LED_PIN, OUTPUT);
  Serial.begin(115200);
  sensors.begin();

  connectToWiFi();
}

void connectToWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(true);
  WiFi.begin(ssid, password);

  Serial.print("Connecting WiFi");
  int attempt = 0;
  while (WiFi.status() != WL_CONNECTED && attempt < 40) {
    esp_task_wdt_reset(); 
    delay(500);
    Serial.print(".");
    attempt++;
  }
  if(WiFi.status() == WL_CONNECTED) Serial.println("\nConnected!");
}

void loop() {
  esp_task_wdt_reset(); 

  if (WiFi.status() != WL_CONNECTED) {
    connectToWiFi();
    return;
  }

  sensors.requestTemperatures();
  float t1 = sensors.getTempCByIndex(0);
  float t2 = sensors.getTempCByIndex(1);

  if (t1 != DEVICE_DISCONNECTED_C && t2 != DEVICE_DISCONNECTED_C) {
    NetworkClientSecure client;
    client.setInsecure();
    HTTPClient http;
    http.setTimeout(8000); 

    if (http.begin(client, serverName)) {
      http.addHeader("Content-Type", "application/json");
      String json = "{\"sensor1\":" + String(t1, 2) + ",\"sensor2\":" + String(t2, 2) + "}";
      
      int response = http.POST(json);
      if (response == 200) {
        digitalWrite(LED_PIN, HIGH); delay(100); digitalWrite(LED_PIN, LOW);
      }
      http.end();
    }
  }

  // Multi-step wait to keep WDT happy
  for (int i = 0; i < 10; i++) {
    esp_task_wdt_reset(); 
    delay(1000); 
  }
}
