#include <stdio.h>
#include <string.h>

#include "fakes/app_fixture.h"
#include "fakes/rtos/fake_rtos.h"
#include "owner_queue.h"
#include "unity.h"

/*
 * main/owner_queue.c on the host, over a fake FreeRTOS. The command handler is
 * the real application, so "never reaches the bus" and "never claimed" are the
 * real outcomes.
 */

#define TICK_MS 100

static char order[256];
static unsigned setups_seen;
static unsigned replies_seen;
static lc_commission_channel_t last_channel;

static void note(const char *text)
{
    strncat(order, text, sizeof(order) - strlen(order) - 1);
}

static void on_control(const owner_control_t *event)
{
    char text[16];
    snprintf(text, sizeof(text), "c%u,", (unsigned)event->value);
    note(text);
    if (event->kind == OWNER_APP) {
        lc_event_t app_event;
        memset(&app_event, 0, sizeof(app_event));
        app_event.kind = event->app_kind;
        app_event.publish_id = event->value;
        lc_app_dispatch(&fx_app, &app_event);
    }
}

static void on_command(const lc_command_t *command)
{
    note(command->transaction_id);
    note(",");
    lc_app_handle_command(&fx_app, command);
}

static void on_setup(const owner_setup_t *setup)
{
    ++setups_seen;
    last_channel = setup->channel;
    note("setup,");
}

static void on_reply(const lc_provisioning_reply_t *reply)
{
    (void)reply;
    ++replies_seen;
    note("reply,");
}

static const owner_handlers_t handlers = {on_control, on_command, on_setup, on_reply};

void setUp(void)
{
    fake_rtos_reset();
    TEST_ASSERT_TRUE(owner_queue_init());
    fx_init();
    fx_configure();
    order[0] = '\0';
    setups_seen = replies_seen = 0;
}

void tearDown(void)
{
}

static bool post_open(unsigned index, const char *message_prefix)
{
    char transaction_id[32];
    char message_id[32];
    snprintf(transaction_id, sizeof(transaction_id), "tx-%u", index);
    snprintf(message_id, sizeof(message_id), "%s-%u", message_prefix, index);
    lc_command_t *slot = owner_command_acquire();
    if (slot == NULL) {
        return false;
    }
    const lc_command_t command = fx_open(transaction_id, message_id, 1);
    *slot = command;
    return owner_command_submit(slot);
}

static void run_until_idle(void)
{
    while (owner_queue_step(&handlers, 0)) {
    }
}

static unsigned count_posted_acks(unsigned attempts)
{
    unsigned accepted = 0;
    for (unsigned i = 0; i < attempts; ++i) {
        accepted += owner_post_app(LC_EVENT_PUBLISH_ACKNOWLEDGED, 5000 + i) ? 1u : 0u;
    }
    return accepted;
}

void test_S2_H54_a_burst_of_control_events_cannot_push_out_a_command(void)
{
    for (unsigned i = 1; i <= 3; ++i) {
        TEST_ASSERT_TRUE(post_open(i, "msg"));
    }

    /* Far more than the control queue holds: the surplus is lost, the commands are not. */
    TEST_ASSERT_EQUAL_UINT(OWNER_CONTROL_DEPTH, count_posted_acks(5 * OWNER_CONTROL_DEPTH));
    TEST_ASSERT_FALSE(owner_post_app(LC_EVENT_PUBLISH_FAILED, 1));
    TEST_ASSERT_FALSE(owner_post(&(owner_control_t){.kind = OWNER_NETWORK_UP}));

    run_until_idle();
    TEST_ASSERT_EQUAL_UINT(3, fx_bus.unlock_count);
    TEST_ASSERT_NOT_NULL(strstr(order, "tx-1,tx-2,tx-3,"));
}

void test_S2_H55_with_the_pool_full_a_command_is_dropped_logged_and_never_claimed(void)
{
    for (unsigned i = 1; i <= OWNER_COMMAND_SLOTS; ++i) {
        TEST_ASSERT_TRUE(post_open(i, "msg"));
    }
    unsigned logged = fake_log_count();

    TEST_ASSERT_FALSE(post_open(OWNER_COMMAND_SLOTS + 1, "msg"));
    TEST_ASSERT_EQUAL_UINT(logged + 1, fake_log_count());

    run_until_idle();
    TEST_ASSERT_EQUAL_UINT(OWNER_COMMAND_SLOTS, fx_bus.unlock_count);
    TEST_ASSERT_NOT_NULL(fake_journal_entry(&fx_journal, "tx-32"));
    TEST_ASSERT_NULL(fake_journal_entry(&fx_journal, "tx-33"));
}

/* Fills the pool, drains it, and proves all slots are back. */
static void assert_pool_is_whole(unsigned base)
{
    for (unsigned i = 0; i < OWNER_COMMAND_SLOTS; ++i) {
        TEST_ASSERT_TRUE(post_open(base + i, "whole"));
    }
    TEST_ASSERT_FALSE(post_open(base + OWNER_COMMAND_SLOTS, "whole"));
    /* Drain them through the owner task. */
    for (unsigned i = 0; i < OWNER_COMMAND_SLOTS; ++i) {
        TEST_ASSERT_TRUE(owner_queue_step(&handlers, 0));
    }
}

void test_S2_H56_slots_return_after_dispatch_including_duplicates_and_offline(void)
{
    /* A full pool of one repeated message id: the first runs, the rest are duplicates. */
    for (unsigned i = 0; i < OWNER_COMMAND_SLOTS; ++i) {
        lc_command_t *slot = owner_command_acquire();
        TEST_ASSERT_NOT_NULL(slot);
        const lc_command_t command = fx_open("tx-same", "msg-same", 1);
        *slot = command;
        TEST_ASSERT_TRUE(owner_command_submit(slot));
    }
    run_until_idle();
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    assert_pool_is_whole(100);

    /* Not connected: every command is refused before it is claimed. */
    lc_app_on_disconnected(&fx_app);
    fx_bus.unlock_count = 0;
    for (unsigned i = 0; i < OWNER_COMMAND_SLOTS; ++i) {
        TEST_ASSERT_TRUE(post_open(200 + i, "offline"));
    }
    run_until_idle();
    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    assert_pool_is_whole(300);
}

void test_S2_H57_a_mailbox_refuses_a_second_post_until_it_is_consumed(void)
{
    lc_setup_request_t request;
    memset(&request, 0, sizeof(request));

    TEST_ASSERT_TRUE(owner_post_setup(LC_COMMISSION_CHANNEL_PORTAL, &request));
    TEST_ASSERT_FALSE(owner_post_setup(LC_COMMISSION_CHANNEL_CONSOLE, &request));
    run_until_idle();
    TEST_ASSERT_EQUAL_UINT(1, setups_seen);
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_CHANNEL_PORTAL, last_channel);
    TEST_ASSERT_TRUE(owner_post_setup(LC_COMMISSION_CHANNEL_CONSOLE, &request));
    run_until_idle();
    TEST_ASSERT_EQUAL_UINT(2, setups_seen);

    lc_provisioning_reply_t *reply = owner_reply_acquire();
    TEST_ASSERT_NOT_NULL(reply);
    TEST_ASSERT_NULL(owner_reply_acquire());
    TEST_ASSERT_TRUE(owner_reply_submit());
    TEST_ASSERT_NULL(owner_reply_acquire());
    run_until_idle();
    TEST_ASSERT_EQUAL_UINT(1, replies_seen);

    /* A reply that did not decode is given back without being handled. */
    reply = owner_reply_acquire();
    TEST_ASSERT_NOT_NULL(reply);
    owner_reply_discard(reply);
    TEST_ASSERT_NOT_NULL(owner_reply_acquire());
}

void test_S2_H58_control_events_are_handled_before_a_waiting_command(void)
{
    TEST_ASSERT_TRUE(post_open(1, "msg"));
    TEST_ASSERT_TRUE(owner_post_app(LC_EVENT_PUBLISH_ACKNOWLEDGED, 7));
    TEST_ASSERT_TRUE(owner_post(&(owner_control_t){.kind = OWNER_NETWORK_UP, .value = 8}));
    TEST_ASSERT_TRUE(post_open(2, "msg"));

    TEST_ASSERT_TRUE(owner_queue_step(&handlers, 0));
    /* One step: every control event, then one command. */
    TEST_ASSERT_EQUAL_STRING("c7,c8,tx-1,", order);
    TEST_ASSERT_TRUE(owner_queue_step(&handlers, 0));
    TEST_ASSERT_EQUAL_STRING("c7,c8,tx-1,tx-2,", order);
}

void test_the_step_is_idle_only_when_nothing_was_handled(void)
{
    TEST_ASSERT_FALSE(owner_queue_step(&handlers, TICK_MS));
    TEST_ASSERT_EQUAL_UINT(1, fake_take_count());
    TEST_ASSERT_EQUAL_UINT(TICK_MS, fake_last_take_ticks());

    TEST_ASSERT_TRUE(owner_post_app(LC_EVENT_TICK, 0));
    TEST_ASSERT_TRUE(owner_queue_step(&handlers, TICK_MS));
    /* Work was waiting, so the task did not sleep. */
    TEST_ASSERT_EQUAL_UINT(1, fake_take_count());
}

void test_a_stale_wake_after_a_burst_is_not_idle(void)
{
    TEST_ASSERT_EQUAL_UINT(5, count_posted_acks(5));
    TEST_ASSERT_TRUE(owner_queue_step(&handlers, TICK_MS));
    TEST_ASSERT_EQUAL_UINT(0, fake_take_count());

    /* The posts left a wake behind. It finds nothing, so the task waits again, in full. */
    TEST_ASSERT_FALSE(owner_queue_step(&handlers, TICK_MS));
    TEST_ASSERT_EQUAL_UINT(2, fake_take_count());
    TEST_ASSERT_EQUAL_UINT(1, fake_take_timeouts());
    TEST_ASSERT_EQUAL_UINT(TICK_MS, fake_last_take_ticks());
}

void test_a_late_wake_that_finds_nothing_waits_only_for_the_rest_of_the_budget(void)
{
    TEST_ASSERT_TRUE(owner_post_app(LC_EVENT_TICK, 0));
    TEST_ASSERT_TRUE(owner_queue_step(&handlers, TICK_MS));

    fake_rtos_set_wake_cost(30);
    TEST_ASSERT_FALSE(owner_queue_step(&handlers, TICK_MS));
    TEST_ASSERT_EQUAL_UINT(TICK_MS - 30, fake_last_take_ticks());
}

static unsigned flooded;

static void on_flooding_control(const owner_control_t *event)
{
    (void)event;
    ++flooded;
    /* A producer that posts as fast as the owner task handles. */
    (void)owner_post_app(LC_EVENT_TICK, 0);
}

void test_a_flood_of_control_events_cannot_starve_a_command(void)
{
    const owner_handlers_t flooding = {on_flooding_control, on_command, on_setup, on_reply};

    TEST_ASSERT_TRUE(post_open(1, "msg"));
    TEST_ASSERT_TRUE(owner_post_app(LC_EVENT_TICK, 0));

    TEST_ASSERT_TRUE(owner_queue_step(&flooding, 0));
    TEST_ASSERT_EQUAL_UINT(OWNER_CONTROL_DEPTH, flooded);
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
}

void test_a_refused_submit_releases_the_setup_mailbox(void)
{
    lc_setup_request_t request;
    memset(&request, 0, sizeof(request));

    fake_rtos_fail_next_send();
    TEST_ASSERT_FALSE(owner_post_setup(LC_COMMISSION_CHANNEL_CONSOLE, &request));
    TEST_ASSERT_TRUE(owner_post_setup(LC_COMMISSION_CHANNEL_CONSOLE, &request));
}

void test_a_refused_command_submit_returns_its_slot_zeroed(void)
{
    lc_command_t *slot = owner_command_acquire();
    TEST_ASSERT_NOT_NULL(slot);
    const lc_command_t command = fx_open("tx-secret", "msg-secret", 1);
    *slot = command;

    fake_rtos_fail_next_send();
    TEST_ASSERT_FALSE(owner_command_submit(slot));

    /* The slot is back in the pool, with nothing of the refused command left in it. */
    bool seen = false;
    for (unsigned i = 0; i < OWNER_COMMAND_SLOTS; ++i) {
        lc_command_t *again = owner_command_acquire();
        TEST_ASSERT_NOT_NULL(again);
        seen = seen || again == slot;
        TEST_ASSERT_EQUAL_STRING("", again->transaction_id);
    }
    TEST_ASSERT_TRUE(seen);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S2_H54_a_burst_of_control_events_cannot_push_out_a_command);
    RUN_TEST(test_S2_H55_with_the_pool_full_a_command_is_dropped_logged_and_never_claimed);
    RUN_TEST(test_S2_H56_slots_return_after_dispatch_including_duplicates_and_offline);
    RUN_TEST(test_S2_H57_a_mailbox_refuses_a_second_post_until_it_is_consumed);
    RUN_TEST(test_S2_H58_control_events_are_handled_before_a_waiting_command);
    RUN_TEST(test_the_step_is_idle_only_when_nothing_was_handled);
    RUN_TEST(test_a_stale_wake_after_a_burst_is_not_idle);
    RUN_TEST(test_a_late_wake_that_finds_nothing_waits_only_for_the_rest_of_the_budget);
    RUN_TEST(test_a_flood_of_control_events_cannot_starve_a_command);
    RUN_TEST(test_a_refused_submit_releases_the_setup_mailbox);
    RUN_TEST(test_a_refused_command_submit_returns_its_slot_zeroed);
    return UNITY_END();
}
