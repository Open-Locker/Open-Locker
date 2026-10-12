#pragma once

#include <stdbool.h>
#include <stdint.h>

#include "lc_ports/locker_bus_port.h"

/*
 * Executes console lines against the locker bus port. Pure: the bytes come in
 * and the replies go out through the caller. Every reply is one line starting
 * with `ok` or `error`.
 */

/* Addresses a `query` reports, starting at 0. */
#define LC_CONSOLE_QUERY_ADDRESSES 32

typedef struct {
    lc_locker_bus_port_t bus;
    void *ctx;
    /* Writes one reply line; the newline is included in `text`. */
    void (*write)(void *ctx, const char *text);
    /* Unlock requests that reached the wire since boot. */
    uint32_t (*unlock_transmissions)(void *ctx);
    /* Must not use the bus: `self-test` sends nothing to the panel. */
    bool (*self_test)(void *ctx);
} lc_console_t;

void lc_console_execute(const lc_console_t *console, const char *line);
/* Reply for a line that lc_console_line_feed() rejected. */
void lc_console_reject(const lc_console_t *console);

/* Starts the task that reads the console UART. `console` must stay valid. */
void lc_usb_console_start(const lc_console_t *console);
