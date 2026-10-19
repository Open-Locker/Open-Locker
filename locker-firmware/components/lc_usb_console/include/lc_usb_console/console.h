#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/identity.h"
#include "lc_ports/locker_bus_port.h"
#include "lc_usb_console/line.h"

/*
 * Executes console lines against the locker bus port. Pure: the bytes come in
 * and the replies go out through the caller. Every reply is one line starting
 * with `ok` or `error`.
 */

/* Addresses a `query` reports, starting at 0. */
#define LC_CONSOLE_QUERY_ADDRESSES 32
/* Most bytes one call of lc_console_feed_bytes() takes, so input cannot starve other work. */
#define LC_CONSOLE_BYTES_PER_PASS 256

typedef struct {
    lc_locker_bus_port_t bus;
    void *ctx;
    /* Writes one reply line; the newline is included in `text`. */
    void (*write)(void *ctx, const char *text);
    /* Unlock requests that reached the wire since boot. */
    uint32_t (*unlock_transmissions)(void *ctx);
    /* Must not use the bus: `self-test` sends nothing to the panel. */
    bool (*self_test)(void *ctx);
    /*
     * `command <json>`: hands one command payload to the owner task, as if the
     * broker had delivered it. False when it was not accepted. May be NULL.
     */
    bool (*submit_command)(void *ctx, const char *json);
    /*
     * `configure <json>`: hands the settings over. False when they were not
     * accepted for processing. When they were, the console says nothing: the
     * application reports the outcome as the one reply to that line. No reply
     * may repeat a submitted value. May be NULL.
     */
    bool (*submit_setup)(void *ctx, const lc_setup_request_t *request);
    /* `reset-network`. May be NULL. */
    bool (*request_reset)(void *ctx);
    /*
     * Whether the device is enrolled, an enrollment is pending, and network
     * settings are stored. Shown by `status`. May be NULL.
     */
    bool (*provisioning_state)(void *ctx, bool *enrolled, bool *pending, bool *configured);
    /*
     * A production build (ADR-0074): `configure`, `unlock`, `command` and
     * `reset-network` do not exist and answer like any unknown word. What
     * remains only reads. The default, false, is the bench console.
     */
    bool production;
} lc_console_t;

void lc_console_execute(const lc_console_t *console, const char *line);
/* Reply for a line that lc_console_line_feed() rejected. */
void lc_console_reject(const lc_console_t *console);

/*
 * Feeds received bytes through the line assembler and executes each complete
 * line. Takes at most LC_CONSOLE_BYTES_PER_PASS bytes and returns how many it
 * took.
 */
size_t lc_console_feed_bytes(const lc_console_t *console, lc_console_line_t *input,
                             const uint8_t *bytes, size_t length);

/* Starts the task that reads the console UART. `console` must stay valid. */
void lc_usb_console_start(const lc_console_t *console);
