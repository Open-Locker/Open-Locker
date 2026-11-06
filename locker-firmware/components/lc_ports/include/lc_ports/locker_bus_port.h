#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/compartment.h"

/*
 * Protocol-neutral locker controller boundary. Wire protocol, channel
 * numbering and feedback polarity stay in the adapter.
 */

typedef enum {
    /* The controller confirmed the unlock. */
    LC_UNLOCK_ACKNOWLEDGED = 0,
    /* No request byte left the device. The lock cannot have moved. */
    LC_UNLOCK_NOT_SENT,
    /* The request may have reached the controller. The lock may have moved. */
    LC_UNLOCK_OUTCOME_UNKNOWN,
    /* The controller answered that the unlock failed. */
    LC_UNLOCK_REFUSED,
} lc_unlock_result_t;

typedef struct {
    lc_unlock_result_t result;
    /* Door state seen directly after actuation; LC_DOOR_UNKNOWN when none. */
    lc_door_t observed;
} lc_unlock_observation_t;

typedef enum {
    /* No successful exchange yet, or the last exchange failed. */
    LC_BUS_DISCONNECTED = 0,
    LC_BUS_CONNECTED,
    /* Repeated failures; the adapter has stopped trying for now. */
    LC_BUS_UNREACHABLE,
} lc_bus_state_t;

typedef struct {
    void *ctx;
    bool (*apply_profile)(void *ctx, const lc_hardware_profile_t *profile);
    /* Never retried by the adapter. */
    lc_unlock_observation_t (*unlock_compartment)(void *ctx, lc_target_t target);
    /*
     * Writes one state per requested address, in the same order. Returns false
     * when the board could not be read; every entry is then LC_DOOR_UNKNOWN.
     */
    bool (*read_compartment_states)(void *ctx, uint8_t board, const uint8_t *addresses,
                                    size_t count, lc_door_t *out);
    lc_bus_state_t (*connection_state)(void *ctx);
} lc_locker_bus_port_t;
