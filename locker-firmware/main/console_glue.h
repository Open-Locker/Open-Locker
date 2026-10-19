#pragma once

#include <stdbool.h>
#include <stdint.h>

#include "lc_application/commission_device.h"
#include "lc_ports/locker_bus_port.h"
#include "lc_usb_console/console.h"
#include "runtime.h"

/* Wires the console to the owner task and to the bus. */
void console_glue_init(lc_console_t *console, const lc_locker_bus_port_t *bus,
                       uint32_t (*unlock_transmissions)(void *ctx), bool (*self_test)(void *ctx));

/*
 * The one reply to a request, from the commissioning use case: a line on the
 * console for a console request, the setup network's reply for a portal one.
 */
void console_glue_reply(void *ctx, lc_commission_channel_t channel, lc_commission_result_t result);

/* Where replies for the setup network go; not set while there is no setup network. */
void console_glue_set_portal_reply(void (*reply)(lc_commission_result_t result));
