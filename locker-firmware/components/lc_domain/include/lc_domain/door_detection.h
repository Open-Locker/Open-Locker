#pragma once

#include <stdbool.h>
#include <stdint.h>

#include "lc_domain/compartment.h"
#include "lc_domain/events.h"

/*
 * What the device knows about the door of one compartment: the state it
 * reports, and the watch it keeps after an unlock (ADR-0040). Pure: time is
 * passed in.
 */

typedef struct {
    /* The state the snapshot gives. */
    lc_door_t reported;
    uint8_t unknown_reads;
    /* The last unlock that may have moved the lock, since boot. */
    bool unlocked_since_boot;
    int64_t unlocked_at_ms;
    /* A watch is running: the door has not been seen open yet. */
    bool watching;
    /* The door was read as closed during the watch. */
    bool saw_closed;
    /* The result of a finished watch, not yet accepted for sending. */
    bool outcome_due;
    lc_open_detection_t outcome;
} lc_door_tracker_t;

/* Forgets everything, as after a change of the mapping. */
void lc_door_tracker_reset(lc_door_tracker_t *tracker);

/*
 * The lock may have moved. Starts a watch for the door; a watch that is
 * still running is replaced.
 */
void lc_door_unlocked(lc_door_tracker_t *tracker, uint16_t compartment_number,
                      const char *transaction_id, int64_t now_ms);

/*
 * A reading of the door while a watch runs. `open` ends the watch with an
 * outcome; so does the end of `window_ms`, as a jam when the door was read as
 * closed, and as nothing when it never could be read.
 */
void lc_door_watch(lc_door_tracker_t *tracker, lc_door_t reading, int64_t now_ms,
                   uint32_t window_ms);

typedef struct {
    /* The reported state of the door is not what it was. */
    bool changed;
    /* The door went from closed to open with no unlock to explain it. */
    bool uncommanded;
    lc_uncommanded_open_t event;
} lc_door_update_t;

/*
 * One polling cycle for one door. `reading` is LC_DOOR_UNKNOWN when the panel
 * could not be read or did not cover the door. Runs the watch as well.
 *
 * The reported state follows the readings, except that a door is reported as
 * unknown only after LC_UNKNOWN_READS_BEFORE_UNKNOWN readings in a row. An
 * opening is uncommanded when the reported state was closed, and neither a
 * running watch nor an unlock within `window_ms` explains it. An unknown or
 * first reading that is open is not news.
 */
lc_door_update_t lc_door_update(lc_door_tracker_t *tracker, uint16_t compartment_number,
                                lc_door_t reading, int64_t now_ms, uint32_t window_ms);
