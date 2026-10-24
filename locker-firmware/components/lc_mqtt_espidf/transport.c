#include "lc_mqtt_espidf/transport.h"

#include <string.h>

#include "esp_crt_bundle.h"
#include "esp_log.h"
#include "mqtt_client.h"
#include "sdkconfig.h"

/* Verification of the broker is not a setting of this firmware. */
#if defined(CONFIG_ESP_TLS_INSECURE) || defined(CONFIG_ESP_TLS_SKIP_SERVER_CERT_VERIFY)
#error "TLS certificate verification must stay on: unset ESP_TLS_INSECURE in sdkconfig"
#endif

#define TOPIC_MAX 192
#define BUFFER_SIZE 2048
#define TASK_STACK_SIZE 8192
#define TASK_PRIORITY 5
#define RECONNECT_MS 5000
#define NETWORK_TIMEOUT_MS 10000

static const char *TAG = "lc_mqtt";

static esp_mqtt_client_handle_t client;
static lc_mqtt_handlers_t handlers;

static void report_error(const esp_mqtt_error_codes_t *error)
{
    if (error == NULL) {
        return;
    }
    if (error->error_type == MQTT_ERROR_TYPE_TCP_TRANSPORT) {
        /* A failed certificate check shows up here, as a TLS stack error. */
        ESP_LOGW(TAG, "transport error: tls=0x%x stack=0x%x errno=%d",
                 (unsigned)error->esp_tls_last_esp_err, (unsigned)error->esp_tls_stack_err,
                 error->esp_transport_sock_errno);
    } else if (error->error_type == MQTT_ERROR_TYPE_CONNECTION_REFUSED) {
        ESP_LOGW(TAG, "the broker refused the connection (code %d)", (int)error->connect_return_code);
    }
}

static void on_event(void *args, esp_event_base_t base, int32_t id, void *data)
{
    (void)args;
    (void)base;
    esp_mqtt_event_handle_t event = data;

    switch ((esp_mqtt_event_id_t)id) {
    case MQTT_EVENT_CONNECTED:
        if (handlers.on_connected != NULL) {
            handlers.on_connected(handlers.ctx);
        }
        break;
    case MQTT_EVENT_DISCONNECTED:
        if (handlers.on_disconnected != NULL) {
            handlers.on_disconnected(handlers.ctx);
        }
        break;
    case MQTT_EVENT_SUBSCRIBED:
        /* esp-mqtt raises this for a refused SUBACK (0x80) too, and only flags it here. */
        if (event->error_handle != NULL &&
            event->error_handle->error_type == MQTT_ERROR_TYPE_SUBSCRIBE_FAILED) {
            ESP_LOGE(TAG, "the broker refused subscription %d (check its ACL)", event->msg_id);
            break;
        }
        if (handlers.on_subscribed != NULL) {
            handlers.on_subscribed(handlers.ctx, event->msg_id);
        }
        break;
    case MQTT_EVENT_PUBLISHED:
        if (handlers.on_published != NULL) {
            handlers.on_published(handlers.ctx, event->msg_id);
        }
        break;
    case MQTT_EVENT_DELETED:
        if (handlers.on_dropped != NULL) {
            handlers.on_dropped(handlers.ctx, event->msg_id);
        }
        break;
    case MQTT_EVENT_DATA: {
        char topic[TOPIC_MAX + 1];
        const char *topic_or_null = NULL;
        if (event->topic != NULL) {
            /* Only the first fragment carries the topic. One that does not fit is no topic of ours. */
            size_t length = event->topic_len < 0 ? 0 : (size_t)event->topic_len;
            if (length > TOPIC_MAX) {
                break;
            }
            memcpy(topic, event->topic, length);
            topic[length] = '\0';
            topic_or_null = topic;
        }
        if (handlers.on_data != NULL && event->data_len >= 0 && event->total_data_len >= 0 &&
            event->current_data_offset >= 0) {
            handlers.on_data(handlers.ctx, topic_or_null, event->data, (size_t)event->data_len,
                             (size_t)event->current_data_offset, (size_t)event->total_data_len);
        }
        break;
    }
    case MQTT_EVENT_ERROR:
        report_error(event->error_handle);
        break;
    case MQTT_EVENT_ANY:
    case MQTT_EVENT_BEFORE_CONNECT:
    case MQTT_EVENT_UNSUBSCRIBED:
    default:
        break;
    }
}

bool lc_mqtt_transport_start(const lc_mqtt_transport_config_t *config,
                             const lc_mqtt_handlers_t *event_handlers)
{
    if (client != NULL) {
        return false;
    }
    bool plaintext = strncmp(config->broker_uri, "mqtts://", 8) != 0;
    if (plaintext) {
#if defined(CONFIG_LC_ALLOW_PLAINTEXT_MQTT)
        ESP_LOGW(TAG, "DEVELOPMENT BUILD: connecting without TLS, credentials travel in clear");
#else
        /* Also catches a URL a development build stored before a release was flashed. */
        ESP_LOGE(TAG, "refusing a broker URL that is not mqtts://");
        return false;
#endif
    }
    handlers = *event_handlers;

    const esp_mqtt_client_config_t mqtt = {
        .broker = {
            .address.uri = config->broker_uri,
            .verification.crt_bundle_attach = plaintext ? NULL : esp_crt_bundle_attach,
        },
        .credentials = {
            .username = config->username,
            .client_id = config->client_id,
            .authentication.password = config->password,
        },
        .session = {
            .keepalive = (int)config->keepalive_seconds,
            /* The broker keeps the session. Commands queued while away arrive before the
             * subscription is confirmed and are dropped by design (S3-H11). */
            .disable_clean_session = true,
            .last_will = {
                .topic = config->will_topic,
                .msg = config->will_payload,
                .msg_len = (int)config->will_length,
                .qos = 1,
                .retain = 0,
            },
            .protocol_ver = MQTT_PROTOCOL_V_3_1_1,
        },
        .network = {
            .reconnect_timeout_ms = RECONNECT_MS,
            .timeout_ms = NETWORK_TIMEOUT_MS,
        },
        .buffer = {
            .size = BUFFER_SIZE,
            .out_size = BUFFER_SIZE,
        },
        .task = {
            .priority = TASK_PRIORITY,
            .stack_size = TASK_STACK_SIZE,
        },
    };

    client = esp_mqtt_client_init(&mqtt);
    if (client == NULL) {
        ESP_LOGE(TAG, "client could not be created");
        return false;
    }
    if (esp_mqtt_client_register_event(client, ESP_EVENT_ANY_ID, on_event, NULL) != ESP_OK ||
        esp_mqtt_client_start(client) != ESP_OK) {
        ESP_LOGE(TAG, "client could not be started");
        esp_mqtt_client_destroy(client);
        client = NULL;
        return false;
    }
    return true;
}

int lc_mqtt_transport_subscribe(const char *topic, int qos)
{
    return client == NULL ? -1 : esp_mqtt_client_subscribe_single(client, topic, qos);
}

int lc_mqtt_transport_publish(const char *topic, const char *payload, size_t length, int qos,
                              bool retain)
{
    if (client == NULL) {
        return -1;
    }
    /* Stored in the outbox, so it survives a short loss of the connection. */
    return esp_mqtt_client_enqueue(client, topic, payload, (int)length, qos, retain ? 1 : 0, true);
}
