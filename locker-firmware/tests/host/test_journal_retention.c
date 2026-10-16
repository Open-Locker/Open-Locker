#include <stdio.h>
#include <string.h>

#include "fakes/fake_flash.h"
#include "lc_journal/journal.h"
#include "unity.h"

/*
 * Retention and compaction of the journal (ADR-0068): two regions, the newest
 * delivered transactions kept, open ones never forgotten, and a power cut at
 * any byte or erase of a compaction losing nothing that is needed. Tests
 * without a plan ID, as section 4.1 allows; HW-B6 checks the same on a device.
 */

#define SMALL_FLASH (16 * 1024)
#define REGION (SMALL_FLASH / 2)

static fake_flash_t flash;
static lc_journal_t journal;
static lc_command_journal_port_t port;
static lc_journal_entry_t entry;

static bool reopen(void)
{
    memset(&journal, 0, sizeof(journal));
    bool opened = lc_journal_open(&journal, fake_flash_io(&flash));
    port = lc_journal_port(&journal);
    return opened;
}

static void settle(void)
{
    while (lc_journal_maintain(&journal)) {
    }
}

/* Erases the spare without starting a compaction that may be due. */
static void prepare_spare(void)
{
    while (!journal.spare_ready && lc_journal_maintain(&journal)) {
    }
    TEST_ASSERT_TRUE(journal.spare_ready);
}

void setUp(void)
{
    fake_flash_init(&flash, SMALL_FLASH);
    TEST_ASSERT_TRUE(reopen());
}

void tearDown(void)
{
}

static bool claim(const char *transaction_id)
{
    char message_id[LC_ID_MAX + 1];
    snprintf(message_id, sizeof(message_id), "m-%s", transaction_id);
    return port.claim(port.ctx, transaction_id, message_id, "open_compartment");
}

static bool complete(const char *transaction_id)
{
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    snprintf(result.transaction_id, sizeof(result.transaction_id), "%s", transaction_id);
    snprintf(result.action_name, sizeof(result.action_name), "open_compartment");
    return port.complete(port.ctx, &result);
}

/* Claims, completes and delivers transaction `n`. */
static void run_command(unsigned n)
{
    char transaction_id[32];
    snprintf(transaction_id, sizeof(transaction_id), "tx-%u", n);
    TEST_ASSERT_TRUE_MESSAGE(claim(transaction_id), transaction_id);
    TEST_ASSERT_TRUE(complete(transaction_id));
    TEST_ASSERT_TRUE(port.mark_delivered(port.ctx, transaction_id));
}

static bool known(unsigned n)
{
    char transaction_id[32];
    snprintf(transaction_id, sizeof(transaction_id), "tx-%u", n);
    return port.find(port.ctx, transaction_id, &entry);
}

void test_compaction_keeps_open_and_the_newest_delivered_transactions(void)
{
    /* The real partition: 256 KiB, two regions of 128 KiB. */
    fake_flash_init(&flash, 256 * 1024);
    TEST_ASSERT_TRUE(reopen());
    settle();

    /* An open transaction from the start, still unacknowledged at the end. */
    TEST_ASSERT_TRUE(claim("open-claimed"));
    TEST_ASSERT_TRUE(claim("open-completed"));
    TEST_ASSERT_TRUE(complete("open-completed"));

    const unsigned commands = 1000;
    for (unsigned n = 0; n < commands; ++n) {
        run_command(n);
        /*
         * A few idle ticks per command. The spare takes 33 steps to get ready
         * and the index is full 30 commands after a compaction, so a faster
         * burst is refused until the spare is ready.
         */
        for (int tick = 0; tick < 4; ++tick) {
            (void)lc_journal_maintain(&journal);
        }
    }
    settle();
    TEST_ASSERT_TRUE(journal.generation > 2);
    TEST_ASSERT_TRUE(reopen());

    TEST_ASSERT_TRUE(port.find(port.ctx, "open-claimed", &entry));
    TEST_ASSERT_FALSE(entry.completed);
    TEST_ASSERT_TRUE(port.find(port.ctx, "open-completed", &entry));
    TEST_ASSERT_TRUE(entry.completed);
    TEST_ASSERT_FALSE(entry.delivered);
    TEST_ASSERT_EQUAL_UINT(2, port.count(port.ctx));
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "m-open-claimed"));

    for (unsigned n = commands - LC_JOURNAL_DELIVERED_KEEP; n < commands; ++n) {
        TEST_ASSERT_TRUE_MESSAGE(known(n), "a recent delivered transaction was forgotten");
        TEST_ASSERT_TRUE(entry.delivered);
    }
    /* An old one is forgotten completely, and is executed as new. */
    TEST_ASSERT_FALSE(known(0));
    TEST_ASSERT_FALSE(port.seen_message(port.ctx, "m-tx-0"));
    settle();
    run_command(0);
}

/* Delivered transactions, then one claimed and one completed, filling a small region past 75 %. */
static void fill_small_region(void)
{
    fake_flash_init(&flash, SMALL_FLASH);
    TEST_ASSERT_TRUE(reopen());
    prepare_spare();
    for (unsigned n = 0; n < 70; ++n) {
        run_command(n);
    }
    TEST_ASSERT_TRUE(claim("open-claimed"));
    TEST_ASSERT_TRUE(claim("open-completed"));
    TEST_ASSERT_TRUE(complete("open-completed"));
    TEST_ASSERT_TRUE(port.remember_message(port.ctx, "open-claimed", "m-again"));
    TEST_ASSERT_TRUE(journal.next_offset > REGION / 4 * 3);
    TEST_ASSERT_EQUAL_UINT(1, journal.generation);
}

static void assert_nothing_needed_is_lost(void)
{
    TEST_ASSERT_TRUE(port.healthy(port.ctx));
    TEST_ASSERT_TRUE(port.find(port.ctx, "open-claimed", &entry));
    TEST_ASSERT_FALSE(entry.completed);
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "m-again"));
    TEST_ASSERT_TRUE(port.find(port.ctx, "open-completed", &entry));
    TEST_ASSERT_TRUE(entry.completed);
    TEST_ASSERT_FALSE(entry.delivered);
    TEST_ASSERT_TRUE(known(69));
    TEST_ASSERT_TRUE(entry.delivered);
}

void test_a_cut_anywhere_in_a_compaction_loses_nothing_that_is_needed(void)
{
    static uint8_t before[SMALL_FLASH];

    fill_small_region();
    memcpy(before, flash.memory, SMALL_FLASH);
    size_t units = flash.bytes_written + flash.erase_calls;
    TEST_ASSERT_TRUE(lc_journal_maintain(&journal));
    units = flash.bytes_written + flash.erase_calls - units;
    TEST_ASSERT_EQUAL_UINT(2, journal.generation);
    assert_nothing_needed_is_lost();
    /* Half a region holds fewer than 70 delivered transactions. */
    TEST_ASSERT_FALSE(known(0));

    for (size_t cut = 0; cut < units; ++cut) {
        memcpy(flash.memory, before, SMALL_FLASH);
        TEST_ASSERT_TRUE(reopen());
        prepare_spare();
        fake_flash_cut_power_after(&flash, cut);
        TEST_ASSERT_FALSE(lc_journal_maintain(&journal));
        fake_flash_restore_power(&flash);

        TEST_ASSERT_TRUE_MESSAGE(reopen(), "a cut compaction left an unhealthy journal");
        assert_nothing_needed_is_lost();
        settle();
        TEST_ASSERT_TRUE(claim("after"));
        TEST_ASSERT_TRUE(reopen());
        assert_nothing_needed_is_lost();
    }
}

void test_with_two_valid_headers_the_newer_generation_wins(void)
{
    uint8_t old_header[16];

    fill_small_region();
    memcpy(old_header, flash.memory, sizeof(old_header));
    TEST_ASSERT_TRUE(lc_journal_maintain(&journal));
    /* The restart came between committing region B and invalidating region A. */
    memcpy(flash.memory, old_header, sizeof(old_header));

    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_EQUAL_UINT(1, journal.active);
    TEST_ASSERT_FALSE(known(0));
    assert_nothing_needed_is_lost();

    /* Two regions of the same generation cannot be told apart. */
    memcpy(flash.memory + REGION, old_header, sizeof(old_header));
    TEST_ASSERT_FALSE(reopen());
    TEST_ASSERT_FALSE(claim("refused"));
}

void test_a_spare_that_does_not_erase_marks_the_journal_unhealthy(void)
{
    TEST_ASSERT_TRUE(claim("tx-1"));
    flash.memory[REGION + FAKE_FLASH_SECTOR - 1] = 0x00;
    flash.erase_leaves_bits = true;

    settle();

    TEST_ASSERT_FALSE(port.healthy(port.ctx));
    TEST_ASSERT_FALSE(claim("tx-2"));
    /* Erased twice and checked twice before giving up. */
    TEST_ASSERT_EQUAL_UINT(2 * REGION / FAKE_FLASH_SECTOR, flash.erase_calls);
}

void test_an_empty_journal_is_told_apart_from_a_corrupt_one(void)
{
    /* The first header write was cut: still empty, and the next append starts over. */
    flash.memory[0] = 'O';
    flash.memory[1] = 'L';
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_EQUAL_UINT(0, port.count(port.ctx));
    TEST_ASSERT_TRUE(claim("tx-1"));
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(port.find(port.ctx, "tx-1", &entry));

    /* No header, but something was written: never read as "nothing was done". */
    fake_flash_init(&flash, SMALL_FLASH);
    flash.memory[REGION + 100] = 0x12;
    TEST_ASSERT_FALSE(reopen());

    fake_flash_init(&flash, SMALL_FLASH);
    flash.memory[100] = 0x12;
    TEST_ASSERT_FALSE(reopen());
}

void test_a_full_region_refuses_claims_until_the_spare_is_ready(void)
{
    unsigned n = 0;
    for (;; ++n) {
        char transaction_id[32];
        snprintf(transaction_id, sizeof(transaction_id), "tx-%u", n);
        if (!claim(transaction_id)) {
            break;
        }
        TEST_ASSERT_TRUE(complete(transaction_id));
        TEST_ASSERT_TRUE(port.mark_delivered(port.ctx, transaction_id));
    }
    TEST_ASSERT_TRUE(n > 0);
    TEST_ASSERT_TRUE(port.healthy(port.ctx));

    settle();
    run_command(n);
    TEST_ASSERT_TRUE(known(n - 1));
}

void test_the_open_limit_clears_as_responses_are_delivered(void)
{
    char transaction_id[32];
    for (unsigned n = 0; n < LC_JOURNAL_OPEN_MAX; ++n) {
        snprintf(transaction_id, sizeof(transaction_id), "tx-%u", n);
        TEST_ASSERT_TRUE(claim(transaction_id));
        TEST_ASSERT_TRUE(complete(transaction_id));
    }
    TEST_ASSERT_FALSE(claim("one-too-many"));
    /* Stored responses are still delivered while claims are refused. */
    TEST_ASSERT_TRUE(port.mark_delivered(port.ctx, "tx-0"));
    TEST_ASSERT_TRUE(claim("one-too-many"));
}

void test_open_message_ids_past_the_copy_budget_are_dropped(void)
{
    char message_id[LC_ID_MAX + 1];
    unsigned remembered = 0;

    prepare_spare();
    TEST_ASSERT_TRUE(claim("tx-1"));
    /* More message ids for an open transaction than half a region holds. */
    for (unsigned n = 0; n < 200; ++n) {
        memset(message_id, 'm', 100);
        snprintf(message_id + 100, sizeof(message_id) - 100, "-%u", n);
        if (!port.remember_message(port.ctx, "tx-1", message_id)) {
            break;
        }
        ++remembered;
    }
    TEST_ASSERT_TRUE(remembered > 0);
    TEST_ASSERT_TRUE(journal.generation > 1);
    TEST_ASSERT_TRUE(port.healthy(port.ctx));

    /* The transaction is kept; the oldest of its extra message ids are not. */
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(port.find(port.ctx, "tx-1", &entry));
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "m-tx-1"));
    TEST_ASSERT_TRUE(complete("tx-1"));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_compaction_keeps_open_and_the_newest_delivered_transactions);
    RUN_TEST(test_a_cut_anywhere_in_a_compaction_loses_nothing_that_is_needed);
    RUN_TEST(test_with_two_valid_headers_the_newer_generation_wins);
    RUN_TEST(test_a_spare_that_does_not_erase_marks_the_journal_unhealthy);
    RUN_TEST(test_an_empty_journal_is_told_apart_from_a_corrupt_one);
    RUN_TEST(test_a_full_region_refuses_claims_until_the_spare_is_ready);
    RUN_TEST(test_the_open_limit_clears_as_responses_are_delivered);
    RUN_TEST(test_open_message_ids_past_the_copy_budget_are_dropped);
    return UNITY_END();
}
