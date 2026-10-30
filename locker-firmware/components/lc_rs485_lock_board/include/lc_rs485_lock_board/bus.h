#pragma once

#include <stdint.h>

#include "lc_domain/compartment.h"
#include "lc_ports/clock_port.h"
#include "lc_ports/locker_bus_port.h"
#include "lc_rs485_lock_board/serial.h"

/*
 * Locker bus adapter for the RS485 lock panel: one request at a time, one
 * reply per request, no request identifiers on the wire.
 */

typedef struct {
    /* Longest wait for a complete reply, measured from the write. */
    uint32_t reply_deadline_ms;
    /* Silence between bytes that ends a frame. Also drained before a request. */
    uint32_t quiet_interval_ms;
    /*
     * Silence enforced after a request timed out, before the next request is
     * written. Bytes received in it are discarded, so a late reply is not
     * credited to the next request. Must exceed the panel's worst reply time.
     */
    uint32_t settle_period_ms;
    /* Consecutive failed exchanges that start a cooldown. */
    uint8_t failures_before_cooldown;
    /* How long nothing is written once a cooldown has started. */
    uint32_t cooldown_ms;
} lc_rs485_bus_config_t;

/* Caller-allocated. Fields are private to the adapter. */
typedef struct {
    lc_rs485_serial_t serial;
    lc_clock_port_t clock;
    lc_rs485_bus_config_t config;
    lc_feedback_t feedback;
    lc_bus_state_t state;
    uint8_t failures;
    int64_t settle_until_ms;
    int64_t cooldown_until_ms;
    uint32_t unlock_transmissions;
} lc_rs485_bus_t;

/* Writes nothing to the serial line. */
void lc_rs485_bus_init(lc_rs485_bus_t *bus, lc_rs485_serial_t serial, lc_clock_port_t clock,
                       const lc_rs485_bus_config_t *config);

lc_locker_bus_port_t lc_rs485_bus_port(lc_rs485_bus_t *bus);

/* Unlock requests of which at least one byte was written. For diagnostics. */
uint32_t lc_rs485_bus_unlock_transmissions(const lc_rs485_bus_t *bus);
