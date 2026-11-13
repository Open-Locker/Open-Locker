#include <stdio.h>
#include <string.h>

#include "esp_random.h"
#include "lc_mqtt_codec/codec.h"
#include "runtime.h"

/*
 * Bench mode: a device with no network settings has no broker, so the console
 * stands in for it. Commands are fed in with `command <json>`, and everything
 * the device would publish is printed as `out <kind> <json>`, which the
 * installer's tool ignores. A publish is acknowledged at once, because the
 * console cannot lose it.
 */

#define ENCODE_BUFFER_SIZE 4608

static char encoded[ENCODE_BUFFER_SIZE];
static lc_publish_id_t last_id;

static lc_mqtt_envelope_t envelope(void)
{
    uint8_t random[16];
    lc_mqtt_envelope_t result;

    esp_fill_random(random, sizeof(random));
    /* There is no wall clock on the bench; the timestamp reads 1970. */
    lc_mqtt_envelope(random, 0, &result);
    return result;
}

static lc_publish_id_t emit(const char *kind, size_t length)
{
    if (length == 0) {
        return LC_PUBLISH_REFUSED;
    }
    printf("out %s %s\n", kind, encoded);
    fflush(stdout);

    if (++last_id == LC_PUBLISH_REFUSED) {
        ++last_id;
    }
    lc_event_t acknowledged;
    memset(&acknowledged, 0, sizeof(acknowledged));
    acknowledged.kind = LC_EVENT_PUBLISH_ACKNOWLEDGED;
    acknowledged.publish_id = last_id;
    (void)owner_post_app(&acknowledged);
    return last_id;
}

static lc_publish_id_t out_response(void *ctx, const lc_command_result_t *result)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return emit("response", lc_mqtt_encode_response(result, &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_heartbeat(void *ctx, uint32_t uptime_seconds, bool bus_connected)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return emit("heartbeat", lc_mqtt_encode_heartbeat(uptime_seconds, bus_connected, &stamp,
                                                      encoded, sizeof(encoded)));
}

static lc_publish_id_t out_snapshot(void *ctx, const lc_compartment_state_t *states, size_t count)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return emit("snapshot", lc_mqtt_encode_snapshot(states, count, &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_open_detection(void *ctx, const lc_open_detection_t *event)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return emit("event", lc_mqtt_encode_open_detection(event, &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_uncommanded_open(void *ctx, const lc_uncommanded_open_t *event)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return emit("event", lc_mqtt_encode_uncommanded_open(event, &stamp, encoded, sizeof(encoded)));
}

static lc_publish_id_t out_connection_offline(void *ctx, lc_offline_reason_t reason)
{
    (void)ctx;
    const lc_mqtt_envelope_t stamp = envelope();
    return emit("connection", lc_mqtt_encode_connection_offline(reason, &stamp, encoded,
                                                                sizeof(encoded)));
}

lc_outbound_port_t bench_outbound(void)
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
