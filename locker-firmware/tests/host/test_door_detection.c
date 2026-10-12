#include <string.h>

#include "fakes/app_fixture.h"
#include "unity.h"

/*
 * Watching a door after an unlock, and noticing a door that opens without
 * one. Compartment 1 is at address 0, compartment 2 at address 1. The
 * detection window is the heartbeat interval.
 */

#define WINDOW_MS (FX_HEARTBEAT_SECONDS * 1000)

void setUp(void)
{
    fx_init();
    fx_configure();
    /* Let the first readings in: both doors are known to be closed. */
    fx_advance_acknowledging(2000);
    fx_reset_counts();
}

void tearDown(void)
{
}

static void open(const char *transaction_id, uint16_t compartment_number)
{
    char message_id[LC_ID_MAX + 1];
    strcpy(message_id, "msg-for-");
    strcat(message_id, transaction_id);
    const lc_command_t command = fx_open(transaction_id, message_id, compartment_number);
    lc_app_handle_command(&fx_app, &command);
}

void test_S4_H01_door_seen_open_inside_the_window_is_reported_as_opened(void)
{
    open("tx-1", 1);
    fx_advance_acknowledging(1000);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.detection_count);

    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
    const lc_open_detection_t *event = &fx_outbound.detections[0];
    TEST_ASSERT_TRUE(event->opened);
    TEST_ASSERT_EQUAL_UINT16(1, event->compartment_number);
    TEST_ASSERT_EQUAL_STRING("tx-1", event->transaction_id);
    TEST_ASSERT_TRUE(event->detection_ms >= 1000);
    TEST_ASSERT_TRUE(event->detection_ms <= 1000 + 2 * LC_POLL_INTERVAL_MS);

    /* One event per unlock, however long the door stays open. */
    fx_advance_acknowledging(2 * WINDOW_MS);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.uncommanded_count);
}

void test_S4_H02_door_never_seen_open_is_reported_as_jammed_once(void)
{
    open("tx-1", 1);
    fx_advance_acknowledging(WINDOW_MS - 1000);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.detection_count);

    fx_advance_acknowledging(2000);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
    TEST_ASSERT_FALSE(fx_outbound.detections[0].opened);
    TEST_ASSERT_EQUAL_UINT16(1, fx_outbound.detections[0].compartment_number);
    TEST_ASSERT_EQUAL_STRING("tx-1", fx_outbound.detections[0].transaction_id);

    fx_advance_acknowledging(3 * WINDOW_MS);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
}

void test_S4_H03_unknown_readings_count_as_neither_open_nor_jammed(void)
{
    open("tx-1", 1);
    fx_bus.read_ok = false;
    fx_advance_acknowledging(WINDOW_MS / 2);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.detection_count);

    fx_bus.read_ok = true;
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
    TEST_ASSERT_TRUE(fx_outbound.detections[0].opened);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.uncommanded_count);
}

void test_S4_H04_mapping_change_during_detection_cancels_it_without_an_event(void)
{
    static const lc_mapping_t MOVED[] = {
        {.number = 1, .board = 1, .address = 5},
        {.number = 2, .board = 1, .address = 1},
    };
    open("tx-1", 1);
    fx_advance_acknowledging(1000);

    const lc_command_t apply =
        fx_apply("tx-config", "msg-config", MOVED, 2, LC_FEEDBACK_DOOR_CLOSING,
                 FX_HEARTBEAT_SECONDS);
    lc_app_handle_command(&fx_app, &apply);
    fx_bus.doors[5] = LC_DOOR_OPEN;
    fx_advance_acknowledging(3 * WINDOW_MS);

    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.detection_count);
}

void test_S4_H05_closed_to_open_without_an_unlock_is_an_uncommanded_open(void)
{
    fx_bus.doors[1] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.uncommanded_count);
    TEST_ASSERT_EQUAL_UINT16(2, fx_outbound.uncommanded[0].compartment_number);
    TEST_ASSERT_FALSE(fx_outbound.uncommanded[0].has_last_unlock);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.detection_count);

    /* Reported once per opening. */
    fx_advance_acknowledging(5000);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.uncommanded_count);

    /* An opening long after an unlock carries the age of that unlock. */
    open("tx-1", 1);
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);
    fx_bus.doors[0] = LC_DOOR_CLOSED;
    fx_advance_acknowledging(4 * WINDOW_MS);
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);

    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.uncommanded_count);
    TEST_ASSERT_EQUAL_UINT16(1, fx_outbound.uncommanded[1].compartment_number);
    TEST_ASSERT_TRUE(fx_outbound.uncommanded[1].has_last_unlock);
    TEST_ASSERT_TRUE(fx_outbound.uncommanded[1].ms_since_last_unlock >= 4 * WINDOW_MS);
    TEST_ASSERT_TRUE(fx_outbound.uncommanded[1].ms_since_last_unlock <= 4 * WINDOW_MS + 4000);
}

void test_S4_H06_opening_during_a_commanded_open_is_not_uncommanded(void)
{
    open("tx-1", 1);
    fx_advance_acknowledging(1000);
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);
    /* Closed and opened again while the unlock still explains it. */
    fx_bus.doors[0] = LC_DOOR_CLOSED;
    fx_advance_acknowledging(1000);
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
    TEST_ASSERT_TRUE(fx_outbound.detections[0].opened);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.uncommanded_count);
}

void test_S4_H07_first_reading_after_boot_being_open_is_no_event(void)
{
    fx_init();
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_bus.doors[1] = LC_DOOR_OPEN;
    fx_configure();

    fx_advance_acknowledging(2 * WINDOW_MS);

    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.uncommanded_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.detection_count);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, fake_outbound_snapshot_state(&fx_outbound, 1));
}

void test_S4_H08_unknown_to_open_is_not_an_uncommanded_open(void)
{
    /* The panel is silent from boot, so no door was ever known to be closed. */
    fx_init();
    fx_bus.read_ok = false;
    fx_configure();
    fx_advance_acknowledging(5000);

    fx_bus.read_ok = true;
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(5000);

    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.uncommanded_count);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, fake_outbound_snapshot_state(&fx_outbound, 1));
}

void test_S4_H09_unlock_with_unknown_outcome_still_runs_detection(void)
{
    fx_bus.unlock_result = LC_UNLOCK_OUTCOME_UNKNOWN;
    open("tx-1", 1);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_HARDWARE_ERROR,
                          fake_outbound_last_response(&fx_outbound)->error);

    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
    TEST_ASSERT_TRUE(fx_outbound.detections[0].opened);
    TEST_ASSERT_EQUAL_STRING("tx-1", fx_outbound.detections[0].transaction_id);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.uncommanded_count);
}

void test_S4_H10_detection_event_whose_publish_fails_is_retried_and_published_once(void)
{
    /* Refused twice, then accepted and reported as failed, then delivered. */
    fx_outbound.refuse[FAKE_OPEN_DETECTION] = 2;
    open("tx-1", 1);
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance(3000);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_OPEN_DETECTION]);
    TEST_ASSERT_TRUE(fx_outbound.attempts[FAKE_OPEN_DETECTION] >= 3);

    lc_app_on_publish_failed(&fx_app, fx_outbound.last_id[FAKE_OPEN_DETECTION]);
    fx_advance(3000);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_OPEN_DETECTION]);

    fx_acknowledge_all();
    fx_advance_acknowledging(2 * WINDOW_MS);

    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_OPEN_DETECTION]);
    /* Every copy carries the outcome that was first observed. */
    TEST_ASSERT_TRUE(fx_outbound.detections[0].opened);
    TEST_ASSERT_TRUE(fx_outbound.detections[1].opened);
    TEST_ASSERT_EQUAL_UINT32(fx_outbound.detections[0].detection_ms,
                             fx_outbound.detections[1].detection_ms);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S4_H01_door_seen_open_inside_the_window_is_reported_as_opened);
    RUN_TEST(test_S4_H02_door_never_seen_open_is_reported_as_jammed_once);
    RUN_TEST(test_S4_H03_unknown_readings_count_as_neither_open_nor_jammed);
    RUN_TEST(test_S4_H04_mapping_change_during_detection_cancels_it_without_an_event);
    RUN_TEST(test_S4_H05_closed_to_open_without_an_unlock_is_an_uncommanded_open);
    RUN_TEST(test_S4_H06_opening_during_a_commanded_open_is_not_uncommanded);
    RUN_TEST(test_S4_H07_first_reading_after_boot_being_open_is_no_event);
    RUN_TEST(test_S4_H08_unknown_to_open_is_not_an_uncommanded_open);
    RUN_TEST(test_S4_H09_unlock_with_unknown_outcome_still_runs_detection);
    RUN_TEST(test_S4_H10_detection_event_whose_publish_fails_is_retried_and_published_once);
    return UNITY_END();
}
