#include <string.h>

#include "lc_rs485_lock_board/codec.h"
#include "unity.h"

/* Captured from the verified 8-channel board at DIP address 1 (ADR-0061). */
static const uint8_t UNLOCK_BOARD_1_ADDRESS_0[] = {0x8A, 0x01, 0x01, 0x11, 0x9B};
static const uint8_t QUERY_ALL_BOARD_1[] = {0x80, 0x01, 0x00, 0x33, 0xB2};
static const uint8_t VERIFIED_QUERY_ALL_RESPONSE[] = {0x80, 0x01, 0x00, 0x00, 0x00, 0x33, 0xB2};

static lc_door_t states[LC_RS485_ADDRESS_COUNT];

void setUp(void)
{
    for (size_t i = 0; i < LC_RS485_ADDRESS_COUNT; ++i) {
        states[i] = LC_DOOR_UNKNOWN;
    }
}

void tearDown(void)
{
}

static bool decode(const uint8_t *frame, size_t length, uint8_t board, lc_feedback_t feedback)
{
    return lc_rs485_decode_query_all(frame, length, board, feedback, states);
}

void test_S1_H01_unlock_board_1_address_0_encodes_golden_frame(void)
{
    uint8_t frame[LC_RS485_REQUEST_SIZE] = {0};

    TEST_ASSERT_TRUE(lc_rs485_encode_unlock(1, 0, frame));

    TEST_ASSERT_EQUAL_HEX8_ARRAY(UNLOCK_BOARD_1_ADDRESS_0, frame, LC_RS485_REQUEST_SIZE);
}

void test_S1_H02_query_all_board_1_encodes_golden_frame(void)
{
    uint8_t frame[LC_RS485_REQUEST_SIZE] = {0};

    TEST_ASSERT_TRUE(lc_rs485_encode_query_all(1, frame));

    TEST_ASSERT_EQUAL_HEX8_ARRAY(QUERY_ALL_BOARD_1, frame, LC_RS485_REQUEST_SIZE);
}

void test_S1_H03_board_addresses_0_and_32_are_rejected(void)
{
    uint8_t frame[LC_RS485_REQUEST_SIZE] = {0};

    TEST_ASSERT_FALSE(lc_rs485_encode_unlock(0, 0, frame));
    TEST_ASSERT_FALSE(lc_rs485_encode_unlock(32, 0, frame));
    TEST_ASSERT_FALSE(lc_rs485_encode_query_all(0, frame));
    TEST_ASSERT_FALSE(lc_rs485_encode_query_all(32, frame));

    TEST_ASSERT_TRUE(lc_rs485_encode_unlock(31, 0, frame));
    TEST_ASSERT_TRUE(lc_rs485_encode_query_all(31, frame));
}

void test_S1_H04_address_255_is_rejected_and_wire_channel_zero_is_never_produced(void)
{
    uint8_t frame[LC_RS485_REQUEST_SIZE] = {0};

    TEST_ASSERT_FALSE(lc_rs485_encode_unlock(1, 255, frame));
    TEST_ASSERT_TRUE(lc_rs485_encode_unlock(1, 254, frame));
    TEST_ASSERT_EQUAL_HEX8(0xFF, frame[2]);

    for (unsigned board = 0; board <= 40; ++board) {
        for (unsigned address = 0; address <= 300; ++address) {
            uint8_t candidate[LC_RS485_REQUEST_SIZE] = {0};
            if (lc_rs485_encode_unlock((uint8_t)board, address, candidate)) {
                TEST_ASSERT_TRUE_MESSAGE(candidate[2] != 0x00, "wire channel zero opens all locks");
            }
        }
    }
}

#define CLOSING LC_FEEDBACK_DOOR_CLOSING
#define OPENING LC_FEEDBACK_DOOR_OPENING

/* Seller protocol (ADR-0071): the status byte means success or failure by lock type. */
void test_S1_H05_unlock_status_00_and_11_mean_success_or_failure_by_feedback_type(void)
{
    const uint8_t status_00[] = {0x8A, 0x01, 0x01, 0x00, 0x8A};
    const uint8_t status_11[] = {0x8A, 0x01, 0x01, 0x11, 0x9B};

    TEST_ASSERT_EQUAL_INT(LC_RS485_UNLOCK_SUCCEEDED,
                          lc_rs485_decode_unlock_ack(status_00, sizeof(status_00), 1, 0, CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_RS485_UNLOCK_FAILED,
                          lc_rs485_decode_unlock_ack(status_11, sizeof(status_11), 1, 0, CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_RS485_UNLOCK_SUCCEEDED,
                          lc_rs485_decode_unlock_ack(status_11, sizeof(status_11), 1, 0, OPENING));
    TEST_ASSERT_EQUAL_INT(LC_RS485_UNLOCK_FAILED,
                          lc_rs485_decode_unlock_ack(status_00, sizeof(status_00), 1, 0, OPENING));
}

void test_S1_H06_unlock_ack_with_another_status_is_rejected(void)
{
    const uint8_t status_01[] = {0x8A, 0x01, 0x01, 0x01, 0x8B};
    const uint8_t status_ff[] = {0x8A, 0x01, 0x01, 0xFF, 0x75};

    TEST_ASSERT_EQUAL_INT(LC_RS485_UNLOCK_NO_ANSWER,
                          lc_rs485_decode_unlock_ack(status_01, sizeof(status_01), 1, 0, CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_RS485_UNLOCK_NO_ANSWER,
                          lc_rs485_decode_unlock_ack(status_ff, sizeof(status_ff), 1, 0, CLOSING));
}

void test_S1_H07_unlock_ack_for_another_board_or_channel_is_rejected(void)
{
    const uint8_t board_2[] = {0x8A, 0x02, 0x01, 0x00, 0x89};
    const uint8_t channel_2[] = {0x8A, 0x01, 0x02, 0x00, 0x89};
    const uint8_t bad_checksum[] = {0x8A, 0x01, 0x01, 0x00, 0x8B};
    const uint8_t query_header[] = {0x80, 0x01, 0x01, 0x00, 0x80};
    const lc_rs485_unlock_answer_t none = LC_RS485_UNLOCK_NO_ANSWER;

    TEST_ASSERT_EQUAL_INT(none, lc_rs485_decode_unlock_ack(board_2, sizeof(board_2), 1, 0, CLOSING));
    TEST_ASSERT_EQUAL_INT(none,
                          lc_rs485_decode_unlock_ack(channel_2, sizeof(channel_2), 1, 0, CLOSING));
    TEST_ASSERT_EQUAL_INT(
        none, lc_rs485_decode_unlock_ack(bad_checksum, sizeof(bad_checksum), 1, 0, CLOSING));
    TEST_ASSERT_EQUAL_INT(
        none, lc_rs485_decode_unlock_ack(query_header, sizeof(query_header), 1, 0, CLOSING));
    TEST_ASSERT_EQUAL_INT(none, lc_rs485_decode_unlock_ack(board_2, 4, 2, 0, CLOSING));

    TEST_ASSERT_EQUAL_INT(LC_RS485_UNLOCK_SUCCEEDED,
                          lc_rs485_decode_unlock_ack(board_2, sizeof(board_2), 2, 0, CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_RS485_UNLOCK_SUCCEEDED,
                          lc_rs485_decode_unlock_ack(channel_2, sizeof(channel_2), 1, 1, CLOSING));
}

void test_S1_H08_verified_query_all_response_decodes(void)
{
    TEST_ASSERT_TRUE(decode(VERIFIED_QUERY_ALL_RESPONSE, sizeof(VERIFIED_QUERY_ALL_RESPONSE), 1,
                            LC_FEEDBACK_DOOR_CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[0]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[23]);

    TEST_ASSERT_TRUE(decode(VERIFIED_QUERY_ALL_RESPONSE, sizeof(VERIFIED_QUERY_ALL_RESPONSE), 1,
                            LC_FEEDBACK_DOOR_OPENING));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, states[0]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, states[23]);
}

void test_S1_H09_status_bits_map_to_addresses_for_both_polarities(void)
{
    /* Last status byte 0x05: addresses 0 and 2 high. */
    const uint8_t low_group[] = {0x80, 0x01, 0x00, 0x00, 0x05, 0x33, 0xB7};
    /* First status byte 0x01: address 16 high. */
    const uint8_t high_group[] = {0x80, 0x01, 0x01, 0x00, 0x00, 0x33, 0xB3};

    TEST_ASSERT_TRUE(decode(low_group, sizeof(low_group), 1, LC_FEEDBACK_DOOR_CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, states[0]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[1]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, states[2]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[3]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[8]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[16]);

    TEST_ASSERT_TRUE(decode(low_group, sizeof(low_group), 1, LC_FEEDBACK_DOOR_OPENING));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[0]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, states[1]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[2]);

    TEST_ASSERT_TRUE(decode(high_group, sizeof(high_group), 1, LC_FEEDBACK_DOOR_CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[0]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[15]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, states[16]);
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[17]);
}

void test_S1_H10_addresses_beyond_the_status_bytes_are_unknown(void)
{
    /* One status byte: only addresses 0..7 are covered. */
    const uint8_t one_status_byte[] = {0x80, 0x01, 0xFF, 0x33, 0x4D};

    TEST_ASSERT_TRUE(decode(VERIFIED_QUERY_ALL_RESPONSE, sizeof(VERIFIED_QUERY_ALL_RESPONSE), 1,
                            LC_FEEDBACK_DOOR_CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_OPEN, states[23]);
    for (size_t address = 24; address < LC_RS485_ADDRESS_COUNT; ++address) {
        TEST_ASSERT_EQUAL_INT(LC_DOOR_UNKNOWN, states[address]);
    }

    TEST_ASSERT_TRUE(decode(one_status_byte, sizeof(one_status_byte), 1, LC_FEEDBACK_DOOR_CLOSING));
    TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, states[7]);
    for (size_t address = 8; address < LC_RS485_ADDRESS_COUNT; ++address) {
        TEST_ASSERT_EQUAL_INT(LC_DOOR_UNKNOWN, states[address]);
    }
}

void test_status_bits_beyond_the_last_address_are_ignored(void)
{
    /* 32 status bytes carry 256 bits, one more than there are addresses. */
    uint8_t frame[2 + 32 + 2] = {0x80, 0x01};
    memset(frame + 2, 0xFF, 32);
    frame[34] = 0x33;
    for (size_t i = 0; i < 35; ++i) {
        frame[35] ^= frame[i];
    }

    TEST_ASSERT_TRUE(decode(frame, sizeof(frame), 1, LC_FEEDBACK_DOOR_CLOSING));

    for (size_t address = 0; address < LC_RS485_ADDRESS_COUNT; ++address) {
        TEST_ASSERT_EQUAL_INT(LC_DOOR_CLOSED, states[address]);
    }
}

static void assert_rejected(const uint8_t *frame, size_t length, uint8_t board)
{
    for (size_t i = 0; i < LC_RS485_ADDRESS_COUNT; ++i) {
        states[i] = LC_DOOR_CLOSED;
    }

    TEST_ASSERT_FALSE(decode(frame, length, board, LC_FEEDBACK_DOOR_CLOSING));

    for (size_t i = 0; i < LC_RS485_ADDRESS_COUNT; ++i) {
        TEST_ASSERT_EQUAL_INT(LC_DOOR_UNKNOWN, states[i]);
    }
}

void test_S1_H11_malformed_query_all_responses_are_rejected(void)
{
    const uint8_t bad_checksum[] = {0x80, 0x01, 0x00, 0x00, 0x00, 0x33, 0xB3};
    const uint8_t wrong_header[] = {0x81, 0x01, 0x00, 0x00, 0x00, 0x33, 0xB3};
    const uint8_t wrong_marker[] = {0x80, 0x01, 0x00, 0x00, 0x00, 0x34, 0xB5};
    const uint8_t four_bytes[] = {0x80, 0x01, 0x33, 0xB2};

    assert_rejected(bad_checksum, sizeof(bad_checksum), 1);
    assert_rejected(wrong_header, sizeof(wrong_header), 1);
    assert_rejected(wrong_marker, sizeof(wrong_marker), 1);
    assert_rejected(four_bytes, sizeof(four_bytes), 1);
    /* Valid frame, but from board 1 while board 2 was asked. */
    assert_rejected(VERIFIED_QUERY_ALL_RESPONSE, sizeof(VERIFIED_QUERY_ALL_RESPONSE), 2);
    assert_rejected(VERIFIED_QUERY_ALL_RESPONSE, 0, 1);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S1_H01_unlock_board_1_address_0_encodes_golden_frame);
    RUN_TEST(test_S1_H02_query_all_board_1_encodes_golden_frame);
    RUN_TEST(test_S1_H03_board_addresses_0_and_32_are_rejected);
    RUN_TEST(test_S1_H04_address_255_is_rejected_and_wire_channel_zero_is_never_produced);
    RUN_TEST(test_S1_H05_unlock_status_00_and_11_mean_success_or_failure_by_feedback_type);
    RUN_TEST(test_S1_H06_unlock_ack_with_another_status_is_rejected);
    RUN_TEST(test_S1_H07_unlock_ack_for_another_board_or_channel_is_rejected);
    RUN_TEST(test_S1_H08_verified_query_all_response_decodes);
    RUN_TEST(test_S1_H09_status_bits_map_to_addresses_for_both_polarities);
    RUN_TEST(test_S1_H10_addresses_beyond_the_status_bytes_are_unknown);
    RUN_TEST(test_status_bits_beyond_the_last_address_are_ignored);
    RUN_TEST(test_S1_H11_malformed_query_all_responses_are_rejected);
    return UNITY_END();
}
