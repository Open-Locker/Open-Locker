#include "lc_rs485_lock_board/codec.h"

#define UNLOCK_HEADER 0x8A
#define QUERY_HEADER 0x80
#define UNLOCK_COMMAND 0x11
#define QUERY_COMMAND 0x33

#define ACK_SIZE 5
#define ACK_STATUS_A 0x00
#define ACK_STATUS_B 0x11

/* Header, board, at least one status byte, command marker, checksum. */
#define QUERY_RESPONSE_MIN 5
#define QUERY_RESPONSE_OVERHEAD 4

static uint8_t xor_checksum(const uint8_t *bytes, size_t length)
{
    uint8_t checksum = 0;
    for (size_t i = 0; i < length; ++i) {
        checksum ^= bytes[i];
    }
    return checksum;
}

static bool board_is_valid(uint8_t board)
{
    return board >= LC_BOARD_ADDRESS_MIN && board <= LC_BOARD_ADDRESS_MAX;
}

static void encode(uint8_t header, uint8_t board, uint8_t channel, uint8_t command,
                   uint8_t out[LC_RS485_REQUEST_SIZE])
{
    out[0] = header;
    out[1] = board;
    out[2] = channel;
    out[3] = command;
    out[4] = xor_checksum(out, LC_RS485_REQUEST_SIZE - 1);
}

bool lc_rs485_encode_unlock(uint8_t board, unsigned address, uint8_t out[LC_RS485_REQUEST_SIZE])
{
    if (!board_is_valid(board) || address > LC_COMPARTMENT_ADDRESS_MAX) {
        return false;
    }
    /* Wire channels are one-based; channel zero would open every lock. */
    encode(UNLOCK_HEADER, board, (uint8_t)(address + 1), UNLOCK_COMMAND, out);
    return true;
}

bool lc_rs485_encode_query_all(uint8_t board, uint8_t out[LC_RS485_REQUEST_SIZE])
{
    if (!board_is_valid(board)) {
        return false;
    }
    encode(QUERY_HEADER, board, 0x00, QUERY_COMMAND, out);
    return true;
}

bool lc_rs485_decode_unlock_ack(const uint8_t *frame, size_t length, uint8_t board,
                                unsigned address)
{
    if (frame == NULL || length != ACK_SIZE || address > LC_COMPARTMENT_ADDRESS_MAX) {
        return false;
    }
    if (frame[0] != UNLOCK_HEADER || frame[1] != board || frame[2] != address + 1) {
        return false;
    }
    if (frame[3] != ACK_STATUS_A && frame[3] != ACK_STATUS_B) {
        return false;
    }
    return frame[4] == xor_checksum(frame, ACK_SIZE - 1);
}

static bool query_all_is_well_formed(const uint8_t *frame, size_t length, uint8_t board)
{
    if (frame == NULL || length < QUERY_RESPONSE_MIN) {
        return false;
    }
    if (frame[0] != QUERY_HEADER || frame[1] != board || frame[length - 2] != QUERY_COMMAND) {
        return false;
    }
    return frame[length - 1] == xor_checksum(frame, length - 1);
}

bool lc_rs485_decode_query_all(const uint8_t *frame, size_t length, uint8_t board,
                               lc_feedback_t feedback,
                               lc_door_t states[LC_RS485_ADDRESS_COUNT])
{
    for (size_t address = 0; address < LC_RS485_ADDRESS_COUNT; ++address) {
        states[address] = LC_DOOR_UNKNOWN;
    }
    if (!query_all_is_well_formed(frame, length, board)) {
        return false;
    }

    size_t status_bytes = length - QUERY_RESPONSE_OVERHEAD;
    for (size_t group = 0; group < status_bytes; ++group) {
        /* The last status byte is group 0. */
        uint8_t status = frame[2 + (status_bytes - 1 - group)];
        for (unsigned bit = 0; bit < 8; ++bit) {
            size_t address = group * 8 + bit;
            if (address >= LC_RS485_ADDRESS_COUNT) {
                return true;
            }
            bool high = (status & (1u << bit)) != 0;
            bool closed = feedback == LC_FEEDBACK_DOOR_CLOSING ? high : !high;
            states[address] = closed ? LC_DOOR_CLOSED : LC_DOOR_OPEN;
        }
    }
    return true;
}
