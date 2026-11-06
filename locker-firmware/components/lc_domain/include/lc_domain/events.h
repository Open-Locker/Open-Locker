#pragma once

#include <stdbool.h>
#include <stdint.h>

#include "lc_domain/command.h"
#include "lc_domain/compartment.h"

/* What the device reports about doors and about itself. */

#define LC_POLL_INTERVAL_MS 500
/* Consecutive failed readings after which a door is reported as unknown. */
#define LC_UNKNOWN_READS_BEFORE_UNKNOWN 3

typedef struct {
    uint16_t compartment_number;
    lc_door_t state;
} lc_compartment_state_t;

/* Outcome of watching a door after an unlock (ADR-0040). */
typedef struct {
    uint16_t compartment_number;
    char transaction_id[LC_ID_MAX + 1];
    /* False: the door was not seen open before the window ended. */
    bool opened;
    /* Time from unlock to the door being seen open. Only when `opened`. */
    uint32_t detection_ms;
} lc_open_detection_t;

/* A door that went from closed to open with no unlock to explain it. */
typedef struct {
    uint16_t compartment_number;
    /* False when this compartment was never unlocked since boot. */
    bool has_last_unlock;
    uint32_t ms_since_last_unlock;
} lc_uncommanded_open_t;

typedef enum {
    LC_OFFLINE_SHUTDOWN = 0,
    LC_OFFLINE_LAST_WILL,
} lc_offline_reason_t;
