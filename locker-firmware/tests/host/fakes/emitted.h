#pragma once

#include "cJSON.h"

/*
 * Records a message the firmware encoded, for validation against the AsyncAPI
 * schemas. Asserts that `json` is one JSON object and returns it parsed; the
 * caller frees it with cJSON_Delete().
 *
 * When the environment variable LC_EMITTED_MESSAGES names a file, a line
 * {"schema": <schema>, "message": <json>} is appended to it. `schema` is a
 * path below docs/asyncapi/schemas. The static and host gates validate every
 * line of that file.
 */
cJSON *emit_message(const char *schema, const char *json);

/* Convenience checks on a parsed message. */
const char *emitted_string(const cJSON *object, const char *key);
/* Fails the test unless the value is a whole number. */
long emitted_integer(const cJSON *object, const char *key);
