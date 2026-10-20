#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/compartment.h"

/*
 * Frame codec for the proprietary RS485 lock panel (ADR-0061). Every frame
 * ends in the XOR of all preceding bytes. Pure: no I/O, no allocation.
 */

#define LC_RS485_REQUEST_SIZE 5
#define LC_RS485_FRAME_MAX 64
/* Addresses 0..254; the size of the state array a status decode fills. */
#define LC_RS485_ADDRESS_COUNT 255

/*
 * Both encoders return false, leaving `out` untouched, for a board outside
 * 1..31. The unlock encoder also rejects an address above 254, so wire
 * channel zero (open all) is never produced.
 */
bool lc_rs485_encode_unlock(uint8_t board, unsigned address, uint8_t out[LC_RS485_REQUEST_SIZE]);
bool lc_rs485_encode_query_all(uint8_t board, uint8_t out[LC_RS485_REQUEST_SIZE]);

typedef enum {
    /* No well-formed unlock reply for this board and address. */
    LC_RS485_UNLOCK_NO_ANSWER = 0,
    LC_RS485_UNLOCK_SUCCEEDED,
    LC_RS485_UNLOCK_FAILED,
} lc_rs485_unlock_answer_t;

/*
 * The panel's answer to an unlock, found among the messages received (door
 * events may follow it in the same burst). Its status byte depends on the lock
 * type: door-closing feedback 00 = succeeded, 11 = failed; door-opening the
 * reverse (ADR-0072).
 */
lc_rs485_unlock_answer_t lc_rs485_decode_unlock_ack(const uint8_t *frame, size_t length,
                                                    uint8_t board, unsigned address,
                                                    lc_feedback_t feedback);

/*
 * Decodes a query-all response. The number of status bytes is taken from the
 * frame, never assumed. The last status byte carries addresses 0..7 in bits
 * 0..7, the one before it addresses 8..15, and so on. Addresses the frame does
 * not cover are LC_DOOR_UNKNOWN. On failure every entry is LC_DOOR_UNKNOWN.
 */
bool lc_rs485_decode_query_all(const uint8_t *frame, size_t length, uint8_t board,
                               lc_feedback_t feedback,
                               lc_door_t states[LC_RS485_ADDRESS_COUNT]);

/* Checks the codec against the golden frames. No I/O: nothing reaches the panel. */
bool lc_rs485_codec_self_test(void);
