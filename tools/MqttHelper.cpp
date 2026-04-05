#include "MqttHelper.h"
#include "WebUiHelper.h"

#if defined(ESP32) && defined(ENABLE_MQTT)

#include <ArduinoJson.h>
#include <PubSubClient.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <esp_wifi.h>
#include <queue>
#include "time.h"

#define MQTT_TRANSPORT_TCP  0
#define MQTT_TRANSPORT_SSL  1
#define MQTT_TRANSPORT_WS   2
#define MQTT_TRANSPORT_WSS  3

#define MQTT_DEFAULT_TCP_PORT  1883
#define MQTT_DEFAULT_SSL_PORT  8883
#define MQTT_BUFFER_SIZE       1280
#define MQTT_QUEUE_MAX         64

#define NTP_RETRY_INTERVAL_MS    30000UL
#define NTP_RESYNC_INTERVAL_MS   (60UL * 60UL * 1000UL)

namespace {

struct MqttItem {
  char  topic[220];
  char* payload;
};

struct MqttQueue {
  std::queue<MqttItem*> queue;
  unsigned discarded = 0;

  void push(const char* topic, const String& payload) {
    while (queue.size() >= MQTT_QUEUE_MAX) {
      discarded++;
      MqttItem* old = queue.front();
      queue.pop();
      delete[] old->payload;
      delete old;
    }

    MqttItem* item = new MqttItem();
    strncpy(item->topic, topic, sizeof(item->topic) - 1);
    item->topic[sizeof(item->topic) - 1] = '\0';
    item->payload = new char[payload.length() + 1];
    payload.toCharArray(item->payload, payload.length() + 1);
    queue.push(item);
  }

  void push(const char* topic, const JsonDocument& doc) {
    String payload;
    serializeJson(doc, payload);
    push(topic, payload);
  }

  size_t size() const { return queue.size(); }
  MqttItem* front() { return queue.front(); }
  void pop() {
    MqttItem* item = queue.front();
    queue.pop();
    delete[] item->payload;
    delete item;
  }
} mqttQueue;

static bool ntpSynced = false;
static unsigned long ntpNext = 0;
static TaskHandle_t wifiTaskHandle = nullptr;
static MyMesh* mqttMesh = nullptr;
static WiFiClient mqttTcpClient;
static WiFiClientSecure mqttSslClient;
static PubSubClient mqttClient;

// Set by loopTask when WiFi credentials change; WiFiTask picks it up and
// performs the restart in its own context to avoid cross-task WiFi API races.
static volatile bool wifiRestartRequested = false;

static void task_sleep(uint32_t ms) {
  vTaskDelay(ms / portTICK_PERIOD_MS);
}

static void mqttDebugHook(const char* line) {
  if (mqttMesh == nullptr || line == nullptr) {
    return;
  }
  repeaterMqttPublishDebugLine(mqttMesh->getNodeName(), mqttMesh->getSelfId().pub_key, line);
}

static void formatMqttTimestamp(char* iso_ts, size_t iso_len,
                                char* time_str, size_t time_len,
                                char* date_str, size_t date_len) {
  time_t now;
  time(&now);
  struct tm ti;
  gmtime_r(&now, &ti);
  if (now > 0) {
    strftime(iso_ts, iso_len, "%Y-%m-%dT%H:%M:%S", &ti);
    strftime(time_str, time_len, "%H:%M:%S", &ti);
    snprintf(date_str, date_len, "%d/%d/%d", ti.tm_mday, ti.tm_mon + 1, ti.tm_year + 1900);
  } else {
    snprintf(iso_ts, iso_len, "1970-01-01T00:00:00");
    snprintf(time_str, time_len, "00:00:00");
    snprintf(date_str, date_len, "1/1/1970");
  }
}

static String getMqttPath(mesh::Packet* pkt) {
  if (!pkt->isRouteDirect()) {
    return "";
  }

  String path;
  int hashsize = pkt->getPathHashSize();
  int blen = pkt->getPathByteLen();
  int partsize = 0;
  bool first = true;
  char buf[4];

  for (int i = 0; i < blen; i++) {
    if (partsize == 0 && !first) {
      path += " -> ";
    }
    snprintf(buf, sizeof(buf), "%02X", pkt->path[i]);
    path += buf;
    if (++partsize >= hashsize) {
      partsize = 0;
      first = false;
    }
  }

  return path;
}

static void WiFiTaskCode(void* pvParameters) {
  static bool wifi_connected = false;
  static unsigned long lastConnected = 0;
  static unsigned long nextReport = 30000;
  static bool reported = false;

  mqttSslClient.setInsecure();
  mqttClient.setBufferSize(MQTT_BUFFER_SIZE);
  mqttClient.setKeepAlive(60);
  // Limit socket blocking to 3 s so PubSubClient::connect() returns before
  // the 5-second IDLE task watchdog fires when the broker is unreachable.
  mqttClient.setSocketTimeout(3);

  for (;;) {
    /* ---- Apply WiFi credential changes requested from loopTask ---- */
    if (wifiRestartRequested) {
      wifiRestartRequested = false;
      wifi_connected = false;
      reported = false;
      if (mqttClient.connected()) mqttClient.disconnect();
      WiFi.disconnect(true);  // fully stop WiFi driver & clear WPA state
      task_sleep(200);
      if (mqttMesh) mqttMesh->toggleWiFi(true);
    }

    if (mqttMesh == nullptr) {
      task_sleep(100);
      continue;
    }

    if (WiFi.status() == WL_CONNECTED && WiFi.localIP() != INADDR_NONE) {
      wifi_connected = true;
      lastConnected = millis();

      if (!ntpSynced && millis() >= ntpNext) {
        const char* srv = mqttMesh->getMqttPrefs()->ntp_server[0]
                            ? mqttMesh->getMqttPrefs()->ntp_server
                            : "pool.ntp.org";
        configTime(0, 0, srv);
        task_sleep(100);
        struct tm ti;
        if (getLocalTime(&ti, 4500)) {
          time_t now;
          time(&now);
          mqttMesh->syncClock((uint32_t)now, true);
          ntpNext = millis() + NTP_RESYNC_INTERVAL_MS;
          // Give tcpip_thread time to finish SNTP post-sync processing before
          // WiFiTask calls back into lwip (prevents lwip mutex deadlock).
          task_sleep(500);
        } else {
          ntpNext = millis() + NTP_RETRY_INTERVAL_MS;
        }
      } else if (ntpSynced && millis() >= ntpNext) {
        ntpSynced = false;
        ntpNext = 0;
      }

      const MqttPrefs* mp = mqttMesh->getMqttPrefs();
      bool wantMqtt = mp->enabled && strlen(mp->host) > 0;

      if (wantMqtt && !mqttClient.connected()) {
        bool useSsl = (mp->transport == MQTT_TRANSPORT_SSL || mp->transport == MQTT_TRANSPORT_WSS);
        if (useSsl) {
          mqttClient.setClient(mqttSslClient);
        } else {
          mqttClient.setClient(mqttTcpClient);
        }

        uint16_t port = mp->port;
        if (port == 0) {
          port = useSsl ? MQTT_DEFAULT_SSL_PORT : MQTT_DEFAULT_TCP_PORT;
        }
        mqttClient.setServer(mp->host, port);

        char pubkeyHex[PUB_KEY_SIZE * 2 + 1];
        mesh::Utils::toHex(pubkeyHex, mqttMesh->getSelfId().pub_key, PUB_KEY_SIZE);

        char lwtTopic[220];
        snprintf(lwtTopic, sizeof(lwtTopic), "%s/%s/%s/status", mp->base_topic, mp->iata, pubkeyHex);

        JsonDocument lwtDoc;
        lwtDoc["status"] = "offline";
        lwtDoc["origin"] = mqttMesh->getNodeName();
        lwtDoc["origin_id"] = pubkeyHex;
        String lwtPayload;
        serializeJson(lwtDoc, lwtPayload);

        char clientId[48];
        snprintf(clientId, sizeof(clientId), "mshcore-%s", pubkeyHex + 56);

        bool connected;
        if (strlen(mp->username) > 0) {
          connected = mqttClient.connect(clientId, mp->username, mp->password,
                                         lwtTopic, 0, false, lwtPayload.c_str());
        } else {
          connected = mqttClient.connect(clientId, nullptr, nullptr,
                                         lwtTopic, 0, false, lwtPayload.c_str());
        }

        if (connected) {
          JsonDocument onDoc;
          onDoc["status"] = "online";
          onDoc["origin"] = mqttMesh->getNodeName();
          onDoc["origin_id"] = pubkeyHex;
          char iso_ts[32], ts[12], ds[16];
          formatMqttTimestamp(iso_ts, sizeof(iso_ts), ts, sizeof(ts), ds, sizeof(ds));
          onDoc["timestamp"] = iso_ts;
          onDoc["firmware_version"] = FIRMWARE_VERSION;
          String onPayload;
          serializeJson(onDoc, onPayload);
          mqttClient.publish(lwtTopic, onPayload.c_str(), false);

          nextReport = millis() + (mp->selfreport * 1000UL);
          repeaterWebUiBroadcast(lwtTopic, onPayload.c_str());
          reported = true;
        } else {
          task_sleep(5000);
        }
      }

      if (wantMqtt && mqttClient.connected()) {
        if (!reported || (mp->selfreport > 0 && millis() > nextReport)) {
          char pubkeyHex[PUB_KEY_SIZE * 2 + 1];
          mesh::Utils::toHex(pubkeyHex, mqttMesh->getSelfId().pub_key, PUB_KEY_SIZE);
          char statusTopic[220];
          snprintf(statusTopic, sizeof(statusTopic), "%s/%s/%s/status", mp->base_topic, mp->iata, pubkeyHex);

          JsonDocument doc;
          doc["status"] = "online";
          doc["origin"] = mqttMesh->getNodeName();
          doc["origin_id"] = pubkeyHex;
          char iso_ts[32], ts[12], ds[16];
          formatMqttTimestamp(iso_ts, sizeof(iso_ts), ts, sizeof(ts), ds, sizeof(ds));
          doc["timestamp"] = iso_ts;
          doc["firmware_version"] = FIRMWARE_VERSION;
          doc["heap_free"] = ESP.getFreeHeap();
          doc["uptime"] = millis();
          doc["rssi"] = WiFi.RSSI();
          String payload;
          serializeJson(doc, payload);
          mqttClient.publish(statusTopic, payload.c_str(), false);
          repeaterWebUiBroadcast(statusTopic, payload.c_str());
          reported = true;
          nextReport = millis() + (mp->selfreport * 1000UL);
        }

        while (mqttQueue.size() > 0) {
          MqttItem* item = mqttQueue.front();
          if (!mqttClient.publish(item->topic, item->payload, false)) {
            break;
          }
          mqttQueue.pop();
        }

        mqttClient.loop();
      }
    } else if (wifi_connected && millis() > lastConnected + 10000) {
      wifi_connected = false;
      reported = false;
      if (mqttClient.connected()) mqttClient.disconnect();
      WiFi.disconnect(true);  // wifioff=true: fully stop WiFi driver & clear WPA state
      task_sleep(200);
      if (mqttMesh) mqttMesh->toggleWiFi(true);
      lastConnected = millis();
    }

    task_sleep(10);
  }
}

}  // namespace

void MyMesh::saveWiFiPrefs() {
  File file = _fs->open("/wifi_prefs", "w", true);
  if (file) {
    file.write((const uint8_t*)&_wifi, sizeof(_wifi));
    file.close();
  }
  // Signal WiFiTask to restart WiFi with new credentials.
  // Do NOT call WiFi API here — loopTask and WiFiTask run on different cores
  // and concurrent WiFi API calls corrupt the WPA supplicant heap.
  wifiRestartRequested = true;
}

void MyMesh::saveMqttPrefs() {
  File file = _fs->open("/mqtt_prefs", "w", true);
  if (file) {
    file.write((const uint8_t*)&_mqttp, sizeof(_mqttp));
    file.close();
  }
}

void MyMesh::loadWiFiPrefs() {
  if (!_fs->exists("/wifi_prefs")) {
    return;
  }

  File file = _fs->open("/wifi_prefs");
  if (!file) {
    return;
  }

  int read = file.read((uint8_t*)&_wifi, sizeof(_wifi));
  file.close();
  if (read <= 97) {
    WiFiPrefs tmp;
    memcpy(&tmp, &_wifi, sizeof(_wifi));
    memcpy((uint8_t*)&_wifi + 1, &tmp, sizeof(_wifi) - 1);
    _wifi.version = 1;
    _wifi.txpower = WIFI_POWER_8_5dBm;
    saveWiFiPrefs();
  }
}

void MyMesh::loadMqttPrefs() {
  if (!_fs->exists("/mqtt_prefs")) {
    return;
  }

  File file = _fs->open("/mqtt_prefs");
  if (!file) {
    return;
  }

  size_t max_read = std::min((size_t)file.size(), sizeof(_mqttp));
  file.read((uint8_t*)&_mqttp, max_read);
  file.close();

  if (_mqttp.version < 2) {
    _mqttp.version = 2;
    if (_mqttp.ntp_server[0] == '\0') {
      strcpy(_mqttp.ntp_server, "pool.ntp.org");
    }
    saveMqttPrefs();
  }
  if (_mqttp.ntp_server[0] == '\0') {
    strcpy(_mqttp.ntp_server, "pool.ntp.org");
  }
}

void MyMesh::toggleWiFi(bool enable) {
  if (strlen(_wifi.ssid) < 1) {
    // No SSID configured — start as Access Point so WebUI is reachable
    if (enable) {
      const uint8_t* pk = getSelfId().pub_key;
      char pkHex[8];
      snprintf(pkHex, sizeof(pkHex), "%02x%02x%02x", pk[0], pk[1], pk[2]);
      pkHex[5] = '\0';  // first 5 hex chars only
      char apSsid[24];
      snprintf(apSsid, sizeof(apSsid), "repeater_%s", pkHex);
      WiFi.mode(WIFI_AP_STA);  // AP+STA so scan still works later
      WiFi.softAP(apSsid, apSsid);
    } else {
      WiFi.softAPdisconnect(true);
      WiFi.mode(WIFI_OFF);
    }
    return;
  }

  if (enable) {
    WiFi.mode(WIFI_STA);
    WiFi.config(INADDR_NONE, INADDR_NONE, INADDR_NONE,
                IPAddress(1, 1, 1, 1), IPAddress(8, 8, 8, 8));
    WiFi.begin(_wifi.ssid, _wifi.password);
    esp_wifi_set_max_tx_power(_wifi.txpower);
    WiFi.setAutoReconnect(true);
  } else {
    WiFi.disconnect(true);  // wifioff=true: fully stop WiFi driver & clear WPA state
  }
}

void MyMesh::mqttPublishPacket(mesh::Packet* pkt, int len, float snr, float rssi, float score) {
  if (!_mqttp.enabled || strlen(_mqttp.host) == 0) {
    return;
  }

  uint8_t rawBuf[MAX_TRANS_UNIT + 1];
  memset(rawBuf, 0, sizeof(rawBuf));
  uint8_t rawLen = pkt->writeTo(rawBuf);

  char rawHex[512];
  mesh::Utils::toHex(rawHex, rawBuf, rawLen);

  uint8_t hash[MAX_HASH_SIZE];
  pkt->calculatePacketHash(hash);
  char hashHex[MAX_HASH_SIZE * 2 + 1];
  mesh::Utils::toHex(hashHex, hash, MAX_HASH_SIZE);
  for (char* p = hashHex; *p; p++) {
    *p = toupper(*p);
  }

  char originId[PUB_KEY_SIZE * 2 + 1];
  mesh::Utils::toHex(originId, self_id.pub_key, PUB_KEY_SIZE);

  char iso_ts[32], time_str[12], date_str[16];
  formatMqttTimestamp(iso_ts, sizeof(iso_ts), time_str, sizeof(time_str), date_str, sizeof(date_str));

  char snrStr[8], rssiStr[8], scoreStr[8], lenStr[8], ptStr[8], plStr[8];
  snprintf(snrStr, sizeof(snrStr), "%d", (int)snr);
  snprintf(rssiStr, sizeof(rssiStr), "%d", (int)rssi);
  snprintf(scoreStr, sizeof(scoreStr), "%d", (int)(score * 1000));
  snprintf(lenStr, sizeof(lenStr), "%d", len);
  snprintf(ptStr, sizeof(ptStr), "%u", (unsigned)pkt->getPayloadType());
  snprintf(plStr, sizeof(plStr), "%u", (unsigned)pkt->payload_len);

  JsonDocument doc;
  doc["origin"] = _prefs.node_name;
  doc["origin_id"] = originId;
  doc["timestamp"] = iso_ts;
  doc["type"] = "PACKET";
  doc["direction"] = "rx";
  doc["time"] = time_str;
  doc["date"] = date_str;
  doc["len"] = lenStr;
  doc["packet_type"] = ptStr;
  doc["route"] = pkt->isRouteDirect() ? "D" : "F";
  doc["payload_len"] = plStr;
  doc["raw"] = rawHex;
  doc["SNR"] = snrStr;
  doc["RSSI"] = rssiStr;
  doc["score"] = scoreStr;
  doc["hash"] = hashHex;

  if (pkt->isRouteDirect()) {
    String pathStr = getMqttPath(pkt);
    if (pathStr.length() > 0) {
      doc["path"] = pathStr;
    }
  }

  char logLine[192];
  snprintf(logLine, sizeof(logLine),
           "RX, len=%d (type=%d, route=%s, payload_len=%d) SNR=%d RSSI=%d score=%d",
           len, pkt->getPayloadType(), pkt->isRouteDirect() ? "D" : "F", pkt->payload_len,
           (int)snr, (int)rssi, (int)(score * 1000));
  doc["log"] = logLine;

  char topic[220];
  snprintf(topic, sizeof(topic), "%s/%s/%s/packets", _mqttp.base_topic, _mqttp.iata, originId);
  String payload;
  serializeJson(doc, payload);
  mqttQueue.push(topic, payload);
  repeaterWebUiBroadcast(topic, payload.c_str());
}

void MyMesh::syncClock(uint32_t timestamp, bool ntp) {
  uint32_t curr = getRTCClock()->getCurrentTime();
  if (timestamp > curr || ntp) {
    getRTCClock()->setCurrentTime(timestamp);
    if (ntp) {
      ntpSynced = true;
    } else {
      timeval epoch = {(time_t)timestamp, 0};
      settimeofday((const timeval*)&epoch, 0);
    }
  }
}

void MyMesh::startMqttTask() {
  mqttMesh = this;
  mesh_set_debug_hook(mqttDebugHook);
  if (wifiTaskHandle != nullptr) {
    return;
  }

  WiFi.setHostname(_prefs.node_name);
  toggleWiFi(true);

  int core = xPortGetCoreID() == 1 ? 0 : 1;
  xTaskCreatePinnedToCore(WiFiTaskCode, "WiFiTask", 12000, nullptr, 1, &wifiTaskHandle, core);
}

bool repeaterMqttHasPendingWork(const MyMesh& mesh) {
  const MqttPrefs* mp = mesh.getMqttPrefs();
  const WiFiPrefs* wp = mesh.getWiFiPrefs();
  return ((mp->enabled && strlen(wp->ssid) > 0) || mqttQueue.size() > 0 || WiFi.status() == WL_CONNECTED);
}

void repeaterMqttRequestNtpResync() {
  ntpSynced = false;
  ntpNext = 0;
}

#endif

#if defined(ESP32) && defined(ENABLE_MQTT)

void repeaterMqttPublishDebugLine(const char* node_name, const uint8_t* pub_key,
                                  const char* line) {
  const char* base = "meshcore";
  const char* iata = "---";
  bool mqttActive = false;
  if (mqttMesh != nullptr) {
    const MqttPrefs* mp = mqttMesh->getMqttPrefs();
    mqttActive = mp->enabled && strlen(mp->host) > 0;
    if (mp->base_topic[0]) base = mp->base_topic;
    if (mp->iata[0])       iata = mp->iata;
  }

  char pubkeyHex[PUB_KEY_SIZE * 2 + 1];
  mesh::Utils::toHex(pubkeyHex, pub_key, PUB_KEY_SIZE);
  char iso_ts[32], time_str[12], date_str[16];
  formatMqttTimestamp(iso_ts, sizeof(iso_ts), time_str, sizeof(time_str),
                      date_str, sizeof(date_str));

  JsonDocument doc;
  doc["origin"] = node_name;
  doc["origin_id"] = pubkeyHex;
  doc["timestamp"] = iso_ts;
  doc["time"] = time_str;
  doc["date"] = date_str;
  doc["type"] = "DEBUG";
  doc["log"] = line;

  char topic[220];
  snprintf(topic, sizeof(topic), "%s/%s/%s/debug", base, iata, pubkeyHex);

  String payload;
  serializeJson(doc, payload);
  repeaterWebUiBroadcast(topic, payload.c_str());
  if (mqttActive) {
    mqttQueue.push(topic, payload);
  }
}

void repeaterMqttPublishRxRaw(const char* node_name, const uint8_t* pub_key,
                               float snr, float rssi, const uint8_t* raw, int len) {
  const char* base = "meshcore";
  const char* iata = "---";
  bool mqttActive = false;
  if (mqttMesh != nullptr) {
    const MqttPrefs* mp = mqttMesh->getMqttPrefs();
    mqttActive = mp->enabled && strlen(mp->host) > 0;
    if (mp->base_topic[0]) base = mp->base_topic;
    if (mp->iata[0])       iata = mp->iata;
  }

  char pubkeyHex[PUB_KEY_SIZE * 2 + 1];
  mesh::Utils::toHex(pubkeyHex, pub_key, PUB_KEY_SIZE);

  int hexLen = len > 255 ? 255 : len;
  char rawHex[512];
  mesh::Utils::toHex(rawHex, raw, hexLen);

  char iso_ts[32], time_str[12], date_str[16];
  formatMqttTimestamp(iso_ts, sizeof(iso_ts), time_str, sizeof(time_str),
                      date_str, sizeof(date_str));

  char snrStr[8], rssiStr[8], lenStr[8];
  snprintf(snrStr,  sizeof(snrStr),  "%d", (int)snr);
  snprintf(rssiStr, sizeof(rssiStr), "%d", (int)rssi);
  snprintf(lenStr,  sizeof(lenStr),  "%d", len);

  JsonDocument doc;
  doc["origin"]    = node_name;
  doc["origin_id"] = pubkeyHex;
  doc["timestamp"] = iso_ts;
  doc["type"]      = "RAWRX";
  doc["time"]      = time_str;
  doc["date"]      = date_str;
  doc["len"]       = lenStr;
  doc["raw"]       = rawHex;
  doc["SNR"]       = snrStr;
  doc["RSSI"]      = rssiStr;
  String rawLine = "RAW: ";
  rawLine += rawHex;
  doc["log"] = rawLine;

  char topic[220];
  snprintf(topic, sizeof(topic), "%s/%s/%s/debug", base, iata, pubkeyHex);

  String payload;
  serializeJson(doc, payload);
  repeaterWebUiBroadcast(topic, payload.c_str());
  if (mqttActive) {
    mqttQueue.push(topic, payload);
  }
}

#endif
