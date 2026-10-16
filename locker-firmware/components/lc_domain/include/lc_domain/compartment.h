#pragma once

#include <stdint.h>

/* Board address set on the panel's DIP switches. */
#define LC_BOARD_ADDRESS_MIN 1
#define LC_BOARD_ADDRESS_MAX 31

/* Zero-based compartment address on one board. 255 has no wire encoding. */
#define LC_COMPARTMENT_ADDRESS_MAX 254

typedef enum {
    LC_DOOR_UNKNOWN = 0,
    LC_DOOR_CLOSED,
    LC_DOOR_OPEN,
} lc_door_t;

/* What a high feedback signal from the lock means (ADR-0061). */
typedef enum {
    LC_FEEDBACK_DOOR_CLOSING = 0,
    LC_FEEDBACK_DOOR_OPENING,
} lc_feedback_t;

typedef struct {
    lc_feedback_t feedback;
} lc_hardware_profile_t;

typedef struct {
    uint16_t compartment_number;
    uint8_t board;
    uint8_t address;
} lc_target_t;
