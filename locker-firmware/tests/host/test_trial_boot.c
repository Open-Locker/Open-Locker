#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "unity.h"

/* The first boot of a new image: confirm it, or go back (ADR-0075). */

/* The unit has restarted into an image that has not been marked valid. */
static void boot_on_trial(void)
{
    fx_update.on_trial = true;
    fx_restart();
    fx_reset_counts();
}

static void network_up(void)
{
    const lc_event_t event = {.kind = LC_EVENT_NETWORK_UP};
    lc_app_dispatch(&fx_app, &event);
}

static void download_ended(lc_event_kind_t kind)
{
    const lc_event_t event = {.kind = kind, .error = LC_ERROR_UPDATE_VERIFY_FAILED};
    lc_app_dispatch(&fx_app, &event);
}

/* Accepts an update to 0.3.0; the download task is then busy. */
static void accept_update(void)
{
    const lc_command_t command = fx_update_firmware("tx-update", "msg-update", "0.3.0",
                                                    "https://example.com/a.bin", false);
    lc_app_handle_command(&fx_app, &command);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, fake_outbound_last_response(&fx_outbound)->error);
    fx_acknowledge_all();
}

#define SECRET "tok-9f3a7c1e-d00d"
#define SECRET_URL "https://open-locker.cloud/firmware/releases/0.3.0/download?token=" SECRET

/* Every step of an update, one after the other, ending in a rollback. */
static void run_a_whole_update(void)
{
    /* An update that fails to download, and one that is verified. */
    lc_command_t command = fx_update_firmware("tx-a", "msg-a", "0.3.0", SECRET_URL, false);
    lc_app_handle_command(&fx_app, &command);
    download_ended(LC_EVENT_FIRMWARE_FAILED);
    command = fx_update_firmware("tx-b", "msg-b", "0.3.0", SECRET_URL, false);
    lc_app_handle_command(&fx_app, &command);
    download_ended(LC_EVENT_FIRMWARE_VERIFIED);
    fx_advance_acknowledging(1000);

    /* The unit restarts into the new image, which never reaches the broker. */
    fx_update.on_trial = true;
    fx_restart();
    fx_advance_acknowledging(LC_TRIAL_WINDOW_MS + 1000);
}

void setUp(void)
{
    fx_init();
    snprintf(fx_update.running_version, sizeof(fx_update.running_version), "0.2.0");
    fx_configure();
    fx_update.mark_valid_ok = true;
}

void tearDown(void)
{
}

void test_S7_H08_the_image_is_marked_valid_only_after_wifi_broker_and_a_bus_query(void)
{
    for (unsigned missing = 0; missing < 7; ++missing) {
        /* Every way of having done only some of the three. */
        boot_on_trial();
        const unsigned done = missing;
        if (done & 1u) {
            network_up();
        }
        if (done & 2u) {
            fx_connect();
        }
        fx_bus.read_ok = (done & 4u) != 0;
        fx_advance_acknowledging(5000);
        TEST_ASSERT_EQUAL_UINT_MESSAGE(0, fx_update.mark_valid_count, "marked valid too early");
    }

    boot_on_trial();
    network_up();
    fx_connect();
    fx_bus.read_ok = true;
    fx_advance_acknowledging(5000);

    TEST_ASSERT_EQUAL_UINT(1, fx_update.mark_valid_count);
    TEST_ASSERT_FALSE(fx_update.on_trial);
    TEST_ASSERT_EQUAL_UINT(0, fx_update.rollback_count);
    /* Once, not at every tick. */
    fx_advance_acknowledging(5000);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.mark_valid_count);
}

void test_S7_H09_an_image_not_confirmed_within_the_trial_window_is_rolled_back(void)
{
    boot_on_trial();
    fx_update.rollback_ok = true;
    network_up();
    fx_bus.read_ok = true;
    /* The broker never answers. */

    fx_advance_acknowledging(LC_TRIAL_WINDOW_MS - 1000);
    TEST_ASSERT_EQUAL_UINT(0, fx_update.rollback_count);

    fx_advance_acknowledging(1100);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.rollback_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_update.mark_valid_count);
    /* The call restarts the unit itself. */
    TEST_ASSERT_EQUAL_UINT(0, fx_system.restart_count);
}

void test_S7_H15_a_rollback_that_returns_falls_back_to_a_restart(void)
{
    /* The first update after a USB flash: otadata is empty and there may be no image to go back to. */
    boot_on_trial();
    fx_update.rollback_ok = false;

    fx_advance_acknowledging(LC_TRIAL_WINDOW_MS + 1000);

    TEST_ASSERT_EQUAL_UINT(1, fx_update.rollback_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_system.restart_count);
    TEST_ASSERT_TRUE(fake_trace_first("update.rollback") < fake_trace_first("system.restart"));
    fx_advance_acknowledging(10000);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.rollback_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_system.restart_count);
}

void test_a_confirmed_image_is_never_rolled_back(void)
{
    boot_on_trial();
    network_up();
    fx_connect();
    fx_advance_acknowledging(5000);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.mark_valid_count);

    fx_advance_acknowledging(LC_TRIAL_WINDOW_MS + 1000);

    TEST_ASSERT_EQUAL_UINT(0, fx_update.rollback_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_system.restart_count);
}

void test_an_image_that_cannot_be_marked_valid_is_rolled_back_at_the_end_of_the_window(void)
{
    boot_on_trial();
    fx_update.mark_valid_ok = false;
    fx_update.rollback_ok = true;
    network_up();
    fx_connect();

    fx_advance_acknowledging(LC_TRIAL_WINDOW_MS - 1000);
    TEST_ASSERT_TRUE(fx_update.mark_valid_count > 1);
    TEST_ASSERT_EQUAL_UINT(0, fx_update.rollback_count);

    fx_advance_acknowledging(1100);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.rollback_count);
}

void test_a_unit_that_booted_a_valid_image_has_no_trial(void)
{
    fx_restart();
    fx_reset_counts();

    fx_advance_acknowledging(LC_TRIAL_WINDOW_MS + 1000);

    TEST_ASSERT_EQUAL_UINT(0, fx_update.mark_valid_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_update.rollback_count);
}

void test_a_verified_image_restarts_the_unit_once_it_is_selected(void)
{
    accept_update();

    download_ended(LC_EVENT_FIRMWARE_VERIFIED);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.select_count);
    fx_advance_acknowledging(300);

    TEST_ASSERT_EQUAL_UINT(1, fx_system.restart_count);
    TEST_ASSERT_TRUE(fake_trace_first("update.select") < fake_trace_first("system.restart"));
    fx_advance_acknowledging(2000);
    TEST_ASSERT_EQUAL_UINT(1, fx_system.restart_count);
}

void test_the_restart_waits_for_an_unlock_that_is_still_being_watched(void)
{
    accept_update();
    /* An open arrives while the image downloads: it is served, and its door is watched. */
    fx_bus.unlock_result = LC_UNLOCK_ACKNOWLEDGED;
    const lc_command_t open = fx_open("tx-open", "msg-open", 1);
    lc_app_handle_command(&fx_app, &open);
    fx_acknowledge_all();

    download_ended(LC_EVENT_FIRMWARE_VERIFIED);
    fx_advance_acknowledging(1000);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.select_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_system.restart_count);

    fx_advance_acknowledging(2 * FX_HEARTBEAT_SECONDS * 1000);
    TEST_ASSERT_EQUAL_UINT(1, fx_system.restart_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
}

void test_an_image_that_could_not_be_selected_does_not_restart_the_unit(void)
{
    accept_update();
    fx_update.select_ok = false;

    download_ended(LC_EVENT_FIRMWARE_VERIFIED);
    fx_advance_acknowledging(1000);

    TEST_ASSERT_EQUAL_UINT(0, fx_system.restart_count);
}

void test_the_heartbeat_reports_the_trial_until_the_image_is_valid(void)
{
    fx_update.slot = LC_OTA_SLOT_1;
    snprintf(fx_update.rolled_back_version, sizeof(fx_update.rolled_back_version), "0.1.9");
    boot_on_trial();
    network_up();
    fx_bus.read_ok = true;
    fx_connect();
    fx_advance_acknowledging(100);

    TEST_ASSERT_TRUE(fx_outbound.heartbeat_has_firmware);
    TEST_ASSERT_TRUE(fx_outbound.heartbeat_firmware.trial);
    TEST_ASSERT_EQUAL_STRING("0.2.0", fx_outbound.heartbeat_firmware.version);
    TEST_ASSERT_EQUAL_INT(LC_OTA_SLOT_1, fx_outbound.heartbeat_firmware.slot);
    TEST_ASSERT_EQUAL_STRING("0.1.9", fx_outbound.heartbeat_firmware.last_rollback_version);
    TEST_ASSERT_TRUE(fx_outbound.heartbeat_firmware.update_capable);

    fx_advance_acknowledging(2 * FX_HEARTBEAT_SECONDS * 1000);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.mark_valid_count);
    TEST_ASSERT_FALSE(fx_outbound.heartbeat_firmware.trial);
}

void test_a_build_without_an_update_port_sends_no_firmware_status(void)
{
    memset(&fx_app.ports.firmware_update, 0, sizeof(fx_app.ports.firmware_update));
    fx_connect();
    fx_advance_acknowledging(2 * FX_HEARTBEAT_SECONDS * 1000);

    TEST_ASSERT_TRUE(fx_outbound.accepted[FAKE_HEARTBEAT] > 0);
    TEST_ASSERT_FALSE(fx_outbound.heartbeat_has_firmware);
}

void test_S7_H10_no_update_step_calls_the_bus_unlock(void)
{
    fx_update.rollback_ok = false;
    fx_connect();

    run_a_whole_update();

    TEST_ASSERT_EQUAL_UINT(1, fx_update.select_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_update.rollback_count);
    TEST_ASSERT_TRUE(fx_system.restart_count >= 2);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

static bool contains(const void *memory, size_t size, const char *text)
{
    const char *bytes = memory;
    const size_t length = strlen(text);
    for (size_t i = 0; i + length <= size; ++i) {
        if (memcmp(bytes + i, text, length) == 0) {
            return true;
        }
    }
    return false;
}

void test_S7_H12_no_published_message_carries_the_download_urls_query_or_token(void)
{
    fx_connect();

    run_a_whole_update();

    /* The URL did get to the port, so the scan has something to miss. */
    TEST_ASSERT_TRUE(contains(&fx_update, sizeof(fx_update), SECRET));
    TEST_ASSERT_TRUE(fx_outbound.response_count > 0);
    TEST_ASSERT_TRUE(fx_outbound.failure_count > 0);
    TEST_ASSERT_FALSE(contains(&fx_outbound, sizeof(fx_outbound), SECRET));
    TEST_ASSERT_FALSE(contains(&fx_outbound, sizeof(fx_outbound), "token="));
    TEST_ASSERT_FALSE(contains(&fx_journal, sizeof(fx_journal), SECRET));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S7_H08_the_image_is_marked_valid_only_after_wifi_broker_and_a_bus_query);
    RUN_TEST(test_S7_H09_an_image_not_confirmed_within_the_trial_window_is_rolled_back);
    RUN_TEST(test_S7_H15_a_rollback_that_returns_falls_back_to_a_restart);
    RUN_TEST(test_a_confirmed_image_is_never_rolled_back);
    RUN_TEST(test_an_image_that_cannot_be_marked_valid_is_rolled_back_at_the_end_of_the_window);
    RUN_TEST(test_a_unit_that_booted_a_valid_image_has_no_trial);
    RUN_TEST(test_the_heartbeat_reports_the_trial_until_the_image_is_valid);
    RUN_TEST(test_a_build_without_an_update_port_sends_no_firmware_status);
    RUN_TEST(test_S7_H10_no_update_step_calls_the_bus_unlock);
    RUN_TEST(test_S7_H12_no_published_message_carries_the_download_urls_query_or_token);
    RUN_TEST(test_a_verified_image_restarts_the_unit_once_it_is_selected);
    RUN_TEST(test_the_restart_waits_for_an_unlock_that_is_still_being_watched);
    RUN_TEST(test_an_image_that_could_not_be_selected_does_not_restart_the_unit);
    return UNITY_END();
}
