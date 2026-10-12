#include <stdio.h>
#include <string.h>

#include "fakes/fake_flash.h"
#include "lc_journal/journal.h"
#include "unity.h"

#define FLASH_SIZE (48 * 1024)
#define HASH "3306f9a74f407226b567536b577fa7dfd460a674850ce9d1818aabe94f55ab62"

static fake_flash_t flash;
static lc_journal_t journal;
static lc_command_journal_port_t port;
static lc_journal_entry_t entry;

/* Opens the journal over whatever the flash holds now, as a restart does. */
static bool reopen(void)
{
    memset(&journal, 0, sizeof(journal));
    bool opened = lc_journal_open(&journal, fake_flash_io(&flash));
    port = lc_journal_port(&journal);
    return opened;
}

void setUp(void)
{
    fake_flash_init(&flash, FLASH_SIZE);
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(port.healthy(port.ctx));
}

void tearDown(void)
{
}

static lc_command_result_t result_for(const char *transaction_id, lc_error_t error)
{
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    snprintf(result.transaction_id, sizeof(result.transaction_id), "%s", transaction_id);
    snprintf(result.action_name, sizeof(result.action_name), "open_compartment");
    result.error = error;
    return result;
}

static bool claim(const char *transaction_id, const char *message_id)
{
    return port.claim(port.ctx, transaction_id, message_id, "open_compartment");
}

static bool complete(const char *transaction_id, lc_error_t error)
{
    const lc_command_result_t result = result_for(transaction_id, error);
    return port.complete(port.ctx, &result);
}

static bool find(const char *transaction_id)
{
    memset(&entry, 0, sizeof(entry));
    return port.find(port.ctx, transaction_id, &entry);
}

void test_S2_H19_claim_is_found_and_its_message_id_is_seen(void)
{
    TEST_ASSERT_FALSE(find("tx-1"));
    TEST_ASSERT_FALSE(port.seen_message(port.ctx, "msg-1"));

    TEST_ASSERT_TRUE(claim("tx-1", "msg-1"));

    TEST_ASSERT_TRUE(find("tx-1"));
    TEST_ASSERT_EQUAL_STRING("tx-1", entry.transaction_id);
    TEST_ASSERT_EQUAL_STRING("open_compartment", entry.action_name);
    TEST_ASSERT_FALSE(entry.completed);
    TEST_ASSERT_FALSE(entry.delivered);
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "msg-1"));
    TEST_ASSERT_FALSE(port.seen_message(port.ctx, "msg-2"));
    TEST_ASSERT_EQUAL_UINT(1, port.count(port.ctx));

    /* A transaction is claimed once. */
    TEST_ASSERT_FALSE(claim("tx-1", "msg-2"));
    TEST_ASSERT_EQUAL_UINT(1, port.count(port.ctx));

    /* A redelivery under a new message id is remembered against it. */
    TEST_ASSERT_TRUE(port.remember_message(port.ctx, "tx-1", "msg-3"));
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "msg-3"));
    TEST_ASSERT_FALSE(port.remember_message(port.ctx, "tx-unknown", "msg-4"));
}

void test_S2_H20_records_survive_closing_and_reopening(void)
{
    lc_command_result_t applied = result_for("tx-3", LC_ERROR_NONE);
    snprintf(applied.action_name, sizeof(applied.action_name), "apply_config");
    snprintf(applied.applied_config_hash, sizeof(applied.applied_config_hash), HASH);

    TEST_ASSERT_TRUE(claim("tx-1", "msg-1"));
    TEST_ASSERT_TRUE(claim("tx-2", "msg-2"));
    TEST_ASSERT_TRUE(port.claim(port.ctx, "tx-3", "msg-3", "apply_config"));
    TEST_ASSERT_TRUE(complete("tx-2", LC_ERROR_HARDWARE_ERROR));
    TEST_ASSERT_TRUE(port.complete(port.ctx, &applied));
    TEST_ASSERT_TRUE(port.mark_delivered(port.ctx, "tx-3"));
    TEST_ASSERT_TRUE(port.remember_message(port.ctx, "tx-1", "msg-1b"));

    TEST_ASSERT_TRUE(reopen());

    TEST_ASSERT_TRUE(port.healthy(port.ctx));
    TEST_ASSERT_EQUAL_UINT(3, port.count(port.ctx));
    TEST_ASSERT_TRUE(find("tx-1"));
    TEST_ASSERT_FALSE(entry.completed);
    TEST_ASSERT_TRUE(find("tx-2"));
    TEST_ASSERT_TRUE(entry.completed);
    TEST_ASSERT_FALSE(entry.delivered);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_HARDWARE_ERROR, entry.result.error);
    TEST_ASSERT_EQUAL_STRING("tx-2", entry.result.transaction_id);
    TEST_ASSERT_TRUE(find("tx-3"));
    TEST_ASSERT_TRUE(entry.completed);
    TEST_ASSERT_TRUE(entry.delivered);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, entry.result.error);
    TEST_ASSERT_EQUAL_STRING("apply_config", entry.result.action_name);
    TEST_ASSERT_EQUAL_STRING(HASH, entry.result.applied_config_hash);
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "msg-1"));
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "msg-1b"));
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "msg-3"));

    /* Claim order is kept. */
    TEST_ASSERT_TRUE(port.at(port.ctx, 0, &entry));
    TEST_ASSERT_EQUAL_STRING("tx-1", entry.transaction_id);
    TEST_ASSERT_TRUE(port.at(port.ctx, 2, &entry));
    TEST_ASSERT_EQUAL_STRING("tx-3", entry.transaction_id);
    TEST_ASSERT_FALSE(port.at(port.ctx, 3, &entry));

    /* And the journal goes on working. */
    TEST_ASSERT_TRUE(claim("tx-4", "msg-4"));
    TEST_ASSERT_TRUE(complete("tx-1", LC_ERROR_NONE));
}

/* Bytes one operation writes, measured on a journal prepared the same way. */
typedef void (*prepare_t)(void);
typedef bool (*operation_t)(void);

static void prepare_nothing(void)
{
}

static void prepare_claim(void)
{
    TEST_ASSERT_TRUE(claim("tx-0", "msg-0"));
    TEST_ASSERT_TRUE(complete("tx-0", LC_ERROR_NONE));
    TEST_ASSERT_TRUE(claim("tx-1", "msg-1"));
}

static void prepare_completion(void)
{
    prepare_claim();
    TEST_ASSERT_TRUE(complete("tx-1", LC_ERROR_HARDWARE_ERROR));
}

static bool do_claim(void)
{
    return claim("tx-1", "msg-1");
}

static bool do_complete(void)
{
    return complete("tx-1", LC_ERROR_HARDWARE_ERROR);
}

static bool do_deliver(void)
{
    return port.mark_delivered(port.ctx, "tx-1");
}

static size_t bytes_written_by(prepare_t prepare, operation_t operation)
{
    fake_flash_init(&flash, FLASH_SIZE);
    TEST_ASSERT_TRUE(reopen());
    prepare();
    size_t before = flash.bytes_written;
    TEST_ASSERT_TRUE(operation());
    TEST_ASSERT_TRUE(flash.bytes_written > before);
    return flash.bytes_written - before;
}

/* Runs `operation` with power lost after `landed` of its bytes, then restarts. */
static bool restart_after_cut(prepare_t prepare, operation_t operation, size_t landed)
{
    fake_flash_init(&flash, FLASH_SIZE);
    TEST_ASSERT_TRUE(reopen());
    prepare();
    fake_flash_cut_power_after(&flash, landed);
    TEST_ASSERT_FALSE(operation());
    fake_flash_restore_power(&flash);
    return reopen();
}

void test_S2_H21_torn_claim_leaves_no_claim_or_an_unhealthy_journal(void)
{
    size_t total = bytes_written_by(prepare_nothing, do_claim);

    for (size_t landed = 0; landed < total; ++landed) {
        if (!restart_after_cut(prepare_nothing, do_claim, landed)) {
            TEST_ASSERT_FALSE(port.healthy(port.ctx));
            continue;
        }
        TEST_ASSERT_TRUE(port.healthy(port.ctx));
        TEST_ASSERT_FALSE_MESSAGE(find("tx-1"), "a torn claim was accepted");
        TEST_ASSERT_FALSE(port.seen_message(port.ctx, "msg-1"));
        TEST_ASSERT_EQUAL_UINT(0, port.count(port.ctx));
        /* A healthy journal must stay usable after the torn record. */
        TEST_ASSERT_TRUE(claim("tx-2", "msg-2"));
        TEST_ASSERT_TRUE(reopen());
        TEST_ASSERT_TRUE(find("tx-2"));
        TEST_ASSERT_FALSE(find("tx-1"));
    }
}

void test_S2_H22_torn_completion_or_delivery_never_yields_a_half_record(void)
{
    size_t total = bytes_written_by(prepare_claim, do_complete);
    for (size_t landed = 0; landed < total; ++landed) {
        if (!restart_after_cut(prepare_claim, do_complete, landed)) {
            TEST_ASSERT_FALSE(port.healthy(port.ctx));
            continue;
        }
        /* The claim stands and is still incomplete; the earlier record is intact. */
        TEST_ASSERT_TRUE(find("tx-1"));
        TEST_ASSERT_FALSE_MESSAGE(entry.completed, "a torn completion was accepted");
        TEST_ASSERT_TRUE(find("tx-0"));
        TEST_ASSERT_TRUE(entry.completed);
        TEST_ASSERT_EQUAL_INT(LC_ERROR_NONE, entry.result.error);
    }

    total = bytes_written_by(prepare_completion, do_deliver);
    for (size_t landed = 0; landed < total; ++landed) {
        if (!restart_after_cut(prepare_completion, do_deliver, landed)) {
            TEST_ASSERT_FALSE(port.healthy(port.ctx));
            continue;
        }
        TEST_ASSERT_TRUE(find("tx-1"));
        TEST_ASSERT_TRUE(entry.completed);
        TEST_ASSERT_EQUAL_INT(LC_ERROR_HARDWARE_ERROR, entry.result.error);
        TEST_ASSERT_FALSE_MESSAGE(entry.delivered, "a torn delivery record was accepted");
    }
}

void test_S2_H23_bad_checksum_marks_the_journal_unhealthy(void)
{
    TEST_ASSERT_TRUE(claim("tx-1", "msg-1"));
    TEST_ASSERT_TRUE(complete("tx-1", LC_ERROR_NONE));
    unsigned corrupted = 0;

    /*
     * Clear one bit in every programmed byte in turn. Bytes still 0xFF may never
     * have been written, and 0x00 has no bit left to clear.
     */
    for (size_t offset = 0; offset < FLASH_SIZE; ++offset) {
        uint8_t original = flash.memory[offset];
        if (original == 0x00 || original == 0xFF) {
            continue;
        }
        ++corrupted;
        flash.memory[offset] = (uint8_t)(original & (original - 1));

        TEST_ASSERT_FALSE_MESSAGE(reopen(), "a corrupted record was accepted");
        TEST_ASSERT_FALSE(port.healthy(port.ctx));
        TEST_ASSERT_FALSE(claim("tx-2", "msg-2"));

        flash.memory[offset] = original;
    }

    TEST_ASSERT_TRUE(corrupted > 0);
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(find("tx-1"));
}

/* Claims until the journal refuses. Returns how many it took. */
static unsigned fill(void)
{
    unsigned claimed = 0;
    for (unsigned i = 0; i < 4 * LC_JOURNAL_TRANSACTIONS_MAX; ++i) {
        char transaction_id[32];
        char message_id[32];
        snprintf(transaction_id, sizeof(transaction_id), "tx-%u", i);
        snprintf(message_id, sizeof(message_id), "msg-%u", i);
        if (!claim(transaction_id, message_id)) {
            break;
        }
        ++claimed;
    }
    return claimed;
}

void test_S2_H24_claim_is_refused_without_room_for_completion_and_delivery(void)
{
    /* A small region, so that space is likely to run out before the transaction limit. */
    fake_flash_init(&flash, 16 * 1024);
    TEST_ASSERT_TRUE(reopen());

    unsigned claimed = fill();

    TEST_ASSERT_TRUE(claimed > 0);
    TEST_ASSERT_TRUE(claimed <= LC_JOURNAL_TRANSACTIONS_MAX);
    /* Every claim that was accepted can still be finished. */
    for (unsigned i = 0; i < claimed; ++i) {
        char transaction_id[32];
        snprintf(transaction_id, sizeof(transaction_id), "tx-%u", i);
        TEST_ASSERT_TRUE_MESSAGE(complete(transaction_id, LC_ERROR_HARDWARE_ERROR),
                                 transaction_id);
        TEST_ASSERT_TRUE_MESSAGE(port.mark_delivered(port.ctx, transaction_id), transaction_id);
    }
    TEST_ASSERT_TRUE(port.healthy(port.ctx));
}

void test_S2_H25_full_journal_refuses_claims_and_keeps_every_response(void)
{
    /* Full by the transaction limit or by space, whichever comes first. */
    unsigned claimed = fill();
    TEST_ASSERT_TRUE(claimed > 0);
    TEST_ASSERT_TRUE(claimed <= LC_JOURNAL_TRANSACTIONS_MAX);
    for (unsigned i = 0; i < claimed; ++i) {
        char transaction_id[32];
        snprintf(transaction_id, sizeof(transaction_id), "tx-%u", i);
        TEST_ASSERT_TRUE(complete(transaction_id, i % 2 ? LC_ERROR_HARDWARE_ERROR : LC_ERROR_NONE));
    }

    TEST_ASSERT_FALSE(claim("tx-one-too-many", "msg-one-too-many"));
    TEST_ASSERT_TRUE(port.healthy(port.ctx));
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_FALSE(claim("tx-one-too-many", "msg-one-too-many"));

    TEST_ASSERT_EQUAL_UINT(claimed, port.count(port.ctx));
    for (unsigned i = 0; i < claimed; ++i) {
        char transaction_id[32];
        snprintf(transaction_id, sizeof(transaction_id), "tx-%u", i);
        TEST_ASSERT_TRUE_MESSAGE(find(transaction_id), transaction_id);
        TEST_ASSERT_TRUE(entry.completed);
        TEST_ASSERT_EQUAL_INT(i % 2 ? LC_ERROR_HARDWARE_ERROR : LC_ERROR_NONE, entry.result.error);
    }
}

void test_S2_H26_reopening_never_erases_or_rewrites(void)
{
    static uint8_t before[FLASH_SIZE];

    TEST_ASSERT_TRUE(claim("tx-1", "msg-1"));
    TEST_ASSERT_TRUE(complete("tx-1", LC_ERROR_NONE));
    TEST_ASSERT_TRUE(claim("tx-2", "msg-2"));
    memcpy(before, flash.memory, FLASH_SIZE);
    unsigned write_calls = flash.write_calls;

    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(reopen());

    TEST_ASSERT_EQUAL_UINT(write_calls, flash.write_calls);
    TEST_ASSERT_EQUAL_MEMORY(before, flash.memory, FLASH_SIZE);

    /* Appending leaves everything written before it untouched. */
    size_t written = flash.bytes_written;
    TEST_ASSERT_TRUE(port.mark_delivered(port.ctx, "tx-1"));
    TEST_ASSERT_TRUE(flash.bytes_written > written);
    unsigned changed_bytes = 0;
    for (size_t offset = 0; offset < FLASH_SIZE; ++offset) {
        if (before[offset] != 0xFF) {
            TEST_ASSERT_EQUAL_HEX8(before[offset], flash.memory[offset]);
        } else if (flash.memory[offset] != 0xFF) {
            ++changed_bytes;
        }
    }
    TEST_ASSERT_TRUE(changed_bytes > 0);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S2_H19_claim_is_found_and_its_message_id_is_seen);
    RUN_TEST(test_S2_H20_records_survive_closing_and_reopening);
    RUN_TEST(test_S2_H21_torn_claim_leaves_no_claim_or_an_unhealthy_journal);
    RUN_TEST(test_S2_H22_torn_completion_or_delivery_never_yields_a_half_record);
    RUN_TEST(test_S2_H23_bad_checksum_marks_the_journal_unhealthy);
    RUN_TEST(test_S2_H24_claim_is_refused_without_room_for_completion_and_delivery);
    RUN_TEST(test_S2_H25_full_journal_refuses_claims_and_keeps_every_response);
    RUN_TEST(test_S2_H26_reopening_never_erases_or_rewrites);
    return UNITY_END();
}
