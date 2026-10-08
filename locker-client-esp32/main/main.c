#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "locker_core.h"
#include "locker_storage.h"
#include "locker_network.h"
#include "locker_mqtt.h"
#include "locker_setup.h"
#include "lock_bus.h"
#include "driver/uart.h"
#include "esp_log.h"
#include "esp_system.h"
#include "esp_timer.h"
#include "esp_task_wdt.h"

#include "psa/crypto.h"
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include "freertos/task.h"

static const char *TAG = "locker";
static locker_settings_t settings;
static lc_journal_t journal;
static lc_app_t application;
static QueueHandle_t inbox;
static bool mqtt_started, enrollment_sent, active;
static int subscription = -1;
static int64_t enrollment_deadline;

typedef struct {
    int kind, id;
    char topic[257];
    char *payload;
} input_t;

static bool hash(void *context, const char *text, char result[65])
{
    (void)context;
    uint8_t digest[32];
    size_t size;
    if (!text || psa_hash_compute(PSA_ALG_SHA_256, (const uint8_t *)text, strlen(text),
                                  digest, sizeof(digest), &size) != PSA_SUCCESS || size != 32) return false;
    for (size_t i = 0; i < 32; ++i) sprintf(result + i * 2, "%02x", digest[i]);
    return true;
}
static bool save_config(void *context, const lc_config_t *config)
{
    (void)context;
    return locker_storage_config(config) == ESP_OK;
}
static lc_unlock_t unlock(void *context, uint8_t board, unsigned address)
{
    (void)context;
    return lock_bus_unlock(board, address);
}
static bool read_states(void *context, uint8_t board, bool opening, lc_door_t states[255])
{
    (void)context;
    return lock_bus_read(board, opening, states);
}
static bool connected(void *context) { (void)context; return lock_bus_connected(); }
static bool publish(void *context, const char *suffix, cJSON *body, bool retain)
{
    (void)context;
    esp_task_wdt_reset();
    if (!active || !settings.enrolled || !locker_network_ready()) return false;
    char topic[200];
    snprintf(topic, sizeof(topic), "locker/%s/%s", settings.locker_uuid, suffix);
    bool result = locker_mqtt_publish(topic, body, retain);
    esp_task_wdt_reset();
    return result;
}

static void mqtt_callback(locker_mqtt_event_t event, int id, const char *topic, const char *payload)
{
    input_t *input = calloc(1, sizeof(*input));
    if (!input) return;
    input->kind = event;
    input->id = id;
    if (payload) {
        input->payload = strdup(payload);
        if (!input->payload) { free(input); return; }
        snprintf(input->topic, sizeof(input->topic), "%s", topic);
    }
    if (xQueueSend(inbox, &input, 0) != pdTRUE) {
        free(input->payload); free(input);
        ESP_LOGW(TAG, "Inbound queue full; no command executed");
    }
}

static const char *setup_status = "Waiting for USB setup.";
static void setup_set_status(const char *status) { setup_status = status; ESP_LOGI(TAG, "%s", status); }
static void usb_reply(const char *status)
{
    /* Machine-readable replies contain no credentials or submitted values. */
    printf("@OPENLOCKER {\"status\":\"%s\",\"enrolled\":%s,\"pending\":%s,\"configured\":%s,\"online\":%s}\n",
           status, settings.enrolled ? "true" : "false",
           settings.enrollment_pending ? "true" : "false", settings.configured ? "true" : "false",
           active && locker_mqtt_online() && locker_network_ready() ? "true" : "false");
    fflush(stdout);
}
static void usb_configure(const char *text)
{
    lc_setup_t values;
    if (!lc_setup_parse(text, settings.enrolled, settings.enrollment_pending, &values)) {
        usb_reply("invalid_settings_or_reset_required"); return;
    }
    locker_settings_t next = settings;
    strcpy(next.ssid, values.ssid); strcpy(next.wifi_password, values.wifi_password);
    strcpy(next.broker, values.broker);
    if (!settings.enrolled) {
        strcpy(next.bootstrap_user, values.bootstrap_user);
        strcpy(next.bootstrap_password, values.bootstrap_password); strcpy(next.token, values.token);
    }
    next.configured = true;
    memset(&values, 0, sizeof(values));
    application.closing = true;
    esp_err_t saved = locker_storage_settings(&next);
    memset(&next, 0, sizeof(next));
    if (saved != ESP_OK) {
        application.closing = false; usb_reply("save_failed"); return;
    }
    if (settings.enrolled && active) {
        cJSON *offline = cJSON_CreateObject();
        if (offline) {
            cJSON_AddStringToObject(offline, "status", "offline");
            cJSON_AddStringToObject(offline, "reason", "shutdown");
            publish(NULL, "state/connection", offline, false); cJSON_Delete(offline);
        }
    }
    usb_reply("saved");
    uart_wait_tx_done(UART_NUM_0, pdMS_TO_TICKS(1000));
    vTaskDelay(pdMS_TO_TICKS(100));
    esp_restart();
}

static bool namespace_uuid(const char *value)
{
    if (strlen(value) != 36) return false;
    for (size_t i = 0; i < 36; ++i) {
        if (i == 8 || i == 13 || i == 18 || i == 23) { if (value[i] != '-') return false; }
        else if (!strchr("0123456789abcdefABCDEF", value[i])) return false;
    }
    return true;
}
static void enrollment_reply(const char *payload)
{
    if (!enrollment_sent) return;
    cJSON *root = lc_parse_json(payload);
    char status[16], message_id[LC_ID_MAX + 1], timestamp[40];
    if (!lc_string(root, "status", status, sizeof(status)) ||
        !lc_string(root, "message_id", message_id, sizeof(message_id)) ||
        !lc_string(root, "timestamp", timestamp, sizeof(timestamp)) || !lc_timestamp(timestamp)) goto done;
    if (strcmp(status, "success")) {
        setup_set_status("Enrollment rejected. Reset provisioning in the backend, then run reset-network over USB.");
        enrollment_deadline = 0;
        goto done;
    }
    const cJSON *data = cJSON_GetObjectItemCaseSensitive(root, "data");
    locker_settings_t next = settings;
    if (!lc_string(data, "mqtt_user", next.username, sizeof(next.username)) ||
        !lc_string(data, "mqtt_password", next.password, sizeof(next.password)) ||
        !lc_string(data, "locker_uuid", next.locker_uuid, sizeof(next.locker_uuid)) || !namespace_uuid(next.locker_uuid)) goto done;
    next.enrolled = true;
    next.enrollment_pending = false;
    memset(next.token, 0, sizeof(next.token));
    memset(next.bootstrap_user, 0, sizeof(next.bootstrap_user));
    memset(next.bootstrap_password, 0, sizeof(next.bootstrap_password));
    if (locker_storage_settings(&next) != ESP_OK) {
        setup_set_status("Credential persistence failed. Backend reset and local recovery are required.");
        memset(&next, 0, sizeof(next));
        goto done;
    }
    settings = next;
    memset(&next, 0, sizeof(next));
    enrollment_sent = false;
    enrollment_deadline = 0;
    locker_mqtt_stop();
    mqtt_started = false;
    active = false;
    ESP_LOGI(TAG, "Enrollment saved; reconnecting with issued identity");
done:
    cJSON_Delete(root);
}

static void input(input_t *event)
{
    if (event->kind == LOCKER_MQTT_CONNECTED) {
        active = false;
        char topic[180];
        if (settings.enrolled) snprintf(topic, sizeof(topic), "locker/%s/command", settings.locker_uuid);
        else snprintf(topic, sizeof(topic), "locker/provisioning/reply/%s", settings.client_id);
        subscription = locker_mqtt_subscribe(topic);
    } else if (event->kind == LOCKER_MQTT_SUBSCRIBED && event->id == subscription) {
        if (settings.enrolled) {
            active = true;
            application.force_snapshot = true;
            application.next_heartbeat = 0;
            lc_app_flush(&application);
            setup_set_status("Connected and enrolled.");
        } else if (!settings.enrollment_pending && !enrollment_sent) {
            /* Commit uncertainty before publishing the one-time token. A lost
             * reply cannot be safely recovered by guessing token validity. */
            settings.enrollment_pending = true;
            if (locker_storage_settings(&settings) != ESP_OK) return;
            enrollment_sent = true;
            enrollment_deadline = esp_timer_get_time() + 30000000;
            char topic[160];
            snprintf(topic, sizeof(topic), "locker/register/%s", settings.token);
            cJSON *request = cJSON_CreateObject();
            cJSON_AddStringToObject(request, "client_id", settings.client_id);
            locker_mqtt_publish(topic, request, false);
            cJSON_Delete(request);
            setup_set_status("Waiting for backend enrollment reply.");
        }
    } else if (event->kind == LOCKER_MQTT_MESSAGE) {
        char topic[180];
        if (settings.enrolled) {
            snprintf(topic, sizeof(topic), "locker/%s/command", settings.locker_uuid);
            if (active && !strcmp(event->topic, topic))
                lc_app_dispatch(&application, event->payload, esp_timer_get_time() / 1000);
        } else {
            snprintf(topic, sizeof(topic), "locker/provisioning/reply/%s", settings.client_id);
            if (!strcmp(event->topic, topic)) enrollment_reply(event->payload);
        }
    }
}

static void console(void)
{
    static lc_usb_line_t buffer;
    static int64_t last_byte;
    uint8_t ch;
    if (buffer.length && esp_timer_get_time() - last_byte > 10000000) {
        memset(buffer.line, 0, sizeof(buffer.line)); buffer.length = 0; buffer.discard = true;
    }
    /* Bound work per pass so serial flooding cannot starve MQTT or watchdogs. */
    for (unsigned bytes = 0; bytes < 256 && uart_read_bytes(UART_NUM_0, &ch, 1, 0) == 1; ++bytes) {
        last_byte = esp_timer_get_time();
        int result = lc_usb_feed(&buffer, ch);
        if (result < 0) { usb_reply("invalid_line"); continue; }
        if (result == 1) {
            char *line = buffer.line;
            if (!strcmp(line, "setup") || !strcmp(line, "status")) {
                usb_reply("ready");
                ESP_LOGI(TAG, "%s", setup_status);
            } else if (!strncmp(line, "configure ", 10)) {
                usb_configure(line + 10);
            } else if (!strcmp(line, "reset-network")) {
                application.closing = true;
                if (locker_storage_reset_network(&settings) == ESP_OK) esp_restart();
                ESP_LOGE(TAG, "Reset persistence failed; actuation remains disabled");
            } else if (!strcmp(line, "diagnostics")) {
                ESP_LOGI(TAG, "heap=%lu bytes, min_heap=%lu, stack=%u, journal commands=%u, slots=%u, healthy=%d, panel=%d, unlock_tx=%lu",
                    (unsigned long)esp_get_free_heap_size(), (unsigned long)esp_get_minimum_free_heap_size(),
                    (unsigned)uxTaskGetStackHighWaterMark(NULL), (unsigned)journal.count,
                    (unsigned)journal.next_slot, journal.healthy, lock_bus_connected(),
                    (unsigned long)lock_bus_unlock_transmissions());
            } else if (!strcmp(line, "self-test")) {
                uint8_t frame[5];
                char digest[65];
                bool ok = lc_encode(1, 0, true, frame) &&
                    !memcmp(frame, (uint8_t[]){0x8a,1,1,0x11,0x9b}, 5) &&
                    lc_ack((uint8_t[]){0x8a,1,1,0,0x8a}, 5, 1, 0) &&
                    hash(NULL, "abc", digest) &&
                    !strcmp(digest, "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad");
                ESP_LOGI(TAG, "Self-test %s; no panel command sent", ok ? "passed" : "failed");
            } else {
                usb_reply("unknown_command");
            }
            memset(buffer.line, 0, sizeof(buffer.line));
        }
    }
}

void app_main(void)
{
    ESP_ERROR_CHECK(psa_crypto_init() == PSA_SUCCESS ? ESP_OK : ESP_FAIL);
    lc_config_t config;
    ESP_ERROR_CHECK(locker_storage_init(&settings, &config, &journal));
    if (config.applied) {
        cJSON *saved = lc_config_json(&config);
        cJSON_AddNumberToObject(saved, "heartbeat_interval_seconds", config.heartbeat_seconds);
        cJSON_AddStringToObject(saved, "config_hash", config.hash);
        lc_config_t checked;
        char *canonical = NULL, digest[65];
        bool valid = lc_parse_config(saved, &checked, &canonical) && hash(NULL, canonical, digest) && !strcmp(digest, checked.hash);
        free(canonical); cJSON_Delete(saved);
        ESP_ERROR_CHECK(valid ? ESP_OK : ESP_ERR_INVALID_STATE);
        config = checked;
    }
    ESP_ERROR_CHECK(lock_bus_start());
    inbox = xQueueCreate(8, sizeof(input_t *));
    ESP_ERROR_CHECK(inbox ? ESP_OK : ESP_ERR_NO_MEM);
    ESP_ERROR_CHECK(locker_network_init(&settings));
    if (settings.configured && !settings.enrolled)
        setup_set_status("Connecting to Wi-Fi and synchronizing time. Update network settings if this persists.");
    ESP_ERROR_CHECK(uart_driver_install(UART_NUM_0, 4096, 0, 0, NULL, 0));
    ESP_ERROR_CHECK(esp_task_wdt_add(NULL));
    lc_app_ports_t ports = {NULL, hash, save_config, unlock, read_states, publish, connected};
    lc_app_init(&application, ports, &journal, &config, esp_timer_get_time() / 1000);
    lc_app_recover(&application);
    if (settings.enrollment_pending)
        setup_set_status("Enrollment outcome unknown. Reset backend provisioning, then run reset-network over USB.");
    ESP_LOGI(TAG, "No startup unlock. USB commands: setup, status, configure <JSON>, reset-network, diagnostics, self-test");
    int64_t next_poll = 0, next_flush = 0;
    for (;;) {
        esp_task_wdt_reset();
        console();
        if (settings.configured && locker_network_ready() && !mqtt_started &&
            (settings.enrolled || !settings.enrollment_pending)) {
            esp_err_t err = locker_mqtt_start(&settings, mqtt_callback);
            if (err == ESP_OK) mqtt_started = true;
            else vTaskDelay(pdMS_TO_TICKS(1000));
        }
        input_t *event;
        if (xQueueReceive(inbox, &event, pdMS_TO_TICKS(20)) == pdTRUE) {
            input(event);
            if (event->payload) { memset(event->payload, 0, strlen(event->payload)); free(event->payload); }
            free(event);
        }
        int64_t now = esp_timer_get_time() / 1000;
        if (active && locker_mqtt_online() && now >= next_poll) {
            lc_app_poll(&application, now);
            next_poll = esp_timer_get_time() / 1000 + 500;
        }
        if (active && locker_mqtt_online() && now >= next_flush) {
            lc_app_flush(&application);
            next_flush = esp_timer_get_time() / 1000 + 5000;
        }
        if (enrollment_deadline && esp_timer_get_time() > enrollment_deadline) {
            setup_set_status("Enrollment reply missing. Reset backend provisioning, then run reset-network over USB.");
            enrollment_deadline = 0;
            ESP_LOGW(TAG, "Enrollment outcome unknown; local/operator recovery required");
        }
    }
}
