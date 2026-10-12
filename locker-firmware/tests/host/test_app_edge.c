#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "unity.h"

/* Behaviour of the application beyond the plan's list. */

void setUp(void)
{
    fx_init();
    fx_configure();
}

void tearDown(void)
{
}

void test_commands_with_a_defect_are_answered_without_a_journal_entry(void)
{
    static const lc_error_t DEFECTS[] = {
        LC_ERROR_MISSING_MESSAGE_ID,
        LC_ERROR_INVALID_COMMAND,
        LC_ERROR_UNKNOWN_ACTION,
        LC_ERROR_INVALID_CONFIG,
    };

    for (size_t i = 0; i < sizeof(DEFECTS) / sizeof(DEFECTS[0]); ++i) {
        lc_command_t command = fx_open("tx-defect", "msg-defect", 1);
        command.defect = DEFECTS[i];
        snprintf(command.action_name, sizeof(command.action_name), "whatever");

        lc_app_handle_command(&fx_app, &command);

        TEST_ASSERT_EQUAL_INT(DEFECTS[i], fake_outbound_last_response(&fx_outbound)->error);
        TEST_ASSERT_EQUAL_STRING("whatever", fake_outbound_last_response(&fx_outbound)->action_name);
    }
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_journal.claim_count);
}

void test_an_action_the_application_does_not_know_is_answered_as_unknown(void)
{
    lc_command_t command = fx_open("tx-1", "msg-1", 1);
    command.action = LC_ACTION_UNKNOWN;
    snprintf(command.action_name, sizeof(command.action_name), "self_destruct");

    lc_app_handle_command(&fx_app, &command);

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UNKNOWN_ACTION, fake_outbound_last_response(&fx_outbound)->error);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

void test_a_bus_that_rejects_the_profile_fails_the_configuration(void)
{
    static const lc_mapping_t MOVED[] = {{.number = 1, .board = 2, .address = 5}};
    char previous[LC_CONFIG_HASH_SIZE];
    snprintf(previous, sizeof(previous), "%s", lc_app_config(&fx_app)->hash);
    fx_bus.apply_profile_ok = false;

    const lc_command_t apply = fx_apply("tx-1", "msg-1", MOVED, 1, LC_FEEDBACK_DOOR_OPENING, 30);
    lc_app_handle_command(&fx_app, &apply);

    TEST_ASSERT_EQUAL_INT(LC_ERROR_HARDWARE_ERROR,
                          fake_outbound_last_response(&fx_outbound)->error);
    TEST_ASSERT_EQUAL_STRING(previous, lc_app_config(&fx_app)->hash);
    TEST_ASSERT_EQUAL_UINT(0, fx_config_store.save_count);
}

void test_a_failed_store_restores_the_profile_of_the_configuration_in_effect(void)
{
    static const lc_mapping_t MOVED[] = {{.number = 1, .board = 1, .address = 5}};
    fx_config_store.save_ok = false;

    const lc_command_t apply = fx_apply("tx-1", "msg-1", MOVED, 1, LC_FEEDBACK_DOOR_OPENING, 30);
    lc_app_handle_command(&fx_app, &apply);

    TEST_ASSERT_EQUAL_UINT(2, fx_bus.profile_count);
    TEST_ASSERT_EQUAL_INT(LC_FEEDBACK_DOOR_CLOSING, fx_bus.last_profile.feedback);
}

void test_recovery_puts_the_stored_profile_on_the_bus(void)
{
    fx_restart();
    fx_reset_counts();

    lc_app_recover(&fx_app);

    TEST_ASSERT_EQUAL_UINT(1, fx_bus.profile_count);
    TEST_ASSERT_EQUAL_INT(LC_FEEDBACK_DOOR_CLOSING, fx_bus.last_profile.feedback);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

/* Fills the table of unacknowledged publishes with answers that need no journal entry. */
static void fill_the_table(void)
{
    for (unsigned i = 0; i < LC_APP_OUTSTANDING_MAX; ++i) {
        lc_command_t command = fx_open("tx-defect", "msg-defect", 1);
        command.defect = LC_ERROR_INVALID_COMMAND;
        lc_app_handle_command(&fx_app, &command);
        /* The fake keeps a few responses only. */
        fx_outbound.response_count = 0;
    }
    TEST_ASSERT_EQUAL_UINT(LC_APP_OUTSTANDING_MAX, fx_outbound.accepted[FAKE_RESPONSE]);
}

void test_a_full_table_of_unacknowledged_publishes_holds_a_response_back(void)
{
    fill_the_table();

    const lc_command_t command = fx_open("tx-1", "msg-1", 1);
    lc_app_handle_command(&fx_app, &command);

    /* The command ran and its outcome is stored; only the sending waits. */
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_TRUE(fake_journal_entry(&fx_journal, "tx-1")->completed);
    TEST_ASSERT_EQUAL_UINT(LC_APP_OUTSTANDING_MAX, fx_outbound.accepted[FAKE_RESPONSE]);

    /* Once the broker has taken some, the response follows and is delivered. */
    fx_acknowledge_all();
    fx_advance_acknowledging(1000);
    TEST_ASSERT_EQUAL_UINT(LC_APP_OUTSTANDING_MAX + 1, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_TRUE(fake_journal_entry(&fx_journal, "tx-1")->delivered);
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
}

void test_a_refusal_while_closing_is_dropped_when_the_table_is_full(void)
{
    fill_the_table();
    lc_app_begin_closing(&fx_app);

    const lc_command_t command = fx_open("tx-late", "msg-late", 1);
    lc_app_handle_command(&fx_app, &command);

    TEST_ASSERT_EQUAL_UINT(LC_APP_OUTSTANDING_MAX, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

void test_unknown_publish_events_are_ignored(void)
{
    unsigned attempts = fake_outbound_attempts(&fx_outbound);

    lc_app_on_publish_acknowledged(&fx_app, 424242);
    lc_app_on_publish_failed(&fx_app, 424242);
    lc_app_on_publish_acknowledged(&fx_app, LC_PUBLISH_REFUSED);
    lc_app_on_publish_failed(&fx_app, LC_PUBLISH_REFUSED);

    TEST_ASSERT_EQUAL_UINT(attempts, fake_outbound_attempts(&fx_outbound));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_commands_with_a_defect_are_answered_without_a_journal_entry);
    RUN_TEST(test_an_action_the_application_does_not_know_is_answered_as_unknown);
    RUN_TEST(test_a_bus_that_rejects_the_profile_fails_the_configuration);
    RUN_TEST(test_a_failed_store_restores_the_profile_of_the_configuration_in_effect);
    RUN_TEST(test_recovery_puts_the_stored_profile_on_the_bus);
    RUN_TEST(test_a_full_table_of_unacknowledged_publishes_holds_a_response_back);
    RUN_TEST(test_a_refusal_while_closing_is_dropped_when_the_table_is_full);
    RUN_TEST(test_unknown_publish_events_are_ignored);
    return UNITY_END();
}
