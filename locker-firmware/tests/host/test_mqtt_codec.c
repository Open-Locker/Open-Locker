#include <stdio.h>
#include <string.h>

#include "fakes/emitted.h"
#include "lc_mqtt_codec/codec.h"
#include "unity.h"

/* 2026-04-14T19:31:00Z */
#define UNIX_SECONDS 1776195060

static const uint8_t RANDOM_A[16] = {0x00, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, 0x77,
                                     0x88, 0x99, 0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF};
static const uint8_t RANDOM_B[16] = {0xFF, 0xEE, 0xDD, 0xCC, 0xBB, 0xAA, 0x99, 0x88,
                                     0x77, 0x66, 0x55, 0x44, 0x33, 0x22, 0x11, 0x00};

static lc_command_t command;
static lc_mqtt_assembler_t assembler;
static lc_mqtt_envelope_t envelope;
static char payload[LC_MQTT_PAYLOAD_MAX + 1];
static char encoded[LC_MQTT_PAYLOAD_MAX + 1];

void setUp(void)
{
    memset(&command, 0, sizeof(command));
    memset(&assembler, 0, sizeof(assembler));
    lc_mqtt_envelope(RANDOM_A, UNIX_SECONDS, &envelope);
}

void tearDown(void)
{
}

static size_t read_example(const char *name)
{
    char path[512];
    snprintf(path, sizeof(path), "%s/docs/asyncapi/examples/%s", LC_REPO_ROOT, name);
    FILE *file = fopen(path, "rb");
    TEST_ASSERT_NOT_NULL_MESSAGE(file, path);
    size_t length = fread(payload, 1, LC_MQTT_PAYLOAD_MAX, file);
    fclose(file);
    payload[length] = '\0';
    return length;
}

static bool decode(const char *text)
{
    memset(&command, 0, sizeof(command));
    return lc_mqtt_decode_command(text, strlen(text), &command);
}

void test_S2_H01_command_examples_parse_into_typed_commands(void)
{
    size_t length = read_example("command-open-compartment.json");
    TEST_ASSERT_TRUE(lc_mqtt_decode_command(payload, length, &command));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, command.defect);
    TEST_ASSERT_EQUAL_INT(LC_ACTION_OPEN_COMPARTMENT, command.action);
    TEST_ASSERT_EQUAL_STRING("open_compartment", command.action_name);
    TEST_ASSERT_EQUAL_STRING("txn-open-001", command.transaction_id);
    TEST_ASSERT_EQUAL_STRING("msg-cmd-open-001", command.message_id);
    TEST_ASSERT_EQUAL_UINT16(3, command.compartment_number);

    length = read_example("command-apply-config.json");
    memset(&command, 0, sizeof(command));
    TEST_ASSERT_TRUE(lc_mqtt_decode_command(payload, length, &command));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, command.defect);
    TEST_ASSERT_EQUAL_INT(LC_ACTION_APPLY_CONFIG, command.action);
    TEST_ASSERT_EQUAL_STRING("txn-config-001", command.transaction_id);
    TEST_ASSERT_TRUE(command.config.supported_adapter);
    TEST_ASSERT_EQUAL_INT(LC_FEEDBACK_DOOR_CLOSING, command.config.feedback);
    TEST_ASSERT_EQUAL_UINT32(15, command.config.heartbeat_seconds);
    TEST_ASSERT_EQUAL_STRING("3306f9a74f407226b567536b577fa7dfd460a674850ce9d1818aabe94f55ab62",
                             command.config.hash);
    TEST_ASSERT_EQUAL_UINT(2, command.config.count);
    TEST_ASSERT_EQUAL_UINT16(1, command.config.mappings[0].number);
    TEST_ASSERT_EQUAL_UINT8(1, command.config.mappings[0].board);
    TEST_ASSERT_EQUAL_UINT8(0, command.config.mappings[0].address);
    TEST_ASSERT_EQUAL_UINT16(2, command.config.mappings[1].number);
    TEST_ASSERT_EQUAL_UINT8(1, command.config.mappings[1].address);
}

void test_S2_H02_payload_without_transaction_id_is_dropped(void)
{
    TEST_ASSERT_FALSE(decode("{\"message_id\":\"m1\",\"action\":\"open_compartment\","
                             "\"timestamp\":\"2026-04-14T19:30:00Z\","
                             "\"data\":{\"compartment_number\":3}}"));
    TEST_ASSERT_FALSE(decode("{\"transaction_id\":\"\",\"message_id\":\"m1\"}"));
    TEST_ASSERT_FALSE(decode("{\"transaction_id\":7,\"message_id\":\"m1\"}"));
    TEST_ASSERT_FALSE(decode("not json"));
    TEST_ASSERT_FALSE(decode("[1,2,3]"));
    TEST_ASSERT_FALSE(decode(""));
}

void test_S2_H03_payload_without_message_id_is_answered_with_missing_message_id(void)
{
    TEST_ASSERT_TRUE(decode("{\"transaction_id\":\"t1\",\"action\":\"open_compartment\","
                            "\"timestamp\":\"2026-04-14T19:30:00Z\","
                            "\"data\":{\"compartment_number\":3}}"));

    TEST_ASSERT_EQUAL_INT(LC_ERROR_MISSING_MESSAGE_ID, command.defect);
    TEST_ASSERT_EQUAL_STRING("t1", command.transaction_id);
    TEST_ASSERT_EQUAL_STRING("open_compartment", command.action_name);
}

void test_S2_H04_malformed_commands_are_invalid_command(void)
{
    static const char *const MALFORMED[] = {
        /* Timestamp that is not a date-time. */
        "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
        "\"timestamp\":\"yesterday\",\"data\":{\"compartment_number\":3}}",
        /* No timestamp. */
        "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
        "\"data\":{\"compartment_number\":3}}",
        /* `data` is not an object. */
        "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
        "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":3}",
        /* No compartment_number. */
        "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
        "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{}}",
        /* compartment_number is zero, negative, fractional, a string. */
        "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
        "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{\"compartment_number\":0}}",
        "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
        "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{\"compartment_number\":-1}}",
        "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
        "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{\"compartment_number\":1.5}}",
        "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
        "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{\"compartment_number\":\"3\"}}",
    };

    for (size_t i = 0; i < sizeof(MALFORMED) / sizeof(MALFORMED[0]); ++i) {
        TEST_ASSERT_TRUE_MESSAGE(decode(MALFORMED[i]), MALFORMED[i]);
        TEST_ASSERT_EQUAL_INT_MESSAGE(LC_ERROR_INVALID_COMMAND, command.defect, MALFORMED[i]);
        TEST_ASSERT_EQUAL_STRING("t1", command.transaction_id);
    }
}

void test_S2_H05_unknown_action_is_unknown_action(void)
{
    TEST_ASSERT_TRUE(decode("{\"transaction_id\":\"t1\",\"message_id\":\"m1\","
                            "\"action\":\"self_destruct\","
                            "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{}}"));

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UNKNOWN_ACTION, command.defect);
    TEST_ASSERT_EQUAL_INT(LC_ACTION_UNKNOWN, command.action);
    TEST_ASSERT_EQUAL_STRING("self_destruct", command.action_name);
}

void test_S2_H06_hostile_payloads_are_rejected(void)
{
    /* Nested one level deeper than the limit. */
    size_t length = 0;
    length += (size_t)snprintf(payload + length, sizeof(payload) - length,
                               "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"x\":");
    for (int depth = 0; depth < LC_MQTT_JSON_DEPTH_MAX + 1; ++depth) {
        payload[length++] = '[';
    }
    for (int depth = 0; depth < LC_MQTT_JSON_DEPTH_MAX + 1; ++depth) {
        payload[length++] = ']';
    }
    payload[length++] = '}';
    payload[length] = '\0';
    TEST_ASSERT_FALSE(lc_mqtt_decode_command(payload, length, &command));

    /* The same key twice: which one counts is not defined. */
    TEST_ASSERT_FALSE(decode("{\"transaction_id\":\"t1\",\"transaction_id\":\"t2\","
                             "\"message_id\":\"m1\",\"action\":\"open_compartment\","
                             "\"timestamp\":\"2026-04-14T19:30:00Z\","
                             "\"data\":{\"compartment_number\":3}}"));

    /* And one level down. */
    TEST_ASSERT_FALSE(decode("{\"transaction_id\":\"t1\",\"message_id\":\"m1\","
                             "\"action\":\"open_compartment\","
                             "\"timestamp\":\"2026-04-14T19:30:00Z\","
                             "\"data\":{\"compartment_number\":3,\"compartment_number\":4}}"));

    /* A NUL byte inside the payload, raw and escaped. */
    static const char WITH_NUL[] = "{\"transaction_id\":\"t1\"\0,\"message_id\":\"m1\"}";
    TEST_ASSERT_FALSE(lc_mqtt_decode_command(WITH_NUL, sizeof(WITH_NUL) - 1, &command));
    TEST_ASSERT_FALSE(decode("{\"transaction_id\":\"t\\u00001\",\"message_id\":\"m1\","
                             "\"action\":\"open_compartment\","
                             "\"timestamp\":\"2026-04-14T19:30:00Z\","
                             "\"data\":{\"compartment_number\":3}}"));

    /* A transaction id one character over the limit. */
    char long_id[LC_ID_MAX + 2];
    memset(long_id, 'a', sizeof(long_id) - 1);
    long_id[sizeof(long_id) - 1] = '\0';
    snprintf(payload, sizeof(payload),
             "{\"transaction_id\":\"%s\",\"message_id\":\"m1\",\"action\":\"open_compartment\","
             "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{\"compartment_number\":3}}",
             long_id);
    TEST_ASSERT_FALSE(decode(payload));

    /* A message id one character over the limit cannot be remembered. */
    snprintf(payload, sizeof(payload),
             "{\"transaction_id\":\"t1\",\"message_id\":\"%s\",\"action\":\"open_compartment\","
             "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{\"compartment_number\":3}}",
             long_id);
    TEST_ASSERT_TRUE(decode(payload));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_COMMAND, command.defect);
}

void test_S2_H07_fragments_in_order_assemble_into_one_payload(void)
{
    const char *whole = "{\"transaction_id\":\"t1\"}";
    size_t total = strlen(whole);

    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole, 5, 0, total));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole + 5, 10, 5, total));
    TEST_ASSERT_TRUE(lc_mqtt_assemble(&assembler, whole + 15, total - 15, 15, total));
    TEST_ASSERT_EQUAL_STRING(whole, assembler.data);

    /* A payload that arrives in one piece. */
    TEST_ASSERT_TRUE(lc_mqtt_assemble(&assembler, "{}", 2, 0, 2));
    TEST_ASSERT_EQUAL_STRING("{}", assembler.data);
}

void test_S2_H08_gap_overlap_or_oversize_discards_the_message(void)
{
    const char *whole = "{\"transaction_id\":\"t1\"}";
    size_t total = strlen(whole);

    /* Gap: bytes 5..9 never arrive. */
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole, 5, 0, total));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole + 10, total - 10, 10, total));

    /* Overlap: bytes 3..4 arrive twice. */
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole, 5, 0, total));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole + 3, total - 3, 3, total));

    /* A continuation with nothing before it. */
    memset(&assembler, 0, sizeof(assembler));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole + 5, total - 5, 5, total));

    /* Larger than the limit, announced up front. */
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole, 5, 0, LC_MQTT_PAYLOAD_MAX + 1));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole + 5, total - 5, 5,
                                       LC_MQTT_PAYLOAD_MAX + 1));

    /* A fragment that runs past the announced total. */
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, whole, total, 0, total - 1));

    /* A NUL inside the payload. */
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, "{\0}", 3, 0, 3));

    /* The next message is unaffected. */
    TEST_ASSERT_TRUE(lc_mqtt_assemble(&assembler, whole, total, 0, total));
    TEST_ASSERT_EQUAL_STRING(whole, assembler.data);
}

static void assert_uuid_v4(const char *id)
{
    TEST_ASSERT_EQUAL_UINT(36, strlen(id));
    for (size_t i = 0; i < 36; ++i) {
        if (i == 8 || i == 13 || i == 18 || i == 23) {
            TEST_ASSERT_EQUAL_CHAR('-', id[i]);
        } else {
            TEST_ASSERT_NOT_NULL_MESSAGE(strchr("0123456789abcdef", id[i]), id);
        }
    }
    TEST_ASSERT_EQUAL_CHAR('4', id[14]);
    TEST_ASSERT_NOT_NULL_MESSAGE(strchr("89ab", id[19]), id);
}

void test_S2_H09_outbound_messages_carry_a_uuid_message_id_and_utc_timestamp(void)
{
    lc_mqtt_envelope_t other;
    lc_mqtt_envelope(RANDOM_B, UNIX_SECONDS + 61, &other);

    assert_uuid_v4(envelope.message_id);
    assert_uuid_v4(other.message_id);
    TEST_ASSERT_TRUE(strcmp(envelope.message_id, other.message_id) != 0);
    TEST_ASSERT_EQUAL_STRING("2026-04-14T19:31:00Z", envelope.timestamp);
    TEST_ASSERT_EQUAL_STRING("2026-04-14T19:32:01Z", other.timestamp);

    TEST_ASSERT_TRUE(lc_mqtt_encode_heartbeat(60, true, NULL, &envelope, encoded, sizeof(encoded)) > 0);
    cJSON *message = emit_message("payloads/state-heartbeat.json", encoded);
    TEST_ASSERT_EQUAL_STRING(envelope.message_id, emitted_string(message, "message_id"));
    TEST_ASSERT_EQUAL_STRING(envelope.timestamp, emitted_string(message, "timestamp"));
    cJSON_Delete(message);
}

static lc_command_result_t result_for(const char *action_name, lc_error_t error)
{
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    snprintf(result.transaction_id, sizeof(result.transaction_id), "txn-001");
    snprintf(result.action_name, sizeof(result.action_name), "%s", action_name);
    result.error = error;
    return result;
}

void test_S2_H10_responses_match_their_schemas(void)
{
    static const char HASH[] = "3306f9a74f407226b567536b577fa7dfd460a674850ce9d1818aabe94f55ab62";

    lc_command_result_t result = result_for("open_compartment", LC_ERROR_NONE);
    size_t length = lc_mqtt_encode_response(&result, &envelope, encoded, sizeof(encoded));
    TEST_ASSERT_EQUAL_UINT(strlen(encoded), length);
    cJSON *message = emit_message("payloads/response-command-success.json", encoded);
    TEST_ASSERT_EQUAL_STRING("txn-001", emitted_string(message, "transaction_id"));
    TEST_ASSERT_EQUAL_STRING("open_compartment", emitted_string(message, "action"));
    TEST_ASSERT_EQUAL_STRING("success", emitted_string(message, "result"));
    TEST_ASSERT_NULL(cJSON_GetObjectItemCaseSensitive(message, "error_code"));
    cJSON_Delete(message);

    static const struct {
        lc_error_t error;
        const char *code;
    } ERRORS[] = {
        {LC_ERROR_MISSING_MESSAGE_ID, "MISSING_MESSAGE_ID"},
        {LC_ERROR_INVALID_COMMAND, "INVALID_COMMAND"},
        {LC_ERROR_UNKNOWN_ACTION, "UNKNOWN_ACTION"},
        {LC_ERROR_INVALID_CONFIG, "INVALID_CONFIG"},
        {LC_ERROR_RUNTIME_CONFIG_NOT_APPLIED, "RUNTIME_CONFIG_NOT_APPLIED"},
        {LC_ERROR_COMPARTMENT_NOT_FOUND, "COMPARTMENT_NOT_FOUND"},
        {LC_ERROR_HARDWARE_ERROR, "HARDWARE_ERROR"},
        {LC_ERROR_SHUTTING_DOWN, "SHUTTING_DOWN"},
        {LC_ERROR_UNKNOWN_ERROR, "UNKNOWN_ERROR"},
    };
    for (size_t i = 0; i < sizeof(ERRORS) / sizeof(ERRORS[0]); ++i) {
        TEST_ASSERT_EQUAL_STRING(ERRORS[i].code, lc_mqtt_error_code(ERRORS[i].error));
        result = result_for("open_compartment", ERRORS[i].error);
        TEST_ASSERT_TRUE(lc_mqtt_encode_response(&result, &envelope, encoded, sizeof(encoded)) > 0);
        message = emit_message("payloads/response-command-error.json", encoded);
        TEST_ASSERT_EQUAL_STRING("error", emitted_string(message, "result"));
        TEST_ASSERT_EQUAL_STRING(ERRORS[i].code, emitted_string(message, "error_code"));
        TEST_ASSERT_TRUE(strlen(emitted_string(message, "message")) > 0);
        cJSON_Delete(message);
    }

    result = result_for("apply_config", LC_ERROR_NONE);
    snprintf(result.applied_config_hash, sizeof(result.applied_config_hash), "%s", HASH);
    TEST_ASSERT_TRUE(lc_mqtt_encode_response(&result, &envelope, encoded, sizeof(encoded)) > 0);
    message = emit_message("payloads/response-apply-config-success.json", encoded);
    TEST_ASSERT_EQUAL_STRING(HASH, emitted_string(message, "applied_config_hash"));
    cJSON_Delete(message);

    /* A buffer that is too small is reported, not overrun. */
    TEST_ASSERT_EQUAL_UINT(0, lc_mqtt_encode_response(&result, &envelope, encoded, 16));
}

/* The defect of an update_firmware command whose `data` is `data_json`. */
static lc_error_t update_defect(const char *data_json)
{
    char text[LC_MQTT_PAYLOAD_MAX];
    snprintf(text, sizeof(text),
             "{\"message_id\":\"m\",\"transaction_id\":\"t\",\"action\":\"update_firmware\","
             "\"timestamp\":\"2026-10-22T09:00:00Z\",\"data\":%s}",
             data_json);
    TEST_ASSERT_TRUE(decode(text));
    return command.defect;
}

void test_S7_H05_malformed_non_https_and_over_long_updates_are_update_invalid(void)
{
    char data[LC_MQTT_PAYLOAD_MAX];
    char url[300];

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_INVALID, update_defect("{}"));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_INVALID, update_defect("{\"version\":\"1.0.0\"}"));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_INVALID,
                          update_defect("{\"url\":\"https://example.com/a.bin\"}"));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_INVALID,
                          update_defect("{\"version\":1,\"url\":\"https://example.com/a.bin\"}"));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_INVALID,
                          update_defect("{\"version\":\"1.0.0\",\"url\":\"https://e.com/a\","
                                        "\"allow_downgrade\":\"yes\"}"));

    /* A version that is not x.y.z with an optional suffix, or that does not fit. */
    static const char *const BAD_VERSIONS[] = {"", "1", "1.0", "v1.0.0", "1.0.0.0", "1.0.0-",
                                               "1.0.0 beta", "1.0.0-a_b", "01.2.3", "1.02.3",
                                               "1.0.0-01", "1.0.0-rc.", "1.0.0-a..b",
                                               "1.0.0-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"};
    for (size_t i = 0; i < sizeof(BAD_VERSIONS) / sizeof(BAD_VERSIONS[0]); ++i) {
        snprintf(data, sizeof(data), "{\"version\":\"%s\",\"url\":\"https://e.com/a\"}",
                 BAD_VERSIONS[i]);
        TEST_ASSERT_EQUAL_INT_MESSAGE(LC_ERROR_UPDATE_INVALID, update_defect(data), BAD_VERSIONS[i]);
    }

    /* Only HTTPS, with a host, in printable ASCII. */
    static const char *const BAD_URLS[] = {"", "http://e.com/a.bin", "HTTP://e.com/a.bin",
                                           "ftp://e.com/a.bin", "https:/e.com", "https://",
                                           "//e.com/a.bin", "e.com/a.bin", "https://e.com/a b",
                                           "https://e.com/a\\u0001b", "HTTPS://e.com/a.bin"};
    for (size_t i = 0; i < sizeof(BAD_URLS) / sizeof(BAD_URLS[0]); ++i) {
        snprintf(data, sizeof(data), "{\"version\":\"1.0.0\",\"url\":\"%s\"}", BAD_URLS[i]);
        TEST_ASSERT_EQUAL_INT_MESSAGE(LC_ERROR_UPDATE_INVALID, update_defect(data), BAD_URLS[i]);
    }

    /* 256 characters fit; one more does not. */
    memset(url, 'a', sizeof(url));
    memcpy(url, "https://e.com/", 14);
    url[256] = '\0';
    snprintf(data, sizeof(data), "{\"version\":\"1.0.0\",\"url\":\"%s\"}", url);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update_defect(data));
    TEST_ASSERT_EQUAL_STRING(url, command.firmware.url);
    url[256] = 'a';
    url[257] = '\0';
    snprintf(data, sizeof(data), "{\"version\":\"1.0.0\",\"url\":\"%s\"}", url);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_INVALID, update_defect(data));

    /* The error schema allows only UPDATE_* codes, so a bad envelope is UPDATE_INVALID too. */
    static const char *const BAD_ENVELOPES[] = {
        "{\"transaction_id\":\"t\",\"message_id\":\"m\",\"action\":\"update_firmware\","
        "\"timestamp\":\"2026-10-22T09:00:00Z\"}",
        "{\"transaction_id\":\"t\",\"message_id\":\"m\",\"action\":\"update_firmware\","
        "\"timestamp\":\"2026-10-22T09:00:00Z\",\"data\":[]}",
        "{\"transaction_id\":\"t\",\"message_id\":\"m\",\"action\":\"update_firmware\","
        "\"timestamp\":\"yesterday\",\"data\":{}}",
        "{\"transaction_id\":\"t\",\"action\":\"update_firmware\","
        "\"timestamp\":\"2026-10-22T09:00:00Z\",\"data\":{}}",
    };
    for (size_t i = 0; i < sizeof(BAD_ENVELOPES) / sizeof(BAD_ENVELOPES[0]); ++i) {
        TEST_ASSERT_TRUE_MESSAGE(decode(BAD_ENVELOPES[i]), BAD_ENVELOPES[i]);
        TEST_ASSERT_EQUAL_INT_MESSAGE(LC_ERROR_UPDATE_INVALID, command.defect, BAD_ENVELOPES[i]);
    }
}

void test_S7_H13_update_messages_match_their_schemas(void)
{
    static const struct {
        lc_error_t error;
        const char *code;
    } REFUSALS[] = {
        {LC_ERROR_UPDATE_INVALID, "UPDATE_INVALID"},
        {LC_ERROR_UPDATE_BUSY, "UPDATE_BUSY"},
        {LC_ERROR_UPDATE_NOT_READY, "UPDATE_NOT_READY"},
        {LC_ERROR_UPDATE_SAME_VERSION, "UPDATE_SAME_VERSION"},
        {LC_ERROR_UPDATE_DOWNGRADE, "UPDATE_DOWNGRADE"},
        {LC_ERROR_UPDATE_VERSION_REJECTED, "UPDATE_VERSION_REJECTED"},
    };

    lc_command_result_t result = result_for("update_firmware", LC_ERROR_NONE);
    TEST_ASSERT_TRUE(lc_mqtt_encode_response(&result, &envelope, encoded, sizeof(encoded)) > 0);
    cJSON *message = emit_message("payloads/response-update-firmware-success.json", encoded);
    TEST_ASSERT_EQUAL_STRING("update_firmware", emitted_string(message, "action"));
    TEST_ASSERT_EQUAL_STRING("Update accepted; download started.", emitted_string(message, "message"));
    cJSON_Delete(message);

    for (size_t i = 0; i < sizeof(REFUSALS) / sizeof(REFUSALS[0]); ++i) {
        result = result_for("update_firmware", REFUSALS[i].error);
        TEST_ASSERT_TRUE(lc_mqtt_encode_response(&result, &envelope, encoded, sizeof(encoded)) > 0);
        message = emit_message("payloads/response-update-firmware-error.json", encoded);
        TEST_ASSERT_EQUAL_STRING(REFUSALS[i].code, emitted_string(message, "error_code"));
        cJSON_Delete(message);
    }

    /* A failure after acceptance is an event, not a second response. */
    static const lc_error_t FAILURES[] = {LC_ERROR_UPDATE_DOWNLOAD_FAILED,
                                          LC_ERROR_UPDATE_VERIFY_FAILED};
    static const char *const FAILURE_CODES[] = {"UPDATE_DOWNLOAD_FAILED", "UPDATE_VERIFY_FAILED"};
    for (size_t i = 0; i < sizeof(FAILURES) / sizeof(FAILURES[0]); ++i) {
        lc_firmware_update_failure_t failure = {.error = FAILURES[i]};
        snprintf(failure.transaction_id, sizeof(failure.transaction_id), "txn-fw-001");
        TEST_ASSERT_TRUE(lc_mqtt_encode_firmware_update_failed(&failure, &envelope, encoded,
                                                               sizeof(encoded)) > 0);
        message = emit_message("payloads/event-firmware-update-failed.json", encoded);
        TEST_ASSERT_EQUAL_STRING("firmware_update_failed", emitted_string(message, "event"));
        const cJSON *data = cJSON_GetObjectItemCaseSensitive(message, "data");
        TEST_ASSERT_EQUAL_STRING("txn-fw-001", emitted_string(data, "transaction_id"));
        TEST_ASSERT_EQUAL_STRING(FAILURE_CODES[i], emitted_string(data, "error_code"));
        cJSON_Delete(message);
    }

    /* The firmware fields of the heartbeat, with `trial` whenever the version is there. */
    lc_firmware_status_t firmware = {.running_slot = LC_OTA_SLOT_1, .trial = true, .update_capable = true};
    snprintf(firmware.version, sizeof(firmware.version), "0.2.0");
    snprintf(firmware.last_rollback_version, sizeof(firmware.last_rollback_version), "0.1.9");
    TEST_ASSERT_TRUE(lc_mqtt_encode_heartbeat(120, true, &firmware, &envelope, encoded,
                                              sizeof(encoded)) > 0);
    message = emit_message("payloads/state-heartbeat.json", encoded);
    TEST_ASSERT_EQUAL_STRING("0.2.0", emitted_string(message, "firmware_version"));
    TEST_ASSERT_EQUAL_STRING("ota_1", emitted_string(message, "running_slot"));
    TEST_ASSERT_TRUE(cJSON_IsTrue(cJSON_GetObjectItemCaseSensitive(message, "trial")));
    TEST_ASSERT_EQUAL_STRING("0.1.9", emitted_string(message, "last_rollback_version"));
    const cJSON *capabilities = cJSON_GetObjectItemCaseSensitive(message, "capabilities");
    TEST_ASSERT_EQUAL_INT(1, cJSON_GetArraySize(capabilities));
    TEST_ASSERT_EQUAL_STRING("firmware_update", cJSON_GetArrayItem(capabilities, 0)->valuestring);
    cJSON_Delete(message);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S2_H01_command_examples_parse_into_typed_commands);
    RUN_TEST(test_S2_H02_payload_without_transaction_id_is_dropped);
    RUN_TEST(test_S2_H03_payload_without_message_id_is_answered_with_missing_message_id);
    RUN_TEST(test_S2_H04_malformed_commands_are_invalid_command);
    RUN_TEST(test_S2_H05_unknown_action_is_unknown_action);
    RUN_TEST(test_S2_H06_hostile_payloads_are_rejected);
    RUN_TEST(test_S2_H07_fragments_in_order_assemble_into_one_payload);
    RUN_TEST(test_S2_H08_gap_overlap_or_oversize_discards_the_message);
    RUN_TEST(test_S2_H09_outbound_messages_carry_a_uuid_message_id_and_utc_timestamp);
    RUN_TEST(test_S2_H10_responses_match_their_schemas);
    RUN_TEST(test_S7_H05_malformed_non_https_and_over_long_updates_are_update_invalid);
    RUN_TEST(test_S7_H13_update_messages_match_their_schemas);
    return UNITY_END();
}
