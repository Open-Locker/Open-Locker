#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "cJSON.h"
#include "lc_mqtt_codec/json_writer.h"
#include "lc_mqtt_codec/codec.h"

/* Shared by the sources of the codec; not part of its interface. */

/* No NUL byte, no \u0000 escape, and nesting within the limit. */
bool lc_codec_payload_is_acceptable(const char *payload, size_t length);
/* Which of two equal keys counts is not defined, so neither is accepted. */
bool lc_codec_has_duplicate_keys(const cJSON *node);
/* Printable ASCII of 1 to `max` characters, so it can be echoed as it is. */
bool lc_codec_is_identifier(const char *text, size_t max);
/* An RFC 3339 date-time. */
bool lc_codec_is_timestamp(const char *text);

/* NULL when the member is missing or not a string. */
const char *lc_codec_get_string(const cJSON *object, const char *key);
/* A whole number within `min..max`. */
bool lc_codec_get_integer(const cJSON *object, const char *key, double min, double max,
                          uint32_t *out);

/* `{"message_id":...,"timestamp":...` and nothing after it. */
void lc_codec_begin_message(lc_json_writer_t *writer, char *out, size_t capacity,
                            const lc_mqtt_envelope_t *envelope);

/* Parses one JSON object payload that passed the checks above. NULL otherwise. */
cJSON *lc_codec_parse_object(const char *payload, size_t length);
