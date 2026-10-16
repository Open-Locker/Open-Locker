#include <string.h>

#include "fakes/app_fixture.h"
#include "unity.h"

/* Ordering and deduplication rules around a command, with every port faked. */

void setUp(void)
{
    fx_init();
    fx_configure();
}

void tearDown(void)
{
}

static void open(const char *transaction_id, const char *message_id)
{
    const lc_command_t command = fx_open(transaction_id, message_id, 1);
    lc_app_handle_command(&fx_app, &command);
}

static const lc_journal_entry_t *entry(const char *transaction_id)
{
    const lc_journal_entry_t *found = fake_journal_entry(&fx_journal, transaction_id);
    TEST_ASSERT_NOT_NULL_MESSAGE(found, transaction_id);
    return found;
}

/* A claim that was committed before a restart and never completed. */
static void leave_incomplete_claim(const char *transaction_id, const char *message_id)
{
    lc_command_journal_port_t journal = fake_journal_port(&fx_journal);
    TEST_ASSERT_TRUE(journal.claim(journal.ctx, transaction_id, message_id, "open_compartment"));
    fx_journal.claim_count = 0;
    fake_trace_reset();
}

void test_S2_H27_claim_is_committed_before_the_bus_is_called(void)
{
    open("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_UINT(1, fx_journal.claim_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_TRUE(fake_trace_first("journal.claim") >= 0);
    TEST_ASSERT_TRUE(fake_trace_first("journal.claim") < fake_trace_first("bus.unlock"));
}

void test_S2_H28_completion_is_committed_before_the_response_is_published(void)
{
    open("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_UINT(1, fx_journal.complete_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_TRUE(fake_trace_first("bus.unlock") < fake_trace_first("journal.complete"));
    TEST_ASSERT_TRUE(fake_trace_first("journal.complete") < fake_trace_first("outbound.response"));
}

void test_S2_H29_delivered_is_committed_only_on_the_acknowledgement_event(void)
{
    open("tx-1", "msg-1");

    /* The publish was accepted; the broker has not confirmed it. */
    TEST_ASSERT_TRUE(entry("tx-1")->completed);
    TEST_ASSERT_FALSE(entry("tx-1")->delivered);
    TEST_ASSERT_EQUAL_UINT(0, fx_journal.delivered_count);

    lc_app_on_publish_acknowledged(&fx_app, fx_outbound.last_id[FAKE_RESPONSE]);

    TEST_ASSERT_TRUE(entry("tx-1")->delivered);
    TEST_ASSERT_EQUAL_UINT(1, fx_journal.delivered_count);
}

void test_S2_H30_undelivered_response_stays_pending_and_is_flushed_once(void)
{
    /* Refused outright. */
    fx_outbound.refuse[FAKE_RESPONSE] = 1;
    open("tx-refused", "msg-1");
    TEST_ASSERT_TRUE(entry("tx-refused")->completed);
    TEST_ASSERT_FALSE(entry("tx-refused")->delivered);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.accepted[FAKE_RESPONSE]);

    /* Accepted, then reported as failed. */
    open("tx-failed", "msg-2");
    lc_app_on_publish_failed(&fx_app, fx_outbound.last_id[FAKE_RESPONSE]);
    TEST_ASSERT_FALSE(entry("tx-failed")->delivered);

    /* Accepted and never acknowledged before the connection dropped. */
    open("tx-silent", "msg-3");
    lc_app_on_disconnected(&fx_app);
    /* Acknowledgements are no longer coming for anything sent so far. */
    fx_outbound.acknowledged = fx_outbound.id_count;

    lc_app_on_connected(&fx_app);
    fx_acknowledge_all();

    TEST_ASSERT_TRUE(entry("tx-refused")->delivered);
    TEST_ASSERT_TRUE(entry("tx-failed")->delivered);
    TEST_ASSERT_TRUE(entry("tx-silent")->delivered);

    /* Delivered responses are not flushed again. */
    unsigned published = fx_outbound.attempts[FAKE_RESPONSE];
    lc_app_on_disconnected(&fx_app);
    lc_app_on_connected(&fx_app);
    TEST_ASSERT_EQUAL_UINT(published, fx_outbound.attempts[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_UINT(3, fx_bus.unlock_count);
}

void test_S2_H31_repeated_message_id_is_ignored(void)
{
    open("tx-1", "msg-1");
    fx_acknowledge_all();
    unsigned responses = fx_outbound.attempts[FAKE_RESPONSE];

    /* The same delivery again. */
    open("tx-1", "msg-1");
    /* A message id that was already used, under another transaction. */
    open("tx-2", "msg-1");

    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(responses, fx_outbound.attempts[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_UINT(1, fx_journal.claim_count);
    TEST_ASSERT_NULL(fake_journal_entry(&fx_journal, "tx-2"));
}

void test_S2_H32_repeated_transaction_replays_the_stored_response_without_a_bus_call(void)
{
    fx_bus.unlock_result = LC_UNLOCK_OUTCOME_UNKNOWN;
    open("tx-1", "msg-1");
    fx_acknowledge_all();
    fx_bus.unlock_result = LC_UNLOCK_ACKNOWLEDGED;

    open("tx-1", "msg-2");

    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_STRING("tx-1", fx_outbound.responses[1].transaction_id);
    /* The stored outcome, not a fresh one. */
    TEST_ASSERT_EQUAL_INT(LC_ERROR_HARDWARE_ERROR, fx_outbound.responses[1].error);
    TEST_ASSERT_EQUAL_UINT(1, fx_journal.complete_count);
    /* The new message id is remembered, so its own redelivery is ignored. */
    open("tx-1", "msg-2");
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_RESPONSE]);
}

void test_S2_H33_repeated_transaction_while_the_first_is_incomplete_makes_no_bus_call(void)
{
    leave_incomplete_claim("tx-1", "msg-1");

    open("tx-1", "msg-2");

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_RESPONSE]);
    TEST_ASSERT_FALSE(entry("tx-1")->completed);
}

void test_S2_H34_unhealthy_journal_yields_an_error_and_no_bus_call(void)
{
    fx_journal.healthy = false;

    open("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_STRING("tx-1", fx_outbound.responses[0].transaction_id);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UNKNOWN_ERROR, fx_outbound.responses[0].error);
}

void test_S2_H35_refused_claim_yields_an_error_and_no_bus_call(void)
{
    fx_journal.refuse_claim = true;

    open("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UNKNOWN_ERROR, fx_outbound.responses[0].error);
    TEST_ASSERT_NULL(fake_journal_entry(&fx_journal, "tx-1"));
}

void test_S2_H36_recovery_turns_an_incomplete_claim_into_an_unknown_outcome(void)
{
    leave_incomplete_claim("tx-1", "msg-1");
    fx_restart();

    lc_app_recover(&fx_app);

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    TEST_ASSERT_TRUE(entry("tx-1")->completed);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UNKNOWN_ERROR, entry("tx-1")->result.error);
    TEST_ASSERT_EQUAL_STRING("open_compartment", entry("tx-1")->result.action_name);
    /* Nothing is published before the device is connected. */
    TEST_ASSERT_EQUAL_UINT(0, fx_outbound.attempts[FAKE_RESPONSE]);

    lc_app_on_connected(&fx_app);

    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_STRING("tx-1", fx_outbound.responses[0].transaction_id);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);

    /* A redelivery of that command is answered from the journal. */
    open("tx-1", "msg-2");
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

void test_S2_H37_running_recovery_twice_produces_one_completion(void)
{
    leave_incomplete_claim("tx-1", "msg-1");
    fx_restart();

    lc_app_recover(&fx_app);
    lc_app_recover(&fx_app);
    fx_restart();
    lc_app_recover(&fx_app);

    TEST_ASSERT_EQUAL_UINT(1, fx_journal.complete_count);
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
}

void test_S2_H38_refused_response_after_the_bus_call_still_leaves_a_final_outcome(void)
{
    /* As when there is no memory left to encode the response. */
    fx_outbound.refuse[FAKE_RESPONSE] = 100;

    open("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_TRUE(entry("tx-1")->completed);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, entry("tx-1")->result.error);
    TEST_ASSERT_FALSE(entry("tx-1")->delivered);

    /* A journal that cannot commit the outcome keeps the claim: never a retry. */
    fx_journal.fail_complete = true;
    open("tx-2", "msg-2");
    TEST_ASSERT_EQUAL_UINT(2, fx_bus.unlock_count);
    TEST_ASSERT_FALSE(entry("tx-2")->completed);
    open("tx-2", "msg-3");
    TEST_ASSERT_EQUAL_UINT(2, fx_bus.unlock_count);
}

void test_S2_H39_while_closing_a_new_command_gets_shutting_down(void)
{
    lc_app_begin_closing(&fx_app);

    open("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_SHUTTING_DOWN, fx_outbound.responses[0].error);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S2_H27_claim_is_committed_before_the_bus_is_called);
    RUN_TEST(test_S2_H28_completion_is_committed_before_the_response_is_published);
    RUN_TEST(test_S2_H29_delivered_is_committed_only_on_the_acknowledgement_event);
    RUN_TEST(test_S2_H30_undelivered_response_stays_pending_and_is_flushed_once);
    RUN_TEST(test_S2_H31_repeated_message_id_is_ignored);
    RUN_TEST(test_S2_H32_repeated_transaction_replays_the_stored_response_without_a_bus_call);
    RUN_TEST(test_S2_H33_repeated_transaction_while_the_first_is_incomplete_makes_no_bus_call);
    RUN_TEST(test_S2_H34_unhealthy_journal_yields_an_error_and_no_bus_call);
    RUN_TEST(test_S2_H35_refused_claim_yields_an_error_and_no_bus_call);
    RUN_TEST(test_S2_H36_recovery_turns_an_incomplete_claim_into_an_unknown_outcome);
    RUN_TEST(test_S2_H37_running_recovery_twice_produces_one_completion);
    RUN_TEST(test_S2_H38_refused_response_after_the_bus_call_still_leaves_a_final_outcome);
    RUN_TEST(test_S2_H39_while_closing_a_new_command_gets_shutting_down);
    return UNITY_END();
}
