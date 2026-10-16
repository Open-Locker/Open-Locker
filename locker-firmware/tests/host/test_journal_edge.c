#include <stdio.h>
#include <string.h>

#include "fakes/fake_flash.h"
#include "lc_journal/journal.h"
#include "unity.h"

/*
 * Behaviour of the journal beyond the test catalogue: what happens after a torn
 * write, and records that are intact but make no sense. Tests without a plan
 * ID, written before the code they cover, as docs/testing.md allows. Compaction
 * and retention are in test_journal_retention.c.
 */

#define FLASH_SIZE (16 * 1024)

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

void setUp(void)
{
    fake_flash_init(&flash, FLASH_SIZE);
    TEST_ASSERT_TRUE(reopen());
}

void tearDown(void)
{
}

static bool claim(const char *transaction_id, const char *message_id)
{
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

/* ---- Hand-made records, with correct checks, for contents that make no sense ---- */

static uint8_t crc8(const uint8_t *data, size_t length)
{
    uint8_t crc = 0;
    for (size_t i = 0; i < length; ++i) {
        crc ^= data[i];
        for (int bit = 0; bit < 8; ++bit) {
            crc = (uint8_t)((crc & 0x80) ? (crc << 1) ^ 0x07 : crc << 1);
        }
    }
    return crc;
}

static uint32_t crc32(const uint8_t *data, size_t length)
{
    uint32_t crc = 0xFFFFFFFFu;
    for (size_t i = 0; i < length; ++i) {
        crc ^= data[i];
        for (int bit = 0; bit < 8; ++bit) {
            crc = (crc & 1) ? (crc >> 1) ^ 0xEDB88320u : crc >> 1;
        }
    }
    return ~crc;
}

/* Erased flash with a valid header for region A; records start at REGION_START. */
#define REGION_START 16

static void blank(void)
{
    fake_flash_init(&flash, FLASH_SIZE);
    uint8_t header[16] = {'O', 'L', 'J', 'R', 1, 0xFF, 0xFF, 0xFF, 1, 0, 0, 0};
    uint32_t check = crc32(header, 12);
    for (size_t i = 0; i < 4; ++i) {
        header[12 + i] = (uint8_t)(check >> (8 * i));
    }
    memcpy(flash.memory, header, sizeof(header));
}

/* Appends a committed record at `offset`; returns the offset after it. */
static size_t put_record(size_t offset, uint8_t version, uint8_t type, const uint8_t *payload,
                         size_t length)
{
    uint8_t record[512];
    record[0] = 0xA7;
    record[1] = version;
    record[2] = type;
    record[3] = (uint8_t)(length & 0xFF);
    record[4] = (uint8_t)(length >> 8);
    record[5] = crc8(record + 1, 4);
    memcpy(record + 6, payload, length);
    uint32_t check = crc32(record + 1, 5 + length);
    for (size_t i = 0; i < 4; ++i) {
        record[6 + length + i] = (uint8_t)(check >> (8 * i));
    }
    record[10 + length] = 0xC3;
    memcpy(flash.memory + offset, record, 11 + length);
    return offset + 11 + length;
}

/* One length-prefixed text. */
static size_t text(uint8_t *out, const char *value)
{
    size_t length = strlen(value);
    out[0] = (uint8_t)length;
    memcpy(out + 1, value, length);
    return 1 + length;
}

static size_t claim_payload(uint8_t *out, const char *tx, const char *message, const char *action)
{
    size_t length = text(out, tx);
    length += text(out + length, message);
    length += text(out + length, action);
    return length;
}

static void assert_unhealthy_open(void)
{
    TEST_ASSERT_FALSE(reopen());
    TEST_ASSERT_FALSE(port.healthy(port.ctx));
    TEST_ASSERT_FALSE(claim("tx-after", "msg-after"));
}

void test_log_goes_on_after_a_torn_claim(void)
{
    /* A cut at every point of a claim, each followed by more work. */
    for (size_t landed = 1; landed < 60; ++landed) {
        fake_flash_init(&flash, FLASH_SIZE);
        TEST_ASSERT_TRUE(reopen());
        TEST_ASSERT_TRUE(claim("tx-0", "msg-0"));
        TEST_ASSERT_TRUE(complete("tx-0"));
        fake_flash_cut_power_after(&flash, landed);
        bool claimed = claim("tx-1", "msg-1");
        fake_flash_restore_power(&flash);
        if (claimed) {
            /* The claim was short enough to land whole. */
            continue;
        }
        /* After a failed write nothing more is accepted until the next start. */
        TEST_ASSERT_FALSE(port.healthy(port.ctx));
        TEST_ASSERT_FALSE(claim("tx-x", "msg-x"));

        TEST_ASSERT_TRUE(reopen());
        /* The torn tail is left behind by a compaction before anything is appended. */
        settle();
        TEST_ASSERT_TRUE(claim("tx-2", "msg-2"));
        TEST_ASSERT_TRUE(complete("tx-2"));
        TEST_ASSERT_TRUE(claim("tx-3", "msg-3"));

        TEST_ASSERT_TRUE_MESSAGE(reopen(), "the log cannot be read after going on");
        TEST_ASSERT_TRUE(port.find(port.ctx, "tx-0", &entry));
        TEST_ASSERT_FALSE(port.find(port.ctx, "tx-1", &entry));
        TEST_ASSERT_TRUE(port.find(port.ctx, "tx-2", &entry));
        TEST_ASSERT_TRUE(entry.completed);
        TEST_ASSERT_TRUE(port.find(port.ctx, "tx-3", &entry));
        TEST_ASSERT_FALSE(entry.completed);
        TEST_ASSERT_EQUAL_UINT(3, port.count(port.ctx));
    }
}

void test_bytes_between_records_are_corruption(void)
{
    uint8_t payload[200];
    blank();
    size_t length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    size_t offset = put_record(REGION_START, 1, 1, payload, length);
    memset(flash.memory + offset, 0x00, 7);
    length = claim_payload(payload, "tx-2", "msg-2", "open_compartment");
    put_record(offset + 7, 1, 1, payload, length);

    assert_unhealthy_open();
}

void test_records_that_make_no_sense_mark_the_journal_unhealthy(void)
{
    uint8_t payload[300];
    size_t length;
    size_t offset;

    /* The same transaction claimed twice. */
    blank();
    length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    offset = put_record(REGION_START, 1, 1, payload, length);
    length = claim_payload(payload, "tx-1", "msg-2", "open_compartment");
    put_record(offset, 1, 1, payload, length);
    assert_unhealthy_open();

    /* The same message id under two transactions. */
    blank();
    length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    offset = put_record(REGION_START, 1, 1, payload, length);
    length = claim_payload(payload, "tx-2", "msg-1", "open_compartment");
    put_record(offset, 1, 1, payload, length);
    assert_unhealthy_open();

    /* A completion for a transaction nobody claimed. */
    blank();
    length = text(payload, "tx-9");
    length += text(payload + length, "open_compartment");
    payload[length++] = 0;
    length += text(payload + length, "");
    put_record(REGION_START, 1, 2, payload, length);
    assert_unhealthy_open();

    /* A delivery before the completion, and a message for nobody. */
    blank();
    length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    offset = put_record(REGION_START, 1, 1, payload, length);
    length = text(payload, "tx-1");
    put_record(offset, 1, 3, payload, length);
    assert_unhealthy_open();

    blank();
    length = text(payload, "tx-nobody");
    length += text(payload + length, "msg-1");
    put_record(REGION_START, 1, 4, payload, length);
    assert_unhealthy_open();

    /* An error code that does not exist. */
    blank();
    length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    offset = put_record(REGION_START, 1, 1, payload, length);
    length = text(payload, "tx-1");
    length += text(payload + length, "open_compartment");
    payload[length++] = 200;
    length += text(payload + length, "");
    put_record(offset, 1, 2, payload, length);
    assert_unhealthy_open();

    /* An empty transaction id, a text that runs past its record, a stray byte. */
    blank();
    length = claim_payload(payload, "", "msg-1", "open_compartment");
    put_record(REGION_START, 1, 1, payload, length);
    assert_unhealthy_open();

    blank();
    payload[0] = 50;
    memcpy(payload + 1, "tx", 2);
    put_record(REGION_START, 1, 1, payload, 3);
    assert_unhealthy_open();

    blank();
    length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    payload[length++] = 0x42;
    put_record(REGION_START, 1, 1, payload, length);
    assert_unhealthy_open();

    /* A claim without a message id, and a later message id that is empty. */
    blank();
    length = claim_payload(payload, "tx-1", "", "open_compartment");
    put_record(REGION_START, 1, 1, payload, length);
    assert_unhealthy_open();

    blank();
    length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    offset = put_record(REGION_START, 1, 1, payload, length);
    length = text(payload, "tx-1");
    length += text(payload + length, "");
    put_record(offset, 1, 4, payload, length);
    assert_unhealthy_open();

    /* No payload at all, and a completion that ends before its error code. */
    blank();
    put_record(REGION_START, 1, 1, payload, 0);
    assert_unhealthy_open();

    blank();
    length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    offset = put_record(REGION_START, 1, 1, payload, length);
    length = text(payload, "tx-1");
    length += text(payload + length, "open_compartment");
    put_record(offset, 1, 2, payload, length);
    assert_unhealthy_open();

    /* A text with a NUL inside it. */
    blank();
    length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    payload[2] = 0;
    put_record(REGION_START, 1, 1, payload, length);
    assert_unhealthy_open();
}

void test_headers_that_are_not_version_one_are_rejected(void)
{
    uint8_t payload[200];
    blank();
    size_t length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");

    put_record(REGION_START, 2, 1, payload, length);
    assert_unhealthy_open();

    blank();
    put_record(REGION_START, 1, 9, payload, length);
    assert_unhealthy_open();

    /* Longer than any record the journal writes. */
    blank();
    static uint8_t big[390];
    memset(big, 'a', sizeof(big));
    put_record(REGION_START, 1, 1, big, sizeof(big));
    assert_unhealthy_open();

}

void test_data_after_the_end_of_the_log_is_torn_nearby_and_corrupt_further_on(void)
{
    uint8_t payload[200];
    blank();
    size_t length = claim_payload(payload, "tx-1", "msg-1", "open_compartment");
    size_t offset = put_record(REGION_START, 1, 1, payload, length);

    /* Within one record of the end: what a cut write whose first byte stayed erased leaves. */
    flash.memory[offset + 100] = 0x12;
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(port.find(port.ctx, "tx-1", &entry));
    settle();
    TEST_ASSERT_TRUE(claim("tx-2", "msg-2"));

    /* The same byte where a committed record would end: never read as torn. */
    blank();
    offset = put_record(REGION_START, 1, 1, payload, length);
    flash.memory[offset + 100] = 0xC3;
    TEST_ASSERT_FALSE(reopen());

    /* Further than any record reaches: corruption. */
    blank();
    offset = put_record(REGION_START, 1, 1, payload, length);
    flash.memory[offset + 400] = 0x12;
    TEST_ASSERT_FALSE(reopen());
}

void test_a_write_cut_out_of_order_is_torn_not_corrupt(void)
{
    /* The cut write lands its last bytes; its first ones, the magic among them, stay erased. */
    for (size_t landed = 1; landed < 60; ++landed) {
        fake_flash_init(&flash, FLASH_SIZE);
        TEST_ASSERT_TRUE(reopen());
        TEST_ASSERT_TRUE(claim("tx-0", "msg-0"));
        TEST_ASSERT_TRUE(complete("tx-0"));
        flash.cut_out_of_order = true;
        fake_flash_cut_power_after(&flash, landed);
        bool claimed = claim("tx-1", "msg-1");
        fake_flash_restore_power(&flash);
        flash.cut_out_of_order = false;
        if (claimed) {
            continue;
        }

        TEST_ASSERT_TRUE_MESSAGE(reopen(), "an out-of-order cut stopped the journal");
        TEST_ASSERT_TRUE(port.find(port.ctx, "tx-0", &entry));
        TEST_ASSERT_TRUE(entry.completed);
        TEST_ASSERT_FALSE(port.find(port.ctx, "tx-1", &entry));
        settle();
        TEST_ASSERT_TRUE(claim("tx-2", "msg-2"));
        TEST_ASSERT_TRUE(reopen());
        TEST_ASSERT_TRUE(port.find(port.ctx, "tx-2", &entry));
    }
}

void test_unreadable_flash_is_unhealthy(void)
{
    static fake_flash_t small;
    fake_flash_init(&small, 8 * 1024);
    lc_flash_io_t io = fake_flash_io(&small);
    /* The partition claims to be larger than it can be read. */
    io.capacity = 16 * 1024;

    TEST_ASSERT_FALSE(lc_journal_open(&journal, io));
    TEST_ASSERT_FALSE(journal.healthy);
}

void test_a_read_error_at_start_never_looks_like_an_empty_log(void)
{
    TEST_ASSERT_TRUE(claim("tx-1", "msg-1"));
    TEST_ASSERT_TRUE(complete("tx-1"));

    /* Every read of a start fails in turn, until a start gets through without one. */
    unsigned failed = 0;
    for (unsigned nth = 1;; ++nth) {
        fake_flash_fail_read(&flash, nth);
        if (reopen()) {
            break;
        }
        ++failed;
        TEST_ASSERT_FALSE(port.healthy(port.ctx));
        /* Neither the command that already ran nor a new one. */
        TEST_ASSERT_FALSE(claim("tx-1", "msg-2"));
        TEST_ASSERT_FALSE(claim("tx-2", "msg-3"));
    }
    fake_flash_fail_read(&flash, 0);

    /* The scan for the end of the log, and then the reads of both records. */
    TEST_ASSERT_TRUE(failed > FLASH_SIZE / 2 / 64 + 4);
    TEST_ASSERT_TRUE(port.find(port.ctx, "tx-1", &entry));
    TEST_ASSERT_TRUE(entry.completed);
}

void test_room_runs_out_in_flash_before_it_runs_out_of_slots(void)
{
    /* Long ids make each transaction expensive; the regions are small. */
    fake_flash_init(&flash, 8 * 1024);
    TEST_ASSERT_TRUE(reopen());
    char tx[LC_ID_MAX + 1];
    char message[LC_ID_MAX + 1];
    memset(tx, 't', LC_ID_MAX);
    memset(message, 'm', LC_ID_MAX);
    tx[LC_ID_MAX] = '\0';
    message[LC_ID_MAX] = '\0';

    unsigned claimed = 0;
    for (unsigned i = 0; i < LC_JOURNAL_TRANSACTIONS_MAX; ++i) {
        tx[0] = (char)('A' + i % 26);
        tx[1] = (char)('a' + i / 26);
        message[0] = tx[0];
        message[1] = tx[1];
        if (!port.claim(port.ctx, tx, message, "apply_config")) {
            break;
        }
        ++claimed;
    }
    TEST_ASSERT_TRUE(claimed > 0);
    TEST_ASSERT_TRUE(claimed < LC_JOURNAL_TRANSACTIONS_MAX);

    /* Message ids may use the room that is left, but never the room kept for finishing. */
    tx[0] = 'A';
    tx[1] = 'a';
    for (unsigned i = 0; i < LC_JOURNAL_MESSAGES_MAX; ++i) {
        char extra[LC_ID_MAX + 1];
        memset(extra, 'e', LC_ID_MAX);
        extra[LC_ID_MAX] = '\0';
        extra[0] = (char)('A' + i % 26);
        extra[1] = (char)('a' + i / 26);
        if (!port.remember_message(port.ctx, tx, extra)) {
            break;
        }
    }

    /* Every accepted claim can still be finished, with the longest result. */
    for (unsigned i = 0; i < claimed; ++i) {
        lc_command_result_t result;
        memset(&result, 0, sizeof(result));
        tx[0] = (char)('A' + i % 26);
        tx[1] = (char)('a' + i / 26);
        snprintf(result.transaction_id, sizeof(result.transaction_id), "%s", tx);
        snprintf(result.action_name, sizeof(result.action_name), "apply_config");
        memset(result.applied_config_hash, 'f', 64);
        TEST_ASSERT_TRUE(port.complete(port.ctx, &result));
        TEST_ASSERT_TRUE(port.mark_delivered(port.ctx, tx));
    }
    TEST_ASSERT_TRUE(port.healthy(port.ctx));
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_EQUAL_UINT(0, port.count(port.ctx));
    TEST_ASSERT_TRUE(port.find(port.ctx, tx, &entry));
    TEST_ASSERT_TRUE(entry.delivered);
}

void test_message_ids_are_bounded(void)
{
    /* Room for every message id without a compaction. */
    fake_flash_init(&flash, 64 * 1024);
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(claim("tx-1", "msg-0"));
    unsigned remembered = 1;
    for (unsigned i = 1; i < LC_JOURNAL_MESSAGES_MAX + 10; ++i) {
        char message[32];
        snprintf(message, sizeof(message), "msg-%u", i);
        if (!port.remember_message(port.ctx, "tx-1", message)) {
            break;
        }
        ++remembered;
    }
    TEST_ASSERT_EQUAL_UINT(LC_JOURNAL_MESSAGES_MAX, remembered);

    /* A new transaction needs a slot for its message id too. */
    TEST_ASSERT_FALSE(claim("tx-2", "msg-new"));
    /* A message id that is already known is not stored twice. */
    TEST_ASSERT_TRUE(port.remember_message(port.ctx, "tx-1", "msg-0"));
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(port.seen_message(port.ctx, "msg-100"));
}

void test_arguments_the_journal_cannot_store_are_refused(void)
{
    char too_long[LC_ID_MAX + 40];
    memset(too_long, 'x', sizeof(too_long));
    too_long[sizeof(too_long) - 1] = '\0';

    TEST_ASSERT_FALSE(claim("", "msg-1"));
    TEST_ASSERT_FALSE(claim("tx-1", ""));
    TEST_ASSERT_FALSE(claim(too_long, "msg-1"));
    TEST_ASSERT_FALSE(claim("tx-1", too_long));
    TEST_ASSERT_FALSE(port.claim(port.ctx, "tx-1", "msg-1", too_long));
    /* An action name that fits a record but not the index: the next start could not read it. */
    char long_action[LC_ACTION_NAME_MAX + 2];
    memset(long_action, 'a', sizeof(long_action));
    long_action[sizeof(long_action) - 1] = '\0';
    TEST_ASSERT_FALSE(port.claim(port.ctx, "tx-1", "msg-1", long_action));
    TEST_ASSERT_EQUAL_UINT(0, flash.bytes_written);
    TEST_ASSERT_FALSE(claim("tx-1", "msg-1") && claim("tx-2", "msg-1"));

    TEST_ASSERT_TRUE(claim("tx-3", "msg-3"));
    TEST_ASSERT_FALSE(port.remember_message(port.ctx, "tx-3", ""));
    TEST_ASSERT_FALSE(port.remember_message(port.ctx, "tx-3", too_long));

    /* Completion: unknown, twice, an error code that does not exist, a delivery too early. */
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    snprintf(result.transaction_id, sizeof(result.transaction_id), "tx-unknown");
    TEST_ASSERT_FALSE(port.complete(port.ctx, &result));
    TEST_ASSERT_FALSE(port.mark_delivered(port.ctx, "tx-unknown"));
    TEST_ASSERT_FALSE(port.mark_delivered(port.ctx, "tx-3"));
    snprintf(result.transaction_id, sizeof(result.transaction_id), "tx-3");
    result.error = (lc_error_t)99;
    TEST_ASSERT_FALSE(port.complete(port.ctx, &result));
    result.error = LC_ERROR_NONE;
    TEST_ASSERT_TRUE(port.complete(port.ctx, &result));
    TEST_ASSERT_FALSE(port.complete(port.ctx, &result));
    TEST_ASSERT_TRUE(port.mark_delivered(port.ctx, "tx-3"));
    /* Delivering again is not an error and writes nothing. */
    size_t written = flash.bytes_written;
    TEST_ASSERT_TRUE(port.mark_delivered(port.ctx, "tx-3"));
    TEST_ASSERT_EQUAL_UINT(written, flash.bytes_written);
}

void test_a_failed_write_blocks_every_operation_until_restart(void)
{
    TEST_ASSERT_TRUE(claim("tx-1", "msg-1"));
    fake_flash_cut_power_after(&flash, 0);

    TEST_ASSERT_FALSE(complete("tx-1"));
    fake_flash_restore_power(&flash);

    TEST_ASSERT_FALSE(port.healthy(port.ctx));
    TEST_ASSERT_FALSE(complete("tx-1"));
    TEST_ASSERT_FALSE(port.mark_delivered(port.ctx, "tx-1"));
    TEST_ASSERT_FALSE(port.remember_message(port.ctx, "tx-1", "msg-2"));
    TEST_ASSERT_FALSE(claim("tx-2", "msg-3"));

    /* Nothing reached the flash, so the restart finds a healthy journal. */
    TEST_ASSERT_TRUE(reopen());
    TEST_ASSERT_TRUE(complete("tx-1"));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_log_goes_on_after_a_torn_claim);
    RUN_TEST(test_bytes_between_records_are_corruption);
    RUN_TEST(test_records_that_make_no_sense_mark_the_journal_unhealthy);
    RUN_TEST(test_headers_that_are_not_version_one_are_rejected);
    RUN_TEST(test_data_after_the_end_of_the_log_is_torn_nearby_and_corrupt_further_on);
    RUN_TEST(test_a_write_cut_out_of_order_is_torn_not_corrupt);
    RUN_TEST(test_unreadable_flash_is_unhealthy);
    RUN_TEST(test_a_read_error_at_start_never_looks_like_an_empty_log);
    RUN_TEST(test_room_runs_out_in_flash_before_it_runs_out_of_slots);
    RUN_TEST(test_message_ids_are_bounded);
    RUN_TEST(test_arguments_the_journal_cannot_store_are_refused);
    RUN_TEST(test_a_failed_write_blocks_every_operation_until_restart);
    return UNITY_END();
}
