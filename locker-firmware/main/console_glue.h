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

/* The one reply to a `configure` line, from the commissioning use case. */
void console_glue_reply(void *ctx, lc_commission_result_t result);
