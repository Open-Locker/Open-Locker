#pragma once

/*
 * The part of esp-mqtt (ESP-IDF 5.4) that lc_mqtt_espidf/transport.c uses, so
 * the adapter can be compiled and driven on the host. Field names match the SDK.
 */

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

typedef int esp_err_t;
#define ESP_OK 0
#define ESP_FAIL -1

typedef const char *esp_event_base_t;
#define ESP_EVENT_ANY_ID -1
typedef void (*esp_event_handler_t)(void *args, esp_event_base_t base, int32_t id, void *data);

typedef enum {
    MQTT_EVENT_ANY = -1,
    MQTT_EVENT_ERROR = 0,
    MQTT_EVENT_CONNECTED,
    MQTT_EVENT_DISCONNECTED,
    MQTT_EVENT_SUBSCRIBED,
    MQTT_EVENT_UNSUBSCRIBED,
    MQTT_EVENT_PUBLISHED,
    MQTT_EVENT_DATA,
    MQTT_EVENT_BEFORE_CONNECT,
    MQTT_EVENT_DELETED,
} esp_mqtt_event_id_t;

typedef enum {
    MQTT_ERROR_TYPE_NONE = 0,
    MQTT_ERROR_TYPE_TCP_TRANSPORT,
    MQTT_ERROR_TYPE_CONNECTION_REFUSED,
    MQTT_ERROR_TYPE_SUBSCRIBE_FAILED,
} esp_mqtt_error_type_t;

typedef struct {
    esp_err_t esp_tls_last_esp_err;
    int esp_tls_stack_err;
    int esp_tls_cert_verify_flags;
    esp_mqtt_error_type_t error_type;
    int connect_return_code;
    int esp_transport_sock_errno;
} esp_mqtt_error_codes_t;

typedef struct esp_mqtt_client *esp_mqtt_client_handle_t;

typedef struct {
    esp_mqtt_event_id_t event_id;
    esp_mqtt_client_handle_t client;
    char *data;
    int data_len;
    int total_data_len;
    int current_data_offset;
    char *topic;
    int topic_len;
    int msg_id;
    esp_mqtt_error_codes_t *error_handle;
} esp_mqtt_event_t;
typedef esp_mqtt_event_t *esp_mqtt_event_handle_t;

typedef enum {
    MQTT_PROTOCOL_UNDEFINED = 0,
    MQTT_PROTOCOL_V_3_1,
    MQTT_PROTOCOL_V_3_1_1,
    MQTT_PROTOCOL_V_5,
} esp_mqtt_protocol_ver_t;

typedef struct {
    struct {
        struct {
            const char *uri;
        } address;
        struct {
            esp_err_t (*crt_bundle_attach)(void *conf);
        } verification;
    } broker;
    struct {
        const char *username;
        const char *client_id;
        struct {
            const char *password;
        } authentication;
    } credentials;
    struct {
        struct {
            const char *topic;
            const char *msg;
            int msg_len;
            int qos;
            int retain;
        } last_will;
        bool disable_clean_session;
        int keepalive;
        esp_mqtt_protocol_ver_t protocol_ver;
    } session;
    struct {
        int reconnect_timeout_ms;
        int timeout_ms;
    } network;
    struct {
        int priority;
        int stack_size;
    } task;
    struct {
        int size;
        int out_size;
    } buffer;
} esp_mqtt_client_config_t;

esp_mqtt_client_handle_t esp_mqtt_client_init(const esp_mqtt_client_config_t *config);
esp_err_t esp_mqtt_client_register_event(esp_mqtt_client_handle_t client,
                                         esp_mqtt_event_id_t event, esp_event_handler_t handler,
                                         void *handler_args);
esp_err_t esp_mqtt_client_start(esp_mqtt_client_handle_t client);
esp_err_t esp_mqtt_client_destroy(esp_mqtt_client_handle_t client);
int esp_mqtt_client_subscribe_single(esp_mqtt_client_handle_t client, const char *topic, int qos);
int esp_mqtt_client_enqueue(esp_mqtt_client_handle_t client, const char *topic, const char *data,
                            int len, int qos, int retain, bool store);
