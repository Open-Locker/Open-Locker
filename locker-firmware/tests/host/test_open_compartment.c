#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "unity.h"

void setUp(void)
{
    fx_init();
}

void tearDown(void)
{
}

static lc_error_t open(const char *transaction_id, uint16_t compartment_number)
{
    char message_id[LC_ID_MAX + 1];
    snprintf(message_id, sizeof(message_id), "msg-for-%s", transaction_id);
    const lc_command_t command = fx_open(transaction_id, message_id, compartment_number);
    lc_app_handle_command(&fx_app, &command);
    const lc_command_result_t *response = fake_outbound_last_response(&fx_outbound);
    TEST_ASSERT_NOT_NULL(response);
    TEST_ASSERT_EQUAL_STRING(transaction_id, response->transaction_id);
    TEST_ASSERT_EQUAL_STRING("open_compartment", response->action_name);
    return response->error;
}

void test_S2_H40_open_before_any_configuration_is_runtime_config_not_applied(void)
{
    fx_connect();

    TEST_ASSERT_EQUAL_INT(LC_ERROR_RUNTIME_CONFIG_NOT_APPLIED, open("tx-1", 1));

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

void test_S2_H41_open_of_an_unmapped_compartment_is_compartment_not_found(void)
{
    fx_configure();

    TEST_ASSERT_EQUAL_INT(LC_ERROR_COMPARTMENT_NOT_FOUND, open("tx-1", 3));

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

void test_S2_H42_acknowledged_unlock_is_success(void)
{
    fx_configure();
    fx_bus.unlock_result = LC_UNLOCK_ACKNOWLEDGED;

    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, open("tx-1", 1));

    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
}

void test_S2_H43_unlock_that_was_not_sent_is_a_hardware_error_without_detection(void)
{
    fx_configure();
    fx_bus.unlock_result = LC_UNLOCK_NOT_SENT;

    TEST_ASSERT_EQUAL_INT(LC_ERROR_HARDWARE_ERROR, open("tx-1", 1));

    /* The lock cannot have moved, so there is no door to watch. */
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(2 * FX_HEARTBEAT_SECONDS * 1000);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_OPEN_DETECTION]);
}

void test_S2_H44_unlock_with_unknown_outcome_is_a_hardware_error_with_detection(void)
{
    fx_configure();
    fx_bus.unlock_result = LC_UNLOCK_OUTCOME_UNKNOWN;

    TEST_ASSERT_EQUAL_INT(LC_ERROR_HARDWARE_ERROR, open("tx-1", 1));

    /* The lock may have moved: the door is watched and reported. */
    fx_advance_acknowledging(2 * FX_HEARTBEAT_SECONDS * 1000);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
    TEST_ASSERT_EQUAL_STRING("tx-1", fx_outbound.detections[0].transaction_id);
    TEST_ASSERT_EQUAL_UINT16(1, fx_outbound.detections[0].compartment_number);
}

void test_S2_H45_bus_is_called_once_with_the_mapped_board_and_address(void)
{
    static const lc_mapping_t MAPPINGS[] = {
        {.number = 5, .board = 7, .address = 12},
        {.number = 9, .board = 7, .address = 3},
    };
    fx_connect();
    const lc_command_t apply =
        fx_apply("tx-config", "msg-config", MAPPINGS, 2, LC_FEEDBACK_DOOR_CLOSING, 15);
    lc_app_handle_command(&fx_app, &apply);
    fx_reset_counts();

    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, open("tx-1", 9));

    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT16(9, fx_bus.last_target.compartment_number);
    TEST_ASSERT_EQUAL_UINT8(7, fx_bus.last_target.board);
    TEST_ASSERT_EQUAL_UINT8(3, fx_bus.last_target.address);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S2_H40_open_before_any_configuration_is_runtime_config_not_applied);
    RUN_TEST(test_S2_H41_open_of_an_unmapped_compartment_is_compartment_not_found);
    RUN_TEST(test_S2_H42_acknowledged_unlock_is_success);
    RUN_TEST(test_S2_H43_unlock_that_was_not_sent_is_a_hardware_error_without_detection);
    RUN_TEST(test_S2_H44_unlock_with_unknown_outcome_is_a_hardware_error_with_detection);
    RUN_TEST(test_S2_H45_bus_is_called_once_with_the_mapped_board_and_address);
    return UNITY_END();
}
