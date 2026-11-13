#include "lc_mqtt_codec/codec.h"

#include <string.h>

#include "cJSON.h"
#include "lc_domain/text.h"
#include "lc_mqtt_codec/internal.h"
#include "lc_mqtt_codec/json_writer.h"

/* ---- Inbound: payload checks before parsing ---- */

/*
 * Rejects what the parser would let through or mishandle: a NUL byte, a
 * \u0000 escape that would end a string early, and nesting beyond the limit.
 */
bool lc_codec_payload_is_acceptable(const char *payload, size_t length)
{
    int depth = 0;
    bool in_string = false;

    if (memchr(payload, '\0', length) != NULL) {
        return false;
    }
    for (size_t i = 0; i < length; ++i) {
        char c = payload[i];
        if (in_string) {
            if (c == '\\') {
                if (i + 5 < length && payload[i + 1] == 'u' && payload[i + 2] == '0' &&
                    payload[i + 3] == '0' && payload[i + 4] == '0' && payload[i + 5] == '0') {
                    return false;
                }
                ++i;
            } else if (c == '"') {
                in_string = false;
            }
        } else if (c == '"') {
            in_string = true;
        } else if (c == '{' || c == '[') {
            if (++depth > LC_MQTT_JSON_DEPTH_MAX) {
                return false;
            }
        } else if (c == '}' || c == ']') {
            --depth;
        }
    }
    return true;
}

/* Which of two equal keys counts is not defined, so neither is accepted. */
bool lc_codec_has_duplicate_keys(const cJSON *node)
{
    for (const cJSON *child = node->child; child != NULL; child = child->next) {
        if (cJSON_IsObject(node)) {
            for (const cJSON *other = child->next; other != NULL; other = other->next) {
                if (strcmp(child->string, other->string) == 0) {
                    return true;
                }
            }
        }
        if ((cJSON_IsObject(child) || cJSON_IsArray(child)) && lc_codec_has_duplicate_keys(child)) {
            return true;
        }
    }
    return false;
}

/* Printable ASCII only, so an identifier can be echoed back as it is. */
bool lc_codec_is_identifier(const char *text, size_t max)
{
    size_t length = strlen(text);
    if (length == 0 || length > max) {
        return false;
    }
    for (size_t i = 0; i < length; ++i) {
        if ((unsigned char)text[i] < 0x20 || (unsigned char)text[i] > 0x7E) {
            return false;
        }
    }
    return true;
}

bool lc_codec_get_integer(const cJSON *object, const char *key, double min, double max,
                        uint32_t *out)
{
    const cJSON *item = cJSON_GetObjectItemCaseSensitive(object, key);
    if (!cJSON_IsNumber(item) || item->valuedouble < min || item->valuedouble > max ||
        (double)(int64_t)item->valuedouble != item->valuedouble) {
        return false;
    }
    *out = (uint32_t)item->valuedouble;
    return true;
}

const char *lc_codec_get_string(const cJSON *object, const char *key)
{
    const cJSON *item = cJSON_GetObjectItemCaseSensitive(object, key);
    return cJSON_IsString(item) ? item->valuestring : NULL;
}

static bool digits(const char *text, size_t count)
{
    for (size_t i = 0; i < count; ++i) {
        if (text[i] < '0' || text[i] > '9') {
            return false;
        }
    }
    return true;
}

static unsigned number(const char *text, size_t count)
{
    unsigned value = 0;
    for (size_t i = 0; i < count; ++i) {
        value = value * 10 + (unsigned)(text[i] - '0');
    }
    return value;
}

static bool is_leap_year(unsigned year)
{
    return (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;
}

/* An RFC 3339 date-time: YYYY-MM-DDTHH:MM:SS[.fraction](Z|+HH:MM|-HH:MM). */
bool lc_codec_is_timestamp(const char *text)
{
    static const unsigned DAYS[] = {31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31};
    size_t length = strlen(text);

    if (length < 20 || !digits(text, 4) || text[4] != '-' || !digits(text + 5, 2) ||
        text[7] != '-' || !digits(text + 8, 2) || (text[10] != 'T' && text[10] != 't') ||
        !digits(text + 11, 2) || text[13] != ':' || !digits(text + 14, 2) || text[16] != ':' ||
        !digits(text + 17, 2)) {
        return false;
    }
    unsigned year = number(text, 4);
    unsigned month = number(text + 5, 2);
    unsigned day = number(text + 8, 2);
    if (month < 1 || month > 12 || day < 1 ||
        day > DAYS[month - 1] + (month == 2 && is_leap_year(year) ? 1u : 0u) ||
        number(text + 11, 2) > 23 || number(text + 14, 2) > 59 || number(text + 17, 2) > 60) {
        return false;
    }

    size_t position = 19;
    if (text[position] == '.') {
        size_t start = ++position;
        while (position < length && text[position] >= '0' && text[position] <= '9') {
            ++position;
        }
        if (position == start) {
            return false;
        }
    }
    if (position + 1 == length && (text[position] == 'Z' || text[position] == 'z')) {
        return true;
    }
    return position + 6 == length && (text[position] == '+' || text[position] == '-') &&
           digits(text + position + 1, 2) && text[position + 3] == ':' &&
           digits(text + position + 4, 2) && number(text + position + 1, 2) <= 23 &&
           number(text + position + 4, 2) <= 59;
}

static lc_error_t parse_open(const cJSON *data, lc_command_t *out)
{
    uint32_t compartment_number;
    if (!lc_codec_get_integer(data, "compartment_number", 1, UINT16_MAX, &compartment_number)) {
        return LC_ERROR_INVALID_COMMAND;
    }
    out->compartment_number = (uint16_t)compartment_number;
    return LC_ERROR_NONE;
}

static lc_error_t parse_apply(const cJSON *data, lc_command_t *out)
{
    lc_config_request_t *config = &out->config;
    uint32_t value;

    const char *adapter = lc_codec_get_string(data, "adapter_type");
    if (adapter == NULL) {
        return LC_ERROR_INVALID_CONFIG;
    }
    config->supported_adapter = strcmp(adapter, "rs485_lock_board") == 0;
    if (!config->supported_adapter) {
        /* Another adapter's configuration is not read; the application refuses it. */
        return LC_ERROR_NONE;
    }

    const char *hash = lc_codec_get_string(data, "config_hash");
    const char *feedback = lc_codec_get_string(data, "feedback_type");
    const cJSON *compartments = cJSON_GetObjectItemCaseSensitive(data, "compartments");
    if (hash == NULL || !lc_text_copy(config->hash, sizeof(config->hash), hash) ||
        feedback == NULL || !cJSON_IsArray(compartments) ||
        !lc_codec_get_integer(data, "heartbeat_interval_seconds", 1, LC_HEARTBEAT_SECONDS_MAX, &value)) {
        return LC_ERROR_INVALID_CONFIG;
    }
    config->heartbeat_seconds = value;
    if (strcmp(feedback, "door_closing") == 0) {
        config->feedback = LC_FEEDBACK_DOOR_CLOSING;
    } else if (strcmp(feedback, "door_opening") == 0) {
        config->feedback = LC_FEEDBACK_DOOR_OPENING;
    } else {
        return LC_ERROR_INVALID_CONFIG;
    }

    if (cJSON_GetArraySize(compartments) > LC_COMPARTMENTS_MAX) {
        return LC_ERROR_INVALID_CONFIG;
    }
    for (const cJSON *item = compartments->child; item != NULL; item = item->next) {
        uint32_t compartment_number;
        uint32_t board;
        uint32_t address;
        if (!cJSON_IsObject(item) ||
            !lc_codec_get_integer(item, "compartment_number", 0, UINT16_MAX, &compartment_number) ||
            !lc_codec_get_integer(item, "slaveId", 0, UINT8_MAX, &board) ||
            !lc_codec_get_integer(item, "address", 0, UINT8_MAX, &address)) {
            return LC_ERROR_INVALID_CONFIG;
        }
        config->mappings[config->count++] = (lc_mapping_t){
            .number = (uint16_t)compartment_number,
            .board = (uint8_t)board,
            .address = (uint8_t)address,
        };
    }
    return LC_ERROR_NONE;
}

static lc_error_t parse_body(const cJSON *root, lc_command_t *out)
{
    const char *message_id = lc_codec_get_string(root, "message_id");
    const char *action = lc_codec_get_string(root, "action");
    const char *timestamp = lc_codec_get_string(root, "timestamp");
    const cJSON *data = cJSON_GetObjectItemCaseSensitive(root, "data");

    if (action != NULL) {
        /* Echoed in the response, so it is kept to characters that are safe to print. */
        size_t length = strlen(action) < LC_ACTION_NAME_MAX ? strlen(action) : LC_ACTION_NAME_MAX;
        for (size_t i = 0; i < length; ++i) {
            unsigned char c = (unsigned char)action[i];
            out->action_name[i] = c >= 0x20 && c <= 0x7E ? (char)c : '?';
        }
        out->action_name[length] = '\0';
    }

    if (message_id == NULL || message_id[0] == '\0') {
        return cJSON_GetObjectItemCaseSensitive(root, "message_id") == NULL ||
                       cJSON_IsString(cJSON_GetObjectItemCaseSensitive(root, "message_id"))
                   ? LC_ERROR_MISSING_MESSAGE_ID
                   : LC_ERROR_INVALID_COMMAND;
    }
    if (!lc_codec_is_identifier(message_id, LC_ID_MAX)) {
        return LC_ERROR_INVALID_COMMAND;
    }
    (void)lc_text_copy(out->message_id, sizeof(out->message_id), message_id);

    if (action == NULL || action[0] == '\0' || strlen(action) > LC_ACTION_NAME_MAX ||
        timestamp == NULL || !lc_codec_is_timestamp(timestamp) || !cJSON_IsObject(data)) {
        return LC_ERROR_INVALID_COMMAND;
    }

    if (strcmp(action, "open_compartment") == 0) {
        out->action = LC_ACTION_OPEN_COMPARTMENT;
        return parse_open(data, out);
    }
    if (strcmp(action, "apply_config") == 0) {
        out->action = LC_ACTION_APPLY_CONFIG;
        return parse_apply(data, out);
    }
    return LC_ERROR_UNKNOWN_ACTION;
}

cJSON *lc_codec_parse_object(const char *payload, size_t length)
{
    if (length == 0 || length > LC_MQTT_PAYLOAD_MAX ||
        !lc_codec_payload_is_acceptable(payload, length)) {
        return NULL;
    }
    cJSON *root = cJSON_ParseWithLength(payload, length);
    if (root != NULL && (!cJSON_IsObject(root) || lc_codec_has_duplicate_keys(root))) {
        cJSON_Delete(root);
        return NULL;
    }
    return root;
}

bool lc_mqtt_decode_command(const char *payload, size_t length, lc_command_t *out)
{
    memset(out, 0, sizeof(*out));
    cJSON *root = lc_codec_parse_object(payload, length);
    if (root == NULL) {
        return false;
    }

    const char *transaction_id = lc_codec_get_string(root, "transaction_id");
    bool usable = transaction_id != NULL && lc_codec_is_identifier(transaction_id, LC_ID_MAX);
    if (usable) {
        (void)lc_text_copy(out->transaction_id, sizeof(out->transaction_id), transaction_id);
        out->defect = parse_body(root, out);
    }
    cJSON_Delete(root);
    return usable;
}

/* ---- Inbound: fragments ---- */

bool lc_mqtt_assemble(lc_mqtt_assembler_t *assembler, const void *fragment, size_t length,
                      size_t offset, size_t total)
{
    if (offset == 0) {
        memset(assembler, 0, sizeof(*assembler));
        if (total == 0 || total > LC_MQTT_PAYLOAD_MAX) {
            return false;
        }
        assembler->total = total;
        assembler->active = true;
    } else if (!assembler->active || total != assembler->total || offset != assembler->received) {
        /* A gap, an overlap, or a continuation of something that was discarded. */
        assembler->active = false;
        return false;
    }

    if (length == 0 || length > assembler->total - assembler->received) {
        assembler->active = false;
        return false;
    }
    memcpy(assembler->data + assembler->received, fragment, length);
    assembler->received += length;
    if (assembler->received < assembler->total) {
        return false;
    }

    assembler->active = false;
    assembler->data[assembler->total] = '\0';
    return memchr(assembler->data, '\0', assembler->total) == NULL;
}

/* ---- Outbound ---- */

static uint8_t hex_digit(unsigned value)
{
    return (uint8_t)"0123456789abcdef"[value & 0xF];
}

void lc_mqtt_envelope(const uint8_t random[16], int64_t unix_seconds, lc_mqtt_envelope_t *out)
{
    uint8_t bytes[16];
    memcpy(bytes, random, sizeof(bytes));
    bytes[6] = (uint8_t)((bytes[6] & 0x0F) | 0x40);
    bytes[8] = (uint8_t)((bytes[8] & 0x3F) | 0x80);

    size_t position = 0;
    for (size_t i = 0; i < sizeof(bytes); ++i) {
        if (i == 4 || i == 6 || i == 8 || i == 10) {
            out->message_id[position++] = '-';
        }
        out->message_id[position++] = (char)hex_digit(bytes[i] >> 4);
        out->message_id[position++] = (char)hex_digit(bytes[i]);
    }
    out->message_id[position] = '\0';

    /* Days since 1970-01-01 to a civil date (Howard Hinnant's algorithm). */
    if (unix_seconds < 0) {
        unix_seconds = 0;
    }
    int64_t days = unix_seconds / 86400;
    int64_t seconds_of_day = unix_seconds % 86400;
    days += 719468;
    int64_t era = days / 146097;
    int64_t day_of_era = days - era * 146097;
    int64_t year_of_era =
        (day_of_era - day_of_era / 1460 + day_of_era / 36524 - day_of_era / 146096) / 365;
    int64_t day_of_year = day_of_era - (365 * year_of_era + year_of_era / 4 - year_of_era / 100);
    int64_t month_index = (5 * day_of_year + 2) / 153;
    int64_t day = day_of_year - (153 * month_index + 2) / 5 + 1;
    int64_t month = month_index < 10 ? month_index + 3 : month_index - 9;
    int64_t year = year_of_era + era * 400 + (month <= 2 ? 1 : 0);

    snprintf(out->timestamp, sizeof(out->timestamp), "%04u-%02u-%02uT%02u:%02u:%02uZ",
             (unsigned)year, (unsigned)month, (unsigned)day, (unsigned)(seconds_of_day / 3600),
             (unsigned)(seconds_of_day % 3600 / 60), (unsigned)(seconds_of_day % 60));
}

const char *lc_mqtt_error_code(lc_error_t error)
{
    switch (error) {
    case LC_ERROR_MISSING_MESSAGE_ID:
        return "MISSING_MESSAGE_ID";
    case LC_ERROR_INVALID_COMMAND:
        return "INVALID_COMMAND";
    case LC_ERROR_UNKNOWN_ACTION:
        return "UNKNOWN_ACTION";
    case LC_ERROR_INVALID_CONFIG:
        return "INVALID_CONFIG";
    case LC_ERROR_RUNTIME_CONFIG_NOT_APPLIED:
        return "RUNTIME_CONFIG_NOT_APPLIED";
    case LC_ERROR_COMPARTMENT_NOT_FOUND:
        return "COMPARTMENT_NOT_FOUND";
    case LC_ERROR_HARDWARE_ERROR:
        return "HARDWARE_ERROR";
    case LC_ERROR_SHUTTING_DOWN:
        return "SHUTTING_DOWN";
    case LC_ERROR_NONE:
    case LC_ERROR_UNKNOWN_ERROR:
    default:
        return "UNKNOWN_ERROR";
    }
}

static const char *error_message(lc_error_t error)
{
    switch (error) {
    case LC_ERROR_MISSING_MESSAGE_ID:
        return "The command has no message_id.";
    case LC_ERROR_INVALID_COMMAND:
        return "The command is malformed.";
    case LC_ERROR_UNKNOWN_ACTION:
        return "The action is not supported.";
    case LC_ERROR_INVALID_CONFIG:
        return "The configuration was rejected.";
    case LC_ERROR_RUNTIME_CONFIG_NOT_APPLIED:
        return "No configuration has been applied yet.";
    case LC_ERROR_COMPARTMENT_NOT_FOUND:
        return "The compartment is not mapped.";
    case LC_ERROR_HARDWARE_ERROR:
        return "The locker hardware did not confirm the unlock.";
    case LC_ERROR_SHUTTING_DOWN:
        return "The device is shutting down.";
    case LC_ERROR_NONE:
    case LC_ERROR_UNKNOWN_ERROR:
    default:
        return "The outcome of the command is not known.";
    }
}

void lc_codec_begin_message(lc_json_writer_t *writer, char *out, size_t capacity,
                          const lc_mqtt_envelope_t *envelope)
{
    lc_json_begin(writer, out, capacity);
    lc_json_raw(writer, "{");
    lc_json_key(writer, "message_id", true);
    lc_json_string(writer, envelope->message_id);
    lc_json_key(writer, "timestamp", false);
    lc_json_string(writer, envelope->timestamp);
}

size_t lc_mqtt_encode_response(const lc_command_result_t *result,
                               const lc_mqtt_envelope_t *envelope, char *out, size_t capacity)
{
    lc_json_writer_t writer;
    bool success = result->error == LC_ERROR_NONE;

    lc_codec_begin_message(&writer, out, capacity, envelope);
    lc_json_key(&writer, "transaction_id", false);
    lc_json_string(&writer, result->transaction_id);
    lc_json_key(&writer, "action", false);
    lc_json_string(&writer, result->action_name);
    lc_json_key(&writer, "result", false);
    lc_json_string(&writer, success ? "success" : "error");
    if (success) {
        lc_json_key(&writer, "message", false);
        lc_json_string(&writer, result->applied_config_hash[0] != '\0' ? "Config applied."
                                                                       : "Command executed.");
        if (result->applied_config_hash[0] != '\0') {
            lc_json_key(&writer, "applied_config_hash", false);
            lc_json_string(&writer, result->applied_config_hash);
        }
    } else {
        lc_json_key(&writer, "error_code", false);
        lc_json_string(&writer, lc_mqtt_error_code(result->error));
        lc_json_key(&writer, "message", false);
        lc_json_string(&writer, error_message(result->error));
    }
    lc_json_raw(&writer, "}");
    return lc_json_finish(&writer);
}

size_t lc_mqtt_encode_heartbeat(uint32_t uptime_seconds, bool bus_connected,
                                const lc_mqtt_envelope_t *envelope, char *out, size_t capacity)
{
    lc_json_writer_t writer;

    lc_codec_begin_message(&writer, out, capacity, envelope);
    lc_json_key(&writer, "uptime_seconds", false);
    lc_json_unsigned(&writer, uptime_seconds);
    /* The key name is the contract's; the bus here is RS485, not Modbus. */
    lc_json_key(&writer, "modbus_connected", false);
    lc_json_bool(&writer, bus_connected);
    lc_json_raw(&writer, "}");
    return lc_json_finish(&writer);
}
