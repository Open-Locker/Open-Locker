#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "unity.h"

/* Starting an update and refusing one (ADR-0075, ADR-0076). */

#define URL "https://open-locker.cloud/firmware/releases/0.2.0/download?token=secret"

void setUp(void)
{
    fx_init();
    snprintf(fx_update.running_version, sizeof(fx_update.running_version), "0.1.0");
    fx_configure();
}

void tearDown(void)
{
}

/* Sends an update_firmware command and returns the error of its one response. */
static lc_error_t update(const char *transaction_id, const char *version, bool allow_downgrade)
{
    char message_id[LC_ID_MAX + 1];
    snprintf(message_id, sizeof(message_id), "msg-for-%s", transaction_id);
    const lc_command_t command =
        fx_update_firmware(transaction_id, message_id, version, URL, allow_downgrade);
    const size_t before = fx_outbound.response_count;
    lc_app_handle_command(&fx_app, &command);
    TEST_ASSERT_EQUAL_UINT(before + 1, fx_outbound.response_count);
    const lc_command_result_t *response = fake_outbound_last_response(&fx_outbound);
    TEST_ASSERT_EQUAL_STRING(transaction_id, response->transaction_id);
    TEST_ASSERT_EQUAL_STRING("update_firmware", response->action_name);
    return response->error;
}

/* The download task reports how it ended. */
static void download_ended(lc_event_kind_t kind, lc_error_t error)
{
    const lc_event_t event = {.kind = kind, .error = error};
    lc_app_dispatch(&fx_app, &event);
}

void test_S7_H01_an_update_command_reaches_the_port_with_its_url_and_version(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-1", "0.2.0", false));

    TEST_ASSERT_EQUAL_UINT(1, fx_update.start_count);
    TEST_ASSERT_EQUAL_STRING("0.2.0", fx_update.last_request.version);
    TEST_ASSERT_EQUAL_STRING(URL, fx_update.last_request.url);
    TEST_ASSERT_FALSE(fx_update.last_request.allow_downgrade);
    TEST_ASSERT_EQUAL_UINT(0, fx_update.select_count);
}

void test_S7_H02_an_update_is_refused_while_a_command_is_in_flight_and_starts_once_idle(void)
{
    fx_bus.unlock_result = LC_UNLOCK_ACKNOWLEDGED;
    const lc_command_t open = fx_open("tx-open", "msg-open", 1);
    lc_app_handle_command(&fx_app, &open);
    fx_acknowledge_all();

    /* The door of the open is still being watched (ADR-0040). */
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_BUSY, update("tx-1", "0.2.0", false));
    TEST_ASSERT_EQUAL_UINT(0, fx_update.start_count);

    /* The watch ends with its outcome; the backend sends the update again. */
    fx_advance_acknowledging(2 * FX_HEARTBEAT_SECONDS * 1000);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-2", "0.2.0", false));
    TEST_ASSERT_EQUAL_UINT(1, fx_update.start_count);
}

void test_a_second_update_is_refused_while_one_is_running(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-1", "0.2.0", false));

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_BUSY, update("tx-2", "0.3.0", false));
    TEST_ASSERT_EQUAL_UINT(1, fx_update.start_count);
}

void test_S7_H03_an_update_is_not_ready_on_an_unhealthy_journal_a_dead_bus_or_a_trial_image(void)
{
    fx_journal.healthy = false;
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-1", "0.2.0", false));
    fx_journal.healthy = true;

    fx_bus.state = LC_BUS_DISCONNECTED;
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-2", "0.2.0", false));
    fx_bus.state = LC_BUS_UNREACHABLE;
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-3", "0.2.0", false));
    fx_bus.state = LC_BUS_CONNECTED;

    fx_update.on_trial = true;
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-4", "0.2.0", false));

    TEST_ASSERT_EQUAL_UINT(0, fx_update.start_count);
}

void test_an_update_that_cannot_start_is_not_ready_and_leaves_the_unit_free(void)
{
    fx_update.start_ok = false;
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-1", "0.2.0", false));

    fx_update.start_ok = true;
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-2", "0.2.0", false));
}

void test_a_build_without_an_update_port_refuses_updates(void)
{
    memset(&fx_app.ports.firmware_update, 0, sizeof(fx_app.ports.firmware_update));

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-1", "0.2.0", false));
}

void test_S7_H04_the_running_version_a_downgrade_and_a_rolled_back_version_are_refused(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_SAME_VERSION, update("tx-1", "0.1.0", false));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_SAME_VERSION, update("tx-2", "0.1.0", true));

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_DOWNGRADE, update("tx-3", "0.0.9", false));

    snprintf(fx_update.rolled_back_version, sizeof(fx_update.rolled_back_version), "0.3.0");
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_VERSION_REJECTED, update("tx-4", "0.3.0", false));
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_VERSION_REJECTED, update("tx-5", "0.3.0", true));

    TEST_ASSERT_EQUAL_UINT(0, fx_update.start_count);
}

void test_a_downgrade_with_the_flag_and_an_upgrade_start(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-1", "0.0.9", true));
    TEST_ASSERT_EQUAL_UINT(1, fx_update.start_count);
    TEST_ASSERT_TRUE(fx_update.last_request.allow_downgrade);
}

void test_versions_are_compared_as_semantic_versions(void)
{
    static const struct {
        const char *running;
        const char *candidate;
        lc_error_t expected;
    } CASES[] = {
        /* Numbers compare as numbers, not as text. */
        {"0.9.0", "0.10.0", LC_ERROR_NONE},
        {"1.2.3", "1.10.0", LC_ERROR_NONE},
        {"2.0.0", "10.0.0", LC_ERROR_NONE},
        /* A pre-release is lower than its release. */
        {"1.0.0", "1.0.0-rc.1", LC_ERROR_UPDATE_DOWNGRADE},
        {"1.0.0-rc.1", "1.0.0", LC_ERROR_NONE},
        /* Its identifiers compare one by one: numbers as numbers, then text, fewer is lower. */
        {"1.0.0-rc.2", "1.0.0-rc.10", LC_ERROR_NONE},
        {"1.0.0-rc.2", "1.0.0-rc.2.1", LC_ERROR_NONE},
        {"1.0.0-rc.2.1", "1.0.0-rc.2", LC_ERROR_UPDATE_DOWNGRADE},
        {"1.0.0-rc.2", "1.0.0-alpha", LC_ERROR_UPDATE_DOWNGRADE},
        {"1.0.0-1", "1.0.0-rc", LC_ERROR_NONE},
        {"1.0.0-rc", "1.0.0-1", LC_ERROR_UPDATE_DOWNGRADE},
        {"1.0.0-rc.2", "1.0.0-rc.2", LC_ERROR_UPDATE_SAME_VERSION},
    };
    for (size_t i = 0; i < sizeof(CASES) / sizeof(CASES[0]); ++i) {
        /* Each case on a fresh application, so an accepted update does not make the next busy. */
        fx_restart();
        fx_connect();
        fx_reset_counts();
        snprintf(fx_update.running_version, sizeof(fx_update.running_version), "%s",
                 CASES[i].running);
        char transaction_id[16];
        snprintf(transaction_id, sizeof(transaction_id), "tx-%u", (unsigned)i);
        TEST_ASSERT_EQUAL_INT_MESSAGE(CASES[i].expected,
                                      update(transaction_id, CASES[i].candidate, false),
                                      CASES[i].candidate);
    }
}

void test_an_update_is_not_ready_when_the_running_version_cannot_be_compared(void)
{
    snprintf(fx_update.running_version, sizeof(fx_update.running_version), "dev");

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-1", "0.2.0", false));
}

void test_S7_H06_a_failed_download_or_verification_is_one_event_and_no_second_response(void)
{
    static const lc_error_t FAILURES[] = {LC_ERROR_UPDATE_DOWNLOAD_FAILED,
                                          LC_ERROR_UPDATE_VERIFY_FAILED};
    for (size_t i = 0; i < sizeof(FAILURES) / sizeof(FAILURES[0]); ++i) {
        fx_restart();
        fx_connect();
        fx_reset_counts();
        char first[16];
        char second[16];
        snprintf(first, sizeof(first), "tx-%u-a", (unsigned)i);
        snprintf(second, sizeof(second), "tx-%u-b", (unsigned)i);
        TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update(first, "0.2.0", false));

        download_ended(LC_EVENT_FIRMWARE_FAILED, FAILURES[i]);

        TEST_ASSERT_EQUAL_UINT(1, fx_outbound.failure_count);
        TEST_ASSERT_EQUAL_STRING(first, fx_outbound.failures[0].transaction_id);
        TEST_ASSERT_EQUAL_INT(FAILURES[i], fx_outbound.failures[0].error);
        TEST_ASSERT_EQUAL_UINT(1, fx_outbound.response_count);
        TEST_ASSERT_EQUAL_UINT(0, fx_update.select_count);

        /* Reported once, and the unit is free for the next update. */
        download_ended(LC_EVENT_FIRMWARE_FAILED, FAILURES[i]);
        TEST_ASSERT_EQUAL_UINT(1, fx_outbound.failure_count);
        TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update(second, "0.2.0", false));
    }
}

void test_S7_H07_the_next_slot_is_selected_only_after_the_image_is_verified(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-1", "0.2.0", false));
    fx_advance_acknowledging(5000);
    TEST_ASSERT_EQUAL_UINT(0, fx_update.select_count);

    download_ended(LC_EVENT_FIRMWARE_VERIFIED, LC_ERROR_NONE);

    TEST_ASSERT_EQUAL_UINT(1, fx_update.select_count);
    TEST_ASSERT_TRUE(fake_trace_first("update.start") < fake_trace_first("update.select"));
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.failure_count);

    /* Nothing else starts while the unit waits for its restart. */
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_BUSY, update("tx-2", "0.3.0", false));
}

void test_a_verified_image_nobody_asked_for_is_not_selected(void)
{
    download_ended(LC_EVENT_FIRMWARE_VERIFIED, LC_ERROR_NONE);

    TEST_ASSERT_EQUAL_UINT(0, fx_update.select_count);
}

void test_an_image_that_cannot_be_selected_is_a_failed_verification(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-1", "0.2.0", false));
    fx_update.select_ok = false;

    download_ended(LC_EVENT_FIRMWARE_VERIFIED, LC_ERROR_NONE);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.failure_count);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_VERIFY_FAILED, fx_outbound.failures[0].error);
}

void test_S7_H11_a_repeated_transaction_does_not_download_twice(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-1", "0.2.0", false));
    fx_acknowledge_all();

    /* The broker delivers it again, under another message id. */
    const lc_command_t again = fx_update_firmware("tx-1", "msg-again", "0.2.0", URL, false);
    lc_app_handle_command(&fx_app, &again);

    TEST_ASSERT_EQUAL_UINT(1, fx_update.start_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_journal.claim_count);
    /* The stored answer is sent again, like for any command. */
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.response_count);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, fake_outbound_last_response(&fx_outbound)->error);
}

void test_a_restart_before_the_answer_reports_the_update_as_not_ready_and_never_starts_it(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-1", "0.2.0", false));
    /* The outcome was never stored: the claim stays and the unit restarts. */
    lc_journal_entry_t *entry = (lc_journal_entry_t *)fake_journal_entry(&fx_journal, "tx-1");
    entry->completed = false;
    entry->delivered = false;
    fx_reset_counts();

    fx_restart();
    lc_app_recover(&fx_app);

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, entry->result.error);
    TEST_ASSERT_EQUAL_STRING("update_firmware", entry->result.action_name);
    TEST_ASSERT_EQUAL_UINT(0, fx_update.start_count);
}

void test_the_error_schema_codes_only_come_out_of_every_refusal_path(void)
{
    /* A unit that is closing, and one whose journal cannot claim, still answer in UPDATE_* codes. */
    fx_journal.refuse_claim = true;
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-1", "0.2.0", false));
    fx_journal.refuse_claim = false;

    lc_app_begin_closing(&fx_app);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_NOT_READY, update("tx-2", "0.2.0", false));
    TEST_ASSERT_EQUAL_UINT(0, fx_update.start_count);
}

void test_a_journal_that_cannot_store_the_answer_aborts_the_download(void)
{
    fx_journal.fail_complete = true;
    const lc_command_t command = fx_update_firmware("tx-1", "msg-1", "0.2.0", URL, false);

    lc_app_handle_command(&fx_app, &command);

    /* Nothing is published that the journal does not hold, and nothing keeps downloading. */
    TEST_ASSERT_EQUAL_UINT(1, fx_update.start_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.abort_count);
    TEST_ASSERT_TRUE(fake_trace_first("update.start") < fake_trace_first("update.abort"));
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.response_count);

    fx_journal.fail_complete = false;
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-2", "0.2.0", false));
}

void test_a_failure_event_that_cannot_be_sent_is_dropped_and_the_unit_is_free(void)
{
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-1", "0.2.0", false));
    fx_acknowledge_all();
    lc_app_on_disconnected(&fx_app);
    fx_outbound.refuse[FAKE_FIRMWARE_FAILED] = 1;

    download_ended(LC_EVENT_FIRMWARE_FAILED, LC_ERROR_UPDATE_DOWNLOAD_FAILED);

    /* Fire and forget: no retry later, and the backend's timeout covers it (ADR-0076). */
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.failure_count);
    fx_connect();
    fx_advance_acknowledging(5000);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.failure_count);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, update("tx-2", "0.2.0", false));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S7_H01_an_update_command_reaches_the_port_with_its_url_and_version);
    RUN_TEST(test_S7_H02_an_update_is_refused_while_a_command_is_in_flight_and_starts_once_idle);
    RUN_TEST(test_a_second_update_is_refused_while_one_is_running);
    RUN_TEST(test_S7_H03_an_update_is_not_ready_on_an_unhealthy_journal_a_dead_bus_or_a_trial_image);
    RUN_TEST(test_an_update_that_cannot_start_is_not_ready_and_leaves_the_unit_free);
    RUN_TEST(test_a_build_without_an_update_port_refuses_updates);
    RUN_TEST(test_S7_H04_the_running_version_a_downgrade_and_a_rolled_back_version_are_refused);
    RUN_TEST(test_a_downgrade_with_the_flag_and_an_upgrade_start);
    RUN_TEST(test_versions_are_compared_as_semantic_versions);
    RUN_TEST(test_an_update_is_not_ready_when_the_running_version_cannot_be_compared);
    RUN_TEST(test_S7_H06_a_failed_download_or_verification_is_one_event_and_no_second_response);
    RUN_TEST(test_S7_H07_the_next_slot_is_selected_only_after_the_image_is_verified);
    RUN_TEST(test_a_verified_image_nobody_asked_for_is_not_selected);
    RUN_TEST(test_an_image_that_cannot_be_selected_is_a_failed_verification);
    RUN_TEST(test_S7_H11_a_repeated_transaction_does_not_download_twice);
    RUN_TEST(test_a_restart_before_the_answer_reports_the_update_as_not_ready_and_never_starts_it);
    RUN_TEST(test_the_error_schema_codes_only_come_out_of_every_refusal_path);
    RUN_TEST(test_a_journal_that_cannot_store_the_answer_aborts_the_download);
    RUN_TEST(test_a_failure_event_that_cannot_be_sent_is_dropped_and_the_unit_is_free);
    return UNITY_END();
}
