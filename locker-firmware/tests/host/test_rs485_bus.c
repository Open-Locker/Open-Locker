#include <string.h>

#include "fakes/fake_serial.h"
#include "lc_rs485_lock_board/bus.h"
#include "unity.h"

#define DEADLINE_MS 1000
#define QUIET_MS 25
#define SETTLE_MS 1500
#define FAILURES_BEFORE_COOLDOWN 3
#define COOLDOWN_MS 60000
/* A normal reply time, well inside the deadline. */
#define REPLY_MS 500

static const lc_rs485_bus_config_t CONFIG = {
    .reply_deadline_ms = DEADLINE_MS,
    .quiet_interval_ms = QUIET_MS,
    .settle_period_ms = SETTLE_MS,
    .failures_before_cooldown = FAILURES_BEFORE_COOLDOWN,
    .cooldown_ms = COOLDOWN_MS,
};

static const lc_target_t TARGET = {.compartment_number = 1, .board = 1, .address = 0};

static const uint8_t UNLOCK_REQUEST[] = {0x8A, 0x01, 0x01, 0x11, 0x9B};
static const uint8_t UNLOCK_ACK[] = {0x8A, 0x01, 0x01, 0x00, 0x8A};
static const uint8_t UNLOCK_ACK_BAD_CHECKSUM[] = {0x8A, 0x01, 0x01, 0x00, 0x8B};
/* Addresses 0 and 2 high: closed under door_closing feedback. */
static const uint8_t STATUS_0_AND_2_HIGH[] = {0x80, 0x01, 0x00, 0x00, 0x05, 0x33, 0xB7};

static const uint8_t ADDRESSES[] = {0, 1, 2};
#define ADDRESS_COUNT (sizeof(ADDRESSES) / sizeof(ADDRESSES[0]))

static fake_serial_t serial;
static lc_rs485_bus_t bus;
static lc_locker_bus_port_t port;
static lc_door_t doors[ADDRESS_COUNT];

void setUp(void)
{
    const lc_hardware_profile_t profile = {.feedback = LC_FEEDBACK_DOOR_CLOSING};

    fake_serial_init(&serial);
    lc_rs485_bus_init(&bus, fake_serial_port(&serial), fake_serial_clock(&serial), &CONFIG);
    port = lc_rs485_bus_port(&bus);
    TEST_ASSERT_TRUE(port.apply_profile(port.ctx, &profile));
}

void tearDown(void)
{
}

static lc_unlock_result_t unlock(void)
{
    return port.unlock_compartment(port.ctx, TARGET).result;
}

/* Pre-fills with a known state so an untouched output cannot pass as unknown. */
static bool read_doors(void)
{
    for (size_t i = 0; i < ADDRESS_COUNT; ++i) {
        doors[i] = LC_DOOR_CLOSED;
    }
    return port.read_compartment_states(port.ctx, TARGET.board, ADDRESSES, ADDRESS_COUNT, doors);
}

static void assert_all_doors_unknown(void)
{
    for (size_t i = 0; i < ADDRESS_COUNT; ++i) {
        TEST_ASSERT_EQUAL_INT(LC_DOOR_UNKNOWN, doors[i]);
    }
}

static void fail_reads(unsigned count)
{
    for (unsigned i = 0; i < count; ++i) {
        TEST_ASSERT_FALSE(read_doors());
    }
}

static int64_t gap_between_writes(size_t earlier, size_t later)
{
    return fake_serial_write_time(&serial, later) - fake_serial_write_time(&serial, earlier);
}

void test_S1_H12_starting_the_adapter_writes_nothing(void)
{
    TEST_ASSERT_EQUAL_UINT(0, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_UINT32(0, lc_rs485_bus_unlock_transmissions(&bus));
    TEST_ASSERT_EQUAL_INT(LC_BUS_DISCONNECTED, port.connection_state(port.ctx));
}

void test_S1_H13_acknowledged_unlock_after_exactly_one_write(void)
{
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, UNLOCK_ACK, sizeof(UNLOCK_ACK));

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_ACKNOWLEDGED, unlock());

    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_UINT(sizeof(UNLOCK_REQUEST), fake_serial_write_at(&serial, 0)->length);
    TEST_ASSERT_EQUAL_HEX8_ARRAY(UNLOCK_REQUEST, fake_serial_write_at(&serial, 0)->data,
                                 sizeof(UNLOCK_REQUEST));
    TEST_ASSERT_EQUAL_UINT32(1, lc_rs485_bus_unlock_transmissions(&bus));
    TEST_ASSERT_EQUAL_INT(LC_BUS_CONNECTED, port.connection_state(port.ctx));
}

void test_S1_H14_write_of_zero_bytes_is_not_sent(void)
{
    fake_serial_set_next_write_result(&serial, 0);

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_NOT_SENT, unlock());

    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_UINT32(0, lc_rs485_bus_unlock_transmissions(&bus));
}

void test_S1_H15_partial_write_is_outcome_unknown(void)
{
    fake_serial_set_next_write_result(&serial, 3);
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, UNLOCK_ACK, sizeof(UNLOCK_ACK));

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_UINT32(1, lc_rs485_bus_unlock_transmissions(&bus));
}

void test_S1_H16_no_reply_before_deadline_is_outcome_unknown_after_one_write(void)
{
    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_UINT32(1, lc_rs485_bus_unlock_transmissions(&bus));
}

void test_S1_H17_bad_checksum_reply_is_outcome_unknown_after_one_write(void)
{
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, UNLOCK_ACK_BAD_CHECKSUM,
                               sizeof(UNLOCK_ACK_BAD_CHECKSUM));

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
}

void test_S1_H18_bytes_already_received_cannot_acknowledge_the_next_request(void)
{
    /* A complete, valid acknowledgement is sitting in the receive buffer. */
    fake_serial_receive_now(&serial, UNLOCK_ACK, sizeof(UNLOCK_ACK));

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
}

void test_S1_H19_frame_ends_only_after_the_quiet_interval(void)
{
    /* Split by less than the quiet interval: one frame. */
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, UNLOCK_ACK, 3);
    fake_serial_reply_to_write(&serial, 0, REPLY_MS + QUIET_MS / 2, UNLOCK_ACK + 3, 2);

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_ACKNOWLEDGED, unlock());

    /* Split by more than the quiet interval: the first part is a frame of its own. */
    setUp();
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, UNLOCK_ACK, 3);
    fake_serial_reply_to_write(&serial, 0, REPLY_MS + 4 * QUIET_MS, UNLOCK_ACK + 3, 2);

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());
}

void test_S1_H20_reply_longer_than_the_frame_buffer_is_rejected(void)
{
    uint8_t oversized[100];
    memset(oversized, 0x8A, sizeof(oversized));

    fake_serial_reply_to_write(&serial, 0, REPLY_MS, oversized, sizeof(oversized));
    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    setUp();
    memset(oversized, 0x80, sizeof(oversized));
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, oversized, sizeof(oversized));
    TEST_ASSERT_FALSE(read_doors());
    assert_all_doors_unknown();
}

void test_S1_H21_failed_read_reports_unknown_and_not_connected(void)
{
    TEST_ASSERT_FALSE(read_doors());

    assert_all_doors_unknown();
    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
    TEST_ASSERT_TRUE(port.connection_state(port.ctx) != LC_BUS_CONNECTED);
}

void test_S1_H22_repeated_failures_start_a_cooldown_without_writes(void)
{
    fail_reads(FAILURES_BEFORE_COOLDOWN);
    TEST_ASSERT_EQUAL_UINT(FAILURES_BEFORE_COOLDOWN, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_INT(LC_BUS_UNREACHABLE, port.connection_state(port.ctx));

    TEST_ASSERT_FALSE(read_doors());
    fake_serial_advance(&serial, COOLDOWN_MS / 2);
    TEST_ASSERT_FALSE(read_doors());

    assert_all_doors_unknown();
    TEST_ASSERT_EQUAL_UINT(FAILURES_BEFORE_COOLDOWN, fake_serial_write_count(&serial));
}

void test_S1_H23_unlock_during_cooldown_is_not_sent(void)
{
    fail_reads(FAILURES_BEFORE_COOLDOWN);

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_NOT_SENT, unlock());

    TEST_ASSERT_EQUAL_UINT(FAILURES_BEFORE_COOLDOWN, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_UINT32(0, lc_rs485_bus_unlock_transmissions(&bus));
}

void test_S1_H24_first_success_after_cooldown_reports_connected(void)
{
    fail_reads(FAILURES_BEFORE_COOLDOWN);
    fake_serial_advance(&serial, COOLDOWN_MS + 1);
    fake_serial_reply_to_write(&serial, FAILURES_BEFORE_COOLDOWN, REPLY_MS, STATUS_0_AND_2_HIGH,
                               sizeof(STATUS_0_AND_2_HIGH));

    TEST_ASSERT_TRUE(read_doors());

    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, doors[0]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, doors[1]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, doors[2]);
    TEST_ASSERT_EQUAL_INT(LC_BUS_CONNECTED, port.connection_state(port.ctx));
}

void test_S1_H27_after_a_timeout_the_bus_stays_silent_for_the_settle_period(void)
{
    const uint8_t noise[] = {0xFF, 0x00, 0x55};

    /* First query: no reply, but noise arrives inside the settle period. */
    fake_serial_reply_to_write(&serial, 0, DEADLINE_MS + 200, noise, sizeof(noise));
    TEST_ASSERT_FALSE(read_doors());

    fake_serial_reply_to_write(&serial, 1, REPLY_MS, STATUS_0_AND_2_HIGH,
                               sizeof(STATUS_0_AND_2_HIGH));
    TEST_ASSERT_TRUE(read_doors());

    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, doors[0]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, doors[1]);
    TEST_ASSERT_EQUAL_UINT(2, fake_serial_write_count(&serial));
    TEST_ASSERT_TRUE_MESSAGE(gap_between_writes(0, 1) >= DEADLINE_MS + SETTLE_MS,
                             "second request was written inside the settle period");
}

void test_S1_H28_late_ack_of_a_timed_out_unlock_does_not_acknowledge_the_next(void)
{
    /* Request A gets its acknowledgement only after its deadline has passed. */
    fake_serial_reply_to_write(&serial, 0, DEADLINE_MS + 100, UNLOCK_ACK, sizeof(UNLOCK_ACK));
    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    /* Request B to the same channel gets no reply of its own. */
    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    TEST_ASSERT_EQUAL_UINT(2, fake_serial_write_count(&serial));
}

void test_S1_H29_reply_already_waiting_when_the_request_was_written_is_outcome_unknown(void)
{
    /* Zero delay: it arrived while the request was still being transmitted. */
    fake_serial_reply_to_write(&serial, 0, 0, UNLOCK_ACK, sizeof(UNLOCK_ACK));

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
}

void test_S1_H30_unlock_during_the_settle_period_waits_and_is_sent_once(void)
{
    TEST_ASSERT_FALSE(read_doors());
    fake_serial_reply_to_write(&serial, 1, REPLY_MS, UNLOCK_ACK, sizeof(UNLOCK_ACK));

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_ACKNOWLEDGED, unlock());

    TEST_ASSERT_EQUAL_UINT(2, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_UINT32(1, lc_rs485_bus_unlock_transmissions(&bus));
    TEST_ASSERT_TRUE_MESSAGE(gap_between_writes(0, 1) >= DEADLINE_MS + SETTLE_MS,
                             "unlock was written inside the settle period");
}

void test_S1_H31_late_status_reply_is_not_the_answer_to_the_next_query(void)
{
    /* Query A is answered only after its deadline has passed. */
    fake_serial_reply_to_write(&serial, 0, DEADLINE_MS + 100, STATUS_0_AND_2_HIGH,
                               sizeof(STATUS_0_AND_2_HIGH));
    TEST_ASSERT_FALSE(read_doors());

    /* Query B gets no reply of its own. */
    TEST_ASSERT_FALSE(read_doors());

    assert_all_doors_unknown();
    TEST_ASSERT_EQUAL_UINT(2, fake_serial_write_count(&serial));
}

void test_invalid_target_is_not_sent_and_writes_nothing(void)
{
    const lc_target_t board_0 = {.compartment_number = 1, .board = 0, .address = 0};
    const lc_target_t address_255 = {.compartment_number = 1, .board = 1, .address = 255};

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_NOT_SENT, port.unlock_compartment(port.ctx, board_0).result);
    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_NOT_SENT,
                          port.unlock_compartment(port.ctx, address_255).result);
    doors[0] = LC_DOOR_CLOSED;
    TEST_ASSERT_FALSE(port.read_compartment_states(port.ctx, 32, ADDRESSES, 1, doors));

    TEST_ASSERT_EQUAL_INT(LC_DOOR_UNKNOWN, doors[0]);
    TEST_ASSERT_EQUAL_UINT(0, fake_serial_write_count(&serial));
}

void test_unlock_acknowledgement_is_not_an_answer_to_a_query(void)
{
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, UNLOCK_ACK, sizeof(UNLOCK_ACK));

    TEST_ASSERT_FALSE(read_doors());

    assert_all_doors_unknown();
    TEST_ASSERT_TRUE(port.connection_state(port.ctx) != LC_BUS_CONNECTED);
}

void test_reply_still_arriving_at_the_deadline_is_outcome_unknown(void)
{
    fake_serial_reply_to_write(&serial, 0, DEADLINE_MS - QUIET_MS / 2, UNLOCK_ACK, 3);
    fake_serial_reply_to_write(&serial, 0, DEADLINE_MS, UNLOCK_ACK + 3, 2);

    TEST_ASSERT_EQUAL_INT(LC_UNLOCK_OUTCOME_UNKNOWN, unlock());

    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
}

void test_unsupported_feedback_profile_is_refused(void)
{
    const lc_hardware_profile_t profile = {.feedback = (lc_feedback_t)7};

    TEST_ASSERT_FALSE(port.apply_profile(port.ctx, &profile));
    TEST_ASSERT_FALSE(port.apply_profile(port.ctx, NULL));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S1_H12_starting_the_adapter_writes_nothing);
    RUN_TEST(test_S1_H13_acknowledged_unlock_after_exactly_one_write);
    RUN_TEST(test_S1_H14_write_of_zero_bytes_is_not_sent);
    RUN_TEST(test_S1_H15_partial_write_is_outcome_unknown);
    RUN_TEST(test_S1_H16_no_reply_before_deadline_is_outcome_unknown_after_one_write);
    RUN_TEST(test_S1_H17_bad_checksum_reply_is_outcome_unknown_after_one_write);
    RUN_TEST(test_S1_H18_bytes_already_received_cannot_acknowledge_the_next_request);
    RUN_TEST(test_S1_H19_frame_ends_only_after_the_quiet_interval);
    RUN_TEST(test_S1_H20_reply_longer_than_the_frame_buffer_is_rejected);
    RUN_TEST(test_S1_H21_failed_read_reports_unknown_and_not_connected);
    RUN_TEST(test_S1_H22_repeated_failures_start_a_cooldown_without_writes);
    RUN_TEST(test_S1_H23_unlock_during_cooldown_is_not_sent);
    RUN_TEST(test_S1_H24_first_success_after_cooldown_reports_connected);
    RUN_TEST(test_S1_H27_after_a_timeout_the_bus_stays_silent_for_the_settle_period);
    RUN_TEST(test_S1_H28_late_ack_of_a_timed_out_unlock_does_not_acknowledge_the_next);
    RUN_TEST(test_S1_H29_reply_already_waiting_when_the_request_was_written_is_outcome_unknown);
    RUN_TEST(test_S1_H30_unlock_during_the_settle_period_waits_and_is_sent_once);
    RUN_TEST(test_S1_H31_late_status_reply_is_not_the_answer_to_the_next_query);
    RUN_TEST(test_invalid_target_is_not_sent_and_writes_nothing);
    RUN_TEST(test_unlock_acknowledgement_is_not_an_answer_to_a_query);
    RUN_TEST(test_reply_still_arriving_at_the_deadline_is_outcome_unknown);
    RUN_TEST(test_unsupported_feedback_profile_is_refused);
    return UNITY_END();
}
