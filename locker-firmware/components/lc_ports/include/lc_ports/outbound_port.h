#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/command.h"
#include "lc_domain/events.h"

/*
 * Messages the device sends, as typed values: no topics, no JSON.
 *
 * A call does not wait for the broker. It returns a non-zero id when the
 * message was accepted for sending and LC_PUBLISH_REFUSED when it was not. The
 * acknowledgement of the broker, a failure, or a timeout arrive later as
 * events carrying that id.
 */

typedef uint32_t lc_publish_id_t;
#define LC_PUBLISH_REFUSED ((lc_publish_id_t)0)

typedef struct {
    void *ctx;
    lc_publish_id_t (*command_response)(void *ctx, const lc_command_result_t *result);
    lc_publish_id_t (*heartbeat)(void *ctx, uint32_t uptime_seconds, bool bus_connected);
    lc_publish_id_t (*compartment_snapshot)(void *ctx, const lc_compartment_state_t *states,
                                            size_t count);
    lc_publish_id_t (*open_detection)(void *ctx, const lc_open_detection_t *event);
    lc_publish_id_t (*uncommanded_open)(void *ctx, const lc_uncommanded_open_t *event);
    lc_publish_id_t (*connection_offline)(void *ctx, lc_offline_reason_t reason);
    lc_publish_id_t (*firmware_update_failed)(void *ctx, const lc_firmware_update_failure_t *event);
} lc_outbound_port_t;
