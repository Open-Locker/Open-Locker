#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "lc_domain/config.h"
#include "lc_mqtt_codec/codec.h"
#include "unity.h"

/*
 * Configuration rules, followed from the JSON payload through validation and
 * the canonical hash to the application accepting or refusing it.
 */

static const lc_mapping_t TWO[] = {
    {.number = 1, .board = 1, .address = 0},
    {.number = 2, .board = 1, .address = 1},
};

static char payload[LC_MQTT_PAYLOAD_MAX + 1];
static lc_command_t command;
static unsigned transaction;

void setUp(void)
{
    fx_init();
    fx_connect();
    transaction = 0;
}

void tearDown(void)
{
}

/* Applies the mappings with a hash that is correct for them. */
static lc_error_t apply(const lc_mapping_t *mappings, size_t count)
{
    char transaction_id[32];
    char message_id[32];
    snprintf(transaction_id, sizeof(transaction_id), "tx-%u", ++transaction);
    snprintf(message_id, sizeof(message_id), "msg-%u", transaction);
    const lc_command_t apply_command = fx_apply(transaction_id, message_id, mappings, count,
                                                LC_FEEDBACK_DOOR_CLOSING, FX_HEARTBEAT_SECONDS);
    lc_app_handle_command(&fx_app, &apply_command);
    const lc_command_result_t *response = fake_outbound_last_response(&fx_outbound);
    TEST_ASSERT_NOT_NULL(response);
    TEST_ASSERT_EQUAL_STRING(transaction_id, response->transaction_id);
    return response->error;
}

static void assert_refused(const lc_mapping_t *mappings, size_t count)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG, apply(mappings, count));
    TEST_ASSERT_FALSE(lc_app_config(&fx_app)->applied);
    TEST_ASSERT_EQUAL_UINT(0, fx_config_store.save_count);
}

/* An apply_config payload with a correct hash for its mappings. */
static void build_payload(const char *adapter_type, const char *compartments_json,
                          const lc_mapping_t *mappings, size_t count)
{
    char canonical[LC_CONFIG_CANONICAL_MAX];
    char hash[LC_SHA256_HEX_SIZE];
    fx_canonical(mappings, count, LC_FEEDBACK_DOOR_CLOSING, canonical, sizeof(canonical));
    fake_sha256_hex(canonical, hash);
    int length = snprintf(
        payload, sizeof(payload),
        "{\"message_id\":\"m1\",\"transaction_id\":\"t1\",\"action\":\"apply_config\","
        "\"timestamp\":\"2026-04-14T19:31:00Z\",\"data\":{\"config_hash\":\"%s\","
        "\"heartbeat_interval_seconds\":15,\"adapter_type\":\"%s\","
        "\"feedback_type\":\"door_closing\",\"compartments\":%s}}",
        hash, adapter_type, compartments_json);
    TEST_ASSERT_TRUE(length > 0 && (size_t)length < sizeof(payload));
}

void test_S2_H11_canonical_hash_of_the_example_equals_its_config_hash(void)
{
    char path[512];
    snprintf(path, sizeof(path), "%s/docs/asyncapi/examples/command-apply-config.json",
             LC_REPO_ROOT);
    FILE *file = fopen(path, "rb");
    TEST_ASSERT_NOT_NULL_MESSAGE(file, path);
    size_t length = fread(payload, 1, LC_MQTT_PAYLOAD_MAX, file);
    fclose(file);

    TEST_ASSERT_TRUE(lc_mqtt_decode_command(payload, length, &command));
    lc_config_t config;
    TEST_ASSERT_TRUE(lc_config_from_request(&command.config, &config));
    char canonical[LC_CONFIG_CANONICAL_MAX];
    TEST_ASSERT_TRUE(lc_config_canonical(&config, canonical, sizeof(canonical)));

    TEST_ASSERT_EQUAL_STRING(
        "{\"adapter_type\":\"rs485_lock_board\",\"feedback_type\":\"door_closing\","
        "\"compartments\":[{\"compartment_number\":1,\"slaveId\":1,\"address\":0},"
        "{\"compartment_number\":2,\"slaveId\":1,\"address\":1}]}",
        canonical);
    char hash[LC_SHA256_HEX_SIZE];
    fake_sha256_hex(canonical, hash);
    TEST_ASSERT_EQUAL_STRING(command.config.hash, hash);
    TEST_ASSERT_EQUAL_STRING(command.config.hash, config.hash);

    /* The application accepts the example as it is. */
    lc_app_handle_command(&fx_app, &command);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, fake_outbound_last_response(&fx_outbound)->error);
    TEST_ASSERT_TRUE(lc_app_config(&fx_app)->applied);
}

void test_S2_H12_input_order_does_not_change_the_hash(void)
{
    static const lc_mapping_t SHUFFLED[] = {
        {.number = 7, .board = 1, .address = 6},
        {.number = 1, .board = 1, .address = 0},
        {.number = 3, .board = 1, .address = 2},
    };
    lc_config_request_t request = {
        .supported_adapter = true,
        .feedback = LC_FEEDBACK_DOOR_OPENING,
        .heartbeat_seconds = 30,
        .count = 3,
    };
    memcpy(request.mappings, SHUFFLED, sizeof(SHUFFLED));

    lc_config_t config;
    TEST_ASSERT_TRUE(lc_config_from_request(&request, &config));
    TEST_ASSERT_EQUAL_UINT16(1, config.mappings[0].number);
    TEST_ASSERT_EQUAL_UINT16(3, config.mappings[1].number);
    TEST_ASSERT_EQUAL_UINT16(7, config.mappings[2].number);

    char canonical[LC_CONFIG_CANONICAL_MAX];
    char expected[LC_CONFIG_CANONICAL_MAX];
    TEST_ASSERT_TRUE(lc_config_canonical(&config, canonical, sizeof(canonical)));
    fx_canonical(SHUFFLED, 3, LC_FEEDBACK_DOOR_OPENING, expected, sizeof(expected));
    TEST_ASSERT_EQUAL_STRING(expected, canonical);

    /* A buffer that is too small is reported, not overrun. */
    TEST_ASSERT_FALSE(lc_config_canonical(&config, canonical, 32));
}

void test_S2_H13_hash_mismatch_is_rejected(void)
{
    lc_command_t wrong = fx_apply("tx-wrong", "msg-wrong", TWO, 2, LC_FEEDBACK_DOOR_CLOSING,
                                  FX_HEARTBEAT_SECONDS);
    /* The hash of the same mappings under the other polarity. */
    char canonical[LC_CONFIG_CANONICAL_MAX];
    fx_canonical(TWO, 2, LC_FEEDBACK_DOOR_OPENING, canonical, sizeof(canonical));
    fake_sha256_hex(canonical, wrong.config.hash);

    lc_app_handle_command(&fx_app, &wrong);

    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          fake_outbound_last_response(&fx_outbound)->error);
    TEST_ASSERT_FALSE(lc_app_config(&fx_app)->applied);
    TEST_ASSERT_EQUAL_UINT(0, fx_config_store.save_count);
}

void test_S2_H14_duplicate_numbers_and_duplicate_addresses_are_rejected(void)
{
    static const lc_mapping_t SAME_NUMBER[] = {
        {.number = 1, .board = 1, .address = 0},
        {.number = 1, .board = 1, .address = 1},
    };
    static const lc_mapping_t SAME_ADDRESS[] = {
        {.number = 1, .board = 1, .address = 0},
        {.number = 2, .board = 1, .address = 0},
    };

    assert_refused(SAME_NUMBER, 2);
    assert_refused(SAME_ADDRESS, 2);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, apply(TWO, 2));
}

void test_S2_H15_out_of_range_board_and_address_are_rejected(void)
{
    static const lc_mapping_t BOARD_0[] = {{.number = 1, .board = 0, .address = 0}};
    static const lc_mapping_t BOARD_32[] = {{.number = 1, .board = 32, .address = 0}};
    static const lc_mapping_t ADDRESS_255[] = {{.number = 1, .board = 1, .address = 255}};
    static const lc_mapping_t NUMBER_0[] = {{.number = 0, .board = 1, .address = 0}};
    static const lc_mapping_t LIMITS[] = {{.number = 1, .board = 31, .address = 254}};

    assert_refused(BOARD_0, 1);
    assert_refused(BOARD_32, 1);
    assert_refused(ADDRESS_255, 1);
    assert_refused(NUMBER_0, 1);

    /* Values that do not fit the wire types never reach validation. */
    build_payload("rs485_lock_board",
                  "[{\"compartment_number\":1,\"slaveId\":300,\"address\":0}]", TWO, 1);
    TEST_ASSERT_TRUE(lc_mqtt_decode_command(payload, strlen(payload), &command));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG, command.defect);
    build_payload("rs485_lock_board",
                  "[{\"compartment_number\":1,\"slaveId\":1,\"address\":-1}]", TWO, 1);
    TEST_ASSERT_TRUE(lc_mqtt_decode_command(payload, strlen(payload), &command));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG, command.defect);

    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, apply(LIMITS, 1));
}

void test_S2_H16_second_board_address_is_rejected(void)
{
    static const lc_mapping_t TWO_BOARDS[] = {
        {.number = 1, .board = 1, .address = 0},
        {.number = 2, .board = 2, .address = 0},
    };

    assert_refused(TWO_BOARDS, 2);
}

void test_S2_H17_other_adapter_types_are_rejected(void)
{
    build_payload("waveshare_modbus",
                  "[{\"compartment_number\":1,\"slaveId\":1,\"address\":0},"
                  "{\"compartment_number\":2,\"slaveId\":1,\"address\":1}]",
                  TWO, 2);
    TEST_ASSERT_TRUE(lc_mqtt_decode_command(payload, strlen(payload), &command));
    TEST_ASSERT_FALSE(command.config.supported_adapter);

    lc_app_handle_command(&fx_app, &command);

    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          fake_outbound_last_response(&fx_outbound)->error);
    TEST_ASSERT_FALSE(lc_app_config(&fx_app)->applied);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.profile_count);
}

void test_S2_H18_more_compartments_than_the_maximum_are_rejected(void)
{
    static char compartments[LC_MQTT_PAYLOAD_MAX];
    size_t length = 0;
    compartments[length++] = '[';
    for (unsigned number = 1; number <= LC_COMPARTMENTS_MAX + 1; ++number) {
        length += (size_t)snprintf(compartments + length, sizeof(compartments) - length,
                                   "%s{\"compartment_number\":%u,\"slaveId\":1,\"address\":%u}",
                                   number == 1 ? "" : ",", number, number - 1);
    }
    compartments[length++] = ']';
    compartments[length] = '\0';
    build_payload("rs485_lock_board", compartments, TWO, 2);

    TEST_ASSERT_TRUE(lc_mqtt_decode_command(payload, strlen(payload), &command));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG, command.defect);

    lc_app_handle_command(&fx_app, &command);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          fake_outbound_last_response(&fx_outbound)->error);
    TEST_ASSERT_FALSE(lc_app_config(&fx_app)->applied);

    /* Exactly the maximum is accepted. */
    lc_mapping_t full[LC_COMPARTMENTS_MAX];
    for (unsigned i = 0; i < LC_COMPARTMENTS_MAX; ++i) {
        full[i] = (lc_mapping_t){.number = (uint16_t)(i + 1), .board = 1, .address = (uint8_t)i};
    }
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, apply(full, LC_COMPARTMENTS_MAX));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S2_H11_canonical_hash_of_the_example_equals_its_config_hash);
    RUN_TEST(test_S2_H12_input_order_does_not_change_the_hash);
    RUN_TEST(test_S2_H13_hash_mismatch_is_rejected);
    RUN_TEST(test_S2_H14_duplicate_numbers_and_duplicate_addresses_are_rejected);
    RUN_TEST(test_S2_H15_out_of_range_board_and_address_are_rejected);
    RUN_TEST(test_S2_H16_second_board_address_is_rejected);
    RUN_TEST(test_S2_H17_other_adapter_types_are_rejected);
    RUN_TEST(test_S2_H18_more_compartments_than_the_maximum_are_rejected);
    return UNITY_END();
}
