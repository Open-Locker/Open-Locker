#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/command.h"
#include "lc_domain/events.h"
#include "lc_domain/firmware.h"
#include "lc_domain/identity.h"

/*
 * The MQTT wire format: topics and JSON, both directions, as defined in
 * docs/asyncapi. Pure: no I/O, no clock, no random source.
 */

#define LC_MQTT_PAYLOAD_MAX 8192
#define LC_MQTT_TOPIC_MAX 192
#define LC_MQTT_JSON_DEPTH_MAX 16
#define LC_MQTT_MESSAGE_ID_SIZE 37
#define LC_MQTT_TIMESTAMP_SIZE 21

/* ---- Topics ---- */

typedef enum {
    LC_TOPIC_COMMAND = 0,
    LC_TOPIC_RESPONSE,
    LC_TOPIC_EVENT,
    LC_TOPIC_HEARTBEAT,
    LC_TOPIC_SNAPSHOT,
    LC_TOPIC_CONNECTION,
} lc_topic_t;

/* Built from the locker UUID, never from the MQTT username (ADR-0050). */
bool lc_mqtt_topic(lc_topic_t topic, const char *locker_uuid, char *out, size_t capacity);
bool lc_mqtt_registration_topic(const char *token, char *out, size_t capacity);
bool lc_mqtt_provisioning_reply_topic(const char *client_id, char *out, size_t capacity);
/* Only the compartment snapshot is retained. */
bool lc_mqtt_is_retained(lc_topic_t topic);

typedef enum {
    LC_INBOUND_OTHER = 0,
    LC_INBOUND_COMMAND,
    LC_INBOUND_PROVISIONING_REPLY,
} lc_inbound_t;

/* `locker_uuid` is empty on a device that is not enrolled. */
lc_inbound_t lc_mqtt_classify(const char *topic, const char *locker_uuid, const char *client_id);

/* ---- Inbound ---- */

typedef struct {
    char data[LC_MQTT_PAYLOAD_MAX + 1];
    size_t received;
    size_t total;
    bool active;
} lc_mqtt_assembler_t;

/*
 * Feeds one fragment of a payload. True when the payload is complete; `data`
 * is then NUL-terminated. A gap, an overlap, an embedded NUL, or a total above
 * LC_MQTT_PAYLOAD_MAX discards the whole message.
 */
bool lc_mqtt_assemble(lc_mqtt_assembler_t *assembler, const void *fragment, size_t length,
                      size_t offset, size_t total);

/*
 * False when there is nobody to answer: not JSON, nested too deep, duplicate
 * keys, or no usable transaction_id. Otherwise `out` is filled; a command that
 * cannot be executed as received carries a `defect`.
 */
bool lc_mqtt_decode_command(const char *payload, size_t length, lc_command_t *out);

/* False for anything that is not a complete, well-formed reply. */
bool lc_mqtt_decode_provisioning_reply(const char *payload, size_t length,
                                       lc_provisioning_reply_t *out);

/* ---- Outbound ---- */

typedef struct {
    char message_id[LC_MQTT_MESSAGE_ID_SIZE];
    char timestamp[LC_MQTT_TIMESTAMP_SIZE];
} lc_mqtt_envelope_t;

/* A version 4 UUID from 16 random bytes, and a UTC timestamp to the second. */
void lc_mqtt_envelope(const uint8_t random[16], int64_t unix_seconds, lc_mqtt_envelope_t *out);

/* The wire string of an error, such as "HARDWARE_ERROR". */
const char *lc_mqtt_error_code(lc_error_t error);

/*
 * Each encoder writes one NUL-terminated JSON document and returns its length,
 * or 0 when it does not fit in `capacity`.
 */
size_t lc_mqtt_encode_response(const lc_command_result_t *result,
                               const lc_mqtt_envelope_t *envelope, char *out, size_t capacity);
/* `firmware` is NULL for a device that reports none; `trial` goes out with the version. */
size_t lc_mqtt_encode_heartbeat(uint32_t uptime_seconds, bool bus_connected,
                                const lc_firmware_status_t *firmware,
                                const lc_mqtt_envelope_t *envelope, char *out, size_t capacity);
size_t lc_mqtt_encode_snapshot(const lc_compartment_state_t *states, size_t count,
                               const lc_mqtt_envelope_t *envelope, char *out, size_t capacity);
size_t lc_mqtt_encode_open_detection(const lc_open_detection_t *event,
                                     const lc_mqtt_envelope_t *envelope, char *out,
                                     size_t capacity);
size_t lc_mqtt_encode_uncommanded_open(const lc_uncommanded_open_t *event,
                                       const lc_mqtt_envelope_t *envelope, char *out,
                                       size_t capacity);
size_t lc_mqtt_encode_firmware_update_failed(const lc_firmware_update_failure_t *event,
                                             const lc_mqtt_envelope_t *envelope, char *out,
                                             size_t capacity);
size_t lc_mqtt_encode_connection_offline(lc_offline_reason_t reason,
                                         const lc_mqtt_envelope_t *envelope, char *out,
                                         size_t capacity);
size_t lc_mqtt_encode_registration(const char *client_id, const lc_mqtt_envelope_t *envelope,
                                   char *out, size_t capacity);
