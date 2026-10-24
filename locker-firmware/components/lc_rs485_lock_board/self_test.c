#include <string.h>

#include "lc_rs485_lock_board/codec.h"

/* Frames captured from the verified 8-channel board at DIP address 1 (ADR-0061). */
static const uint8_t UNLOCK_BOARD_1_ADDRESS_0[] = {0x8A, 0x01, 0x01, 0x11, 0x9B};
static const uint8_t QUERY_ALL_BOARD_1[] = {0x80, 0x01, 0x00, 0x33, 0xB2};
static const uint8_t QUERY_ALL_RESPONSE[] = {0x80, 0x01, 0x00, 0x00, 0x00, 0x33, 0xB2};

bool lc_rs485_codec_self_test(void)
{
    uint8_t request[LC_RS485_REQUEST_SIZE];
    lc_door_t states[LC_RS485_ADDRESS_COUNT];

    if (!lc_rs485_encode_unlock(1, 0, request) ||
        memcmp(request, UNLOCK_BOARD_1_ADDRESS_0, sizeof(request)) != 0) {
        return false;
    }
    if (!lc_rs485_encode_query_all(1, request) ||
        memcmp(request, QUERY_ALL_BOARD_1, sizeof(request)) != 0) {
        return false;
    }
    if (lc_rs485_encode_unlock(1, LC_COMPARTMENT_ADDRESS_MAX + 1, request)) {
        return false;
    }
    return lc_rs485_decode_query_all(QUERY_ALL_RESPONSE, sizeof(QUERY_ALL_RESPONSE), 1,
                                     LC_FEEDBACK_DOOR_CLOSING, states) &&
           states[0] == LC_DOOR_OPEN && states[24] == LC_DOOR_UNKNOWN;
}
