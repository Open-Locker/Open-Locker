#pragma once
#include "locker_storage.h"
typedef enum { LOCKER_MQTT_CONNECTED, LOCKER_MQTT_SUBSCRIBED, LOCKER_MQTT_MESSAGE } locker_mqtt_event_t;
/* Message pointers are borrowed for the duration of this callback only. */
typedef void (*locker_mqtt_callback_t)(locker_mqtt_event_t, int, const char *, const char *);
esp_err_t locker_mqtt_start(const locker_settings_t *settings, locker_mqtt_callback_t callback);
void locker_mqtt_stop(void);
int locker_mqtt_subscribe(const char *topic);
bool locker_mqtt_publish(const char *topic, cJSON *body, bool retain);
bool locker_mqtt_online(void);
cJSON *locker_envelope(cJSON *body);
