#pragma once

#include "MyMesh.h"

#if defined(ESP32) && defined(ENABLE_MQTT)
bool repeaterMqttHasPendingWork(const MyMesh& mesh);
void repeaterMqttRequestNtpResync();
void repeaterMqttPublishDebugLine(const char* node_name, const uint8_t* pub_key,
                                  const char* line);
void repeaterMqttPublishRxRaw(const char* node_name, const uint8_t* pub_key,
							   float snr, float rssi, const uint8_t* raw, int len);
#else
inline bool repeaterMqttHasPendingWork(const MyMesh&) { return false; }
inline void repeaterMqttRequestNtpResync() {}
inline void repeaterMqttPublishDebugLine(const char*, const uint8_t*, const char*) {}
inline void repeaterMqttPublishRxRaw(const char*, const uint8_t*, float, float, const uint8_t*, int) {}
#endif
