#include "lc_mqtt_codec/internal.h"

/* Door state and door events (ADR-0016, ADR-0017, ADR-0040). */

static const char *door_state_name(lc_door_t state)
{
    switch (state) {
    case LC_DOOR_CLOSED:
        return "closed";
    case LC_DOOR_OPEN:
        return "open";
    case LC_DOOR_UNKNOWN:
    default:
        return "unknown";
    }
}

size_t lc_mqtt_encode_snapshot(const lc_compartment_state_t *states, size_t count,
                               const lc_mqtt_envelope_t *envelope, char *out, size_t capacity)
{
    lc_json_writer_t writer;

    lc_codec_begin_message(&writer, out, capacity, envelope);
    lc_json_key(&writer, "compartments", false);
    lc_json_raw(&writer, "[");
    for (size_t i = 0; i < count; ++i) {
        if (i > 0) {
            lc_json_raw(&writer, ",");
        }
        lc_json_raw(&writer, "{");
        lc_json_key(&writer, "compartment_number", true);
        lc_json_unsigned(&writer, states[i].compartment_number);
        lc_json_key(&writer, "door_state", false);
        lc_json_string(&writer, door_state_name(states[i].state));
        lc_json_raw(&writer, "}");
    }
    lc_json_raw(&writer, "]}");
    return lc_json_finish(&writer);
}

/* `{"message_id":...,"event":...,"timestamp":...,"data":{` */
static void begin_event(lc_json_writer_t *writer, char *out, size_t capacity,
                        const lc_mqtt_envelope_t *envelope, const char *event)
{
    lc_json_begin(writer, out, capacity);
    lc_json_raw(writer, "{");
    lc_json_key(writer, "message_id", true);
    lc_json_string(writer, envelope->message_id);
    lc_json_key(writer, "event", false);
    lc_json_string(writer, event);
    lc_json_key(writer, "timestamp", false);
    lc_json_string(writer, envelope->timestamp);
    lc_json_key(writer, "data", false);
    lc_json_raw(writer, "{");
}

size_t lc_mqtt_encode_open_detection(const lc_open_detection_t *event,
                                     const lc_mqtt_envelope_t *envelope, char *out,
                                     size_t capacity)
{
    lc_json_writer_t writer;

    begin_event(&writer, out, capacity, envelope,
                event->opened ? "compartment_open_detected" : "compartment_open_failed");
    lc_json_key(&writer, "compartment_number", true);
    lc_json_unsigned(&writer, event->compartment_number);
    lc_json_key(&writer, "transaction_id", false);
    lc_json_string(&writer, event->transaction_id);
    lc_json_key(&writer, "outcome", false);
    lc_json_string(&writer, event->opened ? "opened" : "door_jammed");
    if (event->opened) {
        lc_json_key(&writer, "detection_ms", false);
        lc_json_unsigned(&writer, event->detection_ms);
    } else {
        lc_json_key(&writer, "error_code", false);
        lc_json_string(&writer, "DOOR_JAMMED");
    }
    lc_json_raw(&writer, "}}");
    return lc_json_finish(&writer);
}

size_t lc_mqtt_encode_uncommanded_open(const lc_uncommanded_open_t *event,
                                       const lc_mqtt_envelope_t *envelope, char *out,
                                       size_t capacity)
{
    lc_json_writer_t writer;

    begin_event(&writer, out, capacity, envelope, "compartment_uncommanded_open");
    lc_json_key(&writer, "compartment_number", true);
    lc_json_unsigned(&writer, event->compartment_number);
    if (event->has_last_unlock) {
        lc_json_key(&writer, "milliseconds_since_last_relay_fire", false);
        lc_json_unsigned(&writer, event->ms_since_last_unlock);
    }
    lc_json_raw(&writer, "}}");
    return lc_json_finish(&writer);
}

size_t lc_mqtt_encode_firmware_update_failed(const lc_firmware_update_failure_t *event,
                                             const lc_mqtt_envelope_t *envelope, char *out,
                                             size_t capacity)
{
    lc_json_writer_t writer;

    begin_event(&writer, out, capacity, envelope, "firmware_update_failed");
    lc_json_key(&writer, "transaction_id", true);
    lc_json_string(&writer, event->transaction_id);
    lc_json_key(&writer, "error_code", false);
    lc_json_string(&writer, lc_mqtt_error_code(event->error));
    lc_json_raw(&writer, "}}");
    return lc_json_finish(&writer);
}
