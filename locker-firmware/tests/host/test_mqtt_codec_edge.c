#include <stdio.h>
#include <string.h>

#include "fakes/emitted.h"
#include "lc_mqtt_codec/codec.h"
#include "unity.h"

/* Behaviour of the codec beyond the plan's list. */

static lc_command_t command;
static lc_mqtt_envelope_t envelope;
static char encoded[512];

void setUp(void)
{
    memset(&command, 0, sizeof(command));
    static const uint8_t RANDOM[16] = {1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16};
    lc_mqtt_envelope(RANDOM, 1776195060, &envelope);
}

void tearDown(void)
{
}

#define OPEN_WITH(timestamp)                                                                     \
    "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"open_compartment\","        \
    "\"timestamp\":\"" timestamp "\",\"data\":{\"compartment_number\":3}}"

static lc_error_t defect_of(const char *payload)
{
    memset(&command, 0, sizeof(command));
    TEST_ASSERT_TRUE_MESSAGE(lc_mqtt_decode_command(payload, strlen(payload), &command), payload);
    return command.defect;
}

void test_timestamps_follow_rfc_3339(void)
{
    static const char *const VALID[] = {
        OPEN_WITH("2026-04-14T19:30:00Z"),       OPEN_WITH("2026-04-14T19:30:00.123Z"),
        OPEN_WITH("2026-04-14T19:30:00+02:00"),  OPEN_WITH("2026-04-14T19:30:00-05:30"),
        OPEN_WITH("2024-02-29T00:00:00Z"),       OPEN_WITH("2026-04-14t19:30:60z"),
    };
    static const char *const INVALID[] = {
        OPEN_WITH("2026-04-14 19:30:00Z"),  OPEN_WITH("2026-13-14T19:30:00Z"),
        OPEN_WITH("2026-00-14T19:30:00Z"),  OPEN_WITH("2026-04-31T19:30:00Z"),
        OPEN_WITH("2026-02-29T19:30:00Z"),  OPEN_WITH("2026-04-14T24:30:00Z"),
        OPEN_WITH("2026-04-14T19:60:00Z"),  OPEN_WITH("2026-04-14T19:30:00"),
        OPEN_WITH("2026-04-14T19:30:00.Z"), OPEN_WITH("2026-04-14T19:30:00+2:00"),
        OPEN_WITH("2026-04-14T19:30:00+24:00"), OPEN_WITH("2026-04-14T19:30:00+02:60"),
        OPEN_WITH("2026-04-14T19:30:00ZZ"), OPEN_WITH("26-04-14T19:30:00Z"),
        OPEN_WITH("2026-04-1xT19:30:00Z"),
    };

    for (size_t i = 0; i < sizeof(VALID) / sizeof(VALID[0]); ++i) {
        TEST_ASSERT_EQUAL_INT_MESSAGE(LC_ERROR_NONE, defect_of(VALID[i]), VALID[i]);
    }
    for (size_t i = 0; i < sizeof(INVALID) / sizeof(INVALID[0]); ++i) {
        TEST_ASSERT_EQUAL_INT_MESSAGE(LC_ERROR_INVALID_COMMAND, defect_of(INVALID[i]),
                                      INVALID[i]);
    }
}

void test_identifiers_must_be_printable_ascii(void)
{
    TEST_ASSERT_FALSE(lc_mqtt_decode_command(
        "{\"transaction_id\":\"t\\n1\",\"message_id\":\"m1\"}", 40, &command));
    TEST_ASSERT_FALSE(lc_mqtt_decode_command("{\"transaction_id\":\"t\xC3\xA9\"}", 22, &command));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_COMMAND,
                          defect_of("{\"transaction_id\":\"t1\",\"message_id\":\"m\\t1\","
                                    "\"action\":\"open_compartment\"}"));
    /* A message id that is not a string. */
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_COMMAND,
                          defect_of("{\"transaction_id\":\"t1\",\"message_id\":5}"));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_MISSING_MESSAGE_ID,
                          defect_of("{\"transaction_id\":\"t1\",\"message_id\":\"\"}"));
}

void test_the_action_name_is_echoed_in_printable_form_only(void)
{
    defect_of("{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"a\\u0001b\\u00e9\"}");

    TEST_ASSERT_EQUAL_STRING("a?b??", command.action_name);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_COMMAND, command.defect);

    /* Too long to echo: cut, and the command is invalid. */
    char payload[256];
    char name[64];
    memset(name, 'n', sizeof(name) - 1);
    name[sizeof(name) - 1] = '\0';
    snprintf(payload, sizeof(payload),
             "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"%s\"}", name);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_COMMAND, defect_of(payload));
    TEST_ASSERT_EQUAL_UINT(LC_ACTION_NAME_MAX, strlen(command.action_name));

    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_COMMAND,
                          defect_of("{\"transaction_id\":\"t1\",\"message_id\":\"m1\","
                                    "\"action\":\"\"}"));
}

void test_apply_config_fields_are_checked(void)
{
#define APPLY(data)                                                                          \
    "{\"transaction_id\":\"t1\",\"message_id\":\"m1\",\"action\":\"apply_config\","         \
    "\"timestamp\":\"2026-04-14T19:31:00Z\",\"data\":{" data "}}"
#define COMPARTMENT "\"compartments\":[{\"compartment_number\":1,\"slaveId\":1,\"address\":0}]"
#define COMMON "\"config_hash\":\"abc\",\"heartbeat_interval_seconds\":15,\"adapter_type\":\"rs485_lock_board\""

    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE,
                          defect_of(APPLY(COMMON ",\"feedback_type\":\"door_opening\"," COMPARTMENT)));
    TEST_ASSERT_EQUAL_INT(LC_FEEDBACK_DOOR_OPENING, command.config.feedback);

    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          defect_of(APPLY(COMMON ",\"feedback_type\":\"sideways\"," COMPARTMENT)));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG, defect_of(APPLY(COMMON "," COMPARTMENT)));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          defect_of(APPLY("\"heartbeat_interval_seconds\":15,"
                                          "\"adapter_type\":\"rs485_lock_board\","
                                          "\"feedback_type\":\"door_closing\"," COMPARTMENT)));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          defect_of(APPLY("\"config_hash\":\"abc\",\"heartbeat_interval_seconds\":0,"
                                          "\"adapter_type\":\"rs485_lock_board\","
                                          "\"feedback_type\":\"door_closing\"," COMPARTMENT)));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          defect_of(APPLY("\"config_hash\":\"abc\","
                                          "\"adapter_type\":\"rs485_lock_board\","
                                          "\"feedback_type\":\"door_closing\"," COMPARTMENT)));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          defect_of(APPLY(COMMON ",\"feedback_type\":\"door_closing\","
                                                 "\"compartments\":{}")));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          defect_of(APPLY(COMMON ",\"feedback_type\":\"door_closing\","
                                                 "\"compartments\":[5]")));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          defect_of(APPLY(COMMON ",\"feedback_type\":\"door_closing\","
                                                 "\"compartments\":[{\"compartment_number\":1,"
                                                 "\"slaveId\":1}]")));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          defect_of(APPLY("\"config_hash\":\"abc\",\"heartbeat_interval_seconds\":15,"
                                          "\"feedback_type\":\"door_closing\"," COMPARTMENT)));
    /* A hash that is too long to be a hash. */
    char hash[100];
    memset(hash, 'a', sizeof(hash) - 1);
    hash[sizeof(hash) - 1] = '\0';
    char payload[600];
    snprintf(payload, sizeof(payload), APPLY("\"config_hash\":\"%s\"," "\"heartbeat_interval_seconds\":15,"
                                             "\"adapter_type\":\"rs485_lock_board\","
                                             "\"feedback_type\":\"door_closing\"," COMPARTMENT),
             hash);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG, defect_of(payload));
}

void test_fragments_that_do_not_fit_are_refused(void)
{
    lc_mqtt_assembler_t assembler;
    memset(&assembler, 0, sizeof(assembler));

    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, "abc", 0, 0, 3));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, "abc", 3, 0, 0));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, "abc", 3, 0, 2));
    /* A fragment of another message in the middle of one. */
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, "ab", 2, 0, 4));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, "cd", 2, 2, 9));
    TEST_ASSERT_FALSE(lc_mqtt_assemble(&assembler, "cd", 2, 2, 4));
}

void test_encoded_text_is_escaped(void)
{
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    snprintf(result.transaction_id, sizeof(result.transaction_id), "a\"b\\c\x01");
    snprintf(result.action_name, sizeof(result.action_name), "open_compartment");

    TEST_ASSERT_TRUE(lc_mqtt_encode_response(&result, &envelope, encoded, sizeof(encoded)) > 0);

    cJSON *message = emit_message("payloads/response-command-success.json", encoded);
    TEST_ASSERT_EQUAL_STRING("a\"b\\c\x01", emitted_string(message, "transaction_id"));
    cJSON_Delete(message);
}

void test_envelope_dates_cover_leap_days_and_the_epoch(void)
{
    static const uint8_t RANDOM[16] = {0};
    lc_mqtt_envelope_t value;

    lc_mqtt_envelope(RANDOM, 0, &value);
    TEST_ASSERT_EQUAL_STRING("1970-01-01T00:00:00Z", value.timestamp);
    lc_mqtt_envelope(RANDOM, -5, &value);
    TEST_ASSERT_EQUAL_STRING("1970-01-01T00:00:00Z", value.timestamp);
    lc_mqtt_envelope(RANDOM, 951782400, &value);
    TEST_ASSERT_EQUAL_STRING("2000-02-29T00:00:00Z", value.timestamp);
    lc_mqtt_envelope(RANDOM, 1709251199, &value);
    TEST_ASSERT_EQUAL_STRING("2024-02-29T23:59:59Z", value.timestamp);
    lc_mqtt_envelope(RANDOM, 4102444799, &value);
    TEST_ASSERT_EQUAL_STRING("2099-12-31T23:59:59Z", value.timestamp);
}

void test_heartbeat_that_does_not_fit_is_reported(void)
{
    for (size_t capacity = 0; capacity < 60; ++capacity) {
        TEST_ASSERT_EQUAL_UINT(0, lc_mqtt_encode_heartbeat(5, true, &envelope, encoded, capacity));
    }
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_timestamps_follow_rfc_3339);
    RUN_TEST(test_identifiers_must_be_printable_ascii);
    RUN_TEST(test_the_action_name_is_echoed_in_printable_form_only);
    RUN_TEST(test_apply_config_fields_are_checked);
    RUN_TEST(test_fragments_that_do_not_fit_are_refused);
    RUN_TEST(test_encoded_text_is_escaped);
    RUN_TEST(test_envelope_dates_cover_leap_days_and_the_epoch);
    RUN_TEST(test_heartbeat_that_does_not_fit_is_reported);
    return UNITY_END();
}
