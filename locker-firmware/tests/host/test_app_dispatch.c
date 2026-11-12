#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "unity.h"

/* The owner task hands each event from its queue to lc_app_dispatch(), one at a time. */

static const lc_mapping_t REMAPPED[] = {
    {.number = 1, .board = 1, .address = 9},
    {.number = 2, .board = 1, .address = 1},
};

static lc_event_t event;

void setUp(void)
{
    fx_init();
    fx_configure();
}

void tearDown(void)
{
}

static void dispatch_command(const lc_command_t *command)
{
    memset(&event, 0, sizeof(event));
    event.kind = LC_EVENT_COMMAND;
    event.command = *command;
    lc_app_dispatch(&fx_app, &event);
}

static void dispatch_open(unsigned index, uint16_t compartment_number)
{
    char transaction_id[32];
    char message_id[32];
    snprintf(transaction_id, sizeof(transaction_id), "tx-%u", index);
    snprintf(message_id, sizeof(message_id), "msg-%u", index);
    const lc_command_t command = fx_open(transaction_id, message_id, compartment_number);
    dispatch_command(&command);
}

static void dispatch_event(lc_event_kind_t kind, lc_publish_id_t id)
{
    memset(&event, 0, sizeof(event));
    event.kind = kind;
    event.publish_id = id;
    lc_app_dispatch(&fx_app, &event);
}

void test_S2_H50_events_run_one_at_a_time_in_arrival_order(void)
{
    const lc_command_t apply =
        fx_apply("tx-config", "msg-config", REMAPPED, 2, LC_FEEDBACK_DOOR_CLOSING, 15);

    dispatch_open(1, 1);
    /* The open before the configuration used the old mapping. */
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT8(0, fx_bus.last_target.address);
    TEST_ASSERT_EQUAL_UINT(0, fx_config_store.save_count);

    dispatch_command(&apply);
    TEST_ASSERT_EQUAL_UINT(1, fx_config_store.save_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_TRUE(fake_trace_first("bus.unlock") < fake_trace_first("config.save"));

    dispatch_open(2, 1);
    /* The open after it uses the new one. */
    TEST_ASSERT_EQUAL_UINT(2, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT8(9, fx_bus.last_target.address);
}

void test_S2_H52_owner_task_goes_on_while_an_acknowledgement_is_outstanding(void)
{
    dispatch_open(1, 1);
    dispatch_open(2, 2);
    dispatch_event(LC_EVENT_TICK, 0);

    /* No acknowledgement arrives for anything. */
    TEST_ASSERT_EQUAL_UINT(2, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_FALSE(fake_journal_entry(&fx_journal, "tx-1")->delivered);
    TEST_ASSERT_FALSE(fake_journal_entry(&fx_journal, "tx-2")->delivered);

    /* The acknowledgements are events like any other, in any order. */
    lc_publish_id_t second = fx_outbound.last_id[FAKE_RESPONSE];
    dispatch_event(LC_EVENT_PUBLISH_ACKNOWLEDGED, second);
    TEST_ASSERT_FALSE(fake_journal_entry(&fx_journal, "tx-1")->delivered);
    TEST_ASSERT_TRUE(fake_journal_entry(&fx_journal, "tx-2")->delivered);
}

void test_S2_H53_acknowledgement_for_an_unknown_or_delivered_response_changes_nothing(void)
{
    dispatch_open(1, 1);
    lc_publish_id_t id = fx_outbound.last_id[FAKE_RESPONSE];
    dispatch_event(LC_EVENT_PUBLISH_ACKNOWLEDGED, id);
    TEST_ASSERT_EQUAL_UINT(1, fx_journal.delivered_count);
    unsigned published = fake_outbound_attempts(&fx_outbound);

    dispatch_event(LC_EVENT_PUBLISH_ACKNOWLEDGED, id);
    dispatch_event(LC_EVENT_PUBLISH_ACKNOWLEDGED, 999999);
    dispatch_event(LC_EVENT_PUBLISH_ACKNOWLEDGED, LC_PUBLISH_REFUSED);
    dispatch_event(LC_EVENT_PUBLISH_FAILED, id);
    dispatch_event(LC_EVENT_PUBLISH_FAILED, 999999);

    TEST_ASSERT_EQUAL_UINT(1, fx_journal.delivered_count);
    TEST_ASSERT_TRUE(fake_journal_entry(&fx_journal, "tx-1")->delivered);
    TEST_ASSERT_EQUAL_UINT(published, fake_outbound_attempts(&fx_outbound));
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
}

void test_connection_events_decide_whether_a_command_runs(void)
{
    dispatch_event(LC_EVENT_DISCONNECTED, 0);
    dispatch_open(1, 1);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    TEST_ASSERT_NULL(fake_journal_entry(&fx_journal, "tx-1"));

    dispatch_event(LC_EVENT_CONNECTED, 0);
    dispatch_open(2, 1);
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_NOT_NULL(fake_journal_entry(&fx_journal, "tx-2"));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S2_H50_events_run_one_at_a_time_in_arrival_order);
    RUN_TEST(test_S2_H52_owner_task_goes_on_while_an_acknowledgement_is_outstanding);
    RUN_TEST(test_S2_H53_acknowledgement_for_an_unknown_or_delivered_response_changes_nothing);
    RUN_TEST(test_connection_events_decide_whether_a_command_runs);
    return UNITY_END();
}
