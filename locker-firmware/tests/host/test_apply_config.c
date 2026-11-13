#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "unity.h"

static const lc_mapping_t THREE[] = {
    {.number = 1, .board = 4, .address = 0},
    {.number = 2, .board = 4, .address = 1},
    {.number = 3, .board = 4, .address = 2},
};

void setUp(void)
{
    fx_init();
    fx_connect();
}

void tearDown(void)
{
}

static const lc_command_result_t *apply_three(const char *transaction_id, const char *message_id)
{
    const lc_command_t command =
        fx_apply(transaction_id, message_id, THREE, 3, LC_FEEDBACK_DOOR_OPENING, 30);
    lc_app_handle_command(&fx_app, &command);
    const lc_command_result_t *response = fake_outbound_last_response(&fx_outbound);
    TEST_ASSERT_NOT_NULL(response);
    TEST_ASSERT_EQUAL_STRING(transaction_id, response->transaction_id);
    TEST_ASSERT_EQUAL_STRING("apply_config", response->action_name);
    return response;
}

static void expected_hash(char out[LC_SHA256_HEX_SIZE])
{
    char canonical[LC_CONFIG_CANONICAL_MAX];
    fx_canonical(THREE, 3, LC_FEEDBACK_DOOR_OPENING, canonical, sizeof(canonical));
    fake_sha256_hex(canonical, out);
}

void test_S2_H46_valid_apply_config_is_saved_sets_the_profile_and_reports_its_hash(void)
{
    char hash[LC_SHA256_HEX_SIZE];
    expected_hash(hash);

    const lc_command_result_t *response = apply_three("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, response->error);
    TEST_ASSERT_EQUAL_STRING(hash, response->applied_config_hash);

    TEST_ASSERT_EQUAL_UINT(1, fx_config_store.save_count);
    TEST_ASSERT_TRUE(fx_config_store.config.applied);
    TEST_ASSERT_EQUAL_STRING(hash, fx_config_store.config.hash);
    TEST_ASSERT_EQUAL_UINT(3, fx_config_store.config.count);
    TEST_ASSERT_EQUAL_UINT32(30, fx_config_store.config.heartbeat_seconds);

    TEST_ASSERT_EQUAL_UINT(1, fx_bus.profile_count);
    TEST_ASSERT_EQUAL_INT(LC_FEEDBACK_DOOR_OPENING, fx_bus.last_profile.feedback);

    const lc_config_t *config = lc_app_config(&fx_app);
    TEST_ASSERT_TRUE(config->applied);
    TEST_ASSERT_EQUAL_STRING(hash, config->hash);
    TEST_ASSERT_EQUAL_UINT8(4, config->mappings[2].board);
    TEST_ASSERT_EQUAL_UINT8(2, config->mappings[2].address);

    /* It is in effect at once and survives a restart. */
    const lc_command_t open = fx_open("tx-open", "msg-open", 3);
    lc_app_handle_command(&fx_app, &open);
    TEST_ASSERT_EQUAL_UINT8(2, fx_bus.last_target.address);
    fx_restart();
    TEST_ASSERT_TRUE(lc_app_config(&fx_app)->applied);
    TEST_ASSERT_EQUAL_STRING(hash, lc_app_config(&fx_app)->hash);
}

void test_S2_H47_valid_apply_config_is_saved_while_the_bus_is_disconnected(void)
{
    fx_bus.state = LC_BUS_UNREACHABLE;
    fx_bus.read_ok = false;

    const lc_command_result_t *response = apply_three("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, response->error);
    TEST_ASSERT_EQUAL_UINT(1, fx_config_store.save_count);
    TEST_ASSERT_TRUE(lc_app_config(&fx_app)->applied);
}

void test_S2_H48_store_failure_is_an_error_and_keeps_the_previous_configuration(void)
{
    fx_configure();
    char previous[LC_CONFIG_HASH_SIZE];
    snprintf(previous, sizeof(previous), "%s", lc_app_config(&fx_app)->hash);
    fx_config_store.save_ok = false;

    const lc_command_result_t *response = apply_three("tx-1", "msg-1");

    TEST_ASSERT_TRUE(response->error != LC_ERROR_NONE);
    TEST_ASSERT_EQUAL_STRING("", response->applied_config_hash);
    TEST_ASSERT_EQUAL_STRING(previous, lc_app_config(&fx_app)->hash);
    TEST_ASSERT_EQUAL_UINT(2, lc_app_config(&fx_app)->count);
    /* Compartment 3 exists only in the configuration that was not stored. */
    const lc_command_t open = fx_open("tx-open", "msg-open", 3);
    lc_app_handle_command(&fx_app, &open);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_COMPARTMENT_NOT_FOUND,
                          fake_outbound_last_response(&fx_outbound)->error);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

void test_S2_H49_rejected_apply_config_keeps_the_previous_configuration(void)
{
    fx_configure();
    char previous[LC_CONFIG_HASH_SIZE];
    snprintf(previous, sizeof(previous), "%s", lc_app_config(&fx_app)->hash);

    lc_command_t command = fx_apply("tx-1", "msg-1", THREE, 3, LC_FEEDBACK_DOOR_OPENING, 30);
    command.config.hash[0] = command.config.hash[0] == '0' ? '1' : '0';
    lc_app_handle_command(&fx_app, &command);

    TEST_ASSERT_EQUAL_INT(LC_ERROR_INVALID_CONFIG,
                          fake_outbound_last_response(&fx_outbound)->error);
    TEST_ASSERT_EQUAL_STRING(previous, lc_app_config(&fx_app)->hash);
    TEST_ASSERT_EQUAL_UINT(0, fx_config_store.save_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.profile_count);
    TEST_ASSERT_EQUAL_STRING(previous, fx_config_store.config.hash);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S2_H46_valid_apply_config_is_saved_sets_the_profile_and_reports_its_hash);
    RUN_TEST(test_S2_H47_valid_apply_config_is_saved_while_the_bus_is_disconnected);
    RUN_TEST(test_S2_H48_store_failure_is_an_error_and_keeps_the_previous_configuration);
    RUN_TEST(test_S2_H49_rejected_apply_config_keeps_the_previous_configuration);
    return UNITY_END();
}
