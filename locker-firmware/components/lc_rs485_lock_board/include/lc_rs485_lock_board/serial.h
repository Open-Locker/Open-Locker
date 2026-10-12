#pragma once

#include <stddef.h>
#include <stdint.h>

/*
 * The serial line as the bus adapter sees it. Implemented by lc_serial_uart on
 * the device and by a scripted fake in host tests.
 */
typedef struct {
    void *ctx;
    /*
     * Transmits up to `length` bytes and returns how many were sent, or a
     * negative value when none were. Returns only when those bytes are on the
     * wire.
     * The bus adapter calls this exactly once per request.
     */
    int (*write)(void *ctx, const uint8_t *data, size_t length);
    /*
     * Returns the bytes already received, at most `capacity`. If there are
     * none, waits up to `timeout_ms` for the first one. Returns 0 on timeout
     * and a negative value on error. A timeout of 0 never waits.
     */
    int (*read)(void *ctx, uint8_t *buffer, size_t capacity, uint32_t timeout_ms);
} lc_rs485_serial_t;
