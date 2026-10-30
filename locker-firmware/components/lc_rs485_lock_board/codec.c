#include "lc_rs485_lock_board/codec.h"

#define UNLOCK_HEADER 0x8A
#define QUERY_HEADER 0x80
/* Active feedback: the panel reports a door change on its own (ADR-0071). */
#define EVENT_HEADER 0x82
#define UNLOCK_COMMAND 0x11
#define QUERY_COMMAND 0x33

#define ACK_SIZE 5
#define STATUS_LOW 0x00
#define STATUS_HIGH 0x11

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

/*
 * Finds the next complete message in a burst, starting at *pos: a five-byte
 * unlock reply (8A) or door event (82), or a status reply (80 ... 33 XOR). The
 * panel may send a door event right behind a reply, with no gap (ADR-0071).
 * Bytes that start no valid message are skipped.
 */
static bool next_message(const uint8_t *bytes, size_t length, size_t *pos, const uint8_t **message,
                         size_t *message_length)
{
    while (*pos < length) {
        const uint8_t *start = bytes + *pos;
        size_t left = length - *pos;

        if ((start[0] == UNLOCK_HEADER || start[0] == EVENT_HEADER) && left >= ACK_SIZE &&
            start[ACK_SIZE - 1] == xor_checksum(start, ACK_SIZE - 1)) {
            *message = start;
            *message_length = ACK_SIZE;
            *pos += ACK_SIZE;
            return true;
        }
        if (start[0] == QUERY_HEADER) {
            for (size_t end = QUERY_RESPONSE_MIN; end <= left; ++end) {
                if (start[end - 2] == QUERY_COMMAND &&
                    start[end - 1] == xor_checksum(start, end - 1)) {
                    *message = start;
                    *message_length = end;
                    *pos += end;
                    return true;
                }
            }
        }
        ++*pos;
    }
    return false;
}

lc_rs485_unlock_answer_t lc_rs485_decode_unlock_ack(const uint8_t *frame, size_t length,
                                                    uint8_t board, unsigned address,
                                                    lc_feedback_t feedback)
{
    const uint8_t *message;
    size_t message_length;
    size_t pos = 0;
    uint8_t succeeded = feedback == LC_FEEDBACK_DOOR_CLOSING ? STATUS_LOW : STATUS_HIGH;
    uint8_t failed = feedback == LC_FEEDBACK_DOOR_CLOSING ? STATUS_HIGH : STATUS_LOW;

    if (frame == NULL || address > LC_COMPARTMENT_ADDRESS_MAX) {
        return LC_RS485_UNLOCK_NO_ANSWER;
    }
    while (next_message(frame, length, &pos, &message, &message_length)) {
        if (message[0] != UNLOCK_HEADER || message[1] != board || message[2] != address + 1) {
            continue;
        }
        if (message[3] == succeeded) {
            return LC_RS485_UNLOCK_SUCCEEDED;
        }
        if (message[3] == failed) {
            return LC_RS485_UNLOCK_FAILED;
        }
    }
    return LC_RS485_UNLOCK_NO_ANSWER;
}

/* The status reply for this board among the messages of a burst; door events are skipped. */
static bool find_query_all_reply(const uint8_t *bytes, size_t length, uint8_t board,
                                 const uint8_t **frame, size_t *frame_length)
{
    size_t pos = 0;

    if (bytes == NULL) {
        return false;
    }
    while (next_message(bytes, length, &pos, frame, frame_length)) {
        if ((*frame)[0] == QUERY_HEADER && (*frame)[1] == board) {
            return true;
        }
    }
    return false;
}

bool lc_rs485_decode_query_all(const uint8_t *bytes, size_t received, uint8_t board,
                               lc_feedback_t feedback,
                               lc_door_t states[LC_RS485_ADDRESS_COUNT])
{
    const uint8_t *frame;
    size_t length;

    for (size_t address = 0; address < LC_RS485_ADDRESS_COUNT; ++address) {
        states[address] = LC_DOOR_UNKNOWN;
    }
    if (!find_query_all_reply(bytes, received, board, &frame, &length)) {
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
