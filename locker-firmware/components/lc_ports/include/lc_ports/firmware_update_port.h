#pragma once

#include <stdbool.h>

#include "lc_domain/firmware.h"

/*
 * The firmware image slots and the download into the other one (ADR-0075).
 * Called only from the owner task and never waiting for the network. The
 * download runs elsewhere; its outcome comes back as LC_EVENT_FIRMWARE_VERIFIED
 * or LC_EVENT_FIRMWARE_FAILED.
 *
 * A port whose `start` is NULL means the build has no OTA: the application
 * then refuses every update with UPDATE_NOT_READY and calls nothing else. A
 * port with a `start` has every function.
 */
typedef struct {
    void *ctx;
    /* Version of the running image. */
    const char *(*running_version)(void *ctx);
    lc_ota_slot_t (*running_slot)(void *ctx);
    /* The running image has not marked itself valid yet (pending verify). */
    bool (*on_trial)(void *ctx);
    /* The version this unit rolled back from last; empty when it never did. */
    const char *(*last_rollback_version)(void *ctx);
    /* Starts the download. False when it could not be started. */
    bool (*start)(void *ctx, const lc_firmware_update_request_t *request);
    /* Stops a download that was started and discards what it wrote. No event follows. */
    void (*abort)(void *ctx);
    /* Makes the verified image the next boot slot. */
    bool (*select_next_slot)(void *ctx);
    /* The running image on trial is good: it stays. False when that could not be stored. */
    bool (*mark_valid)(void *ctx);
    /*
     * The running image on trial is bad: mark it invalid and restart into the previous one.
     * Returns only when that did not work (false), for example with no previous image.
     */
    bool (*rollback)(void *ctx);
} lc_firmware_update_port_t;
