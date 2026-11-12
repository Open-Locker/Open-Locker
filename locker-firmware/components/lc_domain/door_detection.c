#include "lc_domain/door_detection.h"

#include <string.h>

#include "lc_domain/text.h"

void lc_door_tracker_reset(lc_door_tracker_t *tracker)
{
    memset(tracker, 0, sizeof(*tracker));
}

void lc_door_unlocked(lc_door_tracker_t *tracker, uint16_t compartment_number,
                      const char *transaction_id, int64_t now_ms)
{
    tracker->unlocked_since_boot = true;
    tracker->unlocked_at_ms = now_ms;
    tracker->watching = true;
    tracker->saw_closed = false;
    tracker->outcome_due = false;
    memset(&tracker->outcome, 0, sizeof(tracker->outcome));
    tracker->outcome.compartment_number = compartment_number;
    (void)lc_text_copy(tracker->outcome.transaction_id, sizeof(tracker->outcome.transaction_id),
                       transaction_id);
}

static void finish(lc_door_tracker_t *tracker, bool opened, int64_t now_ms)
{
    tracker->watching = false;
    tracker->outcome_due = true;
    tracker->outcome.opened = opened;
    tracker->outcome.detection_ms = opened ? (uint32_t)(now_ms - tracker->unlocked_at_ms) : 0;
}

void lc_door_watch(lc_door_tracker_t *tracker, lc_door_t reading, int64_t now_ms,
                   uint32_t window_ms)
{
    if (!tracker->watching) {
        return;
    }
    if (reading == LC_DOOR_OPEN) {
        finish(tracker, true, now_ms);
        return;
    }
    if (reading == LC_DOOR_CLOSED) {
        tracker->saw_closed = true;
    }
    if (now_ms - tracker->unlocked_at_ms >= (int64_t)window_ms) {
        if (tracker->saw_closed) {
            finish(tracker, false, now_ms);
        } else {
            tracker->watching = false;
        }
    }
}

lc_door_update_t lc_door_update(lc_door_tracker_t *tracker, uint16_t compartment_number,
                                lc_door_t reading, int64_t now_ms, uint32_t window_ms)
{
    lc_door_update_t update;
    memset(&update, 0, sizeof(update));
    lc_door_t before = tracker->reported;

    if (reading == LC_DOOR_UNKNOWN) {
        if (tracker->unknown_reads < UINT8_MAX) {
            ++tracker->unknown_reads;
        }
        if (tracker->unknown_reads >= LC_UNKNOWN_READS_BEFORE_UNKNOWN) {
            tracker->reported = LC_DOOR_UNKNOWN;
        }
    } else {
        tracker->unknown_reads = 0;
        bool explained = tracker->watching ||
                         (tracker->unlocked_since_boot &&
                          now_ms - tracker->unlocked_at_ms < (int64_t)window_ms);
        if (before == LC_DOOR_CLOSED && reading == LC_DOOR_OPEN && !explained) {
            update.uncommanded = true;
            update.event.compartment_number = compartment_number;
            update.event.has_last_unlock = tracker->unlocked_since_boot;
            update.event.ms_since_last_unlock =
                tracker->unlocked_since_boot ? (uint32_t)(now_ms - tracker->unlocked_at_ms) : 0;
        }
        tracker->reported = reading;
    }

    lc_door_watch(tracker, reading, now_ms, window_ms);
    update.changed = tracker->reported != before;
    return update;
}
