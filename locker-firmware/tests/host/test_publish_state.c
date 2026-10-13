#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "fakes/emitted.h"
#include "lc_application/inbox.h"
#include "lc_mqtt_codec/codec.h"
#include "unity.h"

/* Heartbeat, door polling and the compartment snapshot. */

#define HEARTBEAT_MS (FX_HEARTBEAT_SECONDS * 1000)

static const uint8_t RANDOM[16] = {1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16};

static lc_inbox_t inbox;
static bool open_posted;
static char encoded[2048];

void setUp(void)
{
    fx_init();
}

void tearDown(void)
{
}

/* Configured, connected, and past the first snapshot. */
static void settle(void)
{
    fx_configure();
    fx_advance_acknowledging(2000);
    fx_reset_counts();
}

static int door(uint16_t compartment_number)
{
    return fake_outbound_snapshot_state(&fx_outbound, compartment_number);
}

void test_S4_H11_no_polling_and_no_snapshot_before_configuration(void)
{
    fx_connect();

    fx_advance_acknowledging(2 * HEARTBEAT_MS);

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.read_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_OPEN_DETECTION]);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_UNCOMMANDED_OPEN]);
}

void test_S4_H12_snapshot_is_published_when_a_state_changes_and_not_otherwise(void)
{
    fx_configure();
    fx_advance_acknowledging(2000);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.snapshot_count);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, door(1));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, door(2));
    /* Polled about twice a second. */
    TEST_ASSERT_TRUE(fx_bus.read_count >= 3);
    TEST_ASSERT_TRUE(fx_bus.read_count <= 5);

    fx_advance_acknowledging(2 * HEARTBEAT_MS);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_SNAPSHOT]);

    fx_bus.doors[1] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, door(1));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, door(2));

    fx_bus.doors[1] = LC_DOOR_CLOSED;
    fx_advance_acknowledging(1000);
    TEST_ASSERT_EQUAL_UINT(3, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, door(2));
}

void test_S4_H13_snapshot_is_forced_after_reconnect_and_apply_config_without_events(void)
{
    static const lc_mapping_t SAME[] = {
        {.number = 1, .board = 1, .address = 0},
        {.number = 2, .board = 1, .address = 1},
    };
    settle();
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance_acknowledging(1000);
    fx_reset_counts();

    lc_app_on_disconnected(&fx_app);
    lc_app_on_connected(&fx_app);
    fx_advance_acknowledging(1000);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, door(1));

    const lc_command_t apply = fx_apply("tx-again", "msg-again", SAME, 2,
                                        LC_FEEDBACK_DOOR_CLOSING, FX_HEARTBEAT_SECONDS);
    lc_app_handle_command(&fx_app, &apply);
    fx_advance_acknowledging(1000);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, door(1));

    /* The door that was already open is not news. */
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_UNCOMMANDED_OPEN]);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_OPEN_DETECTION]);
}

void test_S4_H14_snapshot_is_retained_and_a_failed_publish_is_retried(void)
{
    TEST_ASSERT_TRUE(lc_mqtt_is_retained(LC_TOPIC_SNAPSHOT));
    TEST_ASSERT_FALSE(lc_mqtt_is_retained(LC_TOPIC_HEARTBEAT));
    TEST_ASSERT_FALSE(lc_mqtt_is_retained(LC_TOPIC_EVENT));
    TEST_ASSERT_FALSE(lc_mqtt_is_retained(LC_TOPIC_RESPONSE));
    TEST_ASSERT_FALSE(lc_mqtt_is_retained(LC_TOPIC_CONNECTION));

    settle();

    /* Refused. */
    fx_outbound.refuse[FAKE_SNAPSHOT] = 2;
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance(3000);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, door(1));

    /* Accepted, then reported as failed. */
    lc_app_on_publish_failed(&fx_app, fx_outbound.last_id[FAKE_SNAPSHOT]);
    fx_advance(3000);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_SNAPSHOT]);

    fx_acknowledge_all();
    fx_advance_acknowledging(2 * HEARTBEAT_MS);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_SNAPSHOT]);
}

void test_S4_H15_third_failed_read_reports_unknown(void)
{
    settle();

    fx_bus.read_ok = false;
    /* Two failed polls: the last known state stands. */
    fx_advance_acknowledging(2 * LC_POLL_INTERVAL_MS);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.accepted[FAKE_SNAPSHOT]);

    fx_advance_acknowledging(2 * LC_POLL_INTERVAL_MS);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_UNKNOWN, door(1));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_UNKNOWN, door(2));

    fx_bus.read_ok = true;
    fx_advance_acknowledging(1000);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, door(1));
    /* The panel coming back with the doors as they were is not an event. */
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_UNCOMMANDED_OPEN]);
}

void test_S4_H16_compartments_the_status_response_does_not_cover_are_unknown(void)
{
    static const lc_mapping_t BEYOND[] = {
        {.number = 1, .board = 1, .address = 0},
        /* The fake panel reports 24 doors; address 40 is not among them. */
        {.number = 2, .board = 1, .address = 40},
    };
    fx_connect();
    const lc_command_t apply =
        fx_apply("tx-config", "msg-config", BEYOND, 2, LC_FEEDBACK_DOOR_CLOSING, 15);
    lc_app_handle_command(&fx_app, &apply);

    fx_advance_acknowledging(5000);

    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, door(1));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_UNKNOWN, door(2));
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_UNCOMMANDED_OPEN]);
}

void test_S4_H17_heartbeat_follows_the_configured_interval(void)
{
    /* Before any configuration: the default interval. */
    fx_connect();
    fx_advance_acknowledging(3 * LC_DEFAULT_HEARTBEAT_SECONDS * 1000 + 1000);
    TEST_ASSERT_TRUE(fx_outbound.accepted[FAKE_HEARTBEAT] >= 3);
    TEST_ASSERT_TRUE(fx_outbound.accepted[FAKE_HEARTBEAT] <= 4);

    settle();
    fx_advance_acknowledging(4 * HEARTBEAT_MS);
    TEST_ASSERT_TRUE(fx_outbound.accepted[FAKE_HEARTBEAT] >= 3);
    TEST_ASSERT_TRUE(fx_outbound.accepted[FAKE_HEARTBEAT] <= 5);
    TEST_ASSERT_TRUE(fx_outbound.heartbeat_bus_connected);
    /* The clock started at one second; uptime counts from initialisation. */
    TEST_ASSERT_TRUE(fx_outbound.heartbeat_uptime_seconds >= 90);
    TEST_ASSERT_TRUE(fx_outbound.heartbeat_uptime_seconds <= 110);
}

void test_S4_H18_changed_interval_takes_effect_on_the_next_heartbeat(void)
{
    static const lc_mapping_t SAME[] = {
        {.number = 1, .board = 1, .address = 0},
        {.number = 2, .board = 1, .address = 1},
    };
    settle();

    const lc_command_t apply =
        fx_apply("tx-faster", "msg-faster", SAME, 2, LC_FEEDBACK_DOOR_CLOSING, 2);
    lc_app_handle_command(&fx_app, &apply);
    fx_acknowledge_all();
    fx_reset_counts();

    fx_advance_acknowledging(20000);

    /* Every two seconds now, not every fifteen. */
    TEST_ASSERT_TRUE(fx_outbound.accepted[FAKE_HEARTBEAT] >= 9);
    TEST_ASSERT_TRUE(fx_outbound.accepted[FAKE_HEARTBEAT] <= 11);
}

void test_S4_H19_heartbeat_reports_the_bus_as_disconnected_while_it_is_unreachable(void)
{
    settle();
    fx_bus.state = LC_BUS_UNREACHABLE;
    fx_bus.read_ok = false;

    fx_advance_acknowledging(2 * HEARTBEAT_MS);

    TEST_ASSERT_TRUE(fx_outbound.accepted[FAKE_HEARTBEAT] >= 1);
    TEST_ASSERT_FALSE(fx_outbound.heartbeat_bus_connected);

    fx_bus.state = LC_BUS_DISCONNECTED;
    fx_advance_acknowledging(2 * HEARTBEAT_MS);
    TEST_ASSERT_FALSE(fx_outbound.heartbeat_bus_connected);

    fx_bus.state = LC_BUS_CONNECTED;
    fx_bus.read_ok = true;
    fx_advance_acknowledging(2 * HEARTBEAT_MS);
    TEST_ASSERT_TRUE(fx_outbound.heartbeat_bus_connected);
}

/* A command arrives while the panel is being read. */
static void post_open_during_read(void *ctx)
{
    (void)ctx;
    if (open_posted) {
        return;
    }
    open_posted = true;
    lc_event_t event;
    memset(&event, 0, sizeof(event));
    event.kind = LC_EVENT_COMMAND;
    event.command = fx_open("tx-during-read", "msg-during-read", 1);
    TEST_ASSERT_TRUE(lc_inbox_post(&inbox, &event));
}

void test_S4_H20_poll_and_unlock_never_overlap_on_the_bus(void)
{
    settle();
    lc_inbox_init(&inbox);
    open_posted = false;
    fx_bus.during_read = post_open_during_read;

    fx_clock.now_ms += 2 * LC_POLL_INTERVAL_MS;
    lc_event_t tick;
    memset(&tick, 0, sizeof(tick));
    tick.kind = LC_EVENT_TICK;
    TEST_ASSERT_TRUE(lc_inbox_post(&inbox, &tick));
    while (lc_inbox_step(&inbox, &fx_app)) {
    }

    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_TRUE(fake_trace_first("bus.read") >= 0);
    TEST_ASSERT_TRUE(fake_trace_first("bus.read.end") < fake_trace_first("bus.unlock"));
}

void test_S4_H21_state_and_event_messages_match_their_schemas(void)
{
    lc_mqtt_envelope_t envelope;
    lc_mqtt_envelope(RANDOM, 1776195060, &envelope);

    TEST_ASSERT_TRUE(lc_mqtt_encode_heartbeat(86400, false, &envelope, encoded, sizeof(encoded)) >
                     0);
    cJSON *message = emit_message("payloads/state-heartbeat.json", encoded);
    TEST_ASSERT_EQUAL_INT(86400, emitted_integer(message, "uptime_seconds"));
    TEST_ASSERT_TRUE(cJSON_IsFalse(cJSON_GetObjectItemCaseSensitive(message, "modbus_connected")));
    cJSON_Delete(message);

    static const lc_compartment_state_t STATES[] = {
        {.compartment_number = 1, .state = LC_DOOR_CLOSED},
        {.compartment_number = 2, .state = LC_DOOR_OPEN},
        {.compartment_number = 7, .state = LC_DOOR_UNKNOWN},
    };
    TEST_ASSERT_TRUE(lc_mqtt_encode_snapshot(STATES, 3, &envelope, encoded, sizeof(encoded)) > 0);
    message = emit_message("payloads/state-snapshot.json", encoded);
    const cJSON *compartments = cJSON_GetObjectItemCaseSensitive(message, "compartments");
    TEST_ASSERT_EQUAL_INT(3, cJSON_GetArraySize(compartments));
    static const char *const EXPECTED[] = {"closed", "open", "unknown"};
    for (int i = 0; i < 3; ++i) {
        const cJSON *item = cJSON_GetArrayItem(compartments, i);
        TEST_ASSERT_EQUAL_INT(STATES[i].compartment_number,
                                 emitted_integer(item, "compartment_number"));
        TEST_ASSERT_EQUAL_STRING(EXPECTED[i], emitted_string(item, "door_state"));
    }
    cJSON_Delete(message);
    /* An empty bank and a buffer that is too small. */
    TEST_ASSERT_TRUE(lc_mqtt_encode_snapshot(STATES, 0, &envelope, encoded, sizeof(encoded)) > 0);
    cJSON_Delete(emit_message("payloads/state-snapshot.json", encoded));
    TEST_ASSERT_EQUAL_UINT(0, lc_mqtt_encode_snapshot(STATES, 3, &envelope, encoded, 40));

    lc_open_detection_t detection = {.compartment_number = 4, .opened = true, .detection_ms = 502};
    snprintf(detection.transaction_id, sizeof(detection.transaction_id), "txn-open-001");
    TEST_ASSERT_TRUE(
        lc_mqtt_encode_open_detection(&detection, &envelope, encoded, sizeof(encoded)) > 0);
    message = emit_message("payloads/event-compartment-open-detected.json", encoded);
    TEST_ASSERT_EQUAL_STRING("compartment_open_detected", emitted_string(message, "event"));
    const cJSON *data = cJSON_GetObjectItemCaseSensitive(message, "data");
    TEST_ASSERT_EQUAL_STRING("opened", emitted_string(data, "outcome"));
    TEST_ASSERT_EQUAL_STRING("txn-open-001", emitted_string(data, "transaction_id"));
    TEST_ASSERT_EQUAL_INT(502, emitted_integer(data, "detection_ms"));
    TEST_ASSERT_EQUAL_INT(4, emitted_integer(data, "compartment_number"));
    cJSON_Delete(message);

    detection.opened = false;
    TEST_ASSERT_TRUE(
        lc_mqtt_encode_open_detection(&detection, &envelope, encoded, sizeof(encoded)) > 0);
    message = emit_message("payloads/event-compartment-open-failed.json", encoded);
    TEST_ASSERT_EQUAL_STRING("compartment_open_failed", emitted_string(message, "event"));
    data = cJSON_GetObjectItemCaseSensitive(message, "data");
    TEST_ASSERT_EQUAL_STRING("door_jammed", emitted_string(data, "outcome"));
    TEST_ASSERT_EQUAL_STRING("DOOR_JAMMED", emitted_string(data, "error_code"));
    TEST_ASSERT_NULL(cJSON_GetObjectItemCaseSensitive(data, "detection_ms"));
    cJSON_Delete(message);

    lc_uncommanded_open_t uncommanded = {
        .compartment_number = 5, .has_last_unlock = true, .ms_since_last_unlock = 3600000};
    TEST_ASSERT_TRUE(
        lc_mqtt_encode_uncommanded_open(&uncommanded, &envelope, encoded, sizeof(encoded)) > 0);
    message = emit_message("payloads/event-compartment-uncommanded-open.json", encoded);
    TEST_ASSERT_EQUAL_STRING("compartment_uncommanded_open", emitted_string(message, "event"));
    data = cJSON_GetObjectItemCaseSensitive(message, "data");
    TEST_ASSERT_EQUAL_INT(3600000, emitted_integer(data, "milliseconds_since_last_relay_fire"));
    cJSON_Delete(message);

    uncommanded.has_last_unlock = false;
    TEST_ASSERT_TRUE(
        lc_mqtt_encode_uncommanded_open(&uncommanded, &envelope, encoded, sizeof(encoded)) > 0);
    message = emit_message("payloads/event-compartment-uncommanded-open.json", encoded);
    data = cJSON_GetObjectItemCaseSensitive(message, "data");
    TEST_ASSERT_NULL(cJSON_GetObjectItemCaseSensitive(data, "milliseconds_since_last_relay_fire"));
    cJSON_Delete(message);
}

void test_S4_H22_delayed_acknowledgement_does_not_turn_an_opening_into_a_jam(void)
{
    settle();
    const lc_command_t command = fx_open("tx-1", "msg-1", 1);
    lc_app_handle_command(&fx_app, &command);

    /* No acknowledgement for the response arrives during the whole window. */
    fx_advance(1000);
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance(1000);
    fx_bus.doors[0] = LC_DOOR_CLOSED;
    fx_advance(2 * HEARTBEAT_MS);

    TEST_ASSERT_FALSE(fake_journal_entry(&fx_journal, "tx-1")->delivered);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.detection_count);
    TEST_ASSERT_TRUE(fx_outbound.detections[0].opened);
    TEST_ASSERT_EQUAL_STRING("tx-1", fx_outbound.detections[0].transaction_id);
}

void test_S4_H23_uncommanded_open_is_detected_with_an_acknowledgement_outstanding(void)
{
    settle();
    const lc_command_t command = fx_open("tx-1", "msg-1", 1);
    lc_app_handle_command(&fx_app, &command);

    fx_bus.doors[1] = LC_DOOR_OPEN;
    fx_advance(1000);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.uncommanded_count);
    TEST_ASSERT_EQUAL_UINT16(2, fx_outbound.uncommanded[0].compartment_number);
}

void test_an_event_in_flight_at_a_disconnect_is_not_published_again_at_once(void)
{
    settle();
    fx_bus.doors[1] = LC_DOOR_OPEN;
    fx_advance(1000);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.uncommanded_count);

    /* The MQTT library resends what it holds; a second copy would be a second alarm. */
    lc_app_on_disconnected(&fx_app);
    lc_app_on_connected(&fx_app);
    fx_advance(5000);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.uncommanded_count);

    /* If nothing acknowledges it in time, it is sent again. */
    fx_advance(LC_APP_PUBLISH_TIMEOUT_MS);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.uncommanded_count);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S4_H11_no_polling_and_no_snapshot_before_configuration);
    RUN_TEST(test_S4_H12_snapshot_is_published_when_a_state_changes_and_not_otherwise);
    RUN_TEST(test_S4_H13_snapshot_is_forced_after_reconnect_and_apply_config_without_events);
    RUN_TEST(test_S4_H14_snapshot_is_retained_and_a_failed_publish_is_retried);
    RUN_TEST(test_S4_H15_third_failed_read_reports_unknown);
    RUN_TEST(test_S4_H16_compartments_the_status_response_does_not_cover_are_unknown);
    RUN_TEST(test_S4_H17_heartbeat_follows_the_configured_interval);
    RUN_TEST(test_S4_H18_changed_interval_takes_effect_on_the_next_heartbeat);
    RUN_TEST(test_S4_H19_heartbeat_reports_the_bus_as_disconnected_while_it_is_unreachable);
    RUN_TEST(test_S4_H20_poll_and_unlock_never_overlap_on_the_bus);
    RUN_TEST(test_S4_H21_state_and_event_messages_match_their_schemas);
    RUN_TEST(test_S4_H22_delayed_acknowledgement_does_not_turn_an_opening_into_a_jam);
    RUN_TEST(test_S4_H23_uncommanded_open_is_detected_with_an_acknowledgement_outstanding);
    RUN_TEST(test_an_event_in_flight_at_a_disconnect_is_not_published_again_at_once);
    return UNITY_END();
}
