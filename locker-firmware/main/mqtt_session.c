#include <string.h>

#include "esp_log.h"
#include "esp_random.h"
#include "lc_mqtt_codec/codec.h"
#include "lc_mqtt_espidf/transport.h"
#include "lc_net_wifi/wifi.h"
#include "runtime.h"

/*
 * Puts the codec and the MQTT transport together: typed messages out as JSON
 * on their topics, inbound messages decoded and posted to the owner task. The
 * outbound and gateway functions are called from the owner task only.
 */

#define KEEPALIVE_SECONDS 30
/* Largest message sent: a snapshot of 64 compartments is about 3.5 KB. */
#define ENCODE_BUFFER_SIZE 4608

static const char *TAG = "mqtt_session";

static mqtt_session_config_t session;
static char encoded[ENCODE_BUFFER_SIZE];
static int reply_subscription = -1;

/* The inbound message in progress; the esp-mqtt task alone touches these. */
static lc_mqtt_assembler_t assembler;
static lc_inbound_t inbound_kind = LC_INBOUND_OTHER;

/* ---- Inbound, on the esp-mqtt task ---- */

static void post(const owner_event_t *event)
{
    if (!owner_post(event)) {
        /* Dropped: no unlock for a lost command, and the backend times out. */
        ESP_LOGW(TAG, "owner queue full, event %d dropped", (int)event->kind);
    }
}

static void on_connected(void *ctx)
{
    (void)ctx;
    post(&(owner_event_t){.kind = OWNER_TRANSPORT_CONNECTED});
}

static void on_disconnected(void *ctx)
{
    (void)ctx;
    post(&(owner_event_t){.kind = OWNER_TRANSPORT_DISCONNECTED});
}

static void on_subscribed(void *ctx, int message_id)
{
    (void)ctx;
    owner_event_t event = {.kind = OWNER_SUBSCRIBED};
    event.u.message_id = message_id;
    post(&event);
}

static void on_publish_result(lc_event_kind_t kind, int message_id)
{
    owner_event_t event = {.kind = OWNER_APP};
    event.u.app.kind = kind;
    event.u.app.publish_id = (lc_publish_id_t)message_id;
    post(&event);
}

static void on_published(void *ctx, int message_id)
{
    (void)ctx;
    on_publish_result(LC_EVENT_PUBLISH_ACKNOWLEDGED, message_id);
}

static void on_dropped(void *ctx, int message_id)
{
    (void)ctx;
    on_publish_result(LC_EVENT_PUBLISH_FAILED, message_id);
}

static void on_data(void *ctx, const char *topic, const char *data, size_t length, size_t offset,
                    size_t total)
{
    (void)ctx;

    if (topic != NULL) {
        inbound_kind = lc_mqtt_classify(topic, session.locker_uuid, session.client_id);
    }
    if (inbound_kind == LC_INBOUND_OTHER ||
        !lc_mqtt_assemble(&assembler, data, length, offset, total)) {
        return;
    }

    owner_event_t event;
    memset(&event, 0, sizeof(event));
    if (inbound_kind == LC_INBOUND_COMMAND) {
        event.kind = OWNER_APP;
        event.u.app.kind = LC_EVENT_COMMAND;
        /* A payload nobody can answer is dropped here, as the codec defines. */
        if (!lc_mqtt_decode_command(assembler.data, assembler.total, &event.u.app.command)) {
            return;
        }
    } else {
        event.kind = OWNER_PROVISION_REPLY;
        if (!lc_mqtt_decode_provisioning_reply(assembler.data, assembler.total, &event.u.reply)) {
            return;
        }
    }
    post(&event);
}

/* ---- Outbound, on the owner task ---- */

static lc_mqtt_envelope_t envelope(void)
{
    uint8_t random[16];
    lc_mqtt_envelope_t result;

    esp_fill_random(random, sizeof(random));
    lc_mqtt_envelope(random, lc_wifi_unix_seconds(), &result);
    return result;
}

/* Sends what the last encoder wrote. */
static lc_publish_id_t publish(lc_topic_t topic, size_t length)
{
    char name[LC_MQTT_TOPIC_MAX + 1];

    if (length == 0 || !lc_mqtt_topic(topic, session.locker_uuid, name, sizeof(name))) {
        return LC_PUBLISH_REFUSED;
    }
    int id = lc_mqtt_transport_publish(name, encoded, length, 1, lc_mqtt_is_retained(topic));
    return id > 0 ? (lc_publish_id_t)id : LC_PUBLISH_REFUSED;
}

static lc_publish_id_t out_response(void *ctx, const lc_command_result_t *result)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return publish(LC_TOPIC_RESPONSE,
                   lc_mqtt_encode_response(result, &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_heartbeat(void *ctx, uint32_t uptime_seconds, bool bus_connected)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return publish(LC_TOPIC_HEARTBEAT, lc_mqtt_encode_heartbeat(uptime_seconds, bus_connected,
                                                                &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_snapshot(void *ctx, const lc_compartment_state_t *states, size_t count)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return publish(LC_TOPIC_SNAPSHOT,
                   lc_mqtt_encode_snapshot(states, count, &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_open_detection(void *ctx, const lc_open_detection_t *event)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return publish(LC_TOPIC_EVENT,
                   lc_mqtt_encode_open_detection(event, &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_uncommanded_open(void *ctx, const lc_uncommanded_open_t *event)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return publish(LC_TOPIC_EVENT,
                   lc_mqtt_encode_uncommanded_open(event, &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_connection_offline(void *ctx, lc_offline_reason_t reason)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return publish(LC_TOPIC_CONNECTION,
                   lc_mqtt_encode_connection_offline(reason, &stamp, encoded, sizeof(encoded)));
}

lc_outbound_port_t mqtt_session_outbound(void)
{
    return (lc_outbound_port_t){
        .ctx = NULL,
        .command_response = out_response,
        .heartbeat = out_heartbeat,
        .compartment_snapshot = out_snapshot,
        .open_detection = out_open_detection,
        .uncommanded_open = out_uncommanded_open,
        .connection_offline = out_connection_offline,
    };
}

/* ---- Enrollment ---- */

static bool gateway_subscribe_reply(void *ctx, const char *client_id)
{
    (void)ctx;
    char name[LC_MQTT_TOPIC_MAX + 1];

    if (!lc_mqtt_provisioning_reply_topic(client_id, name, sizeof(name))) {
        return false;
    }
    reply_subscription = lc_mqtt_transport_subscribe(name, 1);
    return reply_subscription >= 0;
}

static bool gateway_publish_registration(void *ctx, const char *token, const char *client_id)
{
    (void)ctx;
    char name[LC_MQTT_TOPIC_MAX + 1];
    const lc_mqtt_envelope_t stamp = envelope();

    size_t length = lc_mqtt_encode_registration(client_id, &stamp, encoded, sizeof(encoded));
    if (length == 0 || !lc_mqtt_registration_topic(token, name, sizeof(name))) {
        return false;
    }
    return lc_mqtt_transport_publish(name, encoded, length, 1, false) > 0;
}

lc_provisioning_gateway_port_t mqtt_session_gateway(void)
{
    return (lc_provisioning_gateway_port_t){
        .ctx = NULL,
        .subscribe_reply = gateway_subscribe_reply,
        .publish_registration = gateway_publish_registration,
    };
}

int mqtt_session_reply_subscription(void)
{
    return reply_subscription;
}

int mqtt_session_subscribe_commands(void)
{
    char name[LC_MQTT_TOPIC_MAX + 1];

    if (!lc_mqtt_topic(LC_TOPIC_COMMAND, session.locker_uuid, name, sizeof(name))) {
        return -1;
    }
    return lc_mqtt_transport_subscribe(name, 1);
}

/* ---- Start ---- */

bool mqtt_session_start(const mqtt_session_config_t *config)
{
    const lc_mqtt_handlers_t handlers = {
        .ctx = NULL,
        .on_connected = on_connected,
        .on_disconnected = on_disconnected,
        .on_subscribed = on_subscribed,
        .on_published = on_published,
        .on_dropped = on_dropped,
        .on_data = on_data,
    };
    lc_mqtt_transport_config_t transport = {
        .broker_uri = config->broker_uri,
        .client_id = config->client_id,
        .username = config->username,
        .password = config->password,
        .keepalive_seconds = KEEPALIVE_SECONDS,
    };
    char will_topic[LC_MQTT_TOPIC_MAX + 1];
    char will[256];

    session = *config;
    reply_subscription = -1;
    memset(&assembler, 0, sizeof(assembler));
    inbound_kind = LC_INBOUND_OTHER;

    /* The broker publishes this if the device vanishes without saying goodbye. */
    if (config->locker_uuid[0] != '\0' &&
        lc_mqtt_topic(LC_TOPIC_CONNECTION, config->locker_uuid, will_topic, sizeof(will_topic))) {
        const lc_mqtt_envelope_t stamp = envelope();
        size_t length = lc_mqtt_encode_connection_offline(LC_OFFLINE_LAST_WILL, &stamp, will,
                                                          sizeof(will));
        if (length > 0) {
            transport.will_topic = will_topic;
            transport.will_payload = will;
            transport.will_length = length;
        }
    }
    return lc_mqtt_transport_start(&transport, &handlers);
}
