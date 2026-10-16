#include <string.h>

#include "lc_domain/config.h"
#include "lc_domain/door_detection.h"
#include "lc_domain/text.h"
#include "unity.h"

/* Rules of the domain beyond the test catalogue: refusals and edges. */

#define WINDOW_MS 15000

static lc_door_tracker_t tracker;

void setUp(void)
{
    lc_door_tracker_reset(&tracker);
}

void tearDown(void)
{
}

static lc_config_request_t request(void)
{
    lc_config_request_t value;
    memset(&value, 0, sizeof(value));
    value.supported_adapter = true;
    value.heartbeat_seconds = 15;
    value.count = 1;
    value.mappings[0] = (lc_mapping_t){.number = 1, .board = 1, .address = 0};
    memcpy(value.hash, "abc", 4);
    return value;
}

void test_text_copy_reports_what_does_not_fit(void)
{
    char buffer[4];

    TEST_ASSERT_TRUE(lc_text_copy(buffer, sizeof(buffer), "abc"));
    TEST_ASSERT_EQUAL_STRING("abc", buffer);
    TEST_ASSERT_FALSE(lc_text_copy(buffer, sizeof(buffer), "abcd"));
    TEST_ASSERT_EQUAL_STRING("", buffer);
    TEST_ASSERT_FALSE(lc_text_copy(buffer, 0, "a"));
}

void test_config_refuses_what_cannot_be_applied(void)
{
    lc_config_t config;
    lc_config_request_t value = request();

    TEST_ASSERT_TRUE(lc_config_from_request(&value, &config));
    TEST_ASSERT_TRUE(config.applied);

    value = request();
    value.heartbeat_seconds = 0;
    TEST_ASSERT_FALSE(lc_config_from_request(&value, &config));
    TEST_ASSERT_FALSE(config.applied);

    value.heartbeat_seconds = LC_HEARTBEAT_SECONDS_MAX + 1;
    TEST_ASSERT_FALSE(lc_config_from_request(&value, &config));
    value.heartbeat_seconds = LC_HEARTBEAT_SECONDS_MAX;
    TEST_ASSERT_TRUE(lc_config_from_request(&value, &config));

    value = request();
    value.count = LC_COMPARTMENTS_MAX + 1;
    TEST_ASSERT_FALSE(lc_config_from_request(&value, &config));

    /* The longest hash that fits is kept as it is. */
    value = request();
    memset(value.hash, 'a', sizeof(value.hash) - 1);
    value.hash[sizeof(value.hash) - 1] = '\0';
    TEST_ASSERT_TRUE(lc_config_from_request(&value, &config));
    TEST_ASSERT_EQUAL_UINT(sizeof(value.hash) - 1, strlen(config.hash));

    /* No compartments at all is a valid, empty bank. */
    value = request();
    value.count = 0;
    TEST_ASSERT_TRUE(lc_config_from_request(&value, &config));
    TEST_ASSERT_NULL(lc_config_find(&config, 1));
}

void test_canonical_form_reports_every_place_it_can_run_out_of_room(void)
{
    lc_config_t config;
    lc_config_request_t value = request();
    value.count = 2;
    value.mappings[1] = (lc_mapping_t){.number = 2, .board = 1, .address = 1};
    TEST_ASSERT_TRUE(lc_config_from_request(&value, &config));

    char canonical[LC_CONFIG_CANONICAL_MAX];
    TEST_ASSERT_TRUE(lc_config_canonical(&config, canonical, sizeof(canonical)));
    size_t length = strlen(canonical);

    /* One byte short anywhere is an error, never a truncated document. */
    for (size_t capacity = 0; capacity <= length; ++capacity) {
        TEST_ASSERT_FALSE_MESSAGE(lc_config_canonical(&config, canonical, capacity), "truncated");
    }
    TEST_ASSERT_TRUE(lc_config_canonical(&config, canonical, length + 1));
}

void test_config_find_matches_the_compartment_number(void)
{
    lc_config_t config;
    lc_config_request_t value = request();
    value.count = 2;
    value.mappings[0] = (lc_mapping_t){.number = 9, .board = 3, .address = 4};
    value.mappings[1] = (lc_mapping_t){.number = 2, .board = 3, .address = 7};
    TEST_ASSERT_TRUE(lc_config_from_request(&value, &config));

    TEST_ASSERT_EQUAL_UINT8(7, lc_config_find(&config, 2)->address);
    TEST_ASSERT_EQUAL_UINT8(4, lc_config_find(&config, 9)->address);
    TEST_ASSERT_NULL(lc_config_find(&config, 3));
}

void test_a_door_seen_open_ends_the_watch(void)
{
    lc_door_unlocked(&tracker, 4, "tx-1", 1000);
    TEST_ASSERT_TRUE(tracker.watching);

    lc_door_watch(&tracker, LC_DOOR_CLOSED, 1500, WINDOW_MS);
    TEST_ASSERT_TRUE(tracker.watching);
    TEST_ASSERT_FALSE(tracker.outcome_due);
    lc_door_watch(&tracker, LC_DOOR_OPEN, 2300, WINDOW_MS);

    TEST_ASSERT_FALSE(tracker.watching);
    TEST_ASSERT_TRUE(tracker.outcome_due);
    TEST_ASSERT_TRUE(tracker.outcome.opened);
    TEST_ASSERT_EQUAL_UINT32(1300, tracker.outcome.detection_ms);
    TEST_ASSERT_EQUAL_UINT16(4, tracker.outcome.compartment_number);
    TEST_ASSERT_EQUAL_STRING("tx-1", tracker.outcome.transaction_id);

    /* A finished watch does not start again by itself. */
    tracker.outcome_due = false;
    lc_door_watch(&tracker, LC_DOOR_OPEN, 3000, WINDOW_MS);
    TEST_ASSERT_FALSE(tracker.outcome_due);
}

void test_a_window_with_no_reading_ends_without_an_outcome(void)
{
    lc_door_unlocked(&tracker, 4, "tx-1", 1000);

    lc_door_watch(&tracker, LC_DOOR_UNKNOWN, 5000, WINDOW_MS);
    TEST_ASSERT_TRUE(tracker.watching);
    lc_door_watch(&tracker, LC_DOOR_UNKNOWN, 1000 + WINDOW_MS, WINDOW_MS);

    /* Nothing was seen, so neither an opening nor a jam can be claimed. */
    TEST_ASSERT_FALSE(tracker.watching);
    TEST_ASSERT_FALSE(tracker.outcome_due);
}

void test_a_second_unlock_replaces_a_running_watch(void)
{
    lc_door_unlocked(&tracker, 4, "tx-1", 1000);
    lc_door_unlocked(&tracker, 4, "tx-2", 2000);

    lc_door_watch(&tracker, LC_DOOR_OPEN, 2600, WINDOW_MS);

    TEST_ASSERT_EQUAL_STRING("tx-2", tracker.outcome.transaction_id);
    TEST_ASSERT_EQUAL_UINT32(600, tracker.outcome.detection_ms);
    TEST_ASSERT_EQUAL_INT64(2000, tracker.unlocked_at_ms);

    lc_door_tracker_reset(&tracker);
    TEST_ASSERT_FALSE(tracker.watching);
    TEST_ASSERT_FALSE(tracker.unlocked_since_boot);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_text_copy_reports_what_does_not_fit);
    RUN_TEST(test_config_refuses_what_cannot_be_applied);
    RUN_TEST(test_canonical_form_reports_every_place_it_can_run_out_of_room);
    RUN_TEST(test_config_find_matches_the_compartment_number);
    RUN_TEST(test_a_door_seen_open_ends_the_watch);
    RUN_TEST(test_a_window_with_no_reading_ends_without_an_outcome);
    RUN_TEST(test_a_second_unlock_replaces_a_running_watch);
    return UNITY_END();
}
