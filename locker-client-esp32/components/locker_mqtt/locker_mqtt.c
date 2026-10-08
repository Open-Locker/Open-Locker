#include "locker_mqtt.h"
#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <time.h>
#include <stdatomic.h>
#include "esp_crt_bundle.h"
#include "esp_random.h"
#include "esp_log.h"
#include "mqtt_client.h"
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"

static esp_mqtt_client_handle_t client;
static QueueHandle_t acknowledgements;
static locker_mqtt_callback_t notify;
static lc_assembler_t assembly;
static char incoming_topic[257];
static atomic_bool online;

cJSON *locker_envelope(cJSON *body)
{
    if (!body) return NULL;
    uint8_t bytes[16];
    esp_fill_random(bytes, sizeof(bytes));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    char id[37], timestamp[32];
    snprintf(id, sizeof(id), "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
        bytes[0], bytes[1], bytes[2], bytes[3], bytes[4], bytes[5], bytes[6], bytes[7],
        bytes[8], bytes[9], bytes[10], bytes[11], bytes[12], bytes[13], bytes[14], bytes[15]);
    time_t now = time(NULL);
    struct tm tm;
    gmtime_r(&now, &tm);
    strftime(timestamp, sizeof(timestamp), "%Y-%m-%dT%H:%M:%SZ", &tm);
    cJSON_AddStringToObject(body, "message_id", id);
    cJSON_AddStringToObject(body, "timestamp", timestamp);
    return body;
}

static void mqtt_event(void *arg, esp_event_base_t base, int32_t event_id, void *event_data)
{
    (void)arg; (void)base;
    esp_mqtt_event_handle_t event = event_data;
    switch (event_id) {
    case MQTT_EVENT_CONNECTED:
        online = true;
        notify(LOCKER_MQTT_CONNECTED, 0, NULL, NULL);
        break;
    case MQTT_EVENT_DISCONNECTED:
        online = false;
        assembly.active = false;
        break;
    case MQTT_EVENT_SUBSCRIBED:
        notify(LOCKER_MQTT_SUBSCRIBED, event->msg_id, NULL, NULL);
        break;
    case MQTT_EVENT_PUBLISHED:
        xQueueSend(acknowledgements, &event->msg_id, 0);
        break;
    case MQTT_EVENT_DATA:
        if (event->current_data_offset == 0) {
            incoming_topic[0] = 0;
            assembly.active = false;
            if (event->topic_len < 1 || event->topic_len >= (int)sizeof(incoming_topic)) break;
            memcpy(incoming_topic, event->topic, (size_t)event->topic_len);
            incoming_topic[event->topic_len] = 0;
        }
        if (!*incoming_topic || event->data_len < 0 || event->current_data_offset < 0 || event->total_data_len < 0) break;
        if (lc_assemble(&assembly, event->data, (size_t)event->data_len,
                        (size_t)event->current_data_offset, (size_t)event->total_data_len))
            notify(LOCKER_MQTT_MESSAGE, event->msg_id, incoming_topic, assembly.data);
        break;
    default: break;
    }
}

esp_err_t locker_mqtt_start(const locker_settings_t *s, locker_mqtt_callback_t callback)
{
    notify = callback;
    acknowledgements = xQueueCreate(16, sizeof(int));
    if (!acknowledgements) return ESP_ERR_NO_MEM;
    char will_topic[180] = {0};
    char *will_body = NULL;
    if (s->enrolled) {
        snprintf(will_topic, sizeof(will_topic), "locker/%s/state/connection", s->locker_uuid);
        cJSON *will = cJSON_CreateObject();
        cJSON_AddStringToObject(will, "status", "offline");
        cJSON_AddStringToObject(will, "reason", "mqtt_last_will");
        locker_envelope(will);
        will_body = cJSON_PrintUnformatted(will);
        cJSON_Delete(will);
    }
    esp_mqtt_client_config_t cfg = {
        .broker.address.uri = s->broker,
        .broker.verification.crt_bundle_attach = esp_crt_bundle_attach,
        .credentials.client_id = s->client_id,
        .credentials.username = s->enrolled ? s->username : s->bootstrap_user,
        .credentials.authentication.password = s->enrolled ? s->password : s->bootstrap_password,
        .session.disable_clean_session = true,
        .session.protocol_ver = MQTT_PROTOCOL_V_3_1_1,
        .session.keepalive = 60,
        .session.last_will.topic = s->enrolled ? will_topic : NULL,
        .session.last_will.msg = will_body,
        .session.last_will.qos = 1,
        .session.last_will.retain = 0,
        .network.reconnect_timeout_ms = 5000,
        .network.timeout_ms = 5000,
        .buffer.size = 1024,
        .outbox.limit = 16384,
    };
    /* Library errors can include secret-bearing registration topic strings. */
    esp_log_level_set("mqtt_client", ESP_LOG_NONE);
    client = esp_mqtt_client_init(&cfg);
    free(will_body);
    if (!client) { vQueueDelete(acknowledgements); acknowledgements = NULL; return ESP_ERR_NO_MEM; }
    esp_err_t err = esp_mqtt_client_register_event(client, ESP_EVENT_ANY_ID, mqtt_event, NULL);
    if (err == ESP_OK) err = esp_mqtt_client_start(client);
    if (err != ESP_OK) locker_mqtt_stop();
    return err;
}
void locker_mqtt_stop(void)
{
    online = false;
    if (client) { esp_mqtt_client_stop(client); esp_mqtt_client_destroy(client); client = NULL; }
    if (acknowledgements) { vQueueDelete(acknowledgements); acknowledgements = NULL; }
    assembly.active = false;
}
int locker_mqtt_subscribe(const char *topic)
{
    return client && online ? esp_mqtt_client_subscribe(client, topic, 1) : -1;
}
bool locker_mqtt_publish(const char *topic, cJSON *body, bool retain)
{
    if (!online || !client || !body) return false;
    cJSON *envelope = cJSON_Duplicate(body, true);
    if (!envelope) return false;
    locker_envelope(envelope);
    char *json = cJSON_PrintUnformatted(envelope);
    cJSON_Delete(envelope);
    if (!json) return false;
    /* One publishing application task. Clear old ACKs before enqueue; an ACK
     * arriving before enqueue returns stays queued and is still matched by ID. */
    xQueueReset(acknowledgements);
    int id = esp_mqtt_client_enqueue(client, topic, json, 0, 1, retain, true);
    free(json);
    if (id < 0) return false;
    TickType_t remaining = pdMS_TO_TICKS(5000);
    TimeOut_t timeout;
    vTaskSetTimeOutState(&timeout);
    int acknowledged;
    while (xQueueReceive(acknowledgements, &acknowledged, remaining) == pdTRUE) {
        if (acknowledged == id) return true;
        if (xTaskCheckForTimeOut(&timeout, &remaining) == pdTRUE) break;
    }
    return false;
}
bool locker_mqtt_online(void) { return online; }
